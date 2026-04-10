<?php

namespace Anibalealvarezs\GoogleHubDriver\Drivers;

use Anibalealvarezs\ApiSkeleton\Interfaces\AuthProviderInterface;
use Anibalealvarezs\ApiSkeleton\Interfaces\SyncDriverInterface;
use Anibalealvarezs\ApiSkeleton\Traits\HasUpdatableCredentials;
use DateTime;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Anibalealvarezs\ApiSkeleton\Interfaces\SeederInterface;

class GoogleAnalyticsDriver implements SyncDriverInterface
{
    use HasUpdatableCredentials;

    public array $updatableCredentials = [
        'GOOGLE_REFRESH_TOKEN',
        'GOOGLE_USER_ID',
        'GOOGLE_CLIENT_ID',
        'GOOGLE_CLIENT_SECRET'
    ];

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

    public function getAuthProvider(): ?AuthProviderInterface
    {
        return $this->authProvider;
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

    /**
     * @inheritdoc
     */
    public function getConfigSchema(): array
    {
        return [
            'global' => [
                'enabled' => true,
                'cache_history_range' => '30 days',
                'cache_aggregations' => false,
            ],
            'entity' => [
                'enabled' => true,
            ]
        ];
    }

    /**
     * @inheritdoc
     */
    public function validateConfig(array $config): array
    {
        return $config;
    }

    /**
     * @inheritdoc
     */
    public function seedDemoData(SeederInterface $seeder, array $config = []): void
    {
        // Placeholder for future implementation
    }
}
