<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CiCategory;
use App\Entity\CiItem;
use App\Entity\CiRelationship;
use App\Entity\SlaPolicy;
use App\Entity\Tenant;
use App\Entity\Ticket;
use App\Entity\TicketPriority;
use App\Entity\TicketSla;
use App\Entity\TicketStatus;
use App\Entity\User;
use App\Enum\CiCategoryType;
use App\Enum\CiItemStatus;
use App\Enum\CiRelationshipType;
use App\Enum\TicketType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-demo-data',
    description: 'Genera 10 registros de prueba en Infraestructura & Activos (con relaciones de topología) y 10 tickets en Mesa de Ayuda.',
)]
final class SeedDemoDataCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Generando datos de demostración para GPI');

        // 1. Obtener Tenant y Usuario base
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy([]);
        if (!$tenant) {
            $io->error('No se encontró ningún Tenant en el sistema.');
            return Command::FAILURE;
        }

        $user = $this->em->getRepository(User::class)->findOneBy([]) ?? $this->em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        if (!$user) {
            $io->error('No se encontró ningún usuario administrador en el sistema.');
            return Command::FAILURE;
        }

        // Asegurar Categorías de CI por defecto
        $categoryRepo = $this->em->getRepository(CiCategory::class);
        $defaultCategories = [
            ['name' => 'Servidores en Rack', 'type' => CiCategoryType::FISICO],
            ['name' => 'Laptops y Estaciones de Trabajo', 'type' => CiCategoryType::FISICO],
            ['name' => 'Equipos de Red (Switches / Routers)', 'type' => CiCategoryType::FISICO],
            ['name' => 'Almacenamiento (SAN / NAS)', 'type' => CiCategoryType::FISICO],
            ['name' => 'Periféricos e Impresoras', 'type' => CiCategoryType::FISICO],
            ['name' => 'Sistemas Operativos', 'type' => CiCategoryType::LOGICO],
            ['name' => 'Bases de Datos', 'type' => CiCategoryType::LOGICO],
            ['name' => 'Aplicaciones y Servicios Web', 'type' => CiCategoryType::LOGICO],
            ['name' => 'Servicios Cloud e Infraestructura', 'type' => CiCategoryType::LOGICO],
            ['name' => 'Licencias y Certificados SSL', 'type' => CiCategoryType::LOGICO],
        ];

        foreach ($defaultCategories as $cData) {
            $cat = $categoryRepo->findOneBy(['name' => $cData['name']]);
            if (!$cat) {
                $cat = new CiCategory();
                $cat->setName($cData['name']);
                $cat->setType($cData['type']);
                $cat->setTenant($tenant);
                $this->em->persist($cat);
            }
        }
        $this->em->flush();

        $catServidores = $categoryRepo->findOneBy(['name' => 'Servidores en Rack']);
        $catRed = $categoryRepo->findOneBy(['name' => 'Equipos de Red (Switches / Routers)']);
        $catStorage = $categoryRepo->findOneBy(['name' => 'Almacenamiento (SAN / NAS)']);
        $catWorkstations = $categoryRepo->findOneBy(['name' => 'Laptops y Estaciones de Trabajo']);
        $catApps = $categoryRepo->findOneBy(['name' => 'Aplicaciones y Servicios Web']);
        $catDB = $categoryRepo->findOneBy(['name' => 'Bases de Datos']);
        $catCloud = $categoryRepo->findOneBy(['name' => 'Servicios Cloud e Infraestructura']);
        $catLic = $categoryRepo->findOneBy(['name' => 'Licencias y Certificados SSL']);

        // 2. Crear 10 Activos de Infraestructura
        $ciDefinitions = [
            [
                'name' => 'SRV-CORE-PROD01',
                'category' => $catServidores,
                'status' => CiItemStatus::ACTIVO,
                'ip' => '192.168.1.10',
                'mac' => '00:1A:2B:3C:4D:01',
                'serial' => 'SN-SRV-9011',
                'location' => 'Data Center Principal - Rack A, U10',
                'description' => 'Servidor Dell PowerEdge R750 con 2x Xeon Gold, 128GB RAM para cargas productivas.',
            ],
            [
                'name' => 'SW-CORE-CISCO01',
                'category' => $catRed,
                'status' => CiItemStatus::ACTIVO,
                'ip' => '192.168.1.1',
                'mac' => '00:1A:2B:3C:4D:02',
                'serial' => 'SN-SW-4081',
                'location' => 'Data Center Principal - Rack A, U01',
                'description' => 'Switch Core Cisco Catalyst 9300 48 puertos PoE+ enlace 10Gbps fibra.',
            ],
            [
                'name' => 'FW-FORTINET-EDGE',
                'category' => $catRed,
                'status' => CiItemStatus::ACTIVO,
                'ip' => '192.168.1.254',
                'mac' => '00:1A:2B:3C:4D:03',
                'serial' => 'SN-FW-1022',
                'location' => 'Borde Perimetral - Rack A, U03',
                'description' => 'Firewall FortiGate 100F con filtrado UTM, VPN IPSec y prevención de intrusiones.',
            ],
            [
                'name' => 'SAN-DELL-STORAGE',
                'category' => $catStorage,
                'status' => CiItemStatus::ACTIVO,
                'ip' => '192.168.1.20',
                'mac' => '00:1A:2B:3C:4D:04',
                'serial' => 'SN-SAN-7744',
                'location' => 'Data Center Principal - Rack B, U05',
                'description' => 'Cabina de almacenamiento SAN Dell ME4024 con 24TB en discos SAS SSD y NVMe.',
            ],
            [
                'name' => 'WS-FINANCE-01',
                'category' => $catWorkstations,
                'status' => CiItemStatus::ACTIVO,
                'ip' => '192.168.20.15',
                'mac' => '00:1A:2B:3C:4D:05',
                'serial' => 'SN-DELL-5520',
                'location' => 'Piso 2 - Oficina de Contabilidad y Nómina',
                'description' => 'Estación de trabajo Dell OptiPlex 7090 i7 32GB RAM asignada a contabilidad.',
            ],
            [
                'name' => 'VM-PROD-APP01',
                'category' => $catApps,
                'status' => CiItemStatus::ACTIVO,
                'ip' => '192.168.10.15',
                'mac' => '00:50:56:9A:1B:01',
                'serial' => 'LIC-VMW-01',
                'location' => 'Cluster Virtual VMware vSphere ESXi',
                'description' => 'Máquina Virtual Ubuntu Server 24.04 ejecutando el backend transaccional de GPI.',
            ],
            [
                'name' => 'VM-PROD-APP02',
                'category' => $catApps,
                'status' => CiItemStatus::MANTENIMIENTO,
                'ip' => '192.168.10.16',
                'mac' => '00:50:56:9A:1B:02',
                'serial' => 'LIC-VMW-02',
                'location' => 'Cluster Virtual VMware vSphere ESXi',
                'description' => 'Instancia secundaria balanceada, actualmente en aplicación de parches de seguridad.',
            ],
            [
                'name' => 'DB-CLUSTER-MYSQL',
                'category' => $catDB,
                'status' => CiItemStatus::ACTIVO,
                'ip' => '192.168.10.20',
                'mac' => '00:50:56:9A:1B:03',
                'serial' => 'LIC-DB-9901',
                'location' => 'Cluster Virtual VMware vSphere ESXi',
                'description' => 'Instancia de base de datos MySQL 8.4 Enterprise con replicación maestro-esclavo.',
            ],
            [
                'name' => 'API-GATEWAY-SVC',
                'category' => $catCloud,
                'status' => CiItemStatus::ACTIVO,
                'ip' => '192.168.10.50',
                'mac' => '00:50:56:9A:1B:04',
                'serial' => 'LIC-KONG-01',
                'location' => 'Cluster Virtual VMware vSphere ESXi',
                'description' => 'Enrutador perimetral de APIs y microservicios con autenticación OAuth2 y rate limiting.',
            ],
            [
                'name' => 'SSL-CERT-WILDCARD',
                'category' => $catLic,
                'status' => CiItemStatus::ACTIVO,
                'ip' => null,
                'mac' => null,
                'serial' => 'CRT-2026-EXP90',
                'location' => 'Almacén de Certificados Servidor Edge',
                'description' => 'Certificado de cifrado TLS/SSL *.gpi.local emitido por DigiCert con validez de 1 año.',
            ],
        ];

        $ciEntities = [];
        $ciRepo = $this->em->getRepository(CiItem::class);

        foreach ($ciDefinitions as $def) {
            $item = $ciRepo->findOneBy(['name' => $def['name']]);
            if (!$item) {
                $item = new CiItem();
                $item->setName($def['name']);
                $item->setCategory($def['category']);
                $item->setTenant($tenant);
                $item->setStatus($def['status']);
                $item->setIpAddress($def['ip']);
                $item->setMacAddress($def['mac']);
                $item->setSerialNumber($def['serial']);
                $item->setLocation($def['location']);
                $item->setDescription($def['description']);
                $this->em->persist($item);
            }
            $ciEntities[$def['name']] = $item;
        }
        $this->em->flush();

        $io->success(sprintf('Se procesaron %d activos de infraestructura.', count($ciEntities)));

        // 3. Crear 9 Relaciones de Topología
        $relRepo = $this->em->getRepository(CiRelationship::class);
        $relDefinitions = [
            ['parent' => 'SW-CORE-CISCO01', 'child' => 'FW-FORTINET-EDGE', 'type' => CiRelationshipType::CONNECTED_TO],
            ['parent' => 'SRV-CORE-PROD01', 'child' => 'SW-CORE-CISCO01', 'type' => CiRelationshipType::CONNECTED_TO],
            ['parent' => 'SAN-DELL-STORAGE', 'child' => 'SRV-CORE-PROD01', 'type' => CiRelationshipType::CONNECTED_TO],
            ['parent' => 'VM-PROD-APP01', 'child' => 'SRV-CORE-PROD01', 'type' => CiRelationshipType::HOSTED_ON],
            ['parent' => 'VM-PROD-APP02', 'child' => 'SRV-CORE-PROD01', 'type' => CiRelationshipType::HOSTED_ON],
            ['parent' => 'DB-CLUSTER-MYSQL', 'child' => 'VM-PROD-APP01', 'type' => CiRelationshipType::HOSTED_ON],
            ['parent' => 'API-GATEWAY-SVC', 'child' => 'VM-PROD-APP02', 'type' => CiRelationshipType::HOSTED_ON],
            ['parent' => 'API-GATEWAY-SVC', 'child' => 'DB-CLUSTER-MYSQL', 'type' => CiRelationshipType::DEPENDS_ON],
            ['parent' => 'API-GATEWAY-SVC', 'child' => 'SSL-CERT-WILDCARD', 'type' => CiRelationshipType::DEPENDS_ON],
            ['parent' => 'WS-FINANCE-01', 'child' => 'SW-CORE-CISCO01', 'type' => CiRelationshipType::CONNECTED_TO],
        ];

        $createdRels = 0;
        foreach ($relDefinitions as $rDef) {
            $parent = $ciEntities[$rDef['parent']] ?? null;
            $child = $ciEntities[$rDef['child']] ?? null;
            if ($parent && $child) {
                $exists = $relRepo->findOneBy([
                    'parentCi' => $parent,
                    'childCi' => $child,
                    'relationshipType' => $rDef['type'],
                ]);
                if (!$exists) {
                    $rel = new CiRelationship();
                    $rel->setParentCi($parent);
                    $rel->setChildCi($child);
                    $rel->setRelationshipType($rDef['type']);
                    $this->em->persist($rel);
                    $createdRels++;
                }
            }
        }
        $this->em->flush();
        $io->success(sprintf('Se crearon %d conexiones de topología.', $createdRels));

        // 4. Crear 10 Tickets en Mesa de Ayuda
        $statusRepo = $this->em->getRepository(TicketStatus::class);
        $prioRepo = $this->em->getRepository(TicketPriority::class);

        $statusesConfig = [
            ['name' => 'Nuevo', 'order' => 1, 'closed' => false],
            ['name' => 'En Proceso', 'order' => 2, 'closed' => false],
            ['name' => 'En Espera', 'order' => 3, 'closed' => false],
            ['name' => 'Resuelto', 'order' => 4, 'closed' => true],
            ['name' => 'Cerrado', 'order' => 5, 'closed' => true],
        ];

        foreach ($statusesConfig as $config) {
            $st = $statusRepo->findOneBy(['name' => $config['name']]);
            if (!$st) {
                $st = new TicketStatus();
                $st->setTenant($tenant);
                $st->setName($config['name']);
                $st->setOrderIndex($config['order']);
                $st->setIsClosedState($config['closed']);
                $this->em->persist($st);
            }
        }

        $prioritiesConfig = [
            ['name' => 'Baja', 'level' => 1, 'color' => '#10b981'],
            ['name' => 'Media', 'level' => 2, 'color' => '#3b82f6'],
            ['name' => 'Alta', 'level' => 3, 'color' => '#f59e0b'],
            ['name' => 'Crítica', 'level' => 4, 'color' => '#ef4444'],
        ];

        foreach ($prioritiesConfig as $pConfig) {
            $pr = $prioRepo->findOneBy(['name' => $pConfig['name']]);
            if (!$pr) {
                $pr = new TicketPriority();
                $pr->setTenant($tenant);
                $pr->setName($pConfig['name']);
                $pr->setLevel($pConfig['level']);
                $pr->setColor($pConfig['color']);
                $this->em->persist($pr);
            }
        }
        $this->em->flush();

        $stNuevo = $statusRepo->findOneBy(['name' => 'Nuevo']);
        $stEnProceso = $statusRepo->findOneBy(['name' => 'En Proceso']) ?? $stNuevo;
        $stEnEspera = $statusRepo->findOneBy(['name' => 'En Espera']) ?? $stNuevo;
        $stResuelto = $statusRepo->findOneBy(['name' => 'Resuelto']) ?? $stNuevo;
        $stCerrado = $statusRepo->findOneBy(['name' => 'Cerrado']) ?? $stNuevo;

        $prBaja = $prioRepo->findOneBy(['name' => 'Baja']);
        $prMedia = $prioRepo->findOneBy(['name' => 'Media']) ?? $prBaja;
        $prAlta = $prioRepo->findOneBy(['name' => 'Alta']) ?? $prBaja;
        $prCritica = $prioRepo->findOneBy(['name' => 'Crítica']) ?? $prAlta;

        $policyRepo = $this->em->getRepository(SlaPolicy::class);
        $policiesConfig = [
            ['name' => 'SLA Crítico (Incidentes Mayores)', 'priority' => $prCritica, 'resp' => 15, 'resol' => 120],
            ['name' => 'SLA Alto (Servicios Principales)', 'priority' => $prAlta, 'resp' => 30, 'resol' => 240],
            ['name' => 'SLA Medio (Operación Regular)', 'priority' => $prMedia, 'resp' => 60, 'resol' => 480],
            ['name' => 'SLA Bajo (Requerimientos Menores)', 'priority' => $prBaja, 'resp' => 120, 'resol' => 1440],
        ];

        $policiesMap = [];
        foreach ($policiesConfig as $pConfig) {
            $pol = $policyRepo->findOneBy(['priority' => $pConfig['priority']]);
            if (!$pol) {
                $pol = new SlaPolicy();
                $pol->setName($pConfig['name']);
                $pol->setPriority($pConfig['priority']);
                $pol->setTenant($tenant);
                $pol->setResponseTimeMinutes($pConfig['resp']);
                $pol->setResolutionTimeMinutes($pConfig['resol']);
                $pol->setBusinessHoursOnly(true);
                $this->em->persist($pol);
            }
            if ($pConfig['priority']) {
                $policiesMap[$pConfig['priority']->getName()] = $pol;
            }
        }
        $this->em->flush();

        $ticketDefinitions = [
            [
                'code' => 'INC-2026-0004',
                'type' => TicketType::INCIDENTE,
                'title' => 'Falla en ventilador de fuente redundante de Switch Core',
                'description' => 'La alerta SNMP indica que la fuente secundaria PSU-2 del switch Cisco se encuentra en estado de advertencia.',
                'status' => $stNuevo,
                'priority' => $prAlta,
                'ci' => 'SW-CORE-CISCO01',
                'minutesAgo' => 25,
            ],
            [
                'code' => 'REQ-2026-0005',
                'type' => TicketType::PETICION,
                'title' => 'Renovación y despliegue preventivo de certificado SSL comodín',
                'description' => 'El certificado *.gpi.local expira en 30 días. Se requiere programar la instalación del nuevo certificado emitido.',
                'status' => $stNuevo,
                'priority' => $prBaja,
                'ci' => 'SSL-CERT-WILDCARD',
                'minutesAgo' => 105,
            ],
            [
                'code' => 'INC-2026-0010',
                'type' => TicketType::INCIDENTE,
                'title' => 'Reinicio inesperado del servicio de notificaciones push',
                'description' => 'El daemon de mensajería asíncrona se detuvo por timeout de conexión al broker. Reiniciado con supervisión activa.',
                'status' => $stEnProceso,
                'priority' => $prMedia,
                'ci' => 'VM-PROD-APP01',
                'minutesAgo' => 195,
            ],
            [
                'code' => 'INC-2026-0002',
                'type' => TicketType::INCIDENTE,
                'title' => 'Latencia elevada en el Gateway de APIs principales',
                'description' => 'Los endpoints de facturación electrónica presentan tiempos de respuesta superiores a 2.5 segundos.',
                'status' => $stEnProceso,
                'priority' => $prAlta,
                'ci' => 'API-GATEWAY-SVC',
                'minutesAgo' => 170,
            ],
            [
                'code' => 'REQ-2026-0003',
                'type' => TicketType::PETICION,
                'title' => 'Solicitud de ampliación de memoria RAM para VM-PROD-APP02',
                'description' => 'Requerimiento de aumento de 16GB a 32GB de RAM para soportar la nueva versión del módulo de reportería.',
                'status' => $stEnEspera,
                'priority' => $prMedia,
                'ci' => 'VM-PROD-APP02',
                'minutesAgo' => 430,
            ],
            [
                'code' => 'INC-2026-0001',
                'type' => TicketType::INCIDENTE,
                'title' => 'Caída intermitente en conexión de base de datos MySQL',
                'description' => 'Se reportan timeouts ocasionales en las consultas pesadas del cluster DB-CLUSTER-MYSQL durante el cierre contable.',
                'status' => $stEnProceso,
                'priority' => $prCritica,
                'ci' => 'DB-CLUSTER-MYSQL',
                'minutesAgo' => 195,
            ],
            [
                'code' => 'INC-2026-0006',
                'type' => TicketType::INCIDENTE,
                'title' => 'Espacio en disco al 92% en volumen LUN-01 de almacenamiento SAN',
                'description' => 'El pool de almacenamiento de backups superó el umbral de advertencia. Se ejecutó purga de snapshots antiguos.',
                'status' => $stResuelto,
                'priority' => $prAlta,
                'ci' => 'SAN-DELL-STORAGE',
                'minutesAgo' => 360,
                'resolvedInMinutes' => 120,
            ],
            [
                'code' => 'INC-2026-0008',
                'type' => TicketType::INCIDENTE,
                'title' => 'Pérdida de paquetes en interfaz WAN de Firewall perimetral',
                'description' => 'El enlace secundario presentó microcortes. Se verificó con el proveedor de fibra óptica y se estabilizó la línea.',
                'status' => $stResuelto,
                'priority' => $prCritica,
                'ci' => 'FW-FORTINET-EDGE',
                'minutesAgo' => 720,
                'resolvedInMinutes' => 60,
            ],
            [
                'code' => 'REQ-2026-0007',
                'type' => TicketType::PETICION,
                'title' => 'Mantenimiento preventivo de firmware en Servidor Rack 01',
                'description' => 'Actualización de BIOS y controladora iDRAC del servidor principal Dell PowerEdge completada en ventana nocturna.',
                'status' => $stCerrado,
                'priority' => $prMedia,
                'ci' => 'SRV-CORE-PROD01',
                'minutesAgo' => 1440,
                'resolvedInMinutes' => 180,
            ],
            [
                'code' => 'REQ-2026-0009',
                'type' => TicketType::PETICION,
                'title' => 'Instalación de software contable y cliente VPN en estación de finanzas',
                'description' => 'Configuración de acceso seguro remoto y certificación de firma electrónica para la contadora general.',
                'status' => $stCerrado,
                'priority' => $prBaja,
                'ci' => 'WS-FINANCE-01',
                'minutesAgo' => 2880,
                'resolvedInMinutes' => 300,
            ],
        ];

        $ticketRepo = $this->em->getRepository(Ticket::class);
        $processedTickets = 0;

        foreach ($ticketDefinitions as $tDef) {
            $ticket = $ticketRepo->findOneBy(['ticketCode' => $tDef['code']]);
            $createdDate = new \DateTimeImmutable(sprintf('-%d minutes', $tDef['minutesAgo']));

            if (!$ticket) {
                $ticket = new Ticket();
                $ticket->setTicketCode($tDef['code']);
                $ticket->setType($tDef['type']);
                $ticket->setTenant($tenant);
                $ticket->setRequester($user);
                $ticket->setAssignee($user);
            }

            $ticket->setTitle($tDef['title']);
            $ticket->setDescription($tDef['description']);
            $ticket->setStatus($tDef['status']);
            $ticket->setPriority($tDef['priority']);
            $ticket->setCreatedAt($createdDate);
            $ticket->setUpdatedAt($createdDate);

            if ($tDef['status'] === $stResuelto || $tDef['status'] === $stCerrado) {
                $ticket->setResolvedAt($createdDate->modify(sprintf('+%d minutes', $tDef['resolvedInMinutes'] ?? 120)));
            } else {
                $ticket->setResolvedAt(null);
            }

            // Asociar activo CI correspondiente
            if (isset($ciEntities[$tDef['ci']]) && !$ticket->getCiItems()->contains($ciEntities[$tDef['ci']])) {
                $ticket->addCiItem($ciEntities[$tDef['ci']]);
            }

            // Asociar o actualizar entidad TicketSla
            $ticketSla = $ticket->getTicketSlas()->first() ?: null;
            $matchingPolicy = $policiesMap[$tDef['priority']->getName()] ?? null;
            if (!$ticketSla && $matchingPolicy) {
                $ticketSla = new TicketSla();
                $ticketSla->setTicket($ticket);
                $ticketSla->setSlaPolicy($matchingPolicy);
            }
            if ($ticketSla && $matchingPolicy) {
                $respDue = $createdDate->modify(sprintf('+%d minutes', $matchingPolicy->getResponseTimeMinutes()));
                $resolDue = $createdDate->modify(sprintf('+%d minutes', $matchingPolicy->getResolutionTimeMinutes()));
                $ticketSla->setResponseDueAt($respDue);
                $ticketSla->setResolutionDueAt($resolDue);
                $ticketSla->setFirstResponseAt($createdDate->modify('+10 minutes'));
                if ($tDef['status'] === $stResuelto || $tDef['status'] === $stCerrado) {
                    $resolvedDate = $ticket->getResolvedAt() ?? $resolDue;
                    $ticketSla->setResolvedAt($resolvedDate);
                    $ticketSla->setResolutionBreached($resolvedDate > $resolDue);
                    $ticketSla->setResponseBreached($ticketSla->getFirstResponseAt() > $respDue);
                } else {
                    $ticketSla->setResolvedAt(null);
                    $now = new \DateTimeImmutable();
                    $ticketSla->setResponseBreached($now > $respDue && !$ticketSla->getFirstResponseAt());
                    $ticketSla->setResolutionBreached($now > $resolDue);
                }
                $this->em->persist($ticketSla);
            }

            $this->em->persist($ticket);
            $processedTickets++;
        }
        $this->em->flush();

        $io->success(sprintf('Se procesaron %d tickets en Mesa de Ayuda con tiempos relativos actualizados.', $processedTickets));

        return Command::SUCCESS;
    }
}
