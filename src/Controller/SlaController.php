<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SlaPolicy;
use App\Entity\Tenant;
use App\Entity\TicketPriority;
use App\Repository\SlaPolicyRepository;
use App\Repository\TenantRepository;
use App\Repository\TicketPriorityRepository;
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
}
