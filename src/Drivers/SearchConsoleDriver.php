<?php

namespace Anibalealvarezs\GoogleHubDriver\Drivers;

use Anibalealvarezs\ApiSkeleton\Helpers\DateHelper;
use Anibalealvarezs\ApiSkeleton\Interfaces\AuthProviderInterface;
use Anibalealvarezs\ApiSkeleton\Interfaces\SyncDriverInterface;
use Anibalealvarezs\GoogleApi\Services\SearchConsole\SearchConsoleApi;
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

    public function __construct(
        ?AuthProviderInterface $authProvider = null, 
        ?LoggerInterface $logger = null,
        ?callable $dataProcessor = null
    ) {
        $this->authProvider = $authProvider;
        $this->logger = $logger;
        $this->dataProcessor = $dataProcessor;
    }

    public function getChannel(): string
    {
        return 'google_search_console';
    }

    public function setAuthProvider(AuthProviderInterface $provider): void
    {
        $this->authProvider = $provider;
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

        $this->logger->info("Starting SearchConsoleDriver sync...");

        try {
            $api = $this->initializeApi($config);
            $totalStats = ['metrics' => 0, 'rows' => 0, 'duplicates' => 0];

            $sitesToProcess = $config['google_search_console']['sites'] ?? [];
            $chunkSize = $config['google_search_console']['cache_chunk_size'] ?? '1 day';

            // Partition work by site
            foreach ($sitesToProcess as $site) {
                if (!($site['enabled'] ?? true)) continue;

                $siteUrl = $site['url'];
                $this->logger->info("Processing Google Search Console site: $siteUrl");

                // Use DateHelper to chunk the date range
                $chunks = DateHelper::getDateChunks($startDate->format('Y-m-d'), $endDate->format('Y-m-d'), $chunkSize);

                foreach ($chunks as $chunk) {
                    $this->logger->info("Processing chunk: {$chunk['start']} to {$chunk['end']} for $siteUrl");

                    // Call the host's data processor for this chunk
                    $result = ($this->dataProcessor)(
                        site: $site,
                        startDate: $chunk['start'],
                        endDate: $chunk['end'],
                        api: $api,
                        config: $config
                    );

                    $totalStats['metrics'] += $result['metrics'] ?? 0;
                    $totalStats['rows'] += $result['rows'] ?? 0;
                    $totalStats['duplicates'] += $result['duplicates'] ?? 0;
                }
            }

            return new Response(json_encode([
                'status' => 'success', 
                'data' => $totalStats
            ]));

        } catch (Exception $e) {
            $this->logger->error("SearchConsoleDriver error: " . $e->getMessage());
            throw $e;
        }
    }

    private function initializeApi(array $config): SearchConsoleApi
    {
        return new SearchConsoleApi(
            redirectUrl: $config['google_search_console']['redirect_uri'] ?? '',
            clientId: $_ENV['GOOGLE_CLIENT_ID'] ?? '',
            clientSecret: $_ENV['GOOGLE_CLIENT_SECRET'] ?? '',
            refreshToken: $config['google_search_console']['refresh_token'] ?? '',
            userId: $config['google_search_console']['user_id'] ?? '',
            scopes: $this->authProvider->getScopes(),
            token: $this->authProvider->getAccessToken(),
            tokenPath: $config['google_search_console']['token_path'] ?? ""
        );
    }
}
