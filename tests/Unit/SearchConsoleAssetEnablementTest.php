<?php

declare(strict_types=1);

use Anibalealvarezs\GoogleHubDriver\Drivers\SearchConsoleDriver;
use PHPUnit\Framework\TestCase;

final class SearchConsoleAssetEnablementTest extends TestCase
{
    public function test_unverified_property_keeps_the_users_enabled_choice(): void
    {
        $asset = ['url' => 'https://example.com/', 'enabled' => true, 'data' => ['permissionLevel' => 'siteUnverifiedUser']];
        self::assertTrue(SearchConsoleDriver::getPages($asset)[0]['enabled']);
        self::assertTrue(SearchConsoleDriver::getChanneledAccounts($asset)[0]['enabled']);
        $asset['enabled'] = false;
        self::assertFalse(SearchConsoleDriver::getPages($asset)[0]['enabled']);
        self::assertFalse(SearchConsoleDriver::getChanneledAccounts($asset)[0]['enabled']);
    }
}
