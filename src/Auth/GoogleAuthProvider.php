<?php

declare(strict_types=1);

namespace Anibalealvarezs\GoogleHubDriver\Auth;

use Anibalealvarezs\ApiDriverCore\Auth\BaseAuthProvider;

class GoogleAuthProvider extends BaseAuthProvider
{
    public function getAccessToken(): string
    {
        return $this->data['google_auth']['access_token'] ?? "";
    }

    public function getScopes(): array
    {
        return $this->data['google_auth']['scopes'] ?? [];
    }

    public function setAccessToken(string $token): void
    {
        if (!isset($this->data['google_auth'])) {
            $this->data['google_auth'] = [];
        }
        $this->data['google_auth']['access_token'] = $token;
        $this->save();
    }
}
