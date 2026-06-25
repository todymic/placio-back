<?php

namespace App\Entity;

use App\Dto\ChartObjectNode;
use App\Repository\ChartRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ChartRepository::class)]
#[ORM\Table(name: 'charts')]
class Chart
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'string', nullable: false)]
    private string $name;

    #[ORM\Column(type: 'string', unique: true, nullable: false)]
    private string $slug;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $objectsJson = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /** @return ChartObjectNode[] */
    public function getObjects(): array
    {
        if (!$this->objectsJson) {
            return [];
        }

        return array_map(
            fn($data) => ChartObjectNode::fromArray($data),
            $this->objectsJson
        );
    }

    /** @param ChartObjectNode[] $objects */
    public function setObjects(array $objects): self
    {
        $this->objectsJson = array_map(fn($obj) => $obj->toArray(), $objects);
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getObjectsJson(): ?array
    {
        return $this->objectsJson;
    }

    public function setObjectsJson(?array $objectsJson): self
    {
        $this->objectsJson = $objectsJson;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

