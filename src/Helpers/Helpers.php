<?php

declare(strict_types=1);

namespace Anibalealvarezs\GoogleHubDriver\Helpers;

use Anibalealvarezs\ApiDriverCore\Helpers\Helpers as CoreHelpers;

class Helpers
{
    public static array $defaultValues = [
        'query' => 'unknown',
        'country' => 'UNK',
        'page' => null,
        'device' => 'unknown'
    ];

    /**
     * @param array $allRows
     * @param array $targetKeywords
     * @param array $targetCountries
     * @param array $allDimensions
     * @return array
     */
    public static function getFinalRecords(
        array $allRows,
        array $targetKeywords,
        array $targetCountries,
        array $allDimensions
    ): array {
        $childrenSums = self::computeChildrenSum($allRows);

        $differences = self::calculateDifferences($allRows, $childrenSums);

        $allocatedDifferences = self::allocatePositiveDifferences(
            $differences,
            $allDimensions
        );

        $allocateFinalDifference = self::addGlobalRemainderSynthetic(
            $allocatedDifferences,
            $allDimensions
        );

        $negativeDifferencesProcessed = self::flagOrScaleNegativeDifferences(
            $allocateFinalDifference,
            true
        );

        $scaleAdjusted = self::adjustScaledPositions($negativeDifferencesProcessed);

        $finalRecords = array_values(array_filter($scaleAdjusted, function ($record) use ($allDimensions) {
            return (count($record['subset']) === count($allDimensions)) || (!empty($record['synthetic']));
        }));

        return self::fillWithNullsAndFilter($finalRecords, $targetKeywords, $targetCountries);
    }

    public static function allocatePositiveDifferences(
        array $records,
        array $dimensionNames,
        string $missingLabel = 'unknown'
    ): array {
        $extendedRecords = $records;

        foreach ($records as $record) {
            $impressionDiff = $record['impressions_difference'] ?? 0;
            $clicksDiff = $record['clicks_difference'] ?? 0;

            if ($impressionDiff > 0 || $clicksDiff > 0) {
                $newKeys = $record['keys'];
                $subset = $record['subset'];

                $missingDimension = null;
                foreach ($dimensionNames as $dim) {
                    if (!in_array($dim, $subset)) {
                        $missingDimension = $dim;
                        break;
                    }
                }

                if ($missingDimension !== null) {
                    $label = ($missingDimension === 'country') ? 'UNK' : $missingLabel;
                    $newKeys[] = $label;
                    $newSubset = [...$subset, $missingDimension];

                    $syntheticRecord = [
                        'keys' => $newKeys,
                        'clicks' => $clicksDiff,
                        'impressions' => $impressionDiff,
                        'ctr' => ($impressionDiff > 0) ? $clicksDiff / $impressionDiff : 0,
                        'position' => null,
                        'subset' => $newSubset,
                        'impressions_difference' => 0,
                        'clicks_difference' => 0,
                        'synthetic' => true,
                    ];

                    $extendedRecords[] = $syntheticRecord;
                }
            }
        }

        return $extendedRecords;
    }

    public static function addGlobalRemainderSynthetic(
        array $records,
        array $dimensionNames,
        array $parentSubset = ['date', 'page']
    ): array {
        $extendedRecords = $records;

        $allImpressions = 0;
        $allClicks = 0;
        $allPositionWeightedSum = 0;
        $allPositionCount = 0;

        $fiveDImpressions = 0;
        $fiveDClicks = 0;
        $fiveDPositionWeightedSum = 0;
        $fiveDPositionCount = 0;

        $partialImpressions = 0;
        $partialClicks = 0;
        $partialPositionWeightedSum = 0;
        $partialPositionCount = 0;

        foreach ($records as $rec) {
            $subset = $rec['subset'] ?? [];
            $impr = $rec['impressions'] ?? 0;
            $clicks = $rec['clicks'] ?? 0;
            $pos = $rec['position'] ?? null;
            $posWeighted = ($pos !== null) ? ($pos * $impr) : 0;

            if (array_values($subset) == $parentSubset) {
                $allImpressions += $impr;
                $allClicks += $clicks;
                if ($pos !== null) {
                    $allPositionWeightedSum += $posWeighted;
                    $allPositionCount += $impr;
                }
            }

            if (count($subset) === count($dimensionNames) && empty($rec['synthetic'])) {
                $fiveDImpressions += $impr;
                $fiveDClicks += $clicks;
                if ($pos !== null) {
                    $fiveDPositionWeightedSum += $posWeighted;
                    $fiveDPositionCount += $impr;
                }
            }

            if (!empty($rec['synthetic'])) {
                $partialImpressions += $impr;
                $partialClicks += $clicks;
                if ($pos !== null) {
                    $partialPositionWeightedSum += $posWeighted;
                    $partialPositionCount += $impr;
                }
            }
        }

        $remainingImpressions = $allImpressions - $fiveDImpressions - $partialImpressions;
        $remainingClicks = $allClicks - $fiveDClicks - $partialClicks;

        $allPositionAvg = ($allPositionCount > 0) ? ($allPositionWeightedSum / $allPositionCount) : null;
        $remainingPositionWeightedSum = $allPositionWeightedSum - $fiveDPositionWeightedSum - $partialPositionWeightedSum;
        $remainingPositionCount = $allPositionCount - $fiveDPositionCount - $partialPositionCount;
        $remainingPosition = ($remainingPositionCount > 0) ? ($remainingPositionWeightedSum / $remainingPositionCount) : $allPositionAvg;

        $remainingCtr = ($remainingImpressions > 0) ? ($remainingClicks / $remainingImpressions) : 0;

        if ($remainingImpressions > 0 || $remainingClicks > 0) {
            $keys = [];
            foreach ($dimensionNames as $dim) {
                $missingLabel = ($dim === 'country') ? 'UNK' : 'unknown';
                if (in_array($dim, $parentSubset)) {
                    $foundKey = $missingLabel;
                    foreach ($records as $rec) {
                        if (($rec['subset'] ?? []) === $parentSubset) {
                            $index = array_search($dim, $parentSubset);
                            $foundKey = $rec['keys'][$index] ?? $missingLabel;
                            break;
                        }
                    }
                    $keys[] = $foundKey;
                } else {
                    $keys[] = $missingLabel;
                }
            }

            $extendedRecords[] = [
                'keys' => $keys,
                'subset' => $dimensionNames,
                'impressions' => $remainingImpressions,
                'clicks' => $remainingClicks,
                'ctr' => $remainingCtr,
                'position' => $remainingPosition,
                'synthetic' => true,
                'note' => 'final synthetic to reconcile unmatched parent metrics',
                'impressions_difference' => 0,
                'clicks_difference' => 0,
            ];
        }

        return $extendedRecords;
    }

    public static function isParentOf(
        array $parentSubset,
        array $parentDims,
        array $childSubset,
        array $childDims
    ): bool {
        if (count($childSubset) <= count($parentSubset)) {
            return false;
        }
        $childSubsetIndex = array_flip($childSubset);
        $parentIndexInChild = [];

        foreach ($parentSubset as $dimName) {
            if (!isset($childSubsetIndex[$dimName])) {
                return false;
            }
            $parentIndexInChild[] = $childSubsetIndex[$dimName];
        }

        $prevIdx = -1;
        foreach ($parentIndexInChild as $i => $childIdx) {
            if ($childIdx <= $prevIdx) {
                return false;
            }
            $prevIdx = $childIdx;
            if ($parentDims[$i] !== $childDims[$childIdx]) {
                return false;
            }
        }
        return true;
    }

    public static function adjustScaledPositions(array $records): array
    {
        $n = count($records);

        for ($i = 0; $i < $n; $i++) {
            if (!($records[$i]['scaled'] ?? false)) {
                continue;
            }

            $parentSubset = $records[$i]['subset'];
            $parentDims = $records[$i]['keys'];

            $weightedSum = 0;
            $totalImpressions = 0;

            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) {
                    continue;
                }

                $childSubset = $records[$j]['subset'];
                $childDims = $records[$j]['keys'];

                if (self::isParentOf($parentSubset, $parentDims, $childSubset, $childDims)) {
                    $impressions = $records[$j]['impressions'] ?? 0;
                    $position = $records[$j]['position'] ?? null;

                    if ($impressions > 0 && $position !== null) {
                        $weightedSum += $impressions * $position;
                        $totalImpressions += $impressions;
                    }
                }
            }

            if ($totalImpressions > 0) {
                $records[$i]['original_position'] = $records[$i]['position'] ?? null;
                $records[$i]['position'] = round($weightedSum / $totalImpressions, 2);
            }
        }

        return $records;
    }

    public static function flagOrScaleNegativeDifferences(array $records, bool $scaleNegative = false): array
    {
        foreach ($records as &$record) {
            $impressionDiff = $record['impressions_difference'] ?? 0;
            $clicksDiff = $record['clicks_difference'] ?? 0;

            $childrenImpressions = $record['children_sum']['impressions'] ?? 0;
            $childrenClicks = $record['children_sum']['clicks'] ?? 0;

            if ($impressionDiff < 0 || $clicksDiff < 0) {
                if ($scaleNegative) {
                    $scaleFactorImpr = $childrenImpressions > 0 ? $record['impressions'] / $childrenImpressions : 0;
                    $scaleFactorClicks = $childrenClicks > 0 ? $record['clicks'] / $childrenClicks : 0;

                    $record['original_impressions'] = $record['impressions'];
                    $record['original_clicks'] = $record['clicks'];
                    $record['original_differences'] = ['impressions' => $impressionDiff, 'clicks' => $clicksDiff];

                    $record['impressions'] = round($childrenImpressions * $scaleFactorImpr);
                    $record['clicks'] = round($childrenClicks * $scaleFactorClicks);
                    $record['scaled'] = true;
                    $record['note'] = 'scaled down to match parent metrics';
                    $record['ctr'] = $record['impressions'] > 0 ? $record['clicks'] / $record['impressions'] : 0;
                } else {
                    $record['flagged'] = true;
                    $record['note'] = 'exceeds parent; likely misattributed';
                }
            }
        }

        return $records;
    }

    public static function calculateDifferences(array $records, array $childrenSums): array
    {
        foreach ($records as $index => &$record) {
            $record['impressions_difference'] = ($record['impressions'] ?? 0) - ($childrenSums[$index]['impressions'] ?? 0);
            $record['clicks_difference'] = ($record['clicks'] ?? 0) - ($childrenSums[$index]['clicks'] ?? 0);
        }
        return $records;
    }

    public static function computeChildrenSum(array $records): array
    {
        $n = count($records);
        $childrenSums = array_fill(0, $n, ['impressions' => 0, 'clicks' => 0]);

        for ($i = 0; $i < $n; $i++) {
            $parentSubset = $records[$i]['subset'];
            $parentDims = $records[$i]['keys'];

            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) continue;
                if (self::isParentOf($parentSubset, $parentDims, $records[$j]['subset'], $records[$j]['keys'])) {
                    $childrenSums[$i]['impressions'] += $records[$j]['impressions'] ?? 0;
                    $childrenSums[$i]['clicks'] += $records[$j]['clicks'] ?? 0;
                }
            }
        }

        return $childrenSums;
    }

    /**
     * Fills missing dimensions with nulls and filters records based on target keywords and countries.
     *
     * @param array $rows
     * @param array $targetKeywords
     * @param array $targetCountries
     * @return array
     */
    public static function fillWithNullsAndFilter(array $rows, array $targetKeywords, array $targetCountries): array {
        $newRows = [];
        foreach ($rows as $row) {
            list($date, $query, $country, $page, $device) = self::getDimensionsValues($row, array_flip($row['subset']), $targetKeywords, $targetCountries);
            $row['keys'] = [$date, $query, $country, $page, $device];
            $newRows[] = $row;
        }
        return $newRows;
    }

    /**
     * @param array $row
     * @param array $dimensionsIndex
     * @return string|null
     */
    protected static function getDate(array $row, array $dimensionsIndex): ?string
    {
        return isset($dimensionsIndex['date']) ? ($row['keys'][$dimensionsIndex['date']] ?? null) : null;
    }

    /**
     * @param array $row
     * @param array $dimensionsIndex
     * @param array $targetKeywords
     * @return string|null
     */
    protected static function getQueryTerm(array $row, array $dimensionsIndex, array $targetKeywords): ?string
    {
        if (!isset($dimensionsIndex['query']) || !isset($row['keys'][$dimensionsIndex['query']])) {
            return self::$defaultValues['query'];
        }
        $queryTerm = ($row['keys'][$dimensionsIndex['query']]);
        return empty($targetKeywords) || CoreHelpers::str_contains_any($queryTerm, $targetKeywords) ? $queryTerm :
            ($queryTerm == self::$defaultValues['query'] ? self::$defaultValues['query'] : 'others');
    }

    /**
     * @param array $row
     * @param array $dimensionsIndex
     * @param array $targetCountries
     * @return string|null
     */
    protected static function getCountryCode(array $row, array $dimensionsIndex, array $targetCountries): ?string
    {
        if (!isset($dimensionsIndex['country']) || !isset($row['keys'][$dimensionsIndex['country']])) {
            return self::$defaultValues['country'];
        }
        return (empty($targetCountries) || in_array(strtolower($row['keys'][$dimensionsIndex['country']]), $targetCountries)) ?
            strtoupper($row['keys'][$dimensionsIndex['country']]) :
            ($row['keys'][$dimensionsIndex['country']] == self::$defaultValues['country'] ? self::$defaultValues['country'] : 'OTH');
    }

    /**
     * @param array $row
     * @param array $dimensionsIndex
     * @return string|null
     */
    protected static function getPage(array $row, array $dimensionsIndex): ?string
    {
        if (!isset($dimensionsIndex['page']) || !isset($row['keys'][$dimensionsIndex['page']])) {
            return self::$defaultValues['page'];
        }
        return ($row['keys'][$dimensionsIndex['page']]);
    }

    /**
     * @param array $row
     * @param array $dimensionsIndex
     * @return string|null
     */
    protected static function getDevice(array $row, array $dimensionsIndex): ?string
    {
        if (!isset($dimensionsIndex['device']) || !isset($row['keys'][$dimensionsIndex['device']])) {
            return self::$defaultValues['device'];
        }
        return strtolower($row['keys'][$dimensionsIndex['device']]);
    }

    /**
     * Extracts dimension values from a row based on the provided indices.
     *
     * @param array $row
     * @param array $dimensionsIndex
     * @param array $targetKeywords
     * @param array $targetCountries
     * @return array
     */
    protected static function getDimensionsValues(array $row, array $dimensionsIndex, array $targetKeywords, array $targetCountries): array
    {
        return [
            self::getDate($row, $dimensionsIndex),
            self::getQueryTerm($row, $dimensionsIndex, $targetKeywords),
            self::getCountryCode($row, $dimensionsIndex, $targetCountries),
            self::getPage($row, $dimensionsIndex),
            self::getDevice($row, $dimensionsIndex)
        ];
    }

}
