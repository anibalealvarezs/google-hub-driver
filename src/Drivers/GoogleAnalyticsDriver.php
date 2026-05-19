<?php

    namespace Anibalealvarezs\GoogleHubDriver\Drivers;

    use Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface;
    use Anibalealvarezs\ApiDriverCore\Routes\AssetRoutes;
    use Anibalealvarezs\ApiDriverCore\Traits\SyncDriverTrait;
    use Anibalealvarezs\GoogleHubDriver\Controllers\GoogleAuthController;
    use Anibalealvarezs\GoogleHubDriver\Traits\GoogleSyncDriverTrait;
    use DateTime;
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpFoundation\Response;
    use Anibalealvarezs\ApiDriverCore\Interfaces\SeederInterface;
    use Anibalealvarezs\GoogleHubDriver\Enums\GoogleChannel;
    use Anibalealvarezs\GoogleHubDriver\Enums\GoogleEntityType;
    use Anibalealvarezs\ApiDriverCore\Interfaces\CanonicalMetricDictionaryProviderInterface;

    class GoogleAnalyticsDriver implements SyncDriverInterface, CanonicalMetricDictionaryProviderInterface
    {
        use SyncDriverTrait, GoogleSyncDriverTrait {
            GoogleSyncDriverTrait::storeCredentials insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::getApi insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::boot insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::getCommonConfigKey insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::getDateFilterMapping insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::getProviderLabel insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::getProviderName insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::initializeApi insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::reset insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::getEnvMapping insteadof SyncDriverTrait;
            GoogleSyncDriverTrait::validateConfig insteadof SyncDriverTrait;

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
            return 'GoogleAnalytics';
        }

        /**
         * Get the display icon for the channel.
         *
         * @return string
         */
        public static function getChannelIcon(): string
        {
            return 'A';
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
                ]
            ]);
        }

        /**
         * Get the routes that should be whitelisted from rate limiting.
         *
         * @return array
         */
        public static function getRateLimitWhitelist(): array
        {
            return [
                '/ga-reports',
            ];
        }

        /**
         * @inheritdoc
         */
        public function fetchAvailableAssets(bool $throwOnError = false): array
        {
            return [];
        }

        /**
         * @inheritdoc
         */
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
                'message' => 'Status unknown for this driver.',
                'details' => []
            ];
        }

        public function getChannel(): string
        {
            return GoogleChannel::ANALYTICS->value;
        }

        public function sync(
            DateTime  $startDate,
            DateTime  $endDate,
            array     $config = [],
            ?callable $shouldContinue = null,
            ?callable $identityMapper = null
        ): Response

        {
            $this->logger->info("GoogleAnalyticsDriver: Placeholder sync for GA4.");

            return new Response(json_encode([
                'status'  => 'skipped',
                'message' => 'Google Analytics driver (Modular) placeholder executed successfully.'
            ]));
        }

        /**
         * @inheritdoc
         */
        public function getConfigSchema(): array
        {
            return [
                'global' => [
                    'enabled'             => false,
                    'cache_history_range' => '30 days',
                    'cache_aggregations'  => false,
                ],
                'entity' => [
                    'enabled' => true,
                ]
            ];
        }

        public function updateConfiguration(array $newData, array $currentConfig): array
        {
            if (isset($newData['granular_sync'])) {
                $currentConfig['granular_sync'] = filter_var($newData['granular_sync'], FILTER_VALIDATE_BOOLEAN);
            }

            return $currentConfig;
        }

        public function prepareUiConfig(array $channelConfig): array
        {
            return [
                'ga_granular_sync' => $channelConfig['granular_sync'] ?? false
            ];
        }

        /**
         * @inheritdoc
         */
        public function seedDemoData(SeederInterface $seeder, array $config = []): void
        {
            // Placeholder for future implementation
        }

        /**
         * @inheritdoc
         */
        public static function getAssetPatterns(): array
        {
            return [
                'google_business' => [
                    'key'          => 'locations',
                    'prefix'       => 'gb:location',
                    'hostnames'    => [],
                    'url_id_regex' => null,
                    'type'         => GoogleEntityType::LOCATION->value
                ]
            ];
        }

        /**
         * @inheritdoc
         */
        public static function getCanonicalMetricDictionary(): array
        {
            return [
                'conversions' => ['conversions'],
                'reach'       => ['totalUsers'],
                'impressions' => ['screenPageViews'],
            ];
        }

        /**
         * @inheritdoc
         */
        public static function getPageTypes(): array
        {
            return [
                GoogleEntityType::LOCATION->value => 'Google Business Profile'
            ];
        }

        /**
         * @inheritdoc
         */
        public function initializeEntities(array $config = []): array

        {
            return ['initialized' => 0, 'skipped' => 0];
        }

        /**
         * @inheritdoc
         */
        public static function getInstanceRules(): array
        {
            return [
                'history_months'     => 6,
                'entities_sync'      => false,
                'recent_cron_hour'   => 10,
                'recent_cron_minute' => 0,
            ];
        }

        /**
         * @return string
         */
        public static function getPlatformEntityIdField(): string
        {
            // TODO: Implement getPlatformEntityIdField() method.
            return '';
        }
    }

