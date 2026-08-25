<?php

/**
 * Locate a Composer autoloader for the gen-frontend CLI.
 *
 * Two installation shapes have to work, and they put vendor/ in different places:
 *
 *   - developed in place       → packages/generator-engine/vendor/autoload.php
 *   - installed as dependency  → <project>/vendor/autoload.php, with this file at
 *                                <project>/vendor/blutrixx/generator-engine/bin/
 *
 * Nothing here boots a framework — the autoloader is the whole runtime the
 * frontend generators need.
 */

$candidates = [
    // Installed as a dependency: climb out of vendor/blutrixx/generator-engine/bin.
    __DIR__ . '/../../../autoload.php',
    // Developed in place.
    __DIR__ . '/../vendor/autoload.php',
];

foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        require_once $candidate;

        return;
    }
}

fwrite(
    STDERR,
    "gen-frontend: could not find a Composer autoloader.\n"
    . "Run `composer install` in the generator-engine package, or install it as a project dependency.\n"
    . "Looked in:\n  - " . implode("\n  - ", $candidates) . "\n"
);

exit(1);
