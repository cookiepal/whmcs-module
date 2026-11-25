<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\CookiePal;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * A failure the module explains itself. Its message is shown to the WHMCS admin as is.
 */
final class ModuleException extends \RuntimeException
{
}
