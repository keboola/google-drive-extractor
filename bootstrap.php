<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

/**
 * Mute 3rd-party deprecations (Guzzle/promises etc.) *before* autoload.
 * This prevents “Deprecated:” lines during vendor autoloading.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

/**
 * Convert only real problems into exceptions during tests.
 * Deprecations are already masked above, so let them pass.
 */
set_error_handler(
    static function (int $errno, string $errstr, string $errfile, int $errline): bool {
        if (0 === error_reporting()) { // suppressed with @
            return false;
        }
        if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
            return false;
        }
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    }
);

// Root-level bootstrap → autoload lives in ./vendor
require __DIR__ . '/vendor/autoload.php';
