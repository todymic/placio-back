<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:user:create-backoffice',
    description: 'Create or promote a user with ROLE_BACKOFFICE.'
)]
class CreateBackofficeUserCommand extends Command
{
    public function __construct(
        private UserService $userService,
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email')
            ->addArgument('password', InputArgument::OPTIONAL, 'User password (required for new users)')
            ->addArgument('displayName', InputArgument::OPTIONAL, 'Display name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = (string) $input->getArgument('email');
        $password = $input->getArgument('password');
        $displayName = $input->getArgument('displayName');

        $user = $this->userRepository->findByEmail($email);

        if (!$user) {
            if (!is_string($password) || $password === '') {
                $io->error('Password is required when creating a new user.');
                return Command::INVALID;
            }

            $user = $this->userService->register(
                $email,
                $password,
                is_string($displayName) && $displayName !== '' ? $displayName : $email,
            );

            $io->note('User created. Promoting to ROLE_BACKOFFICE...');
        } else {
            $io->note('User already exists. Ensuring ROLE_BACKOFFICE...');
        }

        $roles = $user->getRoles();
        if (!in_array('ROLE_BACKOFFICE', $roles, true)) {
            $roles[] = 'ROLE_BACKOFFICE';
        }
        if (!in_array('ROLE_USER', $roles, true)) {
            $roles[] = 'ROLE_USER';
        }

        $user->setRoles(array_values(array_unique($roles)));
        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('User %s now has roles: %s', $email, implode(', ', $user->getRoles())));

        return Command::SUCCESS;
    }
}

