<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class MainController extends AbstractController
{
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly string $idpBaseUrl,
        private readonly string $appUrl,
    ) {}

    #[Route('/', name: 'app_index')]
    public function index(): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }
        return $this->redirectToRoute('app_login');
    }

    #[Route('/login', name: 'app_login')]
    public function login(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $request->getSession()->set('pkce_verifier', $verifier);

        return $this->clientRegistry->getClient('idp')->redirect(
            ['openid', 'email', 'profile', 'roles'],
            ['code_challenge' => $challenge, 'code_challenge_method' => 'S256'],
        );
    }

    #[Route('/oauth/callback', name: 'oauth_callback')]
    public function oauthCallback(): never
    {
        throw new \LogicException('Handled by OidcAuthenticator.');
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    #[IsGranted('ROLE_USER')]
    public function dashboard(#[CurrentUser] User $user): Response
    {
        return $this->render('dashboard.html.twig', [
            'user'        => $user,
            'idp_base_url' => $this->idpBaseUrl,
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Handled by Symfony security.');
    }

    #[Route('/access-denied', name: 'app_access_denied')]
    public function accessDenied(): Response
    {
        return $this->render('access_denied.html.twig', [
            'idp_base_url' => $this->idpBaseUrl,
        ], new Response('', Response::HTTP_FORBIDDEN));
    }
}
