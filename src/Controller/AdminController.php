<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

#[Route('/admin')]
final class AdminController extends AbstractController
{
    /**
     * Vista principal del submódulo de Organizaciones (Tenants).
     */
    #[Route('/organizations', name: 'app_admin_organization_index', methods: ['GET'])]
    public function organizationIndex(
        TenantRepository $tenantRepository,
        UserRepository $userRepository,
    ): Response {
        /** @var User|null $currentUser */
        $currentUser = $this->getUser();
        if (!$currentUser) {
            return $this->redirectToRoute('app_login');
        }

        $tenants = $tenantRepository->findBy([], ['name' => 'ASC']);

        $totalTenants = count($tenants);
        $activeTenants = count(array_filter($tenants, fn(Tenant $t) => $t->isStatus()));
        $inactiveTenants = $totalTenants - $activeTenants;
        $totalUsersCount = $userRepository->count([]);

        return $this->render('admin/organization/index.html.twig', [
            'tenants' => $tenants,
            'currentTenantId' => $currentUser->getTenant()?->getId()?->toRfc4122(),
            'stats' => [
                'totalTenants' => $totalTenants,
                'activeTenants' => $activeTenants,
                'inactiveTenants' => $inactiveTenants,
                'totalUsersCount' => $totalUsersCount,
            ],
        ]);
    }

    /**
     * Consulta AJAX de los detalles completos de una organización.
     */
    #[Route('/organizations/{id}/detail', name: 'app_admin_organization_detail', methods: ['GET'])]
    public function organizationDetail(
        string $id,
        TenantRepository $tenantRepository,
    ): JsonResponse {
        try {
            $uuid = Uuid::fromString($id);
            $tenant = $tenantRepository->find($uuid);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['success' => false, 'message' => 'Identificador de organización inválido.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$tenant) {
            return new JsonResponse(['success' => false, 'message' => 'Organización no encontrada.'], Response::HTTP_NOT_FOUND);
        }

        $users = [];
        foreach ($tenant->getUsers() as $user) {
            $users[] = [
                'id' => (string) $user->getId(),
                'name' => $user->getName() ?? $user->getUserIdentifier(),
                'email' => $user->getEmail(),
                'isActive' => $user->isStatus(),
            ];
        }

        return new JsonResponse([
            'success' => true,
            'organization' => [
                'id' => (string) $tenant->getId(),
                'name' => $tenant->getName(),
                'domain' => $tenant->getDomain(),
                'status' => $tenant->isStatus(),
                'createdAt' => $tenant->getCreatedAt()->format('d/m/Y H:i'),
                'updatedAt' => $tenant->getUpdatedAt()->format('d/m/Y H:i'),
                'usersCount' => $tenant->getUsers()->count(),
                'rolesCount' => $tenant->getRoles()->count(),
                'users' => $users,
            ],
        ]);
    }

    /**
     * Registrar una nueva organización.
     */
    #[Route('/organizations/create', name: 'app_admin_organization_create', methods: ['POST'])]
    public function organizationCreate(
        Request $request,
        TenantRepository $tenantRepository,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfManager,
    ): Response {
        /** @var User|null $currentUser */
        $currentUser = $this->getUser();
        if (!$currentUser) {
            return $this->redirectToRoute('app_login');
        }

        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('create_organization', $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.', redirectRoute: 'app_admin_organization_index');
        }

        $name = trim((string) $request->request->get('name'));
        $domain = mb_strtolower(trim((string) $request->request->get('domain')));
        $status = $request->request->getBoolean('status', true);

        if ($name === '' || $domain === '') {
            return $this->handleResponse($request, false, 'El nombre y el dominio corporativo son campos obligatorios.', redirectRoute: 'app_admin_organization_index');
        }

        // Sanitización de dominio: remover espacios o protocolos accidentales
        $domain = preg_replace('/^https?:\/\//i', '', $domain) ?? $domain;
        $domain = trim($domain, '/');

        // Validar unicidad del dominio
        if ($tenantRepository->findOneBy(['domain' => $domain])) {
            return $this->handleResponse($request, false, sprintf('Ya existe una organización registrada con el dominio "%s".', $domain), redirectRoute: 'app_admin_organization_index');
        }

        $tenant = new Tenant();
        $tenant->setName($name);
        $tenant->setDomain($domain);
        $tenant->setStatus($status);

        $em->persist($tenant);
        $em->flush();

        // Registro de Auditoría
        $audit = new AuditLog();
        $audit->setTenant($tenant);
        $audit->setUser($currentUser);
        $audit->setAction('CREATE_ORGANIZATION');
        $audit->setEntityType('Tenant');
        $audit->setEntityId($tenant->getId());
        $audit->setNewValues([
            'name' => $name,
            'domain' => $domain,
            'status' => $status,
        ]);
        $em->persist($audit);
        $em->flush();

        return $this->handleResponse($request, true, sprintf('Organización "%s" registrada exitosamente.', $name), redirectRoute: 'app_admin_organization_index');
    }

    /**
     * Editar una organización existente.
     */
    #[Route('/organizations/{id}/edit', name: 'app_admin_organization_edit', methods: ['POST'])]
    public function organizationEdit(
        string $id,
        Request $request,
        TenantRepository $tenantRepository,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfManager,
    ): Response {
        /** @var User|null $currentUser */
        $currentUser = $this->getUser();
        if (!$currentUser) {
            return $this->redirectToRoute('app_login');
        }

        try {
            $uuid = Uuid::fromString($id);
            $tenant = $tenantRepository->find($uuid);
        } catch (\InvalidArgumentException) {
            return $this->handleResponse($request, false, 'Identificador de organización inválido.', redirectRoute: 'app_admin_organization_index');
        }

        if (!$tenant) {
            return $this->handleResponse($request, false, 'La organización no existe o ya fue eliminada.', redirectRoute: 'app_admin_organization_index');
        }

        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('edit_organization_' . $id, $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.', redirectRoute: 'app_admin_organization_index');
        }

        $name = trim((string) $request->request->get('name'));
        $domain = mb_strtolower(trim((string) $request->request->get('domain')));
        $status = $request->request->getBoolean('status');

        if ($name === '' || $domain === '') {
            return $this->handleResponse($request, false, 'El nombre y el dominio corporativo son requeridos.', redirectRoute: 'app_admin_organization_index');
        }

        $domain = preg_replace('/^https?:\/\//i', '', $domain) ?? $domain;
        $domain = trim($domain, '/');

        // Validar unicidad del dominio contra otras organizaciones
        $existing = $tenantRepository->findOneBy(['domain' => $domain]);
        if ($existing && $tenant->getId() && !$existing->getId()?->equals($tenant->getId())) {
            return $this->handleResponse($request, false, sprintf('El dominio "%s" ya pertenece a otra organización registrada.', $domain), redirectRoute: 'app_admin_organization_index');
        }

        $oldValues = [
            'name' => $tenant->getName(),
            'domain' => $tenant->getDomain(),
            'status' => $tenant->isStatus(),
        ];

        $tenant->setName($name);
        $tenant->setDomain($domain);
        $tenant->setStatus($status);

        $em->flush();

        // Auditoría
        $audit = new AuditLog();
        $audit->setTenant($tenant);
        $audit->setUser($currentUser);
        $audit->setAction('UPDATE_ORGANIZATION');
        $audit->setEntityType('Tenant');
        $audit->setEntityId($tenant->getId());
        $audit->setOldValues($oldValues);
        $audit->setNewValues([
            'name' => $name,
            'domain' => $domain,
            'status' => $status,
        ]);
        $em->persist($audit);
        $em->flush();

        return $this->handleResponse($request, true, sprintf('Organización "%s" actualizada correctamente.', $name), redirectRoute: 'app_admin_organization_index');
    }

    /**
     * Alternar estado Activo / Inactivo de una organización.
     */
    #[Route('/organizations/{id}/toggle-status', name: 'app_admin_organization_toggle_status', methods: ['POST'])]
    public function organizationToggleStatus(
        string $id,
        Request $request,
        TenantRepository $tenantRepository,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfManager,
    ): Response {
        /** @var User|null $currentUser */
        $currentUser = $this->getUser();
        if (!$currentUser) {
            return $this->redirectToRoute('app_login');
        }

        try {
            $uuid = Uuid::fromString($id);
            $tenant = $tenantRepository->find($uuid);
        } catch (\InvalidArgumentException) {
            return $this->handleResponse($request, false, 'Identificador de organización inválido.', redirectRoute: 'app_admin_organization_index');
        }

        if (!$tenant) {
            return $this->handleResponse($request, false, 'La organización no existe.', redirectRoute: 'app_admin_organization_index');
        }

        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('toggle_organization_' . $id, $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.', redirectRoute: 'app_admin_organization_index');
        }

        // Impedir que un usuario desactive su propia organización actual
        if ($currentUser->getTenant()?->getId()?->equals($tenant->getId()) && $tenant->isStatus()) {
            return $this->handleResponse($request, false, 'No puede desactivar la organización a la que pertenece su cuenta activa.', redirectRoute: 'app_admin_organization_index');
        }

        $newStatus = !$tenant->isStatus();
        $tenant->setStatus($newStatus);
        $em->flush();

        $audit = new AuditLog();
        $audit->setTenant($tenant);
        $audit->setUser($currentUser);
        $audit->setAction('TOGGLE_STATUS_ORGANIZATION');
        $audit->setEntityType('Tenant');
        $audit->setEntityId($tenant->getId());
        $audit->setNewValues(['status' => $newStatus]);
        $em->persist($audit);
        $em->flush();

        $statusLabel = $newStatus ? 'activada' : 'desactivada';

        return $this->handleResponse($request, true, sprintf('La organización "%s" ha sido %s.', $tenant->getName(), $statusLabel), redirectRoute: 'app_admin_organization_index');
    }

    /**
     * Eliminar una organización.
     */
    #[Route('/organizations/{id}/delete', name: 'app_admin_organization_delete', methods: ['POST'])]
    public function organizationDelete(
        string $id,
        Request $request,
        TenantRepository $tenantRepository,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfManager,
    ): Response {
        /** @var User|null $currentUser */
        $currentUser = $this->getUser();
        if (!$currentUser) {
            return $this->redirectToRoute('app_login');
        }

        try {
            $uuid = Uuid::fromString($id);
            $tenant = $tenantRepository->find($uuid);
        } catch (\InvalidArgumentException) {
            return $this->handleResponse($request, false, 'Identificador de organización inválido.', redirectRoute: 'app_admin_organization_index');
        }

        if (!$tenant) {
            return $this->handleResponse($request, false, 'La organización no existe o ya fue eliminada.', redirectRoute: 'app_admin_organization_index');
        }

        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('delete_organization_' . $id, $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.', redirectRoute: 'app_admin_organization_index');
        }

        // Protección 1: No eliminar la organización del usuario autenticado
        if ($currentUser->getTenant()?->getId()?->equals($tenant->getId())) {
            return $this->handleResponse($request, false, 'No puede eliminar la organización a la que pertenece su sesión actual.', redirectRoute: 'app_admin_organization_index');
        }

        // Protección 2: No eliminar si tiene usuarios vinculados
        if ($tenant->getUsers()->count() > 0) {
            return $this->handleResponse(
                $request,
                false,
                sprintf('No es posible eliminar "%s" porque cuenta con %d usuario(s) vinculado(s). Reasigne los usuarios o desactive la organización.', $tenant->getName(), $tenant->getUsers()->count()),
                redirectRoute: 'app_admin_organization_index'
            );
        }

        $tenantName = $tenant->getName() ?? 'Sin nombre';
        $fallbackTenant = $currentUser->getTenant() ?? $tenant;

        // Auditoría previa a la eliminación
        $audit = new AuditLog();
        $audit->setTenant($fallbackTenant);
        $audit->setUser($currentUser);
        $audit->setAction('DELETE_ORGANIZATION');
        $audit->setEntityType('Tenant');
        $audit->setEntityId($tenant->getId());
        $audit->setOldValues([
            'name' => $tenantName,
            'domain' => $tenant->getDomain(),
        ]);
        $em->persist($audit);

        $em->remove($tenant);
        $em->flush();

        return $this->handleResponse($request, true, sprintf('Organización "%s" eliminada permanentemente.', $tenantName), redirectRoute: 'app_admin_organization_index');
    }

    /**
     * Respuesta unificada para Turbo/Fetch y envíos tradicionales.
     */
    private function handleResponse(
        Request $request,
        bool $success,
        string $message,
        int $status = Response::HTTP_OK,
        string $redirectRoute = 'app_admin_organization_index'
    ): Response {
        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse(['success' => $success, 'message' => $message], $success ? $status : Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash($success ? 'success' : 'error', $message);

        return $this->redirectToRoute($redirectRoute);
    }
}
