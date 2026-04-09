<?php

namespace Anibalealvarezs\GoogleHubDriver\Drivers;

use Anibalealvarezs\ApiSkeleton\Interfaces\AuthProviderInterface;
use Anibalealvarezs\ApiSkeleton\Interfaces\SyncDriverInterface;
use DateTime;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class GoogleAnalyticsDriver implements SyncDriverInterface
{
    private ?AuthProviderInterface $authProvider = null;
    private ?LoggerInterface $logger = null;

    public function __construct(?AuthProviderInterface $authProvider = null, ?LoggerInterface $logger = null)
    {
        $this->authProvider = $authProvider;
        $this->logger = $logger;
    }

    public function getChannel(): string
    {
        return 'google_analytics';
    }

    public function setAuthProvider(AuthProviderInterface $provider): void
    {
        $this->authProvider = $provider;
    }

    public function sync(DateTime $startDate, DateTime $endDate, array $config = []): Response
    {
        $this->logger->info("GoogleAnalyticsDriver: Placeholder sync for GA4.");
        
        return new Response(json_encode([
            'status' => 'skipped',
            'message' => 'Google Analytics driver (Modular) placeholder executed successfully.'
        ]));
    }

    public function getApi(array $config = []): mixed
    {
        return null;
    }
}
