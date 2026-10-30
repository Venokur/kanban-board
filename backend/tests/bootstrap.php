<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * The application normally gets `App\Rt\*` from `src/rt.php`, which aliases the
 * loaded extension. Unit tests must run without OpenSwoole, so the static
 * analysis stubs (which are plain PHP classes) are loaded instead.
 */
require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../stubs/rt.stub.php';
