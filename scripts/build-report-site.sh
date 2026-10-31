#!/usr/bin/env bash
#
# Assembles the EARL conformance report into a static site for GitHub Pages,
# published at json-ld.w3c.reports.conformance.accredify.dev.
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

here=$(cd "$(dirname "$0")" && pwd)
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
echo "json-ld.w3c.reports.conformance.accredify.dev" > "$out/CNAME"

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

esc() { printf '%s' "${1:-}" | sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e 's/>/\&gt;/g'; }

# Which tests failed, read back out of the report for the same reason the
# counts are: the report is the artefact, so it is the only thing worth
# describing. One assertion block per test, outcome before subject before test.
failing=$(awk '
  /earl:outcome[[:space:]]+earl:failed/ { pending = 1 }
  pending && match($0, /earl:test[[:space:]]+<[^>]+>/) {
    uri = substr($0, RSTART, RLENGTH)
    sub(/^earl:test[[:space:]]+</, "", uri)
    sub(/>$/, "", uri)
    print uri
    pending = 0
  }
' "$out/$basename")

# Why each one fails is maintained in exactly one place - the harness's
# expected-failure allowlist - and read from there rather than copied, so the
# page cannot drift from what CI actually gates on. Entries are one per line,
# '#id' => 'reason', which is what the field split below assumes.
#
# Hard-failing on a missing allowlist is deliberate: it ships beside this
# script, so losing it means the checkout is wrong, not that every blocker
# regressed at once - and publishing 13 false regressions is worse than
# publishing nothing.
blockers_src="$here/../tests/W3c/KnownBlockers.php"
[ -f "$blockers_src" ] || {
  echo "no expected-failure list at $blockers_src - refusing to publish" >&2
  exit 1
}

blockers=$(awk -F"'" -v q="'" '
  /public const [A-Z_]+ = \[/ {
    name = $0
    sub(/^.*public const /, "", name)
    sub(/[^A-Z_].*$/, "", name)
    manifest = ""
    if (name == "EXPAND")   manifest = "expand"
    if (name == "COMPACT")  manifest = "compact"
    if (name == "FLATTEN")  manifest = "flatten"
    if (name == "TO_RDF")   manifest = "toRdf"
    if (name == "FROM_RDF") manifest = "fromRdf"
    if (name == "FRAME")    manifest = "frame"
    next
  }
  /^[[:space:]]*\];/ { manifest = "" }
  manifest != "" && NF >= 5 && $2 ~ /^#/ {
    reason = $4
    for (i = 5; i < NF; i++) reason = reason q $i
    print manifest "\t" $2 "\t" reason
  }
' "$blockers_src")

[ -n "$blockers" ] || { echo "expected-failure list parsed empty - refusing to publish" >&2; exit 1; }

reason_for() {
  printf '%s\n' "$blockers" |
    awk -F'\t' -v m="$1" -v i="$2" '$1 == m && $2 == i { print $3; exit }'
}

# Grouped by manifest, in the order the report walks them.
rows=""
unexpected=0
for manifest in expand compact flatten toRdf fromRdf frame; do
  case $manifest in
    expand)  heading="Expansion" ;;
    compact) heading="Compaction" ;;
    flatten) heading="Flattening" ;;
    toRdf)   heading="Serialize to RDF" ;;
    fromRdf) heading="RDF to JSON-LD" ;;
    frame)   heading="Framing" ;;
  esac

  group=""
  for uri in $failing; do
    name=${uri##*/tests/}            # expand-manifest#t0122
    [ "${name%%-manifest#*}" = "$manifest" ] || continue
    id="#${name#*#}"

    why=$(reason_for "$manifest" "$id")
    case $why in
      'negative: '*)                 kind="negative test"; why=${why#negative: } ;;
      'positive (non-normative): '*) kind="non-normative"; why=${why#positive (non-normative): } ;;
      'positive (json-ld-1.0): '*)   kind="JSON-LD 1.0";   why=${why#positive (json-ld-1.0): } ;;
      'positive: '*)                 kind="positive";      why=${why#positive: } ;;
      '')
        # Not on the allowlist, so CI failed on it too. Say so here rather
        # than listing it beside the ones we have accepted.
        kind="unexpected"
        why="Not on the expected-failure list - this is a regression, not an accepted limit."
        unexpected=$((unexpected + 1))
        ;;
      *) kind="" ;;
    esac

    kind_class="kind"
    [ "$kind" = "unexpected" ] && kind_class="kind kind-bad"

    badge="<span class=\"$kind_class\">$(esc "$kind")</span>"
    group="$group
      <tr>
        <td><a href=\"$(esc "$uri")\"><code>$(esc "$id")</code></a></td>
        <td>$badge</td>
        <td>$(esc "$why")</td>
      </tr>"
  done

  [ -n "$group" ] && rows="$rows
      <tr class=\"group\"><th colspan=\"3\">$heading</th></tr>$group"
done

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

if [ "$failed" -eq 0 ]; then
  banner="<p class=\"banner good\">All $total assertions passing.</p>"
elif [ "$unexpected" -gt 0 ]; then
  banner="<p class=\"banner bad\">$failed of $total assertions failing, $unexpected of them unexpected.</p>"
else
  banner="<p class=\"banner bad\">$failed of $total assertions failing, all of them expected and listed below.</p>"
fi

if [ -n "$rows" ]; then
  table="<h2>What is failing</h2>
  <p>Every failure below, and why. The reasons come from the harness's
     expected-failure list, which gates CI in both directions: an unlisted
     failure fails the build, and so does a listed one that starts passing.</p>
  <div class=\"scroll\">
    <table>
      <thead><tr><th>Test</th><th>Kind</th><th>Why it fails</th></tr></thead>
      <tbody>$rows
      </tbody>
    </table>
  </div>"
else
  table=""
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
  h2 { font-size:1.15rem; letter-spacing:-0.01em; margin:36px 0 8px; }
  .sub { color:var(--muted); margin:0 0 24px; }
  .banner { padding:12px 16px; border-radius:6px; font-weight:600; margin:0 0 24px; }
  .good { background:var(--good-bg); color:var(--good); }
  .bad { background:var(--bad-bg); color:var(--bad); }
  dl { display:grid; grid-template-columns:auto 1fr; gap:10px 20px; margin:0 0 28px;
       background:var(--card); border:1px solid var(--rule); border-radius:8px; padding:18px 20px; }
  dt { color:var(--muted); font-size:.85rem; }
  dd { margin:0; font-variant-numeric:tabular-nums; }
  p { max-width:68ch; }
  .scroll { overflow-x:auto; background:var(--card); border:1px solid var(--rule);
            border-radius:8px; margin:16px 0 28px; }
  table { border-collapse:collapse; width:100%; font-size:.92rem; }
  th, td { text-align:left; padding:10px 14px; border-bottom:1px solid var(--rule);
           vertical-align:top; }
  thead th { color:var(--muted); font-size:.78rem; text-transform:uppercase;
             letter-spacing:.04em; font-weight:600; white-space:nowrap; }
  tbody tr:last-child td { border-bottom:none; }
  tr.group th { background:var(--bg); font-size:.85rem; letter-spacing:.01em;
                text-transform:none; color:var(--fg); }
  td code { font-size:.9em; white-space:nowrap; }
  .kind { display:inline-block; white-space:nowrap; font-size:.78rem; padding:2px 8px;
          border:1px solid var(--rule); border-radius:999px; color:var(--muted); }
  .kind-bad { color:var(--bad); border-color:var(--bad); font-weight:600; }
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
  $table
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
