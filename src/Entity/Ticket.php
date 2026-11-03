<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TicketType;
use App\Repository\TicketRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TicketRepository::class)]
#[ORM\Table(name: 'tickets')]
#[ORM\Index(name: 'IDX_TICKET_TENANT', fields: ['tenant'])]
#[ORM\Index(name: 'IDX_TICKET_CODE', fields: ['ticketCode'])]
#[ORM\HasLifecycleCallbacks]
class Ticket
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(name: 'ticket_code', length: 30)]
    #[Assert\NotBlank]
    private ?string $ticketCode = null;

    #[ORM\Column(type: 'string', enumType: TicketType::class)]
    private TicketType $type = TicketType::INCIDENTE;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: TicketStatus::class, inversedBy: 'tickets')]
    #[ORM\JoinColumn(name: 'status_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?TicketStatus $status = null;

    #[ORM\ManyToOne(targetEntity: TicketPriority::class, inversedBy: 'tickets')]
    #[ORM\JoinColumn(name: 'priority_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?TicketPriority $priority = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'requester_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?User $requester = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assignee_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $assignee = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /**
     * @var Collection<int, TicketComment>
     */
    #[ORM\OneToMany(targetEntity: TicketComment::class, mappedBy: 'ticket', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $comments;

    /**
     * @var Collection<int, CiItem>
     */
    #[ORM\ManyToMany(targetEntity: CiItem::class, inversedBy: 'tickets')]
    #[ORM\JoinTable(
        name: 'ticket_ci',
        joinColumns: [new ORM\JoinColumn(name: 'ticket_id', referencedColumnName: 'id', onDelete: 'CASCADE')],
        inverseJoinColumns: [new ORM\JoinColumn(name: 'ci_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    )]
    private Collection $ciItems;

    /**
     * @var Collection<int, Problem>
     */
    #[ORM\ManyToMany(targetEntity: Problem::class, mappedBy: 'tickets')]
    private Collection $problems;

    /**
     * @var Collection<int, TicketSla>
     */
    #[ORM\OneToMany(targetEntity: TicketSla::class, mappedBy: 'ticket', cascade: ['persist', 'remove'])]
    private Collection $ticketSlas;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->comments = new ArrayCollection();
        $this->ciItems = new ArrayCollection();
        $this->problems = new ArrayCollection();
        $this->ticketSlas = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getTicketCode(): ?string
    {
        return $this->ticketCode;
    }

    public function setTicketCode(string $ticketCode): static
    {
        $this->ticketCode = trim($ticketCode);

        return $this;
    }

    public function getType(): TicketType
    {
        return $this->type;
    }

    public function setType(TicketType $type): static
    {
        $this->type = $type;

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

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getStatus(): ?TicketStatus
    {
        return $this->status;
    }

    public function setStatus(?TicketStatus $status): static
    {
        $this->status = $status;

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

    public function getRequester(): ?User
    {
        return $this->requester;
    }

    public function setRequester(?User $requester): static
    {
        $this->requester = $requester;

        return $this;
    }

    public function getAssignee(): ?User
    {
        return $this->assignee;
    }

    public function setAssignee(?User $assignee): static
    {
        $this->assignee = $assignee;

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

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): static
    {
        $this->resolvedAt = $resolvedAt;

        return $this;
    }

    /**
     * @return Collection<int, TicketComment>
     */
    public function getComments(): Collection
    {
        return $this->comments;
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

    /**
     * @return Collection<int, Problem>
     */
    public function getProblems(): Collection
    {
        return $this->problems;
    }

    /**
     * @return Collection<int, TicketSla>
     */
    public function getTicketSlas(): Collection
    {
        return $this->ticketSlas;
    }
}
