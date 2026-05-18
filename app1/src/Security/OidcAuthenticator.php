<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Uid\Uuid;

class OidcAuthenticator extends OAuth2Authenticator implements AuthenticationEntryPointInterface
{
    private const APP_KEY = 'app1';

    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly UserRepository $userRepo,
        private readonly EntityManagerInterface $em,
        private readonly RouterInterface $router,
        private readonly string $idpBaseUrl,
        private readonly string $idpClientId,
    ) {}

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->router->generate('app_login'));
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'oauth_callback';
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clientRegistry->getClient('idp');
        $verifier = $request->getSession()->get('pkce_verifier');
        $request->getSession()->remove('pkce_verifier');

        try {
            $accessToken = $this->fetchAccessToken($client, ['code_verifier' => $verifier]);
        } catch (\RuntimeException $e) {
            throw new CustomUserMessageAuthenticationException('Access denied by the authentication server.');
        }

        $tokenString = $accessToken->getToken();

        return new SelfValidatingPassport(
            new UserBadge($tokenString, function (string $token) {
                $userInfo = $this->fetchUserInfo($token);

                if (!in_array(self::APP_KEY, $userInfo['allowed_apps'] ?? [], true)) {
                    throw new CustomUserMessageAuthenticationException(
                        'You do not have access to the Admin Panel.'
                    );
                }

                $idpUuid = Uuid::fromString($userInfo['sub']);
                $user    = $this->userRepo->findByIdpUuid($idpUuid);

                if ($user === null) {
                    $user = new User();
                    $user->setIdpUuid($idpUuid);
                    $this->em->persist($user);
                }

                $user->setEmail($userInfo['email']);
                $user->setRoles($userInfo['roles'][self::APP_KEY] ?? ['ROLE_USER']);

                $this->em->flush();

                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $targetPath = $request->getSession()->get('_security.' . $firewallName . '.target_path');
        return new RedirectResponse($targetPath ?? $this->router->generate('app_dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        if ($exception instanceof CustomUserMessageAuthenticationException) {
            return new RedirectResponse($this->router->generate('app_access_denied'));
        }

        $request->getSession()->set('auth_error', strtr($exception->getMessageKey(), $exception->getMessageData()));
        return new RedirectResponse($this->router->generate('app_login'));
    }

    private function fetchUserInfo(string $token): array
    {
        $context = stream_context_create([
            'http' => [
                'header'  => "Authorization: Bearer $token\r\nAccept: application/json\r\n",
                'timeout' => 5,
            ],
        ]);

        $expectedIss = rtrim($this->idpBaseUrl, '/');
        $url         = $expectedIss . '/oauth/userinfo';
        $response    = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new CustomUserMessageAuthenticationException('Could not reach the authentication server.');
        }

        $data = json_decode($response, true) ?? [];

        if (($data['iss'] ?? null) !== $expectedIss) {
            throw new CustomUserMessageAuthenticationException('Token issuer mismatch.');
        }

        $aud = $data['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if (!in_array($this->idpClientId, $audiences, true)) {
            throw new CustomUserMessageAuthenticationException('Token audience mismatch.');
        }

        return $data;
    }
}
