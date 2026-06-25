<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserService
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function register(string $email, string $password, string $displayName = null): User
    {
        $existing = $this->userRepository->findByEmail($email);
        if ($existing) {
            throw new \Exception('User with this email already exists');
        }

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName($displayName ?? $email);
        $user->setRoles(['ROLE_USER']);

        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function findByEmail(string $email): ?User
    {
        return $this->userRepository->findByEmail($email);
    }

    public function updateProfile(User $user, string $displayName = null): User
    {
        if ($displayName) {
            $user->setDisplayName($displayName);
        }
        $user->setLastLoginAt(new \DateTimeImmutable());

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}

