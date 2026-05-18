<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;

#[AsEventListener]
class LogoutListener
{
    public function __construct(
        private readonly string $idpBaseUrl,
        private readonly string $appUrl,
    ) {}

    public function __invoke(LogoutEvent $event): void
    {
        $postLogoutUri = rtrim($this->appUrl, '/') . '/login';
        $event->setResponse(new RedirectResponse(
            rtrim($this->idpBaseUrl, '/') . '/logout?post_logout_redirect_uri=' . urlencode($postLogoutUri)
        ));
    }
}
