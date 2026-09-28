<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:admin:create', description: 'Crée un compte administrateur.')]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::OPTIONAL, 'Adresse e-mail de l’administrateur');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $emailArgument = $input->getArgument('email');
        $email = strtolower(trim(is_string($emailArgument) ? $emailArgument : (string) $io->ask('Adresse e-mail')));

        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('L’adresse e-mail est invalide.');

            return Command::INVALID;
        }

        if (null !== $this->entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $email])) {
            $io->error('Un compte administrateur utilise déjà cette adresse.');

            return Command::FAILURE;
        }

        $password = (string) $io->askHidden('Mot de passe (12 caractères minimum)');
        if (strlen($password) < 12) {
            $io->error('Le mot de passe doit contenir au moins 12 caractères.');

            return Command::INVALID;
        }

        if ($password !== (string) $io->askHidden('Confirmez le mot de passe')) {
            $io->error('Les mots de passe ne correspondent pas.');

            return Command::INVALID;
        }

        $user = new AdminUser($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('Le compte administrateur %s a été créé.', $email));

        return Command::SUCCESS;
    }
}
