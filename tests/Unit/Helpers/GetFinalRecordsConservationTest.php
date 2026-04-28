<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Anibalealvarezs\GoogleHubDriver\Helpers\Helpers;
use PHPUnit\Framework\TestCase;

class GetFinalRecordsConservationTest extends TestCase
{
    private const ALL_DIMENSIONS = ['date', 'query', 'country', 'page', 'device'];

    /**
     * @dataProvider scenarioProvider
     */
    public function testGetFinalRecordsConservesCanonicalTotalsAndOutputInvariants(
        string $scenario,
        array $rows,
        bool $expectSynthetic
    ): void {
        $records = Helpers::getFinalRecords($rows, [], [], self::ALL_DIMENSIONS);

        $this->assertNotEmpty($records, "[$scenario] Expected reconciled output rows.");

        $syntheticCount = count(array_filter($records, static fn(array $row): bool => !empty($row['synthetic'])));
        if ($expectSynthetic) {
            $this->assertGreaterThan(0, $syntheticCount, "[$scenario] Expected at least one synthetic row.");
        }

        $generalRows = array_values(array_filter($rows, static fn(array $row): bool => ($row['subset'] ?? []) === ['date', 'page']));
        $this->assertCount(1, $generalRows, "[$scenario] Fixture must include exactly one canonical date+page row.");

        $expectedImpressions = max(0, (int)($generalRows[0]['impressions'] ?? 0));
        $expectedClicks = max(0, (int)($generalRows[0]['clicks'] ?? 0));
        if ($expectedImpressions > 0 && $expectedClicks > $expectedImpressions) {
            $expectedClicks = $expectedImpressions;
        }
        $expectedCtr = $expectedImpressions > 0 ? $expectedClicks / $expectedImpressions : 0.0;

        $totalImpressions = 0;
        $totalClicks = 0;
        foreach ($records as $row) {
            $impressions = (int)($row['impressions'] ?? 0);
            $clicks = (int)($row['clicks'] ?? 0);
            $ctr = (float)($row['ctr'] ?? 0);
            $position = $row['position'] ?? null;

            $this->assertGreaterThanOrEqual(0, $impressions, "[$scenario] Impressions must be non-negative.");
            $this->assertGreaterThanOrEqual(0, $clicks, "[$scenario] Clicks must be non-negative.");
            $this->assertLessThanOrEqual($impressions, $clicks, "[$scenario] Clicks cannot exceed impressions.");
            $this->assertGreaterThanOrEqual(0.0, $ctr, "[$scenario] CTR must be >= 0.");
            $this->assertLessThanOrEqual(1.0, $ctr, "[$scenario] CTR must be <= 1.");

            if ($position !== null) {
                $this->assertGreaterThanOrEqual(0.0, (float)$position, "[$scenario] Position must be non-negative (or null).");
            }

            $totalImpressions += $impressions;
            $totalClicks += $clicks;
        }

        $this->assertSame(
            $expectedImpressions,
            $totalImpressions,
            "[$scenario] Aggregated impressions must match canonical date+page totals exactly."
        );
        $this->assertSame(
            $expectedClicks,
            $totalClicks,
            "[$scenario] Aggregated clicks must match canonical date+page totals exactly."
        );
        $this->assertEqualsWithDelta(
            $expectedCtr,
            $totalImpressions > 0 ? $totalClicks / $totalImpressions : 0.0,
            1e-9,
            "[$scenario] Aggregated CTR must match canonical date+page CTR."
        );

        // Specificity conservation: for each query known at (date,page,query) level,
        // final output must preserve exactly that query total across missing dimensions.
        $queryParents = array_values(array_filter($rows, static fn(array $row): bool => ($row['subset'] ?? []) === ['date', 'page', 'query']));
        foreach ($queryParents as $parent) {
            $parentIndex = array_flip($parent['subset']);
            $query = $parent['keys'][$parentIndex['query']] ?? null;
            if ($query === null) {
                continue;
            }

            $queryImpressions = 0;
            $queryClicks = 0;
            foreach ($records as $row) {
                // Output keys are normalized to [date, query, country, page, device].
                if (($row['keys'][1] ?? null) !== $query) {
                    continue;
                }
                $queryImpressions += (int)($row['impressions'] ?? 0);
                $queryClicks += (int)($row['clicks'] ?? 0);
            }

            $this->assertSame(
                (int)($parent['impressions'] ?? 0),
                $queryImpressions,
                "[$scenario] Query-level impressions must be conserved for query '{$query}'."
            );
            $this->assertSame(
                (int)($parent['clicks'] ?? 0),
                $queryClicks,
                "[$scenario] Query-level clicks must be conserved for query '{$query}'."
            );
        }
    }

    public function scenarioProvider(): array
    {
        return [
            'basic_positive_residual' => [
                'basic_positive_residual',
                [
                    [
                        'keys' => ['2026-04-10', 'https://example.com/'],
                        'subset' => ['date', 'page'],
                        'impressions' => 100,
                        'clicks' => 10,
                        'ctr' => 0.10,
                        'position' => 5.0,
                    ],
                    [
                        'keys' => ['2026-04-10', 'brand shoes', 'US', 'https://example.com/', 'mobile'],
                        'subset' => self::ALL_DIMENSIONS,
                        'impressions' => 70,
                        'clicks' => 7,
                        'ctr' => 0.10,
                        'position' => 4.2,
                    ],
                ],
                true,
            ],
            'overlap_partial_plus_global_remainder' => [
                'overlap_partial_plus_global_remainder',
                [
                    [
                        'keys' => ['2026-04-11', 'https://example.com/'],
                        'subset' => ['date', 'page'],
                        'impressions' => 100,
                        'clicks' => 10,
                        'ctr' => 0.10,
                        'position' => 5.0,
                    ],
                    [
                        'keys' => ['2026-04-11', 'https://example.com/', 'brand shoes'],
                        'subset' => ['date', 'page', 'query'],
                        'impressions' => 90,
                        'clicks' => 9,
                        'ctr' => 0.10,
                        'position' => 4.8,
                    ],
                    [
                        'keys' => ['2026-04-11', 'brand shoes', 'US', 'https://example.com/', 'mobile'],
                        'subset' => self::ALL_DIMENSIONS,
                        'impressions' => 70,
                        'clicks' => 7,
                        'ctr' => 0.10,
                        'position' => 4.2,
                    ],
                ],
                true,
            ],
            'malformed_source_metrics_sanitized' => [
                'malformed_source_metrics_sanitized',
                [
                    [
                        'keys' => ['2026-04-12', 'https://example.com/'],
                        'subset' => ['date', 'page'],
                        'impressions' => 80,
                        'clicks' => 8,
                        'ctr' => -0.25,
                        'position' => -2.0,
                    ],
                    [
                        'keys' => ['2026-04-12', 'query-x', 'CA', 'https://example.com/', 'desktop'],
                        'subset' => self::ALL_DIMENSIONS,
                        'impressions' => 50,
                        'clicks' => 5,
                        'ctr' => 2.75,
                        'position' => -1.1,
                    ],
                ],
                true,
            ],
        ];
    }

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

