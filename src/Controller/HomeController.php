<?php

namespace App\Controller;

use App\Entity\Ticket;
use App\Entity\TicketSla;
use App\Repository\CiCategoryRepository;
use App\Repository\CiItemRepository;
use App\Repository\SlaPolicyRepository;
use App\Repository\TenantRepository;
use App\Repository\TicketPriorityRepository;
use App\Repository\TicketRepository;
use App\Repository\TicketSlaRepository;
use App\Repository\TicketStatusRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/home', name: 'app_home')]
    public function index(
        TicketRepository $ticketRepository,
        TicketStatusRepository $statusRepository,
        TicketPriorityRepository $priorityRepository,
        CiItemRepository $ciItemRepository,
        CiCategoryRepository $ciCategoryRepository,
        UserRepository $userRepository,
        TenantRepository $tenantRepository,
        SlaPolicyRepository $policyRepository,
        TicketSlaRepository $slaRepository,
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        // 1. Estadísticas de Tickets
        $tickets = $ticketRepository->createQueryBuilder('t')
            ->leftJoin('t.status', 'st')
            ->leftJoin('t.priority', 'pr')
            ->leftJoin('t.requester', 'req')
            ->addSelect('st', 'pr', 'req')
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $totalTickets = count($tickets);
        $incidentCount = 0;
        $requestCount = 0;
        $resolvedCount = 0;
        $openCount = 0;

        $ticketsByStatusMap = [];
        $allStatuses = $statusRepository->findBy([], ['orderIndex' => 'ASC']);
        foreach ($allStatuses as $st) {
            $ticketsByStatusMap[$st->getName()] = 0;
        }

        $ticketsByPriorityMap = [];
        $allPriorities = $priorityRepository->findBy([], ['level' => 'DESC']);
        foreach ($allPriorities as $pr) {
            $ticketsByPriorityMap[$pr->getName()] = [
                'count' => 0,
                'color' => $pr->getColor() ?: '#64748b',
            ];
        }

        foreach ($tickets as $t) {
            if ($t->getType()->value === 'incidente') {
                $incidentCount++;
            } else {
                $requestCount++;
            }

            $st = $t->getStatus();
            if ($st) {
                $stName = $st->getName();
                $ticketsByStatusMap[$stName] = ($ticketsByStatusMap[$stName] ?? 0) + 1;
                if ($st->isClosedState()) {
                    $resolvedCount++;
                } else {
                    $openCount++;
                }
            } else {
                $openCount++;
            }

            $pr = $t->getPriority();
            if ($pr) {
                $prName = $pr->getName();
                if (isset($ticketsByPriorityMap[$prName])) {
                    $ticketsByPriorityMap[$prName]['count']++;
                } else {
                    $ticketsByPriorityMap[$prName] = [
                        'count' => 1,
                        'color' => $pr->getColor() ?: '#64748b',
                    ];
                }
            }
        }

        // 2. Métricas de Cumplimiento SLA
        $now = new \DateTimeImmutable();
        $onTimeCount = 0;
        $atRiskCount = 0;
        $breachedCount = 0;

        $allPolicies = $policyRepository->findAll();
        $policiesByPriority = [];
        foreach ($allPolicies as $pol) {
            if ($pol->getPriority()) {
                $policiesByPriority[$pol->getPriority()->getId()] = $pol;
            }
        }

        foreach ($tickets as $ticket) {
            $ticketSla = $ticket->getTicketSlas()->first() ?: null;
            if (!$ticketSla && $ticket->getPriority()) {
                $matchingPolicy = $policiesByPriority[$ticket->getPriority()->getId()] ?? ($allPolicies[0] ?? null);
                if ($matchingPolicy) {
                    $respDue = $ticket->getCreatedAt()->modify(sprintf('+%d minutes', $matchingPolicy->getResponseTimeMinutes()));
                    $resolDue = $ticket->getCreatedAt()->modify(sprintf('+%d minutes', $matchingPolicy->getResolutionTimeMinutes()));
                    if ($ticket->getStatus() && $ticket->getStatus()->isClosedState()) {
                        $onTimeCount++;
                    } else {
                        if ($now > $resolDue || $now > $respDue) {
                            $breachedCount++;
                        } else {
                            $onTimeCount++;
                        }
                    }
                    continue;
                }
            }

            if ($ticketSla) {
                $isResolved = $ticketSla->getResolvedAt() !== null || ($ticket->getStatus() && $ticket->getStatus()->isClosedState());
                if ($ticketSla->isResolutionBreached() || $ticketSla->isResponseBreached()) {
                    $breachedCount++;
                } elseif (!$isResolved && $ticketSla->getResolutionDueAt() && $now > $ticketSla->getResolutionDueAt()) {
                    $breachedCount++;
                } else {
                    $onTimeCount++;
                }
            }
        }

        $totalMonitored = $onTimeCount + $atRiskCount + $breachedCount;
        $complianceRate = $totalMonitored > 0 ? (int) round((($totalMonitored - $breachedCount) / $totalMonitored) * 100) : 100;

        // 3. Métricas de Activos (CMDB)
        $ciItems = $ciItemRepository->findAll();
        $totalCis = count($ciItems);
        $physicalCis = 0;
        $logicalCis = 0;
        $activeCis = 0;
        $maintenanceCis = 0;
        $cisByCategoryMap = [];

        foreach ($ciItems as $ci) {
            $meta = $ci->getMetadata() ?? [];
            $ciType = $meta['type'] ?? 'Físico';
            if ($ciType === 'Físico') {
                $physicalCis++;
            } else {
                $logicalCis++;
            }

            if ($ci->getStatus()->value === 'activo') {
                $activeCis++;
            } elseif ($ci->getStatus()->value === 'mantenimiento') {
                $maintenanceCis++;
            }

            $catName = $ci->getCategory() ? $ci->getCategory()->getName() : 'Sin Categoría';
            $cisByCategoryMap[$catName] = ($cisByCategoryMap[$catName] ?? 0) + 1;
        }

        // 4. Usuarios y Tenants
        $totalUsers = $userRepository->count([]);
        $totalTenants = $tenantRepository->count([]);

        // 5. Tickets Recientes (Top 5)
        $recentTickets = array_slice($tickets, 0, 5);

        return $this->render('home/index.html.twig', [
            'stats' => [
                'total_tickets' => $totalTickets,
                'open_tickets' => $openCount,
                'resolved_tickets' => $resolvedCount,
                'incident_count' => $incidentCount,
                'request_count' => $requestCount,
                'compliance_rate' => $complianceRate,
                'on_time_sla' => $onTimeCount,
                'at_risk_sla' => $atRiskCount,
                'breached_sla' => $breachedCount,
                'total_cis' => $totalCis,
                'physical_cis' => $physicalCis,
                'logical_cis' => $logicalCis,
                'active_cis' => $activeCis,
                'maintenance_cis' => $maintenanceCis,
                'total_users' => $totalUsers,
                'total_tenants' => $totalTenants,
            ],
            'chart_tickets_status' => [
                'labels' => array_keys($ticketsByStatusMap),
                'data' => array_values($ticketsByStatusMap),
            ],
            'chart_sla_compliance' => [
                'labels' => ['En Tiempo', 'En Riesgo', 'Incumplidos'],
                'data' => [$onTimeCount, $atRiskCount, $breachedCount],
            ],
            'chart_tickets_priority' => [
                'labels' => array_keys($ticketsByPriorityMap),
                'data' => array_column($ticketsByPriorityMap, 'count'),
                'colors' => array_column($ticketsByPriorityMap, 'color'),
            ],
            'chart_cis_category' => [
                'labels' => array_keys($cisByCategoryMap),
                'data' => array_values($cisByCategoryMap),
            ],
            'recent_tickets' => $recentTickets,
        ]);
    }
}
