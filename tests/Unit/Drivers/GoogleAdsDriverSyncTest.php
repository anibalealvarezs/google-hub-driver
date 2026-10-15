<?php

declare(strict_types=1);

namespace Tests\Unit\Drivers;

use Anibalealvarezs\GoogleApi\Services\GoogleAds\GoogleAdsApi;
use Anibalealvarezs\GoogleHubDriver\Drivers\GoogleAdsDriver;
use Anibalealvarezs\ApiDriverCore\Interfaces\AuthProviderInterface;
use DateTime;
use PHPUnit\Framework\TestCase;

class GoogleAdsDriverSyncTest extends TestCase
{
    public function testSyncProcessesChunksAndCallsDataProcessor(): void
    {
        $mockApi = $this->createMock(GoogleAdsApi::class);
        $mockApi->method('getMetricsForLevel')
            ->willReturn([
                'results' => [
                    [
                        'customer' => ['id' => '1234567890'],
                        'metrics' => [
                            'cost_micros' => '2500000', // 2.5 spend
                            'impressions' => '500',
                            'clicks' => '25',
                            'conversions' => '1',
                        ],
                        'segments' => [
                            'date' => '2026-01-01',
                            'device' => 'DESKTOP'
                        ]
                    ]
                ]
            ]);

        $mockAuth = $this->createMock(AuthProviderInterface::class);
        $mockAuth->method('hasCredentials')->willReturn(true);

        $logger = new \Psr\Log\NullLogger();
        $driver = new GoogleAdsDriver(null, $logger);
        $driver->setAuthProvider($mockAuth);
        $driver->setApi($mockApi);

        $processedCollection = null;
        $driver->setDataProcessor(function ($collection) use (&$processedCollection) {
            $processedCollection = $collection;
            return [
                'metrics' => $collection->count(),
                'rows' => 1,
                'duplicates' => 0
            ];
        });

        $config = [
            'ad_accounts' => [
                ['id' => '1234567890', 'enabled' => true]
            ],
            'cache_chunk_size' => '1 month',
            'AD_ACCOUNT' => [
                'ad_account_metrics' => true,
                'campaign_metrics' => false,
            ]
        ];

        $response = $driver->sync(
            startDate: new DateTime('2026-01-01'),
            endDate: new DateTime('2026-01-02'),
            config: $config
        );

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertEquals('success', $body['status']);
        $this->assertEquals(4, $body['data']['metrics']); // spend, impressions, clicks, conversions
        $this->assertNotNull($processedCollection);
    }
}
