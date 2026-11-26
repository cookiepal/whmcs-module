<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\CookiePal;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * The banner's script URL (`handler`) and the install code built from it.
 */
final class Snippet
{
    /** An https URL on a plain host with a plain path: safe to put in a link or a script tag. */
    public static function isSafeHandler(string $handler): bool
    {
        return preg_match('#^https://[A-Za-z0-9.-]+/[A-Za-z0-9._~/-]+\z#', $handler) === 1;
    }

    /** The install code, as CookiePal's dashboard builds it, or '' for a handler that isn't safe. */
    public static function forHandler(string $handler): string
    {
        if (!self::isSafeHandler($handler)) {
            return '';
        }

        return "<!-- Start cookiepal banner -->\n"
            . '<script id="cookiepal" type="text/javascript" src="' . $handler . '"></script>' . "\n"
            . '<!-- End cookiepal banner -->';
    }
}
