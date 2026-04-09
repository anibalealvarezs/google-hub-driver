<?php

namespace Anibalealvarezs\GoogleHubDriver\Auth;

use Anibalealvarezs\ApiSkeleton\Interfaces\AuthProviderInterface;

class GoogleAuthProvider implements AuthProviderInterface
{
    private array $credentials = [];
    private ?string $tokenPath;
    private array $config = [];

    public function __construct(?string $tokenPath = null, array $config = [])
    {
        $this->config = $config;
        $projectDir = dirname(__DIR__, 2);
        
        // Priority: Passed arg -> Config -> ENV -> Default
        $this->tokenPath = $tokenPath 
            ?? $config['google_search_console']['token_path'] 
            ?? $config['google']['token_path'] 
            ?? $_ENV['GOOGLE_TOKEN_PATH'] 
            ?? $projectDir . '/storage/tokens/google_tokens.json';

        $this->loadCredentials();

        // Fallback to config if tokens are not loaded or missing fields
        if (empty($this->credentials['client_id'])) {
            $this->credentials['client_id'] = $config['google_search_console']['client_id'] 
                ?? $config['google']['client_id'] 
                ?? $_ENV['GOOGLE_CLIENT_ID'] 
                ?? '';
        }

        if (empty($this->credentials['client_secret'])) {
            $this->credentials['client_secret'] = $config['google_search_console']['client_secret'] 
                ?? $config['google']['client_secret'] 
                ?? $_ENV['GOOGLE_CLIENT_SECRET'] 
                ?? '';
        }

        if (empty($this->credentials['refresh_token'])) {
            $this->credentials['refresh_token'] = $config['google_search_console']['refresh_token'] 
                ?? $config['google']['refresh_token'] 
                ?? $_ENV['GOOGLE_REFRESH_TOKEN'] 
                ?? '';
        }
    }

    private function loadCredentials(): void
    {
        if ($this->tokenPath && file_exists($this->tokenPath)) {
            $tokens = json_decode(file_get_contents($this->tokenPath), true) ?? [];
            $this->credentials = $tokens['google_auth'] ?? [];
        }
    }

    public function getAccessToken(): string
    {
        if (!$this->isValid() || $this->isExpired()) {
            $this->refresh();
        }

        return $this->credentials['access_token'] ?? '';
    }

    public function isValid(): bool
    {
        return !empty($this->credentials['access_token']) || !empty($this->credentials['refresh_token']);
    }

    public function isExpired(): bool
    {
        if (empty($this->credentials['expires_at'])) {
            return true;
        }

        return strtotime($this->credentials['expires_at']) <= (time() + 60); // 1 min buffer
    }

    public function refresh(): bool
    {
        $refreshToken = $this->credentials['refresh_token'] ?? null;
        if (!$refreshToken) {
            return false;
        }

        $url = "https://oauth2.googleapis.com/token";
        $params = [
            'client_id' => $this->credentials['client_id'] ?? '',
            'client_secret' => $this->credentials['client_secret'] ?? '',
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        if (isset($data['access_token'])) {
            $this->credentials['access_token'] = $data['access_token'];
            $this->credentials['expires_at'] = date('Y-m-d H:i:s', time() + ($data['expires_in'] ?? 3600));
            $this->saveCredentials();
            return true;
        }

        return false;
    }

    public function getScopes(): array
    {
        $scopes = $this->credentials['scopes'] 
            ?? $this->config['google_search_console']['scopes']
            ?? $this->config['google']['scopes']
            ?? [];

        if (is_string($scopes)) {
            return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $scopes))));
        }

        return $scopes;
    }

    public function setAccessToken(string $token): void
    {
        $this->credentials['access_token'] = $token;
        $this->saveCredentials();
    }

    private function saveCredentials(): void
    {
        if (!$this->tokenPath) return;

        $tokens = file_exists($this->tokenPath) ? (json_decode(file_get_contents($this->tokenPath), true) ?? []) : [];
        $tokens['google_auth'] = array_merge($tokens['google_auth'] ?? [], $this->credentials);
        $tokens['google_auth']['updated_at'] = date('Y-m-d H:i:s');
        
        file_put_contents($this->tokenPath, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
