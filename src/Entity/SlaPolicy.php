<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SlaPolicyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SlaPolicyRepository::class)]
#[ORM\Table(name: 'sla_policies')]
#[ORM\Index(name: 'IDX_SLA_POLICY_TENANT', fields: ['tenant'])]
class SlaPolicy
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: TicketPriority::class)]
    #[ORM\JoinColumn(name: 'priority_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?TicketPriority $priority = null;

    #[ORM\Column(name: 'response_time_minutes', type: 'integer')]
    #[Assert\Positive]
    private int $responseTimeMinutes = 60;

    #[ORM\Column(name: 'resolution_time_minutes', type: 'integer')]
    #[Assert\Positive]
    private int $resolutionTimeMinutes = 240;

    #[ORM\Column(name: 'business_hours_only', type: 'boolean', options: ['default' => true])]
    private bool $businessHoursOnly = true;

    /**
     * @var Collection<int, TicketSla>
     */
    #[ORM\OneToMany(targetEntity: TicketSla::class, mappedBy: 'slaPolicy')]
    private Collection $ticketSlas;

    public function __construct()
    {
        $this->ticketSlas = new ArrayCollection();
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getPriority(): ?TicketPriority
    {
        return $this->priority;
    }

    public function setPriority(?TicketPriority $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getResponseTimeMinutes(): int
    {
        return $this->responseTimeMinutes;
    }

    public function setResponseTimeMinutes(int $responseTimeMinutes): static
    {
        $this->responseTimeMinutes = $responseTimeMinutes;

        return $this;
    }

    public function getResolutionTimeMinutes(): int
    {
        return $this->resolutionTimeMinutes;
    }

    public function setResolutionTimeMinutes(int $resolutionTimeMinutes): static
    {
        $this->resolutionTimeMinutes = $resolutionTimeMinutes;

        return $this;
    }

    public function isBusinessHoursOnly(): bool
    {
        return $this->businessHoursOnly;
    }

    public function setBusinessHoursOnly(bool $businessHoursOnly): static
    {
        $this->businessHoursOnly = $businessHoursOnly;

        return $this;
    }

    /**
     * @return Collection<int, TicketSla>
     */
    public function getTicketSlas(): Collection
    {
        return $this->ticketSlas;
    }
}
