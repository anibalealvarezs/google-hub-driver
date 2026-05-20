<?php

namespace Tests\Unit\Drivers;

use Anibalealvarezs\GoogleHubDriver\Drivers\GoogleAnalyticsDriver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Anibalealvarezs\ApiDriverCore\Interfaces\AuthProviderInterface;

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
        $this->assertEquals('google_analytics', $this->driver->getChannel());
    }

    public function testGetChannelLabel()
    {
        $this->assertEquals('GoogleAnalytics', GoogleAnalyticsDriver::getChannelLabel());
    }

    public function testGetChannelIcon()
    {
        $this->assertEquals('A', GoogleAnalyticsDriver::getChannelIcon());
    }

    public function testGetPublicResources()
    {
        $resources = GoogleAnalyticsDriver::getPublicResources();
        $this->assertArrayHasKey('metrics', $resources);
        $this->assertEquals('gsc_metrics', $resources['metrics']);
    }

    public function testGetRoutes()
    {
        $routes = GoogleAnalyticsDriver::getRoutes();
        $this->assertArrayHasKey('/google-login', $routes);
        $this->assertArrayHasKey('/google-auth-start', $routes);
        $this->assertArrayHasKey('/google-callback', $routes);
    }

    public function testGetRateLimitWhitelist()
    {
        $whitelist = GoogleAnalyticsDriver::getRateLimitWhitelist();
        $this->assertContains('/ga-reports', $whitelist);
    }

    public function testFetchAvailableAssets()
    {
        $assets = $this->driver->fetchAvailableAssets();
        $this->assertEquals([], $assets);
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
        $this->assertStringContainsString('Status unknown for this driver', $res['message']);
    }

    public function testSync()
    {
        $startDate = new \DateTime('2026-01-01');
        $endDate = new \DateTime('2026-01-07');
        
        $response = $this->driver->sync($startDate, $endDate);
        
        $this->assertEquals(200, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('skipped', $content['status']);
        $this->assertStringContainsString('Google Analytics driver (Modular) placeholder', $content['message']);
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
        $this->assertArrayHasKey('google_business', $patterns);
        $this->assertEquals('locations', $patterns['google_business']['key']);
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
        $this->assertArrayHasKey('google_business', $types);
        $this->assertEquals('Google Business Profile', $types['google_business']);
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
        $this->assertEquals('', GoogleAnalyticsDriver::getPlatformEntityIdField());
    }

    public function testGoogleSyncDriverTraitMethods()
    {
        $this->assertEquals('Google', GoogleAnalyticsDriver::getProviderLabel());
        $this->assertEquals('google', GoogleAnalyticsDriver::getProviderName());
        $this->assertEquals('google', GoogleAnalyticsDriver::getCommonConfigKey());
        
        $mapping = GoogleAnalyticsDriver::getEnvMapping();
        $this->assertArrayHasKey('google', $mapping);
        $this->assertEquals('client_id', $mapping['google']['GOOGLE_CLIENT_ID']);
        
        $this->assertEquals([], $this->driver->getDateFilterMapping());
    }

    public function testResetThrowsExceptionWithoutCallback()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Reset callback not provided for google_analytics');
        
        $this->driver->reset();
    }

    public function testResetInvokesCallback()
    {
        $callbackCalled = false;
        $config = [
            'resetCallback' => function($channel, $mode) use (&$callbackCalled) {
                $callbackCalled = true;
                return ['channel' => $channel, 'mode' => $mode, 'success' => true];
            }
        ];

        $res = $this->driver->reset('custom_mode', $config);
        $this->assertTrue($callbackCalled);
        $this->assertEquals('google_analytics', $res['channel']);
        $this->assertEquals('custom_mode', $res['mode']);
        $this->assertTrue($res['success']);
    }
}
