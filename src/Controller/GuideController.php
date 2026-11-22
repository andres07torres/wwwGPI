<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WikiArticle;
use App\Entity\WikiCategory;
use App\Repository\TenantRepository;
use App\Repository\WikiArticleRepository;
use App\Repository\WikiCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

#[Route('/guides')]
final class GuideController extends AbstractController
{
    /**
     * Vista principal de Autoservicio (Clientes): Base de conocimiento pública.
     */
    #[Route('/self-service', name: 'app_guide_self_service_index', methods: ['GET'])]
    public function selfServiceIndex(
        Request $request,
        WikiArticleRepository $wikiArticleRepository,
        WikiCategoryRepository $wikiCategoryRepository,
    ): Response {
        /** @var User|null $currentUser */
        $currentUser = $this->getUser();
        if (!$currentUser) {
            return $this->redirectToRoute('app_login');
        }

        $allCategories = $wikiCategoryRepository->findAll();
        $publicArticles = $wikiArticleRepository->findBy(['isPublic' => true], ['createdAt' => 'DESC']);

        // Métricas / KPIs
        $totalArticles = count($publicArticles);
        $totalCategories = count($allCategories);
        $totalViews = 0;
        $startOfMonth = new \DateTimeImmutable('first day of this month 00:00:00');
        $newThisMonth = 0;

        foreach ($publicArticles as $art) {
            $totalViews += $art->getViewsCount();
            if ($art->getCreatedAt() >= $startOfMonth) {
                $newThisMonth++;
            }
        }

        return $this->render('guide/self_service/index.html.twig', [
            'articles' => $publicArticles,
            'categories' => $allCategories,
            'stats' => [
                'totalArticles' => $totalArticles,
                'totalCategories' => $totalCategories,
                'totalViews' => $totalViews,
                'newThisMonth' => $newThisMonth,
            ],
        ]);
    }

    /**
     * Detalle AJAX y lectura de artículo: incrementa vistas y retorna JSON estructurado.
     */
    #[Route('/self-service/{id}/detail', name: 'app_guide_self_service_detail', methods: ['GET'])]
    public function articleDetail(
        string $id,
        WikiArticleRepository $wikiArticleRepository,
        EntityManagerInterface $em,
    ): JsonResponse {
        try {
            $uuid = Uuid::fromString($id);
            $article = $wikiArticleRepository->find($uuid);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['success' => false, 'message' => 'Identificador de guía no válido.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$article) {
            return new JsonResponse(['success' => false, 'message' => 'Artículo no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        // Incrementar contador de visualizaciones
        $article->incrementViews();
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'article' => [
                'id' => (string) $article->getId(),
                'title' => $article->getTitle(),
                'content' => $article->getContent(),
                'category' => [
                    'id' => $article->getCategory()?->getId(),
                    'name' => $article->getCategory()?->getName() ?? 'General',
                ],
                'author' => [
                    'name' => $article->getAuthor()?->getName() ?? 'Equipo GPI',
                ],
                'viewsCount' => $article->getViewsCount(),
                'createdAt' => $article->getCreatedAt()->format('d/m/Y H:i'),
                'updatedAt' => $article->getUpdatedAt()->format('d/m/Y H:i'),
            ],
        ]);
    }

    /**
     * Crear nuevo artículo de Autoservicio (isPublic = true).
     */
    #[Route('/self-service/create', name: 'app_guide_self_service_create', methods: ['POST'])]
    public function create(
        Request $request,
        WikiCategoryRepository $categoryRepository,
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
        if (!$csrfManager->isTokenValid(new CsrfToken('create_guide', $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.');
        }

        $title = trim((string) $request->request->get('title'));
        $content = trim((string) $request->request->get('content'));
        $categoryId = (int) $request->request->get('category_id');
        $newCategoryName = trim((string) $request->request->get('new_category_name'));

        if ($title === '' || $content === '') {
            return $this->handleResponse($request, false, 'El título y el contenido son obligatorios.');
        }

        $tenant = $currentUser->getTenant() ?? $tenantRepository->findOneBy([]);
        if (!$tenant) {
            return $this->handleResponse($request, false, 'No existe una organización activa asignada.');
        }

        // Selección o creación de categoría
        $category = null;
        if ($newCategoryName !== '') {
            $category = new WikiCategory();
            $category->setName($newCategoryName);
            $category->setTenant($tenant);
            $em->persist($category);
        } elseif ($categoryId > 0) {
            $category = $categoryRepository->find($categoryId);
        }

        if (!$category) {
            return $this->handleResponse($request, false, 'Debe seleccionar o especificar una categoría válida.');
        }

        $article = new WikiArticle();
        $article->setTitle($title);
        $article->setContent($content);
        $article->setCategory($category);
        $article->setTenant($tenant);
        $article->setAuthor($currentUser);
        $article->setIsPublic(true); // Guía pública de Autoservicio
        $article->setViewsCount(0);

        $em->persist($article);
        $em->flush();

        // Registro de Auditoría
        $audit = new AuditLog();
        $audit->setTenant($tenant);
        $audit->setUser($currentUser);
        $audit->setAction('CREATE_GUIDE_ARTICLE');
        $audit->setEntityType('WikiArticle');
        $audit->setEntityId($article->getId());
        $audit->setNewValues(['title' => $title, 'category' => $category->getName()]);
        $em->persist($audit);
        $em->flush();

        return $this->handleResponse($request, true, sprintf('Guía "%s" publicada con éxito en Autoservicio.', $title));
    }

    /**
     * Editar artículo de Autoservicio.
     */
    #[Route('/self-service/{id}/edit', name: 'app_guide_self_service_edit', methods: ['POST'])]
    public function edit(
        string $id,
        Request $request,
        WikiArticleRepository $wikiArticleRepository,
        WikiCategoryRepository $categoryRepository,
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
            $article = $wikiArticleRepository->find($uuid);
        } catch (\InvalidArgumentException) {
            return $this->handleResponse($request, false, 'Identificador de guía inválido.');
        }

        if (!$article) {
            return $this->handleResponse($request, false, 'La guía no existe o fue eliminada.');
        }

        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('edit_guide_' . $id, $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.');
        }

        $title = trim((string) $request->request->get('title'));
        $content = trim((string) $request->request->get('content'));
        $categoryId = (int) $request->request->get('category_id');

        if ($title === '' || $content === '') {
            return $this->handleResponse($request, false, 'El título y el contenido son obligatorios.');
        }

        if ($categoryId > 0) {
            $category = $categoryRepository->find($categoryId);
            if ($category) {
                $article->setCategory($category);
            }
        }

        $article->setTitle($title);
        $article->setContent($content);
        $em->flush();

        if ($article->getTenant()) {
            $audit = new AuditLog();
            $audit->setTenant($article->getTenant());
            $audit->setUser($currentUser);
            $audit->setAction('UPDATE_GUIDE_ARTICLE');
            $audit->setEntityType('WikiArticle');
            $audit->setEntityId($article->getId());
            $audit->setNewValues(['title' => $title]);
            $em->persist($audit);
            $em->flush();
        }

        return $this->handleResponse($request, true, sprintf('Guía "%s" actualizada con éxito.', $title));
    }

    /**
     * Eliminar artículo de Autoservicio.
     */
    #[Route('/self-service/{id}/delete', name: 'app_guide_self_service_delete', methods: ['POST'])]
    public function delete(
        string $id,
        Request $request,
        WikiArticleRepository $wikiArticleRepository,
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
            $article = $wikiArticleRepository->find($uuid);
        } catch (\InvalidArgumentException) {
            return $this->handleResponse($request, false, 'Identificador de guía inválido.');
        }

        if (!$article) {
            return $this->handleResponse($request, false, 'La guía no existe o ya fue eliminada.');
        }

        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('delete_guide_' . $id, $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.');
        }

        $title = $article->getTitle() ?? 'Sin título';
        $tenant = $article->getTenant();
        $articleId = $article->getId();

        $em->remove($article);
        $em->flush();

        if ($tenant) {
            $audit = new AuditLog();
            $audit->setTenant($tenant);
            $audit->setUser($currentUser);
            $audit->setAction('DELETE_GUIDE_ARTICLE');
            $audit->setEntityType('WikiArticle');
            $audit->setEntityId($articleId);
            $audit->setOldValues(['title' => $title]);
            $em->persist($audit);
            $em->flush();
        }

        return $this->handleResponse($request, true, sprintf('Guía "%s" eliminada correctamente.', $title));
    }

    // =========================================================================
    // SUBMÓDULO 2: PROCEDIMIENTOS (AGENTES) - RUNBOOKS & AGENTE IA N8N
    // =========================================================================

    /**
     * Vista principal de Procedimientos (Agentes): Runbooks internos y asistencia IA n8n.
     */
    #[Route('/procedures', name: 'app_guide_procedures_index', methods: ['GET'])]
    public function proceduresIndex(
        Request $request,
        WikiArticleRepository $wikiArticleRepository,
        WikiCategoryRepository $wikiCategoryRepository,
    ): Response {
        /** @var User|null $currentUser */
        $currentUser = $this->getUser();
        if (!$currentUser) {
            return $this->redirectToRoute('app_login');
        }

        $allCategories = $wikiCategoryRepository->findAll();
        // Procedimientos internos para agentes (isPublic = false)
        $procedures = $wikiArticleRepository->findBy(['isPublic' => false], ['createdAt' => 'DESC']);

        // Estadísticas de Procedimientos (KPIs)
        $totalProcedures = count($procedures);
        $totalExecutions = array_reduce($procedures, fn($sum, $p) => $sum + $p->getViewsCount(), 0);

        $now = new \DateTimeImmutable();
        $startOfMonth = $now->setDate((int) $now->format('Y'), (int) $now->format('m'), 1)->setTime(0, 0, 0);
        $newThisMonth = 0;
        foreach ($procedures as $p) {
            if ($p->getCreatedAt() && $p->getCreatedAt() >= $startOfMonth) {
                $newThisMonth++;
            }
        }

        return $this->render('guide/procedures/index.html.twig', [
            'procedures' => $procedures,
            'categories' => $allCategories,
            'stats' => [
                'totalProcedures' => $totalProcedures,
                'totalCategories' => count($allCategories),
                'totalExecutions' => $totalExecutions,
                'newThisMonth' => $newThisMonth,
            ],
        ]);
    }

    /**
     * Detalle AJAX de procedimiento para agentes.
     */
    #[Route('/procedures/{id}/detail', name: 'app_guide_procedures_detail', methods: ['GET'])]
    public function proceduresDetail(
        string $id,
        WikiArticleRepository $wikiArticleRepository,
        EntityManagerInterface $em,
    ): JsonResponse {
        try {
            $uuid = Uuid::fromString($id);
            $article = $wikiArticleRepository->find($uuid);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['success' => false, 'message' => 'Identificador inválido.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$article || $article->isPublic()) {
            return new JsonResponse(['success' => false, 'message' => 'Procedimiento no encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $article->incrementViews();
        $em->flush();

        return new JsonResponse([
            'success' => true,
            'procedure' => [
                'id' => (string) $article->getId(),
                'title' => $article->getTitle(),
                'content' => $article->getContent(),
                'viewsCount' => $article->getViewsCount(),
                'createdAt' => $article->getCreatedAt()?->format('d/m/Y H:i') ?? '-',
                'category' => $article->getCategory() ? [
                    'id' => $article->getCategory()->getId(),
                    'name' => $article->getCategory()->getName(),
                ] : null,
                'author' => $article->getAuthor() ? [
                    'name' => $article->getAuthor()->getName() ?? $article->getAuthor()->getUserIdentifier(),
                ] : null,
            ],
        ]);
    }

    /**
     * Crear nuevo procedimiento para agentes.
     */
    #[Route('/procedures/create', name: 'app_guide_procedures_create', methods: ['POST'])]
    public function proceduresCreate(
        Request $request,
        WikiCategoryRepository $categoryRepository,
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
        if (!$csrfManager->isTokenValid(new CsrfToken('create_procedure', $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.', redirectRoute: 'app_guide_procedures_index');
        }

        $title = trim((string) $request->request->get('title'));
        $content = trim((string) $request->request->get('content'));
        $categoryId = (int) $request->request->get('category_id');
        $newCategoryName = trim((string) $request->request->get('new_category_name'));

        if ($title === '' || $content === '') {
            return $this->handleResponse($request, false, 'El título y el runbook/contenido son obligatorios.', redirectRoute: 'app_guide_procedures_index');
        }

        $tenant = $currentUser->getTenant() ?? $tenantRepository->findOneBy([]);
        if (!$tenant) {
            return $this->handleResponse($request, false, 'No existe una organización activa asignada.', redirectRoute: 'app_guide_procedures_index');
        }

        $category = null;
        if ($newCategoryName !== '') {
            $category = new WikiCategory();
            $category->setName($newCategoryName);
            $category->setTenant($tenant);
            $em->persist($category);
        } elseif ($categoryId > 0) {
            $category = $categoryRepository->find($categoryId);
        }

        if (!$category) {
            return $this->handleResponse($request, false, 'Debe seleccionar o especificar una categoría técnica.', redirectRoute: 'app_guide_procedures_index');
        }

        $article = new WikiArticle();
        $article->setTitle($title);
        $article->setContent($content);
        $article->setCategory($category);
        $article->setTenant($tenant);
        $article->setAuthor($currentUser);
        $article->setIsPublic(false); // Procedimiento interno para agentes
        $article->setViewsCount(0);

        $em->persist($article);
        $em->flush();

        $audit = new AuditLog();
        $audit->setTenant($tenant);
        $audit->setUser($currentUser);
        $audit->setAction('CREATE_PROCEDURE_ARTICLE');
        $audit->setEntityType('WikiArticle');
        $audit->setEntityId($article->getId());
        $audit->setNewValues(['title' => $title, 'category' => $category->getName()]);
        $em->persist($audit);
        $em->flush();

        return $this->handleResponse($request, true, sprintf('Procedimiento técnico "%s" creado con éxito.', $title), redirectRoute: 'app_guide_procedures_index');
    }

    /**
     * Editar procedimiento existente.
     */
    #[Route('/procedures/{id}/edit', name: 'app_guide_procedures_edit', methods: ['POST'])]
    public function proceduresEdit(
        string $id,
        Request $request,
        WikiArticleRepository $wikiArticleRepository,
        WikiCategoryRepository $categoryRepository,
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
            $article = $wikiArticleRepository->find($uuid);
        } catch (\InvalidArgumentException) {
            return $this->handleResponse($request, false, 'Identificador de procedimiento inválido.', redirectRoute: 'app_guide_procedures_index');
        }

        if (!$article) {
            return $this->handleResponse($request, false, 'El procedimiento no existe o fue eliminado.', redirectRoute: 'app_guide_procedures_index');
        }

        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('edit_procedure_' . $id, $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.', redirectRoute: 'app_guide_procedures_index');
        }

        $title = trim((string) $request->request->get('title'));
        $content = trim((string) $request->request->get('content'));
        $categoryId = (int) $request->request->get('category_id');

        if ($title === '' || $content === '') {
            return $this->handleResponse($request, false, 'El título y el contenido son requeridos.', redirectRoute: 'app_guide_procedures_index');
        }

        if ($categoryId > 0) {
            $category = $categoryRepository->find($categoryId);
            if ($category) {
                $article->setCategory($category);
            }
        }

        $article->setTitle($title);
        $article->setContent($content);
        $em->flush();

        if ($article->getTenant()) {
            $audit = new AuditLog();
            $audit->setTenant($article->getTenant());
            $audit->setUser($currentUser);
            $audit->setAction('UPDATE_PROCEDURE_ARTICLE');
            $audit->setEntityType('WikiArticle');
            $audit->setEntityId($article->getId());
            $audit->setNewValues(['title' => $title]);
            $em->persist($audit);
            $em->flush();
        }

        return $this->handleResponse($request, true, sprintf('Procedimiento "%s" actualizado correctamente.', $title), redirectRoute: 'app_guide_procedures_index');
    }

    /**
     * Eliminar procedimiento técnico.
     */
    #[Route('/procedures/{id}/delete', name: 'app_guide_procedures_delete', methods: ['POST'])]
    public function proceduresDelete(
        string $id,
        Request $request,
        WikiArticleRepository $wikiArticleRepository,
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
            $article = $wikiArticleRepository->find($uuid);
        } catch (\InvalidArgumentException) {
            return $this->handleResponse($request, false, 'Identificador de procedimiento inválido.', redirectRoute: 'app_guide_procedures_index');
        }

        if (!$article) {
            return $this->handleResponse($request, false, 'El procedimiento no existe o ya fue eliminado.', redirectRoute: 'app_guide_procedures_index');
        }

        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('delete_procedure_' . $id, $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.', redirectRoute: 'app_guide_procedures_index');
        }

        $title = $article->getTitle() ?? 'Sin título';
        $tenant = $article->getTenant();
        $articleId = $article->getId();

        $em->remove($article);
        $em->flush();

        if ($tenant) {
            $audit = new AuditLog();
            $audit->setTenant($tenant);
            $audit->setUser($currentUser);
            $audit->setAction('DELETE_PROCEDURE_ARTICLE');
            $audit->setEntityType('WikiArticle');
            $audit->setEntityId($articleId);
            $audit->setOldValues(['title' => $title]);
            $em->persist($audit);
            $em->flush();
        }

        return $this->handleResponse($request, true, sprintf('Procedimiento "%s" eliminado correctamente.', $title), redirectRoute: 'app_guide_procedures_index');
    }

    /**
     * Respuesta unificada para Turbo/Fetch y envíos tradicionales.
     */
    private function handleResponse(
        Request $request,
        bool $success,
        string $message,
        int $status = Response::HTTP_OK,
        string $redirectRoute = 'app_guide_self_service_index'
    ): Response {
        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse(['success' => $success, 'message' => $message], $success ? $status : Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash($success ? 'success' : 'error', $message);

        return $this->redirectToRoute($redirectRoute);
    }
}
