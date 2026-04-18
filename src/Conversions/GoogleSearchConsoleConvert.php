<?php

declare(strict_types=1);

namespace Anibalealvarezs\GoogleHubDriver\Conversions;

use Anibalealvarezs\ApiDriverCore\Conversions\UniversalMetricConverter;
use Anibalealvarezs\ApiDriverCore\Classes\KeyGenerator;
use Doctrine\Common\Collections\ArrayCollection;
use Psr\Log\LoggerInterface;
use Carbon\Carbon;

/**
 * GoogleSearchConsoleConvert
 * 
 * Standardizes Google Search Console data into APIs Hub metric objects.
 * Refactored to be entity-agnostic for the standalone SDK.
 */
class GoogleSearchConsoleConvert
{
    private static array $allDimensions = ['date', 'query', 'page', 'country', 'device', 'searchAppearance'];

    /**
     * Converts GSC API rows into a collection of metric objects.
     */
    public static function metrics(
        array $rows,
        string $siteUrl,
        string $siteKey,
        ?LoggerInterface $logger = null,
        object|string|null $page = null,
        object|string|null $period = 'daily',
        object|null $channeledAccount = null,
    ): ArrayCollection {
        $startTime = microtime(true);
        $rowCount = count($rows);
        $searchAppearance = 'WEB';
        $periodValue = is_object($period) && isset($period->value) ? $period->value : (string) $period;
        $pageUrl = is_object($page) && method_exists($page, 'getUrl') ? $page->getUrl() : (string) $page;

        // 1. Mandatory GSC Aggregation Step
        $aggregatedData = [];
        foreach ($rows as $row) {
            $dimensionValues = [];
            foreach (self::$allDimensions as $index => $dimension) {
                $val = $row['keys'][$index] ?? null;
                if (!$val) {
                    $val = match($dimension) {
                        'date' => Carbon::now()->toDateString(),
                        'query' => 'unknown',
                        'country' => 'UNK',
                        'device' => 'UNKNOWN',
                        default => null,
                    };
                }
                $dimensionValues[$dimension] = $val;
            }

            $dimensions = [
                ['dimensionKey' => 'page', 'dimensionValue' => $dimensionValues['page'] ?? null],
                ['dimensionKey' => 'query', 'dimensionValue' => $dimensionValues['query'] ?? null],
                ['dimensionKey' => 'searchAppearance', 'dimensionValue' => $searchAppearance],
            ];
            $dimensionsHash = KeyGenerator::generateDimensionsHash($dimensions);

            $groupKey = KeyGenerator::generateMetricConfigKey(
                channel: 'google_search_console',
                name: 'impressions',
                period: $periodValue,
                page: $pageUrl ?? $siteUrl,
                country: $dimensionValues['country'],
                device: $dimensionValues['device'],
                dimensionSet: $dimensionsHash
            );

            $impr = (int)($row['impressions'] ?? 0);
            $clicks = (int)($row['clicks'] ?? 0);
            $pos = (float)($row['position'] ?? 0);
            $ctr = (float)($row['ctr'] ?? 0);

            if (isset($aggregatedData[$groupKey])) {
                $aggregatedData[$groupKey]['metrics'] = self::aggregateMetrics(
                    $aggregatedData[$groupKey]['metrics'],
                    ['impressions' => $impr, 'clicks' => $clicks, 'position' => $pos]
                );
                $aggregatedData[$groupKey]['metadata'][] = [
                    ...$aggregatedData[$groupKey]['metrics'],
                    'keys' => $row['keys'] ?? [],
                ];
            } else {
                $aggregatedData[$groupKey] = [
                    'metrics' => [
                        'impressions' => $impr,
                        'clicks' => $clicks,
                        'position' => $pos,
                        'ctr' => $ctr,
                        'count' => 1
                    ],
                    'metadata' => [
                        [
                            'impressions' => $impr,
                            'clicks' => $clicks,
                            'position' => $pos,
                            'ctr' => $ctr,
                            'keys' => $row['keys'] ?? [],
                        ]
                    ],
                    'dimensions' => $dimensions,
                    'dimensionsHash' => $dimensionsHash,
                    'dimensionValues' => $dimensionValues,
                    'groupKey' => $groupKey,
                    'date' => $dimensionValues['date']
                ];
            }
        }

        // 2. Standardized Conversion via UniversalMetricConverter
        $collection = new ArrayCollection();
        foreach ($aggregatedData as $groupKey => $data) {
            $platformId = "gsc_{$siteKey}_{$groupKey}";
            $data['metrics']['date'] = $data['date'];
            $data['metrics']['platform_id'] = $platformId;

            $rowMetrics = UniversalMetricConverter::convert([$data['metrics']], [
                'channel' => 'google_search_console',
                'period' => $periodValue,
                'platform_id_field' => 'platform_id',
                'fallback_platform_id' => $platformId,
                'date_field' => 'date',
                'metrics' => [
                    'clicks' => 'clicks',
                    'impressions' => 'impressions',
                    'ctr' => 'ctr',
                    'position' => 'position'
                ],
                'dimensions' => $data['dimensions'],
                'context' => [
                    'platform_id' => $platformId,
                    'date' => $data['date'],
                    'query' => $data['dimensionValues']['query'] ?? null,
                    'countryCode' => $data['dimensionValues']['country'] ?? 'UNK',
                    'deviceType' => $data['dimensionValues']['device'] ?? 'UNKNOWN',
                    'page' => $page,
                    'channeledAccount' => $channeledAccount,
                ],
            ], $logger);

            foreach ($rowMetrics as $metric) {
                if ($metric) {
                    $metric->metadata = $data['metadata'];
                    $metric->dimensions = $data['dimensions'];
                    $collection->add($metric);
                }
            }
        }

        $totalTime = microtime(true) - $startTime;
        $logger?->info(sprintf("Completed GSC metrics conversion: %d rows to %d metrics in %.4f seconds", $rowCount, $collection->count(), $totalTime));

        return $collection;
    }

    public static function aggregateMetrics(array $data, array $new): array
    {
        $totalImpressions = (int)($data['impressions'] ?? 0) + (int)($new['impressions'] ?? 0);
        $totalClicks = (int)($data['clicks'] ?? 0) + (int)($new['clicks'] ?? 0);
        $data['ctr'] = $totalImpressions > 0 ? $totalClicks / $totalImpressions : 0;
        $totalWeightedPosition = ((float)($data['position'] ?? 0) * (int)($data['impressions'] ?? 0))
            + ((float)($new['position'] ?? 0) * (int)($new['impressions'] ?? 0));
        $data['position'] = $totalImpressions > 0 ? $totalWeightedPosition / $totalImpressions : 0;
        $data['impressions'] = $totalImpressions;
        $data['clicks'] = $totalClicks;
        $data['count'] = (int)($data['count'] ?? 0) + 1;
        return $data;
    }
}
