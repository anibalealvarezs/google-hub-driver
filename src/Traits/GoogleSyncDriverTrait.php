<?php

    namespace Anibalealvarezs\GoogleHubDriver\Traits;

    use Anibalealvarezs\ApiDriverCore\Auth\BaseAuthProvider;
    use Anibalealvarezs\ApiDriverCore\Interfaces\AuthProviderInterface;
    use Anibalealvarezs\ApiDriverCore\Services\ConfigSchemaRegistryService;
    use Anibalealvarezs\GoogleApi\Services\SearchConsole\SearchConsoleApi;
    use Closure;
    use Exception;
    use Psr\Log\LoggerInterface;
    use ReflectionClass;

    trait GoogleSyncDriverTrait
    {

        private ?AuthProviderInterface $authProvider;
        private ?LoggerInterface $logger;
        /** @var callable|null */
        private $dataProcessor = null;
        public array $updatableCredentials = [
            'GOOGLE_REFRESH_TOKEN',
            'GOOGLE_USER_ID',
            'GOOGLE_CLIENT_ID',
            'GOOGLE_CLIENT_SECRET'
        ];

        public function __construct(
            ?AuthProviderInterface $authProvider = null,
            ?LoggerInterface       $logger = null,
        )
        {
            $this->authProvider = $authProvider;
            $this->logger = $logger;
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

        public function boot(): void
        {
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

        public static function getProviderLabel(): string
        {
            return 'Google';
        }

        public static function getProviderName(): string
        {
            return 'google';
        }

        public static function getCommonConfigKey(): ?string
        {
            return self::getProviderName();
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
        protected function initializeApi(array $config): SearchConsoleApi
        {
            $className = (new ReflectionClass($this))->getShortName();
            $this->logger?->info("DEBUG: $className::initializeApi - START");
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
                self::getProviderName() => [
                    'GOOGLE_CLIENT_ID'     => 'client_id',
                    'GOOGLE_CLIENT_SECRET' => 'client_secret',
                    'GOOGLE_REFRESH_TOKEN' => 'refresh_token',
                    'GOOGLE_USER_ID'       => 'user_id',
                    'GOOGLE_REDIRECT_URI'  => 'redirect_uri',
                    'GOOGLE_TOKEN_PATH'    => 'token_path',
                ]
            ];
        }

        /**
         * @inheritdoc
         * @throws Exception
         */
        public function reset(string $mode = 'all', array $config = []): array
        {
            $resetCallback = $config['resetCallback'] ?? null;
            if ($resetCallback instanceof Closure) {
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
        public function validateConfig(array $config): array
        {
            $config = ConfigSchemaRegistryService::hydrate(
                $this->getChannel(),
                'global',
                $config,
                $this->getConfigSchema()
            );

            $envOverrides = $this->getEnvMapping();

            foreach ($envOverrides as $envKey => $configPath) {
                $val = getenv($envKey);
                if ($val !== false && $val !== '') {
                    $config[$configPath] = $val;
                }
            }

            return $config;
        }
    }