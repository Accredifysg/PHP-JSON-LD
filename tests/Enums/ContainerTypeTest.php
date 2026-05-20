<?php

declare(strict_types=1);

use Accredify\JsonLd\Enums\ContainerType;

dataset('container-type-cases', function () {
    foreach (ContainerType::cases() as $case) {
        yield $case->value => [$case];
    }
});

describe('ContainerType', function () {
    it('contains returns true for every defined container value', function (ContainerType $type) {
        expect(ContainerType::contains($type->value))->toBeTrue();
    })->with('container-type-cases');

    it('contains returns false for keywords that are not container types', function () {
        expect(ContainerType::contains('@context'))->toBeFalse();
        expect(ContainerType::contains('@vocab'))->toBeFalse();
        expect(ContainerType::contains('@value'))->toBeFalse();
    });

    it('contains is case-sensitive', function () {
        expect(ContainerType::contains('@LIST'))->toBeFalse();
        expect(ContainerType::contains('@List'))->toBeFalse();
    });
});
