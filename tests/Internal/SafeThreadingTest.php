<?php

declare(strict_types=1);

use Accredify\JsonLd\Algorithms\Compaction;
use Accredify\JsonLd\Algorithms\Expansion;
use Accredify\JsonLd\Algorithms\Flattening;
use Accredify\JsonLd\Algorithms\FromRdf;
use Accredify\JsonLd\Algorithms\NodeMap;
use Accredify\JsonLd\Algorithms\ToRdf;
use Accredify\JsonLd\Context\ContextProcessor;
use Accredify\JsonLd\Context\TermDefinitions;
use Accredify\JsonLd\Exceptions\DataLossException;
use Accredify\JsonLd\Tests\Context\Support\StubDocumentLoader;
use PHPUnit\Framework\AssertionFailedError;

/*
|--------------------------------------------------------------------------
| Safe-mode threading is structural, not conventional
|--------------------------------------------------------------------------
| The safe flag used to default to false on every internal constructor and
| ride into copies via a setter — so a construction site that forgot to
| thread it ran silently with safe off (the failure shape behind the
| Flattening→NodeMap and fromRdf() gaps closed in the hardening pass). Now
| every internal class REQUIRES the flag: a missed site is a hard error at
| the call site. This guard keeps the next algorithm from quietly
| reintroducing the defaulted-flag pattern.
*/

describe('safe-mode threading guards', function () {
    it('requires a non-defaulted bool $safe constructor parameter on every internal pipeline class', function () {
        $classes = [
            Expansion::class,
            Compaction::class,
            Flattening::class,
            NodeMap::class,
            ToRdf::class,
            FromRdf::class,
            ContextProcessor::class,
            TermDefinitions::class,
        ];

        foreach ($classes as $class) {
            $constructor = (new ReflectionClass($class))->getConstructor();
            if ($constructor === null) {
                throw new AssertionFailedError("{$class} must define a constructor");
            }

            $safeParam = null;
            foreach ($constructor->getParameters() as $parameter) {
                if ($parameter->getName() === 'safe') {
                    $safeParam = $parameter;
                    break;
                }
            }

            expect($safeParam)->not->toBeNull("{$class} must take a \$safe constructor parameter")
                ->and($safeParam?->isOptional())->toBeFalse("{$class}'s \$safe parameter must not have a default — every construction site states its safe mode")
                ->and((string) $safeParam?->getType())->toBe('bool');
        }
    });

    it('leaves no setSafe() mutator on TermDefinitions — the flag is fixed at construction', function () {
        expect(method_exists(TermDefinitions::class, 'setSafe'))->toBeFalse();
    });

    it('makes the Compaction safe flag authoritative: a hand-built non-safe context is stamped safe', function () {
        // Previously safe rode along implicitly inside the TermDefinitions
        // instance, so compaction over a hand-built context silently ran with
        // safe OFF. The explicit required flag now wins: a reserved
        // (keyword-shaped) @id in a scoped-context overlay fails closed.
        $contextProcessor = new ContextProcessor([
            '@context' => [
                'thing' => [
                    '@id' => 'http://example.com/thing',
                    '@context' => ['bad' => ['@id' => '@keywordish']],
                ],
            ],
        ], new StubDocumentLoader, safe: false);
        $nonSafeContext = $contextProcessor->getTermDefinitions();
        expect($nonSafeContext->isSafe())->toBeFalse();

        $expanded = [[
            '@id' => 'http://example.com/x',
            'http://example.com/thing' => [['@id' => 'http://example.com/y']],
        ]];

        // Default mode tolerates the reserved overlay entry (silently skipped).
        expect((new Compaction($nonSafeContext, safe: false))->compact($expanded))->toBeArray();

        // Safe-constructed Compaction throws on the same overlay, even though
        // the handed-in context was built without safe.
        expect(fn () => (new Compaction($nonSafeContext, safe: true))->compact($expanded))
            ->toThrow(DataLossException::class);

        // The stamp is a copy: the caller's context is untouched.
        expect($nonSafeContext->isSafe())->toBeFalse();
    });
});
