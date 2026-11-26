<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\CookiePal;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * The strings the client sees: lang/english.php, overlaid with the client's language file when one exists
 * and loads.
 */
final class Lang
{
    public const DIRECTORY = __DIR__ . '/../lang';

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, string>
     */
    public static function forParams(array $params, string $directory = self::DIRECTORY): array
    {
        $strings = self::load($directory . '/english.php');
        $language = self::language($params);
        if ($language !== 'english' && is_file($directory . '/' . $language . '.php')) {
            try {
                $strings = array_merge($strings, self::load($directory . '/' . $language . '.php'));
            } catch (\Throwable) {
                // A host's translation with a syntax error mustn't break the page: English it is.
            }
        }

        return $strings;
    }

    /**
     * The client's language, else the session's, else WHMCS's default; 'english' when none is a safe file name.
     *
     * @param array<string, mixed> $params
     */
    private static function language(array $params): string
    {
        $client = is_array($params['clientsdetails'] ?? null) ? $params['clientsdetails'] : [];
        $candidates = [
            $client['language'] ?? null,
            $_SESSION['Language'] ?? null,
            class_exists(\WHMCS\Config\Setting::class) ? \WHMCS\Config\Setting::getValue('Language') : null,
        ];
        foreach ($candidates as $candidate) {
            $name = is_string($candidate) ? strtolower(trim($candidate)) : '';
            if ($name !== '') {
                return preg_match('/^[a-z0-9_-]+\z/', $name) === 1 ? $name : 'english';
            }
        }

        return 'english';
    }

    /** @return array<string, string> */
    private static function load(string $file): array
    {
        $_LANG = [];
        include $file;

        return array_filter($_LANG, 'is_string');
    }
}
