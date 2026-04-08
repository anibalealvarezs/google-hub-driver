<?php

namespace Anibalealvarezs\GoogleHubDriver\Auth;

use Anibalealvarezs\ApiSkeleton\Interfaces\AuthProviderInterface;

class GoogleAuthProvider implements AuthProviderInterface
{
    private array $credentials = [];
    private string $tokenPath;

    public function __construct(?string $tokenPath = null)
    {
        $projectDir = dirname(__DIR__, 2);
        $this->tokenPath = $tokenPath ?? $_ENV['GOOGLE_TOKEN_PATH'] ?? $projectDir . '/storage/tokens/google_tokens.json';
        $this->loadCredentials();
    }

    private function loadCredentials(): void
    {
        if (file_exists($this->tokenPath)) {
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

        $clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
        $clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';

        $url = "https://oauth2.googleapis.com/token";
        $params = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
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
        return $this->credentials['scopes'] ?? [];
    }

    public function setAccessToken(string $token): void
    {
        $this->credentials['access_token'] = $token;
        $this->saveCredentials();
    }

    private function saveCredentials(): void
    {
        $tokens = file_exists($this->tokenPath) ? (json_decode(file_get_contents($this->tokenPath), true) ?? []) : [];
        $tokens['google_auth'] = array_merge($tokens['google_auth'] ?? [], $this->credentials);
        $tokens['google_auth']['updated_at'] = date('Y-m-d H:i:s');
        
        file_put_contents($this->tokenPath, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
