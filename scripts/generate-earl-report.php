<?php

declare(strict_types=1);

/**
 * Converts a JUnit XML log of the W3C conformance suite into a W3C EARL
 * implementation report (Turtle), suitable for submission to
 * w3c/json-ld-api's reports/ directory.
 *
 *   vendor/bin/pest --testsuite W3C --log-junit w3c-junit.xml
 *   php scripts/generate-earl-report.php w3c-junit.xml <version> > php-json-ld-earl.ttl
 *
 * Every test appears as one earl:Assertion. Tests the harness SKIPS are the
 * KnownBlockers expected-failure list, so they are reported as earl:failed —
 * the report must reflect actual conformance, not suite greenness.
 */
if ($argc < 3) {
    fwrite(STDERR, "usage: php scripts/generate-earl-report.php <junit.xml> <version> [<w3c-tests-dir> <framing-tests-dir>]\n");
    exit(1);
}

[, $junitPath, $version] = $argv;
$apiTests = $argv[3] ?? __DIR__.'/../tests/w3c/tests';
$framingTests = $argv[4] ?? __DIR__.'/../tests/w3c-framing/tests';

$manifestBase = [
    'ExpansionTest' => 'https://w3c.github.io/json-ld-api/tests/expand-manifest',
    'CompactionTest' => 'https://w3c.github.io/json-ld-api/tests/compact-manifest',
    'FlattenTest' => 'https://w3c.github.io/json-ld-api/tests/flatten-manifest',
    'ToRdfTest' => 'https://w3c.github.io/json-ld-api/tests/toRdf-manifest',
    'FromRdfTest' => 'https://w3c.github.io/json-ld-api/tests/fromRdf-manifest',
    'FrameTest' => 'https://w3c.github.io/json-ld-framing/tests/frame-manifest',
];

$project = 'https://github.com/Accredifysg/PHP-JSON-LD';
$assertor = 'https://github.com/Accredifysg';
$assertorName = 'Accredify';
$date = gmdate('Y-m-d');
$dateTime = gmdate('Y-m-d\TH:i:s\Z');

// The consolidated W3C report covers JSON-LD 1.1 only: tests marked
// specVersion json-ld-1.0 are not part of the published manifests, and
// earl-report drops assertions about them ("not defined in manifests").
// Exclude them, as prior report submissions do.
$jsonLd10Only = [];
$manifestFiles = [
    'https://w3c.github.io/json-ld-api/tests/expand-manifest' => $apiTests.'/expand-manifest.jsonld',
    'https://w3c.github.io/json-ld-api/tests/compact-manifest' => $apiTests.'/compact-manifest.jsonld',
    'https://w3c.github.io/json-ld-api/tests/flatten-manifest' => $apiTests.'/flatten-manifest.jsonld',
    'https://w3c.github.io/json-ld-api/tests/toRdf-manifest' => $apiTests.'/toRdf-manifest.jsonld',
    'https://w3c.github.io/json-ld-api/tests/fromRdf-manifest' => $apiTests.'/fromRdf-manifest.jsonld',
    'https://w3c.github.io/json-ld-framing/tests/frame-manifest' => $framingTests.'/frame-manifest.jsonld',
];
foreach ($manifestFiles as $base => $path) {
    $raw = file_get_contents($path);
    if ($raw === false) {
        fwrite(STDERR, "cannot read manifest {$path}\n");
        exit(1);
    }
    /** @var array{sequence: list<array{'@id': string, option?: array{specVersion?: string}}>} $manifest */
    $manifest = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    foreach ($manifest['sequence'] as $entry) {
        if (($entry['option']['specVersion'] ?? null) === 'json-ld-1.0') {
            $jsonLd10Only[$base.$entry['@id']] = true;
        }
    }
}

$xml = simplexml_load_file($junitPath);
if ($xml === false) {
    fwrite(STDERR, "cannot parse {$junitPath}\n");
    exit(1);
}

$assertions = [];
foreach ($xml->xpath('//testcase') as $case) {
    $class = (string) $case['classname'];
    $short = substr($class, (int) strrpos($class, '.') + 1);
    $base = $manifestBase[$short] ?? null;
    if ($base === null) {
        fwrite(STDERR, "unknown test class {$class}\n");
        exit(1);
    }
    if (preg_match('/#[a-z0-9]+/i', (string) $case['name'], $m) !== 1) {
        fwrite(STDERR, "cannot extract test id from: {$case['name']}\n");
        exit(1);
    }
    if (isset($jsonLd10Only[$base.$m[0]])) {
        continue;
    }
    // Skips are the KnownBlockers xfail list → failed; failures/errors → failed.
    $failed = isset($case->skipped) || isset($case->failure) || isset($case->error);
    $assertions[$base.$m[0]] = ! $failed;
}

ksort($assertions, SORT_STRING);

echo <<<TTL
@prefix dc: <http://purl.org/dc/terms/> .
@prefix doap: <http://usefulinc.com/ns/doap#> .
@prefix earl: <http://www.w3.org/ns/earl#> .
@prefix foaf: <http://xmlns.com/foaf/0.1/> .
@prefix xsd: <http://www.w3.org/2001/XMLSchema#> .

<{$project}> a earl:TestSubject, doap:Project, earl:Software;
  dc:title "PHP-JSON-LD";
  dc:creator <{$assertor}>;
  doap:description "A PHP implementation of the JSON-LD 1.1 specification.";
  doap:developer <{$assertor}>;
  doap:homepage <{$project}>;
  doap:license <{$project}/blob/main/LICENSE>;
  doap:name "PHP-JSON-LD";
  doap:programming-language "PHP";
  doap:release [
    doap:created "{$date}"^^xsd:date;
    doap:name "PHP-JSON-LD {$version}";
    doap:revision "{$version}"
  ] .

<{$assertor}> a foaf:Organization, earl:Assertor;
  foaf:homepage <{$assertor}>;
  foaf:name "{$assertorName}" .


TTL;

foreach ($assertions as $test => $passed) {
    $outcome = $passed ? 'earl:passed' : 'earl:failed';
    echo <<<TTL
[
  a earl:Assertion;
  earl:assertedBy <{$assertor}>;
  earl:mode earl:automatic;
  earl:result [
    a earl:TestResult;
    dc:date "{$dateTime}"^^xsd:dateTime;
    earl:outcome {$outcome}
  ];
  earl:subject <{$project}>;
  earl:test <{$test}>
] .


TTL;
}

$total = count($assertions);
$passedCount = count(array_filter($assertions));
fwrite(STDERR, "{$total} assertions ({$passedCount} passed, ".($total - $passedCount)." failed)\n");
