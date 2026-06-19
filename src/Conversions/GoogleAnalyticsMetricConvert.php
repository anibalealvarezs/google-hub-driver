<?php

    declare(strict_types=1);

    namespace Anibalealvarezs\GoogleHubDriver\Conversions;

    use Anibalealvarezs\ApiDriverCore\Conversions\UniversalMetricConverter;
    use Doctrine\Common\Collections\ArrayCollection;
    use Psr\Log\LoggerInterface;
    use Carbon\Carbon;

    /**
     * GoogleAnalyticsMetricConvert
     *
     * Standardizes GA4 insights data into APIs Hub metric objects.
     */
    class GoogleAnalyticsMetricConvert
    {
        private const array METADATA_FIELDS = ['bounce_rate'];

        /**
         * Converts GA4 Property API rows into metrics.
         */
        public static function propertyMetrics(
            array              $response,
            ?LoggerInterface   $logger = null,
            object|string|null $account = null,
            object|string|null $channeledAccount = null,
            object|string|null $period = 'daily',
            array              $metricsToProcess = [],
        ): ArrayCollection
        {
            $metricsList = !empty($metricsToProcess) ? $metricsToProcess : ['reach', 'impressions', 'sessions', 'conversions'];
            $periodValue = is_object($period) && isset($period->value) ? $period->value : (string)$period;
            $channeledAccountId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getId') ? $channeledAccount->getId() : (string)$channeledAccount) : (string)$channeledAccount;
            $channeledPlatformId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getPlatformId') ? $channeledAccount->getPlatformId() : (string)$channeledAccount) : (string)$channeledAccount;

            $rows = self::preprocessRows($response);

            return UniversalMetricConverter::convert($rows, [
                'channel'              => 'google_analytics',
                'period'               => $periodValue,
                'platform_id_field'    => 'property_id', // Virtual property injected in preprocess
                'date_field'           => 'date',
                'metrics'              => array_combine($metricsList, $metricsList),
                'dimensions'           => ['source', 'medium'],
                'metadata_fields'      => self::METADATA_FIELDS,
                'context'              => UniversalMetricConverter::getUniversalContext([
                    'account'            => $account,
                    'channeledAccount'   => $channeledAccount,
                    'channeledAccountId' => $channeledAccountId,
                ]),
                'row_key_fields'       => [
                    'property_id' => ['channeledAccount'],
                ],
                'fallback_platform_id' => $channeledPlatformId
            ], $logger);
        }

        /**
         * Converts GA4 Campaign API rows into metrics.
         */
        public static function campaignMetrics(
            array              $response,
            ?LoggerInterface   $logger = null,
            object|string|null $channeledAccount = null,
            object|string|null $period = 'daily',
            array              $metricsToProcess = [],
            object|string|null $account = null,
        ): ArrayCollection
        {
            $metricsList = !empty($metricsToProcess) ? $metricsToProcess : ['reach', 'impressions', 'sessions', 'conversions'];

            $channeledAccountId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getId') ? $channeledAccount->getId() : (string)$channeledAccount) : (string)$channeledAccount;
            $channeledPlatformId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getPlatformId') ? $channeledAccount->getPlatformId() : (string)$channeledAccount) : (string)$channeledAccount;
            $periodValue = is_object($period) && isset($period->value) ? $period->value : (string)$period;

            $rows = self::preprocessRows($response);

            return UniversalMetricConverter::convert($rows, [
                'channel'              => 'google_analytics',
                'period'               => $periodValue,
                'platform_id_field'    => 'sessionCampaignName',
                'date_field'           => 'date',
                'metrics'              => array_combine($metricsList, $metricsList),
                'dimensions'           => ['source', 'medium'],
                'metadata_fields'      => self::METADATA_FIELDS,
                'context'              => UniversalMetricConverter::getUniversalContext([
                    'account'            => $account,
                    'channeledAccount'   => $channeledAccount,
                    'channeledAccountId' => $channeledAccountId,
                ]),
                'row_key_fields'       => [
                    'property_id'         => ['channeledAccount'],
                    'sessionCampaignName' => ['campaign', 'channeledCampaign'],
                ],
                'row_entity_fields'    => [
                    'sessionCampaignName' => 'channeledCampaign',
                ],
                'fallback_platform_id' => $channeledPlatformId
            ], $logger);
        }

        /**
         * Converts GA4 Page API rows into metrics.
         */
        public static function pageMetrics(
            array              $response,
            ?LoggerInterface   $logger = null,
            object|string|null $channeledAccount = null,
            object|string|null $period = 'daily',
            array              $metricsToProcess = [],
            object|string|null $account = null,
        ): ArrayCollection
        {
            $metricsList = !empty($metricsToProcess) ? $metricsToProcess : ['reach', 'impressions', 'sessions', 'conversions'];

            $channeledAccountId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getId') ? $channeledAccount->getId() : (string)$channeledAccount) : (string)$channeledAccount;
            $channeledPlatformId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getPlatformId') ? $channeledAccount->getPlatformId() : (string)$channeledAccount) : (string)$channeledAccount;
            $periodValue = is_object($period) && isset($period->value) ? $period->value : (string)$period;

            $rows = self::preprocessRows($response);

            return UniversalMetricConverter::convert($rows, [
                'channel'              => 'google_analytics',
                'period'               => $periodValue,
                'platform_id_field'    => 'property_id', // We attribute the metric to the account
                'date_field'           => 'date',
                'metrics'              => array_combine($metricsList, $metricsList),
                'dimensions'           => ['page', 'source', 'medium'], // page is a dimension key here
                'metadata_fields'      => self::METADATA_FIELDS,
                'context'              => UniversalMetricConverter::getUniversalContext([
                    'account'            => $account,
                    'channeledAccount'   => $channeledAccount,
                    'channeledAccountId' => $channeledAccountId,
                ]),
                'row_key_fields'       => [
                    'property_id' => ['channeledAccount'],
                ],
                'fallback_platform_id' => $channeledPlatformId
            ], $logger);
        }

        /**
         * Converts GA4 Event API rows into metrics.
         */
        public static function eventMetrics(
            array              $response,
            ?LoggerInterface   $logger = null,
            object|string|null $channeledAccount = null,
            object|string|null $period = 'daily',
            array              $metricsToProcess = [],
            object|string|null $account = null,
        ): ArrayCollection
        {
            $metricsList = !empty($metricsToProcess) ? $metricsToProcess : ['reach', 'impressions', 'sessions', 'conversions'];

            $channeledAccountId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getId') ? $channeledAccount->getId() : (string)$channeledAccount) : (string)$channeledAccount;
            $channeledPlatformId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getPlatformId') ? $channeledAccount->getPlatformId() : (string)$channeledAccount) : (string)$channeledAccount;
            $periodValue = is_object($period) && isset($period->value) ? $period->value : (string)$period;

            $rows = self::preprocessRows($response);

            return UniversalMetricConverter::convert($rows, [
                'channel'              => 'google_analytics',
                'period'               => $periodValue,
                'platform_id_field'    => 'eventName',
                'date_field'           => 'date',
                'metrics'              => array_combine($metricsList, $metricsList),
                'dimensions'           => ['source', 'medium'],
                'metadata_fields'      => self::METADATA_FIELDS,
                'context'              => UniversalMetricConverter::getUniversalContext([
                    'account'            => $account,
                    'channeledAccount'   => $channeledAccount,
                    'channeledAccountId' => $channeledAccountId,
                ]),
                'row_key_fields'       => [
                    'property_id' => ['channeledAccount'],
                    'eventName'   => ['event', 'channeledEvent'],
                ],
                'row_entity_fields'    => [
                    'eventName' => 'channeledEvent',
                ],
                'fallback_platform_id' => $channeledPlatformId
            ], $logger);
        }

        /**
         * Metrics proxy for dynamic levels.
         */
        public static function metrics(
            array              $response,
            object|string      $channeledAccount,
            string             $level = 'account',
            ?LoggerInterface   $logger = null,
            object|string|null $account = null,
        ): ArrayCollection
        {
            return match ($level) {
                'campaign' => self::campaignMetrics(response: $response, logger: $logger, channeledAccount: $channeledAccount, account: $account),
                'page' => self::pageMetrics(response: $response, logger: $logger, channeledAccount: $channeledAccount, account: $account),
                'event' => self::eventMetrics(response: $response, logger: $logger, channeledAccount: $channeledAccount, account: $account),
                default => self::propertyMetrics(response: $response, logger: $logger, channeledAccount: $channeledAccount, account: $account),
            };
        }

        /**
         * Pre-processes GA4 response, mapping array of dimensionValues/metricValues into simple associative array.
         */
        public static function preprocessRows(array $response): array
        {
            if (empty($response['rows'])) {
                return [];
            }

            $dimensionHeaders = array_map(fn($h) => $h['name'], $response['dimensionHeaders'] ?? []);
            $metricHeaders = array_map(fn($h) => $h['name'], $response['metricHeaders'] ?? []);
            $propertyId = $response['property_id'] ?? 'unknown_property';

            return array_map(function ($row) use ($dimensionHeaders, $metricHeaders, $propertyId) {
                $processed = ['property_id' => (string)$propertyId];

                // Map Dimensions
                $dimValues = $row['dimensionValues'] ?? [];
                foreach ($dimensionHeaders as $idx => $dimName) {
                    $val = $dimValues[$idx]['value'] ?? '';
                    
                    // Specific mapping for GA4 dimensions
                    if ($dimName === 'date') {
                        // GA4 returns YYYYMMDD, we convert to YYYY-MM-DD
                        $processed['date'] = Carbon::createFromFormat('Ymd', $val)->format('Y-m-d');
                    } elseif ($dimName === 'sessionSourceMedium') {
                        // GA4 returns "google / cpc"
                        $parts = explode(' / ', $val);
                        $processed['source'] = trim($parts[0] ?? '');
                        $processed['medium'] = trim($parts[1] ?? '');
                        $processed[$dimName] = $val;
                    } elseif ($dimName === 'pagePath') {
                        $processed['page'] = $val;
                        $processed[$dimName] = $val;
                    } elseif ($dimName === 'sessionCampaignName') {
                        // GA4 default is "(not set)" or "(direct)"
                        if (in_array($val, ['(not set)', '(direct)', '(organic)'])) {
                            $processed['sessionCampaignName'] = null; // Do not map to a campaign entity
                        } else {
                            $processed['sessionCampaignName'] = $val;
                        }
                    } else {
                        $processed[$dimName] = $val;
                    }
                }

                // Map Metrics
                $metValues = $row['metricValues'] ?? [];
                foreach ($metricHeaders as $idx => $metName) {
                    $val = $metValues[$idx]['value'] ?? 0;
                    
                    // Specific mapping for GA4 metrics to Universal Metrics
                    if ($metName === 'activeUsers') {
                        $processed['reach'] = (int)$val;
                    } elseif ($metName === 'screenPageViews') {
                        $processed['impressions'] = (int)$val;
                    } elseif ($metName === 'sessions') {
                        $processed['sessions'] = (int)$val;
                    } elseif ($metName === 'conversions') {
                        $processed['conversions'] = (float)$val;
                    } elseif ($metName === 'bounceRate') {
                        $processed['bounce_rate'] = (float)$val;
                    } elseif ($metName === 'totalRevenue') {
                        $processed['spend'] = (float)$val;
                        $processed['revenue'] = (float)$val;
                    }
                    
                    $processed[$metName] = (float)$val;
                }

                return $processed;
            }, $response['rows']);
        }
    }
