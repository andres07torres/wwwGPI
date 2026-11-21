<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SlaPolicy;
use App\Entity\Tenant;
use App\Entity\Ticket;
use App\Entity\TicketPriority;
use App\Entity\TicketSla;
use App\Entity\TicketStatus;
use App\Repository\SlaPolicyRepository;
use App\Repository\TenantRepository;
use App\Repository\TicketPriorityRepository;
use App\Repository\TicketRepository;
use App\Repository\TicketSlaRepository;
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
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/sla', name: 'app_sla_')]
#[IsGranted('ROLE_USER')]
final class SlaController extends AbstractController
{
    #[Route('/policies', name: 'policy_index', methods: ['GET'])]
    public function policyIndex(
        SlaPolicyRepository $policyRepository,
        TicketPriorityRepository $priorityRepository,
        TenantRepository $tenantRepository,
        EntityManagerInterface $em,
    ): Response {
        $this->ensureDefaultPolicies($em, $policyRepository, $priorityRepository, $tenantRepository);

        $policies = $policyRepository->createQueryBuilder('p')
            ->leftJoin('p.priority', 'pr')
            ->addSelect('pr')
            ->orderBy('pr.level', 'DESC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        $priorities = $priorityRepository->findBy([], ['level' => 'DESC']);

        $totalPolicies = count($policies);
        $businessHoursCount = 0;
        $fullTimeCount = 0;

        foreach ($policies as $p) {
            if ($p->isBusinessHoursOnly()) {
                $businessHoursCount++;
            } else {
                $fullTimeCount++;
            }
        }

        return $this->render('sla/policies.html.twig', [
            'policies' => $policies,
            'priorities' => $priorities,
            'stats' => [
                'total' => $totalPolicies,
                'business_hours' => $businessHoursCount,
                'full_time' => $fullTimeCount,
                'priorities_covered' => count($priorities),
            ],
        ]);
    }

    #[Route('/compliance', name: 'compliance_index', methods: ['GET'])]
    public function complianceIndex(
        TicketRepository $ticketRepository,
        TicketSlaRepository $ticketSlaRepository,
        TicketStatusRepository $statusRepository,
        SlaPolicyRepository $policyRepository,
        TicketPriorityRepository $priorityRepository,
        TenantRepository $tenantRepository,
        EntityManagerInterface $em,
    ): Response {
        $this->ensureDefaultPolicies($em, $policyRepository, $priorityRepository, $tenantRepository);

        $tickets = $ticketRepository->createQueryBuilder('t')
            ->leftJoin('t.priority', 'pr')
            ->leftJoin('t.status', 'st')
            ->leftJoin('t.requester', 'req')
            ->leftJoin('t.assignee', 'asg')
            ->leftJoin('t.ticketSlas', 'tsla')
            ->leftJoin('tsla.slaPolicy', 'sp')
            ->addSelect('pr', 'st', 'req', 'asg', 'tsla', 'sp')
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $now = new \DateTimeImmutable();
        $policiesByPriority = [];
        $allPolicies = $policyRepository->findAll();
        foreach ($allPolicies as $pol) {
            if ($pol->getPriority()) {
                $policiesByPriority[$pol->getPriority()->getId()] = $pol;
            }
        }

        $monitoredTickets = [];
        $onTimeCount = 0;
        $atRiskCount = 0;
        $breachedCount = 0;
        $hasSlaCreated = false;

        foreach ($tickets as $ticket) {
            $ticketSla = $ticket->getTicketSlas()->first() ?: null;
            $matchingPolicy = $ticket->getPriority() ? ($policiesByPriority[$ticket->getPriority()->getId()] ?? ($allPolicies[0] ?? null)) : ($allPolicies[0] ?? null);

            if (!$ticketSla && $matchingPolicy) {
                $ticketSla = new TicketSla();
                $ticketSla->setTicket($ticket);
                $ticketSla->setSlaPolicy($matchingPolicy);
                $em->persist($ticketSla);
                $hasSlaCreated = true;
            }

            if ($ticketSla && $matchingPolicy) {
                $createdAt = $ticket->getCreatedAt();
                $respDue = $createdAt->modify(sprintf('+%d minutes', $matchingPolicy->getResponseTimeMinutes()));
                $resolDue = $createdAt->modify(sprintf('+%d minutes', $matchingPolicy->getResolutionTimeMinutes()));
                $ticketSla->setResponseDueAt($respDue);
                $ticketSla->setResolutionDueAt($resolDue);

                if ($ticket->getStatus() && $ticket->getStatus()->isClosedState()) {
                    $resolvedAt = $ticket->getResolvedAt() ?? $ticket->getUpdatedAt() ?? $resolDue;
                    $ticketSla->setResolvedAt($resolvedAt);
                    if (!$ticketSla->getFirstResponseAt()) {
                        $ticketSla->setFirstResponseAt($createdAt->modify('+10 minutes'));
                    }
                    $ticketSla->setResolutionBreached($resolvedAt > $resolDue);
                    $ticketSla->setResponseBreached($ticketSla->getFirstResponseAt() > $respDue);
                } else {
                    $ticketSla->setResolvedAt(null);
                    $ticketSla->setResponseBreached($now > $respDue && !$ticketSla->getFirstResponseAt());
                    $ticketSla->setResolutionBreached($now > $resolDue);
                }
                $hasSlaCreated = true;
            }

            if ($ticketSla) {
                $metric = $this->calculateSlaMetric($ticket, $ticketSla, $now);
                $monitoredTickets[] = $metric;

                if ($metric['overall_status'] === 'breached') {
                    $breachedCount++;
                } elseif ($metric['overall_status'] === 'at_risk') {
                    $atRiskCount++;
                } else {
                    $onTimeCount++;
                }
            }
        }

        if ($hasSlaCreated) {
            $em->flush();
        }

        $totalMonitored = count($monitoredTickets);
        $complianceRate = $totalMonitored > 0 ? (int) round((($totalMonitored - $breachedCount) / $totalMonitored) * 100) : 100;
        $priorities = $priorityRepository->findBy([], ['level' => 'DESC']);
        $statuses = $statusRepository->findBy([], ['orderIndex' => 'ASC']);

        return $this->render('sla/compliance.html.twig', [
            'items' => $monitoredTickets,
            'priorities' => $priorities,
            'statuses' => $statuses,
            'stats' => [
                'total' => $totalMonitored,
                'compliance_rate' => $complianceRate,
                'on_time' => $onTimeCount,
                'at_risk' => $atRiskCount,
                'breached' => $breachedCount,
            ],
        ]);
    }

    #[Route('/escalations', name: 'escalation_index', methods: ['GET'])]
    public function escalationIndex(
        TicketRepository $ticketRepository,
        TicketSlaRepository $ticketSlaRepository,
        TicketStatusRepository $statusRepository,
        SlaPolicyRepository $policyRepository,
        TicketPriorityRepository $priorityRepository,
        TenantRepository $tenantRepository,
        UserRepository $userRepository,
        EntityManagerInterface $em,
    ): Response {
        $this->ensureDefaultPolicies($em, $policyRepository, $priorityRepository, $tenantRepository);

        $tickets = $ticketRepository->createQueryBuilder('t')
            ->leftJoin('t.priority', 'pr')
            ->leftJoin('t.status', 'st')
            ->leftJoin('t.requester', 'req')
            ->leftJoin('t.assignee', 'asg')
            ->leftJoin('t.ticketSlas', 'tsla')
            ->leftJoin('tsla.slaPolicy', 'sp')
            ->addSelect('pr', 'st', 'req', 'asg', 'tsla', 'sp')
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $now = new \DateTimeImmutable();
        $allPolicies = $policyRepository->findAll();
        $policiesByPriority = [];
        foreach ($allPolicies as $pol) {
            if ($pol->getPriority()) {
                $policiesByPriority[$pol->getPriority()->getId()] = $pol;
            }
        }

        $escalatedItems = [];
        $activeAlertsCount = 0;
        $escalatedCount = 0;
        $criticalRiskCount = 0;
        $resolvedCount = 0;

        foreach ($tickets as $ticket) {
            $ticketSla = $ticket->getTicketSlas()->first() ?: null;
            $matchingPolicy = $ticket->getPriority() ? ($policiesByPriority[$ticket->getPriority()->getId()] ?? ($allPolicies[0] ?? null)) : ($allPolicies[0] ?? null);

            if (!$ticketSla && $matchingPolicy) {
                $ticketSla = new TicketSla();
                $ticketSla->setTicket($ticket);
                $ticketSla->setSlaPolicy($matchingPolicy);
                $em->persist($ticketSla);
            }

            if ($ticketSla && $matchingPolicy) {
                $createdAt = $ticket->getCreatedAt();
                $respDue = $createdAt->modify(sprintf('+%d minutes', $matchingPolicy->getResponseTimeMinutes()));
                $resolDue = $createdAt->modify(sprintf('+%d minutes', $matchingPolicy->getResolutionTimeMinutes()));
                $ticketSla->setResponseDueAt($respDue);
                $ticketSla->setResolutionDueAt($resolDue);

                if ($ticket->getStatus() && $ticket->getStatus()->isClosedState()) {
                    $resolvedAt = $ticket->getResolvedAt() ?? $ticket->getUpdatedAt() ?? $resolDue;
                    $ticketSla->setResolvedAt($resolvedAt);
                    if (!$ticketSla->getFirstResponseAt()) {
                        $ticketSla->setFirstResponseAt($createdAt->modify('+10 minutes'));
                    }
                    $ticketSla->setResolutionBreached($resolvedAt > $resolDue);
                    $ticketSla->setResponseBreached($ticketSla->getFirstResponseAt() > $respDue);
                } else {
                    $ticketSla->setResolvedAt(null);
                    $ticketSla->setResponseBreached($now > $respDue && !$ticketSla->getFirstResponseAt());
                    $ticketSla->setResolutionBreached($now > $resolDue);
                }
            }

            if ($ticketSla) {
                $metric = $this->calculateSlaMetric($ticket, $ticketSla, $now);
                $isClosed = $ticket->getStatus() && $ticket->getStatus()->isClosedState();
                $createdAt = $ticket->getCreatedAt();
                $resolDue = $ticketSla->getResolutionDueAt();
                $totalSec = $resolDue ? max(1, $resolDue->getTimestamp() - $createdAt->getTimestamp()) : 1;

                if ($isClosed) {
                    $finishDate = $ticketSla->getResolvedAt() ?? $ticket->getResolvedAt() ?? $ticket->getUpdatedAt() ?? $now;
                    $elapsedSec = max(0, $finishDate->getTimestamp() - $createdAt->getTimestamp());
                    $elapsedRatio = min(100, (int) round(($elapsedSec / $totalSec) * 100));
                    $elapsedTimeStr = $this->formatElapsedTime($elapsedSec);
                    $timeStatusStr = 'Resuelto';
                    $isBreached = ($finishDate > $resolDue);
                } else {
                    $elapsedSec = max(0, $now->getTimestamp() - $createdAt->getTimestamp());
                    $elapsedRatio = (int) round(($elapsedSec / $totalSec) * 100);
                    $elapsedTimeStr = $this->formatElapsedTime($elapsedSec);
                    $diffSec = $resolDue ? ($resolDue->getTimestamp() - $now->getTimestamp()) : 0;
                    if ($diffSec > 0) {
                        $timeStatusStr = sprintf('Restan %s', $this->formatElapsedTime($diffSec));
                    } else {
                        $timeStatusStr = sprintf('Vencido hace %s', $this->formatElapsedTime(abs($diffSec)));
                    }
                    $isBreached = ($metric['overall_status'] === 'breached' || ($resolDue && $now > $resolDue));
                }

                $priorityLevel = $ticket->getPriority() ? $ticket->getPriority()->getLevel() : 1;

                if ($isBreached && $priorityLevel >= 3) {
                    $escalationLevel = 3;
                    $escalationName = 'Nivel 3 · Coordinación TI';
                    $escalateToRole = 'Coordinador TI / Gerencia';
                } elseif ($metric['overall_status'] === 'at_risk' || $elapsedRatio >= 70 || $priorityLevel >= 3 || $isBreached) {
                    $escalationLevel = 2;
                    $escalationName = 'Nivel 2 · Especialista TI';
                    $escalateToRole = 'Especialista TI Senior';
                } else {
                    $escalationLevel = 1;
                    $escalationName = 'Nivel 1 · Mesa de Ayuda';
                    $escalateToRole = 'Técnico de Soporte N1';
                }

                if ($isBreached) {
                    $alertType = 'Vencido';
                    $alertBadge = 'danger';
                    $alertLabel = 'Vencido';
                    $activeAlertsCount++;
                } else {
                    $alertType = 'En Tiempo';
                    $alertBadge = 'panel';
                    $alertLabel = 'En Tiempo';
                }

                if (!$isClosed && ($metric['overall_status'] === 'at_risk' || $elapsedRatio >= 70)) {
                    $criticalRiskCount++;
                }

                if ($escalationLevel >= 2) {
                    $escalatedCount++;
                }
                if ($isClosed) {
                    $resolvedCount++;
                }

                $escalatedItems[] = [
                    'ticket' => $ticket,
                    'ticketSla' => $ticketSla,
                    'policy' => $matchingPolicy ?? $ticketSla->getSlaPolicy(),
                    'metric' => $metric,
                    'escalation_level' => $escalationLevel,
                    'escalation_name' => $escalationName,
                    'escalate_to' => $escalateToRole,
                    'alert_type' => $alertType,
                    'alert_badge' => $alertBadge,
                    'alert_label' => $alertLabel,
                    'elapsed_ratio' => min(100, max(0, $elapsedRatio)),
                    'elapsed_time_str' => $elapsedTimeStr,
                    'time_status_str' => $timeStatusStr,
                    'is_breached' => $isBreached,
                    'is_closed' => $isClosed,
                ];
            }
        }

        $em->flush();

        $agents = $userRepository->createQueryBuilder('u')
            ->where('u.status = :status')
            ->setParameter('status', true)
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();

        $priorities = $priorityRepository->findBy([], ['level' => 'DESC']);
        $statuses = $statusRepository->findBy([], ['orderIndex' => 'ASC']);

        return $this->render('sla/escalations.html.twig', [
            'items' => $escalatedItems,
            'priorities' => $priorities,
            'statuses' => $statuses,
            'agents' => $agents,
            'stats' => [
                'total' => count($escalatedItems),
                'active_alerts' => $activeAlertsCount,
                'escalated' => $escalatedCount,
                'critical_risk' => $criticalRiskCount,
                'resolved' => $resolvedCount,
            ],
        ]);
    }

    #[Route('/escalations/{id}/escalate', name: 'escalation_action', methods: ['POST'])]
    public function escalateAction(
        string $id,
        Request $request,
        TicketRepository $ticketRepository,
        UserRepository $userRepository,
        TicketStatusRepository $statusRepository,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfTokenManager,
    ): Response {
        $token = new CsrfToken('escalate_ticket', (string) $request->request->get('_csrf_token'));
        if (!$csrfTokenManager->isTokenValid($token)) {
            $this->addFlash('error', 'Token de seguridad inválido. Intenta de nuevo.');
            return $this->redirectToRoute('app_sla_escalation_index');
        }

        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            $this->addFlash('error', 'Identificador de ticket inválido.');
            return $this->redirectToRoute('app_sla_escalation_index');
        }

        $ticket = $ticketRepository->find($uuid);
        if (!$ticket) {
            $this->addFlash('error', 'El ticket no fue encontrado.');
            return $this->redirectToRoute('app_sla_escalation_index');
        }

        $newLevel = (int) $request->request->get('escalation_level', 2);
        $targetUserId = $request->request->get('target_user_id');
        $reason = trim((string) $request->request->get('reason', ''));
        $technicalNote = trim((string) $request->request->get('technical_note', ''));

        $oldAssignee = $ticket->getAssignee();
        $oldStatus = $ticket->getStatus();

        if ($targetUserId) {
            try {
                $targetUser = $userRepository->find(Uuid::fromString((string) $targetUserId));
                if ($targetUser) {
                    $ticket->setAssignee($targetUser);
                }
            } catch (\InvalidArgumentException) {}
        }

        $currentUser = $this->getUser();
        if ($currentUser instanceof \App\Entity\User) {
            $comment = new \App\Entity\TicketComment();
            $comment->setTicket($ticket);
            $comment->setUser($currentUser);
            $levelText = match ($newLevel) {
                3 => 'Nivel 3 (Coordinación TI / Gerencia)',
                2 => 'Nivel 2 (Soporte Especializado TI)',
                default => 'Nivel 1 (Mesa de Ayuda)',
            };
            $content = sprintf("⚠️ ESCALAMIENTO A %s\nMotivo: %s", $levelText, $reason ?: 'Requerimiento de atención prioritaria por SLA');
            if ($technicalNote !== '') {
                $content .= "\nNota técnica: " . $technicalNote;
            }
            $comment->setContent($content);
            $comment->setIsPrivate(true);
            $em->persist($comment);

            if ($ticket->getTenant()) {
                $audit = new \App\Entity\AuditLog();
                $audit->setTenant($ticket->getTenant());
                $audit->setUser($currentUser);
                $audit->setAction('ESCALAR_TICKET_SLA');
                $audit->setEntityType('Ticket');
                $audit->setEntityId($ticket->getId());
                $audit->setOldValues([
                    'assignee' => $oldAssignee ? $oldAssignee->getName() : null,
                    'status' => $oldStatus ? $oldStatus->getName() : null,
                ]);
                $audit->setNewValues([
                    'ticket_code' => $ticket->getTicketCode(),
                    'escalation_level' => $newLevel,
                    'assignee' => $ticket->getAssignee() ? $ticket->getAssignee()->getName() : null,
                    'reason' => $reason,
                ]);
                $audit->setCreatedAt(new \DateTimeImmutable());
                $em->persist($audit);
            }
        }

        if ($ticket->getStatus() && $ticket->getStatus()->getCode() === 'NEW') {
            $inProgress = $statusRepository->findOneBy(['code' => 'IN_PROGRESS']);
            if ($inProgress) {
                $ticket->setStatus($inProgress);
            }
        }

        $em->flush();

        $this->addFlash('success', sprintf('Ticket %s escalado con éxito.', $ticket->getTicketCode()));
        return $this->redirectToRoute('app_sla_escalation_index');
    }

    #[Route('/policies/save', name: 'policy_save', methods: ['POST'])]
    public function policySave(
        Request $request,
        SlaPolicyRepository $policyRepository,
        TicketPriorityRepository $priorityRepository,
        TenantRepository $tenantRepository,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfManager,
    ): Response {
        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('save_sla_policy', $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido. Recarga la página e intenta de nuevo.', Response::HTTP_BAD_REQUEST);
        }

        $id = trim((string) $request->request->get('id'));
        $name = trim((string) $request->request->get('name'));
        $priorityId = (int) $request->request->get('priority_id');
        $responseTime = (int) $request->request->get('response_time_minutes');
        $resolutionTime = (int) $request->request->get('resolution_time_minutes');
        $businessHoursOnly = $request->request->getBoolean('business_hours_only', false);

        if (mb_strlen($name) < 2) {
            return $this->handleResponse($request, false, 'El nombre de la política debe tener al menos 2 caracteres.');
        }

        $priority = $priorityRepository->find($priorityId);
        if (!$priority) {
            return $this->handleResponse($request, false, 'Debes seleccionar una prioridad válida.');
        }

        if ($responseTime <= 0) {
            return $this->handleResponse($request, false, 'El tiempo de primera respuesta debe ser mayor a 0 minutos.');
        }

        if ($resolutionTime <= 0) {
            return $this->handleResponse($request, false, 'El tiempo de resolución debe ser mayor a 0 minutos.');
        }

        if ($resolutionTime < $responseTime) {
            return $this->handleResponse($request, false, 'El tiempo de resolución no puede ser menor al tiempo de primera respuesta.');
        }

        if ($id !== '') {
            try {
                $policy = $policyRepository->find(Uuid::fromString($id));
            } catch (\InvalidArgumentException) {
                return $this->handleResponse($request, false, 'Identificador de política inválido.', Response::HTTP_BAD_REQUEST);
            }

            if (!$policy) {
                return $this->handleResponse($request, false, 'No se encontró la política a modificar.', Response::HTTP_NOT_FOUND);
            }
            $isNew = false;
        } else {
            $policy = new SlaPolicy();
            $tenant = $priority->getTenant() ?? $tenantRepository->findOneBy([]);
            if ($tenant) {
                $policy->setTenant($tenant);
            }
            $em->persist($policy);
            $isNew = true;
        }

        $policy->setName($name);
        $policy->setPriority($priority);
        $policy->setResponseTimeMinutes($responseTime);
        $policy->setResolutionTimeMinutes($resolutionTime);
        $policy->setBusinessHoursOnly($businessHoursOnly);

        $currentUser = $this->getUser();
        if ($currentUser instanceof \App\Entity\User && $policy->getTenant()) {
            $audit = new \App\Entity\AuditLog();
            $audit->setTenant($policy->getTenant());
            $audit->setUser($currentUser);
            $audit->setAction($isNew ? 'CREAR_POLITICA_SLA' : 'ACTUALIZAR_POLITICA_SLA');
            $audit->setEntityType('SlaPolicy');
            $audit->setEntityId($policy->getId());
            $audit->setNewValues([
                'name' => $policy->getName(),
                'priority' => $priority->getName(),
                'response_time_minutes' => $responseTime,
                'resolution_time_minutes' => $resolutionTime,
                'business_hours_only' => $businessHoursOnly,
            ]);
            $audit->setCreatedAt(new \DateTimeImmutable());
            $em->persist($audit);
        }

        $em->flush();

        $actionMsg = $isNew ? 'creada' : 'actualizada';
        return $this->handleResponse($request, true, sprintf('Política SLA "%s" %s correctamente.', $name, $actionMsg));
    }

    #[Route('/policies/{id}/delete', name: 'policy_delete', methods: ['POST'])]
    public function policyDelete(
        string $id,
        Request $request,
        SlaPolicyRepository $policyRepository,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfManager,
    ): Response {
        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('delete_sla_policy', $token)) && !$csrfManager->isTokenValid(new CsrfToken('delete_sla_policy_' . $id, $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $policy = $policyRepository->find(Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            return $this->handleResponse($request, false, 'Identificador inválido.', Response::HTTP_BAD_REQUEST);
        }

        if (!$policy) {
            return $this->handleResponse($request, false, 'La política que intentas eliminar no existe.', Response::HTTP_NOT_FOUND);
        }

        if (!$policy->getTicketSlas()->isEmpty()) {
            return $this->handleResponse($request, false, 'No es posible eliminar esta política porque tiene tickets históricos asociados.');
        }

        $policyName = $policy->getName();
        $currentUser = $this->getUser();
        if ($currentUser instanceof \App\Entity\User && $policy->getTenant()) {
            $audit = new \App\Entity\AuditLog();
            $audit->setTenant($policy->getTenant());
            $audit->setUser($currentUser);
            $audit->setAction('ELIMINAR_POLITICA_SLA');
            $audit->setEntityType('SlaPolicy');
            $audit->setEntityId($policy->getId());
            $audit->setOldValues([
                'name' => $policyName,
                'priority' => $policy->getPriority() ? $policy->getPriority()->getName() : null,
            ]);
            $audit->setCreatedAt(new \DateTimeImmutable());
            $em->persist($audit);
        }

        $em->remove($policy);
        $em->flush();

        return $this->handleResponse($request, true, sprintf('Política "%s" eliminada correctamente.', $policyName));
    }

    private function handleResponse(Request $request, bool $success, string $message, int $status = Response::HTTP_OK): Response
    {
        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse(['success' => $success, 'message' => $message], $success ? $status : Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash($success ? 'success' : 'error', $message);
        return $this->redirectToRoute('app_sla_policy_index');
    }

    private function ensureDefaultPolicies(
        EntityManagerInterface $em,
        SlaPolicyRepository $policyRepository,
        TicketPriorityRepository $priorityRepository,
        TenantRepository $tenantRepository,
    ): void {
        $existingCount = $policyRepository->count([]);
        if ($existingCount > 0) {
            return;
        }

        $defaultTenant = $tenantRepository->findOneBy([]);
        if (!$defaultTenant) {
            $defaultTenant = new Tenant();
            $defaultTenant->setName('Tenant Principal');
            $defaultTenant->setDomain('default');
            $defaultTenant->setStatus(true);
            $em->persist($defaultTenant);
            $em->flush();
        }

        // Asegurar que existan las prioridades si aún no están creadas
        $prioritiesConfig = [
            ['name' => 'Crítica', 'level' => 4, 'color' => '#ef4444'],
            ['name' => 'Alta', 'level' => 3, 'color' => '#f59e0b'],
            ['name' => 'Media', 'level' => 2, 'color' => '#3b82f6'],
            ['name' => 'Baja', 'level' => 1, 'color' => '#10b981'],
        ];

        $prioritiesMap = [];
        foreach ($prioritiesConfig as $pConfig) {
            $pr = $priorityRepository->findOneBy(['name' => $pConfig['name']]);
            if (!$pr) {
                $pr = new TicketPriority();
                $pr->setTenant($defaultTenant);
                $pr->setName($pConfig['name']);
                $pr->setLevel($pConfig['level']);
                $pr->setColor($pConfig['color']);
                $em->persist($pr);
            }
            $prioritiesMap[$pConfig['name']] = $pr;
        }
        $em->flush();

        // Crear las políticas SLA por defecto estándar en la industria
        $defaultPolicies = [
            [
                'name' => 'SLA Crítico - Disponibilidad 24/7',
                'priority' => $prioritiesMap['Crítica'] ?? null,
                'responseTime' => 15, // 15 minutos
                'resolutionTime' => 120, // 2 horas
                'businessHours' => false, // 24/7
            ],
            [
                'name' => 'SLA Alto - Atención Rápida',
                'priority' => $prioritiesMap['Alta'] ?? null,
                'responseTime' => 30, // 30 minutos
                'resolutionTime' => 240, // 4 horas
                'businessHours' => false, // 24/7
            ],
            [
                'name' => 'SLA Medio - Estándar Operativo',
                'priority' => $prioritiesMap['Media'] ?? null,
                'responseTime' => 60, // 1 hora
                'resolutionTime' => 480, // 8 horas
                'businessHours' => true, // Horario Laboral
            ],
            [
                'name' => 'SLA Bajo - Consultas y Tareas Menores',
                'priority' => $prioritiesMap['Baja'] ?? null,
                'responseTime' => 120, // 2 horas
                'resolutionTime' => 1440, // 24 horas
                'businessHours' => true, // Horario Laboral
            ],
        ];

        foreach ($defaultPolicies as $dPolicy) {
            if ($dPolicy['priority']) {
                $policy = new SlaPolicy();
                $policy->setName($dPolicy['name']);
                $policy->setPriority($dPolicy['priority']);
                $policy->setResponseTimeMinutes($dPolicy['responseTime']);
                $policy->setResolutionTimeMinutes($dPolicy['resolutionTime']);
                $policy->setBusinessHoursOnly($dPolicy['businessHours']);
                $policy->setTenant($defaultTenant);
                $em->persist($policy);
            }
        }

        $em->flush();
    }

    private function calculateSlaMetric(Ticket $ticket, TicketSla $ticketSla, \DateTimeImmutable $now): array
    {
        $policy = $ticketSla->getSlaPolicy();
        $createdAt = $ticket->getCreatedAt();
        $respDue = $ticketSla->getResponseDueAt();
        $resolDue = $ticketSla->getResolutionDueAt();
        $firstResp = $ticketSla->getFirstResponseAt();
        $resolved = $ticketSla->getResolvedAt();
        $isClosed = $ticket->getStatus() && $ticket->getStatus()->isClosedState();

        // 1. Métrica Primera Respuesta
        $respLabel = '';
        $respDetail = '';
        $respState = 'on_time';

        if ($firstResp !== null) {
            $diffMinutes = max(1, (int) round(($firstResp->getTimestamp() - $createdAt->getTimestamp()) / 60));
            $respLabel = 'Respondido';
            $respDetail = sprintf('en %d min', $diffMinutes);
            $respState = ($respDue && $firstResp > $respDue) ? 'breached' : 'on_time';
        } elseif ($respDue) {
            $diffSeconds = $respDue->getTimestamp() - $now->getTimestamp();
            if ($diffSeconds > 0) {
                $respLabel = 'Pendiente';
                $respDetail = $this->formatDuration($diffSeconds, 'restan');
                $respState = 'on_time';
            } else {
                $respLabel = 'Vencido';
                $respDetail = $this->formatDuration(abs($diffSeconds), 'hace');
                $respState = 'breached';
            }
        }

        // 2. Métrica Resolución
        $resolLabel = '';
        $resolDetail = '';
        $resolState = 'on_time';
        $progressPercent = 0;

        if ($isClosed || $resolved !== null) {
            $resolLabel = 'Resuelto';
            $finishDate = $resolved ?? $ticket->getUpdatedAt() ?? $now;
            $diffMinutes = max(1, (int) round(($finishDate->getTimestamp() - $createdAt->getTimestamp()) / 60));
            $resolDetail = $this->formatMinutes($diffMinutes);
            $resolState = ($resolDue && $finishDate > $resolDue) ? 'breached' : 'on_time';
            $progressPercent = 100;
        } elseif ($resolDue) {
            $totalSeconds = max(1, $resolDue->getTimestamp() - $createdAt->getTimestamp());
            $elapsedSeconds = max(0, $now->getTimestamp() - $createdAt->getTimestamp());
            $ratio = $elapsedSeconds / $totalSeconds;
            $progressPercent = min(100, (int) round($ratio * 100));

            $diffSeconds = $resolDue->getTimestamp() - $now->getTimestamp();
            if ($diffSeconds > 0) {
                if ($ratio >= 0.70) {
                    $resolLabel = 'En Riesgo';
                    $resolState = 'at_risk';
                } else {
                    $resolLabel = 'En Tiempo';
                    $resolState = 'on_time';
                }
                $resolDetail = $this->formatDuration($diffSeconds, 'restan');
            } else {
                $resolLabel = 'Vencido';
                $resolState = 'breached';
                $resolDetail = $this->formatDuration(abs($diffSeconds), 'hace');
            }
        }

        // 3. Estado General SLA
        if ($respState === 'breached' || $resolState === 'breached' || $ticketSla->isResponseBreached() || $ticketSla->isResolutionBreached()) {
            $overallStatus = 'breached';
            $overallText = 'Incumplido';
        } elseif ($resolState === 'at_risk') {
            $overallStatus = 'at_risk';
            $overallText = 'En Riesgo';
        } else {
            $overallStatus = 'on_time';
            $overallText = 'En Tiempo';
        }

        return [
            'ticket' => $ticket,
            'ticketSla' => $ticketSla,
            'policy' => $policy,
            'response' => [
                'label' => $respLabel,
                'detail' => $respDetail,
                'state' => $respState,
            ],
            'resolution' => [
                'label' => $resolLabel,
                'detail' => $resolDetail,
                'state' => $resolState,
                'progress' => $progressPercent,
            ],
            'overall_status' => $overallStatus,
            'overall_text' => $overallText,
        ];
    }

    private function formatDuration(int $seconds, string $prefix): string
    {
        $minutes = (int) round($seconds / 60);
        if ($minutes < 60) {
            return $prefix === 'restan' ? sprintf('Restan %d min', max(1, $minutes)) : sprintf('Hace %d min', max(1, $minutes));
        }

        $hours = (int) floor($minutes / 60);
        $remMinutes = $minutes % 60;
        if ($remMinutes === 0) {
            return $prefix === 'restan' ? sprintf('Restan %d h', $hours) : sprintf('Hace %d h', $hours);
        }

        return $prefix === 'restan' ? sprintf('Restan %d h %d m', $hours, $remMinutes) : sprintf('Hace %d h %d m', $hours, $remMinutes);
    }

    private function formatMinutes(int $minutes): string
    {
        if ($minutes < 60) {
            return sprintf('en %d min', $minutes);
        }
        $hours = (int) floor($minutes / 60);
        $rem = $minutes % 60;
        return $rem > 0 ? sprintf('en %d h %d m', $hours, $rem) : sprintf('en %d h', $hours);
    }

    private function formatElapsedTime(int $seconds): string
    {
        $minutes = (int) round($seconds / 60);
        if ($minutes < 1) {
            return '< 1 min';
        }
        if ($minutes < 60) {
            return sprintf('%d min', $minutes);
        }
        $hours = (int) floor($minutes / 60);
        $remMinutes = $minutes % 60;
        if ($remMinutes === 0) {
            return sprintf('%d h', $hours);
        }
        return sprintf('%d h %d m', $hours, $remMinutes);
    }
}
