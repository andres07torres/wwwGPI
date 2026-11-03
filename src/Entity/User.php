<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'UNIQ_TENANT_USER_EMAIL', fields: ['tenant', 'email'])]
#[ORM\UniqueConstraint(name: 'UNIQ_TENANT_USERNAME', fields: ['tenant', 'username'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['tenant', 'email'], message: 'Ya existe un usuario con este correo electrónico en este tenant')]
#[UniqueEntity(fields: ['tenant', 'username'], message: 'Ya existe este nombre de usuario en este tenant')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class, inversedBy: 'users')]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: Role::class, inversedBy: 'users')]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Role $role = null;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank(message: 'El nombre de usuario es obligatorio')]
    #[Assert\Length(min: 3, max: 60)]
    #[Assert\Regex(pattern: '/^[a-zA-Z0-9_.-]+$/', message: 'El usuario solo puede contener letras, números, puntos, guiones y guiones bajos')]
    private ?string $username = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'El nombre completo es obligatorio')]
    #[Assert\Length(min: 3, max: 120)]
    private ?string $name = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'El correo electrónico es obligatorio')]
    #[Assert\Email(message: 'El correo electrónico "{{ value }}" no es válido')]
    private ?string $email = null;

    #[ORM\Column(name: 'password_hash', length: 255)]
    private ?string $passwordHash = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $status = true;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isVerified = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getRole(): ?Role
    {
        return $this->role;
    }

    public function setRole(?Role $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = mb_strtolower(trim($username));

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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getPasswordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $passwordHash): static
    {
        $this->passwordHash = $passwordHash;

        return $this;
    }

    /**
     * PasswordAuthenticatedUserInterface compatibility
     */
    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    public function setPassword(string $password): static
    {
        $this->passwordHash = $password;

        return $this;
    }

    public function isStatus(): bool
    {
        return $this->status;
    }

    public function setStatus(bool $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) ($this->username ?? $this->email);
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];

        if ($this->role) {
            $roleName = strtoupper($this->role->getName() ?? '');
            if (!str_starts_with($roleName, 'ROLE_')) {
                $roleName = 'ROLE_' . str_replace(' ', '_', $roleName);
            }
            $roles[] = $roleName;
        }

        return array_values(array_unique($roles));
    }

    public function eraseCredentials(): void
    {
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
}
