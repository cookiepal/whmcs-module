<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\CookiePal;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * An error from the CookiePal agency API, or from reaching it.
 *
 * The API answers errors as `{ "message": string, "code"?: string }`. API Gateway's own
 * 401 and 403 answers carry `message` (or `Message`) only. `status` is 0 when no HTTP
 * answer arrived at all.
 */
final class ApiException extends \RuntimeException
{
    public const NETWORK_ERROR = 'NETWORK_ERROR';
    public const BAD_RESPONSE = 'BAD_RESPONSE';
    public const NO_API_KEY = 'NO_API_KEY';
    public const INVALID_HOST = 'INVALID_HOST';

    /** The module's own codes: their message is already written for the admin. */
    private const MODULE_CODES = [self::NETWORK_ERROR, self::BAD_RESPONSE, self::NO_API_KEY, self::INVALID_HOST];

    public function __construct(
        private readonly int $status,
        string $message,
        private readonly string $errorCode = '',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** The API's `code` field, or one of this class's constants, or ''. */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** True when the API answered with this status, and with this `code` when one is given. */
    public function is(int $status, ?string $errorCode = null): bool
    {
        return $this->status === $status && ($errorCode === null || $this->errorCode === $errorCode);
    }

    /** The text a WHMCS admin sees when an action fails. */
    public function adminMessage(): string
    {
        if (in_array($this->errorCode, self::MODULE_CODES, true)) {
            return $this->getMessage();
        }
        // API Gateway's own answers carry no code, so these two are told apart by their message.
        if ($this->status === 403 && $this->hasMessage('Missing Authentication Token')) {
            return 'This CookiePal API host doesn\'t have this feature yet (403). Check the server\'s hostname, '
                . 'or try again once CookiePal has released it.';
        }
        if ($this->status === 403) {
            return 'CookiePal refused the API key (403). Check the key in the server\'s Password field, '
                . 'or ask CookiePal for a new one.';
        }
        if ($this->status === 401 && $this->hasMessage('Unauthorized')) {
            return 'CookiePal received no API key (401). Enter the key in the server\'s Password field.';
        }

        $text = 'CookiePal API error (' . $this->status . '): ' . $this->getMessage();

        return $this->errorCode !== '' ? $text . ' [' . $this->errorCode . ']' : $text;
    }

    private function hasMessage(string $message): bool
    {
        return strcasecmp(trim($this->getMessage()), $message) === 0;
    }
}
