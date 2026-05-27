<?php

declare(strict_types=1);

namespace Anibalealvarezs\GoogleHubDriver\Enums;

enum GoogleChannel: string
{
    case SEARCH_CONSOLE = 'google_search_console';
    case ANALYTICS = 'google_analytics';
    case BUSINESS_PROFILE = 'google_business_profile';

    /**
     * Get all channels as an array of strings.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
