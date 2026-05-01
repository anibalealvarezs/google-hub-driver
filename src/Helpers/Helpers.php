<?php

declare(strict_types=1);

namespace Anibalealvarezs\GoogleHubDriver\Helpers;

use Anibalealvarezs\ApiDriverCore\Helpers\Helpers as CoreHelpers;
use Psr\Log\LoggerInterface;

class Helpers
{
    public static array $defaultValues = [
        'query' => 'unknown',
        'country' => 'unk',
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
        array $allDimensions,
        ?LoggerInterface $logger = null
    ): array {
        return self::getFinalRecordsIPF($allRows, $targetKeywords, $targetCountries, $allDimensions, logger: $logger);
    }

    // =========================================================================
    // IPF / Raking Reconciliation (v4)
    // =========================================================================

    /**
     * Iterative Proportional Fitting reconciliation.
     *
     * Treats all 16 dimension-subset totals as simultaneous constraints
     * and iteratively scales 5D cells until convergence.
     */
    public static function getFinalRecordsIPF(
        array $allRows,
        array $targetKeywords,
        array $targetCountries,
        array $allDimensions,
        float $tolerance = 0.001,
        int   $maxIterations = 20,
        ?LoggerInterface $logger = null
    ): array {
        // Group rows by date (IPF runs per-day)
        $byDate = [];
        foreach ($allRows as $row) {
            $subset = $row['subset'] ?? [];
            $flipped = array_flip($subset);
            $date = $row['keys'][$flipped['date']] ?? null;
            if ($date === null) {
                $logger?->warning('[IPF] Row skipped: no date key in subset=' . implode(',', $subset) . ' keys=' . json_encode($row['keys'] ?? []));
                continue;
            }
            $byDate[$date][] = $row;
        }

        $allFinal = [];
        foreach ($byDate as $date => $dateRows) {
            $reconciled = self::ipfReconcileDay(
                $date, $dateRows, $allDimensions, $tolerance, $maxIterations, $logger
            );
            foreach ($reconciled as $r) {
                $allFinal[] = $r;
            }
        }

        return $allFinal;
    }

    /**
     * Run IPF for a single day's data.
     */
    private static function ipfReconcileDay(
        string $date,
        array  $rows,
        array  $allDimensions,
        float  $tolerance,
        int    $maxIterations,
        ?LoggerInterface $logger = null
    ): array {
        $optionalDims = array_values(array_diff($allDimensions, ['date']));

        // 1. Parse all subset data into constraints + initial 5D cube
        [$constraints, $cube] = self::parseSubsetData($rows, $optionalDims);

        // 2. Seed synthetic cells for marginal values not covered by 5D records
        $cube = self::seedMissingCells($cube, $constraints, $optionalDims);

        if (empty($cube)) return [];

        // 3. Add explicit unknown-value constraints
        //    If query marginal sums to 160 but S0=200, add query=unknown→40.
        //    Without this, unknown cells don't participate in specific constraints
        //    and IPF oscillates between S0 normalization and marginal fitting.
        $constraints = self::addUnknownConstraints($constraints, $optionalDims);

        // 4. Pre-normalize cube to S0 total
        //    Seeds from overlapping constraints may sum to more than S0.
        $globalConstraint = $constraints[''] ?? null;
        if ($globalConstraint && !empty($globalConstraint['margins'])) {
            $gm = reset($globalConstraint['margins']);
            foreach (['impressions', 'clicks'] as $metric) {
                $s0Val = (float)($gm[$metric] ?? 0);
                if ($s0Val <= 0) continue;
                $cubeTotal = 0.0;
                foreach ($cube as $cell) {
                    $cubeTotal += $cell[$metric];
                }
                if ($cubeTotal > 0 && abs($cubeTotal - $s0Val) > 0.5) {
                    $factor = $s0Val / $cubeTotal;
                    foreach ($cube as &$cell) {
                        $cell[$metric] *= $factor;
                    }
                    unset($cell);
                }
            }
        }

        // 5. Build Index for fast lookup
        $index = self::buildIPFIndex($cube, $constraints);

        // 6. Run IPF — one pass for impressions, one for clicks
        $cube = self::runIPFForMetric($cube, $constraints, $index, 'impressions', $tolerance, $maxIterations);
        $cube = self::runIPFForMetric($cube, $constraints, $index, 'clicks', $tolerance, $maxIterations);

        // 7. Convert to output records
        return self::cubeToRecords($cube, $date, $allDimensions, $logger);
    }

    /**
     * For each dimension where the 1D marginal doesn't cover S0 fully,
     * add an explicit constraint for the "unknown" value so IPF can
     * properly scale unknown cells instead of leaving them unanchored.
     */
    private static function addUnknownConstraints(array $constraints, array $optionalDims): array
    {
        $globalConstraint = $constraints[''] ?? null;
        if (!$globalConstraint || empty($globalConstraint['margins'])) return $constraints;

        $gm = reset($globalConstraint['margins']);
        $s0Impr   = (float)($gm['impressions'] ?? 0);
        $s0Clicks = (float)($gm['clicks'] ?? 0);

        foreach ($optionalDims as $dim) {
            $subsetKey = $dim;
            if (!isset($constraints[$subsetKey])) continue;

            // Sum known marginal values for this dimension
            $knownImpr   = 0.0;
            $knownClicks = 0.0;
            foreach ($constraints[$subsetKey]['margins'] as $margin) {
                $knownImpr   += (float)($margin['impressions'] ?? 0);
                $knownClicks += (float)($margin['clicks'] ?? 0);
            }

            $unknownImpr   = $s0Impr - $knownImpr;
            $unknownClicks = $s0Clicks - $knownClicks;

            // Always add the unknown constraint.
            // If gap > 0: IPF scales unknown cells to absorb the gap.
            // If gap = 0: IPF zeros out any synthetic cells with the default value.
            $unknownValue = self::$defaultValues[$dim] ?? 'unknown';
            $marginKey = $dim . '=' . strtolower((string)$unknownValue);
            $constraints[$subsetKey]['margins'][$marginKey] = [
                'dims'        => [$dim => $unknownValue],
                'impressions' => max(0, (int)round(max(0, $unknownImpr))),
                'clicks'      => max(0, (int)round(max(0, $unknownClicks))),
                'position'    => null,
            ];
        }

        return $constraints;
    }

    /**
     * Parse rows into a constraints map and an initial 5D cube.
     *
     * @return array{0: array, 1: array}  [constraints, cube]
     */
    private static function parseSubsetData(array $rows, array $optionalDims): array
    {
        $constraints = []; // subsetKey => {dims: [...], margins: {marginKey => {dims, impressions, clicks, position}}}
        $cube = [];        // cubeKey  => {dims: {...}, impressions, clicks, position, synthetic}

        $dimCount = count($optionalDims);

        foreach ($rows as $row) {
            $subset = $row['subset'] ?? [];
            $flipped = array_flip($subset);

            // Extract optional-dimension values present in this subset
            $dimValues = [];
            foreach ($optionalDims as $dim) {
                if (isset($flipped[$dim])) {
                    $dimValues[$dim] = $row['keys'][$flipped[$dim]];
                }
            }

            $subsetOptional = array_values(array_intersect($optionalDims, $subset));
            $subsetKey = implode(',', $subsetOptional);

            if (!isset($constraints[$subsetKey])) {
                $constraints[$subsetKey] = ['dims' => $subsetOptional, 'margins' => []];
            }

            $marginKey = self::makeMarginKey($dimValues, $subsetOptional);

            // Keep the largest total when duplicates exist for same margin
            $impr = (int)($row['impressions'] ?? 0);
            $clicks = (int)($row['clicks'] ?? 0);
            if (!isset($constraints[$subsetKey]['margins'][$marginKey])
                || $impr > ($constraints[$subsetKey]['margins'][$marginKey]['impressions'] ?? 0)
            ) {
                $constraints[$subsetKey]['margins'][$marginKey] = [
                    'dims'        => $dimValues,
                    'impressions' => $impr,
                    'clicks'      => $clicks,
                    'position'    => $row['position'] ?? null,
                ];
            }

            // Full 5D record → add to cube
            if (count($subsetOptional) === $dimCount) {
                $cubeKey = self::makeCubeKey($dimValues, $optionalDims);
                $cube[$cubeKey] = [
                    'dims'        => $dimValues,
                    'impressions' => (float)$impr,
                    'clicks'      => (float)$clicks,
                    'position'    => $row['position'] ?? null,
                    'synthetic'   => false,
                ];
            }
        }

        return [$constraints, $cube];
    }

    /**
     * Create synthetic seed cells for marginal values not fully covered by existing 5D cells.
     *
     * Bridge seeding: instead of creating cells with "unknown" values (which don't
     * participate in other marginal constraints and prevent IPF convergence), we
     * distribute each gap proportionally across known values of other dimensions.
     * This creates bridge cells that connect multiple constraints.
     */
    private static function seedMissingCells(array $cube, array $constraints, array $optionalDims): array
    {
        $dimCount = count($optionalDims);

        // 1. Build per-dimension value distributions from 1D marginals
        //    These are used to proportionally distribute gaps.
        //    The "unknown" weight per dimension is derived from data:
        //    unknown_weight = (S0_total - marginal_sum) / S0_total
        $globalTotal = 0.0;
        $globalConstraint = $constraints[''] ?? null;
        if ($globalConstraint && !empty($globalConstraint['margins'])) {
            $gm = reset($globalConstraint['margins']);
            $globalTotal = max(1.0, (float)($gm['impressions'] ?? 0));
        }

        $dimDistributions = []; // dim => [{value => ..., weight => ...}]
        foreach ($constraints as $constraint) {
            if (count($constraint['dims']) !== 1) continue; // Only 1D marginals
            $dim = $constraint['dims'][0];
            $marginalSum = 0.0;
            $values = [];
            foreach ($constraint['margins'] as $margin) {
                $val = $margin['dims'][$dim] ?? null;
                if ($val === null) continue;
                $impr = max(0.0, (float)$margin['impressions']);
                $values[] = ['value' => $val, 'impressions' => $impr, 'clicks' => max(0.0, (float)$margin['clicks']), 'position' => $margin['position']];
                $marginalSum += $impr;
            }

            // Unknown weight = proportion of S0 NOT covered by this marginal
            $unknownWeight = ($globalTotal > 0 && $marginalSum < $globalTotal)
                ? ($globalTotal - $marginalSum) / $globalTotal
                : 0.001; // Epsilon when marginal fully covers S0

            if ($marginalSum > 0) {
                foreach ($values as &$v) {
                    $v['weight'] = $v['impressions'] / $globalTotal; // Relative to S0, not marginal sum
                }
                unset($v);
            }

            $dimDistributions[$dim] = ['values' => $values, 'unknownWeight' => $unknownWeight];
        }

        // 2. Process constraints from most-specific to least-specific
        $sorted = $constraints;
        uasort($sorted, fn($a, $b) => count($b['dims']) <=> count($a['dims']));

        foreach ($sorted as $constraint) {
            $subsetDims = $constraint['dims'];
            if (count($subsetDims) >= $dimCount) continue; // Skip full 5D
            if (count($subsetDims) === 0) continue;        // Skip S0, handled below

            foreach ($constraint['margins'] as $margin) {
                // Sum existing cube cells matching this marginal value
                $existingImpr = 0.0;
                $existingClicks = 0.0;
                foreach ($cube as $cell) {
                    if (self::cellMatchesMargin($cell['dims'], $margin['dims'], $subsetDims)) {
                        $existingImpr += $cell['impressions'];
                        $existingClicks += $cell['clicks'];
                    }
                }

                $gapImpr = (float)$margin['impressions'] - $existingImpr;
                $gapClicks = (float)$margin['clicks'] - $existingClicks;
                if ($gapImpr < 0.5 && $gapClicks < 0.5) continue;

                // Identify dimensions NOT in this constraint's subset
                $missingDims = array_values(array_diff($optionalDims, $subsetDims));

                // Build candidate dimension-value combinations for missing dims
                $combos = [[]]; // Start with one empty combo
                foreach ($missingDims as $mDim) {
                    $distData = $dimDistributions[$mDim] ?? null;
                    if ($distData && !empty($distData['values'])) {
                        // Expand combos with known values from this dimension's distribution
                        $newCombos = [];
                        foreach ($combos as $combo) {
                            foreach ($distData['values'] as $distEntry) {
                                $newCombos[] = array_merge($combo, [
                                    $mDim => ['value' => $distEntry['value'], 'weight' => $distEntry['weight'], 'position' => $distEntry['position']],
                                ]);
                            }
                            // Unknown fallback — weight derived from data (S0 - marginal_sum) / S0
                            $newCombos[] = array_merge($combo, [
                                $mDim => ['value' => self::$defaultValues[$mDim] ?? 'unknown', 'weight' => $distData['unknownWeight'], 'position' => null],
                            ]);
                        }
                        $combos = $newCombos;
                    } else {
                        // No distribution info — use default
                        foreach ($combos as &$combo) {
                            $combo[$mDim] = ['value' => self::$defaultValues[$mDim] ?? 'unknown', 'weight' => 1.0, 'position' => null];
                        }
                        unset($combo);
                    }
                }

                // Normalize combo weights
                $totalWeight = 0.0;
                foreach ($combos as $combo) {
                    $w = 1.0;
                    foreach ($combo as $entry) {
                        $w *= $entry['weight'];
                    }
                    $totalWeight += $w;
                }

                // Create seeds proportionally
                foreach ($combos as $combo) {
                    $w = 1.0;
                    foreach ($combo as $entry) {
                        $w *= $entry['weight'];
                    }
                    $share = ($totalWeight > 0) ? ($w / $totalWeight) : 0;
                    if ($share < 1e-6) continue;

                    $seedDims = [];
                    foreach ($optionalDims as $dim) {
                        if (isset($margin['dims'][$dim])) {
                            $seedDims[$dim] = $margin['dims'][$dim];
                        } elseif (isset($combo[$dim])) {
                            $seedDims[$dim] = $combo[$dim]['value'];
                        } else {
                            $seedDims[$dim] = self::$defaultValues[$dim] ?? 'unknown';
                        }
                    }

                    $seedKey = self::makeCubeKey($seedDims, $optionalDims);
                    if (isset($cube[$seedKey])) continue; // Already exists

                    $seedImpr = max(0.0, $gapImpr * $share);
                    $seedClicks = max(0.0, $gapClicks * $share);
                    if ($seedImpr < 0.1 && $seedClicks < 0.1) continue;

                    // Position: use marginal's position or combo position
                    $seedPosition = $margin['position'];
                    foreach ($combo as $entry) {
                        if ($entry['position'] !== null) {
                            $seedPosition = $entry['position'];
                            break;
                        }
                    }

                    $cube[$seedKey] = [
                        'dims'        => $seedDims,
                        'impressions' => $seedImpr,
                        'clicks'      => $seedClicks,
                        'position'    => $seedPosition,
                        'synthetic'   => true,
                    ];
                }
            }
        }

        // 3. Global (S0) seed — absorb any remaining gap vs the day total
        $globalConstraint = $constraints[''] ?? null;
        if ($globalConstraint && !empty($globalConstraint['margins'])) {
            $globalMargin = reset($globalConstraint['margins']);
            $cubeImpr   = 0.0;
            $cubeClicks = 0.0;
            foreach ($cube as $cell) {
                $cubeImpr   += $cell['impressions'];
                $cubeClicks += $cell['clicks'];
            }

            $globalGapImpr   = (float)$globalMargin['impressions'] - $cubeImpr;
            $globalGapClicks = (float)$globalMargin['clicks'] - $cubeClicks;

            if ($globalGapImpr > 0.5 || $globalGapClicks > 0.5) {
                $seedDims = [];
                foreach ($optionalDims as $dim) {
                    $seedDims[$dim] = self::$defaultValues[$dim] ?? 'unknown';
                }
                $seedKey = self::makeCubeKey($seedDims, $optionalDims);
                if (!isset($cube[$seedKey])) {
                    $cube[$seedKey] = [
                        'dims'        => $seedDims,
                        'impressions' => max(0.0, $globalGapImpr),
                        'clicks'      => max(0.0, $globalGapClicks),
                        'position'    => $globalMargin['position'],
                        'synthetic'   => true,
                    ];
                } else {
                    $cube[$seedKey]['impressions'] += max(0.0, $globalGapImpr);
                    $cube[$seedKey]['clicks']      += max(0.0, $globalGapClicks);
                }
            }
        }

        return $cube;
    }

    /**
     * Build an index mapping marginal constraints to cube cells.
     * $index[subsetKey][marginKey] => [cubeKey1, cubeKey2, ...]
     */
    private static function buildIPFIndex(array $cube, array $constraints): array
    {
        $index = [];
        foreach ($constraints as $subsetKey => $constraint) {
            $subsetDims = $constraint['dims'];
            foreach ($cube as $cubeKey => $cell) {
                $marginKey = self::makeMarginKey($cell['dims'], $subsetDims);
                if (isset($constraint['margins'][$marginKey])) {
                    $index[$subsetKey][$marginKey][] = $cubeKey;
                }
            }
        }
        return $index;
    }

    /**
     * Core IPF loop for a single metric (impressions or clicks).
     */
    private static function runIPFForMetric(
        array  $cube,
        array  $constraints,
        array  $index,
        string $metric,
        float  $tolerance,
        int    $maxIterations
    ): array {
        for ($iter = 0; $iter < $maxIterations; $iter++) {
            $maxError = 0.0;

            foreach ($constraints as $subsetKey => $constraint) {
                foreach ($constraint['margins'] as $marginKey => $margin) {
                    $target = (float)$margin[$metric];

                    // Use pre-calculated index to find matching cube cells
                    $matchingKeys = $index[$subsetKey][$marginKey] ?? [];
                    if (empty($matchingKeys)) continue;

                    $localSum = 0.0;
                    foreach ($matchingKeys as $key) {
                        $localSum += $cube[$key][$metric];
                    }

                    if ($localSum <= 0) continue;
                    if ($target <= 0) {
                        // Marginal says zero — zero out matching cells for this metric
                        foreach ($matchingKeys as $key) {
                            $cube[$key][$metric] = 0.0;
                        }
                        continue;
                    }

                    $factor = $target / $localSum;
                    $maxError = max($maxError, abs(1.0 - $factor));

                    foreach ($matchingKeys as $key) {
                        $cube[$key][$metric] *= $factor;
                    }
                }
            }

            if ($maxError < $tolerance) break;
        }

        return $cube;
    }

    /**
     * Convert the cube into output records with integer rounding and invariants.
     */
    private static function cubeToRecords(array $cube, string $date, array $allDimensions, ?LoggerInterface $logger = null): array
    {
        $records = [];

        foreach ($cube as $cell) {
            $impressions = max(0, (int)round($cell['impressions']));
            $clicks      = max(0, (int)round($cell['clicks']));

            if ($impressions <= 0 && $clicks <= 0) continue;

            // Enforce clicks <= impressions
            if ($impressions > 0 && $clicks > $impressions) {
                $clicks = $impressions;
            }

            $ctr = $impressions > 0 ? $clicks / $impressions : 0.0;

            $logger?->debug("[cubeToRecords] date={$date} | impr={$impressions} | clicks={$clicks}");
            $record = [
                'date'        => $date,
                'impressions' => $impressions,
                'clicks'      => $clicks,
                'ctr'         => $ctr,
                'position'    => $cell['position'],
                'synthetic'   => $cell['synthetic'] ?? false,
            ];

            foreach ($allDimensions as $dim) {
                if ($dim !== 'date') {
                    $val = $cell['dims'][$dim] ?? (self::$defaultValues[$dim] ?? 'unknown');
                    // Normalize casing for system resolution (Country: MEX, Device: desktop)
                    if ($dim === 'country') {
                        $val = strtoupper((string)$val);
                    } elseif ($dim === 'device') {
                        $val = strtolower((string)$val);
                    }
                    $record[$dim] = $val;
                }
            }

            $records[] = $record;
        }

        return $records;
    }

    /**
     * Check if a cube cell matches a marginal constraint's dimension values.
     */
    private static function cellMatchesMargin(array $cellDims, array $marginDims, array $subsetDims): bool
    {
        foreach ($subsetDims as $dim) {
            $cVal = $cellDims[$dim] ?? null;
            $mVal = $marginDims[$dim] ?? null;
            if ($cVal === null || $mVal === null) return false;
            if (is_string($cVal) && is_string($mVal)) {
                if (strtolower($cVal) !== strtolower($mVal)) return false;
            } elseif ($cVal !== $mVal) {
                return false;
            }
        }
        return true;
    }

    /**
     * Build a unique key for a cube cell from its dimension values.
     */
    private static function makeCubeKey(array $dims, array $optionalDims): string
    {
        $parts = [];
        foreach ($optionalDims as $dim) {
            $v = $dims[$dim] ?? 'NULL';
            $parts[] = $dim . '=' . (is_string($v) ? strtolower($v) : $v);
        }
        return implode('|', $parts);
    }

    /**
     * Build a unique key for a marginal row from its dimension values.
     */
    private static function makeMarginKey(array $dims, array $subsetDims): string
    {
        $parts = [];
        foreach ($subsetDims as $dim) {
            $v = $dims[$dim] ?? 'NULL';
            $parts[] = $dim . '=' . (is_string($v) ? strtolower($v) : $v);
        }
        return implode('|', $parts);
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

        return $finalRecords;
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
            $pVal = $parentDims[$i];
            $cVal = $childDims[$childIdx];
            if (is_string($pVal) && is_string($cVal)) {
                if (strtolower($pVal) !== strtolower($cVal)) {
                    return false;
                }
            } elseif ($pVal !== $cVal) {
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
