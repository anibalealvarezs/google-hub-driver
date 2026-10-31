<?php

namespace Tests\Unit\Drivers;

use Anibalealvarezs\GoogleApi\Services\SearchConsole\SearchConsoleApi;
use Anibalealvarezs\GoogleHubDriver\Drivers\SearchConsoleDriver;
use Anibalealvarezs\GoogleHubDriver\Conversions\GoogleSearchConsoleConvert;
use PHPUnit\Framework\TestCase;

class SearchConsoleSearchTypesTest extends TestCase
{
    private function driver(): SearchConsoleDriver
    {
        return new class extends SearchConsoleDriver {
            public function daily($api, string $type, bool $synthetics = false, ?callable $continue = null): array {
                return $this->fetchGSCDailyData($api, 'https://example.com/', '2026-10-01', 25000, $synthetics, $type, $continue);
            }
            public function period($api, ?callable $continue = null, array $types = SearchConsoleDriver::SEARCH_TYPES): array {
                return $this->fetchGSCPeriodData($api, 'https://example.com/', '2026-10-01', '2026-10-01', 25000, true, $continue, $types);
            }
        };
    }

    public function testEveryTypeIsRequestedSeparatelyAndEmptyTypesDoNotFanOut(): void
    {
        $types = [];
        $api = $this->createMock(SearchConsoleApi::class);
        $api->expects($this->exactly(6))->method('getAllSearchQueryResults')
            ->willReturnCallback(function (...$args) use (&$types) { $types[] = $args[7]; return ['rows' => []]; });
        $this->assertSame([], $this->driver()->period($api));
        $this->assertSame(SearchConsoleDriver::SEARCH_TYPES, $types);
    }

    public function testFeedsUseSupportedDimensionsAndNeverProducePosition(): void
    {
        foreach (['discover', 'googleNews'] as $type) {
            $api = $this->createMock(SearchConsoleApi::class);
            $api->expects($this->once())->method('getAllSearchQueryResults')
                ->with('https://example.com/', '2026-10-01', '2026-10-01', 25000, 0, $this->anything(), ['date', 'page', 'country'], $type)
                ->willReturn(['rows' => [['keys' => ['2026-10-01', 'https://example.com/article', 'usa'], 'clicks' => 2, 'impressions' => 10, 'ctr' => .2, 'position' => 0]]]);
            $rows = $this->driver()->daily($api, $type);
            $this->assertSame($type, $rows[0]['search_type']);
            $this->assertArrayNotHasKey('position', $rows[0]);
            $metrics = GoogleSearchConsoleConvert::metrics($rows, 'https://example.com/', 'site');
            $this->assertCount(3, $metrics);
            foreach ($metrics as $metric) $this->assertNotSame('position', $metric->name);
        }
    }

    public function testLegacyRowsDefaultToWebAndTypesHaveDistinctIdentities(): void
    {
        $base = ['date' => '2026-10-01', 'page' => 'https://example.com/', 'searchAppearance' => 'standard', 'clicks' => 2];
        $legacy = GoogleSearchConsoleConvert::metrics([$base], 'https://example.com/', 'site')->first();
        $web = GoogleSearchConsoleConvert::metrics([$base + ['search_type' => 'web']], 'https://example.com/', 'site')->first();
        $image = GoogleSearchConsoleConvert::metrics([$base + ['search_type' => 'image']], 'https://example.com/', 'site')->first();
        $this->assertSame($legacy->metricConfigKey, $web->metricConfigKey);
        $this->assertNotSame($web->metricConfigKey, $image->metricConfigKey);
        $this->assertNotSame($web->dimensionsHash, $image->dimensionsHash);
    }

    public function testSearchAndAppearancePassesRetainTheirTypeWithoutBaselineDoubleCounting(): void
    {
        foreach (['web', 'image', 'video', 'news'] as $type) {
            $calls = [];
            $api = $this->createMock(SearchConsoleApi::class);
            $api->expects($this->exactly(2))->method('getAllSearchQueryResults')
                ->willReturnCallback(function (...$args) use ($type, &$calls) {
                    $this->assertSame($type, $args[7]);
                    $calls[] = $args[6];
                    return ['rows' => [['keys' => $args[6] === ['searchAppearance']
                        ? ['VIDEO'] : ['2026-10-01', 'example', 'usa', 'https://example.com/', 'desktop'],
                        'clicks' => 2, 'impressions' => 10, 'ctr' => .2, 'position' => 3]]];
                });
            $rows = $this->driver()->daily($api, $type);
            $this->assertSame([['date', 'query', 'country', 'page', 'device'], ['searchAppearance']], $calls);
            $this->assertNotEmpty($rows);
            foreach ($rows as $row) $this->assertSame($type, $row['search_type']);
            $this->assertSame('VIDEO', $rows[count($rows)-1]['searchAppearance']);
        }
    }

    public function testCancellationStopsBeforeAnotherProviderCall(): void
    {
        $api = $this->createMock(SearchConsoleApi::class);
        $api->expects($this->never())->method('getAllSearchQueryResults');
        $this->expectExceptionMessage('Sync aborted');
        $this->driver()->period($api, fn() => false);
    }

    public function testConfigurationAndProfilesKeepWebAsTheReportingDefault(): void
    {
        $driver = $this->driver();
        $this->assertSame(SearchConsoleDriver::DEFAULT_SEARCH_TYPES, $driver->getConfigSchema()['global']['search_types']);
        $config = $driver->updateConfiguration(['type' => 'global', 'search_types' => ['web', 'image', 'web']], []);
        $this->assertSame(['web', 'image'], $config['channels']['google_search_console']['search_types']);
        foreach (SearchConsoleDriver::getAggregationProfiles() as $profile) {
            $this->assertSame('web', $profile['default_filters']['dimensions.search_type']);
            $this->assertContains('in', $profile['filter_contract']['dimensions.search_type']);
        }
        $this->expectException(\InvalidArgumentException::class);
        $driver->updateConfiguration(['search_types' => ['unsupported']], []);
    }
}
