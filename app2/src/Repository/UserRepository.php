<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Commish\IdpBundle\Contract\IdpUserInterface;
use Commish\IdpBundle\Contract\IdpUserProviderInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements IdpUserProviderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findByIdpUuid(Uuid $uuid): ?IdpUserInterface
    {
        return $this->findOneBy(['idpUuid' => $uuid]);
    }

    public function findByEmail(string $email): ?IdpUserInterface
    {
        return $this->findOneBy(['email' => $email]);
    }

    public function createForIdp(Uuid $idpUuid): IdpUserInterface
    {
        $user = new User();
        $user->setIdpUuid($idpUuid);
        $this->getEntityManager()->persist($user);
        return $user;
    }
}
