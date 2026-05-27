<?php

namespace Tests\Unit\Conversions;

use Anibalealvarezs\GoogleHubDriver\Conversions\GoogleAdsMetricConvert;
use Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity;
use PHPUnit\Framework\TestCase;

class GoogleAdsMetricConvertTest extends TestCase
{
    public function testCustomerMetricsConversion(): void
    {
        $rows = [
            [
                'customer' => ['id' => '12345'],
                'metrics' => [
                    'cost_micros' => '1500000', // 1.5
                    'impressions' => '1000',
                    'clicks' => '50',
                    'conversions' => '2',
                ],
                'segments' => [
                    'date' => '2025-01-01',
                    'device' => 'MOBILE'
                ]
            ]
        ];

        $collection = GoogleAdsMetricConvert::customerMetrics($rows, null, 'acc1', 'acc1', 'daily');
        
        $this->assertCount(4, $collection);

        $spendMetric = $collection->filter(fn(UniversalEntity $e) => $e->name === 'spend')->first();
        $this->assertNotNull($spendMetric);
        $this->assertEquals(1.5, $spendMetric->value);
        $this->assertEquals('2025-01-01', $spendMetric->metricDate);
        $this->assertEquals('12345', $spendMetric->platformId);
        $this->assertEquals('google_ads', $spendMetric->channel);
        
        $impressionsMetric = $collection->filter(fn(UniversalEntity $e) => $e->name === 'impressions')->first();
        $this->assertEquals(1000, $impressionsMetric->value);

        $clicksMetric = $collection->filter(fn(UniversalEntity $e) => $e->name === 'clicks')->first();
        $this->assertEquals(50, $clicksMetric->value);

        $conversionsMetric = $collection->filter(fn(UniversalEntity $e) => $e->name === 'conversions')->first();
        $this->assertEquals(2, $conversionsMetric->value);
    }

    public function testCampaignMetricsConversion(): void
    {
        $rows = [
            [
                'campaign' => ['id' => '98765'],
                'metrics' => [
                    'cost_micros' => '5000000', // 5.0
                ],
                'segments' => [
                    'date' => '2025-01-02',
                    'device' => 'DESKTOP'
                ]
            ]
        ];

        $collection = GoogleAdsMetricConvert::campaignMetrics($rows, null, 'acc1', 'cmp1', 'cmp1', 'daily', ['spend']);
        
        $this->assertCount(1, $collection);

        $spendMetric = $collection->first();
        $this->assertEquals('spend', $spendMetric->name);
        $this->assertEquals(5.0, $spendMetric->value);
        $this->assertEquals('2025-01-02', $spendMetric->metricDate);
        $this->assertEquals('98765', $spendMetric->platformId);
        $this->assertEquals('98765', $spendMetric->channeledCampaignPlatformId);
    }
}
