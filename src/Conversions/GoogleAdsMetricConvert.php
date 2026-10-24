<?php

    declare(strict_types=1);

    namespace Anibalealvarezs\GoogleHubDriver\Conversions;

    use Anibalealvarezs\ApiDriverCore\Conversions\UniversalMetricConverter;
    use Doctrine\Common\Collections\ArrayCollection;
    use Psr\Log\LoggerInterface;

    /**
     * GoogleAdsMetricConvert
     *
     * Standardizes Google Ads insights data into APIs Hub metric objects.
     */
    class GoogleAdsMetricConvert
    {
        private const array METADATA_FIELDS = ['conversions_value', 'cost_per_conversion'];

        /**
         * Converts Google Ads Customer/Account API rows into metrics.
         */
        public static function customerMetrics(
            array              $rows,
            ?LoggerInterface   $logger = null,
            object|string|null $channeledAccount = null,
            object|string|null $period = 'daily',
            array              $metricsToProcess = [],
            ?string            $customFields = null,
            object|string|null $account = null,
        ): ArrayCollection
        {
            $defaultMetrics = ['spend', 'impressions', 'clicks', 'conversions'];
            $metricsList = !empty($metricsToProcess) ? $metricsToProcess : ($customFields ? explode(',', $customFields) : $defaultMetrics);
            $periodValue = is_object($period) && isset($period->value) ? $period->value : (string)$period;
            $channeledAccountId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getId') ? $channeledAccount->getId() : (string)$channeledAccount) : (string)$channeledAccount;
            $channeledPlatformId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getPlatformId') ? $channeledAccount->getPlatformId() : (string)$channeledAccount) : (string)$channeledAccount;

            $rows = self::preprocessRows($rows);

            return UniversalMetricConverter::convert($rows, [
                'channel'              => 'google_ads',
                'period'               => $periodValue,
                'platform_id_field'    => 'customer.id',
                'date_field'           => 'segments.date',
                'metrics'              => array_combine($metricsList, $metricsList),
                'dimensions'           => ['segments.device'],
                'metadata_fields'      => self::METADATA_FIELDS,
                'context'              => UniversalMetricConverter::getUniversalContext([
                    'account'            => $account,
                    'channeledAccount'   => $channeledAccount,
                    'channeledAccountId' => $channeledAccountId,
                    'level'              => 'account',
                ]),
                'row_key_fields'       => [],
                'fallback_platform_id' => $channeledPlatformId
            ], $logger);
        }

        /**
         * Converts Google Ads Campaign API rows into metrics.
         */
        public static function campaignMetrics(
            array              $rows,
            ?LoggerInterface   $logger = null,
            object|string|null $channeledAccount = null,
            object|string|null $campaign = null,
            object|string|null $channeledCampaign = null,
            object|string|null $period = 'daily',
            array              $metricsToProcess = [],
            ?string            $customFields = null,
            object|string|null $account = null,
        ): ArrayCollection
        {
            $defaultMetrics = ['spend', 'impressions', 'clicks', 'conversions'];
            $metricsList = !empty($metricsToProcess) ? $metricsToProcess : ($customFields ? explode(',', $customFields) : $defaultMetrics);

            $channeledAccountId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getId') ? $channeledAccount->getId() : (string)$channeledAccount) : (string)$channeledAccount;
            $channeledCampaignId = is_object($channeledCampaign) ? (method_exists($channeledCampaign, 'getPlatformId') ? $channeledCampaign->getPlatformId() : (string)$channeledCampaign) : (string)$channeledCampaign;
            $periodValue = is_object($period) && isset($period->value) ? $period->value : (string)$period;

            $rows = self::preprocessRows($rows);

            return UniversalMetricConverter::convert($rows, [
                'channel'              => 'google_ads',
                'period'               => $periodValue,
                'platform_id_field'    => 'campaign.id',
                'date_field'           => 'segments.date',
                'metrics'              => array_combine($metricsList, $metricsList),
                'dimensions'           => ['segments.device', 'campaign.advertising_channel_type'],
                'metadata_fields'      => self::METADATA_FIELDS,
                'context'              => UniversalMetricConverter::getUniversalContext([
                    'account'            => $account,
                    'channeledAccount'   => $channeledAccount,
                    'channeledAccountId' => $channeledAccountId,
                    'campaign'           => $campaign,
                    'channeledCampaign'  => $channeledCampaign,
                    'level'              => 'campaign',
                ]),
                'row_key_fields'       => [
                    'campaign.id' => ['campaign', 'channeledCampaign'],
                ],
                'row_entity_fields'    => [
                    'campaign.id' => 'channeledCampaign',
                ],
                'fallback_platform_id' => $channeledCampaignId
            ], $logger);
        }

        /**
         * Converts Google Ads AdGroup API rows into metrics.
         */
        public static function adGroupMetrics(
            array              $rows,
            ?LoggerInterface   $logger = null,
            object|string|null $channeledAccount = null,
            object|string|null $campaign = null,
            object|string|null $channeledCampaign = null,
            object|string|null $channeledAdGroup = null,
            object|string|null $period = 'daily',
            array              $metricsToProcess = [],
            ?string            $customFields = null,
            object|string|null $account = null,
        ): ArrayCollection
        {
            $defaultMetrics = ['spend', 'impressions', 'clicks', 'conversions'];
            $metricsList = !empty($metricsToProcess) ? $metricsToProcess : ($customFields ? explode(',', $customFields) : $defaultMetrics);

            $channeledAccountId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getId') ? $channeledAccount->getId() : (string)$channeledAccount) : (string)$channeledAccount;
            $channeledAdGroupId = is_object($channeledAdGroup) ? (method_exists($channeledAdGroup, 'getPlatformId') ? $channeledAdGroup->getPlatformId() : (string)$channeledAdGroup) : (string)$channeledAdGroup;
            $periodValue = is_object($period) && isset($period->value) ? $period->value : (string)$period;

            $rows = self::preprocessRows($rows);

            return UniversalMetricConverter::convert($rows, [
                'channel'              => 'google_ads',
                'period'               => $periodValue,
                'platform_id_field'    => 'ad_group.id',
                'date_field'           => 'segments.date',
                'metrics'              => array_combine($metricsList, $metricsList),
                'dimensions'           => ['segments.device'],
                'metadata_fields'      => self::METADATA_FIELDS,
                'context'              => UniversalMetricConverter::getUniversalContext([
                    'account'            => $account,
                    'channeledAccount'   => $channeledAccount,
                    'channeledAccountId' => $channeledAccountId,
                    'campaign'           => $campaign,
                    'channeledCampaign'  => $channeledCampaign,
                    'channeledAdGroup'   => $channeledAdGroup,
                    'level'              => 'ad_group',
                ]),
                'row_key_fields'       => [
                    'campaign.id' => ['campaign', 'channeledCampaign'],
                    'ad_group.id' => ['channeledAdGroup'],
                ],
                'row_entity_fields'    => [
                    'campaign.id' => 'channeledCampaign',
                    'ad_group.id' => 'channeledAdGroup',
                ],
                'fallback_platform_id' => $channeledAdGroupId
            ], $logger);
        }

        /**
         * Converts Google Ads Ad API rows into metrics.
         */
        public static function adMetrics(
            array              $rows,
            ?LoggerInterface   $logger = null,
            object|string|null $channeledAccount = null,
            object|string|null $campaign = null,
            object|string|null $channeledCampaign = null,
            object|string|null $channeledAdGroup = null,
            object|string|null $channeledAd = null,
            object|string|null $period = 'daily',
            array              $metricsToProcess = [],
            ?string            $customFields = null,
            object|string|null $account = null,
        ): ArrayCollection
        {
            $defaultMetrics = ['spend', 'impressions', 'clicks', 'conversions'];
            $metricsList = !empty($metricsToProcess) ? $metricsToProcess : ($customFields ? explode(',', $customFields) : $defaultMetrics);

            $channeledAccountId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getId') ? $channeledAccount->getId() : (string)$channeledAccount) : (string)$channeledAccount;
            $channeledAdId = is_object($channeledAd) ? (method_exists($channeledAd, 'getPlatformId') ? $channeledAd->getPlatformId() : (string)$channeledAd) : (string)$channeledAd;
            $periodValue = is_object($period) && isset($period->value) ? $period->value : (string)$period;

            $rows = self::preprocessRows($rows);

            return UniversalMetricConverter::convert($rows, [
                'channel'              => 'google_ads',
                'period'               => $periodValue,
                'platform_id_field'    => 'ad_group_ad.ad.id',
                'date_field'           => 'segments.date',
                'metrics'              => array_combine($metricsList, $metricsList),
                'dimensions'           => ['segments.device', 'campaign.advertising_channel_type'],
                'metadata_fields'      => self::METADATA_FIELDS,
                'context'              => UniversalMetricConverter::getUniversalContext([
                    'account'            => $account,
                    'channeledAccount'   => $channeledAccount,
                    'channeledAccountId' => $channeledAccountId,
                    'campaign'           => $campaign,
                    'channeledCampaign'  => $channeledCampaign,
                    'channeledAdGroup'   => $channeledAdGroup,
                    'channeledAd'        => $channeledAd,
                    'level'              => 'ad',
                ]),
                'row_key_fields'       => [
                    'campaign.id'       => ['campaign', 'channeledCampaign'],
                    'ad_group.id'       => ['channeledAdGroup'],
                    'ad_group_ad.ad.id' => ['channeledAd'],
                ],
                'row_entity_fields'    => [
                    'campaign.id'       => 'channeledCampaign',
                    'ad_group.id'       => 'channeledAdGroup',
                    'ad_group_ad.ad.id' => 'channeledAd',
                ],
                'fallback_platform_id' => $channeledAdId
            ], $logger);
        }

        /**
         * Converts Google Ads Search Term API rows into metrics.
         */
        public static function searchTermMetrics(
            array              $rows,
            ?LoggerInterface   $logger = null,
            object|string|null $channeledAccount = null,
            object|string|null $campaign = null,
            object|string|null $channeledCampaign = null,
            object|string|null $channeledAdGroup = null,
            object|string|null $period = 'daily',
            array              $metricsToProcess = [],
            ?string            $customFields = null,
            object|string|null $account = null,
        ): ArrayCollection
        {
            $defaultMetrics = ['spend', 'impressions', 'clicks', 'conversions'];
            $metricsList = !empty($metricsToProcess) ? $metricsToProcess : ($customFields ? explode(',', $customFields) : $defaultMetrics);

            $channeledAccountId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getId') ? $channeledAccount->getId() : (string)$channeledAccount) : (string)$channeledAccount;
            $periodValue = is_object($period) && isset($period->value) ? $period->value : (string)$period;

            $rows = self::preprocessRows($rows);

            return UniversalMetricConverter::convert($rows, [
                'channel'              => 'google_ads',
                'period'               => $periodValue,
                'platform_id_field'    => 'search_term_view.search_term',
                'date_field'           => 'segments.date',
                'metrics'              => array_combine($metricsList, $metricsList),
                'dimensions'           => ['segments.device', 'search_term_view.status'],
                'metadata_fields'      => self::METADATA_FIELDS,
                'context'              => UniversalMetricConverter::getUniversalContext([
                    'account'            => $account,
                    'channeledAccount'   => $channeledAccount,
                    'channeledAccountId' => $channeledAccountId,
                    'campaign'           => $campaign,
                    'channeledCampaign'  => $channeledCampaign,
                    'channeledAdGroup'   => $channeledAdGroup,
                    'level'              => 'query',
                ]),
                'row_key_fields'       => [
                    'campaign.id'                 => ['campaign', 'channeledCampaign'],
                    'ad_group.id'                 => ['channeledAdGroup'],
                    'search_term_view.search_term' => ['query'],
                ],
                'row_entity_fields'    => [
                    'campaign.id'                 => 'channeledCampaign',
                    'ad_group.id'                 => 'channeledAdGroup',
                    'search_term_view.search_term' => 'query',
                ],
                'fallback_platform_id' => 'search_term'
            ], $logger);
        }

        /**
         * Metrics proxy for dynamic levels.
         */
        public static function metrics(
            array              $rows,
            object|string      $channeledAccount,
            string             $level = 'account',
            ?LoggerInterface   $logger = null,
            object|string|null $account = null,
        ): ArrayCollection
        {
            return match ($level) {
                'campaign' => self::campaignMetrics(rows: $rows, logger: $logger, channeledAccount: $channeledAccount, account: $account),
                'ad_group', 'adset' => self::adGroupMetrics(rows: $rows, logger: $logger, channeledAccount: $channeledAccount, account: $account),
                'ad' => self::adMetrics(rows: $rows, logger: $logger, channeledAccount: $channeledAccount, account: $account),
                'search_term', 'query' => self::searchTermMetrics(rows: $rows, logger: $logger, channeledAccount: $channeledAccount, account: $account),
                default => self::customerMetrics(rows: $rows, logger: $logger, channeledAccount: $channeledAccount, account: $account),
            };
        }

        /**
         * Pre-processes Google Ads rows, mapping GAQL fields to simple metric names.
         * For example, flattens `metrics.cost_micros` into `spend`, dividing by 1M to maintain precision.
         */
        private static function preprocessRows(array $rows): array
        {
            return array_map(function ($row) {
                $processed = [];

                // Flatten GAQL results. Google Ads returns nested arrays e.g. ['metrics' => ['cost_micros' => '1000000'], 'segments' => ['date' => '2025-01-01']]
                $flatten = function ($array, $prefix = '') use (&$flatten, &$processed) {
                    foreach ($array as $key => $value) {
                        $newKey = $prefix ? $prefix . '.' . $key : $key;
                        if (is_array($value) && !empty($value)) {
                            // Only flatten if it is an associative array (has string keys)
                            if (count(array_filter(array_keys($value), 'is_string')) > 0) {
                                $flatten($value, $newKey);
                            } else {
                                $processed[$newKey] = $value;
                            }
                        } else {
                            $processed[$newKey] = $value;
                        }
                    }
                };

                $flatten($row);

                // Derived metrics mappings - convert micros to currency units and provide canonical aliases
                // Note: Google Ads REST API returns camelCase properties (costMicros, costPerConversion, averageCpc, conversionsValue)
                $costMicros = $processed['metrics.costMicros'] ?? $processed['metrics.cost_micros'] ?? null;
                if ($costMicros !== null) {
                    $spendVal = (float)$costMicros / 1000000;
                    $processed['metrics.cost_micros'] = $spendVal;
                    $processed['metrics.costMicros'] = $spendVal;
                    $processed['spend'] = $spendVal;
                }

                $costPerConversion = $processed['metrics.costPerConversion'] ?? $processed['metrics.cost_per_conversion'] ?? null;
                if ($costPerConversion !== null) {
                    $cpcVal = (float)$costPerConversion / 1000000;
                    $processed['metrics.cost_per_conversion'] = $cpcVal;
                    $processed['cost_per_conversion'] = $cpcVal;
                }

                $averageCpc = $processed['metrics.averageCpc'] ?? $processed['metrics.average_cpc'] ?? null;
                if ($averageCpc !== null) {
                    $avgCpcVal = (float)$averageCpc / 1000000;
                    $processed['metrics.average_cpc'] = $avgCpcVal;
                    $processed['cpc'] = $avgCpcVal;
                }

                $conversionsValue = $processed['metrics.conversionsValue'] ?? $processed['metrics.conversions_value'] ?? null;
                if ($conversionsValue !== null) {
                    $convVal = (float)$conversionsValue;
                    $processed['conversions_value'] = $convVal;
                    $processed['roas_purchase'] = $convVal;
                }

                if (isset($processed['metrics.conversions'])) {
                    $processed['conversions'] = (float)$processed['metrics.conversions'];
                }
                if (isset($processed['metrics.clicks'])) {
                    $processed['clicks'] = (int)$processed['metrics.clicks'];
                }
                if (isset($processed['metrics.impressions'])) {
                    $processed['impressions'] = (int)$processed['metrics.impressions'];
                }

                // Normalize segments.device to canonical lowercase format
                if (isset($processed['segments.device'])) {
                    $deviceNorm = match (strtoupper((string)$processed['segments.device'])) {
                        'DESKTOP' => 'desktop',
                        'HIGH_END_MOBILE', 'MOBILE' => 'mobile',
                        'TABLET' => 'tablet',
                        'CONNECTED_TV' => 'connected_tv',
                        default => strtolower((string)$processed['segments.device']),
                    };
                    $processed['segments.device'] = $deviceNorm;
                    $processed['device'] = $deviceNorm;
                    $processed['device_type'] = $deviceNorm;
                }

                // Keep the original nested objects for backwards compatibility or fallback
                $processed = array_merge($row, $processed);

                return $processed;
            }, $rows);
        }
    }
