<?php

declare(strict_types=1);

namespace Tests\Unit\Drivers;

use Anibalealvarezs\GoogleHubDriver\Drivers\SearchConsoleDriver;
use PHPUnit\Framework\TestCase;

final class SearchConsoleDriverCanonicalMetricDictionaryTest extends TestCase
{
    public function testExposesCanonicalMetricDictionary(): void
    {
        $dictionary = SearchConsoleDriver::getCanonicalMetricDictionary();

        $this->assertArrayHasKey('clicks', $dictionary);
        $this->assertArrayHasKey('impressions', $dictionary);
        $this->assertArrayHasKey('ctr', $dictionary);
        $this->assertContains('clicks', $dictionary['clicks']);
        $this->assertContains('impressions', $dictionary['impressions']);
    }
}

