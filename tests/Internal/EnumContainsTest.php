<?php

declare(strict_types=1);

use Accredify\JsonLd\Internal\EnumContains;

enum FixtureEnum: string
{
    use EnumContains;

    case Alpha = 'alpha';
    case Bravo = 'bravo';
}

describe('EnumContains', function () {
    it('returns true for every backing value', function () {
        expect(FixtureEnum::contains('alpha'))->toBeTrue();
        expect(FixtureEnum::contains('bravo'))->toBeTrue();
    });

    it('returns false for unknown values', function () {
        expect(FixtureEnum::contains('charlie'))->toBeFalse();
        expect(FixtureEnum::contains(''))->toBeFalse();
    });

    it('is case-sensitive', function () {
        expect(FixtureEnum::contains('ALPHA'))->toBeFalse();
        expect(FixtureEnum::contains('Alpha'))->toBeFalse();
    });
});
