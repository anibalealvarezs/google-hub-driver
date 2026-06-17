<?php

declare(strict_types=1);

namespace Anibalealvarezs\GoogleHubDriver\Controllers;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Anibalealvarezs\GoogleHubDriver\Drivers\GoogleAnalyticsDriver;

class GoogleAuthController
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct()
    {
        $config = [];
        if (class_exists('\Helpers\Helpers')) {
            $allConfigs = \Helpers\Helpers::getChannelsConfig();
            $config = $allConfigs['google'] ?? $allConfigs['google_search_console'] ?? $allConfigs['google_analytics'] ?? [];
        }

        $this->clientId = $config['client_id'] ?? $_ENV['GOOGLE_CLIENT_ID'] ?? getenv('GOOGLE_CLIENT_ID') ?: '';
        $this->clientSecret = $config['client_secret'] ?? $_ENV['GOOGLE_CLIENT_SECRET'] ?? getenv('GOOGLE_CLIENT_SECRET') ?: '';
        
        $isHttps = (
            (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] === 1)) ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        );
        $protocol = $isHttps ? 'https' : 'http';
        
        if (isset($_SERVER['HTTP_HOST']) && !str_contains($_SERVER['HTTP_HOST'], 'localhost') && !str_contains($_SERVER['HTTP_HOST'], '127.0.0.1')) {
            $protocol = 'https';
        }

        $this->redirectUri = $config['redirect_uri'] ?? $_ENV['GOOGLE_REDIRECT_URI'] ?? getenv('GOOGLE_REDIRECT_URI') ?: "$protocol://$_SERVER[HTTP_HOST]/google-callback";
    }

    /**
     * Shows the login entry page
     */
    public function login(): Response
    {
        $viewPath = dirname(__DIR__, 2) . '/src/Views/google-login.html';
        $content = file_exists($viewPath) ? file_get_contents($viewPath) : '<h1>Login with Google</h1><p>View not found.</p><a href="/google-auth-start">Continue</a>';
        return new Response($content, 200, ['Content-Type' => 'text/html']);
    }

    /**
     * Starts the OAuth flow
     */
    public function start(): RedirectResponse
    {
        if (empty($this->clientId)) {
            return new RedirectResponse('/google-login?error=invalid_config');
        }

        $scopes = [
            'openid',
            'email',
            'profile',
        ];

        $allConfigs = class_exists('\Helpers\Helpers') ? \Helpers\Helpers::getChannelsConfig() : [];
        $gscConfig = $allConfigs['google_search_console'] ?? [];
        $gbpConfig = $allConfigs['google_business_profile'] ?? [];
        
        if (!empty($gscConfig['enabled'])) {
            $scopes[] = 'https://www.googleapis.com/auth/webmasters.readonly';
        }

        if (!empty($gbpConfig['enabled'])) {
            $scopes[] = 'https://www.googleapis.com/auth/business.manage';
        }

        $url = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => implode(' ', array_unique($scopes)),
            'response_type' => 'code',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => bin2hex(random_bytes(16))
        ]);

        return new RedirectResponse($url);
    }

    /**
     * Handles the callback from Google
     */
    public function callback(Request $request): Response
    {
        $code = $request->query->get('code');
        if (!$code) {
            return new Response("Authorization code missing.", 400);
        }

        $tokenUrl = "https://oauth2.googleapis.com/token";
        
        $postData = [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
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
            return new Response("Failed to retrieve access token from Google.", 500);
        }

        $response = json_decode($result, true);
        
        if (isset($response['error'])) {
            return new Response("Google API Error: " . ($response['error_description'] ?? $response['error']), 500);
        }

        // Get User Identity
        $userJson = @file_get_contents("https://www.googleapis.com/oauth2/v3/userinfo?access_token=" . $response['access_token']);
        $userData = $userJson ? json_decode($userJson, true) : [];
        $userId = $userData['sub'] ?? null;

        // Persist
        GoogleAnalyticsDriver::storeCredentials([
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'] ?? null,
            'user_id' => $userId,
            'scopes' => explode(' ', $response['scope'] ?? ''),
        ]);

        $hasRefreshToken = !empty($response['refresh_token']);
        $refreshTokenDisplay = $hasRefreshToken ? substr($response['refresh_token'], 0, 10) . '...' : '<b>NULL (Google no lo envió)</b>';

        return new Response("
            <div style='background: #0a0c10; color: #fff; height: 100vh; display: flex; align-items: center; justify-content: center; font-family: sans-serif; text-align: center; padding: 20px;'>
                <div style='background: #161b22; border: 1px solid #30363d; padding: 40px; border-radius: 20px; max-width: 400px;'>
                    <h1 style='color: #238636;'>✓ Success!</h1>
                    <p style='color: #8b949e;'>Your Google credentials have been successfully updated and stored in APIs Hub.</p>
                    <p style='color: #ff7b72; font-size: 13px; margin-top: 15px; text-align: left; background: #0a0c10; padding: 10px; border-radius: 5px; border: 1px solid #30363d;'>
                        <b>Debug Info:</b><br>
                        Access Token: received ✔<br>
                        Refresh Token: {$refreshTokenDisplay}
                    </p>
                    <a href='/' style='display: inline-block; margin-top: 20px; background: #58a6ff; color: #fff; padding: 12px 24px; border-radius: 8px; text-decoration: none;'>Back to Hub</a>
                </div>
            </div>
        ", 200, ['Content-Type' => 'text/html']);
    }
}
