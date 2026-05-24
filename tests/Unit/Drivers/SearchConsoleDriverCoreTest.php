<?php

namespace Tests\Unit\Drivers;

use Anibalealvarezs\GoogleHubDriver\Drivers\SearchConsoleDriver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Anibalealvarezs\ApiDriverCore\Interfaces\AuthProviderInterface;

class SearchConsoleDriverCoreTest extends TestCase
{
    private SearchConsoleDriver $driver;
    private $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->driver = new SearchConsoleDriver(null, $this->logger);
    }

    public function testGetChannel()
    {
        $this->assertEquals('google_search_console', $this->driver->getChannel());
    }

    public function testGetChannelLabel()
    {
        $this->assertEquals('Google Search Console', SearchConsoleDriver::getChannelLabel());
    }

    public function testGetChannelIcon()
    {
        $this->assertEquals('G', SearchConsoleDriver::getChannelIcon());
    }

    public function testGetPlatformEntityIdField()
    {
        $this->assertEquals('platform_id', SearchConsoleDriver::getPlatformEntityIdField());
    }

    public function testGetPublicResources()
    {
        $resources = SearchConsoleDriver::getPublicResources();
        $this->assertArrayHasKey('metrics', $resources);
        $this->assertEquals('gsc_metrics', $resources['metrics']);
    }

    public function testGetMetricProfiles()
    {
        $profiles = SearchConsoleDriver::getMetricProfiles();
        $this->assertCount(4, $profiles);
        $this->assertEquals('gsc_site_totals', $profiles[0]['key']);
        $this->assertEquals('gsc_site_query_breakdown', $profiles[1]['key']);
    }

    public function testGetRoutes()
    {
        $routes = SearchConsoleDriver::getRoutes();
        $this->assertArrayHasKey('/google-login', $routes);
        $this->assertArrayHasKey('/gsc-reports', $routes);
    }

    public function testGetRateLimitWhitelist()
    {
        $whitelist = SearchConsoleDriver::getRateLimitWhitelist();
        $this->assertContains('/gsc-reports', $whitelist);
    }

    public function testDeriveTitleFromUrl()
    {
        $this->assertEquals('marialcazares.com', $this->driver->deriveTitleFromUrl('https://marialcazares.com/'));
        $this->assertEquals('example.com', $this->driver->deriveTitleFromUrl('sc-domain:example.com'));
    }

    public function testDeriveHostnameFromUrl()
    {
        $this->assertEquals('marialcazares.com', $this->driver->deriveHostnameFromUrl('https://marialcazares.com/path'));
        $this->assertEquals('example.com', $this->driver->deriveHostnameFromUrl('sc-domain:example.com'));
    }

    public function testValidateAuthenticationWithoutCredentials()
    {
        $res = $this->driver->validateAuthentication();
        $this->assertFalse($res['success']);
        $this->assertStringContainsString('Credentials not configured', $res['message']);
    }

    public function testValidateAuthenticationWithCredentialsSuccess()
    {
        $authMock = $this->createMock(AuthProviderInterface::class);
        $authMock->method('hasCredentials')->willReturn(true);

        $apiMock = $this->createMock(\Anibalealvarezs\GoogleApi\Services\SearchConsole\SearchConsoleApi::class);
        $apiMock->expects($this->once())->method('getSites')->willReturn([]);

        $driverMock = $this->getMockBuilder(SearchConsoleDriver::class)
            ->setConstructorArgs([null, $this->logger])
            ->onlyMethods(['initializeApi'])
            ->getMock();

        $driverMock->method('initializeApi')->willReturn($apiMock);
        $driverMock->setAuthProvider($authMock);

        $res = $driverMock->validateAuthentication();
        $this->assertTrue($res['success']);
        $this->assertEquals('Authentication is valid.', $res['message']);
    }

    public function testValidateAuthenticationWithCredentialsException()
    {
        $authMock = $this->createMock(AuthProviderInterface::class);
        $authMock->method('hasCredentials')->willReturn(true);

        $driverMock = $this->getMockBuilder(SearchConsoleDriver::class)
            ->setConstructorArgs([null, $this->logger])
            ->onlyMethods(['initializeApi'])
            ->getMock();

        $driverMock->method('initializeApi')->willThrowException(new \Exception("Google GSC API connection timeout"));
        $driverMock->setAuthProvider($authMock);

        $res = $driverMock->validateAuthentication();
        $this->assertFalse($res['success']);
        $this->assertEquals('Google GSC API connection timeout', $res['message']);
    }

    public function testUpdateConfiguration()
    {
        $current = [
            'channels' => [
                'google_search_console' => [
                    'enabled' => false,
                    'sites' => [
                        [
                            'url' => 'sc-domain:example.com',
                            'enabled' => true
                        ]
                    ]
                ]
            ]
        ];

        $newData = [
            'enabled' => true,
            'cache_history_range' => '90 days',
            'granular_sync' => 'true',
            'max_workers' => 4,
            'feature_toggles' => [
                'calculate_synthetics' => 'false',
                'cache_aggregations' => 'true'
            ],
            'assets' => [
                'gsc' => [
                    [
                        'url' => 'sc-domain:example.com',
                        'enabled' => 'false',
                        'target_countries' => ['US'],
                        'target_keywords' => ['test']
                    ]
                ]
            ]
        ];

        $result = $this->driver->updateConfiguration($newData, $current);

        $chanCfg = $result['channels']['google_search_console'];
        $this->assertTrue($chanCfg['enabled']);
        $this->assertEquals('90 days', $chanCfg['cache_history_range']);
        $this->assertTrue($chanCfg['granular_sync']);
        $this->assertEquals(4, $chanCfg['max_workers']);
        $this->assertFalse($chanCfg['calculate_synthetics']);
        $this->assertTrue($chanCfg['cache_aggregations']);

        $this->assertCount(1, $chanCfg['sites']);
        $this->assertFalse($chanCfg['sites'][0]['enabled']);
        $this->assertEquals(['US'], $chanCfg['sites'][0]['target_countries']);
    }

    public function testGoogleSyncDriverTraitMethods()
    {
        $this->assertEquals('Google', SearchConsoleDriver::getProviderLabel());
        $this->assertEquals('google', SearchConsoleDriver::getProviderName());
        $this->assertEquals('google', SearchConsoleDriver::getCommonConfigKey());
        
        $mapping = SearchConsoleDriver::getEnvMapping();
        $this->assertArrayHasKey('google', $mapping);
        
        $this->assertEquals([], $this->driver->getDateFilterMapping());
    }

    public function testResetThrowsExceptionWithoutCallback()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Reset callback not provided for google_search_console');
        
        $this->driver->reset();
    }

    public function testInitializeApiPassesTokenRefresherCallback()
    {
        $authMock = $this->createMock(AuthProviderInterface::class);
        $callback = function () { return 'new_token'; };
        $authMock->method('getTokenRefresherCallback')->willReturn($callback);
        $authMock->method('getAccessToken')->willReturn('fake_token');
        $authMock->method('getScopes')->willReturn(['fake_scope']);
        $authMock->method('hasCredentials')->willReturn(true);

        $driver = new SearchConsoleDriver($authMock, $this->logger);

        // Access the protected initializeApi method via Reflection
        $reflection = new \ReflectionClass(SearchConsoleDriver::class);
        $method = $reflection->getMethod('initializeApi');
        $method->setAccessible(true);

        // Call initializeApi
        $api = $method->invoke($driver, ['client_id' => '123', 'client_secret' => '456', 'refresh_token' => '789']);

        // Check if the API was returned
        $this->assertInstanceOf(\Anibalealvarezs\GoogleApi\Services\SearchConsole\SearchConsoleApi::class, $api);
        
        // Use reflection to check the callback property on the API client
        $apiReflection = new \ReflectionClass(\Anibalealvarezs\ApiSkeleton\Client::class);
        $callbackProperty = $apiReflection->getProperty('tokenRefresherCallback');
        $callbackProperty->setAccessible(true);
        $this->assertSame($callback, $callbackProperty->getValue($api));
    }

    public function testInitializeApiWithNullTokenRefresherCallback()
    {
        $authMock = $this->createMock(AuthProviderInterface::class);
        $authMock->method('getTokenRefresherCallback')->willReturn(null);
        $authMock->method('getAccessToken')->willReturn('fake_token');
        $authMock->method('getScopes')->willReturn(['fake_scope']);
        $authMock->method('hasCredentials')->willReturn(true);

        $driver = new SearchConsoleDriver($authMock, $this->logger);

        $reflection = new \ReflectionClass(SearchConsoleDriver::class);
        $method = $reflection->getMethod('initializeApi');
        $method->setAccessible(true);

        $api = $method->invoke($driver, ['client_id' => '123', 'client_secret' => '456', 'refresh_token' => '789']);

        $this->assertInstanceOf(\Anibalealvarezs\GoogleApi\Services\SearchConsole\SearchConsoleApi::class, $api);
        
        $apiReflection = new \ReflectionClass(\Anibalealvarezs\ApiSkeleton\Client::class);
        $callbackProperty = $apiReflection->getProperty('tokenRefresherCallback');
        $callbackProperty->setAccessible(true);
        $this->assertNull($callbackProperty->getValue($api));
    }
}
