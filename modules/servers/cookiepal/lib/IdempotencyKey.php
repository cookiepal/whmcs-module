<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\CookiePal;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * The `idempotencyKey` Create sends, so that running it again after a lost answer gets CookiePal's first
 * answer back (a 200 with the same body) instead of a 409. It isn't a secret: it's in the Module Log.
 */
final class IdempotencyKey
{
    /**
     * The key for a service's Create, or null when WHMCS has no System URL (then none is sent, as before).
     *
     * CookiePal matches keys within one agency, and an agency can use one API key on several WHMCS
     * installs, where service IDs repeat: the hash of the install's System URL keeps their service #101s
     * apart. A service created again after Terminate gets a new key, from the time it was terminated, so it
     * never gets its old website back.
     */
    public static function forCreate(int $serviceId, ?ServiceRecord $previous): ?string
    {
        $install = self::install();
        if ($install === null || $serviceId <= 0) {
            return null;
        }
        $key = 'whmcs-' . $install . '-' . $serviceId;
        if ($previous !== null && $previous->terminatedAt !== null) {
            $key .= '-' . preg_replace('/\D/', '', $previous->terminatedAt);
        }

        return $key;
    }

    /** The first 16 hex characters of sha256 of the System URL, without its scheme, case or final slash. */
    private static function install(): ?string
    {
        $url = class_exists(\WHMCS\Config\Setting::class) ? \WHMCS\Config\Setting::getValue('SystemURL') : null;
        $url = is_string($url) ? (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', trim($url)) : '';
        $url = strtolower(rtrim($url, '/'));

        return $url === '' ? null : substr(hash('sha256', $url), 0, 16);
    }
}
