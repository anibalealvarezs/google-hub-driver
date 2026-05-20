<?php

namespace Tests\Unit\Controllers;

use Anibalealvarezs\GoogleHubDriver\Controllers\ReportController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class ReportControllerTest extends TestCase
{
    private string $originalAppEnv;
    private string $originalProjectName;

    protected function setUp(): void
    {
        $this->originalAppEnv = $_ENV['APP_ENV'] ?? 'production';
        $this->originalProjectName = $_ENV['PROJECT_NAME'] ?? 'APIs Hub';
    }

    protected function tearDown(): void
    {
        $_ENV['APP_ENV'] = $this->originalAppEnv;
        $_ENV['PROJECT_NAME'] = $this->originalProjectName;
    }

    public function testIndexRendering()
    {
        $_ENV['PROJECT_NAME'] = 'APIs Hub';
        $_ENV['APP_ENV'] = 'production';

        $controller = new ReportController();
        $response = $controller->index([
            'channelsConfig' => [
                'google_search_console' => [
                    'metrics_strategy' => 'custom_gsc',
                    'AD_ACCOUNT' => [
                        'campaign_metrics' => true
                    ]
                ]
            ]
        ]);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        
        $content = $response->getContent();
        $this->assertStringContainsString('Google Search Console Insights', $content);
        $this->assertStringContainsString('window.FB_METRICS_CONFIG =', $content);
        $this->assertStringContainsString('"strategy":"custom_gsc"', $content);
        $this->assertStringContainsString('"metrics_level":"campaign"', $content);
    }

    public function testDemoModeBypass()
    {
        $_ENV['PROJECT_NAME'] = 'APIs Hub';
        $_ENV['APP_ENV'] = 'demo'; // Triggers isDemo and injects AUTH_BYPASS

        $controller = new ReportController();
        $response = $controller->index([
            'isDemo' => true,
            'channelsConfig' => [
                'google_search_console' => [
                    'PAGES' => [
                        'post_metrics' => true
                    ]
                ]
            ]
        ]);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        
        $content = $response->getContent();
        $this->assertStringContainsString('Google Search Console Insights', $content);
        $this->assertStringContainsString('window.AUTH_BYPASS = true', $content);
        $this->assertStringContainsString('"metrics_level":"post"', $content);
    }

    public function testDeriveMetricsLevelVariations()
    {
        $controller = new ReportController();
        $ref = new \ReflectionMethod($controller, 'deriveMetricsLevel');
        $ref->setAccessible(true);

        // 1. Organic Page Level
        $organicLevel = $ref->invoke($controller, ['PAGES' => ['post_metrics' => true]]);
        $this->assertEquals('post', $organicLevel);

        // 2. Creative Level
        $creativeLevel = $ref->invoke($controller, ['AD_ACCOUNT' => ['creative_metrics' => true]]);
        $this->assertEquals('creative', $creativeLevel);

        // 3. Ad Level
        $adLevel = $ref->invoke($controller, ['AD_ACCOUNT' => ['ad_metrics' => true]]);
        $this->assertEquals('ad', $adLevel);

        // 4. Adset Level
        $adsetLevel = $ref->invoke($controller, ['AD_ACCOUNT' => ['adset_metrics' => true]]);
        $this->assertEquals('adset', $adsetLevel);

        // 5. Campaign Level
        $campaignLevel = $ref->invoke($controller, ['AD_ACCOUNT' => ['campaign_metrics' => true]]);
        $this->assertEquals('campaign', $campaignLevel);

        // 6. Default fallback
        $defaultLevel = $ref->invoke($controller, []);
        $this->assertEquals('ad_account', $defaultLevel);
    }
}
