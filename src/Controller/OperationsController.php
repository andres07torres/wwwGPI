<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\Problem;
use App\Entity\Tenant;
use App\Entity\Ticket;
use App\Entity\User;
use App\Enum\ProblemStatus;
use App\Repository\ProblemRepository;
use App\Repository\TenantRepository;
use App\Repository\TicketRepository;
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

    private function handleResponse(Request $request, bool $success, string $message, int $status = Response::HTTP_OK): Response
    {
        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse(['success' => $success, 'message' => $message], $success ? $status : Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash($success ? 'success' : 'error', $message);

        return $this->redirectToRoute('app_operations_problem_index');
    }
}
