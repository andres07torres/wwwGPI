<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TicketSlaRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TicketSlaRepository::class)]
#[ORM\Table(name: 'ticket_slas')]
#[ORM\Index(name: 'IDX_TICKET_SLA_BREACH', fields: ['responseBreached', 'resolutionBreached'])]
class TicketSla
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class, inversedBy: 'ticketSlas')]
    #[ORM\JoinColumn(name: 'ticket_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Ticket $ticket = null;

    #[ORM\ManyToOne(targetEntity: SlaPolicy::class, inversedBy: 'ticketSlas')]
    #[ORM\JoinColumn(name: 'sla_policy_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?SlaPolicy $slaPolicy = null;

    #[ORM\Column(name: 'response_due_at', nullable: true)]
    private ?\DateTimeImmutable $responseDueAt = null;

    #[ORM\Column(name: 'resolution_due_at', nullable: true)]
    private ?\DateTimeImmutable $resolutionDueAt = null;

    #[ORM\Column(name: 'first_response_at', nullable: true)]
    private ?\DateTimeImmutable $firstResponseAt = null;

    #[ORM\Column(name: 'resolved_at', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(name: 'response_breached', type: 'boolean', options: ['default' => false])]
    private bool $responseBreached = false;

    #[ORM\Column(name: 'resolution_breached', type: 'boolean', options: ['default' => false])]
    private bool $resolutionBreached = false;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getTicket(): ?Ticket
    {
        return $this->ticket;
    }

    public function setTicket(?Ticket $ticket): static
    {
        $this->ticket = $ticket;

        return $this;
    }

    public function getSlaPolicy(): ?SlaPolicy
    {
        return $this->slaPolicy;
    }

    public function setSlaPolicy(?SlaPolicy $slaPolicy): static
    {
        $this->slaPolicy = $slaPolicy;

        return $this;
    }

    public function getResponseDueAt(): ?\DateTimeImmutable
    {
        return $this->responseDueAt;
    }

    public function setResponseDueAt(?\DateTimeImmutable $responseDueAt): static
    {
        $this->responseDueAt = $responseDueAt;

        return $this;
    }

    public function getResolutionDueAt(): ?\DateTimeImmutable
    {
        return $this->resolutionDueAt;
    }

    public function setResolutionDueAt(?\DateTimeImmutable $resolutionDueAt): static
    {
        $this->resolutionDueAt = $resolutionDueAt;

        return $this;
    }

    public function getFirstResponseAt(): ?\DateTimeImmutable
    {
        return $this->firstResponseAt;
    }

    public function setFirstResponseAt(?\DateTimeImmutable $firstResponseAt): static
    {
        $this->firstResponseAt = $firstResponseAt;

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

    public function isResponseBreached(): bool
    {
        return $this->responseBreached;
    }

    public function setResponseBreached(bool $responseBreached): static
    {
        $this->responseBreached = $responseBreached;

        return $this;
    }

    public function isResolutionBreached(): bool
    {
        return $this->resolutionBreached;
    }

    public function setResolutionBreached(bool $resolutionBreached): static
    {
        $this->resolutionBreached = $resolutionBreached;

        return $this;
    }
}
