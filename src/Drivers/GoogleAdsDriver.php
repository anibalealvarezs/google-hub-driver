<?php

    namespace Anibalealvarezs\GoogleHubDriver\Drivers;

    use Anibalealvarezs\ApiDriverCore\Classes\AggregationProfileTemplates;
    use Anibalealvarezs\ApiDriverCore\Classes\MetricProfileTemplates;
    use Anibalealvarezs\ApiDriverCore\Helpers\FieldsNormalizerHelper;
    use Anibalealvarezs\ApiDriverCore\Interfaces\AggregationProfileProviderInterface;
    use Anibalealvarezs\ApiDriverCore\Interfaces\CanonicalMetricDictionaryProviderInterface;
    use Anibalealvarezs\ApiDriverCore\Interfaces\MetricProfileProviderInterface;
    use Anibalealvarezs\ApiDriverCore\Traits\HasHierarchicalValidationTrait;
    use Anibalealvarezs\ApiDriverCore\Interfaces\ChanneledAccountableInterface;
    use Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface;
    use Anibalealvarezs\ApiDriverCore\Traits\SyncDriverTrait;
    use Anibalealvarezs\GoogleHubDriver\Traits\GoogleAdsSyncDriverTrait;
    use Anibalealvarezs\GoogleHubDriver\Traits\GoogleSyncDriverTrait;
    use Anibalealvarezs\ApiDriverCore\Enums\AssetCategory;
    use Anibalealvarezs\GoogleApi\Services\GoogleAds\GoogleAdsApi;
    use Anibalealvarezs\GoogleHubDriver\Conversions\GoogleAdsMetricConvert;
    use Anibalealvarezs\ApiDriverCore\Services\ConfigSchemaRegistryService;
    use Anibalealvarezs\ApiDriverCore\Helpers\DateHelper;
    use GuzzleHttp\Exception\GuzzleException;
    use Symfony\Component\HttpFoundation\Response;
    use DateTime;
    use Exception;
    use Psr\Log\LoggerInterface;

    class GoogleAdsDriver implements SyncDriverInterface, ChanneledAccountableInterface, MetricProfileProviderInterface, AggregationProfileProviderInterface, CanonicalMetricDictionaryProviderInterface
    {
        use HasHierarchicalValidationTrait;
        use SyncDriverTrait, GoogleSyncDriverTrait, GoogleAdsSyncDriverTrait {
            GoogleAdsSyncDriverTrait::getApi insteadof GoogleSyncDriverTrait;
            GoogleAdsSyncDriverTrait::initializeApi insteadof GoogleSyncDriverTrait;
            GoogleAdsSyncDriverTrait::getProviderLabel insteadof GoogleSyncDriverTrait, SyncDriverTrait;
            GoogleAdsSyncDriverTrait::getProviderName insteadof GoogleSyncDriverTrait, SyncDriverTrait;
            GoogleAdsSyncDriverTrait::getCommonConfigKey insteadof GoogleSyncDriverTrait, SyncDriverTrait;
            GoogleAdsSyncDriverTrait::getEnvMapping insteadof GoogleSyncDriverTrait, SyncDriverTrait;
            GoogleAdsSyncDriverTrait::isAuthenticationError insteadof GoogleSyncDriverTrait;
            GoogleSyncDriverTrait::storeCredentials insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::boot insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::reset insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::getDateFilterMapping insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::validateConfig insteadof SyncDriverTrait;
        }

        public array $updatableCredentials = [
            'GOOGLE_REFRESH_TOKEN',
            'GOOGLE_USER_ID',
            'GOOGLE_CLIENT_ID',
            'GOOGLE_CLIENT_SECRET',
            'GOOGLE_ADS_DEVELOPER_TOKEN',
            'GOOGLE_ADS_LOGIN_CUSTOMER_ID'
        ];

        private const int DEFAULT_MAX_WORKERS = 2;

        public static function getMetricProfiles(): array
        {
            return [
                MetricProfileTemplates::campaignBreakdown(
                    channel: 'google_ads',
                    key: 'google_ads_campaign',
                    label: 'Google Ads Campaign'
                ),
                MetricProfileTemplates::adGroupBreakdown(
                    channel: 'google_ads',
                    key: 'google_ads_ad_group',
                    label: 'Google Ads Ad Group'
                ),
                MetricProfileTemplates::adCreativeBreakdown(
                    channel: 'google_ads',
                    key: 'google_ads_ad',
                    label: 'Google Ads Ad'
                ),
            ];
        }

        public static function getAggregationProfiles(): array
        {
            return [
                AggregationProfileTemplates::adsHierarchyProfile(
                    channel: 'google_ads',
                    key: 'google_ads_hierarchy',
                    label: 'Google Ads Hierarchy'
                ),
            ];
        }

        public static function getCanonicalMetricDictionary(): array
        {
            return [
                'spend'               => ['spend'],
                'clicks'              => ['clicks'],
                'impressions'         => ['impressions'],
                'conversions'         => ['conversions'],
                'roas_purchase'       => ['conversions_value'],
                'cost_per_conversion' => ['cost_per_conversion'],
            ];
        }

        public static function getPlatformEntityIdField(): string
        {
            return 'customer_id';
        }

        public static function getDefaultMaxWorkers(): int
        {
            return self::DEFAULT_MAX_WORKERS;
        }

        public static function getPublicResources(): array
        {
            return ['metrics' => 'gads_metrics', 'campaigns' => 'gads_campaigns'];
        }

        public static function getChannelLabel(): string
        {
            return 'Google Ads';
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

        public function getChannel(): string
        {
            return 'google_ads';
        }

        public function validateAuthentication(): array
        {
            if (!$this->authProvider || !$this->authProvider->hasCredentials()) {
                return [
                    'success' => false,
                    'message' => 'Credentials not configured.',
                    'details' => []
                ];
            }

            return [
                'success' => true,
                'message' => 'Authentication is valid.',
                'details' => []
            ];
        }

        public function updateConfiguration(array $newData, array $currentConfig): array
        {
            $selectedAssets = $newData['assets']['google_ads'] ?? [];
            $enabled = $newData['enabled'] ?? false;
            $historyRange = $newData['cache_history_range'] ?? null;
            $featureToggles = $newData['feature_toggles'] ?? [];

            if (!isset($currentConfig['channels']['google_ads'])) {
                $currentConfig['channels']['google_ads'] = [];
            }

            $chanCfg = &$currentConfig['channels']['google_ads'];

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

            $newAccountsList = [];
            foreach ($selectedAssets as $pData) {
                $item = [
                    'id'          => $pData['id'] ?? null,
                    'name'        => $pData['name'] ?? null,
                    'enabled'     => filter_var($pData['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'lost_access' => filter_var($pData['lost_access'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'data'        => $pData['data'] ?? [],
                ];
                $newAccountsList[] = $item;
            }
            $chanCfg['ad_accounts'] = $newAccountsList;

            return $currentConfig;
        }

        /**
         * @inheritdoc
         * @throws Exception|GuzzleException
         */
        public function fetchAvailableAssets(bool $throwOnError = false, array $config = []): array
        {
            if (!$this->authProvider || !$this->authProvider->hasCredentials()) {
                return [];
            }

            try {
                $api = $this->getApi();
                $accessibleCustomers = $api->getAccessibleCustomers();
                $resourceNames = $accessibleCustomers['resourceNames'] ?? [];
                
                $adAccounts = [];

                foreach ($resourceNames as $resourceName) {
                    $customerId = str_replace('customers/', '', $resourceName);
                    
                    try {
                        $clientsResponse = $api->getCustomerClients($customerId);
                        $clients = $clientsResponse['results'] ?? [];
                        
                        foreach ($clients as $clientWrapper) {
                            $client = $clientWrapper['customerClient'] ?? null;
                            if ($client && empty($client['hidden'])) {
                                $adAccounts[] = [
                                    'id'   => $client['id'],
                                    'name' => $client['descriptiveName'] ?? 'Unknown Account ' . $client['id'],
                                    'data' => $client
                                ];
                            }
                        }
                    } catch (Exception $e) {
                        $this->logger?->warning("Could not fetch customer clients for accessible customer {$customerId}: " . $e->getMessage());
                    }
                }

                return ['ad_accounts' => $adAccounts];

            } catch (Exception $e) {
                if ($throwOnError) {
                    throw $e;
                }
                $this->logger?->error("Google Ads Asset fetch error: " . $e->getMessage());

                return [];
            }
        }

        /**
         * @inheritdoc
         */
        public function syncEntities(string $entity, \DateTime $startDate, \DateTime $endDate, array $config = [], ?callable $shouldContinue = null, ?callable $identityMapper = null): Response
        {
            try {
                /** @var GoogleAdsApi $api */
                $api = $this->getApi();
                $results = [];

                $customerIds = [];
                if (!empty($config['ad_accounts'])) {
                    foreach ($config['ad_accounts'] as $acc) {
                        if (!empty($acc['enabled']) && !empty($acc['id'])) {
                            $customerIds[] = (string)$acc['id'];
                        }
                    }
                }

                foreach ($customerIds as $customerId) {
                    if ($shouldContinue && !$shouldContinue()) {
                        throw new Exception("Sync aborted by the orchestrator.");
                    }

                    switch ($entity) {
                        case 'campaign':
                            $campaignsData = $api->getCampaigns($customerId);
                            if (!empty($campaignsData['results'])) {
                                $results[$customerId]['campaigns'] = $campaignsData['results'];
                            }
                            break;
                        case 'ad_group':
                            $adGroupsData = $api->getAdGroups($customerId);
                            if (!empty($adGroupsData['results'])) {
                                $results[$customerId]['ad_groups'] = $adGroupsData['results'];
                            }
                            break;
                        case 'ad':
                            $adsData = $api->getAds($customerId);
                            if (!empty($adsData['results'])) {
                                $results[$customerId]['ads'] = $adsData['results'];
                            }
                            break;
                        default:
                            throw new Exception("Entity sync for '{$entity}' not implemented in GoogleAdsDriver");
                    }
                }

                return new Response(json_encode(['status' => 'success', 'results' => $results]), 200, ['Content-Type' => 'application/json']);
            } catch (Exception $e) {
                $this->logger?->error("Error syncing Google Ads entities: " . $e->getMessage());
                return new Response(json_encode(['status' => 'error', 'message' => $e->getMessage()]), 500, ['Content-Type' => 'application/json']);
            }
        }

        /**
         * @inheritdoc
         */
        public function sync(\DateTime $startDate, \DateTime $endDate, array $config = [], ?callable $shouldContinue = null, ?callable $identityMapper = null): Response
        {
            try {
                /** @var GoogleAdsApi $api */
                $api = $this->getApi();
                $metrics = [];

                $customerIds = [];
                if (!empty($config['ad_accounts'])) {
                    foreach ($config['ad_accounts'] as $acc) {
                        if (!empty($acc['enabled']) && !empty($acc['id'])) {
                            $customerIds[] = (string)$acc['id'];
                        }
                    }
                }

                $metricsList = $config['metrics_list'] ?? 'metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value, metrics.cost_per_conversion';
                $startDateStr = $startDate->format('Y-m-d');
                $endDateStr = $endDate->format('Y-m-d');

                foreach ($customerIds as $customerId) {
                    if ($shouldContinue && !$shouldContinue()) {
                        throw new Exception("Sync aborted by the orchestrator.");
                    }

                    $level = $config['level'] ?? 'account';
                    
                    if ($level === 'campaign') {
                        $fields = "campaign.id, segments.date, segments.device, " . $metricsList;
                        $data = $api->getMetrics($customerId, 'campaign', $startDateStr, $endDateStr, $fields);
                        $metrics = array_merge($metrics, $data['results'] ?? []);
                    } elseif ($level === 'ad_group' || $level === 'adset') {
                        $fields = "campaign.id, ad_group.id, segments.date, segments.device, " . $metricsList;
                        $data = $api->getMetrics($customerId, 'ad_group', $startDateStr, $endDateStr, $fields);
                        $metrics = array_merge($metrics, $data['results'] ?? []);
                    } elseif ($level === 'ad') {
                        $fields = "campaign.id, ad_group.id, ad_group_ad.ad.id, segments.date, segments.device, " . $metricsList;
                        $data = $api->getMetrics($customerId, 'ad_group_ad', $startDateStr, $endDateStr, $fields);
                        $metrics = array_merge($metrics, $data['results'] ?? []);
                    } else {
                        $fields = "customer.id, segments.date, segments.device, " . $metricsList;
                        $data = $api->getMetrics($customerId, 'customer', $startDateStr, $endDateStr, $fields);
                        $metrics = array_merge($metrics, $data['results'] ?? []);
                    }
                }

                return new Response(json_encode(['status' => 'success', 'metrics' => $metrics]), 200, ['Content-Type' => 'application/json']);
            } catch (Exception $e) {
                $this->logger?->error("Error syncing Google Ads metrics: " . $e->getMessage());
                return new Response(json_encode(['status' => 'error', 'message' => $e->getMessage()]), 500, ['Content-Type' => 'application/json']);
            }
        }

        public function syncMetrics(\DateTime $startDate, \DateTime $endDate, array $config = [], ?callable $shouldContinue = null, ?callable $identityMapper = null): Response
        {
            return $this->sync($startDate, $endDate, $config, $shouldContinue, $identityMapper);
        }

        public function getConfigSchema(): array
        {
            return [
                'global'     => [
                    'enabled'             => false,
                    'max_workers'         => self::DEFAULT_MAX_WORKERS,
                    'cache_history_range' => '2 years',
                    'cache_aggregations'  => false,
                    'metrics_strategy'    => 'default',
                ],
                'AD_ACCOUNT' => [
                    'ad_account_metrics' => false,
                    'campaigns'          => true,
                    'campaign_metrics'   => false,
                    'adgroups'           => true,
                    'adgroup_metrics'    => false,
                    'ads'                => true,
                    'ad_metrics'         => true,
                ],
                'entity'     => [
                    'id'                   => '',
                    'name'                 => '',
                    'enabled'              => true,
                    'exclude_from_caching' => false,
                    'lost_access'          => false,
                ],
                'metrics'    => [
                    'spend'               => ['enabled' => false, 'format' => 'currency', 'precision' => 2],
                    'clicks'              => ['enabled' => false, 'format' => 'number', 'precision' => 0],
                    'impressions'         => ['enabled' => false, 'format' => 'number', 'precision' => 0],
                    'conversions'         => ['enabled' => false, 'format' => 'number', 'precision' => 2],
                    'conversions_value'   => ['enabled' => false, 'format' => 'currency', 'precision' => 2],
                    'cost_per_conversion' => ['enabled' => false, 'format' => 'currency', 'precision' => 2, 'sparkline_direction' => 'inverted'],
                ]
            ];
        }

        public function validateConfig(array $config): array
        {
            $config = ConfigSchemaRegistryService::hydrate(
                $this->getChannel(),
                'global',
                $config,
                $this->getConfigSchema()
            );

            $envOverrides = $this->getEnvMapping();

            foreach ($envOverrides as $envKey => $configPath) {
                $val = getenv($envKey);
                if ($val !== false && $val !== '') {
                    $config[$configPath] = $val;
                }
            }

            return $config;
        }

        public function prepareUiConfig(array $channelConfig): array
        {
            $ui = [
                'gads_enabled'             => $channelConfig['enabled'] ?? false,
                'gads_granular_sync'       => $channelConfig['granular_sync'] ?? false,
                'gads_cache_history_range' => $channelConfig['cache_history_range'] ?? '2 years',
                'gads_cron_recent_hour'    => $channelConfig['cron_recent_hour'] ?? 5,
                'gads_cron_recent_minute'  => $channelConfig['cron_recent_minute'] ?? 30,
                'gads_max_workers'         => (int)($channelConfig['max_workers'] ?? self::DEFAULT_MAX_WORKERS),
                'gads_ad_accounts'         => $channelConfig['ad_accounts'] ?? [],
            ];
            return $ui;
        }

        public function initializeEntities(array $config = []): array
        {
            return ['initialized' => 0, 'skipped' => 0];
        }

        public static function getInstanceRules(): array
        {
            return [
                'history_months'     => 24,
                'entities_sync'      => 'entities',
                'recent_cron_hour'   => 5,
                'recent_cron_minute' => 30,
            ];
        }

        public function getConfigurationJs(): string
        {
            $file = __DIR__ . '/js/GoogleAdsConfigHandler.js';
            if (file_exists($file)) {
                return file_get_contents($file);
            }
            return "";
        }

        public function seedDemoData(\Anibalealvarezs\ApiDriverCore\Interfaces\SeederInterface $seeder, array $config = []): void
        {
            // Placeholder
        }

        public function boot(): void
        {
        }

        public static function getAssetPatterns(): array
        {
            return [
                'google_ads_account' => [
                    'category'          => AssetCategory::IDENTITY,
                    'key'               => 'ad_accounts',
                    'channeled_account' => [
                        'platform_id'             => ['type' => 'raw', 'key'  => 'id'],
                        'platform_created_at_key' => 'created_at',
                        'name_key'                => 'name',
                        'type'                    => 'google_ads_account',
                        'data_key'                => 'data'
                    ]
                ]
            ];
        }

        public static function getChanneledAccounts(array $asset): array
        {
            return [
                [
                    'platformId'        => self::getPlatformId($asset, AssetCategory::IDENTITY, 'google_ads_account'),
                    'platformCreatedAt' => '',
                    'name'              => $asset['name'] ?? '',
                    'type'              => 'google_ads_account',
                    'enabled'           => filter_var($asset['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'data'              => $asset['data'] ?? []
                ]
            ];
        }

        public static function getPlatformId(array $asset, AssetCategory $category, string $context): string
        {
            return (string)($asset['id'] ?? '');
        }

        public static function getCanonicalId(array $asset, AssetCategory $category, string $context): string
        {
            return self::getPlatformId($asset, $category, $context);
        }

        public function getCleanHostname(string $hostname): string
        {
            return parse_url($hostname, PHP_URL_HOST) ?: $hostname;
        }

        public function getCleanId(string $id): string
        {
            return FieldsNormalizerHelper::getCleanString($id);
        }

        public static function getPageTypes(): array
        {
            return ['google_ads_account' => 'Google Ads Account'];
        }

        public static function getAccountTypes(): array
        {
            return ['google_ads_account' => 'Google Ads Account'];
        }

        public static function getEntityPaths(): array
        {
            return [];
        }

        public static function getChanneledAccountPlatformId(array $asset, ?string $key = null): string
        {
            return (string)($asset['id'] ?? '');
        }

        public static function getChanneledAccountPlatformCreatedAt(array $asset, ?string $key = null): string
        {
            return '';
        }

        public static function getChanneledAccountName(array $asset, ?string $key = null): string
        {
            return (string)($asset['name'] ?? '');
        }

        public static function getChanneledAccountType(): string
        {
            return 'google_ads_account';
        }

        public static function getChanneledAccountData(array $asset, ?string $key = null): array
        {
            return $asset['data'] ?? [];
        }
    }
