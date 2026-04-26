<?php

namespace App\Command;

use App\Entity\Alumni;
use App\Entity\User;
use App\Repository\AlumniRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync-alumni-users',
    description: 'Creates Alumni records for Users with ROLE_ALUMNI that don\'t have one yet',
)]
class SyncAlumniUsersCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private AlumniRepository $alumniRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Find all Alumni users without an Alumni record
        $orphanedUsers = $this->userRepository
            ->createQueryBuilder('u')
            ->leftJoin('u.alumni', 'a')
            ->where('u.roles LIKE :alumni_role')
            ->andWhere('a.id IS NULL')
            ->setParameter('alumni_role', '%"' . User::ROLE_ALUMNI . '"%')
            ->getQuery()
            ->getResult();

        if (empty($orphanedUsers)) {
            $io->success('No orphaned alumni users found. All alumni have Alumni records!');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d orphaned alumni user(s). Linking or creating Alumni records...', count($orphanedUsers)));

        $created = 0;
        $linked = 0;
        $skipped = 0;

        foreach ($orphanedUsers as $user) {
            try {
                $email = strtolower(trim((string) $user->getEmail()));
                $schoolId = trim((string) ($user->getSchoolId() ?? ''));
                $alumniByEmail = $email !== '' ? $this->alumniRepository->findOneBy(['emailAddress' => $email]) : null;
                $alumniByStudentNumber = $schoolId !== '' ? $this->alumniRepository->findOneBy(['studentNumber' => $schoolId]) : null;

                if (
                    $alumniByEmail instanceof Alumni
                    && $alumniByStudentNumber instanceof Alumni
                    && $alumniByEmail->getId() !== $alumniByStudentNumber->getId()
                ) {
                    $io->warning(sprintf(
                        'Skipped %s (%s): email and school ID matched different alumni records. Resolve the duplicate manually first.',
                        $user->getFullName(),
                        $user->getEmail()
                    ));
                    ++$skipped;

                    continue;
                }

                $alumni = $alumniByEmail ?? $alumniByStudentNumber;

                if ($alumni instanceof Alumni && $alumni->getUser() !== null) {
                    $io->warning(sprintf(
                        'Skipped %s (%s): matching alumni record is already linked to another user.',
                        $user->getFullName(),
                        $user->getEmail()
                    ));
                    ++$skipped;

                    continue;
                }

                $wasCreated = false;

                if (!$alumni instanceof Alumni) {
                    if ($schoolId === '') {
                        $io->warning(sprintf(
                            'Skipped %s (%s): no school ID was found and no existing alumni record matched the email. Manual cleanup is required.',
                            $user->getFullName(),
                            $user->getEmail()
                        ));
                        ++$skipped;

                        continue;
                    }

                    $alumni = new Alumni();
                    $alumni->setStudentNumber($schoolId);
                    $wasCreated = true;
                }

                $alumni->setUser($user);
                $alumni->setFirstName($user->getFirstName());
                $alumni->setLastName($user->getLastName());
                $alumni->setEmailAddress($email);

                if ($schoolId !== '') {
                    $alumni->setStudentNumber($schoolId);
                }

                $user->setAlumni($alumni);
                $this->entityManager->persist($alumni);
                $this->entityManager->flush();

                if ($wasCreated) {
                    ++$created;
                    $io->writeln(sprintf('  ✓ Created Alumni record for: %s (%s)', $user->getFullName(), $user->getEmail()));

                    continue;
                }

                ++$linked;
                $io->writeln(sprintf('  ✓ Linked existing Alumni record for: %s (%s)', $user->getFullName(), $user->getEmail()));
            } catch (\Throwable $e) {
                $io->error(sprintf('Failed to sync Alumni for %s: %s', $user->getEmail(), $e->getMessage()));

                return Command::FAILURE;
            }
        }

        $io->success(sprintf(
            'Sync complete. Created %d Alumni record(s), linked %d existing record(s), skipped %d account(s).',
            $created,
            $linked,
            $skipped
        ));

        return Command::SUCCESS;
    }
}
