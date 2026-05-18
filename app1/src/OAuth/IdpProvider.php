<?php

declare(strict_types=1);

namespace App\OAuth;

use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\GenericResourceOwner;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Http\Message\ResponseInterface;

class IdpProvider extends AbstractProvider
{
    private string $idpDomain;

    public function __construct(array $options = [], array $collaborators = [])
    {
        $this->idpDomain = rtrim($options['domain'] ?? $_ENV['IDP_BASE_URL'] ?? 'http://localhost:8010', '/');
        parent::__construct($options, $collaborators);
    }

    public function getBaseAuthorizationUrl(): string
    {
        return $this->idpDomain . '/oauth/authorize';
    }

    public function getBaseAccessTokenUrl(array $params): string
    {
        return $this->idpDomain . '/oauth/token';
    }

    public function getResourceOwnerDetailsUrl(AccessToken $token): string
    {
        return $this->idpDomain . '/oauth/userinfo';
    }

    protected function getDefaultScopes(): array
    {
        return ['openid', 'email', 'profile', 'roles'];
    }

    protected function getScopeSeparator(): string
    {
        return ' ';
    }

    protected function checkResponse(ResponseInterface $response, $data): void
    {
        if (isset($data['error'])) {
            throw new \RuntimeException('IdP error: ' . ($data['error_description'] ?? $data['error']));
        }
    }

    protected function createResourceOwner(array $response, AccessToken $token): GenericResourceOwner
    {
        return new GenericResourceOwner($response, 'sub');
    }
}
