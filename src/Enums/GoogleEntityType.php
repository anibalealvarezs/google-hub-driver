<?php

declare(strict_types=1);

namespace Anibalealvarezs\GoogleHubDriver\Enums;

enum GoogleEntityType: string
{
    case SITE = 'gsc_site';
    case LOCATION = 'google_business';

    /**
     * Get all entity types as an array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
