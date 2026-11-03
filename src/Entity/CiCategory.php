<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CiCategoryType;
use App\Repository\CiCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CiCategoryRepository::class)]
#[ORM\Table(name: 'ci_categories')]
class CiCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(type: 'string', enumType: CiCategoryType::class)]
    private CiCategoryType $type = CiCategoryType::FISICO;

    /**
     * @var Collection<int, CiItem>
     */
    #[ORM\OneToMany(targetEntity: CiItem::class, mappedBy: 'category')]
    private Collection $ciItems;

    public function __construct()
    {
        $this->ciItems = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): static
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getType(): CiCategoryType
    {
        return $this->type;
    }

    public function setType(CiCategoryType $type): static
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @return Collection<int, CiItem>
     */
    public function getCiItems(): Collection
    {
        return $this->ciItems;
    }
}
