<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CiRelationshipType;
use App\Repository\CiRelationshipRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CiRelationshipRepository::class)]
#[ORM\Table(name: 'ci_relationships')]
class CiRelationship
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CiItem::class, inversedBy: 'childRelationships')]
    #[ORM\JoinColumn(name: 'parent_ci_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?CiItem $parentCi = null;

    #[ORM\ManyToOne(targetEntity: CiItem::class, inversedBy: 'parentRelationships')]
    #[ORM\JoinColumn(name: 'child_ci_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?CiItem $childCi = null;

    #[ORM\Column(name: 'relationship_type', type: 'string', enumType: CiRelationshipType::class)]
    private CiRelationshipType $relationshipType = CiRelationshipType::DEPENDS_ON;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParentCi(): ?CiItem
    {
        return $this->parentCi;
    }

    public function setParentCi(?CiItem $parentCi): static
    {
        $this->parentCi = $parentCi;

        return $this;
    }

    public function getChildCi(): ?CiItem
    {
        return $this->childCi;
    }

    public function setChildCi(?CiItem $childCi): static
    {
        $this->childCi = $childCi;

        return $this;
    }

    public function getRelationshipType(): CiRelationshipType
    {
        return $this->relationshipType;
    }

    public function setRelationshipType(CiRelationshipType $relationshipType): static
    {
        $this->relationshipType = $relationshipType;

        return $this;
    }
}
