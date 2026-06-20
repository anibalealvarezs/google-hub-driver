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
            object|string|null $account = null,
            object|string|null $channeledAccount = null,
            object|string|null $period = 'daily',
            array              $metricsToProcess = [],
            ?string            $customFields = null,
        ): ArrayCollection
        {
            $metricsList = !empty($metricsToProcess) ? $metricsToProcess : ($customFields ? explode(',', $customFields) : ['metrics.cost_micros', 'metrics.impressions', 'metrics.clicks', 'metrics.conversions']);
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
            $metricsList = !empty($metricsToProcess) ? $metricsToProcess : ($customFields ? explode(',', $customFields) : ['metrics.cost_micros', 'metrics.impressions', 'metrics.clicks', 'metrics.conversions']);

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
                'dimensions'           => ['segments.device'],
                'metadata_fields'      => self::METADATA_FIELDS,
                'context'              => UniversalMetricConverter::getUniversalContext([
                    'account'            => $account,
                    'channeledAccount'   => $channeledAccount,
                    'channeledAccountId' => $channeledAccountId,
                    'campaign'           => $campaign,
                    'channeledCampaign'  => $channeledCampaign,
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
            $metricsList = !empty($metricsToProcess) ? $metricsToProcess : ($customFields ? explode(',', $customFields) : ['metrics.cost_micros', 'metrics.impressions', 'metrics.clicks', 'metrics.conversions']);

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
            $metricsList = !empty($metricsToProcess) ? $metricsToProcess : ($customFields ? explode(',', $customFields) : ['metrics.cost_micros', 'metrics.impressions', 'metrics.clicks', 'metrics.conversions']);

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
                'dimensions'           => ['segments.device'],
                'metadata_fields'      => self::METADATA_FIELDS,
                'context'              => UniversalMetricConverter::getUniversalContext([
                    'account'            => $account,
                    'channeledAccount'   => $channeledAccount,
                    'channeledAccountId' => $channeledAccountId,
                    'campaign'           => $campaign,
                    'channeledCampaign'  => $channeledCampaign,
                    'channeledAdGroup'   => $channeledAdGroup,
                    'channeledAd'        => $channeledAd,
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
                // We will flatten it using dot notation so UniversalMetricConverter can access it, or map directly.
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

                // Derived metrics mappings - keep raw GAQL metric names but fix micros scaling
                if (isset($processed['metrics.cost_micros'])) {
                    $processed['metrics.cost_micros'] = (float)$processed['metrics.cost_micros'] / 1000000;
                }
                if (isset($processed['metrics.cost_per_conversion'])) {
                    $processed['metrics.cost_per_conversion'] = (float)$processed['metrics.cost_per_conversion'] / 1000000;
                }
                
                // Keep the original nested objects for backwards compatibility or fallback
                $processed = array_merge($row, $processed);

                return $processed;
            }, $rows);
        }
    }
