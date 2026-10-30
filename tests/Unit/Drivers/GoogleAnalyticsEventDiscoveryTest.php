<?php

namespace Tests\Unit\Drivers;

use Anibalealvarezs\GoogleApi\Services\AnalyticsData\AnalyticsDataApi;
use Anibalealvarezs\GoogleHubDriver\Drivers\GoogleAnalyticsDriver;
use PHPUnit\Framework\TestCase;

final class GoogleAnalyticsEventDiscoveryTest extends TestCase
{
    private function fixture(): array
    {
        $methods = ['getMetadata', 'runAllReportsAndProcess', 'runSimpleReport'];
        if (method_exists(AnalyticsDataApi::class, 'checkCompatibility')) $methods[] = 'checkCompatibility';
        $builder = $this->getMockBuilder(AnalyticsDataApi::class)->disableOriginalConstructor()->onlyMethods($methods);
        if (!method_exists(AnalyticsDataApi::class, 'checkCompatibility')) $builder->addMethods(['checkCompatibility']);
        $api = $builder->getMock();
        $api->method('getMetadata')->willReturn(['metrics' => [['apiName' => 'eventCount']]]);
        $api->method('checkCompatibility')->willReturn([
            'metricCompatibilities' => [['compatibility' => 'COMPATIBLE', 'metricMetadata' => ['apiName' => 'eventCount']]],
            'dimensionCompatibilities' => [['compatibility' => 'COMPATIBLE', 'dimensionMetadata' => ['apiName' => 'eventName']]],
        ]);
        $driver = new class extends GoogleAnalyticsDriver {
            public AnalyticsDataApi $fixtureApi;
            protected function initializeApi(array $config): AnalyticsDataApi { return $this->fixtureApi; }
        };
        $driver->fixtureApi = $api;
        return [$driver, $api];
    }

    public function testOrdinaryCustomEventsAcrossPagesKeepTheirSourceIdentity(): void
    {
        [$driver, $api] = $this->fixture();
        $api->expects(self::once())->method('runAllReportsAndProcess')->willReturnCallback(function ($property, $payload, $callback) {
            self::assertSame([['name' => 'eventCount']], $payload['metrics']);
            foreach (['page_view', 'custom_quote_started', 'page_view'] as $name) {
                $callback(['dimensionHeaders' => [['name' => 'eventName']], 'metricHeaders' => [['name' => 'eventCount']], 'rows' => [['dimensionValues' => [['value' => $name]], 'metricValues' => [['value' => '2']]]]]);
            }
        });
        $saved = [];
        $driver->setDataProcessor(function ($items, $type) use (&$saved) {
            self::assertSame('event', $type);
            foreach ($items as $item) {
                self::assertSame('123', $item->channeledAccountId);
                self::assertSame($item->platformId, $item->name);
                $saved[] = $item->name;
            }
        });
        $response = $driver->syncEntities(new \DateTime('2026-10-01'), new \DateTime('2026-10-02'), ['platform_id' => '123', 'entity' => 'events']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['page_view', 'custom_quote_started'], $saved);
    }

    public function testCancellationDoesNotStartTheReport(): void
    {
        [$driver, $api] = $this->fixture();
        $api->expects(self::never())->method('runAllReportsAndProcess');
        self::assertSame(500, $driver->syncEntities(new \DateTime('2026-10-01'), new \DateTime('2026-10-02'), ['platform_id' => '123', 'entity' => 'events'], fn () => false)->getStatusCode());
    }

    public function testEventMetricConversionRetainsCustomNameAndEventScope(): void
    {
        $metrics = \Anibalealvarezs\GoogleHubDriver\Conversions\GoogleAnalyticsMetricConvert::eventMatrixMetrics([
            'property_id' => '123',
            'dimensionHeaders' => [['name' => 'date'], ['name' => 'eventName']],
            'metricHeaders' => [['name' => 'eventCount']],
            'rows' => [['dimensionValues' => [['value' => '20261001'], ['value' => 'custom_quote_started']], 'metricValues' => [['value' => '4']]]],
        ], channeledAccount: '123', metricsToProcess: ['eventCount']);
        self::assertCount(1, $metrics);
        self::assertSame('custom_quote_started', $metrics->first()->channeledEventPlatformId);
        self::assertSame('custom_quote_started', $metrics->first()->eventPlatformId);
        self::assertContains('event_matrix', array_column($metrics->first()->dimensions, 'dimensionValue'));
    }

    public function testFailedDiscoveryPreventsMetricSync(): void
    {
        [$driver, $api] = $this->fixture();
        $api->expects(self::never())->method('runAllReportsAndProcess');
        $api->method('runSimpleReport')->willThrowException(new \RuntimeException('fixture discovery failure'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('entity discovery failed');
        $driver->sync(new \DateTime('2026-10-01'), new \DateTime('2026-10-02'), ['platform_id' => '123', 'level' => 'event_matrix', 'metrics' => ['eventCount']]);
    }
}
