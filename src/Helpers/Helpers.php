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
        'device' => 'unknown',
        'searchAppearance' => 'standard'
    ];

    /**
     * Entry point for GSC data reconciliation.
     * Uses the Inclusion-Exclusion principle by default.
     *
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
        return self::getFinalRecordsInclusionExclusion($allRows, $targetKeywords, $targetCountries, $allDimensions);
    }

    /**
     * 4-Subset Inclusion-Exclusion Reconciliation (The "Golden Lattice")
     *
     * Subsets used:
     * - T: (country, device) -> Anchor Truth
     * - P: (page, country, device) -> Page Truth
     * - Q: (query, country, device) -> Query Truth
     * - PQ: (page, query, country, device) -> Detail
     *
     * @param array $allRows
     * @param array $targetKeywords
     * @param array $targetCountries
     * @param array $allDimensions
     * @return array
     */
    public static function getFinalRecordsInclusionExclusion(
        array $allRows,
        array $targetKeywords,
        array $targetCountries,
        array $allDimensions
    ): array {
        $finalRecords = [];
        $siteUrl = self::$defaultValues['page']; // Site root for attribution

        // 1. Group rows by subset levels
        $totals = []; // (country, device) -> Total Clicks/Impressions
        $byPage = []; // (page, country, device) -> Total
        $byQuery = []; // (query, country, device) -> Total
        $byPageQuery = []; // (page, query, country, device) -> Detail
        $k4d_map = []; // hash -> {date, country, device}

        foreach ($allRows as $row) {
            $subset = $row['subset'] ?? [];
            $flipped = array_flip($subset);
            $date = $row['keys'][$flipped['date']] ?? 'unknown';
            $country = $row['keys'][$flipped['country']] ?? 'UNK';
            $device = $row['keys'][$flipped['device']] ?? 'unknown';
            $page = $row['keys'][$flipped['page']] ?? null;
            $query = $row['keys'][$flipped['query']] ?? null;

            $k4d_hash = "{$date}|{$country}|{$device}";
            if (!isset($k4d_map[$k4d_hash])) {
                $k4d_map[$k4d_hash] = ['date' => $date, 'country' => $country, 'device' => $device];
            }

            if (!isset($flipped['page']) && !isset($flipped['query'])) {
                $totals[$k4d_hash] = $row;
            } elseif (isset($flipped['page']) && !isset($flipped['query'])) {
                $byPage[$k4d_hash][$page] = $row;
            } elseif (!isset($flipped['page']) && isset($flipped['query'])) {
                $byQuery[$k4d_hash][$query] = $row;
            } else {
                $byPageQuery[$k4d_hash][$page][$query] = $row;
                $finalRecords[] = self::canonicalize($row, $allDimensions);
            }
        }

        // 2. Reconciliation Loop
        foreach ($k4d_map as $hash => $k) {
            $t_row = $totals[$hash] ?? null;
            if (!$t_row) continue;

            $totalImpr = (int)$t_row['impressions'];
            $totalClicks = (int)$t_row['clicks'];

            $sumP_Impr = 0; $sumP_Clicks = 0;
            $sumQ_Impr = 0; $sumQ_Clicks = 0;
            $sumPQ_Impr = 0; $sumPQ_Clicks = 0;

            // A. Process Page Residuals (Known Page, Unknown Query)
            if (isset($byPage[$hash])) {
                foreach ($byPage[$hash] as $p_val => $p_row) {
                    $sumP_Impr += (int)$p_row['impressions'];
                    $sumP_Clicks += (int)$p_row['clicks'];

                    $pq_sumImpr = 0; $pq_sumClicks = 0;
                    if (isset($byPageQuery[$hash][$p_val])) {
                        foreach ($byPageQuery[$hash][$p_val] as $pq_row) {
                            $pq_sumImpr += (int)$pq_row['impressions'];
                            $pq_sumClicks += (int)$pq_row['clicks'];
                        }
                    }
                    
                    $resImpr = max(0, (int)$p_row['impressions'] - $pq_sumImpr);
                    $resClicks = max(0, (int)$p_row['clicks'] - $pq_sumClicks);

                    if ($resImpr > 0 || $resClicks > 0) {
                        $finalRecords[] = self::createSynthetic($k, $p_val, self::$defaultValues['query'], $resImpr, $resClicks, $p_row['position'], $allDimensions);
                    }
                }
            }

            // B. Process Query Residuals (Known Query, Unknown Page)
            if (isset($byQuery[$hash])) {
                foreach ($byQuery[$hash] as $q_val => $q_row) {
                    $sumQ_Impr += (int)$q_row['impressions'];
                    $sumQ_Clicks += (int)$q_row['clicks'];

                    $pq_sumImpr = 0; $pq_sumClicks = 0;
                    if (isset($byPageQuery[$hash])) {
                        foreach ($byPageQuery[$hash] as $p_queries) {
                            if (isset($p_queries[$q_val])) {
                                $pq_sumImpr += (int)$p_queries[$q_val]['impressions'];
                                $pq_sumClicks += (int)$p_queries[$q_val]['clicks'];
                            }
                        }
                    }

                    $resImpr = max(0, (int)$q_row['impressions'] - $pq_sumImpr);
                    $resClicks = max(0, (int)$q_row['clicks'] - $pq_sumClicks);

                    if ($resImpr > 0 || $resClicks > 0) {
                        $finalRecords[] = self::createSynthetic($k, $siteUrl, $q_val, $resImpr, $resClicks, $q_row['position'], $allDimensions);
                    }
                }
            }

            // C. The Grand Residual (Unknown Page, Unknown Query)
            if (isset($byPageQuery[$hash])) {
                foreach ($byPageQuery[$hash] as $p_queries) {
                    foreach ($p_queries as $pq_row) {
                        $sumPQ_Impr += (int)$pq_row['impressions'];
                        $sumPQ_Clicks += (int)$pq_row['clicks'];
                    }
                }
            }

            $netKnownImpr = $sumP_Impr + $sumQ_Impr - $sumPQ_Impr;
            $netKnownClicks = $sumP_Clicks + $sumQ_Clicks - $sumPQ_Clicks;

            $grandResImpr = max(0, $totalImpr - $netKnownImpr);
            $grandResClicks = max(0, $totalClicks - $netKnownClicks);

            if ($grandResImpr > 0 || $grandResClicks > 0) {
                $finalRecords[] = self::createSynthetic($k, $siteUrl, self::$defaultValues['query'], $grandResImpr, $grandResClicks, $t_row['position'], $allDimensions);
            }
        }

        return self::fillWithNullsAndFilter($finalRecords, $targetKeywords, $targetCountries);
    }

    /**
     * 3-Level Hierarchical Reconciliation (Jars -> Glasses -> Drops)
     *
     * Subsets used:
     * - T: (country, device) -> Anchor
     * - P: (page, country, device) -> Level 1
     * - PQ: (page, query, country, device) -> Level 2
     *
     * @param array $allRows
     * @param array $targetKeywords
     * @param array $targetCountries
     * @param array $allDimensions
     * @return array
     */
    public static function getFinalRecordsHierarchical(
        array $allRows,
        array $targetKeywords,
        array $targetCountries,
        array $allDimensions
    ): array {
        $finalRecords = [];
        $siteUrl = self::$defaultValues['page'];

        $groups = [];
        foreach ($allRows as $row) {
            $subset = $row['subset'] ?? [];
            $flipped = array_flip($subset);
            $date = $row['keys'][$flipped['date']] ?? 'unknown';
            $country = $row['keys'][$flipped['country']] ?? 'UNK';
            $device = $row['keys'][$flipped['device']] ?? 'unknown';
            $page = $row['keys'][$flipped['page']] ?? $siteUrl;

            $groupKey = "{$date}|{$country}|{$device}";
            $pageKey = "{$date}|{$page}|{$country}|{$device}";

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = ['row' => null, 'pages' => [], 'k' => ['date' => $date, 'country' => $country, 'device' => $device]];
            }

            if (count($subset) === 3) {
                $groups[$groupKey]['row'] = $row;
            } elseif (count($subset) === 4) {
                $groups[$groupKey]['pages'][$pageKey] = ['row' => $row, 'queries' => []];
            } else {
                if (!isset($groups[$groupKey]['pages'][$pageKey])) {
                    $groups[$groupKey]['pages'][$pageKey] = ['row' => null, 'queries' => []];
                }
                $groups[$groupKey]['pages'][$pageKey]['queries'][] = $row;
            }
        }

        foreach ($groups as $group) {
            $t_row = $group['row'];
            $k = $group['k'];
            $sumPageImpr = 0; $sumPageClicks = 0;

            foreach ($group['pages'] as $p_key => $p_data) {
                $p_row = $p_data['row'];
                if (!$p_row) continue;

                $sumPageImpr += (int)$p_row['impressions'];
                $sumPageClicks += (int)$p_row['clicks'];

                $sumQImpr = 0; $sumQClicks = 0;
                foreach ($p_data['queries'] as $q_row) {
                    $sumQImpr += (int)$q_row['impressions'];
                    $sumQClicks += (int)$q_row['clicks'];
                    $finalRecords[] = self::canonicalize($q_row, $allDimensions);
                }

                $resImpr = max(0, (int)$p_row['impressions'] - $sumQImpr);
                $resClicks = max(0, (int)$p_row['clicks'] - $sumQClicks);
                if ($resImpr > 0 || $resClicks > 0) {
                    $p_val = explode('|', $p_key)[1];
                    $finalRecords[] = self::createSynthetic($k, $p_val, self::$defaultValues['query'], $resImpr, $resClicks, $p_row['position'], $allDimensions);
                }
            }

            if ($t_row) {
                $resImpr = max(0, (int)$t_row['impressions'] - $sumPageImpr);
                $resClicks = max(0, (int)$t_row['clicks'] - $sumPageClicks);
                if ($resImpr > 0 || $resClicks > 0) {
                    $finalRecords[] = self::createSynthetic($k, $siteUrl, self::$defaultValues['query'], $resImpr, $resClicks, $t_row['position'], $allDimensions);
                }
            }
        }

        return self::fillWithNullsAndFilter($finalRecords, $targetKeywords, $targetCountries);
    }

    /**
     * Helper to create a synthetic record.
     */
    private static function createSynthetic(array $k, ?string $page, string $query, int $impr, int $clicks, $pos, array $allDims): array
    {
        $keys = [];
        foreach ($allDims as $dim) {
            if ($dim === 'date') $keys[] = $k['date'];
            elseif ($dim === 'country') $keys[] = $k['country'];
            elseif ($dim === 'device') $keys[] = $k['device'];
            elseif ($dim === 'page') $keys[] = $page;
            elseif ($dim === 'query') $keys[] = $query;
            elseif ($dim === 'searchAppearance') $keys[] = self::$defaultValues['searchAppearance'];
            else $keys[] = 'unknown';
        }

        return [
            'keys' => $keys,
            'subset' => $allDims,
            'impressions' => $impr,
            'clicks' => $clicks,
            'ctr' => ($impr > 0) ? $clicks / $impr : 0,
            'position' => $pos,
            'synthetic' => true,
        ];
    }

    /**
     * @param array $allRows
     * @param array $targetKeywords
     * @param array $targetCountries
     * @param array $allDimensions
     * @return array
     */
    public static function getFinalRecordsLegacy(
        array $allRows,
        array $targetKeywords,
        array $targetCountries,
        array $allDimensions
    ): array {
        // --- Möbius Inversion (Bottom-Up Exact Residual Calculation) ---
        //
        // Sort records from most granular (5D) to least (2D).
        // For each record, compute:
        //   exact_residual = record.value − sum(exact_residuals of ALL its descendants)
        //
        // Each positive residual represents a UNIQUE, non-overlapping portion of the
        // data that this record knows about but none of its more-granular descendants do.
        // The sum of all residuals equals the 2D parent total exactly,
        // and every 3D/4D marginal is conserved exactly when aggregating by any dimension.

        $dimCount = count($allDimensions);

        // Sort descending by subset size (5D first, 2D last)
        $indexed = [];
        foreach ($allRows as $idx => $row) {
            $indexed[] = ['idx' => $idx, 'row' => $row, 'level' => count($row['subset'] ?? [])];
        }
        usort($indexed, fn($a, $b) => $b['level'] <=> $a['level']);

        // Compute exact residuals bottom-up
        $residuals = []; // [{row, residual_impressions, residual_clicks, residual_position}]

        foreach ($indexed as $entry) {
            $row = $entry['row'];
            $subset = $row['subset'] ?? [];
            $keys = $row['keys'] ?? [];

            $residualImpressions = (int)($row['impressions'] ?? 0);
            $residualClicks = (int)($row['clicks'] ?? 0);

            // Subtract the exact residuals of ALL descendants
            foreach ($residuals as $desc) {
                if (self::isParentOf($subset, $keys, $desc['subset'], $desc['keys'])) {
                    $residualImpressions -= $desc['residual_impressions'];
                    $residualClicks -= $desc['residual_clicks'];
                }
            }

            // Clamp negatives (Google data inconsistency)
            $residualImpressions = max(0, $residualImpressions);
            $residualClicks = max(0, $residualClicks);
            if ($residualImpressions > 0 && $residualClicks > $residualImpressions) {
                $residualClicks = $residualImpressions;
            }

            $residuals[] = [
                'subset' => $subset,
                'keys' => $keys,
                'residual_impressions' => $residualImpressions,
                'residual_clicks' => $residualClicks,
                'position' => $row['position'] ?? null,
                'level' => $entry['level'],
            ];
        }

        // Convert residuals to 5D output records
        $finalRecords = [];

        foreach ($residuals as $res) {
            if ($res['residual_impressions'] <= 0 && $res['residual_clicks'] <= 0) {
                continue;
            }

            $subset = $res['subset'];
            $keys = $res['keys'];
            $impressions = $res['residual_impressions'];
            $clicks = $res['residual_clicks'];
            $ctr = ($impressions > 0) ? $clicks / $impressions : 0;
            $position = $res['position'];
            $isSynthetic = (count($subset) < $dimCount);

            // Build 5D keys: keep known dims, fill unknown dims with defaults
            $outputKeys = [];
            foreach ($allDimensions as $dim) {
                $dimIdx = array_search($dim, $subset);
                if ($dimIdx !== false) {
                    $outputKeys[] = $keys[$dimIdx];
                } else {
                    $outputKeys[] = self::$defaultValues[$dim] ?? 'unknown';
                }
            }

            $finalRecords[] = [
                'keys' => $outputKeys,
                'subset' => $allDimensions,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $ctr,
                'position' => $position,
                'synthetic' => $isSynthetic,
            ];
        }

        $finalRecords = self::normalizeRecords($finalRecords);

        return self::fillWithNullsAndFilter($finalRecords, $targetKeywords, $targetCountries);
    }

    public static function allocatePositiveDifferences(
        array $records,
        array $dimensionNames,
        string $missingLabel = 'unknown'
    ): array {
        $extendedRecords = $records;

        // Sort records by subset size descending: process 4D before 3D before 2D.
        // This ensures finer-grained synthetics are created first,
        // and coarser levels can deduct them from their gaps.
        $indexed = [];
        foreach ($records as $idx => $record) {
            $indexed[] = ['idx' => $idx, 'record' => $record];
        }
        usort($indexed, fn($a, $b) => count($b['record']['subset'] ?? []) <=> count($a['record']['subset'] ?? []));

        // Track all synthetics created so far for deduction at coarser levels
        $createdSynthetics = [];

        foreach ($indexed as $entry) {
            $record = $entry['record'];
            $subset = $record['subset'];

            // Skip records that already have all dimensions (no missing dimension to synthesize)
            if (count($subset) >= count($dimensionNames)) {
                continue;
            }

            $impressionDiff = $record['impressions_difference'] ?? 0;
            $clicksDiff = $record['clicks_difference'] ?? 0;

            // Deduct synthetics created from strictly finer-grained records only
            $currentLevel = count($subset);
            foreach ($createdSynthetics as $syn) {
                if (($syn['source_level'] ?? 0) > $currentLevel
                    && self::isParentOf($subset, $record['keys'], $syn['subset'], $syn['keys'])) {
                    $impressionDiff -= $syn['impressions'];
                    $clicksDiff -= $syn['clicks'];
                }
            }

            $impressionAlloc = max(0, (int)$impressionDiff);
            $clicksAlloc = max(0, (int)$clicksDiff);
            if ($impressionAlloc > 0 && $clicksAlloc > $impressionAlloc) {
                $clicksAlloc = $impressionAlloc;
            }

            if ($impressionAlloc > 0 || $clicksAlloc > 0) {
                $newKeys = $record['keys'];

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
                        'clicks' => $clicksAlloc,
                        'impressions' => $impressionAlloc,
                        'ctr' => ($impressionAlloc > 0) ? $clicksAlloc / $impressionAlloc : 0,
                        'position' => null,
                        'subset' => $newSubset,
                        'impressions_difference' => 0,
                        'clicks_difference' => 0,
                        'synthetic' => true,
                        'source_level' => $currentLevel,
                    ];

                    $extendedRecords[] = $syntheticRecord;
                    $createdSynthetics[] = $syntheticRecord;
                }
            }
        }

        return $extendedRecords;
    }

    /**
     * Caps synthetic impressions per 3D marginal dimension value.
     * When overlapping 4D subset types create independent synthetics for
     * the same dimension value (e.g. country=USA), the total can exceed the
     * actual gap between the 3D marginal and the 5D leaf sum.
     * This method scales them down proportionally to fit the budget.
     */
    public static function capSyntheticsPerMarginal(
        array $records,
        array $allDimensions,
        array $parentSubset = ['date', 'page']
    ): array {
        $optionalDims = array_values(array_diff($allDimensions, $parentSubset));
        $dimCount = count($allDimensions);
        $marginalLevel = count($parentSubset) + 1; // 3D

        // Build marginal totals and 5D leaf sums per optional dimension per value
        $marginalTotals = [];  // dimName => value => impressions
        $leafSums = [];        // dimName => value => impressions

        foreach ($records as $rec) {
            $subset = $rec['subset'] ?? [];
            $subsetCount = count($subset);

            // 3D marginals
            if ($subsetCount === $marginalLevel && empty($rec['synthetic'])) {
                foreach ($optionalDims as $dim) {
                    if (in_array($dim, $subset)) {
                        $dimIdx = array_search($dim, $subset);
                        $val = $rec['keys'][$dimIdx] ?? null;
                        if ($val !== null) {
                            $marginalTotals[$dim][$val] = (int)($rec['impressions'] ?? 0);
                        }
                    }
                }
            }

            // 5D leaves — use each record's own subset for key lookup
            if ($subsetCount === $dimCount && empty($rec['synthetic'])) {
                foreach ($optionalDims as $dim) {
                    $dimIdx = array_search($dim, $subset);
                    if ($dimIdx === false) continue;
                    $val = $rec['keys'][$dimIdx] ?? null;
                    if ($val !== null) {
                        if (!isset($leafSums[$dim][$val])) {
                            $leafSums[$dim][$val] = 0;
                        }
                        $leafSums[$dim][$val] += (int)($rec['impressions'] ?? 0);
                    }
                }
            }
        }

        // Iterative convergence: scaling for one dimension can affect another's totals,
        // so repeat until no more scaling is needed (typically 2-3 iterations).
        $maxIterations = 5;
        for ($iter = 0; $iter < $maxIterations; $iter++) {
            $anyScaled = false;

            foreach ($optionalDims as $dim) {
                if (empty($marginalTotals[$dim])) continue;

                foreach ($marginalTotals[$dim] as $val => $marginalImpr) {
                    $leafImpr = $leafSums[$dim][$val] ?? 0;
                    $budget = max(0, $marginalImpr - $leafImpr);

                    // Sum synthetics attributed to this dimension value
                    $synTotal = 0;
                    foreach ($records as $rec) {
                        if (empty($rec['synthetic'])) continue;
                        $recDimIdx = array_search($dim, $rec['subset'] ?? []);
                        if ($recDimIdx === false) continue;
                        if (($rec['keys'][$recDimIdx] ?? null) === $val) {
                            $synTotal += (int)($rec['impressions'] ?? 0);
                        }
                    }

                    if ($synTotal > $budget && $synTotal > 0) {
                        $scale = $budget / $synTotal;
                        foreach ($records as &$rec) {
                            if (empty($rec['synthetic'])) continue;
                            $recDimIdx = array_search($dim, $rec['subset'] ?? []);
                            if ($recDimIdx === false) continue;
                            if (($rec['keys'][$recDimIdx] ?? null) !== $val) continue;
                            $rec['impressions'] = (int)round(((int)($rec['impressions'] ?? 0)) * $scale);
                            $rec['clicks'] = (int)round(((int)($rec['clicks'] ?? 0)) * $scale);
                            if ($rec['impressions'] > 0 && $rec['clicks'] > $rec['impressions']) {
                                $rec['clicks'] = $rec['impressions'];
                            }
                            $rec['ctr'] = ($rec['impressions'] > 0) ? $rec['clicks'] / $rec['impressions'] : 0;
                        }
                        unset($rec);
                        $anyScaled = true;
                    }
                }
            }

            if (!$anyScaled) break;
        }

        return $records;
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

        $remainingImpressions = max(0, $allImpressions - $fiveDImpressions - $partialImpressions);
        $remainingClicks = max(0, $allClicks - $fiveDClicks - $partialClicks);
        if ($remainingImpressions > 0 && $remainingClicks > $remainingImpressions) {
            $remainingClicks = $remainingImpressions;
        }

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

        foreach ($parentIndexInChild as $i => $childIdx) {
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
                    // Keep original values untouched; mark for diagnostics.
                    // Scaling parent rows from children can introduce instability in reconciliation.
                    $record['scaled'] = false;
                    $record['flagged'] = true;
                    $record['note'] = 'negative residual detected; skipped scaling for safety';
                    $record['children_sum'] = [
                        'impressions' => $childrenImpressions,
                        'clicks' => $childrenClicks,
                    ];
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
            $record['children_sum'] = [
                'impressions' => (int)($childrenSums[$index]['impressions'] ?? 0),
                'clicks' => (int)($childrenSums[$index]['clicks'] ?? 0),
            ];
            $record['impressions_difference'] = ($record['impressions'] ?? 0) - ($childrenSums[$index]['impressions'] ?? 0);
            $record['clicks_difference'] = ($record['clicks'] ?? 0) - ($childrenSums[$index]['clicks'] ?? 0);
        }
        return $records;
    }

    /**
     * Enforces metric invariants before returning final reconciled rows.
     *
     * @param array $records
     * @return array
     */
    protected static function normalizeRecords(array $records): array
    {
        foreach ($records as &$record) {
            $impressions = max(0, (int)($record['impressions'] ?? 0));
            $clicks = max(0, (int)($record['clicks'] ?? 0));

            if ($impressions > 0 && $clicks > $impressions) {
                $clicks = $impressions;
            }

            $record['impressions'] = $impressions;
            $record['clicks'] = $clicks;
            $record['ctr'] = $impressions > 0 ? $clicks / $impressions : 0;

            if (array_key_exists('position', $record) && $record['position'] !== null && $record['position'] < 0) {
                $record['position'] = null;
            }
        }

        return $records;
    }


    public static function computeChildrenSum(array $records): array
    {
        $n = count($records);
        $childrenSums = array_fill(0, $n, ['impressions' => 0, 'clicks' => 0]);

        // Find the max subset size (fully granular level)
        $maxLevel = 0;
        foreach ($records as $r) {
            $maxLevel = max($maxLevel, count($r['subset'] ?? []));
        }

        for ($i = 0; $i < $n; $i++) {
            $parentSubset = $records[$i]['subset'];
            $parentDims = $records[$i]['keys'];
            $parentLevel = count($parentSubset);

            // Strategy: compute TWO sums and take the larger one.
            // (a) Best immediate-child group (parent+1 level, grouped by extra dim)
            // (b) Leaf-descendant sum (max-level records under this parent)
            // The max of these gives the most accurate "already covered" amount.

            // (a) Immediate child groups
            $groupSums = []; // extraDim => ['impressions' => ..., 'clicks' => ...]

            // (b) Leaf sum
            $leafSum = ['impressions' => 0, 'clicks' => 0];

            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) continue;
                $childLevel = count($records[$j]['subset'] ?? []);

                if (!self::isParentOf($parentSubset, $parentDims, $records[$j]['subset'], $records[$j]['keys'])) {
                    continue;
                }

                // Immediate children (parent + 1)
                if ($childLevel === $parentLevel + 1) {
                    $extraDim = null;
                    foreach ($records[$j]['subset'] as $d) {
                        if (!in_array($d, $parentSubset)) {
                            $extraDim = $d;
                            break;
                        }
                    }
                    if ($extraDim !== null) {
                        if (!isset($groupSums[$extraDim])) {
                            $groupSums[$extraDim] = ['impressions' => 0, 'clicks' => 0];
                        }
                        $groupSums[$extraDim]['impressions'] += $records[$j]['impressions'] ?? 0;
                        $groupSums[$extraDim]['clicks'] += $records[$j]['clicks'] ?? 0;
                    }
                }

                // Leaf descendants (max level)
                if ($childLevel === $maxLevel) {
                    $leafSum['impressions'] += $records[$j]['impressions'] ?? 0;
                    $leafSum['clicks'] += $records[$j]['clicks'] ?? 0;
                }
            }

            // Pick the best immediate-child group
            $bestGroup = ['impressions' => 0, 'clicks' => 0];
            foreach ($groupSums as $gs) {
                if ($gs['impressions'] > $bestGroup['impressions']) {
                    $bestGroup = $gs;
                }
            }

            // Take the max of best-group and leaf sum
            $childrenSums[$i] = ($bestGroup['impressions'] >= $leafSum['impressions'])
                ? $bestGroup
                : $leafSum;
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
        // Truncate to fit DB column (varchar 512) with safety margin
        if (mb_strlen($queryTerm) > 500) {
            $queryTerm = mb_substr($queryTerm, 0, 500);
        }
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
     * Reorders keys to match the canonical $allDimensions order.
     */
    private static function canonicalize(array $row, array $allDimensions): array
    {
        $subset = $row['subset'] ?? [];
        $subsetFlipped = array_flip($subset);
        $newKeys = [];
        foreach ($allDimensions as $dim) {
            if (isset($subsetFlipped[$dim])) {
                $newKeys[] = $row['keys'][$subsetFlipped[$dim]];
            } else {
                $newKeys[] = self::$defaultValues[$dim] ?? 'unknown';
            }
        }
        $row['keys'] = $newKeys;
        $row['subset'] = $allDimensions;
        return $row;
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
