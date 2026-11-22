<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CiCategory;
use App\Entity\CiItem;
use App\Entity\CiRelationship;
use App\Entity\Tenant;
use App\Enum\CiCategoryType;
use App\Enum\CiItemStatus;
use App\Enum\CiRelationshipType;
use App\Repository\CiCategoryRepository;
use App\Repository\CiItemRepository;
use App\Repository\CiRelationshipRepository;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

#[Route('/cmdb')]
final class CmdbController extends AbstractController
{
    #[Route('/assets', name: 'app_cmdb_asset_index', methods: ['GET'])]
    public function index(
        Request $request,
        CiItemRepository $ciItemRepository,
        CiCategoryRepository $categoryRepository,
        TenantRepository $tenantRepository,
    ): Response {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        $items = $ciItemRepository->findBy([], ['name' => 'ASC']);
        $categories = $categoryRepository->findBy([], ['name' => 'ASC']);
        $tenants = $tenantRepository->findBy(['status' => true], ['name' => 'ASC']);

        // Calcular contadores para KPIs
        $totalCount = count($items);
        $physicalCount = 0;
        $logicalCount = 0;
        $maintenanceCount = 0;

        foreach ($items as $item) {
            if ($item->getCategory()?->getType() === CiCategoryType::FISICO) {
                $physicalCount++;
            } else {
                $logicalCount++;
            }

            if ($item->getStatus() === CiItemStatus::MANTENIMIENTO) {
                $maintenanceCount++;
            }
        }

        $activeType = $request->query->get('type', 'all');

        return $this->render('cmdb/assets.html.twig', [
            'items' => $items,
            'categories' => $categories,
            'tenants' => $tenants,
            'statuses' => CiItemStatus::cases(),
            'categoryTypes' => CiCategoryType::cases(),
            'totalCount' => $totalCount,
            'physicalCount' => $physicalCount,
            'logicalCount' => $logicalCount,
            'maintenanceCount' => $maintenanceCount,
            'activeType' => $activeType,
        ]);
    }

    #[Route('/assets/create', name: 'app_cmdb_asset_create', methods: ['POST'])]
    public function create(
        Request $request,
        CiCategoryRepository $categoryRepository,
        TenantRepository $tenantRepository,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfManager,
    ): Response {
        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('create_ci_item', $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.', Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) $request->request->get('name'));
        $categoryId = (int) $request->request->get('category_id');
        $tenantId = $request->request->get('tenant_id');
        $statusStr = (string) $request->request->get('status', CiItemStatus::ACTIVO->value);
        $description = trim((string) $request->request->get('description'));
        $serialNumber = trim((string) $request->request->get('serial_number'));
        $ipAddress = trim((string) $request->request->get('ip_address'));
        $macAddress = trim((string) $request->request->get('mac_address'));
        $location = trim((string) $request->request->get('location'));

        if (mb_strlen($name) < 2) {
            return $this->handleResponse($request, false, 'El nombre del activo debe tener al menos 2 caracteres.');
        }

        $category = $categoryRepository->find($categoryId);
        if (!$category) {
            return $this->handleResponse($request, false, 'Debes seleccionar una categoría válida.');
        }

        $tenant = null;
        if ($tenantId) {
            try {
                $tenant = $tenantRepository->find(Uuid::fromString((string) $tenantId));
            } catch (\InvalidArgumentException) {}
        }
        if (!$tenant) {
            $tenant = $tenantRepository->findOneBy([]) ?? $category->getTenant();
        }

        $status = CiItemStatus::tryFrom($statusStr) ?? CiItemStatus::ACTIVO;

        $item = new CiItem();
        $item->setName($name);
        $item->setCategory($category);
        $item->setTenant($tenant);
        $item->setStatus($status);
        $item->setDescription($description !== '' ? $description : null);
        $item->setSerialNumber($serialNumber !== '' ? $serialNumber : null);
        $item->setIpAddress($ipAddress !== '' ? $ipAddress : null);
        $item->setMacAddress($macAddress !== '' ? $macAddress : null);
        $item->setLocation($location !== '' ? $location : null);

        $em->persist($item);
        $em->flush();

        return $this->handleResponse($request, true, sprintf('Activo "%s" registrado exitosamente.', $name));
    }

    #[Route('/assets/{id}/edit', name: 'app_cmdb_asset_edit', methods: ['POST'])]
    public function edit(
        string $id,
        Request $request,
        CiItemRepository $ciItemRepository,
        CiCategoryRepository $categoryRepository,
        TenantRepository $tenantRepository,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfManager,
    ): Response {
        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('edit_ci_item', $token)) && !$csrfManager->isTokenValid(new CsrfToken('edit_ci_item_' . $id, $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $item = $ciItemRepository->find(Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            $item = null;
        }

        if (!$item) {
            return $this->handleResponse($request, false, 'Activo no encontrado.', Response::HTTP_NOT_FOUND);
        }

        $name = trim((string) $request->request->get('name'));
        $categoryId = (int) $request->request->get('category_id');
        $tenantId = $request->request->get('tenant_id');
        $statusStr = (string) $request->request->get('status', $item->getStatus()->value);
        $description = trim((string) $request->request->get('description'));
        $serialNumber = trim((string) $request->request->get('serial_number'));
        $ipAddress = trim((string) $request->request->get('ip_address'));
        $macAddress = trim((string) $request->request->get('mac_address'));
        $location = trim((string) $request->request->get('location'));

        if (mb_strlen($name) < 2) {
            return $this->handleResponse($request, false, 'El nombre del activo debe tener al menos 2 caracteres.');
        }

        $category = $categoryRepository->find($categoryId);
        if ($category) {
            $item->setCategory($category);
        }

        if ($tenantId) {
            try {
                $tenant = $tenantRepository->find(Uuid::fromString((string) $tenantId));
                if ($tenant) {
                    $item->setTenant($tenant);
                }
            } catch (\InvalidArgumentException) {}
        }

        $status = CiItemStatus::tryFrom($statusStr);
        if ($status) {
            $item->setStatus($status);
        }

        $item->setName($name);
        $item->setDescription($description !== '' ? $description : null);
        $item->setSerialNumber($serialNumber !== '' ? $serialNumber : null);
        $item->setIpAddress($ipAddress !== '' ? $ipAddress : null);
        $item->setMacAddress($macAddress !== '' ? $macAddress : null);
        $item->setLocation($location !== '' ? $location : null);

        $em->flush();

        return $this->handleResponse($request, true, sprintf('Activo "%s" actualizado correctamente.', $name));
    }

    #[Route('/assets/{id}/delete', name: 'app_cmdb_asset_delete', methods: ['POST'])]
    public function delete(
        string $id,
        Request $request,
        CiItemRepository $ciItemRepository,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfManager,
    ): Response {
        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('delete_ci_item', $token)) && !$csrfManager->isTokenValid(new CsrfToken('delete_ci_item_' . $id, $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $item = $ciItemRepository->find(Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            $item = null;
        }

        if (!$item) {
            return $this->handleResponse($request, false, 'Activo no encontrado.', Response::HTTP_NOT_FOUND);
        }

        $name = $item->getName();
        $em->remove($item);
        $em->flush();

        return $this->handleResponse($request, true, sprintf('El activo "%s" fue eliminado del inventario.', $name));
    }

    #[Route('/topology', name: 'app_cmdb_topology_index', methods: ['GET'])]
    public function topology(
        Request $request,
        CiItemRepository $ciItemRepository,
        CiRelationshipRepository $relationshipRepository,
        TenantRepository $tenantRepository,
        CiCategoryRepository $categoryRepository,
    ): Response {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        $tenantId = $request->query->get('tenant');
        $selectedTenant = null;
        if ($tenantId) {
            try {
                $selectedTenant = $tenantRepository->find(Uuid::fromString((string) $tenantId));
            } catch (\InvalidArgumentException) {}
        }

        $items = $selectedTenant
            ? $ciItemRepository->findBy(['tenant' => $selectedTenant], ['name' => 'ASC'])
            : $ciItemRepository->findBy([], ['name' => 'ASC']);

        $allRelationships = $relationshipRepository->findAll();
        $relationships = [];
        $itemIds = array_map(fn(CiItem $i) => $i->getId()->toRfc4122(), $items);

        foreach ($allRelationships as $rel) {
            $pId = $rel->getParentCi()?->getId()?->toRfc4122();
            $cId = $rel->getChildCi()?->getId()?->toRfc4122();
            if ($pId && $cId && in_array($pId, $itemIds, true) && in_array($cId, $itemIds, true)) {
                $relationships[] = $rel;
            }
        }

        // Métricas / KPIs
        $totalItems = count($items);
        $totalRelationships = count($relationships);
        $criticalDependencies = 0;
        $maintenanceNodes = 0;

        foreach ($relationships as $rel) {
            if ($rel->getRelationshipType() === CiRelationshipType::DEPENDS_ON) {
                $criticalDependencies++;
            }
        }

        foreach ($items as $item) {
            if ($item->getStatus() === CiItemStatus::MANTENIMIENTO) {
                $maintenanceNodes++;
            }
        }

        // Serializar nodos y aristas para el visor interactivo de topología
        $graphNodes = [];
        foreach ($items as $item) {
            $isFisico = $item->getCategory()?->getType() === CiCategoryType::FISICO;
            $graphNodes[] = [
                'id' => $item->getId()->toRfc4122(),
                'label' => $item->getName(),
                'type' => $isFisico ? 'Físico' : 'Lógico',
                'category' => $item->getCategory()?->getName() ?? 'Sin Categoría',
                'status' => $item->getStatus()->value,
                'ip' => $item->getIpAddress() ?? '',
                'mac' => $item->getMacAddress() ?? '',
                'serial' => $item->getSerialNumber() ?? '',
                'location' => $item->getLocation() ?? 'No especificada',
                'tenant' => $item->getTenant()?->getName() ?? 'Default',
                'description' => $item->getDescription() ?? '',
            ];
        }

        $graphEdges = [];
        foreach ($relationships as $rel) {
            $graphEdges[] = [
                'id' => $rel->getId(),
                'from' => $rel->getParentCi()->getId()->toRfc4122(),
                'to' => $rel->getChildCi()->getId()->toRfc4122(),
                'type' => $rel->getRelationshipType()->value,
                'label' => match($rel->getRelationshipType()) {
                    CiRelationshipType::HOSTED_ON => 'Alojado en',
                    CiRelationshipType::DEPENDS_ON => 'Depende de',
                    CiRelationshipType::CONNECTED_TO => 'Conectado a',
                },
            ];
        }

        return $this->render('cmdb/topology.html.twig', [
            'items' => $items,
            'relationships' => $relationships,
            'tenants' => $tenantRepository->findBy(['status' => true], ['name' => 'ASC']),
            'relationshipTypes' => CiRelationshipType::cases(),
            'totalItems' => $totalItems,
            'totalRelationships' => $totalRelationships,
            'criticalDependencies' => $criticalDependencies,
            'maintenanceNodes' => $maintenanceNodes,
            'selectedTenantId' => $tenantId,
            'graphNodesJson' => json_encode($graphNodes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),
            'graphEdgesJson' => json_encode($graphEdges, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),
        ]);
    }

    #[Route('/topology/relationship/create', name: 'app_cmdb_topology_rel_create', methods: ['POST'])]
    public function createRelationship(
        Request $request,
        CiItemRepository $ciItemRepository,
        CiRelationshipRepository $relationshipRepository,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfManager,
    ): Response {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('create_ci_relationship', $token))) {
            return $this->handleTopologyResponse($request, false, 'Token de seguridad inválido.', Response::HTTP_BAD_REQUEST);
        }

        $parentIdStr = (string) $request->request->get('parent_ci_id');
        $childIdStr = (string) $request->request->get('child_ci_id');
        $typeStr = (string) $request->request->get('relationship_type');

        if ($parentIdStr === '' || $childIdStr === '') {
            return $this->handleTopologyResponse($request, false, 'Debes seleccionar tanto el activo origen como el destino.');
        }

        if ($parentIdStr === $childIdStr) {
            return $this->handleTopologyResponse($request, false, 'Un activo no puede tener una relación consigo mismo.');
        }

        try {
            $parent = $ciItemRepository->find(Uuid::fromString($parentIdStr));
            $child = $ciItemRepository->find(Uuid::fromString($childIdStr));
        } catch (\InvalidArgumentException) {
            $parent = null;
            $child = null;
        }

        if (!$parent || !$child) {
            return $this->handleTopologyResponse($request, false, 'Uno de los activos seleccionados no existe.');
        }

        $type = CiRelationshipType::tryFrom($typeStr) ?? CiRelationshipType::DEPENDS_ON;

        $existing = $relationshipRepository->findOneBy([
            'parentCi' => $parent,
            'childCi' => $child,
            'relationshipType' => $type,
        ]);

        if ($existing) {
            return $this->handleTopologyResponse($request, false, 'Esta relación entre los dos activos ya se encuentra registrada.');
        }

        $relationship = new CiRelationship();
        $relationship->setParentCi($parent);
        $relationship->setChildCi($child);
        $relationship->setRelationshipType($type);

        $em->persist($relationship);
        $em->flush();

        return $this->handleTopologyResponse($request, true, sprintf('Relación "%s" entre "%s" y "%s" registrada correctamente.', $type->value, $parent->getName(), $child->getName()));
    }

    #[Route('/topology/relationship/{id}/delete', name: 'app_cmdb_topology_rel_delete', methods: ['POST'])]
    public function deleteRelationship(
        int $id,
        Request $request,
        CiRelationshipRepository $relationshipRepository,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfManager,
    ): Response {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('delete_ci_relationship', $token)) && !$csrfManager->isTokenValid(new CsrfToken('delete_ci_relationship_' . $id, $token))) {
            return $this->handleTopologyResponse($request, false, 'Token de seguridad inválido.', Response::HTTP_BAD_REQUEST);
        }

        $relationship = $relationshipRepository->find($id);
        if (!$relationship) {
            return $this->handleTopologyResponse($request, false, 'La relación no fue encontrada.', Response::HTTP_NOT_FOUND);
        }

        $em->remove($relationship);
        $em->flush();

        return $this->handleTopologyResponse($request, true, 'Relación de topología eliminada correctamente.');
    }

    private function handleTopologyResponse(Request $request, bool $success, string $message, int $status = Response::HTTP_OK): Response
    {
        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse(['success' => $success, 'message' => $message], $success ? $status : Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash($success ? 'success' : 'error', $message);
        return $this->redirectToRoute('app_cmdb_topology_index');
    }

    private function handleResponse(Request $request, bool $success, string $message, int $status = Response::HTTP_OK): Response
    {
        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse(['success' => $success, 'message' => $message], $success ? $status : Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash($success ? 'success' : 'error', $message);
        return $this->redirectToRoute('app_cmdb_asset_index');
    }
}
