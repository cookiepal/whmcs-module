<?php

declare(strict_types=1);

// WHMCS defines this before it loads a module; the module refuses to load without it.
if (!defined('WHMCS')) {
    define('WHMCS', true);
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Stubs/Capsule.php';
require_once __DIR__ . '/Stubs/Setting.php';
require_once __DIR__ . '/Stubs/functions.php';
