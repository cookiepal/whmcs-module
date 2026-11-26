<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\CookiePal;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * The four plans an agency can sell, and which one a service gets.
 */
final class Plan
{
    public const LABELS = [
        'free' => 'Free',
        'starter' => 'Starter',
        'growth' => 'Growth',
        'business' => 'Business',
    ];

    /** The API's name for any plan outside the four, such as one the customer chose in CookiePal. */
    public const OTHER = 'other';

    /**
     * A configurable option named "Plan" when the service has one, otherwise the product's Plan setting.
     *
     * @param array<string, mixed> $params
     *
     * @throws ModuleException when neither is one of the four plans
     */
    public static function fromParams(array $params): string
    {
        $options = $params['configoptions'] ?? [];
        if (is_array($options)) {
            foreach ($options as $name => $value) {
                $value = is_scalar($value) ? trim((string) $value) : '';
                if (strcasecmp(trim((string) $name), 'Plan') !== 0 || $value === '') {
                    continue;
                }
                $plan = strtolower($value);
                if (!isset(self::LABELS[$plan])) {
                    throw new ModuleException(
                        'The "Plan" configurable option is "' . $value . '". '
                        . 'Its values must be free, starter, growth or business.'
                    );
                }

                return $plan;
            }
        }

        $setting = $params['configoption1'] ?? '';
        $plan = is_string($setting) ? strtolower(trim($setting)) : '';
        if (!isset(self::LABELS[$plan])) {
            throw new ModuleException(
                'Choose a CookiePal plan (free, starter, growth or business) in the product\'s Module Settings.'
            );
        }

        return $plan;
    }

    /** One of the four plan names, or OTHER, from a domain item's `plan`. */
    public static function fromApi(mixed $plan): string
    {
        return is_string($plan) && isset(self::LABELS[$plan]) ? $plan : self::OTHER;
    }
}
