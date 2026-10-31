<?php

namespace Tests\Unit\Drivers;

use Anibalealvarezs\GoogleHubDriver\Analytics\GoogleAnalyticsCatalog as Catalog;
use Anibalealvarezs\GoogleHubDriver\Conversions\GoogleAnalyticsMetricConvert;
use Anibalealvarezs\GoogleHubDriver\Drivers\GoogleAnalyticsDriver;
use PHPUnit\Framework\TestCase;

final class GoogleAnalyticsExpansionTest extends TestCase
{
    private function metadata(): array
    {
        return [
            'metrics' => array_map(fn ($name) => ['apiName' => $name, 'uiName' => $name, 'type' => 'TYPE_FLOAT', 'customDefinition' => str_contains($name, ':')], ['totalUsers', 'activeUsers', 'newUsers', 'sessions', 'engagedSessions', 'engagementRate', 'userEngagementDuration', 'purchaseRevenue', 'itemRevenue', 'cohortActiveUsers', 'cohortTotalUsers', 'customEvent:score', 'averageCustomEvent:score', 'countCustomEvent:score']),
            'dimensions' => array_map(fn ($name) => ['apiName' => $name, 'uiName' => 'Label '.$name, 'customDefinition' => str_contains($name, ':')], ['date', 'eventName', 'itemId', 'itemName', 'cohort', 'cohortNthDay', 'customEvent:category', 'countryId', 'deviceCategory', 'pagePath']),
        ];
    }
    private function context(): array { return ['property_id' => '123', 'time_zone' => 'America/Caracas', 'currency' => 'USD', 'authorization_scope' => 'actor']; }
    private function definition(): array { return ['kind' => 'window', 'metrics' => ['totalUsers'], 'dimensions' => [], 'start_date' => '2026-08-01', 'end_date' => '2026-10-29']; }

    public function testSourceExecutionRejectsPartialReportsAndStopsBeforeCancelledReads(): void
    {
        $class = \Anibalealvarezs\GoogleApi\Services\AnalyticsData\AnalyticsDataApi::class;
        $api = $this->getMockBuilder($class)->disableOriginalConstructor()->onlyMethods(['checkCompatibility', 'runReport'])->getMock();
        $api->method('checkCompatibility')->willReturn(['metricCompatibilities' => [['compatibility' => 'COMPATIBLE', 'metricMetadata' => ['apiName' => 'totalUsers']]], 'dimensionCompatibilities' => []]);
        $api->expects(self::once())->method('runReport')->willReturn(['rowCount' => 2, 'rows' => [['metricValues' => [['value' => '5']]]], 'metadata' => ['timeZone' => 'America/Caracas']]);
        $driver = new class extends GoogleAnalyticsDriver {
            public \Anibalealvarezs\GoogleApi\Services\AnalyticsData\AnalyticsDataApi $fixtureApi;
            protected function initializeApi(array $config): \Anibalealvarezs\GoogleApi\Services\AnalyticsData\AnalyticsDataApi { return $this->fixtureApi; }
        };
        $driver->fixtureApi = $api;
        $frozen = Catalog::compile($this->definition(), $this->metadata(), $this->context());
        try { $driver->executeAnalyticsReport(['platform_id' => '123'], $frozen, fn () => false); self::fail('Cancelled source reads must stop.'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('cancelled', $error->getMessage()); }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Incomplete report');
        $driver->executeAnalyticsReport(['platform_id' => '123'], $frozen);
    }

    public function testWholeWindowUsersHaveNoDailyDimensionAndDifferentAuthorizationNeverSharesIdentity(): void
    {
        $a = Catalog::compile($this->definition(), $this->metadata(), $this->context());
        self::assertSame([], $a['payload']['dimensions']);
        self::assertSame('forbidden', $a['rollup']);
        self::assertSame('source_reported_window_distinct', $a['exactness']);
        $context = $this->context(); $context['authorization_scope'] = 'other';
        self::assertNotSame($a['hash'], Catalog::compile($this->definition(), $this->metadata(), $context)['hash']);
        $this->expectException(\InvalidArgumentException::class);
        Catalog::compile(array_replace($this->definition(), ['dimensions' => ['date']]), $this->metadata(), $this->context());
    }

    public function testCustomFieldsAreFrozenAndAverageVariantsAreNeverSummed(): void
    {
        $catalog = Catalog::project($this->metadata(), GoogleAnalyticsDriver::getReportRecipes());
        self::assertSame('source_report_only', $catalog['metrics']['averageCustomEvent:score']['reducer']);
        self::assertFalse($catalog['metrics']['customEvent:score']['selected_for_sync']);
        $definition = array_replace($this->definition(), ['kind' => 'report', 'metrics' => ['customEvent:score'], 'dimensions' => ['customEvent:category']]);
        $compiled = Catalog::compile($definition, $this->metadata(), $this->context());
        self::assertSame(['TOTAL'], $compiled['payload']['metricAggregations']);
        self::assertArrayHasKey('customEvent:score', $compiled['field_definitions']['metrics']);
        $changed = $this->metadata(); $changed['metrics'][11]['type'] = 'TYPE_CURRENCY';
        self::assertNotSame($compiled['hash'], Catalog::compile($definition, $changed, $this->context())['hash']);
    }

    public function testInvalidBudgetUnavailableFieldsAndItemTransactionMixesFail(): void
    {
        foreach ([
            ['dimensions' => array_column($this->metadata()['dimensions'], 'apiName')],
            ['metrics' => ['missing']],
            ['kind' => 'report', 'metrics' => ['purchaseRevenue'], 'dimensions' => ['itemId']],
            ['end_date' => '2026-13-01'],
            ['start_date' => '2020-01-01'],
        ] as $changes) {
            try { Catalog::compile(array_replace($this->definition(), $changes), $this->metadata(), $this->context()); self::fail('Unsafe report accepted.'); }
            catch (\InvalidArgumentException $expected) { self::assertNotEmpty($expected->getMessage()); }
        }
    }

    public function testCohortsUseTheirDedicatedPopulationAndFunnelsRequireEnablement(): void
    {
        $cohort = Catalog::compile(array_replace($this->definition(), ['kind' => 'cohort', 'dimensions' => ['cohort', 'cohortNthDay'], 'metrics' => ['cohortActiveUsers', 'cohortTotalUsers']]), $this->metadata(), $this->context());
        self::assertArrayNotHasKey('dateRanges', $cohort['payload']);
        self::assertSame('firstSessionDate', $cohort['payload']['cohortSpec']['cohorts'][0]['dimension']);
        $definition = array_replace($this->definition(), ['kind' => 'funnel', 'steps' => ['page_view', 'purchase']]);
        try { Catalog::compile($definition, $this->metadata(), $this->context()); self::fail('Alpha funnel enabled implicitly.'); }
        catch (\InvalidArgumentException $e) { self::assertStringContainsString('disabled', $e->getMessage()); }
        $funnel = Catalog::compile($definition, $this->metadata(), $this->context() + ['funnel_enabled' => true]);
        self::assertSame('purchase', $funnel['payload']['funnel']['steps'][1]['filterExpression']['funnelEventFilter']['eventName']);
        self::assertArrayNotHasKey('metrics', $funnel['payload']);
    }

    public function testNewDailyGrainsPreserveRawSourceDimensionsAndSeparateProductMeasures(): void
    {
        $response = ['property_id' => '123', 'dimensionHeaders' => [['name' => 'date'], ['name' => 'itemId'], ['name' => 'itemName']], 'metricHeaders' => [['name' => 'itemRevenue']], 'rows' => [['dimensionValues' => [['value' => '20261001'], ['value' => 'sku-1'], ['value' => 'Product']], 'metricValues' => [['value' => '0']]]]];
        $rows = GoogleAnalyticsMetricConvert::metrics($response, '123', 'product_matrix', metricsToProcess: ['itemRevenue']);
        self::assertCount(1, $rows);
        $dimensions = array_column($rows->first()->dimensions, 'dimensionValue', 'dimensionKey');
        self::assertSame('sku-1', $dimensions['itemId']);
        self::assertSame('product_matrix', $dimensions['scope']);
        self::assertSame('1', $dimensions['recipe_revision']);
        foreach (GoogleAnalyticsDriver::getReportRecipes() as $recipe) self::assertLessThanOrEqual(9, count($recipe['dimensions']));
        self::assertNotContains('purchaseRevenue', GoogleAnalyticsDriver::getReportRecipes()['product_matrix']['metrics']);
    }
}
