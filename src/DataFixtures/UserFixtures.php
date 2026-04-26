<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
        private UserRepository $userRepository,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $existingAdmin = $this->userRepository->findOneBy(['email' => 'admin@norsu.edu.ph']);

        $admin = $existingAdmin instanceof User ? $existingAdmin : new User();
        $admin->setEmail('admin@norsu.edu.ph');
        $admin->setFirstName('System');
        $admin->setLastName('Administrator');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setAccountStatus('active');
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'Admin@12345'));

        if (!$existingAdmin instanceof User) {
            $manager->persist($admin);
        }

        $manager->flush();
    }
}
