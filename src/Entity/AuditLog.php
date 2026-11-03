<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_logs')]
#[ORM\Index(name: 'IDX_AUDIT_ENTITY', fields: ['entityType', 'entityId'])]
#[ORM\Index(name: 'IDX_AUDIT_TENANT', fields: ['tenant'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(length: 40)]
    #[Assert\NotBlank]
    private ?string $action = null;

    #[ORM\Column(name: 'entity_type', length: 60)]
    #[Assert\NotBlank]
    private ?string $entityType = null;

    #[ORM\Column(name: 'entity_id', type: UuidType::NAME, nullable: true)]
    private ?Uuid $entityId = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'old_values', type: 'json', nullable: true)]
    private ?array $oldValues = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'new_values', type: 'json', nullable: true)]
    private ?array $newValues = null;

    #[ORM\Column(name: 'ip_address', length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = strtoupper(trim($action));

        return $this;
    }

    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    public function setEntityType(string $entityType): static
    {
        $this->entityType = trim($entityType);

        return $this;
    }

    public function getEntityId(): ?Uuid
    {
        return $this->entityId;
    }

    public function setEntityId(?Uuid $entityId): static
    {
        $this->entityId = $entityId;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOldValues(): ?array
    {
        return $this->oldValues;
    }

    /**
     * @param array<string, mixed>|null $oldValues
     */
    public function setOldValues(?array $oldValues): static
    {
        $this->oldValues = $oldValues;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getNewValues(): ?array
    {
        return $this->newValues;
    }

    /**
     * @param array<string, mixed>|null $newValues
     */
    public function setNewValues(?array $newValues): static
    {
        $this->newValues = $newValues;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
