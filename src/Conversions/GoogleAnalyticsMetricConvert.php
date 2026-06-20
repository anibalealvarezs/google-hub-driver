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
            $metricsList = !empty($metricsToProcess) ? $metricsToProcess : ['activeUsers', 'screenPageViews', 'sessions', 'conversions'];
            $periodValue = is_object($period) && isset($period->value) ? $period->value : (string)$period;
            $channeledAccountId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getId') ? $channeledAccount->getId() : (string)$channeledAccount) : (string)$channeledAccount;
            $channeledPlatformId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getPlatformId') ? $channeledAccount->getPlatformId() : (string)$channeledAccount) : (string)$channeledAccount;

            $cAcc = is_object($channeledAccount) ? $channeledAccount : null;
            $baseUrl = $cAcc && property_exists($cAcc, 'data') ? ($cAcc->data['webStreamData']['defaultUri'] ?? $cAcc->title ?? '') : '';
            $hostname = parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;
            $pageCanonicalId = $hostname ? 'ga4:domain:' . str_replace('www.', '', $hostname) : null;
            $pageContext = $pageCanonicalId ? (new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity())->setCanonicalId($pageCanonicalId)->setPlatformId($baseUrl) : null;

            $rows = self::preprocessRows($response);

            return UniversalMetricConverter::convert($rows, [
                'channel'              => 'google_analytics',
                'period'               => $periodValue,
                'platform_id_field'    => 'property_id', // Virtual property injected in preprocess
                'date_field'           => 'date',
                'metrics'              => array_combine($metricsList, $metricsList),
                'dimensions'           => [], // Pure account-level totals have no dimension breakdowns
                'metadata_fields'      => self::METADATA_FIELDS,
                'context'              => UniversalMetricConverter::getUniversalContext([
                    'account'            => $account,
                    'channeledAccount'   => $channeledAccount,
                    'channeledAccountId' => $channeledAccountId,
                    'page'               => $pageContext,
                ]),
                'row_key_fields'       => [
                    'property_id' => ['channeledAccount'],
                ],
                'fallback_platform_id' => $channeledPlatformId
            ], $logger);
        }

        /**
         * Converts GA4 Property API rows into metrics broken down by source and medium.
         */
        public static function sourceMediumMetrics(
            array              $response,
            ?LoggerInterface   $logger = null,
            object|string|null $account = null,
            object|string|null $channeledAccount = null,
            object|string|null $period = 'daily',
            array              $metricsToProcess = [],
        ): ArrayCollection
        {
            $metricsList = !empty($metricsToProcess) ? $metricsToProcess : ['activeUsers', 'screenPageViews', 'sessions', 'conversions'];
            $periodValue = is_object($period) && isset($period->value) ? $period->value : (string)$period;
            $channeledAccountId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getId') ? $channeledAccount->getId() : (string)$channeledAccount) : (string)$channeledAccount;
            $channeledPlatformId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getPlatformId') ? $channeledAccount->getPlatformId() : (string)$channeledAccount) : (string)$channeledAccount;

            $cAcc = is_object($channeledAccount) ? $channeledAccount : null;
            $baseUrl = $cAcc && property_exists($cAcc, 'data') ? ($cAcc->data['webStreamData']['defaultUri'] ?? $cAcc->title ?? '') : '';
            $hostname = parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;
            $pageCanonicalId = $hostname ? 'ga4:domain:' . str_replace('www.', '', $hostname) : null;
            $pageContext = $pageCanonicalId ? (new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity())->setCanonicalId($pageCanonicalId)->setPlatformId($baseUrl) : null;

            $rows = self::preprocessRows($response);

            return UniversalMetricConverter::convert($rows, [
                'channel'              => 'google_analytics',
                'period'               => $periodValue,
                'platform_id_field'    => 'property_id',
                'date_field'           => 'date',
                'metrics'              => array_combine($metricsList, $metricsList),
                'dimensions'           => [],
                'metadata_fields'      => self::METADATA_FIELDS,
                'context'              => UniversalMetricConverter::getUniversalContext([
                    'account'            => $account,
                    'channeledAccount'   => $channeledAccount,
                    'channeledAccountId' => $channeledAccountId,
                    'page'               => $pageContext,
                ]),
                'row_key_fields'       => [
                    'property_id' => ['channeledAccount'],
                ],
                'fallback_platform_id' => $channeledPlatformId
            ], $logger);
        }

        /**
         * Converts GA4 Property API rows into a deeply fragmented session matrix.
         */
        public static function trafficMatrixMetrics(
            array              $response,
            ?LoggerInterface   $logger = null,
            object|string|null $account = null,
            object|string|null $channeledAccount = null,
            object|string|null $period = 'daily',
            array              $metricsToProcess = [],
        ): ArrayCollection
        {
            $metricsList = !empty($metricsToProcess) ? $metricsToProcess : ['screenPageViews', 'sessions', 'bounceRate', 'totalRevenue', 'conversions'];
            $periodValue = is_object($period) && isset($period->value) ? $period->value : (string)$period;
            $channeledAccountId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getId') ? $channeledAccount->getId() : (string)$channeledAccount) : (string)$channeledAccount;
            $channeledPlatformId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getPlatformId') ? $channeledAccount->getPlatformId() : (string)$channeledAccount) : (string)$channeledAccount;

            $cAcc = is_object($channeledAccount) ? $channeledAccount : null;
            $baseUrl = $cAcc && property_exists($cAcc, 'data') ? ($cAcc->data['webStreamData']['defaultUri'] ?? $cAcc->title ?? '') : '';
            $hostname = parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;
            $pageCanonicalId = $hostname ? 'ga4:domain:' . str_replace('www.', '', $hostname) : null;
            $pageContext = $pageCanonicalId ? (new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity())->setCanonicalId($pageCanonicalId)->setPlatformId($baseUrl) : null;

            $rows = self::preprocessRows($response);
            foreach ($rows as &$row) {
                $row['scope'] = 'traffic_matrix';
            }

            return UniversalMetricConverter::convert($rows, [
                'channel'              => 'google_analytics',
                'period'               => $periodValue,
                'platform_id_field'    => 'property_id',
                'date_field'           => 'date',
                'metrics'              => array_combine($metricsList, $metricsList),
                'dimensions'           => ['scope', 'sessionDefaultChannelGroup', 'source', 'medium', 'landing_page'],
                'metadata_fields'      => self::METADATA_FIELDS,
                'context'              => UniversalMetricConverter::getUniversalContext([
                    'account'            => $account,
                    'channeledAccount'   => $channeledAccount,
                    'channeledAccountId' => $channeledAccountId,
                    'page'               => $pageContext,
                ]),
                'row_key_fields'       => [
                    'property_id'                => ['channeledAccount'],
                    'sessionCampaignName'        => ['channeledCampaign'],
                    'sessionGoogleAdsAdGroupName'   => ['channeledAdGroup'],
                    'deviceCategory'             => ['device'],
                    'countryId'                  => ['country'],
                ],
                'row_entity_fields'    => [
                    'sessionCampaignName'        => 'channeledCampaign',
                    'sessionGoogleAdsAdGroupName'   => 'channeledAdGroup',
                    'deviceCategory'             => 'deviceType',
                    'countryId'                  => 'countryCode',
                ],
                'fallback_platform_id' => $channeledPlatformId
            ], $logger);
        }

        /**
         * Converts GA4 Property API rows into a deeply fragmented event matrix.
         */
        public static function eventMatrixMetrics(
            array              $response,
            ?LoggerInterface   $logger = null,
            object|string|null $account = null,
            object|string|null $channeledAccount = null,
            object|string|null $period = 'daily',
            array              $metricsToProcess = [],
        ): ArrayCollection
        {
            $metricsList = !empty($metricsToProcess) ? $metricsToProcess : ['eventCount', 'conversions'];
            $periodValue = is_object($period) && isset($period->value) ? $period->value : (string)$period;
            $channeledAccountId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getId') ? $channeledAccount->getId() : (string)$channeledAccount) : (string)$channeledAccount;
            $channeledPlatformId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getPlatformId') ? $channeledAccount->getPlatformId() : (string)$channeledAccount) : (string)$channeledAccount;

            $cAcc = is_object($channeledAccount) ? $channeledAccount : null;
            $baseUrl = $cAcc && property_exists($cAcc, 'data') ? ($cAcc->data['webStreamData']['defaultUri'] ?? $cAcc->title ?? '') : '';
            $hostname = parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;
            $pageCanonicalId = $hostname ? 'ga4:domain:' . str_replace('www.', '', $hostname) : null;
            $pageContext = $pageCanonicalId ? (new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity())->setCanonicalId($pageCanonicalId)->setPlatformId($baseUrl) : null;

            $rows = self::preprocessRows($response);
            foreach ($rows as &$row) {
                $row['scope'] = 'event_matrix';
            }

            return UniversalMetricConverter::convert($rows, [
                'channel'              => 'google_analytics',
                'period'               => $periodValue,
                'platform_id_field'    => 'property_id',
                'date_field'           => 'date',
                'metrics'              => array_combine($metricsList, $metricsList),
                'dimensions'           => ['scope', 'sessionDefaultChannelGroup', 'source', 'medium', 'page'],
                'metadata_fields'      => self::METADATA_FIELDS,
                'context'              => UniversalMetricConverter::getUniversalContext([
                    'account'            => $account,
                    'channeledAccount'   => $channeledAccount,
                    'channeledAccountId' => $channeledAccountId,
                    'page'               => $pageContext,
                ]),
                'row_key_fields'       => [
                    'property_id'              => ['channeledAccount'],
                    'sessionCampaignName'      => ['channeledCampaign'],
                    'sessionGoogleAdsAdGroupName' => ['channeledAdGroup'],
                    'sessionManualTerm'        => ['channeledAdGroup'],
                    'sessionManualAdContent'   => ['channeledAd'],
                    'eventName'                => ['event'],
                ],
                'row_entity_fields'    => [
                    'sessionCampaignName'      => 'channeledCampaign',
                    'sessionGoogleAdsAdGroupName' => 'channeledAdGroup',
                    'sessionManualTerm'        => 'channeledAdGroup',
                    'sessionManualAdContent'   => 'channeledAd',
                    'eventName'                => 'event',
                ],
                'fallback_platform_id' => $channeledPlatformId
            ], $logger);
        }

        /**
         * Converts GA4 Property API rows into a strictly deduplicated acquisition matrix.
         */
        public static function acquisitionMatrixMetrics(
            array              $response,
            ?LoggerInterface   $logger = null,
            object|string|null $account = null,
            object|string|null $channeledAccount = null,
            object|string|null $period = 'daily',
            array              $metricsToProcess = [],
        ): ArrayCollection
        {
            $metricsList = !empty($metricsToProcess) ? $metricsToProcess : ['newUsers', 'activeUsers'];
            $periodValue = is_object($period) && isset($period->value) ? $period->value : (string)$period;
            $channeledAccountId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getId') ? $channeledAccount->getId() : (string)$channeledAccount) : (string)$channeledAccount;
            $channeledPlatformId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getPlatformId') ? $channeledAccount->getPlatformId() : (string)$channeledAccount) : (string)$channeledAccount;

            $cAcc = is_object($channeledAccount) ? $channeledAccount : null;
            $baseUrl = $cAcc && property_exists($cAcc, 'data') ? ($cAcc->data['webStreamData']['defaultUri'] ?? $cAcc->title ?? '') : '';
            $hostname = parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;
            $pageCanonicalId = $hostname ? 'ga4:domain:' . str_replace('www.', '', $hostname) : null;
            $pageContext = $pageCanonicalId ? (new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity())->setCanonicalId($pageCanonicalId)->setPlatformId($baseUrl) : null;

            $rows = self::preprocessRows($response);
            foreach ($rows as &$row) {
                $row['scope'] = 'acquisition_matrix';
            }

            return UniversalMetricConverter::convert($rows, [
                'channel'              => 'google_analytics',
                'period'               => $periodValue,
                'platform_id_field'    => 'property_id',
                'date_field'           => 'date',
                'metrics'              => array_combine($metricsList, $metricsList),
                'dimensions'           => ['scope', 'firstUserDefaultChannelGroup', 'firstUserSourceMedium'],
                'metadata_fields'      => self::METADATA_FIELDS,
                'context'              => UniversalMetricConverter::getUniversalContext([
                    'account'            => $account,
                    'channeledAccount'   => $channeledAccount,
                    'channeledAccountId' => $channeledAccountId,
                    'page'               => $pageContext,
                ]),
                'row_key_fields'       => [
                    'property_id'                => ['channeledAccount'],
                    'firstUserCampaignName'      => ['channeledCampaign'],
                    'firstUserGoogleAdsAdGroupName' => ['channeledAdGroup'],
                    'firstUserManualTerm'        => ['channeledAdGroup'],
                    'firstUserManualAdContent'   => ['channeledAd'],
                ],
                'row_entity_fields'    => [
                    'firstUserCampaignName'      => 'channeledCampaign',
                    'firstUserGoogleAdsAdGroupName' => 'channeledAdGroup',
                    'firstUserManualTerm'        => 'channeledAdGroup',
                    'firstUserManualAdContent'   => 'channeledAd',
                ],
                'fallback_platform_id' => $channeledPlatformId
            ], $logger);
        }

        /**
         * Converts GA4 Property API rows into a touchpoint matrix.
         */
        public static function touchpointMatrixMetrics(
            array              $response,
            ?LoggerInterface   $logger = null,
            object|string|null $account = null,
            object|string|null $channeledAccount = null,
            object|string|null $period = 'daily',
            array              $metricsToProcess = [],
        ): ArrayCollection
        {
            $metricsList = !empty($metricsToProcess) ? $metricsToProcess : ['activeUsers'];
            $periodValue = is_object($period) && isset($period->value) ? $period->value : (string)$period;
            $channeledAccountId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getId') ? $channeledAccount->getId() : (string)$channeledAccount) : (string)$channeledAccount;
            $channeledPlatformId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getPlatformId') ? $channeledAccount->getPlatformId() : (string)$channeledAccount) : (string)$channeledAccount;

            $cAcc = is_object($channeledAccount) ? $channeledAccount : null;
            $baseUrl = $cAcc && property_exists($cAcc, 'data') ? ($cAcc->data['webStreamData']['defaultUri'] ?? $cAcc->title ?? '') : '';
            $hostname = parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;
            $pageCanonicalId = $hostname ? 'ga4:domain:' . str_replace('www.', '', $hostname) : null;
            $pageContext = $pageCanonicalId ? (new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity())->setCanonicalId($pageCanonicalId)->setPlatformId($baseUrl) : null;

            $rows = self::preprocessRows($response);
            foreach ($rows as &$row) {
                $row['scope'] = 'touchpoint_matrix';
            }

            return UniversalMetricConverter::convert($rows, [
                'channel'              => 'google_analytics',
                'period'               => $periodValue,
                'platform_id_field'    => 'property_id',
                'date_field'           => 'date',
                'metrics'              => array_combine($metricsList, $metricsList),
                'dimensions'           => ['scope'],
                'metadata_fields'      => self::METADATA_FIELDS,
                'context'              => UniversalMetricConverter::getUniversalContext([
                    'account'            => $account,
                    'channeledAccount'   => $channeledAccount,
                    'channeledAccountId' => $channeledAccountId,
                    'page'               => $pageContext,
                ]),
                'row_key_fields'       => [
                    'property_id'         => ['channeledAccount'],
                    'sessionCampaignName' => ['channeledCampaign'],
                ],
                'row_entity_fields'    => [
                    'sessionCampaignName' => 'channeledCampaign',
                ],
                'fallback_platform_id' => $channeledPlatformId
            ], $logger);
        }

        /**
         * Metrics proxy for dynamic levels.
         */
        /**
         * Converts GA4 Property API rows into a deeply fragmented ad touchpoint matrix.
         */
        public static function adTouchpointMatrixMetrics(
            array              $response,
            ?LoggerInterface   $logger = null,
            object|string|null $account = null,
            object|string|null $channeledAccount = null,
            object|string|null $period = 'daily',
            array              $metricsToProcess = [],
        ): ArrayCollection
        {
            $metricsList = !empty($metricsToProcess) ? $metricsToProcess : ['activeUsers'];
            $periodValue = is_object($period) && isset($period->value) ? $period->value : (string)$period;
            $channeledAccountId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getId') ? $channeledAccount->getId() : (string)$channeledAccount) : (string)$channeledAccount;
            $channeledPlatformId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getPlatformId') ? $channeledAccount->getPlatformId() : (string)$channeledAccount) : (string)$channeledAccount;

            $cAcc = is_object($channeledAccount) ? $channeledAccount : null;
            $baseUrl = $cAcc && property_exists($cAcc, 'data') ? ($cAcc->data['webStreamData']['defaultUri'] ?? $cAcc->title ?? '') : '';
            $hostname = parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;
            $pageCanonicalId = $hostname ? 'ga4:domain:' . str_replace('www.', '', $hostname) : null;
            $pageContext = $pageCanonicalId ? (new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity())->setCanonicalId($pageCanonicalId)->setPlatformId($baseUrl) : null;

            $rows = self::preprocessRows($response);
            foreach ($rows as &$row) {
                $row['scope'] = 'ad_touchpoint_matrix';
            }

            return UniversalMetricConverter::convert($rows, [
                'channel'              => 'google_analytics',
                'period'               => $periodValue,
                'platform_id_field'    => 'property_id',
                'date_field'           => 'date',
                'metrics'              => array_combine($metricsList, $metricsList),
                'dimensions'           => ['scope'],
                'metadata_fields'      => self::METADATA_FIELDS,
                'context'              => UniversalMetricConverter::getUniversalContext([
                    'account'            => $account,
                    'channeledAccount'   => $channeledAccount,
                    'channeledAccountId' => $channeledAccountId,
                    'page'               => $pageContext,
                ]),
                'row_key_fields'       => [
                    'property_id'              => ['channeledAccount'],
                    'sessionCampaignName'      => ['channeledCampaign'],
                    'sessionGoogleAdsAdGroupName' => ['channeledAdGroup'],
                    'sessionManualTerm'        => ['channeledAdGroup'],
                    'sessionManualAdContent'   => ['channeledAd'],
                ],
                'row_entity_fields'    => [
                    'sessionCampaignName'      => 'channeledCampaign',
                    'sessionGoogleAdsAdGroupName' => 'channeledAdGroup',
                    'sessionManualTerm'        => 'channeledAdGroup',
                    'sessionManualAdContent'   => 'channeledAd',
                ],
                'fallback_platform_id' => $channeledPlatformId
            ], $logger);
        }

        public static function metrics(
            array              $response,
            object|string      $channeledAccount,
            string             $level = 'account',
            ?LoggerInterface   $logger = null,
            object|string|null $account = null,
            array              $metricsToProcess = []
        ): ArrayCollection
        {
            return match ($level) {
                'traffic_matrix' => self::trafficMatrixMetrics(response: $response, logger: $logger, channeledAccount: $channeledAccount, account: $account, metricsToProcess: $metricsToProcess),
                'event_matrix' => self::eventMatrixMetrics(response: $response, logger: $logger, channeledAccount: $channeledAccount, account: $account, metricsToProcess: $metricsToProcess),
                'acquisition_matrix' => self::acquisitionMatrixMetrics(response: $response, logger: $logger, channeledAccount: $channeledAccount, account: $account, metricsToProcess: $metricsToProcess),
                'touchpoint_matrix' => self::touchpointMatrixMetrics(response: $response, logger: $logger, channeledAccount: $channeledAccount, account: $account, metricsToProcess: $metricsToProcess),
                'ad_touchpoint_matrix' => self::adTouchpointMatrixMetrics(response: $response, logger: $logger, channeledAccount: $channeledAccount, account: $account, metricsToProcess: $metricsToProcess),
                default => self::propertyMetrics(response: $response, logger: $logger, channeledAccount: $channeledAccount, account: $account, metricsToProcess: $metricsToProcess),
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
                    } elseif ($dimName === 'sessionCampaignName' || $dimName === 'firstUserCampaignName') {
                        // Exclude GA4 system buckets (e.g. (referral), (direct), (cross-network)) and placeholder domains
                        if (empty($val) || preg_match('/^\([a-z\- ]+\)$/', $val) || preg_match('/^(?:[a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}(?:\/.*)?$/', $val) || $val === '(not provided)') {
                            $processed[$dimName] = null; // Do not map to a campaign entity
                        } else {
                            $processed[$dimName] = $val;
                        }
                    } elseif (in_array($dimName, ['sessionGoogleAdsAdGroupName', 'sessionManualTerm', 'firstUserGoogleAdsAdGroupName', 'firstUserManualTerm', 'sessionManualAdContent', 'firstUserManualAdContent'])) {
                        if (empty($val) || preg_match('/^\([a-z\- ]+\)$/', $val) || preg_match('/^(?:[a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}(?:\/.*)?$/', $val) || $val === '(not provided)') {
                            $processed[$dimName] = null;
                        } else {
                            $processed[$dimName] = $val;
                        }
                    } elseif ($dimName === 'deviceCategory') {
                        $processed['device'] = strtolower($val);
                        $processed[$dimName] = strtolower($val);
                    } elseif ($dimName === 'landingPagePlusQueryString') {
                        $processed['landing_page'] = $val;
                        $processed[$dimName] = $val;
                    } elseif ($dimName === 'countryId' || $dimName === 'country') {
                        $processed['country'] = $val;
                        $processed[$dimName] = $val;
                    } else {
                        $processed[$dimName] = $val;
                    }
                }

                // Map Metrics
                $metValues = $row['metricValues'] ?? [];
                foreach ($metricHeaders as $idx => $metName) {
                    $val = $metValues[$idx]['value'] ?? 0;
                    
                    // Specific mapping for GA4 metadata fields
                    if ($metName === 'bounceRate') {
                        $processed['bounce_rate'] = (float)$val;
                    }
                    
                    $processed[$metName] = (float)$val;
                }

                return $processed;
            }, $response['rows']);
        }
    }
