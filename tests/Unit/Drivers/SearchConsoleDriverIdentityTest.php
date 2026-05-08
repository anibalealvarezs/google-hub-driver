<?php

namespace Tests\Unit\Drivers;

use Anibalealvarezs\GoogleHubDriver\Drivers\SearchConsoleDriver;
use Anibalealvarezs\ApiDriverCore\Enums\AssetCategory;
use PHPUnit\Framework\TestCase;

class SearchConsoleDriverIdentityTest extends TestCase
{
    public function testPlatformIdMatching()
    {
        $siteUrl = 'sc-domain:marialcazares.com';
        
        // 1. How the scheduler calculates it
        $scheduledId = SearchConsoleDriver::getPlatformId(['url' => $siteUrl], AssetCategory::IDENTITY, 'gsc');
        
        // 2. How the driver calculates it during sync
        $currentId = SearchConsoleDriver::getPlatformId(['url' => $siteUrl], AssetCategory::IDENTITY, 'gsc');
        
        $this->assertEquals($scheduledId, $currentId, "IDs must match between scheduler and driver");
        $this->assertEquals(md5($siteUrl), $scheduledId, "ID should be the MD5 of the URL");
    }

    public function testSyncFiltering()
    {
        $sites = [
            'sc-domain:site1.com',
            'sc-domain:site2.com',
            'sc-domain:marialcazares.com'
        ];
        
        $targetSite = 'sc-domain:site2.com';
        $targetAccountId = SearchConsoleDriver::getPlatformId(['url' => $targetSite], AssetCategory::IDENTITY, 'gsc');
        
        $processedSites = [];
        foreach ($sites as $siteUrl) {
            $currentPlatformId = SearchConsoleDriver::getPlatformId(['url' => $siteUrl], AssetCategory::IDENTITY, 'gsc');
            if ($targetAccountId && $targetAccountId !== $currentPlatformId) {
                continue;
            }
            $processedSites[] = $siteUrl;
        }
        
        $this->assertCount(1, $processedSites, "Should only process one site");
        $this->assertEquals($targetSite, $processedSites[0], "Should process the targeted site");
    }

    public function testSyncFilteringWithPrefix()
    {
        $siteUrl = 'sc-domain:marialcazares.com';
        $realId = SearchConsoleDriver::getPlatformId(['url' => $siteUrl], AssetCategory::IDENTITY, 'gsc');
        
        // Simulate what happens if the ID in the job has a '#' prefix
        $targetAccountId = '#' . $realId;
        
        $currentPlatformId = SearchConsoleDriver::getPlatformId(['url' => $siteUrl], AssetCategory::IDENTITY, 'gsc');
        
        $this->assertNotEquals($targetAccountId, $currentPlatformId, "Prefix '#' causes mismatch");
    }
}
