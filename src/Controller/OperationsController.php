<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\Change;
use App\Entity\CiItem;
use App\Entity\Problem;
use App\Entity\Tenant;
use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\ChangeRiskLevel;
use App\Enum\ChangeStatus;
use App\Enum\ProblemStatus;
use App\Repository\ChangeRepository;
use App\Repository\CiItemRepository;
use App\Repository\ProblemRepository;
use App\Repository\TenantRepository;
use App\Repository\TicketRepository;
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

#[Route('/operations')]
final class OperationsController extends AbstractController
{
    #[Route('/problems', name: 'app_operations_problem_index', methods: ['GET'])]
    public function problemIndex(
        Request $request,
        ProblemRepository $problemRepository,
        TicketRepository $ticketRepository,
        TenantRepository $tenantRepository,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        $allProblems = $problemRepository->findBy([], ['createdAt' => 'DESC']);

        // Calcular estadísticas / KPIs
        $totalProblems = count($allProblems);
        $underInvestigation = 0;
        $identified = 0;
        $resolved = 0;

        foreach ($allProblems as $p) {
            match ($p->getStatus()) {
                ProblemStatus::BAJO_INVESTIGACION => $underInvestigation++,
                ProblemStatus::IDENTIFICADO => $identified++,
                ProblemStatus::RESUELTO => $resolved++,
            };
        }

        // Filtro por estado y búsqueda
        $filterStatus = trim((string) $request->query->get('status', ''));
        $searchQuery = trim((string) $request->query->get('q', ''));

        $filteredProblems = $allProblems;
        if ($filterStatus !== '') {
            $statusEnum = ProblemStatus::tryFrom($filterStatus);
            if ($statusEnum) {
                $filteredProblems = array_filter(
                    $filteredProblems,
                    static fn (Problem $p) => $p->getStatus() === $statusEnum
                );
            }
        }

        if ($searchQuery !== '') {
            $qLower = mb_strtolower($searchQuery);
            $filteredProblems = array_filter(
                $filteredProblems,
                static fn (Problem $p) => str_contains(mb_strtolower((string) $p->getTitle()), $qLower)
                    || str_contains(mb_strtolower((string) $p->getDescription()), $qLower)
                    || str_contains(mb_strtolower((string) $p->getRootCause()), $qLower)
                    || str_contains(mb_strtolower((string) $p->getWorkaround()), $qLower)
            );
        }

        // Tickets disponibles para vincular ordenados por código
        $availableTickets = $ticketRepository->findBy([], ['ticketCode' => 'ASC']);

        return $this->render('operations/problem/index.html.twig', [
            'problems' => $filteredProblems,
            'stats' => [
                'total' => $totalProblems,
                'under_investigation' => $underInvestigation,
                'identified' => $identified,
                'resolved' => $resolved,
            ],
            'statuses' => ProblemStatus::cases(),
            'filterStatus' => $filterStatus,
            'searchQuery' => $searchQuery,
            'availableTickets' => $availableTickets,
        ]);
    }

    #[Route('/problems/create', name: 'app_operations_problem_create', methods: ['POST'])]
    public function problemCreate(
        Request $request,
        TenantRepository $tenantRepository,
        TicketRepository $ticketRepository,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfManager,
    ): Response {
        /** @var User|null $currentUser */
        $currentUser = $this->getUser();
        if (!$currentUser) {
            return $this->redirectToRoute('app_login');
        }

        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('create_problem', $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.');
        }

        $title = trim((string) $request->request->get('title'));
        $description = trim((string) $request->request->get('description'));
        $statusStr = (string) $request->request->get('status', ProblemStatus::BAJO_INVESTIGACION->value);
        $rootCause = trim((string) $request->request->get('root_cause'));
        $workaround = trim((string) $request->request->get('workaround'));
        $ticketIds = (array) $request->request->all('ticket_ids');

        if (mb_strlen($title) < 3) {
            return $this->handleResponse($request, false, 'El título del problema debe tener al menos 3 caracteres.');
        }

        $tenant = $currentUser->getTenant() ?? $tenantRepository->findOneBy([]);
        if (!$tenant) {
            $tenant = new Tenant();
            $tenant->setName('Tenant Principal');
            $tenant->setDomain('default');
            $tenant->setStatus(true);
            $em->persist($tenant);
        }

        $status = ProblemStatus::tryFrom($statusStr) ?? ProblemStatus::BAJO_INVESTIGACION;

        $problem = new Problem();
        $problem->setTitle($title);
        $problem->setTenant($tenant);
        $problem->setStatus($status);
        $problem->setDescription($description !== '' ? $description : null);
        $problem->setRootCause($rootCause !== '' ? $rootCause : null);
        $problem->setWorkaround($workaround !== '' ? $workaround : null);

        if ($status === ProblemStatus::RESUELTO) {
            $problem->setResolvedAt(new \DateTimeImmutable());
        }

        // Vincular tickets seleccionados
        foreach ($ticketIds as $tId) {
            try {
                $uuid = Uuid::fromString((string) $tId);
                $ticket = $ticketRepository->find($uuid);
                if ($ticket) {
                    $problem->addTicket($ticket);
                }
            } catch (\InvalidArgumentException) {
                // ID de ticket no válido, ignorar
            }
        }

        $em->persist($problem);

        // Registro de Auditoría
        $audit = new AuditLog();
        $audit->setTenant($tenant);
        $audit->setUser($currentUser);
        $audit->setAction('CREATE_OPERATION_PROBLEM');
        $audit->setEntityType('Problem');
        $audit->setEntityId($problem->getId());
        $audit->setNewValues([
            'title' => $problem->getTitle(),
            'status' => $problem->getStatus()->value,
            'linked_tickets_count' => count($problem->getTickets()),
        ]);
        $em->persist($audit);

        $em->flush();

        return $this->handleResponse($request, true, sprintf('Problema "%s" registrado con éxito.', $problem->getTitle()));
    }

    #[Route('/problems/{id}/edit', name: 'app_operations_problem_edit', methods: ['POST'])]
    public function problemEdit(
        string $id,
        Request $request,
        ProblemRepository $problemRepository,
        TicketRepository $ticketRepository,
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
            $problem = $problemRepository->find($uuid);
        } catch (\InvalidArgumentException) {
            return $this->handleResponse($request, false, 'Identificador de problema inválido.');
        }

        if (!$problem) {
            return $this->handleResponse($request, false, 'El problema solicitado no existe.');
        }

        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('edit_problem_' . $id, $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.');
        }

        $title = trim((string) $request->request->get('title'));
        $description = trim((string) $request->request->get('description'));
        $statusStr = (string) $request->request->get('status', $problem->getStatus()->value);
        $rootCause = trim((string) $request->request->get('root_cause'));
        $workaround = trim((string) $request->request->get('workaround'));
        $ticketIds = (array) $request->request->all('ticket_ids');

        if (mb_strlen($title) < 3) {
            return $this->handleResponse($request, false, 'El título del problema debe tener al menos 3 caracteres.');
        }

        $newStatus = ProblemStatus::tryFrom($statusStr) ?? $problem->getStatus();
        $oldStatus = $problem->getStatus();

        $problem->setTitle($title);
        $problem->setDescription($description !== '' ? $description : null);
        $problem->setStatus($newStatus);
        $problem->setRootCause($rootCause !== '' ? $rootCause : null);
        $problem->setWorkaround($workaround !== '' ? $workaround : null);

        // Control de fecha de resolución
        if ($newStatus === ProblemStatus::RESUELTO && $problem->getResolvedAt() === null) {
            $problem->setResolvedAt(new \DateTimeImmutable());
        } elseif ($newStatus !== ProblemStatus::RESUELTO) {
            $problem->setResolvedAt(null);
        }

        // Actualizar tickets vinculados
        // 1. Quitar tickets no seleccionados
        $selectedUuids = [];
        foreach ($ticketIds as $tId) {
            try {
                $selectedUuids[] = (string) Uuid::fromString((string) $tId);
            } catch (\InvalidArgumentException) {}
        }

        foreach ($problem->getTickets() as $existingTicket) {
            if (!in_array((string) $existingTicket->getId(), $selectedUuids, true)) {
                $problem->removeTicket($existingTicket);
            }
        }

        // 2. Agregar tickets nuevos seleccionados
        foreach ($selectedUuids as $uStr) {
            $ticket = $ticketRepository->find(Uuid::fromString($uStr));
            if ($ticket && !$problem->getTickets()->contains($ticket)) {
                $problem->addTicket($ticket);
            }
        }

        // Registro de auditoría
        if ($problem->getTenant()) {
            $audit = new AuditLog();
            $audit->setTenant($problem->getTenant());
            $audit->setUser($currentUser);
            $audit->setAction('EDIT_OPERATION_PROBLEM');
            $audit->setEntityType('Problem');
            $audit->setEntityId($problem->getId());
            $audit->setOldValues(['status' => $oldStatus->value]);
            $audit->setNewValues([
                'title' => $problem->getTitle(),
                'status' => $problem->getStatus()->value,
                'linked_tickets_count' => count($problem->getTickets()),
            ]);
            $em->persist($audit);
        }

        $em->flush();

        return $this->handleResponse($request, true, sprintf('Problema "%s" actualizado con éxito.', $problem->getTitle()));
    }

    #[Route('/problems/{id}/delete', name: 'app_operations_problem_delete', methods: ['POST'])]
    public function problemDelete(
        string $id,
        Request $request,
        ProblemRepository $problemRepository,
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
            $problem = $problemRepository->find($uuid);
        } catch (\InvalidArgumentException) {
            return $this->handleResponse($request, false, 'Identificador de problema inválido.');
        }

        if (!$problem) {
            return $this->handleResponse($request, false, 'El problema no existe o ya fue eliminado.');
        }

        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('delete_problem_' . $id, $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.');
        }

        $title = $problem->getTitle();

        if ($problem->getTenant()) {
            $audit = new AuditLog();
            $audit->setTenant($problem->getTenant());
            $audit->setUser($currentUser);
            $audit->setAction('DELETE_OPERATION_PROBLEM');
            $audit->setEntityType('Problem');
            $audit->setEntityId($problem->getId());
            $audit->setOldValues(['title' => $title]);
            $em->persist($audit);
        }

        $em->remove($problem);
        $em->flush();

        return $this->handleResponse($request, true, sprintf('Problema "%s" eliminado con éxito.', $title));
    }

    #[Route('/problems/{id}/detail', name: 'app_operations_problem_detail', methods: ['GET'])]
    public function problemDetail(
        string $id,
        ProblemRepository $problemRepository,
    ): JsonResponse {
        try {
            $uuid = Uuid::fromString($id);
            $problem = $problemRepository->find($uuid);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['success' => false, 'message' => 'ID inválido.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$problem) {
            return new JsonResponse(['success' => false, 'message' => 'Problema no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $ticketsData = [];
        foreach ($problem->getTickets() as $t) {
            $ticketsData[] = [
                'id' => (string) $t->getId(),
                'code' => $t->getTicketCode() ?? 'TICK-' . substr((string) $t->getId(), 0, 6),
                'title' => $t->getTitle(),
                'status' => $t->getStatus()?->getName() ?? 'Sin estado',
                'priority' => $t->getPriority()?->getName() ?? 'Normal',
                'priorityColor' => $t->getPriority()?->getColor() ?? '#64748b',
                'requester' => $t->getRequester()?->getName() ?? 'Anónimo',
                'createdAt' => $t->getCreatedAt()->format('d/m/Y H:i'),
            ];
        }

        return new JsonResponse([
            'success' => true,
            'problem' => [
                'id' => (string) $problem->getId(),
                'title' => $problem->getTitle(),
                'description' => $problem->getDescription(),
                'status' => $problem->getStatus()->value,
                'rootCause' => $problem->getRootCause(),
                'workaround' => $problem->getWorkaround(),
                'createdAt' => $problem->getCreatedAt()->format('d/m/Y H:i'),
                'resolvedAt' => $problem->getResolvedAt()?->format('d/m/Y H:i'),
                'tickets' => $ticketsData,
                'ticketsCount' => count($ticketsData),
            ],
        ]);
    }

    // =========================================================================
    // SUB-MÓDULO: GESTIÓN DE CAMBIOS (CAB)
    // =========================================================================

    #[Route('/changes', name: 'app_operations_change_index', methods: ['GET'])]
    public function changeIndex(
        Request $request,
        ChangeRepository $changeRepository,
        CiItemRepository $ciItemRepository,
        UserRepository $userRepository,
    ): Response {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        $allChanges = $changeRepository->findBy([], ['scheduledStart' => 'DESC', 'id' => 'DESC']);

        // Calcular estadísticas / KPIs
        $totalChanges = count($allChanges);
        $pendingChanges = 0;
        $approvedChanges = 0;
        $implementedChanges = 0;
        $rejectedChanges = 0;

        foreach ($allChanges as $c) {
            match ($c->getStatus()) {
                ChangeStatus::SOLICITADO, ChangeStatus::EN_REVISION => $pendingChanges++,
                ChangeStatus::APROBADO => $approvedChanges++,
                ChangeStatus::IMPLEMENTADO => $implementedChanges++,
                ChangeStatus::RECHAZADO => $rejectedChanges++,
            };
        }

        // Filtro por estado, nivel de riesgo y búsqueda
        $filterStatus = trim((string) $request->query->get('status', ''));
        $filterRisk = trim((string) $request->query->get('risk', ''));
        $searchQuery = trim((string) $request->query->get('q', ''));

        $filteredChanges = $allChanges;
        if ($filterStatus !== '') {
            $statusEnum = ChangeStatus::tryFrom($filterStatus);
            if ($statusEnum) {
                $filteredChanges = array_filter(
                    $filteredChanges,
                    static fn (Change $c) => $c->getStatus() === $statusEnum
                );
            }
        }

        if ($filterRisk !== '') {
            $riskEnum = ChangeRiskLevel::tryFrom($filterRisk);
            if ($riskEnum) {
                $filteredChanges = array_filter(
                    $filteredChanges,
                    static fn (Change $c) => $c->getRiskLevel() === $riskEnum
                );
            }
        }

        if ($searchQuery !== '') {
            $qLower = mb_strtolower($searchQuery);
            $filteredChanges = array_filter(
                $filteredChanges,
                static fn (Change $c) => str_contains(mb_strtolower((string) $c->getTitle()), $qLower)
                    || str_contains(mb_strtolower((string) $c->getDescription()), $qLower)
                    || str_contains(mb_strtolower((string) $c->getRequester()?->getName()), $qLower)
                    || str_contains(mb_strtolower((string) $c->getApprover()?->getName()), $qLower)
            );
        }

        // Activos de infraestructura disponibles ordenados por nombre
        $availableCis = $ciItemRepository->findBy([], ['name' => 'ASC']);
        $cabUsers = $userRepository->findBy([], ['name' => 'ASC']);

        return $this->render('operations/change/index.html.twig', [
            'changes' => $filteredChanges,
            'stats' => [
                'total' => $totalChanges,
                'pending' => $pendingChanges,
                'approved' => $approvedChanges,
                'implemented' => $implementedChanges,
                'rejected' => $rejectedChanges,
            ],
            'statuses' => ChangeStatus::cases(),
            'riskLevels' => ChangeRiskLevel::cases(),
            'filterStatus' => $filterStatus,
            'filterRisk' => $filterRisk,
            'searchQuery' => $searchQuery,
            'availableCis' => $availableCis,
            'cabUsers' => $cabUsers,
        ]);
    }

    #[Route('/changes/create', name: 'app_operations_change_create', methods: ['POST'])]
    public function changeCreate(
        Request $request,
        TenantRepository $tenantRepository,
        CiItemRepository $ciItemRepository,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfManager,
    ): Response {
        /** @var User|null $currentUser */
        $currentUser = $this->getUser();
        if (!$currentUser) {
            return $this->redirectToRoute('app_login');
        }

        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('create_change', $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.', redirectRoute: 'app_operations_change_index');
        }

        $title = trim((string) $request->request->get('title'));
        $description = trim((string) $request->request->get('description'));
        $riskStr = (string) $request->request->get('risk_level', ChangeRiskLevel::MEDIO->value);
        $statusStr = (string) $request->request->get('status', ChangeStatus::SOLICITADO->value);
        $startStr = trim((string) $request->request->get('scheduled_start'));
        $endStr = trim((string) $request->request->get('scheduled_end'));
        $ciIds = (array) $request->request->all('ci_ids');

        if (mb_strlen($title) < 3) {
            return $this->handleResponse($request, false, 'El título del cambio debe tener al menos 3 caracteres.', redirectRoute: 'app_operations_change_index');
        }

        $scheduledStart = null;
        if ($startStr !== '') {
            try {
                $scheduledStart = new \DateTimeImmutable($startStr);
            } catch (\Exception) {
                return $this->handleResponse($request, false, 'Formato de fecha de inicio no válido.', redirectRoute: 'app_operations_change_index');
            }
        }

        $scheduledEnd = null;
        if ($endStr !== '') {
            try {
                $scheduledEnd = new \DateTimeImmutable($endStr);
            } catch (\Exception) {
                return $this->handleResponse($request, false, 'Formato de fecha de fin no válido.', redirectRoute: 'app_operations_change_index');
            }
        }

        if ($scheduledStart && $scheduledEnd && $scheduledEnd < $scheduledStart) {
            return $this->handleResponse($request, false, 'La fecha de fin de la ventana no puede ser anterior a la fecha de inicio.', redirectRoute: 'app_operations_change_index');
        }

        $tenant = $currentUser->getTenant() ?? $tenantRepository->findOneBy([]);
        if (!$tenant) {
            $tenant = new Tenant();
            $tenant->setName('Tenant Principal');
            $tenant->setDomain('default');
            $tenant->setStatus(true);
            $em->persist($tenant);
        }

        $riskLevel = ChangeRiskLevel::tryFrom($riskStr) ?? ChangeRiskLevel::MEDIO;
        $status = ChangeStatus::tryFrom($statusStr) ?? ChangeStatus::SOLICITADO;

        $change = new Change();
        $change->setTitle($title);
        $change->setDescription($description !== '' ? $description : null);
        $change->setTenant($tenant);
        $change->setRequester($currentUser);
        $change->setRiskLevel($riskLevel);
        $change->setStatus($status);
        $change->setScheduledStart($scheduledStart);
        $change->setScheduledEnd($scheduledEnd);

        if ($status === ChangeStatus::APROBADO) {
            $change->setApprover($currentUser);
        }

        foreach ($ciIds as $ciId) {
            try {
                $ciUuid = Uuid::fromString((string) $ciId);
                $ci = $ciItemRepository->find($ciUuid);
                if ($ci) {
                    $change->addCiItem($ci);
                }
            } catch (\InvalidArgumentException) {
            }
        }

        $em->persist($change);
        $em->flush();

        if ($change->getTenant()) {
            $audit = new AuditLog();
            $audit->setTenant($change->getTenant());
            $audit->setUser($currentUser);
            $audit->setAction('CREATE_OPERATION_CHANGE');
            $audit->setEntityType('Change');
            $audit->setEntityId($change->getId());
            $audit->setNewValues([
                'title' => $change->getTitle(),
                'riskLevel' => $change->getRiskLevel()->value,
                'status' => $change->getStatus()->value,
                'ciCount' => $change->getCiItems()->count(),
                'scheduledStart' => $scheduledStart?->format('Y-m-d H:i'),
                'scheduledEnd' => $scheduledEnd?->format('Y-m-d H:i'),
            ]);
            $em->persist($audit);
            $em->flush();
        }

        return $this->handleResponse($request, true, sprintf('Cambio "%s" registrado con éxito.', $title), redirectRoute: 'app_operations_change_index');
    }

    #[Route('/changes/{id}/edit', name: 'app_operations_change_edit', methods: ['POST'])]
    public function changeEdit(
        string $id,
        Request $request,
        ChangeRepository $changeRepository,
        CiItemRepository $ciItemRepository,
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
            $change = $changeRepository->find($uuid);
        } catch (\InvalidArgumentException) {
            return $this->handleResponse($request, false, 'Identificador de cambio inválido.', redirectRoute: 'app_operations_change_index');
        }

        if (!$change) {
            return $this->handleResponse($request, false, 'El cambio no existe o fue eliminado.', redirectRoute: 'app_operations_change_index');
        }

        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('edit_change_' . $id, $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.', redirectRoute: 'app_operations_change_index');
        }

        $title = trim((string) $request->request->get('title'));
        $description = trim((string) $request->request->get('description'));
        $riskStr = (string) $request->request->get('risk_level', $change->getRiskLevel()->value);
        $statusStr = (string) $request->request->get('status', $change->getStatus()->value);
        $startStr = trim((string) $request->request->get('scheduled_start'));
        $endStr = trim((string) $request->request->get('scheduled_end'));
        $ciIds = (array) $request->request->all('ci_ids');

        if (mb_strlen($title) < 3) {
            return $this->handleResponse($request, false, 'El título del cambio debe tener al menos 3 caracteres.', redirectRoute: 'app_operations_change_index');
        }

        $scheduledStart = null;
        if ($startStr !== '') {
            try {
                $scheduledStart = new \DateTimeImmutable($startStr);
            } catch (\Exception) {
                return $this->handleResponse($request, false, 'Formato de fecha de inicio no válido.', redirectRoute: 'app_operations_change_index');
            }
        }

        $scheduledEnd = null;
        if ($endStr !== '') {
            try {
                $scheduledEnd = new \DateTimeImmutable($endStr);
            } catch (\Exception) {
                return $this->handleResponse($request, false, 'Formato de fecha de fin no válido.', redirectRoute: 'app_operations_change_index');
            }
        }

        if ($scheduledStart && $scheduledEnd && $scheduledEnd < $scheduledStart) {
            return $this->handleResponse($request, false, 'La fecha de fin no puede ser anterior a la fecha de inicio.', redirectRoute: 'app_operations_change_index');
        }

        $oldValues = [
            'title' => $change->getTitle(),
            'riskLevel' => $change->getRiskLevel()->value,
            'status' => $change->getStatus()->value,
        ];

        $riskLevel = ChangeRiskLevel::tryFrom($riskStr) ?? $change->getRiskLevel();
        $status = ChangeStatus::tryFrom($statusStr) ?? $change->getStatus();

        $change->setTitle($title);
        $change->setDescription($description !== '' ? $description : null);
        $change->setRiskLevel($riskLevel);
        $change->setStatus($status);
        $change->setScheduledStart($scheduledStart);
        $change->setScheduledEnd($scheduledEnd);

        if ($status === ChangeStatus::APROBADO && !$change->getApprover()) {
            $change->setApprover($currentUser);
        }

        // Actualizar CIs asociados
        $currentCis = clone $change->getCiItems();
        foreach ($currentCis as $existingCi) {
            $change->removeCiItem($existingCi);
        }

        foreach ($ciIds as $ciId) {
            try {
                $ciUuid = Uuid::fromString((string) $ciId);
                $ci = $ciItemRepository->find($ciUuid);
                if ($ci) {
                    $change->addCiItem($ci);
                }
            } catch (\InvalidArgumentException) {
            }
        }

        $em->flush();

        if ($change->getTenant()) {
            $audit = new AuditLog();
            $audit->setTenant($change->getTenant());
            $audit->setUser($currentUser);
            $audit->setAction('UPDATE_OPERATION_CHANGE');
            $audit->setEntityType('Change');
            $audit->setEntityId($change->getId());
            $audit->setOldValues($oldValues);
            $audit->setNewValues([
                'title' => $change->getTitle(),
                'riskLevel' => $change->getRiskLevel()->value,
                'status' => $change->getStatus()->value,
            ]);
            $em->persist($audit);
            $em->flush();
        }

        return $this->handleResponse($request, true, sprintf('Cambio "%s" actualizado con éxito.', $title), redirectRoute: 'app_operations_change_index');
    }

    #[Route('/changes/{id}/evaluate', name: 'app_operations_change_evaluate', methods: ['POST'])]
    public function changeEvaluate(
        string $id,
        Request $request,
        ChangeRepository $changeRepository,
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
            $change = $changeRepository->find($uuid);
        } catch (\InvalidArgumentException) {
            return $this->handleResponse($request, false, 'Identificador de cambio inválido.', redirectRoute: 'app_operations_change_index');
        }

        if (!$change) {
            return $this->handleResponse($request, false, 'El cambio no existe o fue eliminado.', redirectRoute: 'app_operations_change_index');
        }

        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('evaluate_change_' . $id, $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.', redirectRoute: 'app_operations_change_index');
        }

        $decision = trim((string) $request->request->get('decision'));
        $oldStatus = $change->getStatus()->value;

        switch ($decision) {
            case 'aprobar':
                $change->setStatus(ChangeStatus::APROBADO);
                $change->setApprover($currentUser);
                $msg = sprintf('Cambio "%s" aprobado formalmente por el CAB.', $change->getTitle());
                break;
            case 'rechazar':
                $change->setStatus(ChangeStatus::RECHAZADO);
                $change->setApprover($currentUser);
                $msg = sprintf('Cambio "%s" rechazado por el CAB.', $change->getTitle());
                break;
            case 'implementar':
                $change->setStatus(ChangeStatus::IMPLEMENTADO);
                $msg = sprintf('Cambio "%s" marcado como Implementado con éxito.', $change->getTitle());
                break;
            case 'revision':
                $change->setStatus(ChangeStatus::EN_REVISION);
                $msg = sprintf('Cambio "%s" retornado a revisión por el CAB.', $change->getTitle());
                break;
            default:
                return $this->handleResponse($request, false, 'Decisión CAB no reconocida.', redirectRoute: 'app_operations_change_index');
        }

        $em->flush();

        if ($change->getTenant()) {
            $audit = new AuditLog();
            $audit->setTenant($change->getTenant());
            $audit->setUser($currentUser);
            $audit->setAction('EVALUATE_OPERATION_CHANGE');
            $audit->setEntityType('Change');
            $audit->setEntityId($change->getId());
            $audit->setOldValues(['status' => $oldStatus]);
            $audit->setNewValues([
                'status' => $change->getStatus()->value,
                'approver' => $change->getApprover()?->getName(),
            ]);
            $em->persist($audit);
            $em->flush();
        }

        return $this->handleResponse($request, true, $msg, redirectRoute: 'app_operations_change_index');
    }

    #[Route('/changes/{id}/delete', name: 'app_operations_change_delete', methods: ['POST'])]
    public function changeDelete(
        string $id,
        Request $request,
        ChangeRepository $changeRepository,
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
            $change = $changeRepository->find($uuid);
        } catch (\InvalidArgumentException) {
            return $this->handleResponse($request, false, 'Identificador de cambio inválido.', redirectRoute: 'app_operations_change_index');
        }

        if (!$change) {
            return $this->handleResponse($request, false, 'El cambio no existe o ya fue eliminado.', redirectRoute: 'app_operations_change_index');
        }

        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('delete_change_' . $id, $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.', redirectRoute: 'app_operations_change_index');
        }

        $title = $change->getTitle();

        if ($change->getTenant()) {
            $audit = new AuditLog();
            $audit->setTenant($change->getTenant());
            $audit->setUser($currentUser);
            $audit->setAction('DELETE_OPERATION_CHANGE');
            $audit->setEntityType('Change');
            $audit->setEntityId($change->getId());
            $audit->setOldValues(['title' => $title]);
            $em->persist($audit);
        }

        $em->remove($change);
        $em->flush();

        return $this->handleResponse($request, true, sprintf('Cambio "%s" eliminado con éxito.', $title), redirectRoute: 'app_operations_change_index');
    }

    #[Route('/changes/{id}/detail', name: 'app_operations_change_detail', methods: ['GET'])]
    public function changeDetail(
        string $id,
        ChangeRepository $changeRepository,
    ): JsonResponse {
        try {
            $uuid = Uuid::fromString($id);
            $change = $changeRepository->find($uuid);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['success' => false, 'message' => 'ID inválido.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$change) {
            return new JsonResponse(['success' => false, 'message' => 'Cambio no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $ciData = [];
        foreach ($change->getCiItems() as $ci) {
            $ciData[] = [
                'id' => (string) $ci->getId(),
                'name' => $ci->getName(),
                'category' => $ci->getCategory()?->getName() ?? 'Sin categoría',
                'ip' => $ci->getIpAddress(),
                'status' => $ci->getStatus()->value,
            ];
        }

        return new JsonResponse([
            'success' => true,
            'change' => [
                'id' => (string) $change->getId(),
                'title' => $change->getTitle(),
                'description' => $change->getDescription(),
                'riskLevel' => $change->getRiskLevel()->value,
                'status' => $change->getStatus()->value,
                'requester' => [
                    'id' => (string) $change->getRequester()?->getId(),
                    'name' => $change->getRequester()?->getName() ?? 'N/A',
                    'username' => $change->getRequester()?->getUsername() ?? 'N/A',
                ],
                'approver' => $change->getApprover() ? [
                    'id' => (string) $change->getApprover()->getId(),
                    'name' => $change->getApprover()->getName(),
                    'username' => $change->getApprover()->getUsername(),
                ] : null,
                'scheduledStart' => $change->getScheduledStart()?->format('d/m/Y H:i'),
                'scheduledStartIso' => $change->getScheduledStart()?->format('Y-m-d\TH:i'),
                'scheduledEnd' => $change->getScheduledEnd()?->format('d/m/Y H:i'),
                'scheduledEndIso' => $change->getScheduledEnd()?->format('Y-m-d\TH:i'),
                'ciItems' => $ciData,
                'ciCount' => count($ciData),
            ],
        ]);
    }

    private function handleResponse(Request $request, bool $success, string $message, int $status = Response::HTTP_OK, string $redirectRoute = 'app_operations_problem_index'): Response
    {
        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse(['success' => $success, 'message' => $message], $success ? $status : Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash($success ? 'success' : 'error', $message);

        return $this->redirectToRoute($redirectRoute);
    }
}
