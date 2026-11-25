<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\CookiePal;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Hostnames as people type them: the service's website domain, and the server's API host.
 */
final class Hostname
{
    /**
     * The host part of what was typed: no scheme, path, query, userinfo, port or trailing dot.
     * Case and characters are left as they are; isValid() checks them.
     */
    public static function strip(string $raw): string
    {
        $host = trim($raw);
        $host = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $host);
        $host = (string) preg_replace('#[/?\#].*$#s', '', $host);
        $host = (string) preg_replace('#^.*@#s', '', $host);
        $host = (string) preg_replace('#:\d*$#', '', $host);

        return rtrim($host, '.');
    }

    /** A lowercase ASCII hostname with at least two labels and an alphabetic (or punycode) top-level domain. */
    public static function isValid(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return false;
        }
        foreach ($labels as $label) {
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $label) !== 1) {
                return false;
            }
        }

        return preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})\z/', end($labels)) === 1;
    }

    /**
     * The website domain as the API keys it: lowercase, punycode, without a leading `www.`.
     *
     * @throws ModuleException when nothing usable is left
     */
    public static function website(string $raw): string
    {
        $host = self::strip($raw);
        if ($host === '') {
            throw new ModuleException('Enter the customer\'s website domain on the service first.');
        }
        $host = self::toAscii($host);
        if (str_starts_with($host, 'www.') && substr_count($host, '.') >= 2) {
            $host = substr($host, 4);
        }
        if (!self::isValid($host)) {
            throw new ModuleException(
                'The service\'s Domain field isn\'t a website domain such as example.com. '
                . 'Correct it, then run Create again.'
            );
        }

        return $host;
    }

    /** @throws ModuleException for a non-ASCII domain when the intl extension is missing */
    private static function toAscii(string $host): string
    {
        if (preg_match('/[\x80-\xff]/', $host) !== 1) {
            return strtolower($host);
        }
        if (!function_exists('idn_to_ascii')) {
            throw new ModuleException(
                'The service\'s domain has non-ASCII characters, and the PHP intl extension isn\'t enabled on this '
                . 'WHMCS server. Enter the domain in its xn-- form, or enable intl.'
            );
        }
        $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        return is_string($ascii) ? strtolower($ascii) : '';
    }
}
