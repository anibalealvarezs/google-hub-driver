<?php

namespace Anibalealvarezs\GoogleHubDriver\Helpers;

use Psr\Log\LoggerInterface;

class Helpers
{
    public static $defaultValues = [
        'date' => 'unknown',
        'query' => 'unknown',
        'country' => 'unk',
        'page' => 'unknown',
        'device' => 'unknown',
    ];

    /**
     * Reconcile GSC data using Iterative Proportional Fitting (IPF).
     */
    public static function getFinalRecords(
        array $allRows,
        array $targetKeywords,
        array $targetCountries,
        array $allDimensions,
        float $tolerance = 0.000001,
        int   $maxIterations = 5000,
        ?LoggerInterface $logger = null
    ): array {
        // Group rows by date (IPF runs per-day)
        $byDate = [];
        foreach ($allRows as $row) {
            $rawKeys = $row['keys'] ?? ($row[0] ?? []);
            $rawDims = $row['dimensions'] ?? ($row['subset'] ?? ($row[1] ?? []));
            $flipped = array_change_key_case(array_flip($rawDims), CASE_LOWER);
            $date = $rawKeys[$flipped['date'] ?? -1] ?? null;
            if ($date === null) continue;
            $byDate[$date][] = $row;
        }

        $allFinal = [];
        foreach ($byDate as $date => $dateRows) {
            $reconciled = self::ipfReconcileDay($date, $dateRows, $allDimensions, $tolerance, $maxIterations, $logger);
            foreach ($reconciled as $r) {
                $allFinal[] = $r;
            }
        }
        return $allFinal;
    }

    private static function ipfReconcileDay(
        string $date,
        array  $rows,
        array  $allDimensions,
        float  $tolerance,
        int    $maxIterations,
        ?LoggerInterface $logger = null
    ): array {
        $optionalDims = array_values(array_diff($allDimensions, ['date']));
        [$constraints, $cube] = self::parseSubsetData($rows, $optionalDims, $date);
        $cube = self::seedMissingCells($cube, $constraints, $optionalDims, $date);
        $index = self::buildIPFIndex($cube, $constraints);

        $metrics = ['impressions', 'clicks'];
        foreach ($metrics as $metric) {
            self::runIPFForMetric($cube, $constraints, $index, $metric, $tolerance, $maxIterations);
        }

        return self::cubeToRecords($cube, $date, $allDimensions);
    }

    private static function parseSubsetData(array $rows, array $optionalDims, string $date): array
    {
        $constraints = [];
        $cube = [];
        $globalTarget = 0.0;
        $optLower = array_map('strtolower', $optionalDims);

        // 1. Find Global Target (S0)
        foreach ($rows as $row) {
            $subset = $row['subset'] ?? [];
            if (count($subset) === 1 && strtolower($subset[0]) === 'date') {
                $globalTarget = (float)($row['impressions'] ?? 0);
                break;
            }
        }

        // 2. Process all rows
        foreach ($rows as $row) {
            $subset = $row['subset'] ?? [];
            $subsetLower = array_map('strtolower', $subset);
            $keys = $row['keys'] ?? [];

            // Identify dimensions for this row (including date)
            $rowDims = ['date' => $date];
            foreach ($subset as $i => $dim) {
                $lowDim = strtolower($dim);
                if ($lowDim !== 'date') {
                    $rowDims[$lowDim] = $keys[$i] ?? 'unknown';
                }
            }

            // Determine if it's a Full-Dimension record (cube)
            $intersection = array_intersect($optLower, $subsetLower);
            if (count($intersection) === count($optionalDims)) {
                $cubeKey = self::makeCubeKey($rowDims, $optionalDims);
                if (!isset($cube[$cubeKey])) {
                    $cube[$cubeKey] = [
                        'dims' => $rowDims,
                        'impressions' => (float)($row['impressions'] ?? 0),
                        'clicks' => (float)($row['clicks'] ?? 0),
                        'position' => (float)($row['position'] ?? 5.0),
                        'synthetic' => false
                    ];
                } else {
                    $cube[$cubeKey]['impressions'] += (float)($row['impressions'] ?? 0);
                    $cube[$cubeKey]['clicks'] += (float)($row['clicks'] ?? 0);
                }
            } else {
                // It's a marginal constraint
                sort($subsetLower);
                $sKey = implode(',', array_filter($subsetLower, fn($d) => $d !== 'date'));
                if ($sKey === 'date' || $sKey === '') $sKey = '';

                if (!isset($constraints[$sKey])) {
                    $constraints[$sKey] = [
                        'dims' => array_values(array_filter($subsetLower, fn($d) => $d !== 'date')),
                        'margins' => []
                    ];
                }

                $mKey = self::makeMarginKey($rowDims, $constraints[$sKey]['dims']);
                if (!isset($constraints[$sKey]['margins'][$mKey])) {
                    $constraints[$sKey]['margins'][$mKey] = [
                        'impressions' => (float)($row['impressions'] ?? 0),
                        'clicks' => (float)($row['clicks'] ?? 0),
                        'position' => (float)($row['position'] ?? 5.0),
                        'dims' => $rowDims
                    ];
                } else {
                    $constraints[$sKey]['margins'][$mKey]['impressions'] += (float)($row['impressions'] ?? 0);
                    $constraints[$sKey]['margins'][$mKey]['clicks'] += (float)($row['clicks'] ?? 0);
                }
            }
        }

        // 3. Ensure S0 is in constraints for IPF loop
        if ($globalTarget > 0) {
            $constraints[''] = [
                'dims' => [],
                'margins' => ['' => [
                    'impressions' => $globalTarget,
                    'clicks' => 0.0, // Will be filled if needed
                    'position' => 5.0,
                    'dims' => []
                ]]
            ];
            // Find global clicks from any S0 row if possible
            foreach ($rows as $row) {
                if (count($row['subset'] ?? []) === 1 && strtolower($row['subset'][0]) === 'date') {
                    $constraints['']['margins']['']['clicks'] = (float)($row['clicks'] ?? 0);
                    break;
                }
            }
        }

        return [$constraints, $cube];
    }

    private static function seedMissingCells(array $cube, array $constraints, array $optionalDims, string $date): array
    {
        // 1. Identify dimension distributions and gaps
        $hasGap = [];
        $distributions = [];
        $globalTarget = (float)($constraints['']['margins']['']['impressions'] ?? 0);

        foreach ($optionalDims as $dim) {
            $low = strtolower($dim);
            $sKey = $low;
            if (isset($constraints[$sKey])) {
                $sum = 0.0;
                foreach ($constraints[$sKey]['margins'] as $mKey => $m) {
                    $val = $m['dims'][$low] ?? 'unknown';
                    $impr = (float)$m['impressions'];
                    $sum += $impr;
                    if ($val !== 'unknown' && $val !== 'unk' && $impr > 0) {
                        $distributions[$low][$val] = $impr;
                    }
                }
                if ($sum < $globalTarget - 1.0) { $hasGap[$low] = true; }
            }
        }

        // 2. Create pioneer seeds for marginal buckets ONLY if they have a deficit
        foreach ($constraints as $subsetKey => $subsetData) {
            if ($subsetKey === '') continue;
            foreach ($subsetData['margins'] as $marginKey => $margin) {
                $target = (float)$margin['impressions'];
                if ($target <= 0) continue;

                $mKeyForCheck = self::makeMarginKey($margin['dims'], $subsetData['dims']);
                $currentSum = 0.0;
                foreach ($cube as $cell) {
                    if (self::makeMarginKey($cell['dims'], $subsetData['dims']) === $mKeyForCheck) {
                        $currentSum += $cell['impressions'];
                    }
                }

                if ($currentSum < $target - 0.01) {
                    $seeds = [['dims' => $margin['dims'], 'weight' => 1.0]];

                    foreach ($optionalDims as $dim) {
                        $low = strtolower($dim);
                        if (isset($margin['dims'][$low])) continue;

                        if (!isset($hasGap[$low]) && isset($distributions[$low])) {
                            $newSeeds = [];
                            $totalWeight = array_sum($distributions[$low]);
                            foreach ($seeds as $seed) {
                                foreach ($distributions[$low] as $val => $w) {
                                    $s = $seed;
                                    $s['dims'][$low] = $val;
                                    $s['weight'] *= ($w / $totalWeight);
                                    $newSeeds[] = $s;
                                }
                            }
                            $seeds = $newSeeds;
                        } else {
                            foreach ($seeds as &$seed) {
                                $seed['dims'][$low] = self::$defaultValues[$low] ?? 'unknown';
                            }
                        }
                    }

                    foreach ($seeds as $seed) {
                        $seed['dims']['date'] = $date;
                        $seedKey = self::makeCubeKey($seed['dims'], $optionalDims);
                        $impr = 1.0 * $seed['weight']; // Competitive but elastic
                        if (!isset($cube[$seedKey])) {
                            $cube[$seedKey] = [
                                'dims' => $seed['dims'], 'impressions' => $impr, 'clicks' => $impr * 0.1,
                                'position' => $margin['position'] ?? 5.0, 'synthetic' => true,
                            ];
                        } else {
                            $cube[$seedKey]['impressions'] += $impr;
                        }
                    }
                }
            }
        }

        // 3. Global Anchor seed (Residual mass ONLY)
        $maxMarginalSum = 0.0;
        foreach ($constraints as $sKey => $subset) {
            if ($sKey === '') continue;
            $mSum = 0.0;
            foreach ($subset['margins'] as $m) { $mSum += $m['impressions']; }
            $maxMarginalSum = max($maxMarginalSum, $mSum);
        }
        $residualGap = max(1.0, $globalTarget - $maxMarginalSum);

        $seedDims = ['date' => $date];
        foreach ($optionalDims as $dim) {
            $low = strtolower($dim);
            $seedDims[$low] = self::$defaultValues[$low] ?? 'unknown';
        }
        // Fill seeded dimensions from distributions where possible
        foreach ($optionalDims as $dim) {
            $low = strtolower($dim);
            if (!isset($hasGap[$low]) && isset($distributions[$low])) {
                $maxVal = 'unknown'; $maxW = -1.0;
                foreach ($distributions[$low] as $val => $w) {
                    if ($w > $maxW) { $maxW = $w; $maxVal = $val; }
                }
                $seedDims[$low] = $maxVal;
            }
        }
        $seedKey = self::makeCubeKey($seedDims, $optionalDims);
        if (!isset($cube[$seedKey])) {
            $cube[$seedKey] = [
                'dims' => $seedDims, 'impressions' => $residualGap, 'clicks' => $residualGap * 0.1,
                'position' => 5.0, 'synthetic' => true,
            ];
        } else {
            $cube[$seedKey]['impressions'] += $residualGap;
        }

        return $cube;
    }

    private static function buildIPFIndex(array $cube, array $constraints): array
    {
        $index = [];
        foreach ($constraints as $sKey => $data) {
            foreach ($cube as $cubeKey => $cell) {
                $mKey = self::makeMarginKey($cell['dims'], $data['dims']);
                $index[$sKey][$mKey][] = $cubeKey;
            }
        }
        return $index;
    }

    private static function runIPFForMetric(array &$cube, array $constraints, array $index, string $metric, float $tolerance, int $maxIterations): void
    {
        // Ensure S0 (global) is applied LAST in each iteration for better final convergence
        $s0Data = $constraints[''] ?? null;
        $marginalConstraints = $constraints;
        unset($marginalConstraints['']);

        for ($iter = 0; $iter < $maxIterations; $iter++) {
            $maxError = 0;

            // 1. Apply marginal constraints
            foreach ($marginalConstraints as $sKey => $subset) {
                foreach ($subset['margins'] as $mKey => $margin) {
                    $target = (float)$margin[$metric];
                    $matchingKeys = $index[$sKey][$mKey] ?? [];
                    if (empty($matchingKeys)) continue;

                    $localSum = 0.0;
                    foreach ($matchingKeys as $key) { $localSum += $cube[$key][$metric]; }
                    if ($localSum <= 1e-10) continue;

                    $factor = $target / $localSum;
                    $maxError = max($maxError, abs(1.0 - $factor));
                    foreach ($matchingKeys as $key) { $cube[$key][$metric] *= $factor; }
                }
            }

            // 2. Apply S0 constraint LAST
            if ($s0Data) {
                foreach ($s0Data['margins'] as $mKey => $margin) {
                    $target = (float)$margin[$metric];
                    $matchingKeys = $index[''][$mKey] ?? [];
                    if (empty($matchingKeys)) continue;

                    $localSum = 0.0;
                    foreach ($matchingKeys as $key) { $localSum += $cube[$key][$metric]; }
                    if ($localSum <= 1e-10) continue;

                    $factor = $target / $localSum;
                    $maxError = max($maxError, abs(1.0 - $factor));
                    foreach ($matchingKeys as $key) { $cube[$key][$metric] *= $factor; }
                }
            }

            if ($maxError < $tolerance) break;
        }
    }

    private static function cubeToRecords(array $cube, string $date, array $allDimensions): array
    {
        $records = [];
        foreach ($cube as $cell) {
            $impr = self::normalizeValue($cell['impressions']);
            $clicks = self::normalizeValue($cell['clicks']);
            if ($impr == 0 && $clicks == 0) continue;
            $record = [
                'date' => $date, 'impressions' => $impr, 'clicks' => $clicks,
                'ctr' => ($impr > 0 ? round($clicks / $impr, 4) : 0),
                'position' => round($cell['position'], 2), 'synthetic' => $cell['synthetic'] ?? false,
            ];
            $keys = [];
            foreach ($allDimensions as $dim) {
                $low = strtolower($dim);
                if ($low === 'date') { $val = $date; }
                else {
                    $val = $cell['dims'][$low] ?? (self::$defaultValues[$low] ?? 'unknown');
                    if ($low === 'country') $val = strtoupper((string)$val);
                    elseif ($low === 'device') $val = strtolower((string)$val);
                }
                $record[$low] = $val; $keys[] = $val;
            }
            $record['keys'] = $keys; $records[] = $record;
        }
        return $records;
    }

    // --- Legacy Helpers for Tests ---

    public static function isParentOf(array $parentSubset, array $parentDims, array $childSubset, array $childDims): bool
    {
        $pMap = array_combine(array_map('strtolower', $parentSubset), $parentDims);
        $cMap = array_combine(array_map('strtolower', $childSubset), $childDims);
        foreach ($pMap as $dim => $val) {
            if (!isset($cMap[$dim]) || strtolower((string)$cMap[$dim]) !== strtolower((string)$val)) return false;
        }
        return count($parentSubset) < count($childSubset);
    }

    public static function computeChildrenSum(array $records, string $metric = 'impressions'): array
    {
        $results = [];
        foreach ($records as $i => $parent) {
            $sumImpr = 0.0; $sumClicks = 0.0;
            foreach ($records as $j => $child) {
                if ($i === $j) continue;
                if (self::isParentOf($parent['subset'] ?? [], $parent['keys'] ?? [], $child['subset'] ?? [], $child['keys'] ?? [])) {
                    $sumImpr += (float)($child['impressions'] ?? 0); $sumClicks += (float)($child['clicks'] ?? 0);
                }
            }
            $results[$i] = ['impressions' => self::normalizeValue($sumImpr), 'clicks' => self::normalizeValue($sumClicks)];
        }
        return $results;
    }

    public static function calculateDifferences(array $records, array $sums): array
    {
        foreach ($records as $i => &$record) {
            $record['children_sum'] = $sums[$i];
            $record['impressions_difference'] = self::normalizeValue((float)($record['impressions'] ?? 0) - $sums[$i]['impressions']);
            $record['clicks_difference'] = self::normalizeValue((float)($record['clicks'] ?? 0) - $sums[$i]['clicks']);
        }
        return $records;
    }

    public static function allocatePositiveDifferences(array $records, array $allDimensions): array
    {
        $newRecords = $records;
        foreach ($records as $record) {
            $diff = (float)($record['impressions_difference'] ?? 0);
            if ($diff > 0.1) {
                $newRecord = array_merge(self::$defaultValues, $record);
                $impr = self::normalizeValue($diff);
                $clicks = self::normalizeValue(max(0, (float)($record['clicks_difference'] ?? 0)));
                $newRecord['impressions'] = $impr;
                $newRecord['clicks'] = min($clicks, $impr);
                $newRecord['synthetic'] = true;
                $newRecords[] = $newRecord;
            }
        }
        return $newRecords;
    }

    public static function flagOrScaleNegativeDifferences(array $records, bool $diagnostic): array
    {
        foreach ($records as &$record) {
            $record['scaled'] = false;
            $record['flagged'] = true;
        }
        return $records;
    }

    // --- Private Utilities ---

    private static function normalizeValue(float $val): int
    {
        return (int)round($val, 0);
    }

    private static function cellMatchesMargin(array $cellDims, array $marginDims, array $subsetDims): bool
    {
        foreach ($subsetDims as $dim) {
            if (strtolower((string)($cellDims[$dim] ?? 'unknown')) !== strtolower((string)($marginDims[$dim] ?? 'unknown'))) return false;
        }
        return true;
    }

    private static function makeCubeKey(array $dims, array $optionalDims): string
    {
        sort($optionalDims); $parts = [];
        foreach ($optionalDims as $dim) {
            $parts[] = strtolower($dim) . "=" . strtolower((string)($dims[strtolower($dim)] ?? self::$defaultValues[strtolower($dim)]));
        }
        return implode('|', $parts);
    }

    private static function makeSubsetKey(array $subsetDims, array $optionalDims): string
    {
        sort($subsetDims); return implode(',', $subsetDims);
    }

    private static function makeMarginKey(array $dims, array $subsetDims): string
    {
        sort($subsetDims); $parts = [];
        foreach ($subsetDims as $dim) {
            $parts[] = strtolower($dim) . "=" . strtolower((string)($dims[strtolower($dim)] ?? self::$defaultValues[strtolower($dim)]));
        }
        return implode('|', $parts);
    }
}
