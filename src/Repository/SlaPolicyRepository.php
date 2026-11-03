<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SlaPolicy;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SlaPolicy>
 */
class SlaPolicyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SlaPolicy::class);
    }
}
