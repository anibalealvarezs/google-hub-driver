<?php

namespace Tests\Unit\Auth;

use Anibalealvarezs\GoogleHubDriver\Auth\GoogleAuthProvider;
use PHPUnit\Framework\TestCase;

class GoogleAuthProviderTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'google_tokens_' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function testConstructorWithString()
    {
        $provider = new GoogleAuthProvider($this->tempFile);
        $this->assertFalse($provider->hasCredentials());
    }

    public function testConstructorWithArray()
    {
        $provider = new GoogleAuthProvider(['token_path' => $this->tempFile]);
        $this->assertFalse($provider->hasCredentials());
    }

    public function testGetAccessTokenAndUserId()
    {
        $data = [
            'google_auth' => [
                'access_token' => 'g_test_token',
                'user_id' => 'google_user_999',
                'scopes' => ['openid', 'email'],
                'expires_at' => date('Y-m-d H:i:s', strtotime('+5 days'))
            ]
        ];
        file_put_contents($this->tempFile, json_encode($data));

        $provider = new GoogleAuthProvider($this->tempFile);
        $this->assertTrue($provider->hasCredentials());
        $this->assertEquals('g_test_token', $provider->getAccessToken());
        $this->assertEquals('google_user_999', $provider->getUserId());
        $this->assertEquals(['openid', 'email'], $provider->getScopes());
        $this->assertTrue($provider->isValid());
    }

    public function testSetAccessToken()
    {
        $provider = new GoogleAuthProvider($this->tempFile);
        $provider->setAccessToken('new_google_token');

        $this->assertEquals('new_google_token', $provider->getAccessToken());

        $reloaded = new GoogleAuthProvider($this->tempFile);
        $this->assertEquals('new_google_token', $reloaded->getAccessToken());
    }

    public function testIsExpired()
    {
        $expiredData = [
            'google_auth' => [
                'access_token' => 'expired_google_token',
                'expires_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))
            ]
        ];
        file_put_contents($this->tempFile, json_encode($expiredData));

        $provider = new GoogleAuthProvider($this->tempFile);
        $this->assertTrue($provider->isExpired());

        $validData = [
            'google_auth' => [
                'access_token' => 'valid_google_token',
                'expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour'))
            ]
        ];
        file_put_contents($this->tempFile, json_encode($validData));

        $providerValid = new GoogleAuthProvider($this->tempFile);
        $this->assertFalse($providerValid->isExpired());
    }

    public function testGetAuthUrl()
    {
        $data = [
            'client_id' => 'google_client_id_123',
            'client_secret' => 'google_secret'
        ];
        file_put_contents($this->tempFile, json_encode($data));

        $provider = new GoogleAuthProvider($this->tempFile);
        $url = $provider->getAuthUrl('https://gsc.callback/auth', [
            'state' => 'googlesecretstate',
            'google_search_console' => ['enabled' => true],
            'google_analytics' => ['enabled' => true],
        ]);

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth', $url);
        $this->assertStringContainsString('client_id=google_client_id_123', $url);
        $this->assertStringContainsString('redirect_uri=https%3A%2F%2Fgsc.callback%2Fauth', $url);
        $this->assertStringContainsString('state=googlesecretstate', $url);
        $this->assertStringContainsString('scope=', $url);
        $this->assertStringContainsString('openid', $url);
        $this->assertStringContainsString('email', $url);
        $this->assertStringContainsString('webmasters.readonly', $url);
        $this->assertStringContainsString('analytics.readonly', $url);
    }
}
