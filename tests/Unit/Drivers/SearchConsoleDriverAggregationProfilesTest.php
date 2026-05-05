<?php

declare(strict_types=1);

namespace Tests\Unit\Drivers;

use Anibalealvarezs\GoogleHubDriver\Drivers\SearchConsoleDriver;
use PHPUnit\Framework\TestCase;

final class SearchConsoleDriverAggregationProfilesTest extends TestCase
{
    public function testExposesAggregationProfilesForSearchConsole(): void
    {
        $profiles = SearchConsoleDriver::getAggregationProfiles();

        $this->assertNotEmpty($profiles);
        $this->assertSame('gsc_search_cube', $profiles[0]['key']);
        $this->assertSame('google_search_console', $profiles[0]['channel']);
        $this->assertArrayHasKey('group_patterns', $profiles[0]);
        $this->assertArrayHasKey('filter_contract', $profiles[0]);
        $this->assertArrayHasKey('reducer_strategies', $profiles[0]);
    }
}

