<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Role;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\RoleRepository;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-user',
    description: 'Crea un usuario en el sistema con credenciales y rol listos para acceder.',
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepository,
        private readonly RoleRepository $roleRepository,
        private readonly TenantRepository $tenantRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Nombre de usuario (ej. admin o ctorres)')
            ->addArgument('password', InputArgument::REQUIRED, 'Contraseña de acceso')
            ->addOption('email', null, InputOption::VALUE_OPTIONAL, 'Correo electrónico', 'admin@gpi.local')
            ->addOption('name', null, InputOption::VALUE_OPTIONAL, 'Nombre completo', 'Usuario Administrador')
            ->addOption('role', null, InputOption::VALUE_OPTIONAL, 'Nombre del rol (ej. "Super Admin", "Administrador", "Especialista TI")', 'Super Admin')
            ->addOption('tenant', null, InputOption::VALUE_OPTIONAL, 'Nombre o dominio del Tenant por defecto', 'default')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = mb_strtolower(trim((string) $input->getArgument('username')));
        $password = (string) $input->getArgument('password');
        $email = mb_strtolower(trim((string) $input->getOption('email')));
        $name = trim((string) $input->getOption('name'));
        $roleName = trim((string) $input->getOption('role'));
        $tenantDomain = mb_strtolower(trim((string) $input->getOption('tenant')));

        // Validar si el usuario ya existe
        $existing = $this->userRepository->findOneBy(['username' => $username]);
        if ($existing) {
            $io->error(sprintf('Ya existe un usuario con el username "%s".', $username));
            return Command::FAILURE;
        }

        // Buscar o crear Tenant por defecto si se especificó
        $tenant = $this->tenantRepository->findOneBy(['domain' => $tenantDomain]);
        if (!$tenant) {
            $tenant = new Tenant();
            $tenant->setName('Tenant Principal');
            $tenant->setDomain($tenantDomain);
            $tenant->setStatus(true);
            $this->entityManager->persist($tenant);
        }

        // Buscar o crear Rol
        $role = null;
        if ($roleName !== '') {
            $role = $this->roleRepository->findOneBy(['name' => $roleName, 'tenant' => $tenant])
                ?? $this->roleRepository->findOneBy(['name' => $roleName, 'tenant' => null]);

            if (!$role) {
                $role = new Role();
                $role->setName($roleName);
                $role->setTenant($tenant);
                $this->entityManager->persist($role);
            }
        }

        // Crear Usuario
        $user = new User();
        $user->setUsername($username);
        $user->setName($name);
        $user->setEmail($email);
        $user->setStatus(true);
        $user->setIsVerified(true);
        $user->setTenant($tenant);
        if ($role) {
            $user->setRole($role);
        }

        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('¡Usuario "%s" creado exitosamente!', $username));
        $io->table(
            ['Campo', 'Valor'],
            [
                ['Usuario (Username)', $user->getUsername()],
                ['Nombre', $user->getName()],
                ['Email', $user->getEmail()],
                ['Rol asignado', $role ? $role->getName() : 'ROLE_USER'],
                ['Tenant', $tenant->getDomain()],
                ['Estado', 'Activo / Verificado'],
            ]
        );

        return Command::SUCCESS;
    }
}
