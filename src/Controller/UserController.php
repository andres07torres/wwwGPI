<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Permission;
use App\Entity\Role;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\PermissionRepository;
use App\Repository\RoleRepository;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

#[Route('/users')]
final class UserController extends AbstractController
{
    #[Route('', name: 'app_user_index', methods: ['GET'])]
    public function index(
        UserRepository $userRepository,
        RoleRepository $roleRepository,
        TenantRepository $tenantRepository,
        PermissionRepository $permissionRepository,
        EntityManagerInterface $em,
    ): Response {
        // Asegurar la existencia de roles y permisos base si la base de datos está vacía
        $this->ensureDefaultRolesAndPermissions($em, $roleRepository, $permissionRepository, $tenantRepository);

        $users = $userRepository->findBy([], ['createdAt' => 'DESC']);
        $roles = $roleRepository->findAll();
        $tenants = $tenantRepository->findAll();
        $permissions = $permissionRepository->findAll();

        return $this->render('user/index.html.twig', [
            'users' => $users,
            'roles' => $roles,
            'tenants' => $tenants,
            'permissions' => $permissions,
        ]);
    }

    #[Route('/create', name: 'app_user_create', methods: ['POST'])]
    public function create(
        Request $request,
        UserRepository $userRepository,
        RoleRepository $roleRepository,
        TenantRepository $tenantRepository,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfManager,
    ): Response {
        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('create_user', $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.', Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) $request->request->get('name'));
        $username = mb_strtolower(trim((string) $request->request->get('username')));
        $email = mb_strtolower(trim((string) $request->request->get('email')));
        $password = (string) $request->request->get('password');
        $roleId = $request->request->get('role_id');
        $tenantId = $request->request->get('tenant_id');
        $status = $request->request->getBoolean('status', true);

        // Validaciones
        if (mb_strlen($name) < 3) {
            return $this->handleResponse($request, false, 'El nombre debe tener al menos 3 caracteres.');
        }

        if (mb_strlen($username) < 3 || !preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            return $this->handleResponse($request, false, 'El usuario debe tener al menos 3 caracteres y formato válido.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->handleResponse($request, false, 'Escribe un correo electrónico válido.');
        }

        if (mb_strlen($password) < 8) {
            return $this->handleResponse($request, false, 'La contraseña debe tener al menos 8 caracteres.');
        }

        if ($userRepository->findOneBy(['username' => $username])) {
            return $this->handleResponse($request, false, 'El nombre de usuario ya está registrado.');
        }

        if ($userRepository->findOneBy(['email' => $email])) {
            return $this->handleResponse($request, false, 'El correo electrónico ya está registrado.');
        }

        $user = new User();
        $user->setName($name);
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setStatus($status);
        $user->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, $password));

        if ($roleId) {
            $role = $roleRepository->find($roleId);
            if ($role) {
                $user->setRole($role);
            }
        }

        if ($tenantId) {
            try {
                $tenant = $tenantRepository->find(Uuid::fromString((string) $tenantId));
                if ($tenant) {
                    $user->setTenant($tenant);
                }
            } catch (\InvalidArgumentException) {}
        }

        if (!$user->getTenant()) {
            $firstTenant = $tenantRepository->findOneBy([]);
            if ($firstTenant) {
                $user->setTenant($firstTenant);
            }
        }

        $em->persist($user);
        $em->flush();

        return $this->handleResponse($request, true, 'Usuario creado exitosamente.');
    }

    #[Route('/{id}/edit', name: 'app_user_edit', methods: ['POST'])]
    public function edit(
        string $id,
        Request $request,
        UserRepository $userRepository,
        RoleRepository $roleRepository,
        TenantRepository $tenantRepository,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfManager,
    ): Response {
        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('edit_user', $token)) && !$csrfManager->isTokenValid(new CsrfToken('edit_user_' . $id, $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $uuid = Uuid::fromString($id);
            $user = $userRepository->find($uuid);
        } catch (\InvalidArgumentException) {
            $user = null;
        }

        if (!$user) {
            return $this->handleResponse($request, false, 'Usuario no encontrado.', Response::HTTP_NOT_FOUND);
        }

        $name = trim((string) $request->request->get('name'));
        $username = mb_strtolower(trim((string) $request->request->get('username')));
        $email = mb_strtolower(trim((string) $request->request->get('email')));
        $password = (string) $request->request->get('password');
        $status = $request->request->getBoolean('status', true);
        $tenantId = $request->request->get('tenant_id');

        if (mb_strlen($name) < 3) {
            return $this->handleResponse($request, false, 'El nombre debe tener al menos 3 caracteres.');
        }

        if (mb_strlen($username) < 3 || !preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            return $this->handleResponse($request, false, 'El nombre de usuario no es válido.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->handleResponse($request, false, 'El correo electrónico no es válido.');
        }

        // Validar unicidad si cambió el username o email
        $existingUser = $userRepository->findOneBy(['username' => $username]);
        if ($existingUser && $existingUser->getId()?->toRfc4122() !== $user->getId()?->toRfc4122()) {
            return $this->handleResponse($request, false, 'El nombre de usuario ya está en uso por otro registro.');
        }

        $existingEmail = $userRepository->findOneBy(['email' => $email]);
        if ($existingEmail && $existingEmail->getId()?->toRfc4122() !== $user->getId()?->toRfc4122()) {
            return $this->handleResponse($request, false, 'El correo electrónico ya está en uso por otro registro.');
        }

        $user->setName($name);
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setStatus($status);

        if (!empty($password)) {
            if (mb_strlen($password) < 8) {
                return $this->handleResponse($request, false, 'La nueva contraseña debe tener al menos 8 caracteres.');
            }
            $user->setPassword($hasher->hashPassword($user, $password));
        }

        if ($tenantId) {
            try {
                $tenant = $tenantRepository->find(Uuid::fromString((string) $tenantId));
                if ($tenant) {
                    $user->setTenant($tenant);
                }
            } catch (\InvalidArgumentException) {}
        }

        $em->flush();

        return $this->handleResponse($request, true, 'Datos del usuario actualizados correctamente.');
    }

    #[Route('/{id}/roles', name: 'app_user_manage_roles', methods: ['POST'])]
    public function manageRoles(
        string $id,
        Request $request,
        UserRepository $userRepository,
        RoleRepository $roleRepository,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfManager,
    ): Response {
        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('manage_roles', $token)) && !$csrfManager->isTokenValid(new CsrfToken('manage_roles_' . $id, $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $user = $userRepository->find(Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            $user = null;
        }

        if (!$user) {
            return $this->handleResponse($request, false, 'Usuario no encontrado.', Response::HTTP_NOT_FOUND);
        }

        $roleId = $request->request->get('role_id');
        if ($roleId) {
            $role = $roleRepository->find($roleId);
            if ($role) {
                $user->setRole($role);
            }
        } else {
            $user->setRole(null);
        }

        $em->flush();

        return $this->handleResponse($request, true, 'Rol y permisos actualizados para el usuario.');
    }

    #[Route('/{id}/delete', name: 'app_user_delete', methods: ['POST'])]
    public function delete(
        string $id,
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfManager,
    ): Response {
        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('delete_user', $token)) && !$csrfManager->isTokenValid(new CsrfToken('delete_user_' . $id, $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $user = $userRepository->find(Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            $user = null;
        }

        if (!$user) {
            return $this->handleResponse($request, false, 'Usuario no encontrado.', Response::HTTP_NOT_FOUND);
        }

        /** @var User|null $currentUser */
        $currentUser = $this->getUser();
        if ($currentUser && $currentUser->getId()?->toRfc4122() === $user->getId()?->toRfc4122()) {
            return $this->handleResponse($request, false, 'Por seguridad, no puedes eliminar tu propia cuenta en sesión activa.');
        }

        $username = $user->getUsername();
        $em->remove($user);
        $em->flush();

        return $this->handleResponse($request, true, sprintf('El usuario "%s" fue eliminado correctamente.', $username));
    }

    private function handleResponse(Request $request, bool $success, string $message, int $status = Response::HTTP_OK): Response
    {
        if ($request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json' || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse(['success' => $success, 'message' => $message], $success ? $status : Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash($success ? 'success' : 'error', $message);
        return $this->redirectToRoute('app_user_index');
    }

    private function ensureDefaultRolesAndPermissions(
        EntityManagerInterface $em,
        RoleRepository $roleRepository,
        PermissionRepository $permissionRepository,
        TenantRepository $tenantRepository,
    ): void {
        $permissionsMap = [
            'users.view' => 'Ver directorio de usuarios',
            'users.create' => 'Crear nuevos usuarios',
            'users.edit' => 'Editar usuarios existentes',
            'users.delete' => 'Eliminar cuentas de usuario',
            'roles.manage' => 'Gestionar roles y permisos',
            'tickets.view' => 'Consultar mesa de ayuda',
            'tickets.manage' => 'Gestionar ciclo de vida de tickets',
            'cmdb.view' => 'Ver inventario de activos TI',
            'cmdb.manage' => 'Administrar elementos de configuración (CMDB)',
            'sla.manage' => 'Configurar políticas y cronómetros SLA',
            'changes.approve' => 'Aprobar solicitudes en Comité CAB',
            'wiki.write' => 'Publicar artículos en base de conocimiento',
        ];

        $permissionEntities = [];
        foreach ($permissionsMap as $code => $name) {
            $perm = $permissionRepository->findOneBy(['code' => $code]);
            if (!$perm) {
                $perm = new Permission();
                $perm->setCode($code);
                $perm->setName($name);
                $em->persist($perm);
            }
            $permissionEntities[$code] = $perm;
        }

        $defaultTenant = $tenantRepository->findOneBy([]);
        if (!$defaultTenant) {
            $defaultTenant = new Tenant();
            $defaultTenant->setName('Tenant Principal');
            $defaultTenant->setDomain('default');
            $defaultTenant->setStatus(true);
            $em->persist($defaultTenant);
        }

        $rolesConfig = [
            'Super Admin' => array_keys($permissionsMap),
            'Administrador' => ['users.view', 'users.create', 'users.edit', 'roles.manage', 'tickets.view', 'tickets.manage', 'cmdb.view', 'cmdb.manage', 'sla.manage', 'wiki.write'],
            'Especialista TI' => ['tickets.view', 'tickets.manage', 'cmdb.view', 'cmdb.manage', 'wiki.write'],
            'Usuario' => ['tickets.view', 'tickets.manage'],
        ];

        foreach ($rolesConfig as $roleName => $allowedPermCodes) {
            $role = $roleRepository->findOneBy(['name' => $roleName]);
            if (!$role) {
                $role = new Role();
                $role->setName($roleName);
                $role->setTenant($defaultTenant);
                foreach ($allowedPermCodes as $code) {
                    if (isset($permissionEntities[$code])) {
                        $role->addPermission($permissionEntities[$code]);
                    }
                }
                $em->persist($role);
            }
        }

        $em->flush();
    }
}
