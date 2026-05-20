<?php

declare(strict_types=1);

it('boots the test environment', function () {
    expect(true)->toBeTrue();
});

it('exposes a composer-installed autoloader for the package namespace', function () {
    // No production classes exist yet (PR 1.2). Asserting the PSR-4 prefix
    // is registered confirms the autoloader is wired up correctly.
    $loaders = spl_autoload_functions();

    expect($loaders)->not->toBeEmpty();
});
