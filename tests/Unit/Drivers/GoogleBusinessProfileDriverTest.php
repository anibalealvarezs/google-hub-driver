<?php

namespace Tests\Unit\Drivers;

use Anibalealvarezs\GoogleHubDriver\Drivers\GoogleBusinessProfileDriver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Anibalealvarezs\ApiDriverCore\Interfaces\AuthProviderInterface;
use DateTime;

class GoogleBusinessProfileDriverTest extends TestCase
{
    private GoogleBusinessProfileDriver $driver;
    private $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->driver = new GoogleBusinessProfileDriver(null, $this->logger);
    }

    public function testGetChannel()
    {
        $this->assertEquals('google_business_profile', $this->driver->getChannel());
    }

    public function testGetChannelLabel()
    {
        $this->assertEquals('Google Business Profile', GoogleBusinessProfileDriver::getChannelLabel());
    }

    public function testGetChannelIcon()
    {
        $this->assertEquals('G', GoogleBusinessProfileDriver::getChannelIcon());
    }

    public function testGetPublicResources()
    {
        $resources = GoogleBusinessProfileDriver::getPublicResources();
        $this->assertArrayHasKey('metrics', $resources);
        $this->assertEquals('gbp_metrics', $resources['metrics']);
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

        $driver = new GoogleBusinessProfileDriver($authMock, $this->logger);
        $res = $driver->validateAuthentication();

        $this->assertTrue($res['success']);
        $this->assertStringContainsString('Connected successfully', $res['message']);
    }

    public function testGetPlatformEntityIdField()
    {
        $this->assertEquals('location_id', GoogleBusinessProfileDriver::getPlatformEntityIdField());
    }

    public function testGetCanonicalMetricDictionary()
    {
        $dict = GoogleBusinessProfileDriver::getCanonicalMetricDictionary();
        $this->assertArrayHasKey('impressions', $dict);
        $this->assertArrayHasKey('website_clicks', $dict);
        $this->assertContains('WEBSITE_CLICKS', $dict['website_clicks']);
    }

    public function testChanneledAccountableInterfaceMethods()
    {
        $asset = [
            'platformId' => '12345',
            'createTime' => '2026-05-26T12:00:00Z',
            'name' => 'Test Location',
            'data' => ['foo' => 'bar']
        ];

        $this->assertEquals('12345', GoogleBusinessProfileDriver::getChanneledAccountPlatformId($asset));
        $this->assertEquals('2026-05-26T12:00:00Z', GoogleBusinessProfileDriver::getChanneledAccountPlatformCreatedAt($asset));
        $this->assertEquals('Test Location', GoogleBusinessProfileDriver::getChanneledAccountName($asset));
        $this->assertEquals('google_business', GoogleBusinessProfileDriver::getChanneledAccountType());
        $this->assertEquals(['foo' => 'bar'], GoogleBusinessProfileDriver::getChanneledAccountData($asset));
    }
}
