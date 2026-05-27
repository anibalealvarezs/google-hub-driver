<?php

namespace Anibalealvarezs\GoogleHubDriver\Drivers;

use Anibalealvarezs\ApiDriverCore\Enums\AssetCategory;
use Anibalealvarezs\ApiDriverCore\Enums\InstanceTier;
use Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface;
use Anibalealvarezs\ApiDriverCore\Traits\SyncDriverTrait;
use Anibalealvarezs\GoogleHubDriver\Traits\GoogleSyncDriverTrait;
use DateTime;
use Symfony\Component\HttpFoundation\Response;
use Anibalealvarezs\ApiDriverCore\Interfaces\CanonicalMetricDictionaryProviderInterface;
use Anibalealvarezs\ApiDriverCore\Interfaces\ChanneledAccountableInterface;
use Anibalealvarezs\GoogleApi\Services\BusinessInformation\BusinessInformationApi;
use Anibalealvarezs\GoogleApi\Services\BusinessPerformance\BusinessPerformanceApi;
use Anibalealvarezs\GoogleHubDriver\Conversions\GoogleBusinessProfileMetricConvert;
use Exception;

class GoogleBusinessProfileDriver implements SyncDriverInterface, CanonicalMetricDictionaryProviderInterface, ChanneledAccountableInterface
{
    use SyncDriverTrait, GoogleSyncDriverTrait {
        GoogleSyncDriverTrait::storeCredentials insteadof SyncDriverTrait;
        GoogleSyncDriverTrait::getApi insteadof SyncDriverTrait;
        GoogleSyncDriverTrait::boot insteadof SyncDriverTrait;
        GoogleSyncDriverTrait::getCommonConfigKey insteadof SyncDriverTrait;
        GoogleSyncDriverTrait::getDateFilterMapping insteadof SyncDriverTrait;
        GoogleSyncDriverTrait::getProviderLabel insteadof SyncDriverTrait;
        GoogleSyncDriverTrait::getProviderName insteadof SyncDriverTrait;
        GoogleSyncDriverTrait::initializeApi insteadof SyncDriverTrait;
        GoogleSyncDriverTrait::reset insteadof SyncDriverTrait;
        GoogleSyncDriverTrait::getEnvMapping insteadof SyncDriverTrait;
        GoogleSyncDriverTrait::validateConfig insteadof SyncDriverTrait;
    }

    public static function getPublicResources(): array
    {
        return ['metrics' => 'gbp_metrics'];
    }

    public static function getChannelLabel(): string
    {
        return 'Google Business Profile';
    }

    public static function getChannelIcon(): string
    {
        return 'G';
    }

    public static function getRoutes(): array
    {
        return [];
    }

    public static function getRateLimitWhitelist(): array
    {
        return [];
    }

    public function fetchAvailableAssets(bool $throwOnError = false): array
    {
        try {
            $credentials = $this->getUpdatableCredentials();
            $infoApi = new BusinessInformationApi(
                redirectUrl: $this->authProvider->getRedirectUrl(),
                clientId: $this->authProvider->getClientId(),
                clientSecret: $this->authProvider->getClientSecret(),
                refreshToken: $credentials['refreshToken'] ?? '',
                userId: $this->authProvider->getUserId()
            );

            $assets = [];
            $accountsData = $infoApi->getAccounts();
            $accounts = $accountsData['accounts'] ?? [];

            foreach ($accounts as $account) {
                $locationsData = $infoApi->getLocations($account['name']);
                $locations = $locationsData['locations'] ?? [];

                foreach ($locations as $location) {
                    $parts = explode('/', $location['name']);
                    $locationId = end($parts);

                    $assets[] = [
                        'platformId' => $locationId,
                        'name' => $location['title'] ?? 'Unknown Location',
                        'data' => $location,
                    ];
                }
            }

            return $assets;
        } catch (Exception $e) {
            if ($throwOnError) {
                throw $e;
            }
            $this->logger?->error("GoogleBusinessProfileDriver: Error fetching locations", ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function validateAuthentication(): array
    {
        if (!$this->authProvider || !$this->authProvider->hasCredentials()) {
            return [
                'success' => false,
                'message' => 'Credentials not configured. Please complete Google login to acquire a token.',
                'details' => []
            ];
        }

        return [
            'success' => true,
            'message' => 'Connected successfully.',
            'details' => []
        ];
    }

    public function getChannel(): string
    {
        return 'google_business_profile';
    }

    public function syncEntities(
        DateTime  $startDate,
        DateTime  $endDate,
        array     $config = [],
        ?callable $shouldContinue = null,
        ?callable $identityMapper = null
    ): Response {
        return new Response(json_encode(['entities' => []]), 200, ['Content-Type' => 'application/json']);
    }

    public function sync(
        DateTime  $startDate,
        DateTime  $endDate,
        array     $config = [],
        ?callable $shouldContinue = null,
        ?callable $identityMapper = null
    ): Response {
        $credentials = $this->getUpdatableCredentials();
        $api = new BusinessPerformanceApi(
            redirectUrl: $this->authProvider->getRedirectUrl(),
            clientId: $this->authProvider->getClientId(),
            clientSecret: $this->authProvider->getClientSecret(),
            refreshToken: $credentials['refreshToken'] ?? '',
            userId: $this->authProvider->getUserId()
        );

        $channeledAccount = $config['channeledAccount'] ?? null;
        $locationId = $config['platform_id'] ?? null;

        if (!$locationId) {
            return new Response(json_encode(['error' => 'Location ID is required']), 400, ['Content-Type' => 'application/json']);
        }

        $metrics = [
            'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
            'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
            'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
            'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
            'BUSINESS_CONVERSATIONS',
            'BUSINESS_DIRECTION_REQUESTS',
            'CALL_CLICKS',
            'WEBSITE_CLICKS'
        ];

        try {
            $response = $api->fetchDailyMetricsTimeSeries(
                locationName: 'locations/' . $locationId,
                metrics: $metrics,
                startDate: $startDate->format('Y-m-d'),
                endDate: $endDate->format('Y-m-d')
            );

            $converted = GoogleBusinessProfileMetricConvert::convert($response, $channeledAccount);

            if ($this->dataProcessor) {
                ($this->dataProcessor)($converted, 'metrics');
            }

            return new Response(json_encode([
                'message' => 'Google Business Profile Sync completed successfully',
                'processed' => count($converted)
            ]), 200, ['Content-Type' => 'application/json']);
        } catch (Exception $e) {
            $this->logger?->error("GoogleBusinessProfileDriver: Error syncing metrics for {$locationId}: " . $e->getMessage());
            return new Response(json_encode(['error' => $e->getMessage()]), 500, ['Content-Type' => 'application/json']);
        }
    }

    public static function getPlatformEntityIdField(): string
    {
        return 'location_id';
    }

    public static function getCanonicalMetricDictionary(): array
    {
        return [
            'impressions' => [
                'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
                'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
                'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
                'BUSINESS_IMPRESSIONS_MOBILE_SEARCH'
            ],
            'website_clicks' => ['WEBSITE_CLICKS'],
            'calls' => ['CALL_CLICKS'],
            'directions' => ['BUSINESS_DIRECTION_REQUESTS'],
            'conversations' => ['BUSINESS_CONVERSATIONS']
        ];
    }

    // --- ChanneledAccountableInterface Methods ---

    public static function getChanneledAccountPlatformId(array $asset, ?string $key = null): string
    {
        return $asset['platformId'] ?? '';
    }

    public static function getChanneledAccountPlatformCreatedAt(array $asset, ?string $key = null): string
    {
        return $asset['createTime'] ?? '';
    }

    public static function getChanneledAccountName(array $asset, ?string $key = null): string
    {
        return $asset['name'] ?? '';
    }

    public static function getChanneledAccountType(): string
    {
        return 'google_business';
    }

    public static function getChanneledAccountData(array $asset, ?string $key = null): array
    {
        return $asset['data'] ?? [];
    }

    // --- SyncDriverInterface Additional Required Methods ---

    public function updateConfiguration(array $newData, array $currentConfig): array
    {
        if (isset($newData['granular_sync'])) {
            $currentConfig['granular_sync'] = filter_var($newData['granular_sync'], FILTER_VALIDATE_BOOLEAN);
        }

        return $currentConfig;
    }

    public function prepareUiConfig(array $channelConfig): array
    {
        return [
            'gbp_granular_sync' => $channelConfig['granular_sync'] ?? false
        ];
    }

    public function seedDemoData(\Anibalealvarezs\ApiDriverCore\Interfaces\SeederInterface $seeder, array $config = []): void
    {
    }

    public function initializeEntities(array $config = []): array
    {
        return ['initialized' => 0, 'skipped' => 0];
    }

    public static function getInstanceRules(): array
    {
        return [
            'history_months'     => 6,
            'entities_sync'      => false,
            'recent_cron_hour'   => 10,
            'recent_cron_minute' => 0,
        ];
    }

    public static function getAssetPatterns(): array
    {
        return [
            'google_business_profile' => [
                'key'          => 'locations',
                'prefix'       => 'gbp:location',
                'hostnames'    => [],
                'url_id_regex' => null,
                'type'         => 'location'
            ]
        ];
    }

    public static function getPageTypes(): array
    {
        return [
            'location' => 'Google Business Profile Location'
        ];
    }

    public static function getAccountTypes(): array
    {
        return [
            'location' => 'Google Business Profile Location'
        ];
    }

    public static function getEntityPaths(): array
    {
        return [];
    }

    public static function getPages(array $asset): array
    {
        return [];
    }

    public static function getChanneledAccounts(array $asset): array
    {
        return [];
    }

    public function getConfigurationJs(): string
    {
        return "";
    }

    public function getRequiredInstanceTier(): InstanceTier
    {
        return InstanceTier::TIER_2;
    }

    public static function getPlatformId(array $asset, AssetCategory $category, string $context): string
    {
        return $asset['platformId'] ?? '';
    }

    public static function getCanonicalId(array $asset, AssetCategory $category, string $context): string
    {
        return self::getPlatformId($asset, $category, $context);
    }
}
