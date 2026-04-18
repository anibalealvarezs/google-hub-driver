<?php

declare(strict_types=1);

namespace Anibalealvarezs\GoogleHubDriver\Enums;

enum GoogleFeature: string
{
    // Global Configuration
    case ENABLED = 'enabled';
    case CACHE_HISTORY_RANGE = 'cache_history_range';
    case CACHE_AGGREGATIONS = 'cache_aggregations';

    // Cron Features
    case CRON_ENTITIES_HOUR = 'cron_entities_hour';
    case CRON_ENTITIES_MINUTE = 'cron_entities_minute';
    case CRON_RECENT_HOUR = 'cron_recent_hour';
    case CRON_RECENT_MINUTE = 'cron_recent_minute';

    // Search Console Entity Features
    case TARGET_COUNTRIES = 'target_countries';
    case TARGET_KEYWORDS = 'target_keywords';
    case LOST_ACCESS = 'lost_access';

    // Metrics
    case CLICKS = 'clicks';
    case IMPRESSIONS = 'impressions';
    case CTR = 'ctr';
    case POSITION = 'position';

    /**
     * Get cron features.
     */
    public static function cron(): array
    {
        return [
            self::CRON_ENTITIES_HOUR,
            self::CRON_ENTITIES_MINUTE,
            self::CRON_RECENT_HOUR,
            self::CRON_RECENT_MINUTE
        ];
    }
}
