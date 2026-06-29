<?php

namespace App\Command;

use App\Entity\SeatStatus;
use App\Repository\EventSeatRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:expire-holds',
    description: 'Release seat holds whose expiry has passed',
)]
class ExpireHoldsCommand extends Command
{
    public function __construct(
        private EventSeatRepository $eventSeatRepository,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $expired = $this->eventSeatRepository->findByStatusAndHeldUntilBefore(
            SeatStatus::HOLD,
            new \DateTimeImmutable(),
        );

        if (empty($expired)) {
            return Command::SUCCESS;
        }

        $ids = array_map(fn($seat) => $seat->getId(), $expired);
        $this->eventSeatRepository->updateStatusByIds($ids, SeatStatus::AVAILABLE);

        $output->writeln(sprintf('Released %d expired hold(s).', count($expired)));

        return Command::SUCCESS;
    }
}
