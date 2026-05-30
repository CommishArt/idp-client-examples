<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AsEventListener(event: LoginSuccessEvent::class)]
#[AsEventListener(event: KernelEvents::REQUEST, priority: -8)]
class SessionSecurityListener
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
    ) {}

    public function __invoke(LoginSuccessEvent|RequestEvent $event): void
    {
        match (true) {
            $event instanceof LoginSuccessEvent => $this->onLoginSuccess($event),
            $event instanceof RequestEvent      => $this->onRequest($event),
        };
    }

    private function onLoginSuccess(LoginSuccessEvent $event): void
    {
        // Record when this session was authenticated so the force-logout check
        // can compare against loggedOutAt from the back-channel webhook.
        $event->getRequest()->getSession()->set('idp_authenticated_at', time());
    }

    private function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return;
        }

        $loggedOutAt = $user->getLoggedOutAt();
        if ($loggedOutAt === null) {
            return;
        }

        $session         = $event->getRequest()->getSession();
        $authenticatedAt = $session->get('idp_authenticated_at');

        // If no recorded auth time, this session predates the listener — force reauth.
        if ($authenticatedAt === null || $loggedOutAt->getTimestamp() > (int) $authenticatedAt) {
            $this->invalidate($session);
            $this->tokenStorage->setToken(null);
        }
    }

    private function invalidate(SessionInterface $session): void
    {
        $session->remove('oauth_refresh_token');
        $session->remove('oauth_access_token_expiry');
        $session->remove('oauth_id_token');
        $session->remove('idp_authenticated_at');
        $session->invalidate();
    }
}
