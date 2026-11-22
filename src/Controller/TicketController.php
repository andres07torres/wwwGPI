<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Tenant;
use App\Entity\Ticket;
use App\Entity\TicketComment;
use App\Entity\TicketPriority;
use App\Entity\TicketStatus;
use App\Entity\User;
use App\Enum\TicketType;
use App\Repository\TenantRepository;
use App\Repository\TicketCommentRepository;
use App\Repository\TicketPriorityRepository;
use App\Repository\TicketRepository;
use App\Repository\TicketStatusRepository;
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

#[Route('/tickets')]
final class TicketController extends AbstractController
{
    #[Route('', name: 'app_ticket_index', methods: ['GET'])]
    public function index(
        TicketRepository $ticketRepository,
        TicketStatusRepository $statusRepository,
        TicketPriorityRepository $priorityRepository,
        UserRepository $userRepository,
    ): Response {
        $tickets = $ticketRepository->findBy([], ['createdAt' => 'DESC']);
        $statuses = $statusRepository->findBy([], ['orderIndex' => 'ASC']);
        $priorities = $priorityRepository->findBy([], ['level' => 'ASC']);
        $users = $userRepository->findBy(['status' => true], ['name' => 'ASC']);

        return $this->render('ticket/index.html.twig', [
            'tickets' => $tickets,
            'statuses' => $statuses,
            'priorities' => $priorities,
            'users' => $users,
            'ticketTypes' => TicketType::cases(),
        ]);
    }

    #[Route('/kanban', name: 'app_ticket_kanban', methods: ['GET'])]
    public function kanban(
        TicketRepository $ticketRepository,
        TicketStatusRepository $statusRepository,
        TicketPriorityRepository $priorityRepository,
        UserRepository $userRepository,
    ): Response {
        $statuses = $statusRepository->findBy([], ['orderIndex' => 'ASC']);
        $priorities = $priorityRepository->findBy([], ['level' => 'ASC']);
        $users = $userRepository->findBy(['status' => true], ['name' => 'ASC']);
        $tickets = $ticketRepository->findBy([], ['createdAt' => 'DESC']);

        // Agrupar tickets por ID de estado para el tablero Kanban
        $ticketsByStatus = [];
        foreach ($statuses as $st) {
            $ticketsByStatus[$st->getId()] = [];
        }
        foreach ($tickets as $ticket) {
            $stId = $ticket->getStatus()->getId();
            if (isset($ticketsByStatus[$stId])) {
                $ticketsByStatus[$stId][] = $ticket;
            }
        }

        return $this->render('ticket/kanban.html.twig', [
            'statuses' => $statuses,
            'priorities' => $priorities,
            'users' => $users,
            'ticketsByStatus' => $ticketsByStatus,
            'totalTickets' => count($tickets),
            'ticketTypes' => TicketType::cases(),
        ]);
    }

    #[Route('/create', name: 'app_ticket_create', methods: ['POST'])]
    public function create(
        Request $request,
        TicketRepository $ticketRepository,
        TicketStatusRepository $statusRepository,
        TicketPriorityRepository $priorityRepository,
        UserRepository $userRepository,
        TenantRepository $tenantRepository,
        CsrfTokenManagerInterface $csrfTokenManager,
        EntityManagerInterface $em,
    ): Response {
        $referer = (string) $request->headers->get('referer');
        $redirectRoute = str_contains($referer, '/kanban') ? 'app_ticket_kanban' : 'app_ticket_index';

        $token = new CsrfToken('ticket_create', (string) $request->request->get('_csrf_token'));
        if (!$csrfTokenManager->isTokenValid($token)) {
            if ($this->isJsonRequest($request)) {
                return new JsonResponse(['success' => false, 'message' => 'Token de seguridad inválido o expirado.'], Response::HTTP_FORBIDDEN);
            }
            $this->addFlash('error', 'Token de seguridad inválido o expirado.');
            return $this->redirectToRoute($redirectRoute);
        }

        $title = trim((string) $request->request->get('title'));
        $description = trim((string) $request->request->get('description'));
        $typeVal = (string) $request->request->get('type', TicketType::INCIDENTE->value);
        $priorityId = (int) $request->request->get('priority_id');
        $statusId = (int) $request->request->get('status_id');
        $requesterId = (string) $request->request->get('requester_id');
        $assigneeId = (string) $request->request->get('assignee_id');

        if ($title === '' || $description === '') {
            $msg = 'El título y la descripción son obligatorios.';
            if ($this->isJsonRequest($request)) {
                return new JsonResponse(['success' => false, 'message' => $msg], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', $msg);
            return $this->redirectToRoute($redirectRoute);
        }

        $type = TicketType::tryFrom($typeVal) ?? TicketType::INCIDENTE;
        $priority = $priorityRepository->find($priorityId);
        $status = $statusId > 0 ? $statusRepository->find($statusId) : $statusRepository->findOneBy([], ['orderIndex' => 'ASC']);

        if (!$priority || !$status) {
            $msg = 'Prioridad o estado seleccionado no válido.';
            if ($this->isJsonRequest($request)) {
                return new JsonResponse(['success' => false, 'message' => $msg], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', $msg);
            return $this->redirectToRoute($redirectRoute);
        }

        // Solicitante: usuario seleccionado o el usuario actual autenticado
        $requester = null;
        if ($requesterId !== '') {
            $requester = $userRepository->find(Uuid::fromString($requesterId));
        }
        if (!$requester) {
            /** @var User|null $currentUser */
            $currentUser = $this->getUser();
            $requester = $currentUser ?? $userRepository->findOneBy([]);
        }

        if (!$requester) {
            $msg = 'No se encontró un solicitante válido.';
            if ($this->isJsonRequest($request)) {
                return new JsonResponse(['success' => false, 'message' => $msg], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', $msg);
            return $this->redirectToRoute($redirectRoute);
        }

        $assignee = null;
        if ($assigneeId !== '') {
            $assignee = $userRepository->find(Uuid::fromString($assigneeId));
        }

        $tenant = $requester->getTenant() ?? $tenantRepository->findOneBy([]);
        if (!$tenant) {
            $msg = 'No se encontró una organización (tenant) válida.';
            if ($this->isJsonRequest($request)) {
                return new JsonResponse(['success' => false, 'message' => $msg], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', $msg);
            return $this->redirectToRoute($redirectRoute);
        }

        // Código secuencial del ticket
        $count = $ticketRepository->count([]) + 1;
        $ticketCode = sprintf('TCK-%s-%04d', date('Y'), $count);

        $ticket = new Ticket();
        $ticket->setTenant($tenant);
        $ticket->setTicketCode($ticketCode);
        $ticket->setType($type);
        $ticket->setTitle($title);
        $ticket->setDescription($description);
        $ticket->setStatus($status);
        $ticket->setPriority($priority);
        $ticket->setRequester($requester);
        if ($assignee) {
            $ticket->setAssignee($assignee);
        }

        $em->persist($ticket);
        $em->flush();

        $successMsg = sprintf('Ticket %s creado exitosamente.', $ticketCode);

        if ($this->isJsonRequest($request)) {
            return new JsonResponse([
                'success' => true,
                'message' => $successMsg,
                'ticket' => [
                    'id' => $ticket->getId()->toRfc4122(),
                    'code' => $ticketCode,
                    'title' => $ticket->getTitle(),
                ],
            ]);
        }

        $this->addFlash('success', $successMsg);
        return $this->redirectToRoute($redirectRoute);
    }

    private function isJsonRequest(Request $request): bool
    {
        return $request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json');
    }

    #[Route('/{id}/status', name: 'app_ticket_update_status', methods: ['POST'])]
    public function updateStatus(
        string $id,
        Request $request,
        TicketRepository $ticketRepository,
        TicketStatusRepository $statusRepository,
        CsrfTokenManagerInterface $csrfTokenManager,
        EntityManagerInterface $em,
    ): JsonResponse {
        $token = new CsrfToken('ticket_status', (string) $request->request->get('_csrf_token'));
        if (!$csrfTokenManager->isTokenValid($token)) {
            return new JsonResponse(['success' => false, 'message' => 'Token de seguridad inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $ticket = $ticketRepository->find(Uuid::fromString($id));
        } catch (\Throwable) {
            return new JsonResponse(['success' => false, 'message' => 'Identificador de ticket inválido.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$ticket) {
            return new JsonResponse(['success' => false, 'message' => 'Ticket no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $statusId = (int) $request->request->get('status_id');
        $status = $statusRepository->find($statusId);
        if (!$status) {
            return new JsonResponse(['success' => false, 'message' => 'Estado inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $ticket->setStatus($status);
        if ($status->isClosedState() && !$ticket->getResolvedAt()) {
            $ticket->setResolvedAt(new \DateTimeImmutable());
        } elseif (!$status->isClosedState()) {
            $ticket->setResolvedAt(null);
        }

        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => sprintf('Ticket %s actualizado a "%s".', $ticket->getTicketCode(), $status->getName()),
        ]);
    }

    #[Route('/{id}/comment', name: 'app_ticket_add_comment', methods: ['POST'])]
    public function addComment(
        string $id,
        Request $request,
        TicketRepository $ticketRepository,
        UserRepository $userRepository,
        CsrfTokenManagerInterface $csrfTokenManager,
        EntityManagerInterface $em,
    ): JsonResponse {
        $token = new CsrfToken('ticket_comment', (string) $request->request->get('_csrf_token'));
        if (!$csrfTokenManager->isTokenValid($token)) {
            return new JsonResponse(['success' => false, 'message' => 'Token de seguridad inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $ticket = $ticketRepository->find(Uuid::fromString($id));
        } catch (\Throwable) {
            return new JsonResponse(['success' => false, 'message' => 'Identificador de ticket inválido.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$ticket) {
            return new JsonResponse(['success' => false, 'message' => 'Ticket no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $content = trim((string) $request->request->get('content'));
        $isPrivate = (bool) $request->request->get('is_private', false);

        if ($content === '') {
            return new JsonResponse(['success' => false, 'message' => 'El mensaje no puede estar vacío.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var User|null $currentUser */
        $currentUser = $this->getUser();
        $user = $currentUser ?? $userRepository->findOneBy([]);

        if (!$user) {
            return new JsonResponse(['success' => false, 'message' => 'Usuario no identificado.'], Response::HTTP_BAD_REQUEST);
        }

        $comment = new TicketComment();
        $comment->setTicket($ticket);
        $comment->setUser($user);
        $comment->setContent($content);
        $comment->setIsPrivate($isPrivate);

        $em->persist($comment);
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'message' => 'Mensaje agregado con éxito.',
            'comment' => [
                'id' => $comment->getId()->toRfc4122(),
                'user' => $user->getName() ?: $user->getUserIdentifier(),
                'content' => nl2br(htmlspecialchars($comment->getContent())),
                'isPrivate' => $comment->isPrivate(),
                'createdAt' => $comment->getCreatedAt()->format('d/m/Y H:i'),
            ],
        ]);
    }

    #[Route('/{id}/details', name: 'app_ticket_details', methods: ['GET'])]
    public function details(string $id, TicketRepository $ticketRepository): JsonResponse
    {
        try {
            $ticket = $ticketRepository->find(Uuid::fromString($id));
        } catch (\Throwable) {
            return new JsonResponse(['success' => false, 'message' => 'Identificador inválido.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$ticket) {
            return new JsonResponse(['success' => false, 'message' => 'Ticket no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $comments = [];
        foreach ($ticket->getComments() as $c) {
            $comments[] = [
                'id' => $c->getId()->toRfc4122(),
                'author' => $c->getUser() ? ($c->getUser()->getName() ?: $c->getUser()->getUserIdentifier()) : 'Sistema',
                'content' => $c->getContent(),
                'isPrivate' => $c->isPrivate(),
                'createdAt' => $c->getCreatedAt()->format('d/m/Y H:i'),
            ];
        }

        return new JsonResponse([
            'success' => true,
            'ticket' => [
                'id' => $ticket->getId()->toRfc4122(),
                'code' => $ticket->getTicketCode(),
                'type' => $ticket->getType()->label(),
                'title' => $ticket->getTitle(),
                'description' => $ticket->getDescription(),
                'status' => $ticket->getStatus()->getName(),
                'statusId' => $ticket->getStatus()->getId(),
                'priority' => $ticket->getPriority()->getName(),
                'priorityColor' => $ticket->getPriority()->getColor(),
                'requester' => $ticket->getRequester() ? ($ticket->getRequester()->getName() ?: $ticket->getRequester()->getUserIdentifier()) : 'Desconocido',
                'assignee' => $ticket->getAssignee() ? ($ticket->getAssignee()->getName() ?: $ticket->getAssignee()->getUserIdentifier()) : 'Sin asignar',
                'createdAt' => $ticket->getCreatedAt()->format('d/m/Y H:i'),
                'comments' => $comments,
            ],
        ]);
    }
}
