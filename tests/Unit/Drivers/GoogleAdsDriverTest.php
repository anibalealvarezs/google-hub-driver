<?php

namespace Tests\Unit\Drivers;

use Anibalealvarezs\GoogleHubDriver\Drivers\GoogleAdsDriver;
use Anibalealvarezs\ApiDriverCore\Interfaces\AuthProviderInterface;
use Anibalealvarezs\GoogleApi\Services\GoogleAds\GoogleAdsApi;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GoogleAdsDriverTest extends TestCase
{
    private GoogleAdsDriver $driver;
    private $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->driver = new GoogleAdsDriver(null, $this->logger);
    }

    public function testGetChannel()
    {
        $this->assertEquals('google_ads', $this->driver->getChannel());
    }

    public function testGetChannelLabel()
    {
        $this->assertEquals('Google Ads', GoogleAdsDriver::getChannelLabel());
    }

    public function testGetChannelIcon()
    {
        $this->assertEquals('G', GoogleAdsDriver::getChannelIcon());
    }

    public function testGetPlatformEntityIdField()
    {
        $this->assertEquals('customer_id', GoogleAdsDriver::getPlatformEntityIdField());
    }

    public function testFetchAvailableAssets()
    {
        $authMock = $this->createMock(AuthProviderInterface::class);
        $authMock->method('hasCredentials')->willReturn(true);

        $apiMock = $this->createMock(GoogleAdsApi::class);
        $apiMock->expects($this->once())
            ->method('getAccessibleCustomers')
            ->willReturn(['resourceNames' => ['customers/123456']]);
        
        $apiMock->expects($this->once())
            ->method('getCustomerClients')
            ->with('123456')
            ->willReturn([
                'results' => [
                    [
                        'customerClient' => [
                            'id' => '09876',
                            'descriptiveName' => 'Test Account',
                            'hidden' => false
                        ]
                    ]
                ]
            ]);

        $driverMock = $this->getMockBuilder(GoogleAdsDriver::class)
            ->setConstructorArgs([null, $this->logger])
            ->onlyMethods(['getApi'])
            ->getMock();

        $driverMock->method('getApi')->willReturn($apiMock);
        $driverMock->setAuthProvider($authMock);

        $result = $driverMock->fetchAvailableAssets(false);

        $this->assertArrayHasKey('ad_accounts', $result);
        $this->assertCount(1, $result['ad_accounts']);
        $this->assertEquals('09876', $result['ad_accounts'][0]['id']);
        $this->assertEquals('Test Account', $result['ad_accounts'][0]['name']);
    }

    public function testSyncEntities()
    {
        $apiMock = $this->createMock(GoogleAdsApi::class);
        $apiMock->expects($this->once())
            ->method('getCampaigns')
            ->with('123456')
            ->willReturn(['results' => [['campaign' => ['id' => '111']]]]);

        $config = [
            'ad_accounts' => [
                ['enabled' => true, 'id' => '123456']
            ]
        ];

        $authMock = $this->createMock(AuthProviderInterface::class);
        $authMock->method('hasCredentials')->willReturn(true);

        $driverMock = $this->getMockBuilder(GoogleAdsDriver::class)
            ->setConstructorArgs([null, $this->logger])
            ->onlyMethods(['getApi'])
            ->getMock();

        $driverMock->method('getApi')->willReturn($apiMock);
        $driverMock->setAuthProvider($authMock);

        $response = $driverMock->syncEntities('campaign', new \DateTime('2025-01-01'), new \DateTime('2025-01-31'), $config);
        
        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        
        $this->assertEquals('success', $body['status']);
        $this->assertArrayHasKey('123456', $body['results']);
        $this->assertCount(1, $body['results']['123456']['campaigns']);
        $this->assertEquals('111', $body['results']['123456']['campaigns'][0]['campaign']['id']);
    }

    public function testSyncMetrics()
    {
        $apiMock = $this->createMock(GoogleAdsApi::class);
        $apiMock->expects($this->once())
            ->method('getMetricsForLevel')
            ->willReturn(['results' => [['campaign' => ['id' => '111'], 'metrics' => ['cost_micros' => '1000000', 'impressions' => '100', 'clicks' => '10', 'conversions' => '1'], 'segments' => ['date' => '2025-01-01', 'device' => 'DESKTOP']]]]);

        $config = [
            'ad_accounts' => [
                ['enabled' => true, 'id' => '123456']
            ],
            'AD_ACCOUNT' => [
                'campaign_metrics' => true,
            ],
            'cache_chunk_size' => '1 month',
        ];

        $authMock = $this->createMock(AuthProviderInterface::class);
        $authMock->method('hasCredentials')->willReturn(true);

        $driverMock = $this->getMockBuilder(GoogleAdsDriver::class)
            ->setConstructorArgs([null, $this->logger])
            ->onlyMethods(['getApi'])
            ->getMock();

        $driverMock->method('getApi')->willReturn($apiMock);
        $driverMock->setAuthProvider($authMock);

        $processedMetrics = 0;
        $driverMock->setDataProcessor(function ($collection) use (&$processedMetrics) {
            $processedMetrics = $collection->count();
            return ['metrics' => $processedMetrics, 'rows' => 1, 'duplicates' => 0];
        });

        $response = $driverMock->sync(new \DateTime('2025-01-01'), new \DateTime('2025-01-31'), $config);
        
        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        
        $this->assertEquals('success', $body['status']);
        $this->assertEquals(4, $body['data']['metrics']);
        $this->assertEquals(4, $processedMetrics);
    }

    public function testSyncEntitiesWithEntitiesType()
    {
        $apiMock = $this->createMock(GoogleAdsApi::class);
        $apiMock->expects($this->once())
            ->method('getCampaigns')
            ->with('123456')
            ->willReturn(['results' => [['campaign' => ['id' => '111']]]]);
        $apiMock->expects($this->once())
            ->method('getAdGroups')
            ->with('123456')
            ->willReturn(['results' => [['ad_group' => ['id' => '222']]]]);
        $apiMock->expects($this->once())
            ->method('getAds')
            ->with('123456')
            ->willReturn(['results' => [['ad_group_ad' => ['ad' => ['id' => '333']]]]] );

        $config = [
            'entity' => 'entities',
            'ad_accounts' => [
                ['enabled' => true, 'id' => '123456']
            ]
        ];

        $authMock = $this->createMock(AuthProviderInterface::class);
        $authMock->method('hasCredentials')->willReturn(true);

        $driverMock = $this->getMockBuilder(GoogleAdsDriver::class)
            ->setConstructorArgs([null, $this->logger])
            ->onlyMethods(['getApi'])
            ->getMock();

        $driverMock->method('getApi')->willReturn($apiMock);
        $driverMock->setAuthProvider($authMock);

        $response = $driverMock->sync(new \DateTime('2025-01-01'), new \DateTime('2025-01-31'), $config);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);

        $this->assertEquals('success', $body['status']);
        $this->assertArrayHasKey('123456', $body['results']);
        $this->assertCount(1, $body['results']['123456']['campaigns']);
        $this->assertCount(1, $body['results']['123456']['ad_groups']);
        $this->assertCount(1, $body['results']['123456']['ads']);
    }

    public function testSyncEntitiesWithEmptyAccountsReturnsCleanSuccess()
    {
        $apiMock = $this->createMock(GoogleAdsApi::class);
        $apiMock->expects($this->never())->method('getCampaigns');

        $config = [
            'ad_accounts' => []
        ];

        $authMock = $this->createMock(AuthProviderInterface::class);
        $authMock->method('hasCredentials')->willReturn(true);

        $driverMock = $this->getMockBuilder(GoogleAdsDriver::class)
            ->setConstructorArgs([null, $this->logger])
            ->onlyMethods(['getApi'])
            ->getMock();

        $driverMock->method('getApi')->willReturn($apiMock);
        $driverMock->setAuthProvider($authMock);

        $response = $driverMock->syncEntities('entities', new \DateTime('2025-01-01'), new \DateTime('2025-01-31'), $config);

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertEquals('success', $body['status']);
        $this->assertEquals([], $body['results']);
    }

    public function testGoogleAdsDriverMethods()
    {
        $this->assertEquals('Google Ads', GoogleAdsDriver::getProviderLabel());
        $this->assertEquals('google_ads', GoogleAdsDriver::getProviderName());
        $this->assertEquals('google_ads', GoogleAdsDriver::getCommonConfigKey());
        
        $mapping = GoogleAdsDriver::getEnvMapping();
        $this->assertArrayHasKey('google_ads', $mapping);
        $this->assertArrayHasKey('GOOGLE_ADS_DEVELOPER_TOKEN', $mapping['google_ads']);
        
        $this->assertEquals([], $this->driver->getDateFilterMapping());
    }
}
