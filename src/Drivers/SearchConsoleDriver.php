<?php

    namespace Anibalealvarezs\GoogleHubDriver\Drivers;

    use Anibalealvarezs\ApiDriverCore\Auth\BaseAuthProvider;
    use Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity;
    use Anibalealvarezs\ApiDriverCore\Classes\MetricProfileTemplates;
    use Anibalealvarezs\ApiDriverCore\Enums\AssetCategory;
    use Anibalealvarezs\ApiDriverCore\Helpers\FieldsNormalizerHelper;
    use Anibalealvarezs\ApiDriverCore\Interfaces\AuthProviderInterface;
    use Anibalealvarezs\ApiDriverCore\Interfaces\ChanneledAccountableInterface;
    use Anibalealvarezs\ApiDriverCore\Interfaces\MetricProfileProviderInterface;
    use Anibalealvarezs\ApiDriverCore\Interfaces\PageableInterface;
    use Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface;
    use Anibalealvarezs\ApiDriverCore\Routes\AssetRoutes;
    use Anibalealvarezs\ApiDriverCore\Services\CacheStrategyService;
    use Anibalealvarezs\ApiDriverCore\Services\ConfigSchemaRegistryService;
    use Anibalealvarezs\ApiDriverCore\Traits\HasHierarchicalValidationTrait;
    use Anibalealvarezs\ApiDriverCore\Traits\SyncDriverTrait;
    use Anibalealvarezs\GoogleApi\Services\SearchConsole\SearchConsoleApi;
    use Anibalealvarezs\GoogleHubDriver\Controllers\GoogleAuthController;
    use Anibalealvarezs\GoogleHubDriver\Controllers\ReportController;
    use Anibalealvarezs\GoogleHubDriver\Conversions\GoogleSearchConsoleConvert;
    use Anibalealvarezs\GoogleHubDriver\Helpers\Helpers;
    use Carbon\Carbon;
    use DateTime;
    use Exception;
    use Faker\Factory;
    use GuzzleHttp\Exception\GuzzleException;
    use Psr\Log\LoggerInterface;
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpFoundation\Response;
    use Anibalealvarezs\ApiDriverCore\Interfaces\SeederInterface;

    use Anibalealvarezs\ApiDriverCore\Enums\HierarchyType;
    use Anibalealvarezs\GoogleHubDriver\Enums\GoogleChannel;
    use Anibalealvarezs\GoogleHubDriver\Enums\GoogleEntityType;
    use Anibalealvarezs\GoogleHubDriver\Enums\GoogleFeature;

    class SearchConsoleDriver implements SyncDriverInterface, PageableInterface, ChanneledAccountableInterface, MetricProfileProviderInterface
    {
        use HasHierarchicalValidationTrait;
        use SyncDriverTrait;

        public static function getCommonConfigKey(): ?string
        {
            return 'google';
        }

        /**
         * Store credentials for this driver.
         *
         * @param array $credentials
         * @return void
         */
        public static function storeCredentials(array $credentials): void
        {
            $tokenPath = $_ENV['GOOGLE_TOKEN_PATH'] ?? getenv('GOOGLE_TOKEN_PATH') ?: (getcwd().'/storage/tokens/google_tokens.json');
            $tokenKey = 'google_auth';

            if (!is_dir(dirname($tokenPath))) {
                mkdir(dirname($tokenPath), 0755, true);
            }

            $tokens = file_exists($tokenPath) ? (json_decode(file_get_contents($tokenPath), true) ?? []) : [];

            $tokens[$tokenKey] = [
                'access_token'  => $credentials['access_token'] ?? null,
                'refresh_token' => $credentials['refresh_token'] ?? null,
                'user_id'       => $credentials['user_id'] ?? null,
                'scopes'        => $credentials['scopes'] ?? [],
                'updated_at'    => date('Y-m-d H:i:s'),
                'expires_at'    => date('Y-m-d H:i:s', strtotime('+3600 seconds'))
            ];

            file_put_contents($tokenPath, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        /**
         * Get the public resources exposed by this driver.
         *
         * @return array
         */
        public static function getPublicResources(): array
        {
            return ['metrics' => 'gsc_metrics'];
        }

        public static function getMetricProfiles(): array
        {
            return [
                MetricProfileTemplates::pageTotals(
                    channel: GoogleChannel::SEARCH_CONSOLE->value,
                    key: 'gsc_site_totals',
                    label: 'GSC Site Totals'
                ),
                MetricProfileTemplates::pageQueryBreakdown(
                    channel: GoogleChannel::SEARCH_CONSOLE->value,
                    key: 'gsc_site_query_breakdown',
                    label: 'GSC Site Query Breakdown'
                ),
                MetricProfileTemplates::pageGeoDeviceBreakdown(
                    channel: GoogleChannel::SEARCH_CONSOLE->value,
                    key: 'gsc_site_geo_device_breakdown',
                    label: 'GSC Site Country Device Breakdown'
                ),
            ];
        }

        public static function getChannelLabel(): string
        {
            return 'Google Search Console';
        }

        public static function getProviderLabel(): string
        {
            return 'Google';
        }

        public static function getProviderName(): string
        {
            return 'google';
        }

        /**
         * Get the display icon for the channel.
         *
         * @return string
         */
        public static function getChannelIcon(): string
        {
            return 'G';
        }

        /**
         * Get the routes served by this driver.
         *
         * @return array
         */
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
                '/gsc-reports'       => [
                    'httpMethod' => 'GET',
                    'callable'   => fn(...$args) => (new ReportController())->index($args),
                    'public'     => true,
                    'admin'      => false,
                    'html'       => true
                ]
            ]);
        }

        /**
         * @inheritdoc
         * @throws Exception|GuzzleException
         */
        public function fetchAvailableAssets(bool $throwOnError = false): array
        {
            if (!$this->authProvider) {
                return [];
            }

            try {
                $api = $this->getApi();
                $sitesResponse = $api->getSites();
                $assets = ['sites' => []];

                if (isset($sitesResponse['siteEntry'])) {
                    foreach ($sitesResponse['siteEntry'] as $entry) {
                        $url = $entry['siteUrl'];
                        $assets['sites'][] = [
                            'url'             => $url,
                            'title'           => $this->deriveTitleFromUrl($url),
                            'hostname'        => $this->deriveHostnameFromUrl($url),
                            'permissionLevel' => $entry['permissionLevel'] ?? 'siteRestrictedUser',
                            'data'            => $entry
                        ];
                    }
                }

                return $assets;
            } catch (Exception $e) {
                if ($this->isAuthenticationError($e)) {
                    $this->logger?->critical("SearchConsoleDriver: Authentication failed (invalid_grant/expired). Please re-authenticate via UI.");
                } else {
                    $this->logger?->error("SearchConsoleDriver: Error fetching available assets: ".$e->getMessage());
                }

                if ($throwOnError) {
                    throw $e;
                }

                return [];
            }
        }

        /**
         * Derive a human-readable title from a GSC site URL.
         */
        public function deriveTitleFromUrl(string $url): string
        {
            return str_replace(['https://', 'http://', 'sc-domain:'], '', rtrim($url, '/'));
        }

        /**
         * Derive a hostname from a GSC site URL.
         */
        public function deriveHostnameFromUrl(string $url): string
        {
            return parse_url(str_replace('sc-domain:', 'https://', $url), PHP_URL_HOST) ?? $url;
        }

        /**
         * @inheritdoc
         */
        public function updateConfiguration(array $newData, array $currentConfig): array
        {
            $selectedSites = $newData['assets']['gsc'] ?? [];
            $enabled = $newData['enabled'] ?? false;
            $historyRange = $newData['cache_history_range'] ?? null;
            $featureToggles = $newData['feature_toggles'] ?? [];

            if (!isset($currentConfig['channels'][GoogleChannel::SEARCH_CONSOLE->value])) {
                $currentConfig['channels'][GoogleChannel::SEARCH_CONSOLE->value] = [];
            }

            $chanCfg = &$currentConfig['channels'][GoogleChannel::SEARCH_CONSOLE->value];

            if ($historyRange) {
                $chanCfg['cache_history_range'] = $historyRange;
            }

            // Cron settings
            foreach (GoogleFeature::cron() as $feature) {
                $key = $feature->value;
                if (isset($featureToggles[$key])) {
                    $chanCfg[$key] = (int)$featureToggles[$key];
                }
            }

            $chanCfg['enabled'] = $enabled;

            // Synthetic generation toggle
            if (isset($featureToggles['calculate_synthetics'])) {
                $chanCfg['calculate_synthetics'] = filter_var($featureToggles['calculate_synthetics'], FILTER_VALIDATE_BOOLEAN);
            }

            // Redis cache toggle
            if (isset($featureToggles['cache_aggregations'])) {
                $prevValue = (bool)($chanCfg['cache_aggregations'] ?? false);
                $newValue = (bool)$featureToggles['cache_aggregations'];
                $chanCfg['cache_aggregations'] = $newValue;

                if ($prevValue && !$newValue && class_exists('\Anibalealvarezs\ApiDriverCore\Services\CacheStrategyService')) {
                    CacheStrategyService::clearChannel(GoogleChannel::SEARCH_CONSOLE->value);
                }
            }

            // Sites management
            $currentSites = $chanCfg['sites'] ?? [];
            $newSitesList = [];
            $selectedMap = [];
            foreach ($selectedSites as $sel) {
                $normUrl = FieldsNormalizerHelper::getCleanString($sel['url']);
                $selectedMap[$normUrl] = $sel;
            }

            $processedNormUrls = [];
            foreach ($currentSites as $site) {
                $normUrl = FieldsNormalizerHelper::getCleanString($site['url']);
                if (isset($selectedMap[$normUrl])) {
                    $site['enabled'] = filter_var($selectedMap[$normUrl]['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
                    $site['target_countries'] = $selectedMap[$normUrl]['target_countries'] ?? [];
                    $site['target_keywords'] = $selectedMap[$normUrl]['target_keywords'] ?? [];
                    $site['lost_access'] = filter_var($selectedMap[$normUrl]['lost_access'] ?? false, FILTER_VALIDATE_BOOLEAN);
                    $site['data'] = $selectedMap[$normUrl]['data'] ?? $site['data'] ?? [];
                    if (($site['data']['permissionLevel'] ?? null) === 'siteUnverifiedUser') {
                        $site['enabled'] = false;
                    }
                    $newSitesList[] = $site;
                    $processedNormUrls[] = $normUrl;
                }
            }

            foreach ($selectedSites as $sel) {
                $normUrl = FieldsNormalizerHelper::getCleanString($sel['url']);
                if (!in_array($normUrl, $processedNormUrls)) {
                    $siteData = $sel['data'] ?? [];
                    $newSitesList[] = [
                        'url'              => $sel['url'],
                        'title'            => $this->deriveTitleFromUrl($sel['url']),
                        'hostname'         => $this->deriveHostnameFromUrl($sel['url']),
                        'enabled'          => filter_var($sel['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN) && (($siteData['permissionLevel'] ?? null) !== 'siteUnverifiedUser'),
                        'target_countries' => $sel['target_countries'] ?? [],
                        'target_keywords'  => $sel['target_keywords'] ?? [],
                        'lost_access'      => filter_var($sel['lost_access'] ?? false, FILTER_VALIDATE_BOOLEAN),
                        'data'             => $siteData
                    ];
                }
            }

            $chanCfg['sites'] = $newSitesList;

            return $currentConfig;
        }

        /**
         * @inheritdoc
         */
        public function validateAuthentication(): array
        {
            try {
                $api = $this->getApi();
                $api->getSites();

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

        public array $updatableCredentials = [
            'GOOGLE_REFRESH_TOKEN',
            'GOOGLE_USER_ID',
            'GOOGLE_CLIENT_ID',
            'GOOGLE_CLIENT_SECRET'
        ];

        private ?AuthProviderInterface $authProvider;
        private ?LoggerInterface $logger;
        /** @var callable|null */
        private $dataProcessor = null;

        // Dimensions from legacy GoogleSearchConsoleHelpers
        private static array $allDimensions = ['date', 'query', 'country', 'page', 'device', 'searchAppearance'];
        private static array $optionalDimensions = ['query', 'country', 'device', 'searchAppearance'];

        public function __construct(
            ?AuthProviderInterface $authProvider = null,
            ?LoggerInterface       $logger = null,
        )
        {
            $this->authProvider = $authProvider;
            $this->logger = $logger;
        }

        public function getChannel(): string
        {
            return GoogleChannel::SEARCH_CONSOLE->value;
        }

        public function setAuthProvider(AuthProviderInterface $provider): void
        {
            $this->authProvider = $provider;
        }

        public function getAuthProvider(): ?AuthProviderInterface
        {
            return $this->authProvider;
        }

        public function setDataProcessor(callable $processor): void
        {
            $this->dataProcessor = $processor;
        }

        /**
         * @throws Exception
         */
        public function sync(
            DateTime  $startDate,
            DateTime  $endDate,
            array     $config = [],
            ?callable $shouldContinue = null,
            ?callable $identityMapper = null
        ): Response
        {
            if (!$this->authProvider) {
                throw new Exception("AuthProvider not set for SearchConsoleDriver");
            }

            if (!$this->dataProcessor) {
                throw new Exception("DataProcessor not set for SearchConsoleDriver");
            }

            try {
                $api = $this->initializeApi($config);
                $totalStats = ['metrics' => 0, 'rows' => 0, 'duplicates' => 0];

                $chanCfg = $config[GoogleChannel::SEARCH_CONSOLE->value] ?? [];
                $sitesToProcess = $config['sites'] ?? $chanCfg['sites'] ?? [];
                $rowLimit = $chanCfg['row_limit'] ?? 25000;
                $calculateSynthetics = filter_var($chanCfg['calculate_synthetics'] ?? false, FILTER_VALIDATE_BOOLEAN);

                // 1. Batch Resolve Identities via Oracle
                $pageMap = [];
                $caMap = [];
                $accountMap = [];
                if ($identityMapper && !empty($sitesToProcess)) {
                    $urls = [];
                    $caPlatformIds = [];
                    foreach ($sitesToProcess as $site) {
                        $u = (string)($site['url'] ?? $site);
                        $urls[] = $u;
                        $caPlatformIds[] = self::getPlatformId(['url' => $u], AssetCategory::IDENTITY, 'gsc');
                    }
                    $pageMap = $identityMapper('pages', ['urls' => $urls]) ?? [];
                    $caMap = $identityMapper('channeled_accounts', ['platform_ids' => $caPlatformIds]) ?? [];
                    $accountMap = $identityMapper('accounts', ['names' => ['Google Search Console', 'Google', 'google']]) ?? [];
                }

                $startDateCarbon = Carbon::instance($startDate);
                $endDateCarbon = Carbon::instance($endDate);

                foreach ($sitesToProcess as $site) {
                    $siteUrl = (string)($site['url'] ?? $site);
                    if (!($site['enabled'] ?? true) && is_array($site)) continue;

                    $pLevel = $site['permissionLevel'] ?? null;
                    if (in_array($pLevel, ['siteRestrictedUser', 'siteUnverifiedUser'])) {
                        $this->logger?->warning("--- SKIP: Permission insufficient for $siteUrl ($pLevel)");
                        continue;
                    }

                    $caPlatformId = self::getPlatformId(['url' => $siteUrl], AssetCategory::IDENTITY, 'gsc');
                    $ca = $caMap[$caPlatformId] ?? null;
                    $page = $pageMap[$caPlatformId] ?? null;
                    if (!is_object($page)) {
                        $page = (new UniversalEntity())->setPlatformId($caPlatformId);
                    }
                    $siteKey = is_object($page) ? ($page->getCanonicalId() ?? $page->getPlatformId() ?? $siteUrl) : $siteUrl;

                    // CHUNKING: Process in 7-day windows to balance Privacy Thresholds vs Memory/DB performance
                    $chunkStart = clone $startDateCarbon;
                    while ($chunkStart <= $endDateCarbon) {
                        $chunkEnd = (clone $chunkStart)->addDays(6);
                        if ($chunkEnd > $endDateCarbon) $chunkEnd = clone $endDateCarbon;

                        $chunkStartStr = $chunkStart->format('Y-m-d');
                        $chunkEndStr = $chunkEnd->format('Y-m-d');

                        if ($shouldContinue && !$shouldContinue()) {
                            throw new Exception("Sync aborted by the orchestrator.");
                        }

                        $this->logger?->info(">>> INICIO: Sincronizando GSC para Sitio: $siteUrl (Ventana: $chunkStartStr a $chunkEndStr)");

                        try {
                            // FETCH IN BULK FOR THE CHUNK
                            $rows = $this->fetchGSCPeriodData(
                                api: $api,
                                siteUrl: $siteUrl,
                                startDate: $chunkStartStr,
                                endDate: $chunkEndStr,
                                rowLimit: $rowLimit,
                                calculateSynthetics: $calculateSynthetics
                            );

                            if (empty($rows)) {
                                $this->logger?->info("--- INFO: No se encontraron datos GSC para Sitio: $siteUrl ($chunkStartStr a $chunkEndStr)");
                                $chunkStart->addDays(7);
                                continue;
                            }

                            $mainAccount = $accountMap['Google Search Console'] ?? $accountMap['Google'] ?? $accountMap['google'] ?? ($config['accounts_group_name'] ?? 'Default');
                            $caObject = is_object($ca) ? $ca : (new UniversalEntity())->setPlatformId($caPlatformId);
                            $accObject = (is_object($caObject) && method_exists($caObject, 'getAccount')) ? $caObject->getAccount() : (method_exists($caObject, 'getContext') ? ($caObject->getContext()['account'] ?? $mainAccount) : $mainAccount);

                            $collection = GoogleSearchConsoleConvert::metrics(
                                rows: $rows,
                                siteUrl: $siteUrl,
                                siteKey: $siteKey,
                                logger: $this->logger,
                                page: $page,
                                channeledAccount: $caObject,
                                account: $accObject
                            );

                            if ($this->dataProcessor && $collection->count() > 0) {
                                $this->validateHierarchicalIntegrity(collection: $collection, type: HierarchyType::PAGE);

                                $result = ($this->dataProcessor)($collection, $this->logger);

                                $totalStats['metrics'] += $result['metrics'] ?? 0;
                                $totalStats['rows'] += count($rows);
                                $totalStats['duplicates'] += $result['duplicates'] ?? 0;

                                $this->logger?->info("+++ ÉXITO: Sincronizados " . count($rows) . " registros GSC para $siteUrl");
                            }
                        } catch (Exception $e) {
                            $this->logger?->error("!!! ERROR: Fallo al sincronizar ventana $chunkStartStr a $chunkEndStr para $siteUrl: ".$e->getMessage());
                            if ($this->isAuthenticationError($e)) throw $e;
                            // For other errors, we continue with next chunk
                        }

                        $chunkStart->addDays(7);
                    }
                }

                return new Response(json_encode([
                    'status'  => 'success',
                    'message' => 'Search Console sync completed',
                    'stats'   => $totalStats
                ]));

            } catch (Exception $e) {
                if ($this->isAuthenticationError($e)) {
                    $this->logger?->critical("!!!! ERROR CRÍTICO DE AUTENTICACIÓN: SearchConsoleDriver falló debido a un token inválido o expirado: ".$e->getMessage());
                    return new Response(json_encode([
                        'status' => 'error',
                        'message' => 'Authentication failed. Please re-authenticate.',
                        'error_code' => 'auth_failure'
                    ]), 401);
                }

                $this->logger?->critical("!!!! ERROR CRÍTICO: SearchConsoleDriver falló: ".$e->getMessage());
                throw $e;
            }
        }

        /**
         * @throws GuzzleException
         */
        protected function fetchGSCPeriodData(
            object $api,
            string $siteUrl,
            string $startDate,
            string $endDate,
            int    $rowLimit = 25000,
            bool   $calculateSynthetics = true
        ): array {
            $finalRows = [];
            $current = Carbon::parse($startDate);
            $end = Carbon::parse($endDate);

            while ($current <= $end) {
                $dayStr = $current->format('Y-m-d');
                $this->logger?->info(">>> Fetching GSC Data for Date: $dayStr");
                
                $dayRows = $this->fetchGSCDailyData($api, $siteUrl, $dayStr, $rowLimit, $calculateSynthetics);
                $finalRows = array_merge($finalRows, $dayRows);
                
                $current->addDay();
            }

            return $finalRows;
        }

        /**
         * Fetches and reconciles all GSC data for a single day using the Power Set of dimensions.
         */
        protected function fetchGSCDailyData(
            object $api,
            string $siteUrl,
            string $date,
            int    $rowLimit = 25000,
            bool   $calculateSynthetics = true
        ): array {
            $baseDimensions = ['query', 'country', 'page', 'device'];
            $reconcileDimensions = ['date', 'query', 'country', 'page', 'device'];

            // 1. Generate Power Set of dimensions (2^4 = 16 combinations)
            // This ensures we capture EVERY level of knowledge provided by Google.
            $subsetsToFetch = [[]]; // Start with S0: [date]
            if ($calculateSynthetics) {
                $numDims = count($baseDimensions);
                for ($i = 1; $i < (1 << $numDims); $i++) {
                    $subset = [];
                    for ($j = 0; $j < $numDims; $j++) {
                        if ($i & (1 << $j)) {
                            $subset[] = $baseDimensions[$j];
                        }
                    }
                    $subsetsToFetch[] = $subset;
                }
            } else {
                $subsetsToFetch[] = $baseDimensions;
            }

            // 2. Fetch all combinations for this specific day
            $dayRows = [];
            foreach ($subsetsToFetch as $dimSubset) {
                $actualDims = array_merge(['date'], $dimSubset);
                $rows = $this->fetchWithRetry($api, $siteUrl, $date, $date, $actualDims, $rowLimit);
                foreach ($rows as $row) {
                    $dayRows[] = array_merge($row, ['subset' => $actualDims]);
                }
            }

            if (empty($dayRows)) return [];

            // 3. Perform Möbius Reconciliation (Daily Truth)
            // This will calculate residuals for each level of the power set.
            $reconciledRows = Helpers::getFinalRecords(
                $dayRows,
                $calculateSynthetics ? ['query'] : [],
                $calculateSynthetics ? ['country'] : [],
                $reconcileDimensions
            );

            // 4. Format and add Search Appearance (Standard)
            $finalDayRows = [];
            foreach ($reconciledRows as $row) {
                $row['keys'][] = 'standard';
                $row['subset'][] = 'searchAppearance';
                $finalDayRows[] = $row;
            }

            // Pass 2: Search Appearance (Parallel Set - Not processed by Möbius/IPF)
            $appearanceRows = $this->fetchWithRetry($api, $siteUrl, $date, $date, ['searchAppearance'], $rowLimit);
            foreach ($appearanceRows as $row) {
                $finalDayRows[] = [
                    'keys' => [
                        $date,
                        Helpers::$defaultValues['query'],
                        Helpers::$defaultValues['country'],
                        Helpers::$defaultValues['page'] ?? $siteUrl,
                        Helpers::$defaultValues['device'],
                        $row['keys'][0] // Appearance Type
                    ],
                    'clicks' => $row['clicks'],
                    'impressions' => $row['impressions'],
                    'ctr' => $row['ctr'],
                    'position' => $row['position'],
                    'subset' => array_merge($reconcileDimensions, ['searchAppearance'])
                ];
            }

            return $finalDayRows;
        }

        /**
         * @throws GuzzleException
         */
        private function fetchWithRetry(SearchConsoleApi $api, string $siteUrl, string $startDate, string $endDate, array $dimensions, int $rowLimit): array
        {
            $maxRetries = 3;
            $retryCount = 0;
            while ($retryCount < $maxRetries) {
                try {
                    $response = $api->getAllSearchQueryResults(
                        siteUrl: $siteUrl,
                        startDate: $startDate,
                        endDate: $endDate,
                        rowLimit: $rowLimit,
                        dimensions: $dimensions
                    );
                    return $response['rows'] ?? [];
                } catch (Exception $e) {
                    $retryCount++;
                    if ($retryCount >= $maxRetries) throw $e;
                    usleep(500000 * $retryCount);
                } catch (GuzzleException $e) {
                    $retryCount++;
                    if ($retryCount >= $maxRetries) throw $e;
                }
            }
            return [];
        }

        /**
         * @throws Exception
         */
        public function getApi(array $config = []): SearchConsoleApi
        {
            if (empty($config) && $this->authProvider instanceof BaseAuthProvider) {
                $config = $this->authProvider->getConfig();
            }

            return $this->initializeApi($config);
        }

        /**
         * @throws Exception
         */
        protected function initializeApi(array $config): SearchConsoleApi
        {
            $this->logger?->info("DEBUG: SearchConsoleDriver::initializeApi - START");
            $scopes = $this->authProvider->getScopes();
            $token = $this->authProvider->getAccessToken();

            $providerConfig = [];
            if ($this->authProvider instanceof BaseAuthProvider) {
                $providerConfig = $this->authProvider->getConfig();
            }

            return new SearchConsoleApi(
                redirectUrl: $config['redirect_uri'] ?? $config['google']['redirect_uri'] ?? $_ENV['GOOGLE_REDIRECT_URI'] ?? getenv('GOOGLE_REDIRECT_URI') ?: '',
                clientId: $config['client_id'] ?? $config['google']['client_id'] ?? $_ENV['GOOGLE_CLIENT_ID'] ?? getenv('GOOGLE_CLIENT_ID') ?: '',
                clientSecret: $config['client_secret'] ?? $config['google']['client_secret'] ?? $_ENV['GOOGLE_CLIENT_SECRET'] ?? getenv('GOOGLE_CLIENT_SECRET') ?: '',
                refreshToken: $providerConfig['google_auth']['refresh_token'] ?? $providerConfig['google']['refresh_token'] ?? $config['refresh_token'] ?? $config['google']['refresh_token'] ?? $_ENV['GOOGLE_REFRESH_TOKEN'] ?? getenv('GOOGLE_REFRESH_TOKEN') ?: '',
                userId: $providerConfig['google_auth']['user_id'] ?? $providerConfig['google']['user_id'] ?? $config['user_id'] ?? $config['google']['user_id'] ?? 'default',
                scopes: $scopes,
                token: $token,
                tokenPath: $config['token_path'] ?? $config['google']['token_path'] ?? $_ENV['GOOGLE_TOKEN_PATH'] ?? getenv('GOOGLE_TOKEN_PATH') ?: "",
                logger: $this->logger
            );
        }

        private function getAllSubsets(array $input): array
        {
            $result = [[]];
            foreach ($input as $element) {
                foreach ($result as $combination) {
                    $result[] = array_merge($combination, [$element]);
                }
            }

            return $result;
        }

        /**
         * @inheritdoc
         */
        public function getConfigSchema(): array
        {
            return [
                'global'  => [
                    'enabled'             => false,
                    'cache_history_range' => '16 months',
                    'cache_aggregations'  => true,
                ],
                'entity'  => [
                    'url'               => '',
                    'title'             => '',
                    'hostname'          => '',
                    'enabled'           => true,
                    'target_countries'  => [],
                    'target_keywords'   => [],
                    'include_keywords'  => [],
                    'exclude_keywords'  => [],
                    'include_countries' => [],
                    'exclude_countries' => [],
                    'include_pages'     => [],
                    'exclude_pages'     => [],
                    'lost_access'       => false,
                ],
                'metrics' => [
                    'clicks'      => ['enabled' => true, 'format' => 'number', 'precision' => 0],
                    'impressions' => ['enabled' => true, 'format' => 'number', 'precision' => 0],
                    'ctr'         => ['enabled' => true, 'format' => 'percent', 'precision' => 2],
                    'position'    => ['enabled' => true, 'format' => 'number', 'precision' => 1, 'sparkline_direction' => 'inverted'],
                ]
            ];
        }

        /**
         * @inheritdoc
         */
        public function validateConfig(array $config): array
        {
            $config = ConfigSchemaRegistryService::hydrate(
                $this->getChannel(),
                'global',
                $config,
                $this->getConfigSchema()
            );

            $envOverrides = [
                'GOOGLE_CLIENT_ID'                    => 'client_id',
                'GOOGLE_CLIENT_SECRET'                => 'client_secret',
                'GOOGLE_REFRESH_TOKEN'                => 'refresh_token',
                'GOOGLE_REDIRECT_URI'                 => 'redirect_uri',
                'GOOGLE_USER_ID'                      => 'user_id',
                'GOOGLE_SEARCH_CONSOLE_CLIENT_ID'     => 'client_id',
                'GOOGLE_SEARCH_CONSOLE_CLIENT_SECRET' => 'client_secret',
                'GOOGLE_SEARCH_CONSOLE_REFRESH_TOKEN' => 'refresh_token',
                'GOOGLE_SEARCH_CONSOLE_TOKEN'         => 'token',
            ];

            foreach ($envOverrides as $envKey => $configPath) {
                $val = getenv($envKey);
                if ($val !== false && $val !== '') {
                    $config[$configPath] = $val;
                }
            }

            return $config;
        }

        /**
         * @inheritdoc
         */
        public function seedDemoData(SeederInterface $seeder, array $config = []): void
        {
            $output = $config['output'] ?? null;
            if ($output) $output->writeln("🔍 GSC (10 Sites, 6 Months, Correct Universal SEO Domain Model)...");

            $dates = $seeder->getDates(180);
            $countryEnumValues = ['USA', 'ESP', 'MEX', 'COL'];
            $deviceEnumValues = ['desktop', 'mobile', 'tablet'];
            // $appearances = ['AMP_TOP_STORIES', 'PRODUCT_SNIPPETS', 'REVIEW_SNIPPET', 'VIDEO', 'ORGANIC_SHOPPING'];

            $dimManager = $seeder->getDimensionManager();

            // Pre-fetch Universal Entities via Seeder
            $countries = [];
            foreach ($countryEnumValues as $code) {
                $countries[$code] = $seeder->resolveEntity('country', ['code' => $code, 'name' => $code]);
            }
            $devices = [];
            foreach ($deviceEnumValues as $type) {
                $devices[$type] = $seeder->resolveEntity('device', ['type' => $type]);
            }

            $faker = Factory::create('en_US');
            $gscChan = GoogleChannel::SEARCH_CONSOLE;

            $gscAcc = $seeder->resolveEntity('account', ['name' => 'Demo Agency GSC']);

            for ($s = 1; $s <= 10; $s++) {
                $sitePId = "https://demo-site-$s.com/";
                $site = $seeder->resolveEntity('page', [
                    'platformId'  => $sitePId,
                    'account'     => $gscAcc,
                    'title'       => "Demo Site $s",
                    'url'         => $sitePId,
                    'canonicalId' => $sitePId
                ]);

                $ca = $seeder->resolveEntity('channeled_account', [
                    'platformId' => $sitePId,
                    'account'    => $gscAcc,
                    'type'       => GoogleEntityType::SITE->value,
                    'channel'    => GoogleChannel::SEARCH_CONSOLE->value,
                    'name'       => "Demo Site $s"
                ]);

                foreach ($dates as $date) {
                    foreach ($countryEnumValues as $code) {
                        foreach ($deviceEnumValues as $type) {
                            $country = $countries[$code];
                            $device = $devices[$type];

                            $dimSet = $dimManager->resolveDimensionSet([
                                ['dimensionKey' => 'country', 'dimensionValue' => $code],
                                ['dimensionKey' => 'device', 'dimensionValue' => $type],
                            ]);

                            $imps = rand(10, 100);
                            $clicks = (int)($imps * rand(1, 5) / 100);

                            foreach (['impressions' => $imps, 'clicks' => $clicks] as $name => $val) {
                                if ($val <= 0) continue;
                                $seeder->queueMetric(
                                    channel: $gscChan,
                                    name: $name,
                                    date: $date,
                                    value: $val,
                                    setId: $dimSet->id,
                                    pageId: $site->id,
                                    caId: $ca->id,
                                    gAccId: $gscAcc->id,
                                    accName: $gscAcc->getTitle(),
                                    caPId: $sitePId,
                                    pageUrl: $sitePId,
                                    data: json_encode(['raw' => $val])
                                );
                            }
                        }
                    }
                }
            }
        }

        public function boot(): void
        {
        }

        /**
         * @inheritdoc
         */
        public static function getAssetPatterns(): array
        {
            return [
                'gsc' => [
                    'category'          => [AssetCategory::IDENTITY, AssetCategory::PAGEABLE],
                    'key'               => 'sites',
                    'channeled_account' => [
                        'platform_id'             => [
                            'type' => 'md5',
                            'key'  => 'url'
                        ],
                        'platform_created_at_key' => 'created_time',
                        'name_key'                => 'title',
                        'type'                    => GoogleEntityType::SITE->value,
                        'data_key'                => 'data'
                    ],
                    'page'              => [
                        'canonical_id' => [
                            'prefix' => 'gsc:domain',
                            'field'  => 'hostname'
                        ],
                        'platform_id'  => [
                            'type' => 'md5',
                            'key'  => 'url'
                        ],
                        'title_key'    => 'title',
                        'url'          => [
                            'type' => 'default'
                        ],
                        'hostname_key' => 'hostname',
                        'data_key'     => 'data'
                    ]
                ],
            ];
        }

        public static function getPages(array $asset): array
        {
            return [
                // GSC Site
                [
                    'platformId'  => self::getPlatformId($asset, AssetCategory::PAGEABLE, 'gsc'),
                    'canonicalId' => self::getCanonicalId($asset, AssetCategory::PAGEABLE, 'gsc'),
                    'hostname'    => self::getPageHostname(asset: $asset),
                    'title'       => self::getPageTitle(asset: $asset),
                    'url'         => self::getPageUrl(asset: $asset),
                    'enabled'     => ($asset['enabled'] ?? true) && (($asset['data']['permissionLevel'] ?? $asset['permissionLevel'] ?? null) !== 'siteUnverifiedUser'),
                    'data'        => self::getPageData(asset: $asset)
                ]
            ];
        }

        public static function getChanneledAccounts(array $asset): array
        {
            return [
                // GSC Site
                [
                    'platformId'        => self::getPlatformId($asset, AssetCategory::IDENTITY, 'gsc'),
                    'platformCreatedAt' => self::getChanneledAccountPlatformCreatedAt(asset: $asset),
                    'name'              => self::getChanneledAccountName(asset: $asset),
                    'type'              => self::getChanneledAccountType(),
                    'account'           => self::getChannelLabel(),
                    'enabled'           => ($asset['enabled'] ?? true) && (($asset['data']['permissionLevel'] ?? $asset['permissionLevel'] ?? null) !== 'siteUnverifiedUser'),
                    'data'              => self::getChanneledAccountData(asset: $asset)
                ]
            ];
        }

        // PAGE FIELDS

        public static function getPagePlatformId(array $asset, ?string $key = null): string
        {
            return self::getPlatformId($asset, AssetCategory::PAGEABLE, 'gsc');
        }

        public static function getPageCanonicalId(array $asset, ?string $key = null): string
        {
            return 'gsc:domain:'.self::getPageHostname($asset, $key);
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

        public static function getPlatformId(array $asset, AssetCategory $category, string $context): string
        {
            return match ($category) {
                AssetCategory::IDENTITY, AssetCategory::PAGEABLE => self::deriveSearchConsoleId($asset),
                default => (string)($asset['id'] ?? '')
            };
        }

        public static function getCanonicalId(array $asset, AssetCategory $category, string $context): string
        {
            if ($category !== AssetCategory::PAGEABLE) {
                return self::getPlatformId($asset, $category, $context);
            }

            $patterns = self::getAssetPatterns();
            $prefix = $patterns[$context]['page']['canonical_id']['prefix'] ?? 'gsc:domain';

            $pId = self::deriveSearchConsoleHostname($asset);
            if (!$pId) return '';

            return str_starts_with($pId, $prefix.':') ? $pId : $prefix.':'.$pId;
        }

        private static function deriveSearchConsoleId(array $asset): string
        {
            $id = $asset['id'] ?? $asset['url'] ?? null;
            if ($id && (str_starts_with($id, 'http') || str_contains($id, '/') || str_starts_with($id, 'sc-domain:'))) {
                return md5(FieldsNormalizerHelper::getCleanString($id));
            }

            return $id ? (string)$id : '';
        }

        private static function deriveSearchConsoleHostname(array $asset): string
        {
            $id = $asset['hostname'] ?? $asset['url'] ?? $asset['id'] ?? null;
            if (!$id) return '';
            $id = FieldsNormalizerHelper::getCleanString($id);
            if (str_starts_with($id, 'gsc:domain:')) {
                $id = str_replace('gsc:domain:', '', $id);
            }
            if (str_starts_with($id, 'http')) {
                return parse_url($id, PHP_URL_HOST) ?: $id;
            }

            return $id;
        }

        // CHANNELED ACCOUNT FIELDS

        public static function getChanneledAccountPlatformId(array $asset, ?string $key = null): string
        {
            return self::getPlatformId($asset, AssetCategory::IDENTITY, 'gsc');
        }

        public static function getChanneledAccountPlatformCreatedAt(array $asset, ?string $key = null): string
        {
            $idKey = $key ?: 'created_time';

            return isset($asset[$idKey]) && $asset[$idKey] ? FieldsNormalizerHelper::getCleanString($asset[$idKey]) : '';
        }

        public static function getChanneledAccountName(array $asset, ?string $key = null): string
        {
            $idKey = $key ?: 'title';

            return isset($asset[$idKey]) && $asset[$idKey] ? FieldsNormalizerHelper::getCleanString($asset[$idKey]) : '';
        }

        public static function getChanneledAccountType(string|GoogleEntityType $entityType = GoogleEntityType::SITE): string
        {
            return $entityType instanceof GoogleEntityType ? $entityType->value : $entityType;
        }

        public static function getChanneledAccountData(array $asset, ?string $key = null): array
        {
            $idKey = $key ?: 'data';

            return isset($asset[$idKey]) && $asset[$idKey] ? FieldsNormalizerHelper::getCleanArray($asset[$idKey]) : [];
        }

        /**
         * @inheritdoc
         */
        public static function getPageTypes(): array
        {
            return [
                GoogleEntityType::SITE->value => 'GSC Site'
            ];
        }

        /**
         * @inheritdoc
         */
        public static function getAccountTypes(): array
        {
            return [
                GoogleEntityType::SITE->value => 'GSC Site'
            ];
        }

        /**
         * @inheritdoc
         */
        public static function getEntityPaths(): array
        {
            return [__DIR__.'/../Entities'];
        }

        /**
         * @inheritdoc
         */
        public function prepareUiConfig(array $channelConfig): array
        {
            $ui = [];
            $ui['gsc_cache_history_range'] = $channelConfig['cache_history_range'] ?? '16 months';
            $ui['gsc_enabled'] = $channelConfig['enabled'] ?? false;
            $ui['gsc_cron_recent_hour'] = $channelConfig['cron_recent_hour'] ?? 5;
            $ui['gsc_cron_recent_minute'] = $channelConfig['cron_recent_minute'] ?? 0;
            $ui['gsc_calculate_synthetics'] = filter_var($channelConfig['calculate_synthetics'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $ui['gsc'] = [];
            foreach (($channelConfig['sites'] ?? []) as $site) {
                $url = $site['url'];
                if (class_exists('\Anibalealvarezs\ApiDriverCore\Services\ConfigSchemaRegistryService')) {
                    $ui['gsc'][$url] = ConfigSchemaRegistryService::hydrate('google_search_console', 'entity', $site);
                } else {
                    $ui['gsc'][$url] = $site;
                }
            }

            return $ui;
        }

        /**
         * @inheritdoc
         * @throws Exception
         * @throws GuzzleException
         */
        public function initializeEntities(array $config = []): array
        {
            return $this->fetchAvailableAssets(throwOnError: false);
        }

        /**
         * @inheritdoc
         * @throws Exception
         */
        public function reset(string $mode = 'all', array $config = []): array
        {
            $resetCallback = $config['resetCallback'] ?? null;
            if ($resetCallback instanceof \Closure) {
                return $resetCallback($this->getChannel(), $mode);
            }

            throw new Exception("Reset callback not provided for ".$this->getChannel());
        }

        /**
         * @inheritdoc
         */
        public function getDateFilterMapping(): array
        {
            return [];
        }

        /**
         * @inheritdoc
         */
        public static function getInstanceRules(): array
        {
            return [
                'history_months'     => 16,
                'entities_sync'      => false,
                'recent_cron_hour'   => 7,
                'recent_cron_minute' => 0,
            ];
        }

        /**
         * Check if the exception is related to authentication failure.
         */
        private function isAuthenticationError(Exception $e): bool
        {
            $msg = $e->getMessage();
            return (
                str_contains($msg, 'invalid_grant') || 
                str_contains($msg, 'expired') || 
                str_contains($msg, 'revoked') || 
                str_contains($msg, 'authentication')
            );
        }

        /**
         * @inheritdoc
         */
        public static function getEnvMapping(): array
        {
            return [
                'google' => [
                    'GOOGLE_CLIENT_ID'     => 'client_id',
                    'GOOGLE_CLIENT_SECRET' => 'client_secret',
                    'GOOGLE_REFRESH_TOKEN' => 'refresh_token',
                    'GOOGLE_USER_ID'       => 'user_id',
                    'GOOGLE_REDIRECT_URI'  => 'redirect_uri',
                    'GOOGLE_TOKEN_PATH'    => 'token_path',
                ]
            ];
        }
    }
