<?php

    declare(strict_types=1);

    namespace Anibalealvarezs\GoogleHubDriver\Conversions;

    use Anibalealvarezs\ApiDriverCore\Conversions\UniversalMetricConverter;
    use Anibalealvarezs\ApiDriverCore\Classes\KeyGenerator;
    use Anibalealvarezs\GoogleHubDriver\Drivers\SearchConsoleDriver;
    use Doctrine\Common\Collections\ArrayCollection;
    use Psr\Log\LoggerInterface;
    use Anibalealvarezs\GoogleHubDriver\Enums\GoogleChannel;
    use Anibalealvarezs\GoogleHubDriver\Enums\GoogleFeature;
    use Carbon\Carbon;

    /**
     * GoogleSearchConsoleConvert
     *
     * Standardizes Google Search Console data into APIs Hub metric objects.
     * Refactored to be entity-agnostic for the standalone SDK.
     */
    class GoogleSearchConsoleConvert
    {
        private static array $allDimensions = ['date', 'query', 'country', 'page', 'device', 'searchAppearance'];

        /**
         * Converts GSC API rows into a collection of metric objects.
         */
        public static function metrics(
            array              $rows,
            ?string            $siteUrl = null,
            ?string            $siteKey = null,
            ?LoggerInterface   $logger = null,
            object|string|null $page = null,
            object|string|null $period = 'daily',
            object|string|null $channeledAccount = null,
            object|string|null $account = null,
        ): ArrayCollection
        {
            $startTime = microtime(true);
            $rowCount = count($rows);
            if ($rowCount > 0 && $logger) {
                $logger->info("DEBUG: First GSC row sample: " . json_encode($rows[0]));
            }
            $periodValue = is_object($period) && isset($period->value) ? $period->value : (string)$period;
            $pageUrl = is_object($page) && method_exists($page, 'getUrl') ? $page->getUrl() : (string)$page;

            $channeledAccountPlatformId = is_object($channeledAccount) ? (method_exists($channeledAccount, 'getPlatformId') ? (string)$channeledAccount->getPlatformId() : (method_exists($channeledAccount, 'getId') ? (string)$channeledAccount->getId() : (string)$channeledAccount)) : (string)$channeledAccount;
            $accountId = is_object($account) ? (method_exists($account, 'getId') ? (string)$account->getId() : (string)$account) : (string)$account;
            $pagePlatformId = SearchConsoleDriver::getPlatformId(['url' => $pageUrl ?? $siteUrl], \Anibalealvarezs\ApiDriverCore\Enums\AssetCategory::PAGEABLE, 'gsc');

            $logger?->info(sprintf("Starting GSC metrics conversion for %d rows...", $rowCount));
            $collection = UniversalMetricConverter::convert($rows, [
                'channel'              => GoogleChannel::SEARCH_CONSOLE->value,
                'period'               => $periodValue,
                'platform_id_field'    => 'platform_id',
                'date_field'           => 'date',
                'metrics'              => [
                    GoogleFeature::CLICKS->value      => GoogleFeature::CLICKS->value,
                    GoogleFeature::IMPRESSIONS->value => GoogleFeature::IMPRESSIONS->value,
                    GoogleFeature::CTR->value         => GoogleFeature::CTR->value,
                    GoogleFeature::POSITION->value    => GoogleFeature::POSITION->value,
                ],
                'dimensions'           => ['page', 'searchAppearance'],
                'metadata_fields'      => ['synthetic'],
                'context'              => UniversalMetricConverter::getUniversalContext([
                    'account'                    => $account,
                    'accountPlatformId'          => $accountId,
                    'channeledAccount'           => $channeledAccount,
                    'channeledAccountId'         => $channeledAccountPlatformId,
                    'channeledAccountPlatformId' => $channeledAccountPlatformId,
                    'page'                       => SearchConsoleDriver::getCanonicalId(['url' => $pageUrl ?? $siteUrl], \Anibalealvarezs\ApiDriverCore\Enums\AssetCategory::PAGEABLE, 'gsc'),
                    'pagePlatformId'             => $pagePlatformId,
                ]),
                'row_key_fields'       => [
                    'query'   => 'query',
                    'country' => 'country',
                    'device'  => 'device',
                ],
                'row_entity_fields'    => [
                    'query'   => 'query',
                    'country' => 'country',
                    'device'  => 'device',
                ],
                'fallback_platform_id' => $pagePlatformId,
            ], $logger);

            $totalTime = microtime(true) - $startTime;
            $logger?->info(sprintf("Completed GSC metrics conversion: %d input rows -> %d converted metrics in %.4f seconds", $rowCount, $collection->count(), $totalTime));

            return $collection;
        }

        /**
         * Legacy metric aggregator to combine impression, click, position, and CTR rows.
         */
        public static function aggregateMetrics(array $data, array $new): array
        {
            $totalImpressions = ($data['impressions'] ?? 0) + ($new['impressions'] ?? 0);
            $totalClicks = ($data['clicks'] ?? 0) + ($new['clicks'] ?? 0);
            $count = ($data['count'] ?? 1) + 1;

            $ctr = $totalImpressions > 0 ? $totalClicks / $totalImpressions : 0.0;

            $p1 = $data['position'] ?? 0.0;
            $p2 = $new['position'] ?? 0.0;
            $i1 = $data['impressions'] ?? 0;
            $i2 = $new['impressions'] ?? 0;

            $position = $totalImpressions > 0 ? (($i1 * $p1) + ($i2 * $p2)) / $totalImpressions : 0.0;

            return [
                'impressions' => $totalImpressions,
                'clicks' => $totalClicks,
                'position' => $position,
                'ctr' => $ctr,
                'count' => $count,
            ];
        }

    }
