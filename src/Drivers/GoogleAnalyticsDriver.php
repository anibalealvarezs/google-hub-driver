<?php

    namespace Anibalealvarezs\GoogleHubDriver\Drivers;

    use Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface;
    use Anibalealvarezs\ApiDriverCore\Routes\AssetRoutes;
    use Anibalealvarezs\ApiDriverCore\Traits\SyncDriverTrait;
    use Anibalealvarezs\GoogleHubDriver\Controllers\GoogleAuthController;
    use Anibalealvarezs\GoogleHubDriver\Traits\GoogleSyncDriverTrait;
    use DateTime;
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpFoundation\Response;
    use Anibalealvarezs\ApiDriverCore\Interfaces\SeederInterface;
    use Anibalealvarezs\GoogleHubDriver\Enums\GoogleChannel;
    use Anibalealvarezs\GoogleHubDriver\Enums\GoogleEntityType;
    use Anibalealvarezs\ApiDriverCore\Interfaces\CanonicalMetricDictionaryProviderInterface;
    use Anibalealvarezs\ApiDriverCore\Interfaces\ChanneledAccountableInterface;
    use Anibalealvarezs\GoogleApi\Services\AnalyticsAdmin\AnalyticsAdminApi;
    use Anibalealvarezs\GoogleApi\Services\AnalyticsData\AnalyticsDataApi;
    use Anibalealvarezs\GoogleHubDriver\Conversions\GoogleAnalyticsMetricConvert;

    class GoogleAnalyticsDriver implements SyncDriverInterface, CanonicalMetricDictionaryProviderInterface, ChanneledAccountableInterface
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
            return ['metrics' => 'ga_metrics'];
        }

        public static function getChannelLabel(): string
        {
            return 'Google Analytics';
        }

        public static function getChannelIcon(): string
        {
            return 'A';
        }

        public static function getRoutes(): array
        {
            return array_merge(AssetRoutes::get(), [
                '/google-login'      => [
                    'httpMethod' => 'GET',
                    'callable'   => fn(...$args) => (new GoogleAuthController())->login(),
                    'public'     => true,
                    'admin'      => false,
                    'html'       => true
                ],
                '/google-auth-start' => [
                    'httpMethod' => 'GET',
                    'callable'   => fn(...$args) => (new GoogleAuthController())->start(),
                    'public'     => true,
                    'admin'      => false
                ],
                '/google-callback'   => [
                    'httpMethod' => 'GET',
                    'callable'   => fn(...$args) => (new GoogleAuthController())->callback($args['request'] ?? Request::createFromGlobals()),
                    'public'     => true,
                    'admin'      => false,
                    'html'       => true
                ]
            ]);
        }

        public static function getRateLimitWhitelist(): array
        {
            return [
                '/ga-reports',
            ];
        }

        public function fetchAvailableAssets(bool $throwOnError = false): array
        {
            try {
                $credentials = $this->getUpdatableCredentials();
                $api = new AnalyticsAdminApi(
                    redirectUrl: $this->authProvider->getRedirectUrl(),
                    clientId: $this->authProvider->getClientId(),
                    clientSecret: $this->authProvider->getClientSecret(),
                    refreshToken: $credentials['refreshToken'] ?? '',
                    userId: $this->authProvider->getUserId()
                );

                $properties = $api->getProperties();

                $mappedProperties = array_map(function ($property) {
                    // Extract numeric ID from 'properties/123456789'
                    $platformId = str_replace('properties/', '', $property['property'] ?? $property['name']);
                    return [
                        'platformId' => $platformId,
                        'name' => $property['displayName'] ?? 'Unknown Property',
                        'data' => $property,
                    ];
                }, $properties);

                return ['properties' => $mappedProperties];
            } catch (\Exception $e) {
                if ($throwOnError) {
                    throw $e;
                }
                $this->logger?->error("GoogleAnalyticsDriver: Error fetching GA4 properties", ['error' => $e->getMessage()]);
                return ['properties' => []];
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
            return GoogleChannel::ANALYTICS->value;
        }

        public function syncEntities(
            DateTime  $startDate,
            DateTime  $endDate,
            array     $config = [],
            ?callable $shouldContinue = null,
            ?callable $identityMapper = null
        ): Response
        {
            $credentials = $this->getUpdatableCredentials();
            $api = new AnalyticsDataApi(
                redirectUrl: $this->authProvider->getRedirectUrl(),
                clientId: $this->authProvider->getClientId(),
                clientSecret: $this->authProvider->getClientSecret(),
                refreshToken: $credentials['refreshToken'] ?? '',
                userId: $this->authProvider->getUserId()
            );

            $channeledAccountId = $config['account_id'] ?? null;
            $propertyId = $config['platform_id'] ?? null;
            if (!$propertyId) {
                return new Response(json_encode(['error' => 'Property ID is required']));
            }

            $entities = [];

            try {
                // Discover Campaigns using sessionCampaignName
                $campaignResponse = $api->runSimpleReport(
                    propertyId: $propertyId,
                    metrics: ['activeUsers'],
                    dimensions: ['sessionCampaignName'],
                    startDate: $startDate->format('Y-m-d'),
                    endDate: $endDate->format('Y-m-d')
                );

                $processedCampaigns = GoogleAnalyticsMetricConvert::preprocessRows($campaignResponse);
                foreach ($processedCampaigns as $row) {
                    if (!empty($row['sessionCampaignName']) && $row['sessionCampaignName'] !== '(not set)') {
                        $entities[] = [
                            'platformId' => $row['sessionCampaignName'], // using name as platform ID
                            'name' => $row['sessionCampaignName'],
                            'type' => 'campaign'
                        ];
                    }
                }
                
                // Discover Pages using pagePath (if needed, otherwise we rely on syncMetrics inline creation)
                // We'll skip pages here to keep entity sync light, since pages are handled via DimensionKeys.
            } catch (\Exception $e) {
                $this->logger?->error("GA4 Entity Sync Error: " . $e->getMessage());
                return new Response(json_encode(['error' => $e->getMessage()]), 500);
            }

            return new Response(json_encode([
                'entities' => $entities
            ]));
        }

        public function sync(
            DateTime  $startDate,
            DateTime  $endDate,
            array     $config = [],
            ?callable $shouldContinue = null,
            ?callable $identityMapper = null
        ): Response
        {
            $credentials = $this->getUpdatableCredentials();
            $api = new AnalyticsDataApi(
                redirectUrl: $this->authProvider->getRedirectUrl(),
                clientId: $this->authProvider->getClientId(),
                clientSecret: $this->authProvider->getClientSecret(),
                refreshToken: $credentials['refreshToken'] ?? '',
                userId: $this->authProvider->getUserId()
            );

            $channeledAccount = $config['channeledAccount'] ?? null;
            $propertyId = $config['platform_id'] ?? null;
            $level = $config['level'] ?? 'account';
            $metricsList = $config['metrics'] ?? ['activeUsers', 'screenPageViews', 'sessions', 'bounceRate', 'totalRevenue'];
            
            if (!$propertyId) {
                return new Response(json_encode(['error' => 'Property ID is required']));
            }

            $dimensions = match($level) {
                'campaign' => ['date', 'sessionSourceMedium', 'sessionCampaignName'],
                'page' => ['date', 'sessionSourceMedium', 'pagePath'],
                default => ['date', 'sessionSourceMedium']
            };

            try {
                $payload = [
                    'dateRanges' => [['startDate' => $startDate->format('Y-m-d'), 'endDate' => $endDate->format('Y-m-d')]],
                    'dimensions' => array_map(fn ($d) => ['name' => $d], $dimensions),
                    'metrics' => array_map(fn ($m) => ['name' => $m], $metricsList),
                ];

                $response = $api->runAllReports($propertyId, $payload);
                $response['property_id'] = $propertyId;

                $metricsCollection = GoogleAnalyticsMetricConvert::metrics(
                    response: $response,
                    channeledAccount: $channeledAccount ?? $config['account_id'] ?? '',
                    level: $level,
                    logger: $this->logger,
                    account: $config['account'] ?? null
                );

                return new Response(json_encode([
                    'status' => 'success',
                    'metrics' => $metricsCollection->toArray()
                ]));
            } catch (\Exception $e) {
                $this->logger?->error("GA4 Metrics Sync Error: " . $e->getMessage());
                return new Response(json_encode(['error' => $e->getMessage()]), 500);
            }
        }

        public function getConfigSchema(): array
        {
            return [
                'global'     => [
                    'enabled'             => false,
                    'max_workers'         => self::DEFAULT_MAX_WORKERS,
                    'cache_history_range' => '30 days',
                    'cache_aggregations'  => false,
                    'metrics_strategy'    => 'default',
                ],
                'entity'     => [
                    'platformId'           => '',
                    'name'                 => '',
                    'enabled'              => true,
                    'exclude_from_caching' => false,
                    'lost_access'          => false,
                ],
                'metrics'    => [
                    'sessions'              => ['enabled' => false, 'format' => 'number', 'precision' => 0],
                    'totalUsers'            => ['enabled' => false, 'format' => 'number', 'precision' => 0],
                    'activeUsers'           => ['enabled' => false, 'format' => 'number', 'precision' => 0],
                    'newUsers'              => ['enabled' => false, 'format' => 'number', 'precision' => 0],
                    'screenPageViews'       => ['enabled' => false, 'format' => 'number', 'precision' => 0],
                    'bounceRate'            => ['enabled' => false, 'format' => 'percent', 'precision' => 2, 'sparkline_direction' => 'inverted'],
                    'averageSessionDuration'=> ['enabled' => false, 'format' => 'number', 'precision' => 2],
                    'conversions'           => ['enabled' => false, 'format' => 'number', 'precision' => 2],
                    'totalRevenue'          => ['enabled' => false, 'format' => 'currency', 'precision' => 2],
                ]
            ];
        }

        public function updateConfiguration(array $newData, array $currentConfig): array
        {
            $selectedAssets = $newData['assets']['google_analytics'] ?? [];
            $enabled = $newData['enabled'] ?? false;
            $historyRange = $newData['cache_history_range'] ?? null;
            $featureToggles = $newData['feature_toggles'] ?? [];

            if (!isset($currentConfig['channels']['google_analytics'])) {
                $currentConfig['channels']['google_analytics'] = [];
            }

            $chanCfg = &$currentConfig['channels']['google_analytics'];

            if ($historyRange) {
                $chanCfg['cache_history_range'] = $historyRange;
            }

            foreach (['cron_recent_hour', 'cron_recent_minute'] as $key) {
                if (isset($featureToggles[$key])) {
                    $chanCfg[$key] = (int)$featureToggles[$key];
                }
            }

            $chanCfg['enabled'] = $enabled;
            if (isset($newData['granular_sync'])) {
                $chanCfg['granular_sync'] = filter_var($newData['granular_sync'], FILTER_VALIDATE_BOOLEAN);
            }
            if (isset($newData['max_workers'])) {
                $chanCfg['max_workers'] = (int)$newData['max_workers'];
            }

            $newPropertiesList = [];
            foreach ($selectedAssets as $pData) {
                $item = [
                    'platformId'  => $pData['platformId'] ?? null,
                    'name'        => $pData['name'] ?? null,
                    'enabled'     => filter_var($pData['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'lost_access' => filter_var($pData['lost_access'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'data'        => $pData['data'] ?? [],
                ];
                $newPropertiesList[] = $item;
            }
            $chanCfg['properties'] = $newPropertiesList;

            return $currentConfig;
        }

        public function prepareUiConfig(array $channelConfig): array
        {
            $ui = [
                'ga_enabled'             => $channelConfig['enabled'] ?? false,
                'ga_granular_sync'       => $channelConfig['granular_sync'] ?? false,
                'ga_cache_history_range' => $channelConfig['cache_history_range'] ?? '30 days',
                'ga_cron_recent_hour'    => $channelConfig['cron_recent_hour'] ?? 10,
                'ga_cron_recent_minute'  => $channelConfig['cron_recent_minute'] ?? 0,
                'ga_max_workers'         => $channelConfig['max_workers'] ?? 3,
                'ga_properties'          => $channelConfig['properties'] ?? [],
            ];
            return $ui;
        }

        public function seedDemoData(SeederInterface $seeder, array $config = []): void
        {
            // Placeholder for future implementation
        }

        public static function getAssetPatterns(): array
        {
            return [
                'google_analytics' => [
                    'key'          => 'properties',
                    'prefix'       => 'ga:property',
                    'hostnames'    => [],
                    'url_id_regex' => null,
                    'type'         => 'property'
                ]
            ];
        }

        public static function getCanonicalMetricDictionary(): array
        {
            return [
                'conversions' => ['conversions'],
                'reach'       => ['activeUsers'],
                'impressions' => ['screenPageViews'],
                'sessions'    => ['sessions'],
                'spend'       => ['totalRevenue'],
                'revenue'     => ['totalRevenue'],
            ];
        }

        public static function getPageTypes(): array
        {
            return [
                'property' => 'Google Analytics 4 Property'
            ];
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

        public static function getPlatformEntityIdField(): string
        {
            return 'property_id';
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
            return 'google_analytics';
        }

        public static function getChanneledAccountData(array $asset, ?string $key = null): array
        {
            return $asset['data'] ?? [];
        }

        public function getConfigurationJs(): string
        {
            $file = __DIR__ . '/js/GoogleAnalyticsConfigHandler.js';
            if (file_exists($file)) {
                return file_get_contents($file);
            }
            return "";
        }
    }
