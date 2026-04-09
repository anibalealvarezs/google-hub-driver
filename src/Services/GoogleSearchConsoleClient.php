<?php

namespace Anibalealvarezs\GoogleHubDriver\Services;

use Anibalealvarezs\GoogleApi\Services\SearchConsole\SearchConsoleApi;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Exception;

class GoogleSearchConsoleClient
{
    private SearchConsoleApi $api;
    private string $siteUrl;
    private LoggerInterface $logger;

    public function __construct(SearchConsoleApi $api, string $siteUrl, LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->logger->info("Initializing GoogleSearchConsoleClient for site: $siteUrl");
        $this->api = $api;
        $this->siteUrl = $siteUrl;
    }

    private function generateDimensionCombinations(): array
    {
        $dimensions = ['date', 'query', 'page', 'country', 'device'];
        $combinations = [];
        for ($i = 1; $i < (1 << count($dimensions)); $i++) {
            $combo = [];
            for ($j = 0; $j < count($dimensions); $j++) {
                if ($i & (1 << $j)) {
                    $combo[] = $dimensions[$j];
                }
            }
            $combinations[] = $combo;
        }
        usort($combinations, fn ($a, $b) => count($b) <=> count($a));
        $this->logger->info("Generated " . count($combinations) . " dimension combinations");
        return $combinations;
    }

    public function fetchAllCombinations(string $date, int $rowLimit, array $dimensionFilterGroups = []): array
    {
        $this->logger->info("Fetching GSC data for site {$this->siteUrl}, date=$date, rowLimit=$rowLimit, filters=" . json_encode($dimensionFilterGroups));
        $combinations = $this->generateDimensionCombinations();
        $allRows = [];
        $totalRows = 0;

        foreach ($combinations as $index => $dims) {
            $this->logger->debug("Querying combination " . ($index + 1) . "/31: " . implode(',', $dims));
            try {
                $rows = $this->api->getAllSearchQueryResults(
                    siteUrl: $this->siteUrl,
                    startDate: $date,
                    endDate: $date,
                    rowLimit: $rowLimit,
                    dimensions: $dims,
                    dimensionFilterGroups: $dimensionFilterGroups
                );
                $rowCount = count($rows);
                $totalRows += $rowCount;
                $normalizedRows = $this->normalizeRows($rows, $dims);
                $allRows = array_merge($allRows, $normalizedRows);
                $this->logger->info("Fetched $rowCount rows for dimensions: " . implode(',', $dims) . ", normalized to " . count($normalizedRows));
            } catch (Exception|GuzzleException $e) {
                $this->logger->error("GSC API error for dimensions " . implode(',', $dims) . ": " . $e->getMessage());
            }
        }

        $this->logger->info("Completed fetching for date=$date, total rows=$totalRows, merged rows=" . count($allRows));
        return $allRows;
    }

    private function normalizeRows(array $rows, array $dims): array
    {
        $normalized = [];
        $dimIndices = ['date' => 0, 'query' => 1, 'page' => 2, 'country' => 3, 'device' => 4];

        $flatRows = [];
        if (isset($rows[0]['keys']) && is_array($rows[0])) {
            $flatRows = $rows;
        } else {
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $flatRows = array_merge($flatRows, $row);
                }
            }
        }

        foreach ($flatRows as $index => $row) {
            if (!isset($row['keys']) || !is_array($row['keys']) || count($row['keys']) !== count($dims)) {
                continue;
            }
            if (!isset($row['impressions']) || !is_numeric($row['impressions'])) {
                continue;
            }
            $keys = array_fill(0, 5, null);
            foreach ($dims as $i => $dim) {
                $keys[$dimIndices[$dim]] = $row['keys'][$i] ?? null;
            }
            $normalized[] = [
                'keys' => $keys,
                'clicks' => isset($row['clicks']) && is_numeric($row['clicks']) ? $row['clicks'] : 0,
                'impressions' => $row['impressions'],
                'ctr' => isset($row['ctr']) && is_numeric($row['ctr']) ? $row['ctr'] : 0,
                'position' => isset($row['position']) && is_numeric($row['position']) ? $row['position'] : 0,
                'dimensions' => $dims
            ];
        }

        return $normalized;
    }
}
