<?php

declare(strict_types=1);

use Accredify\JsonLd\Enums\Keyword;

dataset('keyword-cases', function () {
    foreach (Keyword::cases() as $case) {
        yield $case->value => [$case];
    }
});

describe('Keyword', function () {
    describe('contains', function () {
        it('returns true for every defined keyword value', function (Keyword $keyword) {
            expect(Keyword::contains($keyword->value))->toBeTrue();
        })->with('keyword-cases');

        it('returns false for unknown values', function (string $value) {
            expect(Keyword::contains($value))->toBeFalse();
        })->with([
            'id alias (without @)' => ['id'],
            'made-up keyword' => ['@foo'],
            'empty string' => [''],
            'whitespace' => [' @id'],
            'wrong case' => ['@ID'],
        ]);
    });

    describe('withoutAtSign', function () {
        it('strips the leading @ from every keyword', function (Keyword $keyword) {
            $stripped = $keyword->withoutAtSign();
            expect($stripped)->toBe(substr($keyword->value, 1));
            expect(str_starts_with($stripped, '@'))->toBeFalse();
        })->with('keyword-cases');
    });
});
