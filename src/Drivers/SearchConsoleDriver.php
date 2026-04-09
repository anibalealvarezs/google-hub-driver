<?php

namespace Anibalealvarezs\GoogleHubDriver\Drivers;

use Anibalealvarezs\ApiSkeleton\Helpers\DateHelper;
use Anibalealvarezs\ApiSkeleton\Interfaces\AuthProviderInterface;
use Anibalealvarezs\ApiSkeleton\Interfaces\SyncDriverInterface;
use Anibalealvarezs\GoogleApi\Services\SearchConsole\SearchConsoleApi;
use Anibalealvarezs\GoogleApi\Conversions\GoogleSearchConsoleConvert;
use Carbon\Carbon;
use DateTime;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class SearchConsoleDriver implements SyncDriverInterface
{
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
}
