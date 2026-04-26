<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:reset-password',
    description: 'Reset the password for an existing user',
)]
class ResetUserPasswordCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email of the user whose password should be reset')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'New password to set');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = strtolower(trim((string) $input->getArgument('email')));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Please provide a valid email address.');
            return Command::INVALID;
        }

        /** @var User|null $user */
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user instanceof User) {
            $io->error(sprintf('No user found with email: %s', $email));
            return Command::FAILURE;
        }

        $password = (string) $input->getOption('password');

        if ($password === '') {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $passwordQuestion = new Question('New password: ');
            $passwordQuestion->setHidden(true);
            $passwordQuestion->setHiddenFallback(false);
            $passwordQuestion->setValidator(function (?string $answer): string {
                $value = (string) $answer;
                if (strlen($value) < 8) {
                    throw new \RuntimeException('Password must be at least 8 characters long.');
                }

                return $value;
            });

            $password = $helper->ask($input, $output, $passwordQuestion);
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->flush();

        $io->success(sprintf('Password updated for %s.', $user->getEmail()));

        return Command::SUCCESS;
    }
}