<?php

namespace App\Entity;

use App\Repository\EventSeatRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EventSeatRepository::class)]
#[ORM\Table(name: 'event_seats', uniqueConstraints: [
    new ORM\UniqueConstraint(name: 'UNIQ_event_seat_key', columns: ['event_id', 'seat_key'])
])]
class EventSeat
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Event::class, inversedBy: 'seats')]
    #[ORM\JoinColumn(nullable: false)]
    private Event $event;

    #[ORM\Column(type: 'string', nullable: false)]
    private string $seatKey;

    #[ORM\Column(type: 'string', enumType: SeatStatus::class, nullable: false)]
    private SeatStatus $status = SeatStatus::AVAILABLE;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $holdToken = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $heldUntil = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function setEvent(Event $event): self
    {
        $this->event = $event;
        return $this;
    }

    public function getSeatKey(): string
    {
        return $this->seatKey;
    }

    public function setSeatKey(string $seatKey): self
    {
        $this->seatKey = $seatKey;
        return $this;
    }

    public function getStatus(): SeatStatus
    {
        return $this->status;
    }

    public function setStatus(SeatStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getHoldToken(): ?string
    {
        return $this->holdToken;
    }

    public function setHoldToken(?string $holdToken): self
    {
        $this->holdToken = $holdToken;
        return $this;
    }

    public function getHeldUntil(): ?\DateTimeImmutable
    {
        return $this->heldUntil;
    }

    public function setHeldUntil(?\DateTimeImmutable $heldUntil): self
    {
        $this->heldUntil = $heldUntil;
        return $this;
    }
}

