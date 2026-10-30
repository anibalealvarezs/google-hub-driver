<?php

namespace Tests\Unit\Drivers;

use Anibalealvarezs\GoogleHubDriver\Drivers\GoogleAnalyticsDriver;
use Anibalealvarezs\GoogleHubDriver\Conversions\GoogleAnalyticsMetricConvert;
use PHPUnit\Framework\TestCase;

final class GoogleAnalyticsCorrectnessTest extends TestCase
{
    public function testRecipeCatalogSeparatesSourceAvailabilityFromCompatibilityAndCoverage(): void
    {
        $recipes = GoogleAnalyticsDriver::getReportRecipes();
        foreach ($recipes as $recipe) self::assertLessThanOrEqual(9, count($recipe['dimensions']));
        self::assertCount(9, $recipes['event_matrix']['dimensions']);
        $catalog = GoogleAnalyticsDriver::getPropertyRecipeCatalog([
            'metrics' => [['apiName' => 'eventCount'], ['apiName' => 'keyEvents']],
            'dimensions' => array_map(fn ($name) => ['apiName' => $name], $recipes['event_matrix']['dimensions']),
        ]);
        self::assertTrue($catalog['event_matrix']['metadata_available']);
        self::assertSame(['eventCount', 'keyEvents'], $catalog['event_matrix']['source_metrics']);
        self::assertSame('not_checked', $catalog['event_matrix']['compatibility']);
        self::assertSame('unknown', $catalog['event_matrix']['cache_coverage']);
        self::assertFalse($catalog['traffic_matrix']['metadata_available']);
    }

    public function testRevenueIsNotCostAndSourceNamesFollowPropertyMetadata(): void
    {
        $dictionary = GoogleAnalyticsDriver::getCanonicalMetricDictionary();
        self::assertSame([], $dictionary['spend']);
        self::assertContains('totalRevenue', $dictionary['revenue']);
        $metadata = ['metrics' => [['apiName' => 'keyEvents'], ['apiName' => 'sessions']]];
        self::assertSame(['keyEvents', 'sessions'], GoogleAnalyticsDriver::resolveReportMetricNames(['conversions', 'keyEvents', 'sessions'], $metadata));
        self::assertSame(['conversions'], GoogleAnalyticsDriver::resolveReportMetricNames(['keyEvents'], ['metrics' => [['apiName' => 'conversions']]]));
    }

    public function testQueryPolicyPublishesScopedMetricsAndGenericReducers(): void
    {
        $contract = GoogleAnalyticsDriver::getAggregationProfiles()[0]['query_contract'];
        self::assertSame('traffic_matrix', $contract['default_filters']['dimensions.scope']);
        self::assertNotContains('totalRevenue', $contract['scopes']['acquisition_matrix']);
        self::assertSame(['sessions'], $contract['rules']['bounceRate']['weight_names']);
        self::assertSame('sum_of_reported_user_counts_not_window_unique', $contract['rules']['totalUsers']['quality']);
        self::assertSame(['source', 'medium'], $contract['derived_dimensions']['sessionSourceMedium']['parts']);
    }

    public function testUnsupportedSourceMetricsFailRatherThanBecomeZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GoogleAnalyticsDriver::resolveReportMetricNames(['madeUpMetric'], ['metrics' => [['apiName' => 'sessions']]]);
    }

    public function testIncompatibleReportsFailExplicitly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GoogleAnalyticsDriver::assertReportCompatibility(['metricCompatibilities' => [['compatibility' => 'INCOMPATIBLE']], 'dimensionCompatibilities' => []]);
    }

    public function testCompatibilityChecksRequestedFieldsRatherThanUnrelatedCandidates(): void
    {
        GoogleAnalyticsDriver::assertReportCompatibility([
            'metricCompatibilities' => [['compatibility' => 'COMPATIBLE', 'metricMetadata' => ['apiName' => 'sessions']], ['compatibility' => 'INCOMPATIBLE', 'metricMetadata' => ['apiName' => 'unrelated']]],
            'dimensionCompatibilities' => [['compatibility' => 'COMPATIBLE', 'dimensionMetadata' => ['apiName' => 'date']]],
        ], ['metrics' => [['name' => 'sessions']], 'dimensions' => [['name' => 'date']]]);
        self::assertTrue(true);
    }

    public function testFailedReportDoesNotReturnSuccessfulSync(): void
    {
        $class = \Anibalealvarezs\GoogleApi\Services\AnalyticsData\AnalyticsDataApi::class;
        $methods = ['getMetadata', 'runAllReportsAndProcess'];
        if (method_exists($class, 'checkCompatibility')) $methods[] = 'checkCompatibility';
        $builder = $this->getMockBuilder($class)->disableOriginalConstructor()->onlyMethods($methods);
        if (!method_exists($class, 'checkCompatibility')) $builder->addMethods(['checkCompatibility']);
        $api = $builder->getMock();
        $names = ['screenPageViews', 'sessions', 'bounceRate', 'totalRevenue'];
        $api->method('getMetadata')->willReturn(['metrics' => array_map(fn ($name) => ['apiName' => $name], $names)]);
        $api->method('checkCompatibility')->willReturn(['metricCompatibilities' => array_map(fn ($name) => ['compatibility' => 'COMPATIBLE', 'metricMetadata' => ['apiName' => $name]], $names), 'dimensionCompatibilities' => [['compatibility' => 'COMPATIBLE', 'dimensionMetadata' => ['apiName' => 'date']]]]);
        $api->expects(self::once())->method('runAllReportsAndProcess')->willThrowException(new \RuntimeException('synthetic source failure'));
        $driver = new class extends GoogleAnalyticsDriver {
            public \Anibalealvarezs\GoogleApi\Services\AnalyticsData\AnalyticsDataApi $fixtureApi;
            protected function initializeApi(array $config): \Anibalealvarezs\GoogleApi\Services\AnalyticsData\AnalyticsDataApi { return $this->fixtureApi; }
        };
        $driver->fixtureApi = $api;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sync is incomplete');
        $driver->sync(new \DateTime('2026-10-01'), new \DateTime('2026-10-02'), ['platform_id' => '123', 'level' => 'account']);
    }

    public function testQualityAndMissingVsZeroSurviveConversionWithoutChangingGrain(): void
    {
        $response = ['property_id' => 'fixture', 'metadata' => ['subjectToThresholding' => true, 'currencyCode' => 'USD', 'timeZone' => 'UTC', 'untrustedExtra' => 'ignored'], 'dimensionHeaders' => [['name' => 'date'], ['name' => 'sessionSourceMedium']], 'metricHeaders' => [['name' => 'keyEvents'], ['name' => 'sessions'], ['name' => 'bounceRate']], 'rows' => [['dimensionValues' => [['value' => '20261001'], ['value' => 'google / organic']], 'metricValues' => [['value' => '0'], ['value' => '10']]]]];
        $rows = GoogleAnalyticsMetricConvert::preprocessRows($response);
        self::assertSame(0.0, $rows[0]['conversions']);
        self::assertArrayNotHasKey('bounceRate', $rows[0]);
        $metrics = GoogleAnalyticsMetricConvert::trafficMatrixMetrics($response, channeledAccount: 'fixture', metricsToProcess: ['conversions', 'sessions', 'bounceRate']);
        self::assertCount(2, $metrics);
        foreach ($metrics as $metric) {
            self::assertTrue($metric->metadata['ga4_report_quality']['subjectToThresholding']);
            self::assertArrayNotHasKey('untrustedExtra', $metric->metadata['ga4_report_quality']);
            self::assertContains('source', array_column($metric->dimensions, 'dimensionKey'));
            self::assertNotContains('sessionSourceMedium', array_column($metric->dimensions, 'dimensionKey'));
        }
    }
}
