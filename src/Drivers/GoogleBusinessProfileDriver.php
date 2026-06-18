<?php

    namespace Anibalealvarezs\GoogleHubDriver\Drivers;

    use Anibalealvarezs\ApiDriverCore\Enums\AssetCategory;
    use Anibalealvarezs\ApiDriverCore\Classes\AggregationProfileTemplates;
    use Anibalealvarezs\ApiDriverCore\Classes\MetricProfileTemplates;
    use Anibalealvarezs\ApiDriverCore\Interfaces\AggregationProfileProviderInterface;
    use Anibalealvarezs\ApiDriverCore\Interfaces\LocationableInterface;
    use Anibalealvarezs\ApiDriverCore\Interfaces\MetricProfileProviderInterface;
    use Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface;
    use Anibalealvarezs\ApiDriverCore\Traits\SyncDriverTrait;
    use Anibalealvarezs\GoogleHubDriver\Traits\GoogleSyncDriverTrait;
    use Anibalealvarezs\GoogleHubDriver\Enums\GoogleChannel;
    use DateTime;
    use Symfony\Component\HttpFoundation\Response;
    use Anibalealvarezs\ApiDriverCore\Interfaces\CanonicalMetricDictionaryProviderInterface;
    use Anibalealvarezs\ApiDriverCore\Interfaces\ChanneledAccountableInterface;
    use Anibalealvarezs\GoogleApi\Services\BusinessInformation\BusinessInformationApi;
    use Anibalealvarezs\GoogleApi\Services\BusinessPerformance\BusinessPerformanceApi;
    use Anibalealvarezs\GoogleHubDriver\Conversions\GoogleBusinessProfileMetricConvert;
    use Anibalealvarezs\GoogleHubDriver\Controllers\GoogleAuthController;
    use Anibalealvarezs\GoogleHubDriver\Controllers\ReportController;
    use Anibalealvarezs\ApiDriverCore\Routes\AssetRoutes;
    use Anibalealvarezs\ApiDriverCore\Helpers\FieldsNormalizerHelper;
    use Anibalealvarezs\ApiDriverCore\Services\ConfigSchemaRegistryService;
    use Anibalealvarezs\ApiDriverCore\Services\CacheStrategyService;
    use Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity;
    use Anibalealvarezs\GoogleHubDriver\Enums\GoogleFeature;
    use Anibalealvarezs\GoogleHubDriver\Enums\GoogleEntityType;
    use Carbon\Carbon;
    use Classes\DriverInitializer;
    use Exception;
    use Symfony\Component\HttpFoundation\Request;

    class GoogleBusinessProfileDriver implements SyncDriverInterface, CanonicalMetricDictionaryProviderInterface, ChanneledAccountableInterface, LocationableInterface, MetricProfileProviderInterface, AggregationProfileProviderInterface
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

        public static function getMetricProfiles(): array
        {
            return [
                MetricProfileTemplates::pageTotals(
                    channel: GoogleChannel::BUSINESS_PROFILE->value,
                    key: 'gbp_location_totals',
                    label: 'GBP Location Totals'
                ),
                [
                    'key'           => 'gbp_location_daily',
                    'channel'       => GoogleChannel::BUSINESS_PROFILE->value,
                    'label'         => 'GBP Location Daily Breakdown',
                    'metric_config' => [
                        'required_fields'  => ['account', 'channeledAccount', 'channel', 'name', 'period'],
                        'common_filters'   => ['name', 'period'],
                        'groupable_fields' => [],
                        'index_hints'      => [
                            ['channel', 'name', 'period'],
                        ],
                    ],
                ],
            ];
        }

        public static function getAggregationProfiles(): array
        {
            return [
                AggregationProfileTemplates::organicPageFlowProfile(
                    channel: GoogleChannel::BUSINESS_PROFILE->value,
                    key: 'gbp_location_flow',
                    label: 'GBP Location Flow',
                    overrides: [
                        'asset_type'         => 'location',
                        'filter_contract'    => [
                            'channel'    => ['='],
                            'metricDate' => ['between', '>=', '<='],
                        ],
                        'reducer_strategies' => [
                            '*' => 'sum',
                        ],
                    ]
                ),
            ];
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
                ],
                '/gbp-reports'       => [
                    'httpMethod' => 'GET',
                    'callable'   => fn(...$args) => (new ReportController())->gbp($args),
                    'public'     => true,
                    'admin'      => false,
                    'html'       => true
                ]
            ]);
        }

        public static function getRateLimitWhitelist(): array
        {
            return [
                '/gbp-reports',
            ];
        }

        public function fetchAvailableAssets(bool $throwOnError = false): array
        {
            if (!$this->authProvider || !$this->authProvider->hasCredentials()) {
                return ['accounts' => [], 'locations' => []];
            }

            try {
                $creds = $this->resolveGoogleCredentials();
                $infoApi = new BusinessInformationApi(
                    redirectUrl: $creds['redirectUrl'],
                    clientId: $creds['clientId'],
                    clientSecret: $creds['clientSecret'],
                    refreshToken: $creds['refreshToken'],
                    userId: $creds['userId'],
                    scopes: $creds['scopes'],
                    token: $creds['token'],
                    tokenPath: $creds['tokenPath'],
                    logger: $this->logger,
                    tokenRefresherCallback: $creds['tokenRefresherCallback']
                );

                $accounts = [];
                $allLocations = [];
                $accountsData = $infoApi->getAccounts();
                $accountsList = $accountsData['accounts'] ?? [];

                foreach ($accountsList as $account) {
                    $accountPlatformId = $account['name'] ?? '';
                    $accountEntry = [
                        'platformId' => $accountPlatformId,
                        'name'       => $account['accountName'] ?? $account['title'] ?? 'Unknown Account',
                        'enabled'    => true,
                        'data'       => $account,
                        'locations'  => [],
                    ];

                    $locationsData = $infoApi->getLocations($accountPlatformId);
                    $locationsList = $locationsData['locations'] ?? [];

                    foreach ($locationsList as $location) {
                        $locEntry = [
                            'platformId' => $location['name'] ?? '',
                            'title'      => $location['title'] ?? 'Unknown Location',
                            'enabled'    => true,
                            'data'       => $location,
                            'lat'        => $location['latlng']['latitude'] ?? null,
                            'lng'        => $location['latlng']['longitude'] ?? null,
                            'zipCode'    => $location['storefrontAddress']['postalCode'] ?? null,
                            'city'       => $location['storefrontAddress']['locality'] ?? null,
                            'state'      => $location['storefrontAddress']['administrativeArea'] ?? null,
                            'country'    => $location['storefrontAddress']['regionCode'] ?? null,
                            'storeCode'  => $location['storeCode'] ?? null,
                        ];

                        $accountEntry['locations'][] = $locEntry;
                        $allLocations[] = $locEntry;
                    }

                    $accounts[] = $accountEntry;
                }

                return [
                    'accounts'  => $accounts,
                    'locations' => $allLocations,
                ];
            } catch (Exception $e) {
                if ($this->isAuthenticationError($e)) {
                    $this->logger?->critical("GoogleBusinessProfileDriver: Authentication failed (invalid_grant/expired). Please re-authenticate via UI.");
                } else {
                    $this->logger?->error("GoogleBusinessProfileDriver: Error fetching locations", ['error' => $e->getMessage()]);
                }

                if ($throwOnError) {
                    throw $e;
                }

                return ['accounts' => [], 'locations' => []];
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

            try {
                $creds = $this->resolveGoogleCredentials();
                $api = new BusinessInformationApi(
                    redirectUrl: $creds['redirectUrl'],
                    clientId: $creds['clientId'],
                    clientSecret: $creds['clientSecret'],
                    refreshToken: $creds['refreshToken'],
                    userId: $creds['userId'],
                    scopes: $creds['scopes'],
                    token: $creds['token'],
                    tokenPath: $creds['tokenPath'],
                    logger: $this->logger,
                    tokenRefresherCallback: $creds['tokenRefresherCallback']
                );
                $api->getAccounts();

                return [
                    'success' => true,
                    'message' => 'Authentication is valid.',
                    'details' => []
                ];
            } catch (Exception $e) {
                return [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'details' => []
                ];
            }
        }

        public function getChannel(): string
        {
            return GoogleChannel::BUSINESS_PROFILE->value;
        }

        public function sync(
            DateTime  $startDate,
            DateTime  $endDate,
            array     $config = [],
            ?callable $shouldContinue = null,
            ?callable $identityMapper = null
        ): Response
        {
            if (!$this->authProvider) {
                throw new Exception("AuthProvider not set for GoogleBusinessProfileDriver");
            }

            if (!$this->dataProcessor) {
                throw new Exception("DataProcessor not set for GoogleBusinessProfileDriver");
            }

            try {
                $creds = $this->resolveGoogleCredentials();
                $api = new BusinessPerformanceApi(
                    redirectUrl: $creds['redirectUrl'],
                    clientId: $creds['clientId'],
                    clientSecret: $creds['clientSecret'],
                    refreshToken: $creds['refreshToken'],
                    userId: $creds['userId'],
                    scopes: $creds['scopes'],
                    token: $creds['token'],
                    tokenPath: $creds['tokenPath'],
                    logger: $this->logger,
                    tokenRefresherCallback: $creds['tokenRefresherCallback']
                );

                $totalStats = ['metrics' => 0, 'locations' => 0, 'errors' => 0];

                $chanCfg = $config[GoogleChannel::BUSINESS_PROFILE->value] ?? [];
                $locationsToProcess = $config['locations'] ?? $chanCfg['locations'] ?? [];
                $locationId = $config['platform_id'] ?? null;

                $metrics = [
                    'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
                    'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
                    'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
                    'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
                    'BUSINESS_CONVERSATIONS',
                    'BUSINESS_DIRECTION_REQUESTS',
                    'CALL_CLICKS',
                    'WEBSITE_CLICKS',
                    'BUSINESS_BOOKINGS',
                    'BUSINESS_FOOD_ORDERS',
                    'BUSINESS_FOOD_MENU_CLICKS',
                ];

                // 1. Batch Resolve Identities via Oracle
                $caMap = [];
                $accountMap = [];
                $locationMap = [];
                if ($identityMapper && (!empty($locationsToProcess) || $locationId)) {
                    $caPlatformIds = [];
                    $locPlatformIds = [];
                    $locIds = $locationId ? [$locationId] : [];
                    foreach ($locationsToProcess as $loc) {
                        $locIds[] = (string)($loc['location_id'] ?? $loc);
                    }
                    foreach ($locIds as $lid) {
                        $caPlatformIds[] = self::getPlatformId(['platformId' => $lid], AssetCategory::IDENTITY, 'google_business_profile');
                        $locPlatformIds[] = $lid;
                    }
                    $caMap = $identityMapper('channeled_accounts', ['platform_ids' => $caPlatformIds]) ?? [];
                    $accountMap = $identityMapper('accounts', ['names' => ['Google Business Profile', 'Google', 'google']]) ?? [];
                    $locationMap = $identityMapper('locations', ['platform_ids' => $locPlatformIds]) ?? [];
                }

                $startDateCarbon = Carbon::instance($startDate);
                $endDateCarbon = Carbon::instance($endDate);

                // 2. Collect location IDs to process
                $locIdsToProcess = [];
                $locConfigMap = [];
                if ($locationId) {
                    $locIdsToProcess[] = $locationId;
                }
                foreach ($locationsToProcess as $loc) {
                    $locEnabled = true;
                    if (is_array($loc)) {
                        $locEnabled = (bool)($loc['enabled'] ?? true);
                    }
                    if (!$locEnabled) {
                        continue;
                    }
                    $lid = is_array($loc) ? ($loc['location_id'] ?? '') : (string)$loc;
                    if ($lid && !in_array($lid, $locIdsToProcess)) {
                        $locIdsToProcess[] = $lid;
                        if (is_array($loc)) {
                            $locConfigMap[$lid] = $loc;
                        }
                    }
                }

                if (empty($locIdsToProcess)) {
                    return new Response(json_encode(['error' => 'No locations to process']), 400, ['Content-Type' => 'application/json']);
                }

                // 3. Process each location with 7-day window chunking
                foreach ($locIdsToProcess as $lid) {
                    $caPlatformId = self::getPlatformId(['platformId' => $lid], AssetCategory::IDENTITY, 'google_business_profile');
                    $ca = $caMap[$caPlatformId] ?? null;

                    $caObject = is_object($ca) ? $ca : (new UniversalEntity())->setPlatformId($caPlatformId);

                    // Resolve Location entity
                    $locConfig = $locConfigMap[$lid] ?? [];
                    $locPlatformId = $locConfig['platformId'] ?? null;
                    $locationEntity = null;
                    $stateName = null;
                    $cityName = null;
                    if ($locPlatformId && isset($locationMap[$locPlatformId])) {
                        $locationEntity = $locationMap[$locPlatformId];
                        $stateName = is_object($locationEntity) && method_exists($locationEntity, 'getState') && $locationEntity->getState()
                            ? (method_exists($locationEntity->getState(), 'getName') ? $locationEntity->getState()->getName() : null)
                            : null;
                        $cityName = is_object($locationEntity) && method_exists($locationEntity, 'getCity') && $locationEntity->getCity()
                            ? (method_exists($locationEntity->getCity(), 'getName') ? $locationEntity->getCity()->getName() : null)
                            : null;
                    }

                    $chunkStart = clone $startDateCarbon;
                    while ($chunkStart <= $endDateCarbon) {
                        $chunkEnd = (clone $chunkStart)->addDays(6);
                        if ($chunkEnd > $endDateCarbon) {
                            $chunkEnd = clone $endDateCarbon;
                        }

                        $chunkStartStr = $chunkStart->format('Y-m-d');
                        $chunkEndStr = $chunkEnd->format('Y-m-d');

                        if ($shouldContinue && !$shouldContinue()) {
                            throw new Exception("Sync aborted by the orchestrator.");
                        }

                        $this->logger?->info(">>> Syncing GBP for Location: $lid (Window: $chunkStartStr to $chunkEndStr)");

                        try {
                            $response = $api->fetchDailyMetricsTimeSeries(
                                locationName: 'locations/'.$lid,
                                metrics: $metrics,
                                startDate: $chunkStartStr,
                                endDate: $chunkEndStr
                            );

                            $converted = GoogleBusinessProfileMetricConvert::convert(
                                response: $response,
                                channeledAccount: $caObject,
                                logger: $this->logger,
                                location: $locationEntity,
                                state: $stateName,
                                city: $cityName
                            );

                            if ($this->dataProcessor && $converted->count() > 0) {
                                $result = ($this->dataProcessor)($converted, $this->logger);
                                $totalStats['metrics'] += $result['metrics'] ?? 0;
                                $totalStats['locations']++;
                                $this->logger?->info("+++ Synced ".$converted->count()." GBP metric rows for Location: $lid");
                            }
                        } catch (Exception $e) {
                            $totalStats['errors']++;
                            $this->logger?->error("!!! Error syncing window $chunkStartStr to $chunkEndStr for Location $lid: ".$e->getMessage());
                            if (str_contains($e->getMessage(), 'Sync aborted')) throw $e;
                        }

                        $chunkStart->addDays(7);
                    }
                }

                return new Response(json_encode([
                    'status'  => 'success',
                    'message' => 'Google Business Profile sync completed',
                    'stats'   => $totalStats
                ]));
            } catch (Exception $e) {
                $this->logger?->critical("!!!! CRITICAL ERROR: GoogleBusinessProfileDriver failed: ".$e->getMessage());

                return new Response(json_encode([
                    'status'     => 'error',
                    'message'    => $e->getMessage(),
                    'error_code' => 'sync_failure'
                ]), 500);
            }
        }

        public static function getPlatformEntityIdField(): string
        {
            return 'location_id';
        }

        public static function getCanonicalMetricDictionary(): array
        {
            return [
                'impressions'    => [
                    'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
                    'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
                    'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
                    'BUSINESS_IMPRESSIONS_MOBILE_SEARCH'
                ],
                'website_clicks' => ['WEBSITE_CLICKS'],
                'calls'          => ['CALL_CLICKS'],
                'directions'     => ['BUSINESS_DIRECTION_REQUESTS'],
                'conversations'  => ['BUSINESS_CONVERSATIONS'],
                'bookings'       => ['BUSINESS_BOOKINGS'],
                'food_orders'    => ['BUSINESS_FOOD_ORDERS'],
                'menu_clicks'    => ['BUSINESS_FOOD_MENU_CLICKS'],
            ];
        }

        // --- ChanneledAccountableInterface Methods ---

        public static function getChanneledAccountPlatformId(array $asset, ?string $key = null): string
        {
            return $asset['platformId'] ?? '';
        }

        public static function getChanneledAccountPlatformCreatedAt(array $asset, ?string $key = null): string
        {
            return $asset['data']['createTime'] ?? $asset['createTime'] ?? '';
        }

        public static function getChanneledAccountName(array $asset, ?string $key = null): string
        {
            return $asset['name'] ?? $asset['title'] ?? $asset['data']['title'] ?? '';
        }

        public static function getChanneledAccountType(string|GoogleEntityType $entityType = GoogleEntityType::LOCATION): string
        {
            return $entityType instanceof GoogleEntityType ? $entityType->value : $entityType;
        }

        public static function getChanneledAccountData(array $asset, ?string $key = null): array
        {
            return $asset['data'] ?? [];
        }

        // --- LocationableInterface Methods ---

        public static function getLocationPlatformId(array $asset, ?string $key = null): string
        {
            return $asset['platformId'] ?? $asset['name'] ?? '';
        }

        public static function getLocationTitle(array $asset, ?string $key = null): string
        {
            return $asset['title'] ?? $asset['name'] ?? '';
        }

        public static function getLocationStoreCode(array $asset, ?string $key = null): ?string
        {
            return $asset['storeCode'] ?? $asset['data']['storeCode'] ?? null;
        }

        public static function getLocationLat(array $asset, ?string $key = null): ?float
        {
            $lat = $asset['lat'] ?? $asset['data']['latlng']['latitude'] ?? null;

            return $lat !== null ? (float)$lat : null;
        }

        public static function getLocationLng(array $asset, ?string $key = null): ?float
        {
            $lng = $asset['lng'] ?? $asset['data']['latlng']['longitude'] ?? null;

            return $lng !== null ? (float)$lng : null;
        }

        public static function getLocationZipCode(array $asset, ?string $key = null): ?string
        {
            return $asset['zipCode'] ?? $asset['data']['storefrontAddress']['postalCode'] ?? null;
        }

        public static function getLocationCity(array $asset, ?string $key = null): ?string
        {
            return $asset['city'] ?? $asset['data']['storefrontAddress']['locality'] ?? null;
        }

        public static function getLocationState(array $asset, ?string $key = null): ?string
        {
            return $asset['state'] ?? $asset['data']['storefrontAddress']['administrativeArea'] ?? null;
        }

        public static function getLocationCountry(array $asset, ?string $key = null): ?string
        {
            return $asset['country'] ?? $asset['data']['storefrontAddress']['regionCode'] ?? null;
        }

        public static function getLocationData(array $asset, ?string $key = null): array
        {
            return $asset['data'] ?? [];
        }

        // --- SyncDriverInterface Additional Required Methods ---

        public function getConfigSchema(): array
        {
            return [
                'global'  => [
                    'enabled'             => false,
                    'cache_history_range' => '30 months',
                    'cache_aggregations'  => true,
                    'cron_recent_hour'    => 10,
                    'cron_recent_minute'  => 0,
                ],
                'entity'  => [
                    'location_id' => '',
                    'title'       => '',
                    'enabled'     => true,
                ],
                'metrics' => [
                    'impressions'    => ['enabled' => true, 'format' => 'number', 'precision' => 0],
                    'website_clicks' => ['enabled' => true, 'format' => 'number', 'precision' => 0],
                    'calls'          => ['enabled' => true, 'format' => 'number', 'precision' => 0],
                    'directions'     => ['enabled' => true, 'format' => 'number', 'precision' => 0],
                    'conversations'  => ['enabled' => true, 'format' => 'number', 'precision' => 0],
                    'bookings'       => ['enabled' => true, 'format' => 'number', 'precision' => 0],
                    'food_orders'    => ['enabled' => true, 'format' => 'number', 'precision' => 0],
                    'menu_clicks'    => ['enabled' => true, 'format' => 'number', 'precision' => 0],
                ]
            ];
        }

        public function updateConfiguration(array $newData, array $currentConfig): array
        {
            $selectedLocations = $newData['assets']['gbp'] ?? [];
            $enabled = $newData['enabled'] ?? false;
            $historyRange = $newData['cache_history_range'] ?? null;
            $featureToggles = $newData['feature_toggles'] ?? [];

            if (!isset($currentConfig['channels'][GoogleChannel::BUSINESS_PROFILE->value])) {
                $currentConfig['channels'][GoogleChannel::BUSINESS_PROFILE->value] = [];
            }

            $chanCfg = &$currentConfig['channels'][GoogleChannel::BUSINESS_PROFILE->value];

            if ($historyRange) {
                $chanCfg['cache_history_range'] = $historyRange;
            }

            foreach (GoogleFeature::cron() as $feature) {
                $key = $feature->value;
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

            if (isset($featureToggles['cache_aggregations'])) {
                $prevValue = (bool)($chanCfg['cache_aggregations'] ?? false);
                $newValue = (bool)$featureToggles['cache_aggregations'];
                $chanCfg['cache_aggregations'] = $newValue;

                if ($prevValue && !$newValue && class_exists('\Anibalealvarezs\ApiDriverCore\Services\CacheStrategyService')) {
                    CacheStrategyService::clearChannel(GoogleChannel::BUSINESS_PROFILE->value);
                }
            }

            if (empty($selectedLocations) && isset($newData['type']) && $newData['type'] !== 'global') {
                if ($this->logger) {
                    $this->logger->warning("Received empty locations payload for Google Business Profile, skipping update to prevent wipe.");
                }

                return $currentConfig;
            }

            $currentLocations = $chanCfg['locations'] ?? [];

            $newLocationsList = [];
            $selectedMap = [];
            foreach ($selectedLocations as $sel) {
                $normId = FieldsNormalizerHelper::getCleanString($sel['location_id']);
                $selectedMap[$normId] = $sel;
            }

            $processedNormIds = [];
            foreach ($currentLocations as $location) {
                $normId = FieldsNormalizerHelper::getCleanString($location['location_id']);
                if (isset($selectedMap[$normId])) {
                    $location['enabled'] = filter_var($selectedMap[$normId]['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
                    $location['data'] = $selectedMap[$normId]['data'] ?? $location['data'] ?? [];
                    if (!empty($selectedMap[$normId]['platformId'])) {
                        $location['platformId'] = $selectedMap[$normId]['platformId'];
                    }

                    $schemaMetrics = array_keys($this->getConfigSchema()['metrics'] ?? []);
                    foreach ($schemaMetrics as $metricKey) {
                        if (isset($selectedMap[$normId][$metricKey])) {
                            $location[$metricKey] = $selectedMap[$normId][$metricKey];
                        }
                    }

                    $newLocationsList[] = $location;
                    $processedNormIds[] = $normId;
                }
            }

            foreach ($selectedLocations as $sel) {
                $normId = FieldsNormalizerHelper::getCleanString($sel['location_id']);
                if (!in_array($normId, $processedNormIds)) {
                    $entry = [
                        'location_id' => $sel['location_id'],
                        'title'       => $sel['title'] ?? 'Unknown Location',
                        'enabled'     => filter_var($sel['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                        'data'        => $sel['data'] ?? []
                    ];
                    if (!empty($sel['platformId'])) {
                        $entry['platformId'] = $sel['platformId'];
                    }

                    $schemaMetrics = array_keys($this->getConfigSchema()['metrics'] ?? []);
                    foreach ($schemaMetrics as $metricKey) {
                        if (isset($sel[$metricKey])) {
                            $entry[$metricKey] = $sel[$metricKey];
                        }
                    }

                    $newLocationsList[] = $entry;
                }
            }

            $chanCfg['locations'] = $newLocationsList;

            return $currentConfig;
        }

        public function prepareUiConfig(array $channelConfig): array
        {
            $ui = [];
            $ui['gbp_cache_history_range'] = $channelConfig['cache_history_range'] ?? '30 months';
            $ui['gbp_enabled'] = $channelConfig['enabled'] ?? false;
            $ui['gbp_cron_recent_hour'] = $channelConfig['cron_recent_hour'] ?? 10;
            $ui['gbp_cron_recent_minute'] = $channelConfig['cron_recent_minute'] ?? 0;
            $ui['gbp_granular_sync'] = $channelConfig['granular_sync'] ?? false;
            $ui['gbp_max_workers'] = $channelConfig['max_workers'] ?? 3;

            $ui['gbp'] = [];
            foreach (($channelConfig['locations'] ?? []) as $location) {
                $locId = $location['location_id'];
                if (class_exists('\Anibalealvarezs\ApiDriverCore\Services\ConfigSchemaRegistryService')) {
                    $hydrated = ConfigSchemaRegistryService::hydrate('google_business_profile', 'entity', $location);
                    $hydratedMetrics = ConfigSchemaRegistryService::hydrate('google_business_profile', 'metrics', $location);
                    $hydrated = array_merge($hydrated, $hydratedMetrics);

                    if (!empty($location['platformId'])) {
                        $hydrated['platformId'] = $location['platformId'];
                    }
                    $ui['gbp'][$locId] = $hydrated;
                } else {
                    $ui['gbp'][$locId] = $location;
                }
            }

            return $ui;
        }

        public function seedDemoData(\Anibalealvarezs\ApiDriverCore\Interfaces\SeederInterface $seeder, array $config = []): void
        {
            $output = $config['output'] ?? null;
            if ($output) $output->writeln("🔍 GBP (5 Locations, 6 Months)...");

            $dates = $seeder->getDates(180);
            $gbpChan = GoogleChannel::BUSINESS_PROFILE;

            $gbpAcc = $seeder->resolveEntity('account', ['name' => 'Demo Agency GBP']);

            $locationNames = [
                'Downtown Store',
                'Uptown Branch',
                'Airport Kiosk',
                'Mall Location',
                'Business Center'
            ];

            foreach ($locationNames as $idx => $locName) {
                $locPId = "gbp:location:demo-".($idx + 1);
                $location = $seeder->resolveEntity('page', [
                    'platformId'  => $locPId,
                    'account'     => $gbpAcc,
                    'title'       => $locName,
                    'url'         => '',
                    'canonicalId' => $locPId
                ]);

                $ca = $seeder->resolveEntity('channeled_account', [
                    'platformId' => $locPId,
                    'account'    => $gbpAcc,
                    'type'       => GoogleEntityType::LOCATION->value,
                    'channel'    => GoogleChannel::BUSINESS_PROFILE->value,
                    'name'       => $locName
                ]);

                foreach ($dates as $date) {
                    $imps = rand(50, 500);
                    $clicks = (int)($imps * rand(1, 8) / 100);
                    $calls = rand(0, 15);
                    $directions = rand(0, 30);
                    $conversations = rand(0, 10);

                    foreach ([
                                 'impressions'    => $imps,
                                 'website_clicks' => $clicks,
                                 'calls'          => $calls,
                                 'directions'     => $directions,
                                 'conversations'  => $conversations,
                             ] as $name => $val) {
                        if ($val <= 0) continue;
                        $seeder->queueMetric(
                            channel: $gbpChan,
                            name: $name,
                            date: $date,
                            value: $val,
                            pageId: $location->id,
                            caId: $ca->id,
                            gAccId: $gbpAcc->id,
                            accName: $gbpAcc->getTitle(),
                            caPId: $locPId,
                            pageUrl: '',
                            data: json_encode(['raw' => $val])
                        );
                    }
                }
            }
        }

        public function initializeEntities(array $config = []): array
        {
            $em = $config['manager'] ?? null;

            if (!$em) {
                return $this->fetchAvailableAssets(throwOnError: false);
            }

            $assets = $this->fetchAvailableAssets(throwOnError: false);
            $accountEntities = $assets['accounts'] ?? [];
            $initialized = 0;
            $skipped = 0;

            $channelEntity = $em->getRepository(\Entities\Analytics\Channel::class)
                ->findOneBy(['name' => GoogleChannel::BUSINESS_PROFILE->value]);
            $accountEntity = $em->getRepository(\Entities\Analytics\Account::class)
                ->findOneBy(['name' => 'Google Business Profile']);

            foreach ($accountEntities as $accAsset) {
                // Create/update ChanneledAccount for this Business Account
                $caPlatformId = $accAsset['platformId'];
                $ca = $em->getRepository(\Entities\Analytics\Channeled\ChanneledAccount::class)
                    ->findOneBy(['platformId' => $caPlatformId, 'channel' => $channelEntity]);

                if (!$ca) {
                    $ca = new \Entities\Analytics\Channeled\ChanneledAccount();
                    $ca->addPlatformId($caPlatformId);
                    if ($accountEntity) {
                        $ca->addAccount($accountEntity);
                    }
                    if ($channelEntity) {
                        $ca->addChannel($channelEntity);
                    }
                    $ca->addName($accAsset['name']);
                    $ca->addType(GoogleEntityType::BUSINESS_ACCOUNT->value);
                    $ca->addData($accAsset['data'] ?? []);
                    $em->persist($ca);
                    $initialized++;
                }

                // Create/update Location entities
                foreach ($accAsset['locations'] ?? [] as $locAsset) {
                    $locPlatformId = self::getLocationPlatformId($locAsset);
                    $location = $em->getRepository(\Entities\Analytics\Location::class)
                        ->findOneBy(['platformId' => $locPlatformId]);

                    if (!$location) {
                        $location = new \Entities\Analytics\Location();
                        $location->addPlatformId($locPlatformId);
                        $location->addTitle(self::getLocationTitle($locAsset));
                        if ($ca) {
                            $location->addChanneledAccount($ca);
                        }
                        if ($accountEntity) {
                            $location->addAccount($accountEntity);
                        }

                        // Resolve country
                        $countryCode = self::getLocationCountry($locAsset);
                        $countryEntity = null;
                        if ($countryCode) {
                            $countryEntity = $em->getRepository(\Entities\Analytics\Country::class)
                                ->findOneBy(['code' => self::convertAlpha2ToAlpha3($countryCode)]);
                            if ($countryEntity) {
                                $location->addCountry($countryEntity);
                            }
                        }

                        // Resolve state
                        $stateName = self::getLocationState($locAsset);
                        if ($stateName && $countryEntity) {
                            $state = $em->getRepository(\Entities\Analytics\State::class)
                                ->findByNameAndCountry($stateName, $countryEntity->getId());
                            if ($state) {
                                $location->addState($state);
                            }
                        }

                        // Resolve city
                        $cityName = self::getLocationCity($locAsset);
                        if ($cityName && $countryEntity) {
                            $city = $em->getRepository(\Entities\Analytics\City::class)
                                ->findOneBy(['name' => $cityName, 'country' => $countryEntity->getId()]);
                            if ($city) {
                                $location->addCity($city);
                            }
                        }

                        $location->addLat(self::getLocationLat($locAsset));
                        $location->addLng(self::getLocationLng($locAsset));
                        $location->addZipCode(self::getLocationZipCode($locAsset));
                        $location->addStoreCode(self::getLocationStoreCode($locAsset));
                        $location->addData(self::getLocationData($locAsset));
                        $em->persist($location);
                        $initialized++;
                    } else {
                        $skipped++;
                    }
                }
            }

            $em->flush();

            return [
                'initialized' => $initialized,
                'skipped'     => $skipped,
                'accounts'    => $accountEntities,
                'locations'   => $assets['locations'] ?? [],
            ];
        }

        /**
         * Convert ISO 3166-1 alpha-2 to alpha-3 for Country entity lookup.
         */
        private static function convertAlpha2ToAlpha3(string $alpha2): string
        {
            $map = [
                'AF' => 'AFG', 'AX' => 'ALA', 'AL' => 'ALB', 'DZ' => 'DZA', 'AS' => 'ASM',
                'AD' => 'AND', 'AO' => 'AGO', 'AI' => 'AIA', 'AQ' => 'ATA', 'AG' => 'ATG',
                'AR' => 'ARG', 'AM' => 'ARM', 'AW' => 'ABW', 'AU' => 'AUS', 'AT' => 'AUT',
                'AZ' => 'AZE', 'BS' => 'BHS', 'BH' => 'BHR', 'BD' => 'BGD', 'BB' => 'BRB',
                'BY' => 'BLR', 'BE' => 'BEL', 'BZ' => 'BLZ', 'BJ' => 'BEN', 'BM' => 'BMU',
                'BT' => 'BTN', 'BO' => 'BOL', 'BQ' => 'BES', 'BA' => 'BIH', 'BW' => 'BWA',
                'BV' => 'BVT', 'BR' => 'BRA', 'IO' => 'IOT', 'BN' => 'BRN', 'BG' => 'BGR',
                'BF' => 'BFA', 'BI' => 'BDI', 'CV' => 'CPV', 'KH' => 'KHM', 'CM' => 'CMR',
                'CA' => 'CAN', 'KY' => 'CYM', 'CF' => 'CAF', 'TD' => 'TCD', 'CL' => 'CHL',
                'CN' => 'CHN', 'CX' => 'CXR', 'CC' => 'CCK', 'CO' => 'COL', 'KM' => 'COM',
                'CD' => 'COD', 'CG' => 'COG', 'CK' => 'COK', 'CR' => 'CRI', 'CI' => 'CIV',
                'HR' => 'HRV', 'CU' => 'CUB', 'CW' => 'CUW', 'CY' => 'CYP', 'CZ' => 'CZE',
                'DK' => 'DNK', 'DJ' => 'DJI', 'DM' => 'DMA', 'DO' => 'DOM', 'EC' => 'ECU',
                'EG' => 'EGY', 'SV' => 'SLV', 'GQ' => 'GNQ', 'ER' => 'ERI', 'EE' => 'EST',
                'SZ' => 'SWZ', 'ET' => 'ETH', 'FK' => 'FLK', 'FO' => 'FRO', 'FJ' => 'FJI',
                'FI' => 'FIN', 'FR' => 'FRA', 'GF' => 'GUF', 'PF' => 'PYF', 'TF' => 'ATF',
                'GA' => 'GAB', 'GM' => 'GMB', 'GE' => 'GEO', 'DE' => 'DEU', 'GH' => 'GHA',
                'GI' => 'GIB', 'GR' => 'GRC', 'GL' => 'GRL', 'GD' => 'GRD', 'GP' => 'GLP',
                'GU' => 'GUM', 'GT' => 'GTM', 'GG' => 'GGY', 'GN' => 'GIN', 'GW' => 'GNB',
                'GY' => 'GUY', 'HT' => 'HTI', 'HM' => 'HMD', 'VA' => 'VAT', 'HN' => 'HND',
                'HK' => 'HKG', 'HU' => 'HUN', 'IS' => 'ISL', 'IN' => 'IND', 'ID' => 'IDN',
                'IR' => 'IRN', 'IQ' => 'IRQ', 'IE' => 'IRL', 'IM' => 'IMN', 'IL' => 'ISR',
                'IT' => 'ITA', 'JM' => 'JAM', 'JP' => 'JPN', 'JE' => 'JEY', 'JO' => 'JOR',
                'KZ' => 'KAZ', 'KE' => 'KEN', 'KI' => 'KIR', 'KP' => 'PRK', 'KR' => 'KOR',
                'KW' => 'KWT', 'KG' => 'KGZ', 'LA' => 'LAO', 'LV' => 'LVA', 'LB' => 'LBN',
                'LS' => 'LSO', 'LR' => 'LBR', 'LY' => 'LBY', 'LI' => 'LIE', 'LT' => 'LTU',
                'LU' => 'LUX', 'MO' => 'MAC', 'MG' => 'MDG', 'MW' => 'MWI', 'MY' => 'MYS',
                'MV' => 'MDV', 'ML' => 'MLI', 'MT' => 'MLT', 'MH' => 'MHL', 'MQ' => 'MTQ',
                'MR' => 'MRT', 'MU' => 'MUS', 'YT' => 'MYT', 'MX' => 'MEX', 'FM' => 'FSM',
                'MD' => 'MDA', 'MC' => 'MCO', 'MN' => 'MNG', 'ME' => 'MNE', 'MS' => 'MSR',
                'MA' => 'MAR', 'MZ' => 'MOZ', 'MM' => 'MMR', 'NA' => 'NAM', 'NR' => 'NRU',
                'NP' => 'NPL', 'NL' => 'NLD', 'NC' => 'NCL', 'NZ' => 'NZL', 'NI' => 'NIC',
                'NE' => 'NER', 'NG' => 'NGA', 'NU' => 'NIU', 'NF' => 'NFK', 'MK' => 'MKD',
                'MP' => 'MNP', 'NO' => 'NOR', 'OM' => 'OMN', 'PK' => 'PAK', 'PW' => 'PLW',
                'PS' => 'PSE', 'PA' => 'PAN', 'PG' => 'PNG', 'PY' => 'PRY', 'PE' => 'PER',
                'PH' => 'PHL', 'PN' => 'PCN', 'PL' => 'POL', 'PT' => 'PRT', 'PR' => 'PRI',
                'QA' => 'QAT', 'RE' => 'REU', 'RO' => 'ROU', 'RU' => 'RUS', 'RW' => 'RWA',
                'BL' => 'BLM', 'SH' => 'SHN', 'KN' => 'KNA', 'LC' => 'LCA', 'MF' => 'MAF',
                'PM' => 'SPM', 'VC' => 'VCT', 'WS' => 'WSM', 'SM' => 'SMR', 'ST' => 'STP',
                'SA' => 'SAU', 'SN' => 'SEN', 'RS' => 'SRB', 'SC' => 'SYC', 'SL' => 'SLE',
                'SG' => 'SGP', 'SX' => 'SXM', 'SK' => 'SVK', 'SI' => 'SVN', 'SB' => 'SLB',
                'SO' => 'SOM', 'ZA' => 'ZAF', 'GS' => 'SGS', 'SS' => 'SSD', 'ES' => 'ESP',
                'LK' => 'LKA', 'SD' => 'SDN', 'SR' => 'SUR', 'SJ' => 'SJM', 'SE' => 'SWE',
                'CH' => 'CHE', 'SY' => 'SYR', 'TW' => 'TWN', 'TJ' => 'TJK', 'TZ' => 'TZA',
                'TH' => 'THA', 'TL' => 'TLS', 'TG' => 'TGO', 'TK' => 'TKL', 'TO' => 'TON',
                'TT' => 'TTO', 'TN' => 'TUN', 'TR' => 'TUR', 'TM' => 'TKM', 'TC' => 'TCA',
                'TV' => 'TUV', 'UG' => 'UGA', 'UA' => 'UKR', 'AE' => 'ARE', 'GB' => 'GBR',
                'UM' => 'UMI', 'US' => 'USA', 'UY' => 'URY', 'UZ' => 'UZB', 'VU' => 'VUT',
                'VE' => 'VEN', 'VN' => 'VNM', 'VG' => 'VGB', 'VI' => 'VIR', 'WF' => 'WLF',
                'EH' => 'ESH', 'YE' => 'YEM', 'ZM' => 'ZMB', 'ZW' => 'ZWE',
            ];

            return $map[strtoupper($alpha2)] ?? strtoupper($alpha2);
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
                    'category'     => [AssetCategory::IDENTITY],
                    'key'          => 'locations',
                    'prefix'       => 'gbp:location',
                    'hostnames'    => [],
                    'url_id_regex' => null,
                    'type'         => 'location',
                ],
            ];
        }

        public static function getPageTypes(): array
        {
            return [];
        }

        public static function getAccountTypes(): array
        {
            return [
                'business_account' => 'Google Business Account',
            ];
        }

        public static function getEntityPaths(): array
        {
            return [__DIR__.'/../Entities'];
        }

        public static function getPages(array $asset): array
        {
            return [];
        }

        public static function getChanneledAccounts(array $asset): array
        {
            $isAccount = isset($asset['locations']) || (!empty($asset['platformId']) && str_contains($asset['platformId'] ?? '', 'accounts/') && !str_contains($asset['platformId'] ?? '', '/locations/'));
            $type = $isAccount ? GoogleEntityType::BUSINESS_ACCOUNT->value : GoogleEntityType::LOCATION->value;

            return [
                [
                    'platformId'        => self::getPlatformId($asset, AssetCategory::IDENTITY, 'google_business_profile'),
                    'platformCreatedAt' => self::getChanneledAccountPlatformCreatedAt($asset),
                    'name'              => self::getChanneledAccountName($asset),
                    'type'              => $type,
                    'account'           => self::getChannelLabel(),
                    'enabled'           => $asset['enabled'] ?? true,
                    'data'              => self::getChanneledAccountData($asset),
                ]
            ];
        }

        public static function getLocations(array $asset): array
        {
            $locations = $asset['locations'] ?? [];

            return array_map(fn(array $loc) => [
                'platformId' => self::getLocationPlatformId($loc),
                'title'      => self::getLocationTitle($loc),
                'storeCode'  => self::getLocationStoreCode($loc),
                'lat'        => self::getLocationLat($loc),
                'lng'        => self::getLocationLng($loc),
                'zipCode'    => self::getLocationZipCode($loc),
                'city'       => self::getLocationCity($loc),
                'state'      => self::getLocationState($loc),
                'country'    => self::getLocationCountry($loc),
                'enabled'    => $loc['enabled'] ?? true,
                'data'       => self::getLocationData($loc),
            ], $locations);
        }

        public function getConfigurationJs(): string
        {
            $file = __DIR__.'/js/GoogleBusinessProfileConfigHandler.js';
            if (file_exists($file)) {
                return file_get_contents($file);
            }

            return "";
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
