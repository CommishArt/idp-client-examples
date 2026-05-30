<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Commish\IdpBundle\Contract\IdpUserInterface;
use Commish\IdpBundle\Contract\NeedsOnboardingInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\HasLifecycleCallbacks]
class User implements IdpUserInterface, NeedsOnboardingInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    /** UUID from the IdP — the authoritative identifier across all apps */
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $idpUuid;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column]
    private bool $onboarded = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $loggedOutAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function preUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid { return $this->id; }

    public function getIdpUuid(): Uuid { return $this->idpUuid; }
    public function setIdpUuid(Uuid $uuid): static { $this->idpUuid = $uuid; return $this; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }

    public function getUserIdentifier(): string { return (string) $this->idpUuid; }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): static { $this->roles = $roles; return $this; }

    public function eraseCredentials(): void {}

    public function isOnboarded(): bool { return $this->onboarded; }
    public function setOnboarded(bool $onboarded): static { $this->onboarded = $onboarded; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getLoggedOutAt(): ?\DateTimeImmutable { return $this->loggedOutAt; }
    public function setLoggedOutAt(?\DateTimeImmutable $loggedOutAt): static { $this->loggedOutAt = $loggedOutAt; return $this; }
}
