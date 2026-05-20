<?php

declare(strict_types=1);

use Accredify\JsonLd\Exceptions\JsonLdException;

describe('JsonLdException', function () {
    it('extends \\Exception so it is catchable by callers expecting any throwable', function () {
        $exception = new JsonLdException('oh no');

        expect($exception)->toBeInstanceOf(Exception::class);
        expect($exception->getMessage())->toBe('oh no');
    });
});
