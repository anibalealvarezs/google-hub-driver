<?php

namespace Tests\Unit\Drivers;

use Anibalealvarezs\GoogleHubDriver\Drivers\GoogleAnalyticsDriver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Anibalealvarezs\ApiDriverCore\Interfaces\AuthProviderInterface;
use Anibalealvarezs\GoogleHubDriver\Enums\GoogleChannel;

class GoogleAnalyticsDriverTest extends TestCase
{
    private GoogleAnalyticsDriver $driver;
    private $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->driver = new GoogleAnalyticsDriver(null, $this->logger);
    }

    public function testGetChannel()
    {
        $this->assertEquals(GoogleChannel::ANALYTICS->value, $this->driver->getChannel());
    }

    public function testGetChannelLabel()
    {
        $this->assertEquals('Google Analytics', GoogleAnalyticsDriver::getChannelLabel());
    }

    public function testGetChannelIcon()
    {
        $this->assertEquals('A', GoogleAnalyticsDriver::getChannelIcon());
    }

    public function testGetPublicResources()
    {
        $resources = GoogleAnalyticsDriver::getPublicResources();
        $this->assertArrayHasKey('metrics', $resources);
        $this->assertEquals('ga_metrics', $resources['metrics']);
    }

    public function testValidateAuthenticationWithoutCredentials()
    {
        $res = $this->driver->validateAuthentication();
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('Credentials not configured', $res['message']);
    }

    public function testValidateAuthenticationWithCredentials()
    {
        $authMock = $this->createMock(AuthProviderInterface::class);
        $authMock->method('hasCredentials')->willReturn(true);
        
        $driver = new GoogleAnalyticsDriver($authMock, $this->logger);
        $res = $driver->validateAuthentication();
        
        $this->assertTrue($res['success']);
        $this->assertStringContainsString('Connected successfully', $res['message']);
    }

    public function testGetConfigSchema()
    {
        $schema = $this->driver->getConfigSchema();
        $this->assertArrayHasKey('global', $schema);
        $this->assertArrayHasKey('entity', $schema);
        $this->assertFalse($schema['global']['enabled']);
    }

    public function testUpdateConfiguration()
    {
        $current = ['granular_sync' => false];
        $updated = $this->driver->updateConfiguration(['granular_sync' => 'true'], $current);
        
        $this->assertTrue($updated['channels']['google_analytics']['granular_sync']);
    }

    public function testPrepareUiConfig()
    {
        $config = ['granular_sync' => true];
        $ui = $this->driver->prepareUiConfig($config);
        
        $this->assertTrue($ui['ga_granular_sync']);
    }

    public function testGetAssetPatterns()
    {
        $patterns = GoogleAnalyticsDriver::getAssetPatterns();
        $this->assertArrayHasKey(\Anibalealvarezs\GoogleHubDriver\Enums\GoogleEntityType::LOCATION->value, $patterns);
        $this->assertEquals('properties', $patterns[\Anibalealvarezs\GoogleHubDriver\Enums\GoogleEntityType::LOCATION->value]['key']);
    }

    public function testGetCanonicalMetricDictionary()
    {
        $dict = GoogleAnalyticsDriver::getCanonicalMetricDictionary();
        $this->assertArrayHasKey('conversions', $dict);
        $this->assertArrayHasKey('reach', $dict);
        $this->assertContains('screenPageViews', $dict['impressions']);
    }

    public function testGetPageTypes()
    {
        $types = GoogleAnalyticsDriver::getPageTypes();
        $this->assertArrayHasKey('property', $types);
        $this->assertEquals('Google Analytics 4 Property', $types['property']);
    }

    public function testInitializeEntities()
    {
        $res = $this->driver->initializeEntities();
        $this->assertEquals(['initialized' => 0, 'skipped' => 0], $res);
    }

    public function testGetInstanceRules()
    {
        $rules = GoogleAnalyticsDriver::getInstanceRules();
        $this->assertEquals(6, $rules['history_months']);
        $this->assertFalse($rules['entities_sync']);
    }

    public function testGetPlatformEntityIdField()
    {
        $this->assertEquals('property_id', GoogleAnalyticsDriver::getPlatformEntityIdField());
    }

    public function testChanneledAccountableInterfaceMethods()
    {
        $asset = [
            'platformId' => '12345',
            'createTime' => '2023-01-01T00:00:00Z',
            'name' => 'Test GA4 Property',
            'data' => ['key' => 'value']
        ];

        $this->assertEquals('12345', GoogleAnalyticsDriver::getChanneledAccountPlatformId($asset));
        $this->assertEquals('2023-01-01T00:00:00Z', GoogleAnalyticsDriver::getChanneledAccountPlatformCreatedAt($asset));
        $this->assertEquals('Test GA4 Property', GoogleAnalyticsDriver::getChanneledAccountName($asset));
        $this->assertEquals('google_analytics', GoogleAnalyticsDriver::getChanneledAccountType());
        $this->assertEquals(['key' => 'value'], GoogleAnalyticsDriver::getChanneledAccountData($asset));
    }

    public function testSupportsLiveWindow()
    {
        $this->assertTrue(GoogleAnalyticsDriver::supportsLiveWindow('activeUsers'));
        $this->assertTrue(GoogleAnalyticsDriver::supportsLiveWindow('active_users'));
        $this->assertTrue(GoogleAnalyticsDriver::supportsLiveWindow('reach'));
        $this->assertTrue(GoogleAnalyticsDriver::supportsLiveWindow('newUsers'));
        $this->assertTrue(GoogleAnalyticsDriver::supportsLiveWindow('new_users'));
        $this->assertTrue(GoogleAnalyticsDriver::supportsLiveWindow('totalUsers'));
        $this->assertTrue(GoogleAnalyticsDriver::supportsLiveWindow('total_users'));

        $this->assertFalse(GoogleAnalyticsDriver::supportsLiveWindow('sessions'));
        $this->assertFalse(GoogleAnalyticsDriver::supportsLiveWindow('screenPageViews'));
        $this->assertFalse(GoogleAnalyticsDriver::supportsLiveWindow('conversions'));
    }

    public function testFetchLiveWindowMapsDimensionsAndMetricsProperly()
    {
        $mockApi = $this->createMock(\Anibalealvarezs\GoogleApi\Services\AnalyticsData\AnalyticsDataApi::class);
        $mockApi->expects($this->once())
            ->method('runReport')
            ->with(
                'properties/123456',
                $this->callback(function ($payload) {
                    return isset($payload['metrics'])
                        && $payload['metrics'][0]['name'] === 'activeUsers'
                        && isset($payload['dimensions'])
                        && $payload['dimensions'][0]['name'] === 'firstUserDefaultChannelGroup'
                        && $payload['dateRanges'][0]['startDate'] === '2026-01-01'
                        && $payload['dateRanges'][0]['endDate'] === '2026-01-31';
                })
            )
            ->willReturn([
                'metricHeaders' => [['name' => 'activeUsers', 'type' => 'TYPE_INTEGER']],
                'dimensionHeaders' => [['name' => 'firstUserDefaultChannelGroup']],
                'rows' => [
                    [
                        'dimensionValues' => [['value' => 'Organic Search']],
                        'metricValues' => [['value' => '1520']],
                    ],
                    [
                        'dimensionValues' => [['value' => 'Direct']],
                        'metricValues' => [['value' => '850']],
                    ],
                ],
            ]);

        $reflection = new \ReflectionClass($this->driver);
        $method = $reflection->getMethod('initializeApi');
        $method->setAccessible(true);

        $driver = $this->getMockBuilder(GoogleAnalyticsDriver::class)
            ->onlyMethods(['initializeApi'])
            ->getMock();

        $driver->method('initializeApi')->willReturn($mockApi);

        $rows = $driver->fetchLiveWindow(
            config: [],
            platformId: 'properties/123456',
            metrics: ['reach'],
            startDate: '2026-01-01',
            endDate: '2026-01-31',
            dimensions: ['dimensions.firstUserDefaultChannelGroup']
        );

        $this->assertCount(2, $rows);
        $this->assertEquals(1520.0, $rows[0]['activeUsers']);
        $this->assertEquals(1520.0, $rows[0]['reach']);
        $this->assertEquals('Organic Search', $rows[0]['firstUserDefaultChannelGroup']);
        $this->assertEquals('Organic Search', $rows[0]['dimensions.firstUserDefaultChannelGroup']);
    }
}
