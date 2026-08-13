/**
 * Regenerates the interop golden files (expected.nq) from jsonld.js, the
 * reference implementation, using RDFC-1.0 canonicalization — the exact
 * bytes an eddsa-rdfc-2022 proof is computed over.
 *
 * Fully offline: contexts resolve ONLY from fixtures/contexts/index.json;
 * any other URL is an error. This keeps goldens deterministic and auditable.
 *
 * Run via ./regenerate.sh (docker) or directly with node >= 18 after
 * `npm install jsonld` in this directory.
 */
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import jsonld from 'jsonld';

const root = path.dirname(fileURLToPath(import.meta.url));
const contextsDir = path.join(root, 'fixtures', 'contexts');
const casesDir = path.join(root, 'fixtures', 'cases');

const index = JSON.parse(await fs.readFile(path.join(contextsDir, 'index.json'), 'utf8'));

const documentLoader = async (url) => {
  const file = index[url];
  if (file === undefined) {
    throw new Error(`interop fixtures are offline-only: no vendored context for ${url} (add it to fixtures/contexts/index.json)`);
  }
  return {
    contextUrl: null,
    documentUrl: url,
    document: JSON.parse(await fs.readFile(path.join(contextsDir, file), 'utf8')),
  };
};

const jsonldVersion = JSON.parse(
  await fs.readFile(path.join(root, 'node_modules', 'jsonld', 'package.json'), 'utf8'),
).version;

// RDFC-1.0 is the W3C standardization of URDNA2015 (same output); the
// rdf-canonize version bundled with jsonld.js 8 only knows the older name.
let algorithm = 'RDFC-1.0';
try {
  await jsonld.canonize(
    { '@id': 'urn:probe:s', 'urn:probe:p': 'o' },
    { algorithm, format: 'application/n-quads', documentLoader },
  );
} catch (e) {
  if (!String(e).includes('Invalid RDF Dataset Canonicalization algorithm')) throw e;
  algorithm = 'URDNA2015';
}

const manifest = { generator: `jsonld.js ${jsonldVersion}`, algorithm, cases: {} };

for (const name of (await fs.readdir(casesDir)).sort()) {
  const dir = path.join(casesDir, name);
  const input = JSON.parse(await fs.readFile(path.join(dir, 'input.jsonld'), 'utf8'));

  let config = {};
  try {
    config = JSON.parse(await fs.readFile(path.join(dir, 'case.json'), 'utf8'));
  } catch {
    // no per-case config — defaults apply
  }

  // safe mode (the default) makes jsonld.js ERROR on data-lossy constructs
  // (dropped terms, relative IRIs) instead of silently omitting quads — the
  // silent omission is exactly the bug class this corpus exists to catch, so
  // a case may opt out only deliberately, with a `note` explaining the loss.
  const safe = config.safe !== false;
  if (!safe && !config.note) {
    throw new Error(`${name}: disabling safe mode requires a "note" in case.json explaining the intended loss`);
  }

  const nquads = await jsonld.canonize(input, {
    algorithm,
    format: 'application/n-quads',
    documentLoader,
    safe,
  });

  await fs.writeFile(path.join(dir, 'expected.nq'), nquads);
  const count = nquads.split('\n').filter(Boolean).length;
  manifest.cases[name] = { quads: count, safe };
  console.log(`${name}: ${count} quads${safe ? '' : ' (safe mode off — intentionally lossy, see case.json)'}`);
}

await fs.writeFile(path.join(root, 'fixtures', 'goldens.json'), JSON.stringify(manifest, null, 2) + '\n');
console.log(`\ngoldens.json written (generator: jsonld.js ${jsonldVersion})`);
