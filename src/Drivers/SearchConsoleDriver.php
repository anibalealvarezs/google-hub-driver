<?php

namespace Anibalealvarezs\GoogleHubDriver\Drivers;

use Anibalealvarezs\ApiSkeleton\Helpers\DateHelper;
use Anibalealvarezs\ApiSkeleton\Interfaces\AuthProviderInterface;
use Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface;
use Anibalealvarezs\ApiSkeleton\Traits\HasUpdatableCredentials;
use Anibalealvarezs\GoogleApi\Services\SearchConsole\SearchConsoleApi;
use Anibalealvarezs\GoogleHubDriver\Conversions\GoogleSearchConsoleConvert;
use Carbon\Carbon;
use DateTime;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Anibalealvarezs\ApiDriverCore\Interfaces\SeederInterface;
use Doctrine\ORM\EntityManagerInterface;

class SearchConsoleDriver implements SyncDriverInterface
{

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
        $tokenPath = $_ENV['GOOGLE_TOKEN_PATH'] ?? getcwd() . '/storage/tokens/google_tokens.json';
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
        return 'google_search_console';
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

    public function sync(DateTime $startDate, DateTime $endDate, array $config = []): Response
    {
        if (!$this->authProvider) {
            throw new Exception("AuthProvider not set for SearchConsoleDriver");
        }

        if (!$this->dataProcessor) {
            throw new Exception("DataProcessor not set for SearchConsoleDriver");
        }

        if ($this->logger) {
            $this->logger->info("Starting SearchConsoleDriver sync (Modular)...");
        }

        try {
            $api = $this->initializeApi($config);
            $totalStats = ['metrics' => 0, 'rows' => 0, 'duplicates' => 0];

            $sitesToProcess = $config['google_search_console']['sites'] ?? [];
            
            foreach ($sitesToProcess as $site) {
                if (!($site['enabled'] ?? true)) continue;

                $siteUrl = $site['url'];
                if ($this->logger) {
                    $this->logger->info("Processing Google Search Console site: $siteUrl");
                }

                $period = Carbon::instance($startDate)->toPeriod($endDate, '1 day');
                foreach ($period as $day) {
                    $dayStr = $day->format('Y-m-d');
                    $rows = $this->fetchGSCDailyData($api, $siteUrl, $dayStr, $config);
                    
                    if (empty($rows)) continue;

                    // Convert raw data into metrics using the SDK
                    // We pass the site URL as the "page" identifier; the host will resolve the entity if needed.
                    $collection = GoogleSearchConsoleConvert::metrics(
                        rows: $rows,
                        siteUrl: $siteUrl,
                        siteKey: $siteUrl,
                        logger: $this->logger,
                        page: $siteUrl 
                    );

                    // Persist converted collection in the host
                    if ($this->dataProcessor && $collection->count() > 0) {
                        $result = ($this->dataProcessor)($collection, $this->logger);
                        
                        $totalStats['metrics'] += $result['metrics'] ?? $collection->count();
                        $totalStats['rows'] += $result['rows'] ?? count($rows);
                        $totalStats['duplicates'] += $result['duplicates'] ?? 0;
                    }
                }
            }

            return new Response(json_encode(['status' => 'success', 'data' => $totalStats]));

        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("SearchConsoleDriver error: " . $e->getMessage());
            }
            throw $e;
        }
    }

    private function fetchGSCDailyData(SearchConsoleApi $api, string $siteUrl, string $dayStr, array $config): array
    {
        $rowLimit = $config['google_search_console']['row_limit'] ?? 25000;
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
        return $this->initializeApi($config);
    }

    protected function initializeApi(array $config): SearchConsoleApi
    {
        $scopes = $this->authProvider->getScopes();
        $token = $this->authProvider->getAccessToken();

        return new SearchConsoleApi(
            redirectUrl: $config['google_search_console']['redirect_uri'] ?? $config['google']['redirect_uri'] ?? '',
            clientId: $config['google_search_console']['client_id'] ?? $config['google']['client_id'] ?? $_ENV['GOOGLE_CLIENT_ID'] ?? '',
            clientSecret: $config['google_search_console']['client_secret'] ?? $config['google']['client_secret'] ?? $_ENV['FACEBOOK_APP_SECRET'] ?? '', // Fixed typo from env mapping if exists
            refreshToken: $config['google_search_console']['refresh_token'] ?? $config['google']['refresh_token'] ?? '',
            userId: $config['google_search_console']['user_id'] ?? $config['google']['user_id'] ?? 'default',
            scopes: $scopes,
            token: $token,
            tokenPath: $config['google_search_console']['token_path'] ?? $config['google']['token_path'] ?? ""
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

        $em = $seeder->getEntityManager();
        
        $dates = $seeder->getDates(180);
        $countryEnumValues = ['USA', 'ESP', 'MEX', 'COL'];
        $deviceEnumValues = ['desktop', 'mobile', 'tablet'];
        $appearances = ['AMP_TOP_STORIES', 'PRODUCT_SNIPPETS', 'REVIEW_SNIPPET', 'VIDEO', 'ORGANIC_SHOPPING'];

        $dimManager = $seeder->getDimensionManager();

        // Pre-fetch Universal Entities
        $countries = [];
        foreach ($countryEnumValues as $code) {
            $enumClass = $seeder->getEnumClass('country');
            $countryClass = $seeder->getEntityClass('country');
            $enum = $enumClass::from($code);
            $c = $em->getRepository($countryClass)->findOneBy(['code' => $enum]);
            if (!$c) {
                $c = (new $countryClass())->addCode($enum)->addName($code);
                $em->persist($c);
            }
            $countries[$code] = $c;
        }
        $devices = [];
        foreach ($deviceEnumValues as $type) {
            $enumClass = $seeder->getEnumClass('device');
            $deviceClass = $seeder->getEntityClass('device');
            $enum = $enumClass::from($type);
            $d = $em->getRepository($deviceClass)->findOneBy(['type' => $enum]);
            if (!$d) {
                $d = (new $deviceClass())->addType($enum);
                $em->persist($d);
            }
            $devices[$type] = $d;
        }
        $em->flush();

        $faker = \Faker\Factory::create('en_US');
        $pageClass = $seeder->getEntityClass('page');
        $chanEnumClass = $seeder->getEnumClass('channel');
        $gscChan = $chanEnumClass::google_search_console;

        for ($s = 1; $s <= 10; $s++) {
            $hostname = "blog" . $s . ".demo-agency.com";
            $siteName = "Brand Blog $s ($hostname)";

            $property = $em->getRepository($pageClass)->findOneBy(['platformId' => $hostname]);
            if (!$property) {
                $property = (new $pageClass())->addUrl("https://$hostname")->addTitle($siteName)->addHostname($hostname)->addPlatformId($hostname)->addCanonicalId($hostname);
                $em->persist($property);
                $em->flush();
            }

            $childUrls = [];
            for ($i = 0; $i < 20; $i++) {
                $childUrls[] = "https://$hostname/article-" . $faker->slug();
            }

            $queries = [];
            for ($i = 0; $i < 30; $i++) {
                $queries[] = $faker->words(rand(1, 4), true);
            }

            foreach ($dates as $date) {
                for ($j = 0; $j < 8; $j++) {
                    $url = $childUrls[array_rand($childUrls)];
                    $qStr = $queries[array_rand($queries)];
                    $code = $countryEnumValues[array_rand($countryEnumValues)];
                    $type = $deviceEnumValues[array_rand($deviceEnumValues)];

                    $country = $countries[$code];
                    $device = $devices[$type];
                    $appearance = $appearances[array_rand($appearances)];

                    $dimensionSet = $dimManager->resolveDimensionSet([
                        ['dimensionKey' => 'page', 'dimensionValue' => $url],
                        ['dimensionKey' => 'query', 'dimensionValue' => $qStr],
                        ['dimensionKey' => 'searchAppearance', 'dimensionValue' => $appearance],
                    ]);
                    $setId = $dimensionSet->getId();

                    $imps = rand(10, 200);
                    $clicks = (int)($imps * rand(1, 10) / 100);
                    $pos = (float)rand(10, 80) / 10;
                    $data = ['impressions' => $imps, 'clicks' => $clicks, 'ctr' => $imps > 0 ? $clicks / $imps : 0, 'position' => $pos, 'keys' => [$url, $qStr, $code, $type, $appearance]];

                    foreach (['impressions', 'clicks', 'ctr', 'position'] as $name) {
                        $seeder->queueMetric(
                            channel: $gscChan,
                            name: $name,
                            date: $date,
                            value: $data[$name],
                            setId: $setId,
                            pageId: $property->getId(),
                            countryId: $country->getId(),
                            deviceId: $device->getId(),
                            data: json_encode($data),
                            pageUrl: $property->getUrl(),
                            countryPId: $code,
                            devicePId: $type,
                            setHash: $dimensionSet->getHash()
                        );
                    }
                }
            }
            $em->clear();
            $dimManager->clearCaches();
            if ($output) $output->writeln("   - Site $hostname complete.");
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
            'website' => [
                'prefix' => 'web:site',
                'hostnames' => [],
                'url_id_regex' => null
            ]
        ];
    }
}

