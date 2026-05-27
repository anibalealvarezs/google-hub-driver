<?php

namespace Tests\Unit\Conversions;

use Anibalealvarezs\GoogleHubDriver\Conversions\GoogleAnalyticsMetricConvert;
use PHPUnit\Framework\TestCase;

class GoogleAnalyticsMetricConvertTest extends TestCase
{
    private array $sampleGa4Response;

    protected function setUp(): void
    {
        $this->sampleGa4Response = [
            'property_id' => '123456789',
            'dimensionHeaders' => [
                ['name' => 'date'],
                ['name' => 'sessionSourceMedium'],
                ['name' => 'sessionCampaignName'],
                ['name' => 'pagePath']
            ],
            'metricHeaders' => [
                ['name' => 'activeUsers'],
                ['name' => 'screenPageViews'],
                ['name' => 'sessions'],
                ['name' => 'bounceRate'],
                ['name' => 'totalRevenue']
            ],
            'rows' => [
                [
                    'dimensionValues' => [
                        ['value' => '20260526'],
                        ['value' => 'google / cpc'],
                        ['value' => 'Black_Friday_Sale'],
                        ['value' => '/home']
                    ],
                    'metricValues' => [
                        ['value' => '100'],
                        ['value' => '500'],
                        ['value' => '120'],
                        ['value' => '0.45'],
                        ['value' => '1500.50']
                    ]
                ],
                [
                    'dimensionValues' => [
                        ['value' => '20260526'],
                        ['value' => '(direct) / (none)'],
                        ['value' => '(not set)'],
                        ['value' => '/about']
                    ],
                    'metricValues' => [
                        ['value' => '50'],
                        ['value' => '100'],
                        ['value' => '60'],
                        ['value' => '0.50'],
                        ['value' => '0.0']
                    ]
                ]
            ]
        ];
    }

    public function testPreprocessRows()
    {
        $processed = GoogleAnalyticsMetricConvert::preprocessRows($this->sampleGa4Response);

        $this->assertCount(2, $processed);
        
        $row1 = $processed[0];
        $this->assertEquals('123456789', $row1['property_id']);
        $this->assertEquals('2026-05-26', $row1['date']);
        $this->assertEquals('google', $row1['source']);
        $this->assertEquals('cpc', $row1['medium']);
        $this->assertEquals('Black_Friday_Sale', $row1['sessionCampaignName']);
        $this->assertEquals('/home', $row1['page']);
        $this->assertEquals(100, $row1['reach']);
        $this->assertEquals(500, $row1['impressions']);
        $this->assertEquals(120, $row1['sessions']);
        $this->assertEquals(0.45, $row1['bounce_rate']);
        $this->assertEquals(1500.50, $row1['spend']); // Revenue mapped to spend as well
        $this->assertEquals(1500.50, $row1['revenue']);

        $row2 = $processed[1];
        $this->assertNull($row2['sessionCampaignName']); // '(not set)' should be mapped to null
        $this->assertEquals('(direct)', $row2['source']);
        $this->assertEquals('(none)', $row2['medium']);
    }

    public function testPropertyMetrics()
    {
        $metrics = GoogleAnalyticsMetricConvert::propertyMetrics($this->sampleGa4Response, null, null, 'channeledAcc123', 'daily');

        $this->assertCount(6, $metrics);

        $metric1 = $metrics->first();
        $this->assertEquals('123456789', $metric1->channeledAccountPlatformId);
        $this->assertEquals('google_analytics', $metric1->channel);
        
        // Dimensions
        $dimensions = $metric1->dimensions ?? [];
        $sourceDim = current(array_filter($dimensions, fn($d) => $d['dimensionKey'] === 'source'));
        $this->assertEquals('google', $sourceDim['dimensionValue'] ?? null);
        $mediumDim = current(array_filter($dimensions, fn($d) => $d['dimensionKey'] === 'medium'));
        $this->assertEquals('cpc', $mediumDim['dimensionValue'] ?? null);
    }

    public function testCampaignMetrics()
    {
        $metrics = GoogleAnalyticsMetricConvert::campaignMetrics($this->sampleGa4Response, null, 'channeledAcc123', 'daily');

        $this->assertCount(6, $metrics);

        // First row should have a channeledCampaignPlatformId
        $metric1 = $metrics->first();
        $this->assertEquals('Black_Friday_Sale', $metric1->channeledCampaignPlatformId);

        // Second row should NOT have a channeledCampaignPlatformId because it was '(not set)'
        $metric2 = $metrics->toArray()[3]; // First metric of the second row
        $this->assertNull($metric2->channeledCampaignPlatformId);
    }

    public function testPageMetrics()
    {
        $metrics = GoogleAnalyticsMetricConvert::pageMetrics($this->sampleGa4Response, null, 'channeledAcc123', 'daily');

        $this->assertCount(6, $metrics);

        $metric1 = $metrics->first();
        $this->assertEquals('123456789', $metric1->channeledAccountPlatformId);
        
        $dimensions = $metric1->dimensions ?? [];
        $pageDim = current(array_filter($dimensions, fn($d) => $d['dimensionKey'] === 'page'));
        $this->assertEquals('/home', $pageDim['dimensionValue'] ?? null);
    }
}
