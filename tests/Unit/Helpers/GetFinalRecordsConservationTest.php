<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Anibalealvarezs\GoogleHubDriver\Helpers\Helpers;
use PHPUnit\Framework\TestCase;

class GetFinalRecordsConservationTest extends TestCase
{
    private const ALL_DIMENSIONS = ['date', 'query', 'country', 'page', 'device'];

    // =========================================================================
    // IPF Reconciliation Tests
    // =========================================================================

    /**
     * @dataProvider ipfScenarioProvider
     */
    public function testIPFConservesGlobalTotalAndOutputInvariants(
        string $scenario,
        array $rows,
        int $expectedImpressions,
        int $expectedClicks,
        bool $expectSynthetic
    ): void {
        $records = Helpers::getFinalRecords($rows, [], [], self::ALL_DIMENSIONS);

        $this->assertNotEmpty($records, "[$scenario] Expected reconciled output rows.");

        // Check synthetics
        $syntheticCount = count(array_filter($records, static fn(array $row): bool => !empty($row['synthetic'])));
        if ($expectSynthetic) {
            $this->assertGreaterThan(0, $syntheticCount, "[$scenario] Expected at least one synthetic row.");
        }

        // Per-row invariants
        $totalImpressions = 0;
        $totalClicks = 0;
        foreach ($records as $row) {
            $impressions = (int)($row['impressions'] ?? 0);
            $clicks = (int)($row['clicks'] ?? 0);
            $ctr = (float)($row['ctr'] ?? 0);

            $this->assertGreaterThanOrEqual(0, $impressions, "[$scenario] Impressions must be non-negative.");
            $this->assertGreaterThanOrEqual(0, $clicks, "[$scenario] Clicks must be non-negative.");
            $this->assertLessThanOrEqual($impressions, $clicks, "[$scenario] Clicks cannot exceed impressions.");
            $this->assertGreaterThanOrEqual(0.0, $ctr, "[$scenario] CTR must be >= 0.");
            $this->assertLessThanOrEqual(1.0, $ctr, "[$scenario] CTR must be <= 1.");

            $totalImpressions += $impressions;
            $totalClicks += $clicks;
        }

        // Global total conservation (S0 = [date] anchor)
        $this->assertEqualsWithDelta(
            $expectedImpressions,
            $totalImpressions,
            0, // Parity 100%
            "[$scenario] Aggregated impressions must match S0 total."
        );
        $this->assertEqualsWithDelta(
            $expectedClicks,
            $totalClicks,
            0,
            "[$scenario] Aggregated clicks must match S0 total."
        );
    }

    /**
     * @dataProvider ipfMarginalProvider
     */
    public function testIPFSatisfiesMarginalConstraints(
        string $scenario,
        array  $rows,
        array  $marginalChecks // [{dims: [dim => value], expectedImpr: int, expectedClicks: int}]
    ): void {
        $records = Helpers::getFinalRecords($rows, [], [], self::ALL_DIMENSIONS);
        $this->assertNotEmpty($records, "[$scenario] Expected output rows.");

        // Output keys are ordered as ALL_DIMENSIONS: [date, query, country, page, device]
        $dimIndex = array_flip(self::ALL_DIMENSIONS);

        foreach ($marginalChecks as $check) {
            $sumImpr = 0;
            $sumClicks = 0;
            foreach ($records as $row) {
                $matches = true;
                foreach ($check['dims'] as $dim => $value) {
                    $idx = $dimIndex[$dim];
                    $rowVal = $row['keys'][$idx] ?? null;
                    if (is_string($rowVal) && is_string($value)) {
                        if (strtolower($rowVal) !== strtolower($value)) {
                            $matches = false;
                            break;
                        }
                    } elseif ($rowVal !== $value) {
                        $matches = false;
                        break;
                    }
                }
                if ($matches) {
                    $sumImpr += (int)($row['impressions'] ?? 0);
                    $sumClicks += (int)($row['clicks'] ?? 0);
                }
            }

            $label = json_encode($check['dims']);
            $this->assertEqualsWithDelta(
                $check['expectedImpr'],
                $sumImpr,
                0, // IPF rounding tolerance
                "[$scenario] Marginal impressions for $label should match."
            );
            $this->assertEqualsWithDelta(
                $check['expectedClicks'],
                $sumClicks,
                0,
                "[$scenario] Marginal clicks for $label should match."
            );
        }
    }
    /**
     * THE DEFINITIVE TEST: Verify that IPF output, when aggregated by ANY of the
     * 16 dimension subsets, reproduces the ground-truth totals within rounding tolerance.
     *
     * Approach:
     * 1. Define a ground-truth 8-cell 4D cube (query×country×page×device)
     * 2. Programmatically compute all 16 subset aggregations from it
     * 3. Remove 3 of the 8 5D cells (simulating GSC privacy filtering)
     * 4. Feed the remaining 5D cells + all 16 subset totals to IPF
     * 5. Verify the output satisfies ALL 16 constraints
     */
    public function testIPFSatisfiesAll16SubsetConstraints(): void
    {
        $date = '2026-04-10';
        $page = 'https://example.com/';

        // Ground truth: 8-cell cube [query, country, page, device, impressions, clicks]
        $groundTruth = [
            ['shoes', 'US', $page, 'mobile',  40, 4],
            ['shoes', 'US', $page, 'desktop', 30, 3],
            ['shoes', 'DE', $page, 'mobile',  15, 2],
            ['shoes', 'DE', $page, 'desktop', 10, 1],
            ['boots', 'US', $page, 'mobile',  25, 3],
            ['boots', 'US', $page, 'desktop', 20, 2],
            ['boots', 'DE', $page, 'mobile',  10, 1],
            ['boots', 'DE', $page, 'desktop',  5, 1],
        ];

        $optionalDims = ['query', 'country', 'page', 'device'];

        // --- Generate all 16 subsets from ground truth ---
        $allRows = [];
        $subsetConstraints = []; // For verification: subsetKey => [{dims => [...], impr, clicks}]

        $numDims = count($optionalDims);
        for ($mask = 0; $mask < (1 << $numDims); $mask++) {
            $subsetDims = ['date'];
            $dimIndices = [];
            for ($j = 0; $j < $numDims; $j++) {
                if ($mask & (1 << $j)) {
                    $subsetDims[] = $optionalDims[$j];
                    $dimIndices[] = $j;
                }
            }

            // Aggregate ground truth by this subset's dimensions
            $agg = [];
            foreach ($groundTruth as $cell) {
                $keys = [$date];
                $groupKey = $date;
                foreach ($dimIndices as $di) {
                    $keys[] = $cell[$di];
                    $groupKey .= '|' . $cell[$di];
                }
                if (!isset($agg[$groupKey])) {
                    $agg[$groupKey] = ['keys' => $keys, 'subset' => $subsetDims, 'impressions' => 0, 'clicks' => 0, 'ctr' => 0, 'position' => 5.0];
                }
                $agg[$groupKey]['impressions'] += $cell[4];
                $agg[$groupKey]['clicks'] += $cell[5];
            }

            $subsetKey = implode(',', $subsetDims);
            $subsetConstraints[$subsetKey] = [];

            foreach ($agg as $row) {
                $row['ctr'] = $row['impressions'] > 0 ? $row['clicks'] / $row['impressions'] : 0;
                $allRows[] = $row;
                $subsetConstraints[$subsetKey][] = [
                    'keys'        => $row['keys'],
                    'impressions' => $row['impressions'],
                    'clicks'      => $row['clicks'],
                ];
            }
        }

        // --- Simulate privacy filtering: remove 3 of 8 five-dimensional cells ---
        $hiddenKeys = [
            $date . '|shoes|DE|' . $page . '|desktop',  // 10 impr
            $date . '|boots|DE|' . $page . '|desktop',  //  5 impr
            $date . '|boots|DE|' . $page . '|mobile',   // 10 impr
        ];

        $filteredRows = [];
        foreach ($allRows as $row) {
            if (count($row['subset']) === 5) { // Full 5D
                $cellKey = implode('|', $row['keys']);
                if (in_array($cellKey, $hiddenKeys)) continue;
            }
            $filteredRows[] = $row;
        }

        // Sanity: we should have removed exactly 3 rows
        $this->assertCount(count($allRows) - 3, $filteredRows, 'Should remove exactly 3 hidden 5D cells.');

        // --- Run IPF ---
        $records = Helpers::getFinalRecords($filteredRows, [], [], self::ALL_DIMENSIONS);
        $this->assertNotEmpty($records, 'IPF should produce output records.');

        // --- Verify ALL 16 subset constraints ---
        $dimIndex = array_flip(self::ALL_DIMENSIONS); // date=0, query=1, country=2, page=3, device=4
        $totalConstraints = 0;
        $passedConstraints = 0;

        foreach ($subsetConstraints as $subsetKey => $expectedRows) {
            $subsetDims = explode(',', $subsetKey);

            foreach ($expectedRows as $expected) {
                $totalConstraints++;
                $sumImpr = 0;
                $sumClicks = 0;

                foreach ($records as $rec) {
                    $matches = true;
                    foreach ($subsetDims as $si => $dim) {
                        $expectedVal = $expected['keys'][$si];
                        $recVal = $rec['keys'][$dimIndex[$dim]];
                        if (is_string($expectedVal) && is_string($recVal)) {
                            if (strtolower($expectedVal) !== strtolower($recVal)) {
                                $matches = false;
                                break;
                            }
                        } elseif ($expectedVal !== $recVal) {
                            $matches = false;
                            break;
                        }
                    }
                    if ($matches) {
                        $sumImpr += (int)($rec['impressions'] ?? 0);
                        $sumClicks += (int)($rec['clicks'] ?? 0);
                    }
                }

                $label = "[$subsetKey] keys=" . json_encode($expected['keys']);

                $this->assertEqualsWithDelta(
                    $expected['impressions'],
                    $sumImpr,
                    0, // IPF rounding tolerance
                    "Impressions mismatch for $label (expected {$expected['impressions']}, got $sumImpr)"
                );
                $this->assertEqualsWithDelta(
                    $expected['clicks'],
                    $sumClicks,
                    0,
                    "Clicks mismatch for $label (expected {$expected['clicks']}, got $sumClicks)"
                );
                $passedConstraints++;
            }
        }

        // Verify we checked a meaningful number of constraints
        // 16 subsets × varying number of rows per subset
        $this->assertGreaterThanOrEqual(30, $totalConstraints,
            "Should verify at least 30 constraint rows across all 16 subsets (got $totalConstraints).");
    }

    /**
     * Verify that "unknown" dimension values appear naturally when marginals
     * don't fully cover S0, and that records with multiple unknowns are created.
     *
     * Scenario: S0=200, but query marginal sums to 160 (20% unknown query)
     * and country marginal sums to 170 (15% unknown country).
     * The output must include records with query=unknown and/or country=unknown,
     * and ALL 16 subset constraints must still be satisfied.
     */
    public function testIPFCreatesUnknownRecordsWhenMarginalsDontCoverS0(): void
    {
        $date = '2026-04-10';
        $page = 'https://example.com/';

        $rows = [
            // S0: [date] — 200 total
            ['keys' => [$date], 'subset' => ['date'],
             'impressions' => 200, 'clicks' => 20, 'ctr' => 0.10, 'position' => 5.0],

            // [date, query] — sums to 160 (gap of 40 = 20% unknown query)
            ['keys' => [$date, 'shoes'], 'subset' => ['date', 'query'],
             'impressions' => 100, 'clicks' => 10, 'ctr' => 0.10, 'position' => 4.0],
            ['keys' => [$date, 'boots'], 'subset' => ['date', 'query'],
             'impressions' => 60, 'clicks' => 6, 'ctr' => 0.10, 'position' => 6.0],

            // [date, country] — sums to 170 (gap of 30 = 15% unknown country)
            ['keys' => [$date, 'US'], 'subset' => ['date', 'country'],
             'impressions' => 120, 'clicks' => 12, 'ctr' => 0.10, 'position' => 4.5],
            ['keys' => [$date, 'DE'], 'subset' => ['date', 'country'],
             'impressions' => 50, 'clicks' => 5, 'ctr' => 0.10, 'position' => 6.5],

            // [date, device] — sums to 200 (no unknown device)
            ['keys' => [$date, 'mobile'], 'subset' => ['date', 'device'],
             'impressions' => 120, 'clicks' => 12, 'ctr' => 0.10, 'position' => 4.5],
            ['keys' => [$date, 'desktop'], 'subset' => ['date', 'device'],
             'impressions' => 80, 'clicks' => 8, 'ctr' => 0.10, 'position' => 5.5],

            // [date, page] — sums to 200 (no unknown page)
            ['keys' => [$date, $page], 'subset' => ['date', 'page'],
             'impressions' => 200, 'clicks' => 20, 'ctr' => 0.10, 'position' => 5.0],

            // 5D records (partial coverage)
            ['keys' => [$date, 'shoes', 'US', $page, 'mobile'],
             'subset' => self::ALL_DIMENSIONS,
             'impressions' => 50, 'clicks' => 5, 'ctr' => 0.10, 'position' => 3.5],
            ['keys' => [$date, 'boots', 'DE', $page, 'desktop'],
             'subset' => self::ALL_DIMENSIONS,
             'impressions' => 20, 'clicks' => 2, 'ctr' => 0.10, 'position' => 7.0],
        ];

        $records = Helpers::getFinalRecords($rows, [], [], self::ALL_DIMENSIONS);
        $this->assertNotEmpty($records);

        $dimIndex = array_flip(self::ALL_DIMENSIONS);

        // 1. Verify S0 total conservation
        $totalImpr = 0;
        $totalClicks = 0;
        foreach ($records as $rec) {
            $totalImpr += (int)$rec['impressions'];
            $totalClicks += (int)$rec['clicks'];
        }
        $this->assertEqualsWithDelta(200, $totalImpr, 0, 'S0 impressions must match.');
        $this->assertEqualsWithDelta(20, $totalClicks, 0, 'S0 clicks must match.');

        // 2. Verify query marginal (shoes=100, boots=60, unknown=40)
        $queryGroups = [];
        foreach ($records as $rec) {
            $q = $rec['keys'][$dimIndex['query']];
            $queryGroups[$q] = ($queryGroups[$q] ?? 0) + (int)$rec['impressions'];
        }
        $this->assertEqualsWithDelta(100, $queryGroups['shoes'] ?? 0, 3, 'Query shoes impressions.');
        $this->assertEqualsWithDelta(60, $queryGroups['boots'] ?? 0, 3, 'Query boots impressions.');
        // Unknown query must absorb the remaining 40
        $unknownQueryImpr = $queryGroups['unknown'] ?? 0;
        $this->assertGreaterThan(0, $unknownQueryImpr, 'Unknown query records must exist.');
        $this->assertEqualsWithDelta(40, $unknownQueryImpr, 5, 'Unknown query should absorb ~40 impressions.');

        // 3. Verify country marginal (US=120, DE=50, unknown=30)
        $countryGroups = [];
        foreach ($records as $rec) {
            $c = $rec['keys'][$dimIndex['country']];
            $countryGroups[strtolower($c)] = ($countryGroups[strtolower($c)] ?? 0) + (int)$rec['impressions'];
        }
        $this->assertEqualsWithDelta(120, $countryGroups['us'] ?? 0, 3, 'Country US impressions.');
        $this->assertEqualsWithDelta(50, $countryGroups['de'] ?? 0, 3, 'Country DE impressions.');
        $unknownCountryImpr = $countryGroups['unk'] ?? 0;
        $this->assertGreaterThan(0, $unknownCountryImpr, 'Unknown country records must exist.');
        $this->assertEqualsWithDelta(30, $unknownCountryImpr, 5, 'Unknown country should absorb ~30 impressions.');

        // 4. Verify device marginal is fully covered (no unknown device)
        $deviceGroups = [];
        foreach ($records as $rec) {
            $d = $rec['keys'][$dimIndex['device']];
            $deviceGroups[$d] = ($deviceGroups[$d] ?? 0) + (int)$rec['impressions'];
        }
        $this->assertEqualsWithDelta(120, $deviceGroups['mobile'] ?? 0, 3, 'Device mobile impressions.');
        $this->assertEqualsWithDelta(80, $deviceGroups['desktop'] ?? 0, 3, 'Device desktop impressions.');

        // 5. Verify multi-unknown records exist (query=unknown AND country=unk)
        $multiUnknownCount = 0;
        foreach ($records as $rec) {
            $q = $rec['keys'][$dimIndex['query']];
            $c = strtolower($rec['keys'][$dimIndex['country']]);
            if ($q === 'unknown' && $c === 'unk') {
                $multiUnknownCount++;
            }
        }
        $this->assertGreaterThan(0, $multiUnknownCount,
            'Records with BOTH query=unknown AND country=unk must exist (multi-unknown).');
    }

    // =========================================================================
    // Data Providers
    // =========================================================================

    public function ipfScenarioProvider(): array
    {
        return [
            'basic_with_global_anchor' => [
                'basic_with_global_anchor',
                [
                    // S0: [date] — Global truth
                    ['keys' => ['2026-04-10'], 'subset' => ['date'],
                     'impressions' => 100, 'clicks' => 10, 'ctr' => 0.10, 'position' => 5.0],
                    // S_full: 5D record
                    ['keys' => ['2026-04-10', 'brand shoes', 'US', 'https://example.com/', 'mobile'],
                     'subset' => self::ALL_DIMENSIONS,
                     'impressions' => 70, 'clicks' => 7, 'ctr' => 0.10, 'position' => 4.2],
                ],
                100, // expected impressions
                10,  // expected clicks
                true, // expect synthetic for the gap of 30 impressions
            ],

            'full_coverage_no_gap' => [
                'full_coverage_no_gap',
                [
                    // S0
                    ['keys' => ['2026-04-10'], 'subset' => ['date'],
                     'impressions' => 100, 'clicks' => 10, 'ctr' => 0.10, 'position' => 5.0],
                    // Two 5D records that sum to exactly 100
                    ['keys' => ['2026-04-10', 'shoes', 'US', 'https://example.com/', 'mobile'],
                     'subset' => self::ALL_DIMENSIONS,
                     'impressions' => 60, 'clicks' => 6, 'ctr' => 0.10, 'position' => 4.0],
                    ['keys' => ['2026-04-10', 'boots', 'DE', 'https://example.com/', 'desktop'],
                     'subset' => self::ALL_DIMENSIONS,
                     'impressions' => 40, 'clicks' => 4, 'ctr' => 0.10, 'position' => 6.0],
                ],
                100, 10, false,
            ],

            'multi_subset_with_marginals' => [
                'multi_subset_with_marginals',
                [
                    // S0: Global
                    ['keys' => ['2026-04-10'], 'subset' => ['date'],
                     'impressions' => 200, 'clicks' => 20, 'ctr' => 0.10, 'position' => 5.0],
                    // 2D marginal: [date, query]
                    ['keys' => ['2026-04-10', 'shoes'], 'subset' => ['date', 'query'],
                     'impressions' => 120, 'clicks' => 12, 'ctr' => 0.10, 'position' => 4.0],
                    ['keys' => ['2026-04-10', 'boots'], 'subset' => ['date', 'query'],
                     'impressions' => 80, 'clicks' => 8, 'ctr' => 0.10, 'position' => 6.0],
                    // 2D marginal: [date, country]
                    ['keys' => ['2026-04-10', 'US'], 'subset' => ['date', 'country'],
                     'impressions' => 150, 'clicks' => 15, 'ctr' => 0.10, 'position' => 4.5],
                    ['keys' => ['2026-04-10', 'DE'], 'subset' => ['date', 'country'],
                     'impressions' => 50, 'clicks' => 5, 'ctr' => 0.10, 'position' => 6.5],
                    // 5D records (partial coverage)
                    ['keys' => ['2026-04-10', 'shoes', 'US', 'https://example.com/', 'mobile'],
                     'subset' => self::ALL_DIMENSIONS,
                     'impressions' => 80, 'clicks' => 8, 'ctr' => 0.10, 'position' => 3.5],
                    ['keys' => ['2026-04-10', 'boots', 'DE', 'https://example.com/', 'desktop'],
                     'subset' => self::ALL_DIMENSIONS,
                     'impressions' => 30, 'clicks' => 3, 'ctr' => 0.10, 'position' => 7.0],
                ],
                200, 20, true,
            ],

            'sparse_5d_heavy_marginals' => [
                'sparse_5d_heavy_marginals',
                [
                    // S0
                    ['keys' => ['2026-04-10'], 'subset' => ['date'],
                     'impressions' => 500, 'clicks' => 50, 'ctr' => 0.10, 'position' => 5.0],
                    // [date, query] — 3 queries known
                    ['keys' => ['2026-04-10', 'q1'], 'subset' => ['date', 'query'],
                     'impressions' => 200, 'clicks' => 20, 'ctr' => 0.10, 'position' => 4.0],
                    ['keys' => ['2026-04-10', 'q2'], 'subset' => ['date', 'query'],
                     'impressions' => 150, 'clicks' => 15, 'ctr' => 0.10, 'position' => 5.0],
                    ['keys' => ['2026-04-10', 'q3'], 'subset' => ['date', 'query'],
                     'impressions' => 100, 'clicks' => 10, 'ctr' => 0.10, 'position' => 6.0],
                    // Only one 5D record (very sparse)
                    ['keys' => ['2026-04-10', 'q1', 'US', 'https://example.com/', 'mobile'],
                     'subset' => self::ALL_DIMENSIONS,
                     'impressions' => 50, 'clicks' => 5, 'ctr' => 0.10, 'position' => 3.0],
                ],
                500, 50, true,
            ],
        ];
    }

    public function ipfMarginalProvider(): array
    {
        $rows = [
            // S0: Global
            ['keys' => ['2026-04-10'], 'subset' => ['date'],
             'impressions' => 200, 'clicks' => 20, 'ctr' => 0.10, 'position' => 5.0],
            // [date, query]
            ['keys' => ['2026-04-10', 'shoes'], 'subset' => ['date', 'query'],
             'impressions' => 120, 'clicks' => 12, 'ctr' => 0.10, 'position' => 4.0],
            ['keys' => ['2026-04-10', 'boots'], 'subset' => ['date', 'query'],
             'impressions' => 60, 'clicks' => 6, 'ctr' => 0.10, 'position' => 6.0],
            // [date, country]
            ['keys' => ['2026-04-10', 'US'], 'subset' => ['date', 'country'],
             'impressions' => 130, 'clicks' => 13, 'ctr' => 0.10, 'position' => 4.5],
            ['keys' => ['2026-04-10', 'DE'], 'subset' => ['date', 'country'],
             'impressions' => 50, 'clicks' => 5, 'ctr' => 0.10, 'position' => 6.5],
            // 5D records
            ['keys' => ['2026-04-10', 'shoes', 'US', 'https://example.com/', 'mobile'],
             'subset' => self::ALL_DIMENSIONS,
             'impressions' => 60, 'clicks' => 6, 'ctr' => 0.10, 'position' => 3.5],
            ['keys' => ['2026-04-10', 'boots', 'DE', 'https://example.com/', 'desktop'],
             'subset' => self::ALL_DIMENSIONS,
             'impressions' => 20, 'clicks' => 2, 'ctr' => 0.10, 'position' => 7.0],
        ];

        return [
            'query_marginals' => [
                'query_marginals',
                $rows,
                [
                    ['dims' => ['query' => 'shoes'], 'expectedImpr' => 120, 'expectedClicks' => 12],
                    ['dims' => ['query' => 'boots'], 'expectedImpr' => 60, 'expectedClicks' => 6],
                ],
            ],
            'country_marginals' => [
                'country_marginals',
                $rows,
                [
                    ['dims' => ['country' => 'US'], 'expectedImpr' => 130, 'expectedClicks' => 13],
                    ['dims' => ['country' => 'DE'], 'expectedImpr' => 50, 'expectedClicks' => 5],
                ],
            ],
        ];
    }

    // =========================================================================
    // Legacy helper method tests (still valid — methods preserved)
    // =========================================================================

    public function testIsParentOfSupportsDifferentSubsetOrder(): void
    {
        $parentSubset = ['date', 'page'];
        $parentDims = ['2023-01-01', 'https://example.com/'];

        $childSubset = ['date', 'query', 'page'];
        $childDims = ['2023-01-01', 'test query', 'https://example.com/'];

        $this->assertTrue(Helpers::isParentOf($parentSubset, $parentDims, $childSubset, $childDims));
        $this->assertFalse(Helpers::isParentOf($childSubset, $childDims, $parentSubset, $parentDims));
        $this->assertFalse(Helpers::isParentOf($parentSubset, ['2023-01-01', 'https://other.com/'], $childSubset, $childDims));
    }

    public function testComputeChildrenSumAndCalculateDifferences(): void
    {
        $records = [
            [
                'subset' => ['date', 'page'],
                'keys' => ['2023-01-01', 'url1'],
                'impressions' => 100,
                'clicks' => 10,
            ],
            [
                'subset' => ['date', 'query', 'page'],
                'keys' => ['2023-01-01', 'q1', 'url1'],
                'impressions' => 40,
                'clicks' => 4,
            ],
            [
                'subset' => ['date', 'query', 'page'],
                'keys' => ['2023-01-01', 'q2', 'url1'],
                'impressions' => 30,
                'clicks' => 3,
            ],
        ];

        $sums = Helpers::computeChildrenSum($records);
        $this->assertSame(70, $sums[0]['impressions']);
        $this->assertSame(7, $sums[0]['clicks']);
        $this->assertSame(0, $sums[1]['impressions']);
        $this->assertSame(0, $sums[2]['impressions']);

        $diffs = Helpers::calculateDifferences($records, $sums);
        $this->assertSame(30, $diffs[0]['impressions_difference']);
        $this->assertSame(3, $diffs[0]['clicks_difference']);
        $this->assertSame(70, $diffs[0]['children_sum']['impressions']);
        $this->assertSame(7, $diffs[0]['children_sum']['clicks']);
    }

    public function testAllocatePositiveDifferencesClampsMixedSignsAndCapsClicks(): void
    {
        $records = [
            [
                'subset' => ['date', 'page'],
                'keys' => ['2023-01-01', 'url1'],
                'impressions_difference' => 30,
                'clicks_difference' => -5,
            ],
            [
                'subset' => ['date', 'page'],
                'keys' => ['2023-01-01', 'url1'],
                'impressions_difference' => 5,
                'clicks_difference' => 10,
            ],
        ];

        $result = Helpers::allocatePositiveDifferences($records, ['date', 'query', 'page']);
        $synthetics = array_values(array_filter($result, static fn(array $row): bool => !empty($row['synthetic'])));

        $this->assertCount(2, $synthetics);
        $this->assertSame(30, $synthetics[0]['impressions']);
        $this->assertSame(0, $synthetics[0]['clicks']);
        $this->assertSame(5, $synthetics[1]['impressions']);
        $this->assertSame(5, $synthetics[1]['clicks']);
    }

    public function testFlagOrScaleNegativeDifferencesUsesSafeDiagnosticMode(): void
    {
        $records = [
            [
                'impressions' => 100,
                'clicks' => 10,
                'impressions_difference' => -20,
                'clicks_difference' => -2,
                'children_sum' => ['impressions' => 120, 'clicks' => 12],
            ],
        ];

        $result = Helpers::flagOrScaleNegativeDifferences($records, true);

        $this->assertFalse($result[0]['scaled']);
        $this->assertTrue($result[0]['flagged']);
        $this->assertSame(100, $result[0]['impressions']);
        $this->assertSame(10, $result[0]['clicks']);
    }
}
