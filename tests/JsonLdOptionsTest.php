<?php

declare(strict_types=1);

use Accredify\JsonLd\JsonLdOptions;

describe('JsonLdOptions', function () {
    it('defaults every option to null / false', function () {
        $options = new JsonLdOptions;
        expect($options->base)->toBeNull();
        expect($options->processingMode)->toBeNull();
        expect($options->rdfDirection)->toBeNull();
        expect($options->produceGeneralizedRdf)->toBeFalse();
    });

    it('exposes the constructor values', function () {
        $options = new JsonLdOptions(base: 'http://example/', processingMode: 'json-ld-1.0');
        expect($options->base)->toBe('http://example/');
        expect($options->processingMode)->toBe('json-ld-1.0');
    });

    it('derives a copy with overridden fields, leaving the rest intact', function () {
        $options = new JsonLdOptions(base: 'http://example/', processingMode: 'json-ld-1.1');
        $derived = $options->with(processingMode: 'json-ld-1.0');
        expect($derived->base)->toBe('http://example/');
        expect($derived->processingMode)->toBe('json-ld-1.0');
        // Original is unchanged (immutable).
        expect($options->processingMode)->toBe('json-ld-1.1');
    });
});
