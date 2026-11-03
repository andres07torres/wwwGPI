<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CiItemStatus;
use App\Repository\CiItemRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CiItemRepository::class)]
#[ORM\Table(name: 'ci_items')]
#[ORM\Index(name: 'IDX_CI_TENANT', fields: ['tenant'])]
#[ORM\Index(name: 'IDX_CI_STATUS', fields: ['status'])]
class CiItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: CiCategory::class, inversedBy: 'ciItems')]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?CiCategory $category = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', enumType: CiItemStatus::class)]
    private CiItemStatus $status = CiItemStatus::ACTIVO;

    #[ORM\Column(name: 'mac_address', length: 50, nullable: true)]
    private ?string $macAddress = null;

    #[ORM\Column(name: 'ip_address', length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(name: 'serial_number', length: 100, nullable: true)]
    private ?string $serialNumber = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $location = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    /**
     * @var Collection<int, CiRelationship>
     */
    #[ORM\OneToMany(targetEntity: CiRelationship::class, mappedBy: 'parentCi')]
    private Collection $childRelationships;

    /**
     * @var Collection<int, CiRelationship>
     */
    #[ORM\OneToMany(targetEntity: CiRelationship::class, mappedBy: 'childCi')]
    private Collection $parentRelationships;

    /**
     * @var Collection<int, Ticket>
     */
    #[ORM\ManyToMany(targetEntity: Ticket::class, mappedBy: 'ciItems')]
    private Collection $tickets;

    /**
     * @var Collection<int, Change>
     */
    #[ORM\ManyToMany(targetEntity: Change::class, mappedBy: 'ciItems')]
    private Collection $changes;

    public function __construct()
    {
        $this->childRelationships = new ArrayCollection();
        $this->parentRelationships = new ArrayCollection();
        $this->tickets = new ArrayCollection();
        $this->changes = new ArrayCollection();
    }

    public function getId(): ?Uuid
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

    public function getCategory(): ?CiCategory
    {
        return $this->category;
    }

    public function setCategory(?CiCategory $category): static
    {
        $this->category = $category;

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getStatus(): CiItemStatus
    {
        return $this->status;
    }

    public function setStatus(CiItemStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getMacAddress(): ?string
    {
        return $this->macAddress;
    }

    public function setMacAddress(?string $macAddress): static
    {
        $this->macAddress = $macAddress;

        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;

        return $this;
    }

    public function getSerialNumber(): ?string
    {
        return $this->serialNumber;
    }

    public function setSerialNumber(?string $serialNumber): static
    {
        $this->serialNumber = $serialNumber;

        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * @return Collection<int, CiRelationship>
     */
    public function getChildRelationships(): Collection
    {
        return $this->childRelationships;
    }

    /**
     * @return Collection<int, CiRelationship>
     */
    public function getParentRelationships(): Collection
    {
        return $this->parentRelationships;
    }

    /**
     * @return Collection<int, Ticket>
     */
    public function getTickets(): Collection
    {
        return $this->tickets;
    }

    /**
     * @return Collection<int, Change>
     */
    public function getChanges(): Collection
    {
        return $this->changes;
    }
}
