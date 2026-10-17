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
        use SyncDriverTrait, GoogleSyncDriverTrait {
            GoogleSyncDriverTrait::storeCredentials insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::getApi insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::boot insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::reset insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::getDateFilterMapping insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::validateConfig insteadof SyncDriverTrait;
        }

        protected ?GoogleAdsApi $api = null;

        public function setApi(GoogleAdsApi $api): self
        {
            $this->api = $api;
            return $this;
        }

        /**
         * @throws Exception
         */
        public function getApi(array $config = []): GoogleAdsApi
        {
            if ($this->api !== null) {
                return $this->api;
            }

            if (!$this->authProvider || !$this->authProvider->hasCredentials()) {
                throw new Exception("Credentials not configured.");
            }

            if (empty($config) && $this->authProvider instanceof \Anibalealvarezs\ApiDriverCore\Auth\BaseAuthProvider) {
                $config = $this->authProvider->getConfig();
            }

            return $this->initializeApi($config);
        }

        public static function getProviderLabel(): string
        {
            return 'Google Ads';
        }

        public static function getProviderName(): string
        {
            return 'google_ads';
        }

        public static function getCommonConfigKey(): ?string
        {
            return self::getProviderName();
        }

        public static function getEnvMapping(): array
        {
            return [
                self::getProviderName() => [
                    'GOOGLE_CLIENT_ID'             => 'client_id',
                    'GOOGLE_CLIENT_SECRET'         => 'client_secret',
                    'GOOGLE_REFRESH_TOKEN'         => 'refresh_token',
                    'GOOGLE_USER_ID'               => 'user_id',
                    'GOOGLE_REDIRECT_URI'          => 'redirect_uri',
                    'GOOGLE_TOKEN_PATH'            => 'token_path',
                    'GOOGLE_ADS_DEVELOPER_TOKEN'   => 'developer_token',
                    'GOOGLE_ADS_LOGIN_CUSTOMER_ID' => 'login_customer_id',
                ]
            ];
        }

        protected function initializeApi(array $config): GoogleAdsApi
        {
            if (!$this->authProvider || !$this->authProvider->hasCredentials()) {
                throw new Exception("Credentials not configured.");
            }

            $className = (new \ReflectionClass($this))->getShortName();
            $this->logger?->info("DEBUG: $className::initializeApi - START");

            $creds = $this->resolveGoogleCredentials($config);

            return new GoogleAdsApi(
                redirectUrl: $creds['redirectUrl'],
                clientId: $creds['clientId'],
                clientSecret: $creds['clientSecret'],
                refreshToken: $creds['refreshToken'],
                userId: $creds['userId'],
                developerToken: $config['developer_token'] ?? $config['google_ads']['developer_token'] ?? $_ENV['GOOGLE_ADS_DEVELOPER_TOKEN'] ?? getenv('GOOGLE_ADS_DEVELOPER_TOKEN') ?: '',
                loginCustomerId: $config['login_customer_id'] ?? $config['google_ads']['login_customer_id'] ?? $_ENV['GOOGLE_ADS_LOGIN_CUSTOMER_ID'] ?? getenv('GOOGLE_ADS_LOGIN_CUSTOMER_ID') ?: null,
                scopes: $creds['scopes'],
                token: $creds['token'],
                tokenPath: $creds['tokenPath'],
                logger: $this->logger,
                tokenRefresherCallback: $creds['tokenRefresherCallback']
            );
        }

        public function getUpdatableCredentials(): array
        {
            return [
                'GOOGLE_REFRESH_TOKEN',
                'GOOGLE_USER_ID',
                'GOOGLE_CLIENT_ID',
                'GOOGLE_CLIENT_SECRET',
                'GOOGLE_ADS_DEVELOPER_TOKEN',
                'GOOGLE_ADS_LOGIN_CUSTOMER_ID'
            ];
        }

        private const int DEFAULT_MAX_WORKERS = 3;

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
                'spend'               => ['metrics.cost_micros', 'spend'],
                'clicks'              => ['metrics.clicks', 'clicks'],
                'impressions'         => ['metrics.impressions', 'impressions'],
                'conversions'         => ['metrics.conversions', 'conversions'],
                'roas_purchase'       => ['metrics.conversions_value', 'conversions_value'],
                'cost_per_conversion' => ['metrics.cost_per_conversion', 'cost_per_conversion'],
                'cpc'                 => ['metrics.average_cpc', 'cpc'],
                'ctr'                 => ['metrics.ctr', 'ctr'],
                'conversion_rate'     => ['metrics.conversions_from_interactions_rate', 'conversion_rate'],
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
            $selectedAssets = $newData['assets']['ad_accounts'] ?? $newData['assets']['google_ads'] ?? [];
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

            // Map level and metric feature toggles into AD_ACCOUNT config block
            $gadsToggles = ['campaigns', 'adgroups', 'ads', 'ad_account_metrics', 'campaign_metrics', 'adgroup_metrics', 'ad_metrics'];
            foreach ($gadsToggles as $toggleKey) {
                if (isset($featureToggles[$toggleKey])) {
                    $chanCfg['AD_ACCOUNT'][$toggleKey] = (bool)$featureToggles[$toggleKey];
                }
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
                        
                        // First pass: find the manager account name if this customer is a manager
                        $managerName = null;
                        foreach ($clients as $clientWrapper) {
                            $c = $clientWrapper['customerClient'] ?? null;
                            if ($c && (string)($c['id'] ?? '') === (string)$customerId) {
                                if (!empty($c['manager'])) {
                                    $managerName = $c['descriptiveName'] ?? "Manager ($customerId)";
                                }
                                break;
                            }
                        }

                        foreach ($clients as $clientWrapper) {
                            $client = $clientWrapper['customerClient'] ?? null;
                            if (!$client || !empty($client['hidden'])) {
                                continue;
                            }

                            // If this account is a manager account, ignore it (cannot hold campaigns or ad delivery data)
                            if (!empty($client['manager'])) {
                                continue;
                            }

                            $clientId = (string)$client['id'];

                            // Attach parent manager information and routing header credentials
                            if ($managerName) {
                                $client['login_customer_id'] = $customerId;
                                $client['manager_name'] = $managerName;
                            }

                            $adAccounts[$clientId] = [
                                'id'   => $clientId,
                                'name' => $client['descriptiveName'] ?? 'Unknown Account ' . $clientId,
                                'data' => $client
                            ];
                        }
                    } catch (Exception $e) {
                        $this->logger?->warning("Could not fetch customer clients for accessible customer {$customerId}: " . $e->getMessage());
                    }
                }

                return ['ad_accounts' => array_values($adAccounts)];

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

                $accounts = [];
                if (!empty($config['ad_accounts'])) {
                    foreach ($config['ad_accounts'] as $acc) {
                        if (!empty($acc['enabled']) && !empty($acc['id'])) {
                            $accounts[] = $acc;
                        }
                    }
                }

                // If no enabled ad accounts are configured, return clean success rather than failing
                if (empty($accounts)) {
                    $this->logger?->info("No enabled Google Ads accounts found for entity sync ($entity).");
                    return new Response(json_encode(['status' => 'success', 'results' => []]), 200, ['Content-Type' => 'application/json']);
                }

                foreach ($accounts as $account) {
                    if ($shouldContinue && !$shouldContinue()) {
                        throw new Exception("Sync aborted by the orchestrator.");
                    }

                    $customerId = (string)$account['id'];
                    $accData = $account['data'] ?? ($account['account_data'] ?? []);
                    $loginCustomerId = $accData['login_customer_id'] ?? null;
                    if ($loginCustomerId && method_exists($api, 'setLoginCustomerId')) {
                        $api->setLoginCustomerId($loginCustomerId);
                    }

                    switch ($entity) {
                        case 'entities':
                            // Sync campaigns, ad groups, and ads together
                            $campData = $api->getCampaigns(
                                $customerId,
                                "campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, campaign.start_date, campaign.end_date"
                            );
                            if (!empty($campData['results'])) {
                                $results[$customerId]['campaigns'] = $campData['results'];
                            }

                            $adGroupsData = $api->getAdGroups(
                                $customerId,
                                "ad_group.id, ad_group.name, ad_group.status, campaign.id, ad_group.type"
                            );
                            if (!empty($adGroupsData['results'])) {
                                $results[$customerId]['ad_groups'] = $adGroupsData['results'];
                            }

                            $adsData = $api->getAds(
                                $customerId,
                                "ad_group_ad.ad.id, ad_group_ad.ad.name, ad_group_ad.status, ad_group.id, campaign.id, ad_group_ad.ad.type, ad_group_ad.ad.final_urls"
                            );
                            if (!empty($adsData['results'])) {
                                $results[$customerId]['ads'] = $adsData['results'];
                            }
                            break;
                        case 'campaign':
                            $campaignsData = $api->getCampaigns(
                                $customerId,
                                "campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, campaign.start_date, campaign.end_date"
                            );
                            if (!empty($campaignsData['results'])) {
                                $results[$customerId]['campaigns'] = $campaignsData['results'];
                            }
                            break;
                        case 'ad_group':
                        case 'adset':
                            $adGroupsData = $api->getAdGroups(
                                $customerId,
                                "ad_group.id, ad_group.name, ad_group.status, campaign.id, ad_group.type"
                            );
                            if (!empty($adGroupsData['results'])) {
                                $results[$customerId]['ad_groups'] = $adGroupsData['results'];
                            }
                            break;
                        case 'ad':
                        case 'creative':
                            $adsData = $api->getAds(
                                $customerId,
                                "ad_group_ad.ad.id, ad_group_ad.ad.name, ad_group_ad.status, ad_group.id, campaign.id, ad_group_ad.ad.type, ad_group_ad.ad.final_urls"
                            );
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
            $entity = $config['entity'] ?? 'metrics';
            if ($entity !== 'metrics' && $entity !== 'channeled_metrics') {
                return $this->syncEntities($entity, $startDate, $endDate, $config, $shouldContinue, $identityMapper);
            }

            $api = $this->getApi();
            $accountsToProcess = $config['ad_accounts'] ?? [];
            $chunkSize = $config['cache_chunk_size'] ?? '1 week';
            $targetAccountId = $config['account_id'] ?? null;
            $cleanTargetId = $targetAccountId ? ltrim((string)$targetAccountId, '#') : null;

            // 1. Batch Resolve Customer / Ad Accounts via Oracle Identity Mapper
            $caMap = [];
            if ($identityMapper && !empty($accountsToProcess)) {
                $aIds = [];
                foreach ($accountsToProcess as $account) {
                    $id = (string)($account['id'] ?? $account);
                    if ($id) {
                        $cleanId = self::getPlatformId(['id' => $id], AssetCategory::IDENTITY, 'google_ads_account');
                        if ($cleanTargetId && $cleanTargetId !== $cleanId) {
                            continue;
                        }
                        $aIds[] = $cleanId;
                    }
                }
                $caMap = $identityMapper('channeled_accounts', ['platform_ids' => $aIds]) ?? [];
            }

            $totalStats = ['metrics' => 0, 'rows' => 0, 'duplicates' => 0];
            $consecutiveFailures = 0;
            $maxConsecutiveFailures = 5;

            $metricsList = $config['metrics_list'] ?? 'metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value, metrics.cost_per_conversion, metrics.average_cpc';
            $requestedMetrics = array_map('trim', explode(',', $metricsList));

            foreach ($accountsToProcess as $account) {
                $accountPlatformIdRaw = (string)($account['id'] ?? $account);
                if (!$accountPlatformIdRaw) {
                    continue;
                }

                $accountPlatformId = self::getPlatformId(['id' => $accountPlatformIdRaw], AssetCategory::IDENTITY, 'google_ads_account');
                if ($cleanTargetId && $cleanTargetId !== $accountPlatformId) {
                    continue;
                }

                try {
                    $accCfg = $account['account_data'] ?? $account;
                    if (isset($accCfg['enabled']) && !$accCfg['enabled']) {
                        $this->logger?->info("Skipping Google Ads sync for account $accountPlatformId (disabled in config)");
                        continue;
                    }

                    if (!empty($accCfg['lost_access'])) {
                        $this->logger?->info("Skipping Google Ads sync for account $accountPlatformId (lost access)");
                        continue;
                    }

                    $caObject = $caMap[$accountPlatformId] ?? (new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity())->setPlatformId($accountPlatformId);
                    $levelsToFetch = $this->resolveLevelsToFetch($accCfg, $config);

                    $accData = $accCfg['data'] ?? ($account['data'] ?? []);
                    $loginCustomerId = $accData['login_customer_id'] ?? null;
                    if ($loginCustomerId && method_exists($api, 'setLoginCustomerId')) {
                        $api->setLoginCustomerId($loginCustomerId);
                    }
                    $chunks = DateHelper::getDateChunks($startDate->format('Y-m-d'), $endDate->format('Y-m-d'), $chunkSize);

                    foreach ($chunks as $chunk) {
                        foreach ($levelsToFetch as $level) {
                            if ($shouldContinue && !$shouldContinue()) {
                                throw new Exception("Sync aborted by the orchestrator.");
                            }

                            $this->logger?->info(">>> INICIO: Sincronizando métricas de Google Ads para Customer: $accountPlatformId (Level: $level | Timeframe: {$chunk['start']} a {$chunk['end']})");

                            $data = $api->getMetricsForLevel(
                                customerId: $accountPlatformId,
                                level: $level,
                                startDate: $chunk['start'],
                                endDate: $chunk['end'],
                                metrics: $requestedMetrics,
                                segments: ['segments.date', 'segments.device']
                            );

                            $rows = $data['results'] ?? [];

                            if (!empty($rows)) {
                                $collection = GoogleAdsMetricConvert::metrics(
                                    rows: $rows,
                                    channeledAccount: $caObject ?? $accountPlatformId,
                                    level: $level,
                                    logger: $this->logger,
                                    account: (method_exists($caObject, 'getAccount')) ? $caObject->getAccount() : ($config['accounts_group_name'] ?? 'Default')
                                );

                                if ($this->dataProcessor && $collection->count() > 0) {
                                    $this->validateHierarchicalIntegrity(collection: $collection, type: \Anibalealvarezs\ApiDriverCore\Enums\HierarchyType::MARKETING);

                                    $result = ($this->dataProcessor)($collection, $this->logger);

                                    $metricsCount = $result['metrics'] ?? $collection->count();
                                    $processedRows = $result['rows'] ?? count($rows);
                                    $duplicates = $result['duplicates'] ?? 0;

                                    $totalStats['metrics'] += $metricsCount;
                                    $totalStats['rows'] += $processedRows;
                                    $totalStats['duplicates'] += $duplicates;

                                    $this->logger?->info("<<< EXITO: Sincronización completada para Customer: $accountPlatformId (Level: $level). Métricas: $metricsCount | Filas: $processedRows | Duplicados: $duplicates");
                                }
                            } else {
                                $this->logger?->info("--- INFO: No se encontraron datos de Google Ads para Customer: $accountPlatformId (Level: $level)");
                            }

                            // If fetching at 'ad' level, also fetch campaign-level metrics for PERFORMANCE_MAX and SHOPPING
                            // which have no traditional ad_group_ad entities, preventing under-reporting or double-counting.
                            if ($level === 'ad') {
                                try {
                                    $pmaxData = $api->getMetricsForLevel(
                                        customerId: $accountPlatformId,
                                        level: 'campaign',
                                        startDate: $chunk['start'],
                                        endDate: $chunk['end'],
                                        metrics: $requestedMetrics,
                                        segments: ['segments.date', 'segments.device'],
                                        where: "campaign.advertising_channel_type IN ('PERFORMANCE_MAX', 'SHOPPING')"
                                    );

                                    $pmaxRows = $pmaxData['results'] ?? [];
                                    if (!empty($pmaxRows)) {
                                        $pmaxCollection = GoogleAdsMetricConvert::campaignMetrics(
                                            rows: $pmaxRows,
                                            logger: $this->logger,
                                            channeledAccount: $caObject ?? $accountPlatformId,
                                            account: (method_exists($caObject, 'getAccount')) ? $caObject->getAccount() : ($config['accounts_group_name'] ?? 'Default')
                                        );

                                        if ($this->dataProcessor && $pmaxCollection->count() > 0) {
                                            $this->validateHierarchicalIntegrity(collection: $pmaxCollection, type: \Anibalealvarezs\ApiDriverCore\Enums\HierarchyType::MARKETING);

                                            $result = ($this->dataProcessor)($pmaxCollection, $this->logger);
                                            $metricsCount = $result['metrics'] ?? $pmaxCollection->count();
                                            $processedRows = $result['rows'] ?? count($pmaxRows);
                                            $duplicates = $result['duplicates'] ?? 0;

                                            $totalStats['metrics'] += $metricsCount;
                                            $totalStats['rows'] += $processedRows;
                                            $totalStats['duplicates'] += $duplicates;

                                            $this->logger?->info("<<< EXITO: PMax/Shopping metrics sincronizadas para Customer: $accountPlatformId. Métricas: $metricsCount | Filas: $processedRows");
                                        }
                                    }
                                } catch (Exception $pe) {
                                    $this->logger?->warning("Could not sync PMax/Shopping metrics for Customer $accountPlatformId: " . $pe->getMessage());
                                }
                            }
                        }
                    }

                    $consecutiveFailures = 0;
                } catch (Exception $e) {
                    if (str_contains($e->getMessage(), 'Sync aborted')) {
                        throw $e;
                    }
                    if ($e instanceof \Anibalealvarezs\ApiSkeleton\Classes\Exceptions\PermanentAuthenticationException) {
                        throw $e;
                    }
                    $this->logger?->error("Unhandled error syncing Google Ads Account $accountPlatformId: " . $e->getMessage());
                    $consecutiveFailures++;
                    if ($consecutiveFailures >= $maxConsecutiveFailures) {
                        throw new Exception("Terminating Google Ads sync due to $consecutiveFailures consecutive unhandled failures.");
                    }
                }
            }

            return new Response(json_encode(['status' => 'success', 'data' => $totalStats]), 200, ['Content-Type' => 'application/json']);
        }

        private function resolveLevelsToFetch(array $accCfg, array $config): array
        {
            $toggleConfig = is_array($config['AD_ACCOUNT'] ?? null) ? $config['AD_ACCOUNT'] : [];

            $levels = [];
            if (!empty($toggleConfig['campaign_metrics']) || !empty($config['sync_campaign_metrics'])) {
                $levels[] = 'campaign';
            }
            if (!empty($toggleConfig['adgroup_metrics']) || !empty($config['sync_adgroup_metrics'])) {
                $levels[] = 'ad_group';
            }
            if (!empty($toggleConfig['ad_metrics']) || !empty($config['sync_ad_metrics'])) {
                $levels[] = 'ad';
            }
            if (!empty($toggleConfig['ad_account_metrics']) || !empty($config['sync_ad_account_metrics']) || empty($levels)) {
                $levels[] = 'account';
            }

            return array_unique($levels);
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
            $mapping = $envOverrides[$this->getChannel()] ?? $envOverrides;

            foreach ($mapping as $envKey => $configPath) {
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
                'entities_sync'      => false,
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
                        'platform_id'             => [
                            'type' => 'raw',
                            'key'  => 'id'
                        ],
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
