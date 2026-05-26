<?php

namespace Tests\Unit\Conversions;

use Anibalealvarezs\GoogleHubDriver\Conversions\GoogleBusinessProfileMetricConvert;
use PHPUnit\Framework\TestCase;

class GoogleBusinessProfileMetricConvertTest extends TestCase
{
    private array $sampleGbpResponse;

    protected function setUp(): void
    {
        $this->sampleGbpResponse = [
            'timeSeries' => [
                'dailyMetricTimeSeries' => [
                    [
                        'dailyMetric' => 'WEBSITE_CLICKS',
                        'dailyValues' => [
                            [
                                'date' => [
                                    'year' => 2026,
                                    'month' => 5,
                                    'day' => 26
                                ],
                                'value' => '12'
                            ]
                        ]
                    ],
                    [
                        'dailyMetric' => 'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
                        'dailyValues' => [
                            [
                                'date' => [
                                    'year' => 2026,
                                    'month' => 5,
                                    'day' => 26
                                ],
                                'value' => '50'
                            ]
                        ]
                    ],
                    [
                        'dailyMetric' => 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
                        'dailyValues' => [
                            [
                                'date' => [
                                    'year' => 2026,
                                    'month' => 5,
                                    'day' => 26
                                ],
                                'value' => '30'
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    public function testConvert()
    {
        $metrics = GoogleBusinessProfileMetricConvert::convert($this->sampleGbpResponse, 'loc123');

        $this->assertCount(3, $metrics);

        $metricArray = $metrics->toArray();

        // website_clicks -> WEBSITE_CLICKS => sessions & conversions = 12
        // impressions -> BUSINESS_IMPRESSIONS_DESKTOP_MAPS + BUSINESS_IMPRESSIONS_DESKTOP_SEARCH = 50 + 30 = 80
        $impressionsMetric = current(array_filter($metricArray, fn($m) => $m->name === 'impressions'));
        $this->assertNotFalse($impressionsMetric);
        $this->assertEquals(80.0, $impressionsMetric->value);

        $sessionsMetric = current(array_filter($metricArray, fn($m) => $m->name === 'sessions'));
        $this->assertNotFalse($sessionsMetric);
        $this->assertEquals(12.0, $sessionsMetric->value);
    }
}
