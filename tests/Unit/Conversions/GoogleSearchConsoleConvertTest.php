<?php

namespace Tests\Unit\Conversions;

use Anibalealvarezs\GoogleHubDriver\Conversions\GoogleSearchConsoleConvert;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GoogleSearchConsoleConvertTest extends TestCase
{
    private $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testMetricsConversionWithEmptyRows()
    {
        $collection = GoogleSearchConsoleConvert::metrics([], 'https://example.com', null, $this->logger);
        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertEquals(0, $collection->count());
    }

    public function testMetricsConversionWithValidRows()
    {
        $rows = [
            [
                'date' => '2026-05-19',
                'clicks' => 120,
                'impressions' => 1500,
                'ctr' => 0.08,
                'position' => 2.4,
                'page' => 'https://example.com/blog/1',
                'query' => 'best php agent',
                'country' => 'usa',
                'device' => 'desktop',
                'searchAppearance' => 'standard',
                'synthetic' => false
            ],
            [
                'date' => '2026-05-19',
                'clicks' => 5,
                'impressions' => 100,
                'ctr' => 0.05,
                'position' => 12.3,
                'page' => 'https://example.com/blog/2',
                'query' => 'agent developer tool',
                'country' => 'esp',
                'device' => 'mobile',
                'searchAppearance' => 'standard',
                'synthetic' => true
            ]
        ];

        $collection = GoogleSearchConsoleConvert::metrics(
            rows: $rows,
            siteUrl: 'https://example.com',
            siteKey: 'example_site',
            logger: $this->logger,
            page: 'https://example.com/blog/1',
            period: 'daily',
            channeledAccount: 'gsc_channeled_acct_id',
            account: 'gsc_acct_id'
        );

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        
        // UniversalMetricConverter::convert standardizes input rows into a collection of normalized items
        // Let's verify the converted collection structure
        $this->assertGreaterThan(0, $collection->count());
        
        $firstItem = $collection->first();
        $this->assertNotNull($firstItem);
    }

    public function testMetricsConversionWithObjectParams()
    {
        $rows = [
            [
                'date' => '2026-05-19',
                'clicks' => 10,
                'impressions' => 100,
                'ctr' => 0.1,
                'position' => 1.0,
                'page' => 'https://example.com/shop',
                'query' => 'buy PHP tools',
                'country' => 'can',
                'device' => 'desktop',
                'searchAppearance' => 'standard',
                'synthetic' => false
            ]
        ];

        // Create mock or stub objects to simulate Doctrine Entities
        $mockPage = new class {
            public function getUrl(): string { return 'https://example.com/shop'; }
            public function __toString(): string { return 'https://example.com/shop'; }
        };

        $mockChanneledAccount = new class {
            public function getPlatformId(): string { return 'mock_ca_platform_id'; }
            public function getId(): int { return 888; }
            public function __toString(): string { return 'mock_ca_platform_id'; }
        };

        $mockAccount = new class {
            public function getId(): int { return 999; }
            public function __toString(): string { return '999'; }
        };

        $collection = GoogleSearchConsoleConvert::metrics(
            rows: $rows,
            siteUrl: 'https://example.com',
            siteKey: 'example_site',
            logger: $this->logger,
            page: $mockPage,
            period: 'daily',
            channeledAccount: $mockChanneledAccount,
            account: $mockAccount
        );

        $this->assertInstanceOf(ArrayCollection::class, $collection);
        $this->assertGreaterThan(0, $collection->count());
    }
}
