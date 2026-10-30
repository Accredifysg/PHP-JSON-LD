#!/usr/bin/env bash
#
# Assembles the EARL conformance report into a static site for GitHub Pages,
# published at json-ld.w3c.conformance.accredify.dev.
#
# Three files, each for a different reader:
#
#   *.ttl        the EARL report itself, which is what W3C takes by pull
#                request to w3c/json-ld-api. This is the artefact; the rest is
#                presentation.
#   index.json   a summary the conformance index at conformance.accredify.dev
#                reads on every build. Plain JSON over public HTTPS, no
#                credentials - the index links rather than aggregates, so this
#                is how it learns the numbers without holding a token.
#   index.html   for a person who followed a link here from a pull request.
#
# Expects the EARL report to exist already; generating it needs the W3C
# submodule and a full test run, which is the workflow's job.
set -euo pipefail

out=site
report="${1:?usage: build-report-site.sh <report.ttl> <version>}"
version="${2:?usage: build-report-site.sh <report.ttl> <version>}"

[ -f "$report" ] || { echo "no such report: $report" >&2; exit 1; }

mkdir -p "$out"
cp "$report" "$out/"
basename=$(basename "$report")

# Must match the Route 53 record in Conformance-Infra
# (conformance_pages_dns.tf) and the domain under Settings -> Pages.
#
# Note this file does NOT claim the domain on an Actions-based deployment -
# that comes from the repository setting. It is kept because it documents
# which name the site expects, and because a mismatch between the two is worth
# being able to see in a diff.
echo "json-ld.w3c.conformance.accredify.dev" > "$out/CNAME"

# Counted from the report rather than from the test run, deliberately. The
# report is what gets submitted, so what it asserts is the only number worth
# publishing - a suite that passed while the report under-claims coverage is
# still a report that under-claims coverage.
passed=$(grep -c 'earl:outcome earl:passed' "$out/$basename" || true)
failed=$(grep -c 'earl:outcome earl:failed' "$out/$basename" || true)
total=$((passed + failed))

[ "$total" -gt 0 ] || { echo "report asserts nothing - refusing to publish" >&2; exit 1; }

generated=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
shown=$(date -u '+%Y-%m-%d %H:%M UTC')

cat > "$out/index.json" <<JSON
{
  "format": "earl-summary",
  "implementation": "PHP-JSON-LD",
  "version": "$version",
  "suite": "JSON-LD 1.1 processor conformance",
  "report": "$basename",
  "generated": "$generated",
  "passed": $passed,
  "failed": $failed,
  "total": $total
}
JSON

if [ "$failed" -gt 0 ]; then
  banner="<p class=\"banner bad\">$failed of $total assertions failing.</p>"
else
  banner="<p class=\"banner good\">All $total assertions passing.</p>"
fi

cat > "$out/index.html" <<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>JSON-LD 1.1 — PHP-JSON-LD</title>
<style>
  :root { color-scheme: light; --bg:#f4f6f8; --card:#fff; --fg:#111820; --muted:#5a6a78;
          --rule:#dde4ea; --good:#176b48; --bad:#9d2f1f; --accent:#0b6b74;
          --good-bg:#e4f1ea; --bad-bg:#faeae6; }
  @media (prefers-color-scheme: dark) {
    :root { --bg:#0d1318; --card:#161e25; --fg:#e4eaf0; --muted:#8fa0af;
            --rule:#25323d; --good:#4fc08d; --bad:#f08a74; --accent:#4cc2cc;
            --good-bg:#11291e; --bad-bg:#2b1612; }
  }
  *,*::before,*::after { box-sizing:border-box; }
  body { margin:0; padding:56px 24px 96px; background:var(--bg); color:var(--fg);
         font:15.5px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
  main { max-width:760px; margin:0 auto; }
  h1 { font-size:1.9rem; letter-spacing:-0.02em; margin:0 0 8px; }
  .sub { color:var(--muted); margin:0 0 24px; }
  .banner { padding:12px 16px; border-radius:6px; font-weight:600; margin:0 0 24px; }
  .good { background:var(--good-bg); color:var(--good); }
  .bad { background:var(--bad-bg); color:var(--bad); }
  dl { display:grid; grid-template-columns:auto 1fr; gap:10px 20px; margin:0 0 28px;
       background:var(--card); border:1px solid var(--rule); border-radius:8px; padding:18px 20px; }
  dt { color:var(--muted); font-size:.85rem; }
  dd { margin:0; font-variant-numeric:tabular-nums; }
  p { max-width:68ch; }
  footer { margin-top:32px; color:var(--muted); font-size:.88rem;
           border-top:1px solid var(--rule); padding-top:18px; }
  a { color:var(--accent); }
</style>
</head>
<body>
<main>
  <h1>JSON-LD 1.1 processor conformance</h1>
  <p class="sub">EARL conformance report for
     <a href="https://github.com/Accredifysg/PHP-JSON-LD">PHP-JSON-LD</a>
     $version, against the JSON-LD 1.1 API and Framing test manifests.</p>
  $banner
  <dl>
    <dt>Assertions</dt><dd>$total</dd>
    <dt>Passed</dt><dd>$passed</dd>
    <dt>Failed</dt><dd>$failed</dd>
    <dt>Report</dt><dd><a href="./$basename">$basename</a> (Turtle)</dd>
    <dt>Generated</dt><dd>$shown</dd>
  </dl>
  <p>Every test appears as one <code>earl:Assertion</code>, across expansion,
     compaction, flattening, toRdf, fromRdf and framing.</p>
  <p>Tests the harness skips are the expected-failure list, and they are
     reported here as <code>earl:failed</code> rather than omitted. A report
     has to reflect conformance, not suite greenness — a skipped test is a
     test that does not pass, and presenting it as anything else would
     over-claim.</p>
  <footer>
    <p><strong>This is our own run, not the record.</strong> The published record
       lives at <a href="https://w3c.github.io/json-ld-api/reports/">w3c.github.io/json-ld-api/reports</a>
       once the submission is merged.</p>
    <p>Part of <a href="https://conformance.accredify.dev">conformance.accredify.dev</a>,
       which indexes every conformance target we are working through.</p>
  </footer>
</main>
</body>
</html>
HTML

echo "Built $out/ — $passed/$total passing, version $version"
