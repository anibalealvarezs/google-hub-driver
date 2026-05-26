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
            ->method('getMetrics')
            ->with('123456', 'campaign', '2025-01-01', '2025-01-31')
            ->willReturn(['results' => [['campaign' => ['id' => '111'], 'metrics' => ['impressions' => '100']]]]);

        $config = [
            'ad_accounts' => [
                ['enabled' => true, 'id' => '123456']
            ],
            'level' => 'campaign'
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
        $this->assertCount(1, $body['metrics']);
        $this->assertEquals('100', $body['metrics'][0]['metrics']['impressions']);
    }

    public function testGoogleAdsSyncDriverTraitMethods()
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
