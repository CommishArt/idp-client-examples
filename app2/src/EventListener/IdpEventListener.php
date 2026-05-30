<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Commish\IdpBundle\Event\IdpAccountDeletedEvent;
use Commish\IdpBundle\Event\IdpRolesUpdatedEvent;
use Commish\IdpBundle\Event\IdpSessionRevokedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: IdpSessionRevokedEvent::class)]
#[AsEventListener(event: IdpRolesUpdatedEvent::class)]
#[AsEventListener(event: IdpAccountDeletedEvent::class)]
class IdpEventListener
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(IdpSessionRevokedEvent|IdpRolesUpdatedEvent|IdpAccountDeletedEvent $event): void
    {
        match (true) {
            $event instanceof IdpSessionRevokedEvent  => $this->onSessionRevoked($event),
            $event instanceof IdpRolesUpdatedEvent    => $this->onRolesUpdated($event),
            $event instanceof IdpAccountDeletedEvent  => $this->onAccountDeleted($event),
        };
    }

    private function onSessionRevoked(IdpSessionRevokedEvent $event): void
    {
        $user = $event->user;
        if (!$user instanceof User) {
            return;
        }

        // Mark the logout time so any active browser sessions for this user
        // are force-invalidated on their next request.
        $user->setLoggedOutAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    private function onRolesUpdated(IdpRolesUpdatedEvent $event): void
    {
        $user = $event->user;
        if (!$user instanceof User) {
            return;
        }

        $user->setRoles($event->roles);
        $this->em->flush();
    }

    private function onAccountDeleted(IdpAccountDeletedEvent $event): void
    {
        $user = $event->user;
        if (!$user instanceof User) {
            return;
        }

        $this->em->remove($user);
        $this->em->flush();
    }
}
