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
        TenantRepository $tenantRepository,
        EntityManagerInterface $em,
    ): Response {
        /** @var User|null $currentUser */
        $currentUser = $this->getUser();
        if (!$currentUser) {
            return $this->redirectToRoute('app_login');
        }

        $allCategories = $wikiCategoryRepository->findAll();
        // Si no existen categorías técnicas específicas, asegurar su creación
        $technicalCatNames = [
            'Infraestructura & Redes',
            'DevOps & Servidores',
            'Bases de Datos & Backup',
            'Ciberseguridad & SOC',
            'Soporte Técnico Especializado',
        ];
        $existingCatNames = array_map(fn($c) => $c->getName(), $allCategories);
        $tenant = $currentUser->getTenant() ?? $tenantRepository->findOneBy([]);

        if ($tenant) {
            $createdCat = false;
            foreach ($technicalCatNames as $name) {
                if (!in_array($name, $existingCatNames, true)) {
                    $cat = new WikiCategory();
                    $cat->setName($name);
                    $cat->setDescription('Procedimientos técnicos y protocolos de ' . $name);
                    $cat->setTenant($tenant);
                    $em->persist($cat);
                    $createdCat = true;
                }
            }
            if ($createdCat) {
                $em->flush();
                $allCategories = $wikiCategoryRepository->findAll();
            }
        }

        // Procedimientos internos para agentes (isPublic = false)
        $procedures = $wikiArticleRepository->findBy(['isPublic' => false], ['createdAt' => 'DESC']);

        // Sembrar procedimientos técnicos de ejemplo si no existen
        if (empty($procedures) && !empty($allCategories) && $tenant) {
            $catMap = [];
            foreach ($allCategories as $c) {
                $catMap[$c->getName()] = $c;
            }

            $sampleProcedures = [
                [
                    'title' => 'Procedimiento de Mitigación de Caída de Enlace WAN / Red Principal',
                    'catName' => 'Infraestructura & Redes',
                    'content' => "## 1. Alcance y Detección\nAplicable ante pérdida de conectividad perimetral reportada por sondas SNMP o alerta de Zabbix.\n\n## 2. Diagnóstico Rápido\n1. Validar estado de interfaces en Switch Core: `show interfaces status | include WAN`.\n2. Ejecutar ping bidireccional hacia DNS público (1.1.1.1 / 8.8.8.8).\n3. Comprobar tabla de rutas BGP: `show ip bgp summary`.\n\n## 3. Conmutación a Enlace Secundario (Failover)\n1. Si la latencia supera 350ms o packet loss > 40%, forzar switchover en FortiGate:\n   `config system virtual-wan-link` -> `set status failover-backup`.\n2. Validar sesión IPSec hacia nodo secundario.\n\n## 4. Registro y Notificación\nNotificar al canal de guardia NOC y registrar el evento en el ticket correspondiente.",
                    'views' => 64,
                ],
                [
                    'title' => 'Protocolo de Rollback y Despliegue de Emergencia en Producción',
                    'catName' => 'DevOps & Servidores',
                    'content' => "## 1. Criterios de Aborto\nActivar inmediatamente si tras el despliegue se detecta tasa de errores HTTP 5xx > 2% o indisponibilidad de endpoint crítico durante 3 minutos consecutivos.\n\n## 2. Pasos de Reversión\n1. Conectar al orquestador Kubernetes o servidor host.\n2. Localizar versión estable previa: `helm history gpi-core -n prod`.\n3. Ejecutar rollback inmediato:\n   `helm rollback gpi-core <REVISION_ID> -n prod`.\n4. Si el despliegue es Docker Compose tradicional:\n   `docker compose -f docker-compose.prod.yml down`\n   `docker compose -f docker-compose.prod.yml up -d --build` con tag estable.\n\n## 3. Verificación de Salud\nVerificar endpoint `/api/health` y validar respuesta HTTP 200 con `{\"status\":\"healthy\"}`.\n\n## 4. Registro CAB\nRegistrar evento en el módulo de Gestión de Cambios (RFC de Emergencia).",
                    'views' => 91,
                ],
                [
                    'title' => 'Depuración y Rotación de Logs en Servidores de Base de Datos',
                    'catName' => 'Bases de Datos & Backup',
                    'content' => "## 1. Diagnóstico de Espacio en Disco\n1. Comprobar uso de partición `/var/lib/mysql` o `/var/log`:\n   `df -h /var/log`\n2. Identificar archivos de log sobredimensionados:\n   `ls -lhS /var/log/mysql/*.log | head -n 5`\n\n## 2. Purgado Seguro de Binary Logs\n1. Conectar a consola MySQL como DBA:\n   `PURGE BINARY LOGS BEFORE NOW() - INTERVAL 3 DAY;`\n2. Forzar rotación de logrotate del sistema operativo:\n   `logrotate -f /etc/logrotate.d/mysql-server`\n\n## 3. Verificación de Réplica\nValidar que la réplica no se encuentre retrasada tras la rotación:\n`SHOW REPLICA STATUS\\G;` (Seconds_Behind_Source = 0).",
                    'views' => 38,
                ],
                [
                    'title' => 'Aislamiento de Endpoint y Mitigación de Ransomware / Malware',
                    'catName' => 'Ciberseguridad & SOC',
                    'content' => "## 1. Identificación y Aislamiento Inmediato\n1. En consola EDR/Antivirus, activar comando 'Network Containment / Isolation' para el host comprometido.\n2. Si el host es virtual (Proxmox/VMware): desconectar virtual vNIC de inmediato.\n\n## 2. Preservación de Evidencia\n1. Extraer volcado de memoria volátil antes de apagar el equipo: `winpmem.exe -o memdump.raw`.\n2. Tomar snapshot forense en frío del disco virtual.\n\n## 3. Revocación de Credenciales\n1. Bloquear usuario en Directorio Activo / LDAP y resetear sesión SSO.\n2. Revocar tokens de API y llaves SSH activas del usuario afectado.\n\n## 4. Notificación y Escalamiento\nAlertar de inmediato al equipo de Seguridad Informática / CSIRT y clasificar como incidente P1.",
                    'views' => 52,
                ],
            ];

            foreach ($sampleProcedures as $pData) {
                $category = $catMap[$pData['catName']] ?? $allCategories[0];
                $art = new WikiArticle();
                $art->setTitle($pData['title']);
                $art->setContent($pData['content']);
                $art->setCategory($category);
                $art->setTenant($tenant);
                $art->setAuthor($currentUser);
                $art->setIsPublic(false); // Procedimiento interno para agentes
                $art->setViewsCount($pData['views']);
                $em->persist($art);
            }
            $em->flush();
            $procedures = $wikiArticleRepository->findBy(['isPublic' => false], ['createdAt' => 'DESC']);
        }

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
