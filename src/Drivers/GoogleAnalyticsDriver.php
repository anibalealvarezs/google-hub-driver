<?php

    namespace Anibalealvarezs\GoogleHubDriver\Drivers;

    use Anibalealvarezs\ApiDriverCore\Interfaces\AuthProviderInterface;
    use Anibalealvarezs\ApiDriverCore\Interfaces\SyncDriverInterface;
    use Anibalealvarezs\ApiDriverCore\Routes\AssetRoutes;
    use Anibalealvarezs\ApiDriverCore\Services\ConfigSchemaRegistryService;
    use Anibalealvarezs\ApiDriverCore\Traits\SyncDriverTrait;
    use Anibalealvarezs\GoogleHubDriver\Controllers\GoogleAuthController;
    use DateTime;
    use Exception;
    use Psr\Log\LoggerInterface;
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpFoundation\Response;
    use Anibalealvarezs\ApiDriverCore\Interfaces\SeederInterface;
    use Anibalealvarezs\GoogleHubDriver\Enums\GoogleChannel;
    use Anibalealvarezs\GoogleHubDriver\Enums\GoogleEntityType;
    use Anibalealvarezs\ApiDriverCore\Interfaces\CanonicalMetricDictionaryProviderInterface;

    class GoogleAnalyticsDriver implements SyncDriverInterface, CanonicalMetricDictionaryProviderInterface
    {
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
            return [
                'success' => true,
                'message' => 'Status unknown for this driver.',
                'details' => []
            ];
        }

        public array $updatableCredentials = [
            'GOOGLE_REFRESH_TOKEN',
            'GOOGLE_USER_ID',
            'GOOGLE_CLIENT_ID',
            'GOOGLE_CLIENT_SECRET'
        ];

        private ?AuthProviderInterface $authProvider;
        private ?LoggerInterface $logger;

        public function __construct(?AuthProviderInterface $authProvider = null, ?LoggerInterface $logger = null)
        {
            $this->authProvider = $authProvider;
            $this->logger = $logger;
        }

        public function getChannel(): string
        {
            return GoogleChannel::ANALYTICS->value;
        }

        public function setAuthProvider(AuthProviderInterface $provider): void
        {
            $this->authProvider = $provider;
        }

        public function getAuthProvider(): ?AuthProviderInterface
        {
            return $this->authProvider;
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
        public function validateConfig(array $config): array
        {
            $config = ConfigSchemaRegistryService::hydrate(
                $this->getChannel(),
                'global',
                $config,
                $this->getConfigSchema()
            );

            $envOverrides = [
                'GOOGLE_CLIENT_ID'     => 'client_id',
                'GOOGLE_CLIENT_SECRET' => 'client_secret',
                'GOOGLE_REFRESH_TOKEN' => 'refresh_token',
                'GOOGLE_REDIRECT_URI'  => 'redirect_uri',
                'GOOGLE_USER_ID'       => 'user_id',
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
            // Placeholder for future implementation
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
        }
    }

