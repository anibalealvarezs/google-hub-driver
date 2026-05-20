<?php

namespace Tests\Unit\Controllers;

use Anibalealvarezs\GoogleHubDriver\Controllers\GoogleAuthController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

class GoogleAuthControllerTest extends TestCase
{
    private string $originalClientId;
    private string $originalClientSecret;

    protected function setUp(): void
    {
        $this->originalClientId = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
        $this->originalClientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';
        $_SERVER['HTTP_HOST'] = 'localhost';
    }

    protected function tearDown(): void
    {
        $_ENV['GOOGLE_CLIENT_ID'] = $this->originalClientId;
        $_ENV['GOOGLE_CLIENT_SECRET'] = $this->originalClientSecret;
        unset($_SERVER['HTTP_HOST']);
    }

    public function testLoginViewFallback()
    {
        $controller = new GoogleAuthController();
        $response = $controller->login();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Google', $response->getContent());
    }

    public function testStartWithoutConfigRedirects()
    {
        $_ENV['GOOGLE_CLIENT_ID'] = '';
        $controller = new GoogleAuthController();
        $response = $controller->start();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('/google-login?error=invalid_config', $response->getTargetUrl());
    }

    public function testStartWithConfigRedirectsToGoogle()
    {
        $_ENV['GOOGLE_CLIENT_ID'] = 'google_client_test_id';
        $controller = new GoogleAuthController();
        $response = $controller->start();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $target = $response->getTargetUrl();
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth', $target);
        $this->assertStringContainsString('client_id=google_client_test_id', $target);
        $this->assertStringContainsString('response_type=code', $target);
    }

    public function testCallbackWithoutCodeReturns400()
    {
        $controller = new GoogleAuthController();
        $request = new Request();
        $response = $controller->callback($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(400, $response->getStatusCode());
        $this->assertStringContainsString('Authorization code missing', $response->getContent());
    }
}
