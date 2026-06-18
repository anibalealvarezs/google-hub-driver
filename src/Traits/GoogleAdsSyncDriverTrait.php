<?php

    namespace Anibalealvarezs\GoogleHubDriver\Traits;

    use Anibalealvarezs\ApiDriverCore\Auth\BaseAuthProvider;
    use Anibalealvarezs\GoogleApi\Services\GoogleAds\GoogleAdsApi;
    use Exception;
    use ReflectionClass;

    trait GoogleAdsSyncDriverTrait
    {
        /**
         * @throws Exception
         */
        public function getApi(array $config = []): GoogleAdsApi
        {
            if (!$this->authProvider || !$this->authProvider->hasCredentials()) {
                throw new Exception("Credentials not configured.");
            }

            if (empty($config) && $this->authProvider instanceof BaseAuthProvider) {
                $config = $this->authProvider->getConfig();
            }

            return $this->initializeApi($config);
        }

        public static function getProviderLabel(): string
        {
            return 'Google Ads';
        }

        public static function getProviderName(): string
        {
            return 'google_ads';
        }

        public static function getCommonConfigKey(): ?string
        {
            return self::getProviderName();
        }

        /**
         * @throws Exception
         */
        protected function initializeApi(array $config): GoogleAdsApi
        {
            if (!$this->authProvider || !$this->authProvider->hasCredentials()) {
                throw new Exception("Credentials not configured.");
            }

            $className = (new ReflectionClass($this))->getShortName();
            $this->logger?->info("DEBUG: $className::initializeApi - START");

            $creds = $this->resolveGoogleCredentials($config);

            return new GoogleAdsApi(
                redirectUrl: $creds['redirectUrl'],
                clientId: $creds['clientId'],
                clientSecret: $creds['clientSecret'],
                refreshToken: $creds['refreshToken'],
                userId: $creds['userId'],
                developerToken: $config['developer_token'] ?? $config['google_ads']['developer_token'] ?? $_ENV['GOOGLE_ADS_DEVELOPER_TOKEN'] ?? getenv('GOOGLE_ADS_DEVELOPER_TOKEN') ?: '',
                loginCustomerId: $config['login_customer_id'] ?? $config['google_ads']['login_customer_id'] ?? $_ENV['GOOGLE_ADS_LOGIN_CUSTOMER_ID'] ?? getenv('GOOGLE_ADS_LOGIN_CUSTOMER_ID') ?: null,
                scopes: $creds['scopes'],
                token: $creds['token'],
                tokenPath: $creds['tokenPath'],
                logger: $this->logger,
                tokenRefresherCallback: $creds['tokenRefresherCallback']
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
                    'GOOGLE_CLIENT_ID'             => 'client_id',
                    'GOOGLE_CLIENT_SECRET'         => 'client_secret',
                    'GOOGLE_REFRESH_TOKEN'         => 'refresh_token',
                    'GOOGLE_USER_ID'               => 'user_id',
                    'GOOGLE_REDIRECT_URI'          => 'redirect_uri',
                    'GOOGLE_TOKEN_PATH'            => 'token_path',
                    'GOOGLE_ADS_DEVELOPER_TOKEN'   => 'developer_token',
                    'GOOGLE_ADS_LOGIN_CUSTOMER_ID' => 'login_customer_id',
                ]
            ];
        }
    }
