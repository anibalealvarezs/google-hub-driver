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
        
        $this->assertTrue($updated['granular_sync']);
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
        $this->assertArrayHasKey('google_analytics', $patterns);
        $this->assertEquals('properties', $patterns['google_analytics']['key']);
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
}
