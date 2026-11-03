<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ChangeRiskLevel;
use App\Enum\ChangeStatus;
use App\Repository\ChangeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ChangeRepository::class)]
#[ORM\Table(name: 'changes')]
#[ORM\Index(name: 'IDX_CHANGE_TENANT', fields: ['tenant'])]
#[ORM\Index(name: 'IDX_CHANGE_STATUS', fields: ['status'])]
class Change
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    private ?string $title = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'risk_level', type: 'string', enumType: ChangeRiskLevel::class)]
    private ChangeRiskLevel $riskLevel = ChangeRiskLevel::MEDIO;

    #[ORM\Column(type: 'string', enumType: ChangeStatus::class)]
    private ChangeStatus $status = ChangeStatus::SOLICITADO;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'requester_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?User $requester = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'approver_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $approver = null;

    #[ORM\Column(name: 'scheduled_start', nullable: true)]
    private ?\DateTimeImmutable $scheduledStart = null;

    #[ORM\Column(name: 'scheduled_end', nullable: true)]
    private ?\DateTimeImmutable $scheduledEnd = null;

    /**
     * @var Collection<int, CiItem>
     */
    #[ORM\ManyToMany(targetEntity: CiItem::class, inversedBy: 'changes')]
    #[ORM\JoinTable(
        name: 'change_ci',
        joinColumns: [new ORM\JoinColumn(name: 'change_id', referencedColumnName: 'id', onDelete: 'CASCADE')],
        inverseJoinColumns: [new ORM\JoinColumn(name: 'ci_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    )]
    private Collection $ciItems;

    public function __construct()
    {
        $this->ciItems = new ArrayCollection();
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

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = trim($title);

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

    public function getRiskLevel(): ChangeRiskLevel
    {
        return $this->riskLevel;
    }

    public function setRiskLevel(ChangeRiskLevel $riskLevel): static
    {
        $this->riskLevel = $riskLevel;

        return $this;
    }

    public function getStatus(): ChangeStatus
    {
        return $this->status;
    }

    public function setStatus(ChangeStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getRequester(): ?User
    {
        return $this->requester;
    }

    public function setRequester(?User $requester): static
    {
        $this->requester = $requester;

        return $this;
    }

    public function getApprover(): ?User
    {
        return $this->approver;
    }

    public function setApprover(?User $approver): static
    {
        $this->approver = $approver;

        return $this;
    }

    public function getScheduledStart(): ?\DateTimeImmutable
    {
        return $this->scheduledStart;
    }

    public function setScheduledStart(?\DateTimeImmutable $scheduledStart): static
    {
        $this->scheduledStart = $scheduledStart;

        return $this;
    }

    public function getScheduledEnd(): ?\DateTimeImmutable
    {
        return $this->scheduledEnd;
    }

    public function setScheduledEnd(?\DateTimeImmutable $scheduledEnd): static
    {
        $this->scheduledEnd = $scheduledEnd;

        return $this;
    }

    /**
     * @return Collection<int, CiItem>
     */
    public function getCiItems(): Collection
    {
        return $this->ciItems;
    }

    public function addCiItem(CiItem $ciItem): static
    {
        if (!$this->ciItems->contains($ciItem)) {
            $this->ciItems->add($ciItem);
        }

        return $this;
    }

    public function removeCiItem(CiItem $ciItem): static
    {
        $this->ciItems->removeElement($ciItem);

        return $this;
    }
}
