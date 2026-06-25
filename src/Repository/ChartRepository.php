<?php

namespace App\Repository;

use App\Entity\Chart;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Chart>
 *
 * @method Chart|null find($id, $lockMode = null, $lockVersion = null)
 * @method Chart|null findOneBy(array $criteria, array $orderBy = null)
 * @method Chart[]    findAll()
 * @method Chart[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ChartRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Chart::class);
    }

    public function findBySlug(string $slug): ?Chart
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}

