<?php

namespace Anibalealvarezs\GoogleHubDriver\Drivers;

use Anibalealvarezs\ApiDriverCore\Helpers\DateHelper;
use Anibalealvarezs\ApiDriverCore\Interfaces\AuthProviderInterface;
use Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface;
use Anibalealvarezs\ApiDriverCore\Traits\HasUpdatableCredentials;
use Anibalealvarezs\GoogleApi\Services\SearchConsole\SearchConsoleApi;
use Anibalealvarezs\GoogleHubDriver\Conversions\GoogleSearchConsoleConvert;
use Carbon\Carbon;
use DateTime;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Anibalealvarezs\ApiDriverCore\Interfaces\SeederInterface;

use Anibalealvarezs\ApiDriverCore\Enums\HierarchyType;
use Anibalealvarezs\GoogleHubDriver\Enums\GoogleChannel;
use Anibalealvarezs\GoogleHubDriver\Enums\GoogleEntityType;
use Anibalealvarezs\GoogleHubDriver\Enums\GoogleFeature;

class SearchConsoleDriver implements SyncDriverInterface
{
    use \Anibalealvarezs\ApiDriverCore\Traits\HasHierarchicalValidationTrait;
    use \Anibalealvarezs\ApiDriverCore\Traits\SyncDriverTrait;

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
        $tokenPath = $_ENV['GOOGLE_TOKEN_PATH'] ?? getenv('GOOGLE_TOKEN_PATH') ?: (getcwd() . '/storage/tokens/google_tokens.json');
        $tokenKey = 'google_auth';
        
        if (!is_dir(dirname($tokenPath))) {
            mkdir(dirname($tokenPath), 0755, true);
        }

        $tokens = file_exists($tokenPath) ? (json_decode(file_get_contents($tokenPath), true) ?? []) : [];
        
        $tokens[$tokenKey] = [
            'access_token' => $credentials['access_token'] ?? null,
            'refresh_token' => $credentials['refresh_token'] ?? null,
            'user_id' => $credentials['user_id'] ?? null,
            'scopes' => $credentials['scopes'] ?? [],
            'updated_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+3600 seconds'))
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

    /**
     * Get the display label for the channel.
     * 
     * @return string
     */
    public static function getChannelLabel(): string
    {
        return 'GoogleSearchConsole';
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
        return array_merge(\Anibalealvarezs\ApiDriverCore\Routes\AssetRoutes::get(), [
            '/google-login' => [
                'httpMethod' => 'GET',
                'callable' => fn(...$args) => (new \Anibalealvarezs\GoogleHubDriver\Controllers\GoogleAuthController())->login(),
                'public' => true,
                'admin' => false,
                'html' => true
            ],
            '/google-auth-start' => [
                'httpMethod' => 'GET',
                'callable' => fn(...$args) => (new \Anibalealvarezs\GoogleHubDriver\Controllers\GoogleAuthController())->start(),
                'public' => true,
                'admin' => false
            ],
            '/google-callback' => [
                'httpMethod' => 'GET',
                'callable' => fn(...$args) => (new \Anibalealvarezs\GoogleHubDriver\Controllers\GoogleAuthController())->callback($args['request'] ?? \Symfony\Component\HttpFoundation\Request::createFromGlobals()),
                'public' => true,
                'admin' => false,
                'html' => true
            ],
            '/gsc-reports' => [
                'httpMethod' => 'GET',
                'callable' => fn(...$args) => (new \Anibalealvarezs\GoogleHubDriver\Controllers\ReportController())->index($args),
                'public' => true,
                'admin' => false,
                'html' => true
            ]
        ]);
    }

    /**
     * @inheritdoc
     */
    public function fetchAvailableAssets(bool $throwOnError = false): array
    {
        if (!$this->authProvider) {
            return [];
        }

        try {
            $api = $this->getApi();
            $sitesResponse = $api->getSites();
            $assets = ['gsc' => []];
            
            if (isset($sitesResponse['siteEntry'])) {
                foreach ($sitesResponse['siteEntry'] as $entry) {
                    $url = $entry['siteUrl'];
                    $assets['gsc'][] = [
                        'url' => $url,
                        'title' => $this->deriveTitleFromUrl($url),
                        'hostname' => $this->deriveHostnameFromUrl($url),
                        'permissionLevel' => $entry['permissionLevel'] ?? 'siteRestrictedUser',
                    ];
                }
            }
            return $assets;
        } catch (\Exception $e) {
            if ($this->logger) $this->logger->error("SearchConsoleDriver: Error fetching available assets: " . $e->getMessage());
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
        return parse_url(str_replace('sc-domain:', 'http://', $url), PHP_URL_HOST) ?? $url;
    }

    /**
     * Normalize a GSC site URL for comparison.
     */
    public function normalizeGscUrl(?string $url): string
    {
        if (!$url) return '';
        return rtrim(strtolower($url), '/');
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

        // Redis cache toggle
        if (isset($featureToggles['cache_aggregations'])) {
            $prevValue = (bool)($chanCfg['cache_aggregations'] ?? false);
            $newValue = (bool)$featureToggles['cache_aggregations'];
            $chanCfg['cache_aggregations'] = $newValue;
            
            if ($prevValue && !$newValue && class_exists('\Anibalealvarezs\ApiDriverCore\Services\CacheStrategyService')) {
                \Anibalealvarezs\ApiDriverCore\Services\CacheStrategyService::clearChannel(GoogleChannel::SEARCH_CONSOLE->value);
            }
        }

        // Sites management
        $currentSites = $chanCfg['sites'] ?? [];
        $newSitesList = [];
        $selectedMap = [];
        foreach ($selectedSites as $sel) {
            $normUrl = $this->normalizeGscUrl($sel['url']);
            $selectedMap[$normUrl] = $sel;
        }

        $processedNormUrls = [];
        foreach ($currentSites as $site) {
            $normUrl = $this->normalizeGscUrl($site['url']);
            if (isset($selectedMap[$normUrl])) {
                $site['target_countries'] = $selectedMap[$normUrl]['target_countries'] ?? [];
                $site['target_keywords'] = $selectedMap[$normUrl]['target_keywords'] ?? [];
                $site['lost_access'] = filter_var($selectedMap[$normUrl]['lost_access'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $newSitesList[] = $site;
                $processedNormUrls[] = $normUrl;
            }
        }

        foreach ($selectedSites as $sel) {
            $normUrl = $this->normalizeGscUrl($sel['url']);
            if (!in_array($normUrl, $processedNormUrls)) {
                $newSitesList[] = [
                    'url' => $sel['url'],
                    'title' => $this->deriveTitleFromUrl($sel['url']),
                    'hostname' => $this->deriveHostnameFromUrl($sel['url']),
                    'enabled' => true,
                    'target_countries' => $sel['target_countries'] ?? [],
                    'target_keywords' => $sel['target_keywords'] ?? [],
                    'lost_access' => filter_var($sel['lost_access'] ?? false, FILTER_VALIDATE_BOOLEAN),
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
    use HasUpdatableCredentials;

    public array $updatableCredentials = [
        'GOOGLE_REFRESH_TOKEN',
        'GOOGLE_USER_ID',
        'GOOGLE_CLIENT_ID',
        'GOOGLE_CLIENT_SECRET'
    ];

    private ?AuthProviderInterface $authProvider = null;
    private ?LoggerInterface $logger = null;
    /** @var callable|null */
    private $dataProcessor = null;

    // Dimensions from legacy GoogleSearchConsoleHelpers
    private static array $allDimensions = ['date', 'query', 'country', 'page', 'device'];
    private static array $optionalDimensions = ['query', 'country', 'device'];

    public function __construct(
        ?AuthProviderInterface $authProvider = null, 
        ?LoggerInterface $logger = null,
    ) {
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

    public function sync(
        DateTime $startDate,
        DateTime $endDate,
        array $config = [],
        ?callable $shouldContinue = null,
        ?callable $identityMapper = null
    ): Response {
        if (!$this->authProvider) {
            throw new Exception("AuthProvider not set for SearchConsoleDriver");
        }

        if (!$this->dataProcessor) {
            throw new Exception("DataProcessor not set for SearchConsoleDriver");
        }

        try {
            $api = $this->initializeApi($config);
            $totalStats = ['metrics' => 0, 'rows' => 0, 'duplicates' => 0];

            $sitesToProcess = $config['sites'] ?? $config[GoogleChannel::SEARCH_CONSOLE->value]['sites'] ?? [];
            
            // 1. Batch Resolve Identities via Oracle
            $pageMap = [];
            $caMap = [];
            if ($identityMapper && !empty($sitesToProcess)) {
                foreach ($sitesToProcess as $site) {
                    $u = (string)($site['url'] ?? $site);
                    $urls[] = $u;
                    $caPlatformIds[] = md5(rtrim($u, '/'));
                }
                $pageMap = $identityMapper('pages', ['urls' => $urls]) ?? [];
                $caMap = $identityMapper('channeled_accounts', ['platform_ids' => $caPlatformIds]) ?? [];
            }

            foreach ($sitesToProcess as $site) {
                $siteUrl = (string)($site['url'] ?? $site);
                if (!($site['enabled'] ?? true) && is_array($site)) continue;

                $pLevel = $site['permissionLevel'] ?? null;
                if (!$pLevel && is_array($site)) {
                    // Try to find it in the resolved channeled account later
                }
                if ($pLevel && in_array($pLevel, ['siteRestrictedUser', 'siteUnverifiedUser'])) {
                    $this->logger?->warning("--- SKIP: Permission insufficient for $siteUrl ($pLevel)");
                    continue;
                }

                $caPlatformId = md5(rtrim($siteUrl, '/'));
                $ca = $caMap[$caPlatformId] ?? null;
                if (!is_object($ca)) {
                    $ca = (new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity())->setPlatformId($caPlatformId);
                }

                if (!$pLevel && is_object($ca) && method_exists($ca, 'getData')) {
                    $pLevel = $ca->getData()['permissionLevel'] ?? null;
                    if ($pLevel && in_array($pLevel, ['siteRestrictedUser', 'siteUnverifiedUser'])) {
                        $this->logger?->warning("--- SKIP: Permission insufficient for $siteUrl ($pLevel) [from metadata]");
                        continue;
                    }
                }
                $page = $pageMap[$caPlatformId] ?? null;
                if (!is_object($page)) {
                    $page = (new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity())->setPlatformId($caPlatformId);
                }
                $siteKey = is_object($page) && method_exists($page, 'getCanonicalId') ? $page->getCanonicalId() : (is_object($page) ? $page->getPlatformId() : $siteUrl);

                try {
                    $period = Carbon::instance($startDate)->toPeriod($endDate, '1 day');
                    foreach ($period as $day) {
                        if ($shouldContinue && !$shouldContinue()) {
                            throw new Exception("Sync aborted by the orchestrator.");
                        }
                        $dayStr = $day->format('Y-m-d');
                        $this->logger?->info(">>> INICIO: Sincronizando GSC para Sitio: $siteUrl (Día: $dayStr)");
                        $rows = $this->fetchGSCDailyData($api, $siteUrl, $dayStr, $config);
                        
                        if (empty($rows)) {
                            $this->logger?->info("--- INFO: No se encontraron datos GSC para Sitio: $siteUrl (Día: $dayStr)");
                            continue;
                        }

                        $mainAccount = $accountMap['Google Search Console'] ?? null;
                        $caObject = is_object($ca) ? $ca : (new \Anibalealvarezs\ApiDriverCore\Classes\UniversalEntity())->setPlatformId($caPlatformId)->setContext(['account' => $mainAccount]);
                        $accObject = (is_object($caObject) && method_exists($caObject, 'getAccount')) ? $caObject->getAccount() : ($caObject->getContext()['account'] ?? $mainAccount);

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
                            $totalStats['rows'] += $rowCount = count($rows);
                            $totalStats['duplicates'] += $result['duplicates'] ?? 0;

                            $this->logger?->info("+++ ÉXITO: Sincronizados $rowCount registros GSC para $siteUrl el $dayStr");
                        }
                    }
                } catch (Exception $e) {
                    $this->logger?->error("!!! ERROR: Fallo al sincronizar GSC para $siteUrl: " . $e->getMessage());
                }
            }

            return new Response(json_encode([
                'status' => 'success',
                'message' => 'Search Console sync completed',
                'stats' => $totalStats
            ]));

        } catch (Exception $e) {
            $this->logger?->critical("!!!! ERROR CRÍTICO: SearchConsoleDriver falló: " . $e->getMessage());
            throw $e;
        }
    }

    private function fetchGSCDailyData(SearchConsoleApi $api, string $siteUrl, string $dayStr, array $config): array
    {
        $rowLimit = $config[GoogleChannel::SEARCH_CONSOLE->value]['row_limit'] ?? 25000;
        $allFetchedData = [];

        $dimensionsSubsets = $this->getAllSubsets(self::$optionalDimensions);
        foreach ($dimensionsSubsets as $dimensionsSubset) {
            $actualDimensionsSubset = array_merge(array_diff(self::$allDimensions, self::$optionalDimensions), $dimensionsSubset);

            $maxRetries = 3;
            $retryCount = 0;
            $fetched = false;
            
            while ($retryCount < $maxRetries && !$fetched) {
                try {
                    $rows = $api->getAllSearchQueryResults(
                        siteUrl: $siteUrl,
                        startDate: $dayStr,
                        endDate: $dayStr,
                        rowLimit: $rowLimit,
                        dimensions: $actualDimensionsSubset
                    );
                    
                    if (!empty($rows['rows'])) {
                        foreach ($rows['rows'] as $row) {
                            $allFetchedData[] = array_merge($row, ['subset' => $actualDimensionsSubset]);
                        }
                    }
                    $fetched = true;
                } catch (Exception $e) {
                    $retryCount++;
                    if ($retryCount >= $maxRetries) throw $e;
                    usleep(500000 * $retryCount);
                }
            }
        }

        return $allFetchedData;
    }


    public function getApi(array $config = []): SearchConsoleApi
    {
        if (empty($config) && $this->authProvider instanceof \Anibalealvarezs\ApiDriverCore\Auth\BaseAuthProvider) {
            $config = $this->authProvider->getConfig();
        }
        return $this->initializeApi($config);
    }

    protected function initializeApi(array $config): SearchConsoleApi
    {
        $this->logger?->info("DEBUG: SearchConsoleDriver::initializeApi - START");
        $scopes = $this->authProvider->getScopes();
        $token = $this->authProvider->getAccessToken();
        
        $providerConfig = [];
        if ($this->authProvider instanceof \Anibalealvarezs\ApiDriverCore\Auth\BaseAuthProvider) {
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
            'global' => [
                'enabled' => false,
                'cache_history_range' => '16 months',
                'cache_aggregations' => false,
            ],
            'entity' => [
                'url' => '',
                'title' => '',
                'hostname' => '',
                'enabled' => true,
                'target_countries' => [],
                'target_keywords' => [],
                'include_keywords' => [],
                'exclude_keywords' => [],
                'include_countries' => [],
                'exclude_countries' => [],
                'include_pages' => [],
                'exclude_pages' => [],
                'lost_access' => false,
            ],
            'metrics' => [
                'clicks' => ['enabled' => true, 'format' => 'number', 'precision' => 0],
                'impressions' => ['enabled' => true, 'format' => 'number', 'precision' => 0],
                'ctr' => ['enabled' => true, 'format' => 'percent', 'precision' => 2],
                'position' => ['enabled' => true, 'format' => 'number', 'precision' => 1, 'sparkline_direction' => 'inverted'],
            ]
        ];
    }

    /**
     * @inheritdoc
     */
    /**
     * @inheritdoc
     */
    public function validateConfig(array $config): array
    {
        $config = \Anibalealvarezs\ApiDriverCore\Services\ConfigSchemaRegistryService::hydrate(
            $this->getChannel(),
            'global',
            $config,
            $this->getConfigSchema()
        );

        $envOverrides = [
            'GOOGLE_CLIENT_ID' => 'client_id',
            'GOOGLE_CLIENT_SECRET' => 'client_secret',
            'GOOGLE_REFRESH_TOKEN' => 'refresh_token',
            'GOOGLE_REDIRECT_URI' => 'redirect_uri',
            'GOOGLE_USER_ID' => 'user_id',
            'GOOGLE_SEARCH_CONSOLE_CLIENT_ID' => 'client_id',
            'GOOGLE_SEARCH_CONSOLE_CLIENT_SECRET' => 'client_secret',
            'GOOGLE_SEARCH_CONSOLE_REFRESH_TOKEN' => 'refresh_token',
            'GOOGLE_SEARCH_CONSOLE_TOKEN' => 'token',
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
        $appearances = ['AMP_TOP_STORIES', 'PRODUCT_SNIPPETS', 'REVIEW_SNIPPET', 'VIDEO', 'ORGANIC_SHOPPING'];

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

        $faker = \Faker\Factory::create('en_US');
        $gscChan = GoogleChannel::SEARCH_CONSOLE;

        $gscAcc = $seeder->resolveEntity('account', ['name' => 'Demo Agency GSC']);

        for ($s = 1; $s <= 10; $s++) {
            $sitePId = "https://demo-site-$s.com/";
            $site = $seeder->resolveEntity('page', [
                'platformId' => $sitePId,
                'account' => $gscAcc,
                'title' => "Demo Site $s",
                'url' => $sitePId,
                'canonicalId' => $sitePId
            ]);

            $ca = $seeder->resolveEntity('channeled_account', [
                'platformId' => $sitePId,
                'account' => $gscAcc,
                'type' => GoogleEntityType::SITE->value,
                'channel' => GoogleChannel::SEARCH_CONSOLE->value,
                'name' => "Demo Site $s"
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
                                caId: $ca->id,
                                gAccId: $gscAcc->id,
                                pageId: $site->id,
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
    public function getAssetPatterns(): array
    {
        return [
            'gsc' => [
                'prefix' => 'sc',
                'hostnames' => [],
                'url_id_regex' => null,
                'type' => GoogleEntityType::SITE->value,
                'key' => 'sites'
            ]
        ];
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
        return [__DIR__ . '/../Entities'];
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
        
        $ui['gsc'] = [];
        foreach (($channelConfig['sites'] ?? []) as $site) {
            $url = $site['url'];
            if (class_exists('\Anibalealvarezs\ApiDriverCore\Services\ConfigSchemaRegistryService')) {
                $ui['gsc'][$url] = \Anibalealvarezs\ApiDriverCore\Services\ConfigSchemaRegistryService::hydrate('google_search_console', 'entity', $site);
            } else {
                $ui['gsc'][$url] = $site;
            }
        }
        return $ui;
    }

    /**
     * @inheritdoc
     */
    public function initializeEntities(array $config = []): array
    {
        return $this->fetchAvailableAssets(throwOnError: true);
    }

    /**
     * @inheritdoc
     */
    public function reset(string $mode = 'all', array $config = []): array
    {
        $resetCallback = $config['resetCallback'] ?? null;
        if ($resetCallback instanceof \Closure) {
            return $resetCallback($this->getChannel(), $mode);
        }

        throw new Exception("Reset callback not provided for " . $this->getChannel()->name);
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
            'history_months' => 16,
            'entities_sync' => false,
            'recent_cron_hour' => 7,
            'recent_cron_minute' => 0,
        ];
    }
}

