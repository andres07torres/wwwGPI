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
        TenantRepository $tenantRepository,
        EntityManagerInterface $em,
    ): Response {
        /** @var User|null $currentUser */
        $currentUser = $this->getUser();
        if (!$currentUser) {
            return $this->redirectToRoute('app_login');
        }

        // Obtener o inicializar categorías si la base de datos está vacía para autoservicio
        $allCategories = $wikiCategoryRepository->findAll();
        if (empty($allCategories)) {
            $tenant = $currentUser->getTenant() ?? $tenantRepository->findOneBy([]);
            if ($tenant) {
                $defaultCats = [
                    ['name' => 'Acceso y Contraseñas', 'desc' => 'Recuperación de cuentas, doble factor (2FA) y credenciales.'],
                    ['name' => 'Conectividad y Redes', 'desc' => 'Configuración de VPN, Wi-Fi corporativo y resolución de caídas de red.'],
                    ['name' => 'Software y Aplicaciones', 'desc' => 'Instalación de paquetes, suite ofimática y licenciamiento.'],
                    ['name' => 'Hardware y Equipos', 'desc' => 'Periféricos, estaciones de trabajo y solicitudes de periféricos.'],
                    ['name' => 'Seguridad Informática', 'desc' => 'Reporte de phishing, antivirus y buenas prácticas de protección.'],
                ];
                foreach ($defaultCats as $catData) {
                    $cat = new WikiCategory();
                    $cat->setName($catData['name']);
                    $cat->setDescription($catData['desc']);
                    $cat->setTenant($tenant);
                    $em->persist($cat);
                }
                $em->flush();
                $allCategories = $wikiCategoryRepository->findAll();
            }
        }

        // Artículos públicos (Autoservicio para clientes)
        $publicArticles = $wikiArticleRepository->findBy(['isPublic' => true], ['createdAt' => 'DESC']);

        // Si no hay artículos, crear guías base iniciales para Autoservicio
        if (empty($publicArticles) && !empty($allCategories)) {
            $tenant = $currentUser->getTenant() ?? $tenantRepository->findOneBy([]);
            if ($tenant) {
                $sampleArticles = [
                    [
                        'title' => '¿Cómo restablecer mi contraseña institucional?',
                        'category' => $allCategories[0],
                        'content' => "Para restablecer su contraseña:\n\n1. Diríjase a la pantalla de inicio de sesión de GPI.\n2. Haga clic en '¿Olvidó su contraseña?'.\n3. Ingrese su correo corporativo institucional.\n4. Revise su bandeja de entrada (y carpeta de spam) donde recibirá un enlace temporal válido por 15 minutos.\n5. Defina su nueva contraseña cumpliendo con mínimo 10 caracteres, una mayúscula, un número y un símbolo.\n\nEn caso de no recibir el enlace, por favor abra un Ticket en la Mesa de Ayuda.",
                        'views' => 42,
                    ],
                    [
                        'title' => 'Guía de conexión a la VPN Corporativa (Fortinet / OpenVPN)',
                        'category' => $allCategories[1] ?? $allCategories[0],
                        'content' => "Pasos para conectar al entorno seguro de la organización:\n\n1. Inicie la aplicación FortiClient o cliente VPN autorizado en su estación de trabajo.\n2. Ingrese la dirección del gateway VPN: vpn.gpi-infra.corp.\n3. Utilice sus credenciales corporativas (usuario y clave).\n4. Confirme el código de verificación del autenticador (2FA/OTP).\n5. Verifique que el estado figure en 'Conectado - 100%'.\n\nSi experimenta problemas de latencia o corte de túnel, reinicie su adaptador de red antes de generar un incidente.",
                        'views' => 89,
                    ],
                    [
                        'title' => 'Configuración de correo electrónico en dispositivos móviles',
                        'category' => $allCategories[2] ?? $allCategories[0],
                        'content' => "Siga estos pasos para configurar su buzón en Android o iOS:\n\n1. Abra la app oficial de correo (Outlook o Mail nativo).\n2. Seleccione 'Agregar cuenta de Exchange / Office 365'.\n3. Ingrese su dirección corporativa completa y su contraseña.\n4. Acepte los permisos de seguridad y cifrado del dispositivo requeridos por la política de la empresa.\n5. Espere a que finalice la sincronización inicial de carpetas y calendario.",
                        'views' => 27,
                    ],
                ];

                foreach ($sampleArticles as $artData) {
                    $art = new WikiArticle();
                    $art->setTitle($artData['title']);
                    $art->setContent($artData['content']);
                    $art->setCategory($artData['category']);
                    $art->setTenant($tenant);
                    $art->setAuthor($currentUser);
                    $art->setIsPublic(true);
                    $art->setViewsCount($artData['views']);
                    $em->persist($art);
                }
                $em->flush();
                $publicArticles = $wikiArticleRepository->findBy(['isPublic' => true], ['createdAt' => 'DESC']);
            }
        }

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
