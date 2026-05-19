<?php

declare(strict_types=1);

namespace Anibalealvarezs\GoogleHubDriver\Auth;

use Anibalealvarezs\ApiDriverCore\Auth\BaseAuthProvider;
use Anibalealvarezs\ApiDriverCore\Interfaces\AuthProviderInterface;
use Anibalealvarezs\ApiDriverCore\Interfaces\OAuthProviderInterface;

class GoogleAuthProvider extends BaseAuthProvider implements AuthProviderInterface, OAuthProviderInterface
{
    public function __construct(array|string $configOrPath = "")
    {
        if (is_array($configOrPath)) {
            $configOrPath = $configOrPath['token_path'] ?? $_ENV['GOOGLE_TOKEN_PATH'] ?? getenv('GOOGLE_TOKEN_PATH') ?: (getcwd() . '/storage/tokens/google_tokens.json');
        } elseif (!$configOrPath || (is_string($configOrPath) && empty($configOrPath))) {
            $configOrPath = $_ENV['GOOGLE_TOKEN_PATH'] ?? getenv('GOOGLE_TOKEN_PATH') ?: (getcwd() . '/storage/tokens/google_tokens.json');
        }
        
        parent::__construct($configOrPath);
    }

    /**
     * @inheritdoc
     */
    public function getUserId(): string
    {
        return $this->data['google_auth']['user_id'] ?? "";
    }

    /**
     * @inheritdoc
     */
    public function getAccessToken(): string
    {
        if ($this->isExpired()) {
            $this->refresh();
        }
        return $this->data['google_auth']['access_token'] ?? "";
    }

    /**
     * @inheritdoc
     */
    public function getScopes(): array
    {
        return $this->data['google_auth']['scopes'] ?? [];
    }

    /**
     * @inheritdoc
     */
    public function setAccessToken(string $token): void
    {
        if (!isset($this->data['google_auth'])) {
            $this->data['google_auth'] = [];
        }
        $this->data['google_auth']['access_token'] = $token;
        $this->save();
    }

    /**
     * @inheritdoc
     */
    public function isValid(): bool
    {
        return !empty($this->getAccessToken());
    }

    public function hasCredentials(): bool
    {
        if (!$this->filePath || !file_exists($this->filePath)) {
            return false;
        }
        return !empty($this->data['google_auth']['access_token']) || !empty($this->data['google_auth']['refresh_token']);
    }

    /**
     * @inheritdoc
     */
    public function isExpired(): bool
    {
        $expiry = $this->data['google_auth']['expires_at'] ?? null;
        if (!$expiry) return false;
        return strtotime($expiry) < time();
    }

    /**
     * @inheritdoc
     */
    public function refresh(): bool
    {
        if (!$this->filePath || !file_exists($this->filePath)) {
            return false;
        }

        $refreshToken = $this->data['google_auth']['refresh_token'] 
            ?? $this->data['google']['refresh_token'] 
            ?? $_ENV['GOOGLE_REFRESH_TOKEN'] 
            ?? null;

        if (!$refreshToken) return false;

        $clientId = $this->data['client_id'] ?? $_ENV['GOOGLE_CLIENT_ID'] ?? '';
        $clientSecret = $this->data['client_secret'] ?? $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';

        $tokenUrl = "https://oauth2.googleapis.com/token";
        
        $postData = [
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'refresh_token',
        ];

        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($postData),
                'timeout' => 5.0,
            ],
        ];

        $context  = stream_context_create($options);
        $result = @file_get_contents($tokenUrl, false, $context);
        
        if ($result === false) return false;

        $response = json_decode($result, true);
        if (empty($response['access_token'])) return false;

        if (!isset($this->data['google_auth'])) {
            $this->data['google_auth'] = [];
        }

        $this->data['google_auth']['access_token'] = $response['access_token'];
        $this->data['google_auth']['updated_at'] = date('Y-m-d H:i:s');
        $this->data['google_auth']['expires_at'] = date('Y-m-d H:i:s', time() + ($response['expires_in'] ?? 3600));
        
        if (isset($response['refresh_token'])) {
            $this->data['google_auth']['refresh_token'] = $response['refresh_token'];
        }

        $this->save();
        return true;
    }

    /**
     * @inheritdoc
     */
    public function getAuthUrl(string $redirectUri, array $config = []): string
    {
        $clientId = $this->data['client_id'] ?? $_ENV['GOOGLE_CLIENT_ID'] ?? '';
        
        $scopes = [
            'openid',
            'email',
            'profile',
            'https://www.googleapis.com/auth/webmasters.readonly', // Search Console
            'https://www.googleapis.com/auth/analytics.readonly',   // Google Analytics
        ];

        $state = $config['state'] ?? bin2hex(random_bytes(16));

        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', array_unique($scopes)),
            'response_type' => 'code',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state
        ];

        return "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query($params);
    }

    /**
     * @inheritdoc
     */
    public function handleCallback(string $code, string $redirectUri): array
    {
        $clientId = $this->data['client_id'] ?? $_ENV['GOOGLE_CLIENT_ID'] ?? '';
        $clientSecret = $this->data['client_secret'] ?? $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';

        $tokenUrl = "https://oauth2.googleapis.com/token";
        
        $postData = [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ];

        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($postData),
            ],
        ];

        $context  = stream_context_create($options);
        $result = @file_get_contents($tokenUrl, false, $context);
        
        if ($result === false) {
            throw new \Exception("Failed to retrieve access token from Google.");
        }

        $response = json_decode($result, true);
        
        if (isset($response['error'])) {
            throw new \Exception("Google API Error: " . ($response['error_description'] ?? $response['error']));
        }

        // Get User Identity
        $userJson = @file_get_contents("https://www.googleapis.com/oauth2/v3/userinfo?access_token=" . $response['access_token']);
        $userData = $userJson ? json_decode($userJson, true) : [];
        $userId = $userData['sub'] ?? null;

        return [
            'google_auth' => [
                'access_token' => $response['access_token'],
                'refresh_token' => $response['refresh_token'] ?? null,
                'user_id' => $userId,
                'scopes' => explode(' ', $response['scope'] ?? ''),
                'updated_at' => date('Y-m-d H:i:s'),
                'expires_at' => date('Y-m-d H:i:s', time() + ($response['expires_in'] ?? 3600))
            ]
        ];
    }
}
