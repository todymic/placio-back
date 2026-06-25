<?php

namespace App\Repository;

use App\Entity\ApiKey;
use App\Entity\ApiKeyScope;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiKey>
 *
 * @method ApiKey|null find($id, $lockMode = null, $lockVersion = null)
 * @method ApiKey|null findOneBy(array $criteria, array $orderBy = null)
 * @method ApiKey[]    findAll()
 * @method ApiKey[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ApiKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiKey::class);
    }

    public function findByKeyIdAndActiveTrue(string $keyId): ?ApiKey
    {
        return $this->findOneBy([
            'keyId' => $keyId,
            'active' => true,
        ]);
    }

    public function findByCreatedByKeycloakIdAndActiveTrue(string $keycloakId): array
    {
        return $this->findBy([
            'createdByKeycloakId' => $keycloakId,
            'active' => true,
        ]);
    }
}

