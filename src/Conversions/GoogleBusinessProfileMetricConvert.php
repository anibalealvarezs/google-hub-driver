<?php

declare(strict_types=1);

namespace Anibalealvarezs\GoogleHubDriver\Conversions;

use Anibalealvarezs\ApiDriverCore\Conversions\UniversalMetricConverter;
use Doctrine\Common\Collections\ArrayCollection;
use Psr\Log\LoggerInterface;

class GoogleBusinessProfileMetricConvert
{
    /**
     * Converts Google Business Profile daily metrics time series response to Universal Metric objects.
     */
    public static function convert(
        array              $response,
        object|string|null $channeledAccount = null,
        ?LoggerInterface   $logger = null,
        object|string|null $location = null,
        object|string|null $state = null,
        object|string|null $city = null
    ): ArrayCollection {
        $channeledAccountId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getId') ? $channeledAccount->getId() : (string)$channeledAccount) : (string)$channeledAccount;
        $channeledPlatformId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getPlatformId') ? $channeledAccount->getPlatformId() : (string)$channeledAccount) : (string)$channeledAccount;

        $locationPlatformId = is_object($location) ? (method_exists($location, 'getPlatformId') ? $location->getPlatformId() : (string)$location) : (string)$location;
        $stateName = is_object($state) ? (method_exists($state, 'getName') ? $state->getName() : (string)$state) : (string)$state;
        $cityName = is_object($city) ? (method_exists($city, 'getName') ? $city->getName() : (string)$city) : (string)$city;

        $rowsByDate = [];
        $timeSeriesList = $response['timeSeries']['dailyMetricTimeSeries'] ?? $response['dailyMetricTimeSeries'] ?? [];

        foreach ($timeSeriesList as $metricSeries) {
            $metricName = $metricSeries['dailyMetric'] ?? '';
            $dailyValues = $metricSeries['dailyValues'] ?? [];

            foreach ($dailyValues as $dailyValue) {
                $dateObj = $dailyValue['date'] ?? [];
                if (empty($dateObj['year'])) {
                    continue;
                }
                $dateStr = sprintf('%04d-%02d-%02d', $dateObj['year'], $dateObj['month'], $dateObj['day']);
                $value = (float)($dailyValue['value'] ?? 0);

                if (!isset($rowsByDate[$dateStr])) {
                    $rowsByDate[$dateStr] = [
                        'date' => $dateStr,
                        'location_id' => $channeledPlatformId,
                    ];
                }

                $rowsByDate[$dateStr][$metricName] = $value;

                // Accumulate to common metrics
                if (in_array($metricName, [
                    'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
                    'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
                    'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
                    'BUSINESS_IMPRESSIONS_MOBILE_SEARCH'
                ])) {
                    $rowsByDate[$dateStr]['impressions'] = ($rowsByDate[$dateStr]['impressions'] ?? 0.0) + $value;
                } elseif ($metricName === 'WEBSITE_CLICKS') {
                    $rowsByDate[$dateStr]['sessions'] = $value;
                    $rowsByDate[$dateStr]['conversions'] = ($rowsByDate[$dateStr]['conversions'] ?? 0.0) + $value;
                } elseif ($metricName === 'CALL_CLICKS') {
                    $rowsByDate[$dateStr]['calls'] = $value;
                } elseif ($metricName === 'BUSINESS_DIRECTION_REQUESTS') {
                    $rowsByDate[$dateStr]['directions'] = $value;
                } elseif ($metricName === 'BUSINESS_CONVERSATIONS') {
                    $rowsByDate[$dateStr]['conversations'] = $value;
                } elseif ($metricName === 'BUSINESS_BOOKINGS') {
                    $rowsByDate[$dateStr]['bookings'] = $value;
                } elseif ($metricName === 'BUSINESS_FOOD_ORDERS') {
                    $rowsByDate[$dateStr]['food_orders'] = $value;
                } elseif ($metricName === 'BUSINESS_FOOD_MENU_CLICKS') {
                    $rowsByDate[$dateStr]['menu_clicks'] = $value;
                }
            }
        }

        return UniversalMetricConverter::convert(array_values($rowsByDate), [
            'channel'              => 'google_business_profile',
            'period'               => 'daily',
            'platform_id_field'    => 'location_id',
            'date_field'           => 'date',
            'metrics'              => [
                'impressions' => 'impressions',
                'sessions' => 'sessions',
                'conversions' => 'conversions',
            ],
            'dimensions'           => [],
            'metadata_fields'      => ['calls', 'directions', 'conversations', 'bookings', 'food_orders', 'menu_clicks'],
            'context'              => UniversalMetricConverter::getUniversalContext([
                'channeledAccount'   => $channeledAccount,
                'channeledAccountId' => $channeledAccountId,
                'location'           => $locationPlatformId ?: null,
                'state'              => $stateName ?: null,
                'city'               => $cityName ?: null,
            ]),
            'row_key_fields'       => [
                'location_id' => ['channeledAccount'],
            ],
            'fallback_platform_id' => $channeledPlatformId
        ], $logger);
    }
}
