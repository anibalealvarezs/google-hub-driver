<?php

    namespace Anibalealvarezs\GoogleHubDriver\Drivers;

    use Anibalealvarezs\ApiDriverCore\Enums\AssetCategory;
    use Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface;
    use Anibalealvarezs\ApiDriverCore\Routes\AssetRoutes;
    use Anibalealvarezs\ApiDriverCore\Traits\SyncDriverTrait;
    use Anibalealvarezs\ApiDriverCore\Classes\MetricProfileTemplates;
    use Anibalealvarezs\ApiDriverCore\Classes\AggregationProfileTemplates;
    use Anibalealvarezs\GoogleHubDriver\Controllers\GoogleAuthController;
    use Anibalealvarezs\GoogleHubDriver\Controllers\ReportController;
    use Anibalealvarezs\GoogleHubDriver\Traits\GoogleSyncDriverTrait;
    use DateTime;
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpFoundation\Response;
    use Anibalealvarezs\ApiDriverCore\Interfaces\SeederInterface;
    use Anibalealvarezs\ApiDriverCore\Interfaces\CanonicalMetricDictionaryProviderInterface;
    use Anibalealvarezs\ApiDriverCore\Interfaces\AggregationProfileProviderInterface;
    use Anibalealvarezs\ApiDriverCore\Interfaces\MetricProfileProviderInterface;
    use Anibalealvarezs\ApiDriverCore\Interfaces\ChanneledAccountableInterface;
    use Anibalealvarezs\GoogleApi\Services\AnalyticsAdmin\AnalyticsAdminApi;
    use Anibalealvarezs\GoogleApi\Services\AnalyticsData\AnalyticsDataApi;
    use Anibalealvarezs\GoogleHubDriver\Enums\GoogleChannel;
    use Anibalealvarezs\GoogleHubDriver\Conversions\GoogleAnalyticsMetricConvert;
    use Anibalealvarezs\GoogleHubDriver\Enums\GoogleEntityType;
    use Anibalealvarezs\ApiDriverCore\Interfaces\PageableInterface;
    use Anibalealvarezs\ApiDriverCore\Helpers\FieldsNormalizerHelper;

    class GoogleAnalyticsDriver implements SyncDriverInterface, CanonicalMetricDictionaryProviderInterface, ChanneledAccountableInterface, PageableInterface, AggregationProfileProviderInterface, MetricProfileProviderInterface
    {
        public const DEFAULT_MAX_WORKERS = 3;

        use SyncDriverTrait, GoogleSyncDriverTrait {
            GoogleSyncDriverTrait::storeCredentials insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::getApi insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::boot insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::getCommonConfigKey insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::getDateFilterMapping insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::getProviderLabel insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::getProviderName insteadof SyncDriverTrait;
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
                ],
                '/ga4-reports'       => [
                    'httpMethod' => 'GET',
                    'callable'   => fn(...$args) => (new ReportController())->ga4($args),
                    'public'     => true,
                    'admin'      => false,
                    'html'       => true
                ]
            ]);
        }

        public static function getRateLimitWhitelist(): array
        {
            return [
                '/ga4-reports',
            ];
        }

        public function fetchAvailableAssets(bool $throwOnError = false): array
        {
            try {
                $creds = $this->resolveGoogleCredentials();
                $api = new AnalyticsAdminApi(
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

                $properties = $api->getProperties();

                $mappedProperties = array_map(function ($property) {
                    // Extract numeric ID from 'properties/123456789'
                    $platformId = str_replace('properties/', '', $property['property'] ?? $property['name']);

                    return [
                        'platformId' => $platformId,
                        'name'       => $property['displayName'] ?? 'Unknown Property',
                        'data'       => $property,
                    ];
                }, $properties);

                $propertyCount = count($mappedProperties);
                if ($propertyCount === 0) {
                    $this->logger?->info("--- INFO: No GA4 properties discovered for this account");
                } else {
                    $this->logger?->info("<<< EXITO: Descubrimiento GA4 completado. Properties: $propertyCount");
                }

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
            $api = $this->initializeApi($config);

            $channeledAccountId = $config['account_id'] ?? null;
            $propertiesToProcess = $config['properties'] ?? $config[GoogleChannel::ANALYTICS->value]['properties'] ?? [];
            $targetAccountId = $config['account_id'] ?? $config['params']['account_id'] ?? null;
            $cleanTargetId = $targetAccountId ? ltrim($targetAccountId, '#') : null;

            $propertyId = null;
            if (!empty($propertiesToProcess)) {
                foreach ($propertiesToProcess as $prop) {
                    $pId = $prop['platformId'] ?? $prop['id'] ?? null;
                    if ($pId && $cleanTargetId && $pId == $cleanTargetId) {
                        if (isset($prop['enabled']) && !$prop['enabled']) {
                            return new Response(json_encode(['status' => 'skipped', 'message' => 'Property is disabled.']));
                        }
                        $propertyId = $pId;
                        break;
                    }
                }
            }

            if (!$propertyId) {
                $propertyId = $config['platform_id'] ?? $targetAccountId ?? null;
            }

            if (!$propertyId) {
                return new Response(json_encode(['error' => 'Property ID is required']));
            }

            $entities = [];
            $startDateStr = $startDate->format('Y-m-d');
            $endDateStr = $endDate->format('Y-m-d');
            
            $targetEntity = $config['entity'] ?? null;
            $level = $config['level'] ?? null;
            
            $syncCampaigns = ($targetEntity === 'entities' || in_array($targetEntity, ['traffic_matrix', 'event_matrix', 'acquisition_matrix']) || in_array($level, ['traffic_matrix', 'event_matrix', 'acquisition_matrix']));
            $syncPages = ($targetEntity === 'entities' || in_array($targetEntity, ['traffic_matrix', 'event_matrix']) || in_array($level, ['traffic_matrix', 'event_matrix']));
            $syncEvents = ($targetEntity === 'entities' || in_array($targetEntity, ['event_matrix']) || in_array($level, ['event_matrix']));
            $syncCountries = ($targetEntity === 'entities' || in_array($targetEntity, ['traffic_matrix', 'event_matrix']) || in_array($level, ['traffic_matrix', 'event_matrix']));
            $syncDevices = ($targetEntity === 'entities' || in_array($targetEntity, ['traffic_matrix', 'event_matrix']) || in_array($level, ['traffic_matrix', 'event_matrix']));
            $syncAdGroups = ($targetEntity === 'entities' || in_array($targetEntity, ['traffic_matrix', 'event_matrix', 'ad_touchpoint_matrix', 'acquisition_matrix']) || in_array($level, ['traffic_matrix', 'event_matrix', 'ad_touchpoint_matrix', 'acquisition_matrix']));
            $syncAds = ($targetEntity === 'entities' || in_array($targetEntity, ['traffic_matrix', 'event_matrix', 'ad_touchpoint_matrix', 'acquisition_matrix']) || in_array($level, ['traffic_matrix', 'event_matrix', 'ad_touchpoint_matrix', 'acquisition_matrix']));

            try {
                $this->logger?->info(">>> INICIO: Sincronizando Entidades GA4 para Property: $propertyId (Timeframe: $startDateStr a $endDateStr)");
                $channeledAccount = $config['channeledAccount'] ?? null;

                // --- CAMPAIGNS ---
                if ($syncCampaigns) {
                    $campaignResponse = $api->runSimpleReport(
                        propertyId: $propertyId,
                        metrics: ['activeUsers'],
                        dimensions: ['sessionCampaignName'],
                        startDate: $startDateStr,
                        endDate: $endDateStr
                    );

                    $processedCampaigns = GoogleAnalyticsMetricConvert::preprocessRows($campaignResponse);
                    $buffer = new \Doctrine\Common\Collections\ArrayCollection();

                    foreach ($processedCampaigns as $row) {
                        if (!self::isJunkGoogleDimension($row['sessionCampaignName'] ?? null)) {
                            $entities[] = [
                                'platformId' => $row['sessionCampaignName'],
                                'name'       => $row['sessionCampaignName'],
                                'type'       => 'campaign'
                            ];

                            $item = new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity();
                            $item->setChannel(GoogleChannel::ANALYTICS->value);
                            $item->setPlatformId($row['sessionCampaignName'])
                                 ->setTitle($row['sessionCampaignName'])
                                 ->setContext([
                                     'channeledAccount' => $channeledAccount ?? (new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity())->setPlatformId($propertyId),
                                     'channeledAccountId' => $propertyId
                                 ]);
                            $item->name = $row['sessionCampaignName'];
                            $buffer->add($item);
                        }
                    }

                    if ($this->dataProcessor && $buffer->count() > 0) {
                        ($this->dataProcessor)($buffer, 'campaign');
                    }
                    $this->logger?->info("<<< EXITO: Sincronización Entidades GA4 (Campaigns): " . $buffer->count());
                }

                // --- COUNTRIES ---
                if ($syncCountries) {
                    $countryResponse = $api->runSimpleReport(
                        propertyId: $propertyId,
                        metrics: ['activeUsers'],
                        dimensions: ['countryId'],
                        startDate: $startDateStr,
                        endDate: $endDateStr
                    );

                    $processedCountries = GoogleAnalyticsMetricConvert::preprocessRows($countryResponse);
                    $buffer = new \Doctrine\Common\Collections\ArrayCollection();

                    foreach ($processedCountries as $row) {
                        if (!empty($row['country']) && $row['country'] !== '(not set)') {
                            $entities[] = [
                                'platformId' => $row['country'],
                                'name'       => $row['country'],
                                'type'       => 'country'
                            ];

                            $item = new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity();
                            $item->setChannel(GoogleChannel::ANALYTICS->value);
                            $item->setPlatformId($row['country'])
                                 ->setTitle($row['country'])
                                 ->setContext([
                                     'channeledAccount' => $channeledAccount ?? (new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity())->setPlatformId($propertyId),
                                     'channeledAccountId' => $propertyId
                                 ]);
                            $item->name = $row['country'];
                            $buffer->add($item);
                        }
                    }

                    if ($this->dataProcessor && $buffer->count() > 0) {
                        ($this->dataProcessor)($buffer, 'country');
                    }
                    $this->logger?->info("<<< EXITO: Sincronización Entidades GA4 (Countries): " . $buffer->count());
                }

                // --- DEVICES ---
                if ($syncDevices) {
                    $deviceResponse = $api->runSimpleReport(
                        propertyId: $propertyId,
                        metrics: ['activeUsers'],
                        dimensions: ['deviceCategory'],
                        startDate: $startDateStr,
                        endDate: $endDateStr
                    );

                    $processedDevices = GoogleAnalyticsMetricConvert::preprocessRows($deviceResponse);
                    $buffer = new \Doctrine\Common\Collections\ArrayCollection();

                    foreach ($processedDevices as $row) {
                        if (!empty($row['device']) && $row['device'] !== '(not set)') {
                            $entities[] = [
                                'platformId' => $row['device'],
                                'name'       => $row['device'],
                                'type'       => 'device'
                            ];

                            $item = new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity();
                            $item->setChannel(GoogleChannel::ANALYTICS->value);
                            $item->setPlatformId($row['device'])
                                 ->setTitle($row['device'])
                                 ->setContext([
                                     'channeledAccount' => $channeledAccount ?? (new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity())->setPlatformId($propertyId),
                                     'channeledAccountId' => $propertyId
                                 ]);
                            $item->name = $row['device'];
                            $buffer->add($item);
                        }
                    }

                    if ($this->dataProcessor && $buffer->count() > 0) {
                        ($this->dataProcessor)($buffer, 'device');
                    }
                    $this->logger?->info("<<< EXITO: Sincronización Entidades GA4 (Devices): " . $buffer->count());
                }

                // --- AD GROUPS ---
                if ($syncAdGroups) {
                    $googleAdsAdGroupResponse = $api->runSimpleReport(
                        propertyId: $propertyId,
                        metrics: ['activeUsers'],
                        dimensions: ['sessionCampaignName', 'sessionGoogleAdsAdGroupName'],
                        startDate: $startDateStr,
                        endDate: $endDateStr
                    );

                    $manualAdGroupResponse = $api->runSimpleReport(
                        propertyId: $propertyId,
                        metrics: ['activeUsers'],
                        dimensions: ['sessionCampaignName', 'sessionManualTerm'],
                        startDate: $startDateStr,
                        endDate: $endDateStr
                    );

                    $processedAdGroups = array_merge(
                        GoogleAnalyticsMetricConvert::preprocessRows($googleAdsAdGroupResponse),
                        GoogleAnalyticsMetricConvert::preprocessRows($manualAdGroupResponse)
                    );

                    $buffer = new \Doctrine\Common\Collections\ArrayCollection();
                    $seenAdGroups = [];

                    foreach ($processedAdGroups as $row) {
                        $campaignName = $row['sessionCampaignName'] ?? null;
                        if (self::isJunkGoogleDimension($campaignName)) {
                            continue; // Hierarchical enforcement: require valid campaign
                        }

                        $adGroupName = $row['sessionGoogleAdsAdGroupName'] ?? $row['sessionManualTerm'] ?? null;
                        
                        if (!self::isJunkGoogleDimension($adGroupName) && !isset($seenAdGroups[$adGroupName])) {
                            $seenAdGroups[$adGroupName] = true;
                            $entities[] = [
                                'platformId' => $adGroupName,
                                'name'       => $adGroupName,
                                'type'       => 'ad_group'
                            ];

                            $item = new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity();
                            $item->setChannel(GoogleChannel::ANALYTICS->value);
                            $item->setPlatformId($adGroupName)
                                 ->setTitle($adGroupName)
                                 ->setContext([
                                     'channeledAccount' => $channeledAccount ?? (new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity())->setPlatformId($propertyId),
                                     'channeledAccountId' => $propertyId,
                                     'channeledCampaignId' => $campaignName
                                 ]);
                            $item->name = $adGroupName;
                            $buffer->add($item);
                        }
                    }

                    if ($this->dataProcessor && $buffer->count() > 0) {
                        ($this->dataProcessor)($buffer, 'ad_group');
                    }
                    $this->logger?->info("<<< EXITO: Sincronización Entidades GA4 (AdGroups): " . $buffer->count());
                }

                // --- ADS ---
                if ($syncAds) {
                    $adResponse = $api->runSimpleReport(
                        propertyId: $propertyId,
                        metrics: ['activeUsers'],
                        dimensions: ['sessionCampaignName', 'sessionManualAdContent'],
                        startDate: $startDateStr,
                        endDate: $endDateStr
                    );

                    $processedAds = GoogleAnalyticsMetricConvert::preprocessRows($adResponse);
                    $buffer = new \Doctrine\Common\Collections\ArrayCollection();

                    foreach ($processedAds as $row) {
                        $campaignName = $row['sessionCampaignName'] ?? null;
                        if (self::isJunkGoogleDimension($campaignName)) {
                            continue; // Hierarchical enforcement: require valid campaign
                        }

                        if (!self::isJunkGoogleDimension($row['sessionManualAdContent'] ?? null)) {
                            $entities[] = [
                                'platformId' => $row['sessionManualAdContent'],
                                'name'       => $row['sessionManualAdContent'],
                                'type'       => 'ad'
                            ];

                            $item = new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity();
                            $item->setChannel(GoogleChannel::ANALYTICS->value);
                            $item->setPlatformId($row['sessionManualAdContent'])
                                 ->setTitle($row['sessionManualAdContent'])
                                 ->setContext([
                                     'channeledAccount' => $channeledAccount ?? (new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity())->setPlatformId($propertyId),
                                     'channeledAccountId' => $propertyId,
                                     'channeledCampaignId' => $campaignName
                                 ]);
                            $item->name = $row['sessionManualAdContent'];
                            $buffer->add($item);
                        }
                    }

                    if ($this->dataProcessor && $buffer->count() > 0) {
                        ($this->dataProcessor)($buffer, 'ad');
                    }
                    $this->logger?->info("<<< EXITO: Sincronización Entidades GA4 (Ads): " . $buffer->count());
                }

                // --- PAGES (BaseURL) ---
                if ($syncPages) {
                    $adminApi = $this->initializeAdminApi($config);
                    
                    $dataStreams = $adminApi->getDataStreams($propertyId);
                    $buffer = new \Doctrine\Common\Collections\ArrayCollection();
                    
                    foreach ($dataStreams as $stream) {
                        if (isset($stream['webStreamData']['defaultUri'])) {
                            $baseUrl = $stream['webStreamData']['defaultUri'];
                            $hostname = parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;
                            $canonicalId = 'ga4:domain:' . str_replace('www.', '', $hostname);
                            
                            $item = new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity();
                            $item->setChannel(GoogleChannel::ANALYTICS->value);
                            $item->setPlatformId($baseUrl)
                                 ->setCanonicalId($canonicalId)
                                 ->setHostname($hostname)
                                 ->setTitle($baseUrl)
                                 ->setUrl($baseUrl)
                                 ->setContext([
                                     'channeledAccount' => $channeledAccount ?? (new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity())->setPlatformId($propertyId),
                                     'channeledAccountId' => $propertyId
                                 ]);
                            $item->name = $baseUrl;
                            $buffer->add($item);
                        }
                    }
                    
                    if ($this->dataProcessor && $buffer->count() > 0) {
                        ($this->dataProcessor)($buffer, 'page');
                    }
                    $this->logger?->info("<<< EXITO: Sincronización Entidades GA4 (Pages): " . $buffer->count());
                }



                // --- CONVERSION EVENTS ---
                if ($syncEvents) {
                    $eventResponse = $api->runSimpleReport(
                        propertyId: $propertyId,
                        metrics: ['conversions'],
                        dimensions: ['eventName'],
                        startDate: $startDateStr,
                        endDate: $endDateStr
                    );

                    $processedEvents = GoogleAnalyticsMetricConvert::preprocessRows($eventResponse);
                    $buffer = new \Doctrine\Common\Collections\ArrayCollection();

                    foreach ($processedEvents as $row) {
                        if (!empty($row['eventName']) && !empty($row['conversions']) && (int)$row['conversions'] > 0) {
                            $sourceKey = $row['eventName'];
                            $unifiedKey = \Anibalealvarezs\ApiDriverCore\Classes\GlobalEventDictionary::getGlobalKey($sourceKey, 'google_analytics');
                            
                            $entities[] = [
                                'platformId' => $sourceKey,
                                'name'       => $unifiedKey,
                                'type'       => 'event'
                            ];

                            $item = new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity();
                            $item->setChannel(GoogleChannel::ANALYTICS->value);
                            $item->setPlatformId($sourceKey)
                                 ->setTitle($unifiedKey)
                                 ->setData(['source_key' => $sourceKey])
                                 ->setContext([
                                     'channeledAccount' => $channeledAccount ?? (new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity())->setPlatformId($propertyId),
                                     'channeledAccountId' => $propertyId
                                 ]);
                            $item->name = $unifiedKey;
                            $buffer->add($item);
                        }
                    }

                    if ($this->dataProcessor && $buffer->count() > 0) {
                        ($this->dataProcessor)($buffer, 'event');
                    }
                    $this->logger?->info("<<< EXITO: Sincronización Entidades GA4 (Events): " . $buffer->count());
                }

            } catch (\Exception $e) {
                $this->logger?->error("GA4 Entity Sync Error: ".$e->getMessage());

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
            if (isset($config['entity']) && $config['entity'] === 'entities') {
                return $this->syncEntities($startDate, $endDate, $config, $shouldContinue, $identityMapper);
            }

            $api = $this->initializeApi($config);

            $channeledAccount = $config['channeledAccount'] ?? null;
            $propertiesToProcess = $config['properties'] ?? $config[GoogleChannel::ANALYTICS->value]['properties'] ?? [];
            $targetAccountId = $config['account_id'] ?? $config['params']['account_id'] ?? null;
            $cleanTargetId = $targetAccountId ? ltrim($targetAccountId, '#') : null;

            $propertyId = null;
            if (!empty($propertiesToProcess)) {
                foreach ($propertiesToProcess as $prop) {
                    $pId = $prop['platformId'] ?? $prop['id'] ?? null;
                    if ($pId && $cleanTargetId && $pId == $cleanTargetId) {
                        if (isset($prop['enabled']) && !$prop['enabled']) {
                            return new Response(json_encode(['status' => 'skipped', 'message' => 'Property is disabled.']));
                        }
                        $propertyId = $pId;
                        break;
                    }
                }
            }

            // Fallback for cases where properties aren't explicitly passed but an account_id is
            if (!$propertyId) {
                $propertyId = $config['platform_id'] ?? $targetAccountId ?? null;
            }

            if (!$propertyId) {
                return new Response(json_encode(['error' => 'Property ID is required']));
            }
            
            $requestedLevel = $config['level'] ?? 'all';
            $levelsToProcess = $requestedLevel === 'all' 
                ? ['account', 'traffic_matrix', 'event_matrix', 'acquisition_matrix', 'ad_touchpoint_matrix']
                : [$requestedLevel];

            $totalMetricsSyncedAllLevels = 0;
            $allMetricsCollection = new \Doctrine\Common\Collections\ArrayCollection();

            foreach ($levelsToProcess as $level) {
                $defaultMetrics = match ($level) {
                    'event_matrix' => ['eventCount', 'conversions'],
                    'traffic_matrix' => ['screenPageViews', 'sessions', 'bounceRate', 'totalRevenue', 'conversions'],
                    'acquisition_matrix' => ['newUsers', 'activeUsers'],
                    'ad_touchpoint_matrix' => ['sessions', 'conversions'],
                    default => ['screenPageViews', 'sessions', 'bounceRate', 'totalRevenue']
                };

                $metricsList = $config['metrics'] ?? $defaultMetrics;

                $dimensions = match ($level) {
                    'traffic_matrix' => ['date', 'sessionDefaultChannelGroup', 'sessionSourceMedium', 'sessionCampaignName', 'sessionGoogleAdsAdGroupName', 'deviceCategory', 'countryId', 'landingPagePlusQueryString'],
                    'event_matrix' => ['date', 'eventName', 'pagePath', 'sessionDefaultChannelGroup', 'sessionSourceMedium', 'sessionCampaignName', 'sessionGoogleAdsAdGroupName', 'sessionManualTerm', 'sessionManualAdContent'],
                    'acquisition_matrix' => ['date', 'firstUserDefaultChannelGroup', 'firstUserSourceMedium', 'firstUserCampaignName', 'firstUserGoogleAdsAdGroupName', 'firstUserManualTerm', 'firstUserManualAdContent'],
                    'ad_touchpoint_matrix' => ['date', 'sessionCampaignName', 'sessionGoogleAdsAdGroupName', 'sessionManualTerm', 'sessionManualAdContent'],
                    default => ['date']
                };

                $startDateStr = $startDate->format('Y-m-d');
                $endDateStr = $endDate->format('Y-m-d');

                $this->logger?->info("");
                $this->logger?->info("========================================================================");
                $this->logger?->info("========== START MATRIX SYNC PROCESS: " . strtoupper($level) . " ==========");
                $this->logger?->info("========================================================================");

                if (in_array($level, ['traffic_matrix', 'event_matrix', 'acquisition_matrix', 'ad_touchpoint_matrix'])) {
                    $this->logger?->info(">>> Sincronización automática de Entidades ($level) previo a la sincronización de métricas...");
                    $configForEntities = $config;
                    $configForEntities['level'] = $level; // Pass current level to syncEntities
                    $this->syncEntities($startDate, $endDate, $configForEntities, $shouldContinue, $identityMapper);
                }

                try {
                    $this->logger?->info(">>> INICIO: Sincronizando GA4 para Property: $propertyId (Level: $level | Timeframe: $startDateStr a $endDateStr)");

                    $this->logger?->info(">>> Solicitando métricas: " . implode(', ', $metricsList) . " | Dimensiones: " . implode(', ', $dimensions));

                    $payload = [
                        'dateRanges' => [['startDate' => $startDateStr, 'endDate' => $endDateStr]],
                        'dimensions' => array_map(fn($d) => ['name' => $d], $dimensions),
                        'metrics'    => array_map(fn($m) => ['name' => $m], $metricsList),
                    ];

                    $totalMetricsCount = 0;
                    
                    $api->runAllReportsAndProcess($propertyId, $payload, function ($response) use ($propertyId, $channeledAccount, $config, $level, &$totalMetricsCount, $metricsList) {
                        $rows = $response['rows'] ?? [];
                        if ($totalMetricsCount === 0 && count($rows) > 0) {
                            $this->logger?->info(">>> Primera fila cruda devuelta por GA4 ($level): " . json_encode($rows[0]));
                        }

                        $chunkResponse = $response;
                        $chunkResponse['property_id'] = $propertyId;

                        $chunkCollection = GoogleAnalyticsMetricConvert::metrics(
                            response: $chunkResponse,
                            channeledAccount: $channeledAccount ?? $config['account_id'] ?? '',
                            level: $level,
                            logger: $this->logger,
                            account: $config['account'] ?? null,
                            metricsToProcess: $metricsList
                        );

                        $chunkCount = $chunkCollection->count() ?? 0;
                        $totalMetricsCount += $chunkCount;

                        if ($chunkCount > 0 && isset($this->dataProcessor) && is_callable($this->dataProcessor)) {
                            ($this->dataProcessor)($chunkCollection, $this->logger);
                        }
                    });

                    $totalMetricsSyncedAllLevels += $totalMetricsCount;

                    if ($totalMetricsCount === 0) {
                        $this->logger?->info("--- INFO: No se encontraron datos GA4 para Property: $propertyId (Level: $level)");
                    } else {
                        $this->logger?->info("<<< EXITO: Sincronización completada para Property: $propertyId (Level: $level). Métricas: $totalMetricsCount");
                    }

                    $this->logger?->info("========================================================================");
                    $this->logger?->info("========== END MATRIX SYNC PROCESS: " . strtoupper($level) . " ==========");
                    $this->logger?->info("========================================================================");
                    $this->logger?->info("");

                } catch (\Exception $e) {
                    $this->logger?->error("GA4 Metrics Sync Error at level $level: ".$e->getMessage());
                    // Don't break entirely, try the next matrix
                }
            }

            return new Response(json_encode([
                'status'  => 'success',
                'metrics_synced' => $totalMetricsSyncedAllLevels
            ]));
        }

        public function getConfigSchema(): array
        {
            return [
                'global'   => [
                    'enabled'             => false,
                    'max_workers'         => self::DEFAULT_MAX_WORKERS,
                    'cache_history_range' => '30 days',
                    'cache_aggregations'  => false,
                    'metrics_strategy'    => 'default',
                ],
                'PROPERTY' => [
                    'platformId'           => '',
                    'name'                 => '',
                    'enabled'              => true,
                    'exclude_from_caching' => false,
                    'lost_access'          => false,
                ],
                'entity'   => [
                    'platformId'           => '',
                    'name'                 => '',
                    'enabled'              => true,
                    'exclude_from_caching' => false,
                    'lost_access'          => false,
                ],
                'metrics'  => [
                    'sessions'               => ['enabled' => false, 'format' => 'number', 'precision' => 0],
                    'totalUsers'             => ['enabled' => false, 'format' => 'number', 'precision' => 0],
                    'activeUsers'            => ['enabled' => false, 'format' => 'number', 'precision' => 0],
                    'newUsers'               => ['enabled' => false, 'format' => 'number', 'precision' => 0],
                    'screenPageViews'        => ['enabled' => false, 'format' => 'number', 'precision' => 0],
                    'bounceRate'             => ['enabled' => false, 'format' => 'percent', 'precision' => 2, 'sparkline_direction' => 'inverted'],
                    'averageSessionDuration' => ['enabled' => false, 'format' => 'number', 'precision' => 2],
                    'conversions'            => ['enabled' => false, 'format' => 'number', 'precision' => 2],
                    'totalRevenue'           => ['enabled' => false, 'format' => 'currency', 'precision' => 2],
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
                GoogleEntityType::LOCATION->value => [
                    'category'          => [AssetCategory::IDENTITY],
                    'key'               => 'properties',
                    'channeled_account' => [
                        'platform_id'             => [
                            'type' => 'raw',
                            'key'  => 'platformId'
                        ],
                        'platform_created_at_key' => 'createTime',
                        'name_key'                => 'name',
                        'type'                    => 'google_analytics_property',
                        'data_key'                => 'data'
                    ]
                ],
                GoogleEntityType::EVENT->value => [
                    'category'          => [AssetCategory::EVENT],
                    'key'               => 'events',
                    'channeled_account' => [
                        'platform_id'             => [
                            'type' => 'raw',
                            'key'  => 'platformId'
                        ],
                        'name_key'                => 'name',
                        'type'                    => 'google_analytics_event',
                        'data_key'                => 'data'
                    ]
                ]
            ];
        }

        public static function getCanonicalMetricDictionary(): array
        {
            return [
                'conversions' => ['conversions'],
                'reach'       => ['activeUsers', 'reach'],
                'impressions' => ['screenPageViews', 'impressions'],
                'sessions'    => ['sessions'],
                'new_users'   => ['newUsers'],
                'spend'       => ['totalRevenue', 'spend'],
                'revenue'     => ['totalRevenue', 'revenue'],
            ];
        }

        public static function getMetricProfiles(): array
        {
            return [
                MetricProfileTemplates::pageTotals(
                    channel: 'google_analytics',
                    key: 'google_analytics_property',
                    label: 'Google Analytics Property'
                ),
                MetricProfileTemplates::campaignBreakdown(
                    channel: 'google_analytics',
                    key: 'google_analytics_campaign',
                    label: 'Google Analytics Campaign'
                ),
                [
                    'key' => 'google_analytics_page',
                    'channel' => 'google_analytics',
                    'label' => 'Google Analytics Page',
                    'metric_config' => [
                        'required_fields' => ['account', 'channeledAccount', 'page', 'dimensionSet', 'channel', 'name', 'period'],
                        'common_filters' => ['page', 'name', 'period'],
                        'groupable_fields' => ['page'],
                        'index_hints' => [
                            ['channel', 'name', 'period', 'page'],
                            ['channel', 'page', 'dimensionSet', 'name', 'id'],
                        ],
                    ],
                ],
                [
                    'key' => 'google_analytics_event',
                    'channel' => 'google_analytics',
                    'label' => 'Google Analytics Event',
                    'metric_config' => [
                        'required_fields' => ['account', 'channeledAccount', 'event', 'dimensionSet', 'channel', 'name', 'period'],
                        'common_filters' => ['event', 'name', 'period'],
                        'groupable_fields' => ['event'],
                        'index_hints' => [
                            ['channel', 'name', 'period', 'event'],
                        ],
                    ],
                ]
            ];
        }

        public static function getAggregationProfiles(): array
        {
            return [
                [
                    'key' => 'ga4_universal_matrix',
                    'channel' => 'google_analytics',
                    'label' => 'GA4 Universal Matrix',
                    'asset_type' => 'account',
                    'metric_nature' => 'flow',
                    'period_modes' => ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'],
                    'group_patterns' => [
                        ['channeledAccount'],
                        ['channeledAccount', 'metricDate'],
                        ['channeledCampaign'],
                        ['channeledCampaign.title'],
                        ['dimensions.landing_page'],
                        ['event.title'],
                        ['country.name'],
                        ['device.name'],
                        ['channeledCampaign.title', 'metricDate'],
                        ['dimensions.landing_page', 'metricDate'],
                        ['event.title', 'metricDate'],
                        ['country.name', 'metricDate'],
                        ['device.name', 'metricDate'],
                        // UI Matrix Dimensions
                        ['dimensions.sessionDefaultChannelGroup'],
                        ['dimensions.sessionCampaignName'],
                        ['dimensions.landingPagePlusQueryString'],
                        ['dimensions.countryId'],
                        ['dimensions.deviceCategory'],
                        ['dimensions.sessionGoogleAdsAdGroupName'],
                        ['dimensions.sessionManualTerm'],
                        ['dimensions.sessionManualAdContent'],
                        ['dimensions.firstUserDefaultChannelGroup'],
                        ['dimensions.firstUserCampaignName'],
                        ['dimensions.firstUserSourceMedium'],
                        ['dimensions.eventName'],
                        ['dimensions.sessionSourceMedium']
                    ],
                    'filter_contract' => [
                        'channeledAccount' => ['=', 'in'],
                        'dimensions.scope' => ['='],
                        'metricDate' => ['between', '>=', '<='],
                    ],
                    'reducer_strategies' => [
                        '*' => 'sum'
                    ]
                ],
                AggregationProfileTemplates::organicPageFlowProfile(
                    channel: 'google_analytics',
                    key: 'google_analytics_property_flow',
                    label: 'Google Analytics Property Flow',
                    overrides: [
                        'asset_type' => 'account',
                    ]
                ),
                AggregationProfileTemplates::flowCampaignProfile(
                    channel: 'google_analytics',
                    key: 'google_analytics_campaign_flow',
                    label: 'Google Analytics Campaign Flow'
                ),
            ];
        }

        private static function isJunkGoogleDimension(?string $name): bool
        {
            if (empty($name)) return true;
            
            // Exclude exact matches for old hardcoded garbage
            if (in_array($name, ['(not set)', '(direct)', '(organic)', '(not provided)'])) return true;
            
            // Exclude GA4 system buckets: (referral), (cross-network), (ai-assistant), etc.
            if (preg_match('/^\([a-z\- ]+\)$/', $name)) return true;
            
            // Exclude bare domains acting as placeholders (e.g., leadsgo.io, facebook.com)
            // Regex matches domains with optional trailing path. Ex: example.com, test.co.uk/path
            if (preg_match('/^(?:[a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}(?:\/.*)?$/', $name)) return true;

            // Exclude known internal or auto-generated system prefixes
            if (str_starts_with($name, 'as-npc')) return true;

            return false;
        }

        // PAGE FIELDS

        public static function getPagePlatformId(array $asset, ?string $key = null): string
        {
            $idKey = $key ?: 'platformId';
            return isset($asset[$idKey]) && $asset[$idKey] ? FieldsNormalizerHelper::getCleanString($asset[$idKey]) : '';
        }

        public static function getPageCanonicalId(array $asset, ?string $key = null): string
        {
            $idKey = $key ?: 'canonicalId';
            if (isset($asset[$idKey]) && $asset[$idKey]) {
                return FieldsNormalizerHelper::getCleanString($asset[$idKey]);
            }
            return 'ga4:domain:'.self::getPageHostname($asset, $key);
        }

        public static function getPageHostname(array $asset, ?string $key = null): string
        {
            $idKey = $key ?: 'hostname';
            return isset($asset[$idKey]) && $asset[$idKey] ? FieldsNormalizerHelper::getCleanString($asset[$idKey]) : '';
        }

        public static function getPageTitle(array $asset, ?string $key = null): string
        {
            $idKey = $key ?: 'title';
            return isset($asset[$idKey]) && $asset[$idKey] ? FieldsNormalizerHelper::getCleanString($asset[$idKey]) : '';
        }

        public static function getPageUrl(array $asset, ?string $key = null): string
        {
            $idKey = $key ?: 'url';
            return isset($asset[$idKey]) && $asset[$idKey] ? FieldsNormalizerHelper::getCleanString($asset[$idKey]) : '';
        }

        public static function getPageData(array $asset, ?string $key = null): array
        {
            $idKey = $key ?: 'data';
            return isset($asset[$idKey]) && $asset[$idKey] ? FieldsNormalizerHelper::getCleanArray($asset[$idKey]) : [];
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

        public static function getChanneledAccounts(array $asset): array
        {
            return [
                [
                    'platformId'        => self::getChanneledAccountPlatformId($asset),
                    'platformCreatedAt' => self::getChanneledAccountPlatformCreatedAt($asset),
                    'name'              => self::getChanneledAccountName($asset),
                    'type'              => self::getChanneledAccountType(),
                    'enabled'           => filter_var($asset['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'data'              => self::getChanneledAccountData($asset)
                ]
            ];
        }

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
            $file = __DIR__.'/js/GoogleAnalyticsConfigHandler.js';
            if (file_exists($file)) {
                return file_get_contents($file);
            }

            return "";
        }

        protected function initializeApi(array $config): AnalyticsDataApi
        {
            $creds = $this->resolveGoogleCredentials($config);

            return new AnalyticsDataApi(
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
        }

        protected function initializeAdminApi(array $config): AnalyticsAdminApi
        {
            $creds = $this->resolveGoogleCredentials($config);

            return new AnalyticsAdminApi(
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
        }
    }
