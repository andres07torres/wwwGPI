<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AuditLog;
use App\Entity\Change;
use App\Entity\CiCategory;
use App\Entity\CiItem;
use App\Entity\CiRelationship;
use App\Entity\Permission;
use App\Entity\Problem;
use App\Entity\Role;
use App\Entity\SlaPolicy;
use App\Entity\Tenant;
use App\Entity\Ticket;
use App\Entity\TicketComment;
use App\Entity\TicketPriority;
use App\Entity\TicketSla;
use App\Entity\TicketStatus;
use App\Entity\User;
use App\Entity\WikiArticle;
use App\Entity\WikiCategory;
use App\Enum\ChangeRiskLevel;
use App\Enum\ChangeStatus;
use App\Enum\CiCategoryType;
use App\Enum\CiItemStatus;
use App\Enum\CiRelationshipType;
use App\Enum\ProblemStatus;
use App\Enum\TicketType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ItmsEntitiesTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        parent::tearDown();
    }

    public function testFullModulesEntitiesPersistence(): void
    {
        $suffix = bin2hex(random_bytes(4));

        // 1. Core Module: Tenant, Roles, Permissions, User, AuditLog
        $tenant = new Tenant();
        $tenant->setName('Acme Corp ' . $suffix);
        $tenant->setDomain('acme-' . $suffix . '.gpi.local');
        $this->em->persist($tenant);

        $permission = new Permission();
        $permission->setName('Eliminar Tickets');
        $permission->setCode('ticket.delete.' . $suffix);
        $this->em->persist($permission);

        $role = new Role();
        $role->setName('Administrador L2');
        $role->setTenant($tenant);
        $role->addPermission($permission);
        $this->em->persist($role);

        $user = new User();
        $user->setName('Juan Perez');
        $user->setUsername('jperez_' . $suffix);
        $user->setEmail('juan.perez.' . $suffix . '@acme.com');
        $user->setPassword('secure_hashed_password');
        $user->setTenant($tenant);
        $user->setRole($role);
        $this->em->persist($user);

        $auditLog = new AuditLog();
        $auditLog->setTenant($tenant);
        $auditLog->setUser($user);
        $auditLog->setAction('CREATE');
        $auditLog->setEntityType('ticket');
        $auditLog->setOldValues(null);
        $auditLog->setNewValues(['title' => 'Falla en Switch']);
        $auditLog->setIpAddress('127.0.0.1');
        $this->em->persist($auditLog);

        // 2. CMDB Module: CiCategory, CiItem, CiRelationship
        $catHardware = new CiCategory();
        $catHardware->setName('Infraestructura de Red');
        $catHardware->setType(CiCategoryType::FISICO);
        $catHardware->setTenant($tenant);
        $this->em->persist($catHardware);

        $ciSwitch = new CiItem();
        $ciSwitch->setName('Switch Cisco Core ' . $suffix);
        $ciSwitch->setCategory($catHardware);
        $ciSwitch->setTenant($tenant);
        $ciSwitch->setStatus(CiItemStatus::ACTIVO);
        $ciSwitch->setIpAddress('192.168.1.1');
        $ciSwitch->setSerialNumber('CISCO-' . $suffix);
        $ciSwitch->setMetadata(['puertos' => 48, 'poe' => true]);
        $this->em->persist($ciSwitch);

        $ciServer = new CiItem();
        $ciServer->setName('Servidor Web Principal ' . $suffix);
        $ciServer->setCategory($catHardware);
        $ciServer->setTenant($tenant);
        $ciServer->setStatus(CiItemStatus::ACTIVO);
        $ciServer->setIpAddress('192.168.1.50');
        $this->em->persist($ciServer);

        $relationship = new CiRelationship();
        $relationship->setParentCi($ciSwitch);
        $relationship->setChildCi($ciServer);
        $relationship->setRelationshipType(CiRelationshipType::CONNECTED_TO);
        $this->em->persist($relationship);

        // 3. Mesa de Ayuda: TicketPriority, TicketStatus, Ticket, TicketComment, ticket_ci
        $priority = new TicketPriority();
        $priority->setName('Alta');
        $priority->setLevel(3);
        $priority->setColor('#f97316');
        $priority->setTenant($tenant);
        $this->em->persist($priority);

        $status = new TicketStatus();
        $status->setName('En Progreso');
        $status->setOrderIndex(2);
        $status->setIsClosedState(false);
        $status->setTenant($tenant);
        $this->em->persist($status);

        $ticket = new Ticket();
        $ticket->setTenant($tenant);
        $ticket->setTicketCode('INC-' . $suffix);
        $ticket->setType(TicketType::INCIDENTE);
        $ticket->setTitle('Caída de conexión en Servidor Web');
        $ticket->setDescription('No hay respuesta ping hacia el servidor principal');
        $ticket->setStatus($status);
        $ticket->setPriority($priority);
        $ticket->setRequester($user);
        $ticket->addCiItem($ciServer);
        $ticket->addCiItem($ciSwitch);
        $this->em->persist($ticket);

        $comment = new TicketComment();
        $comment->setTicket($ticket);
        $comment->setUser($user);
        $comment->setContent('Se ha reiniciado la interfaz de red.');
        $comment->setIsPrivate(true);
        $this->em->persist($comment);

        // 4. SLA Engine: SlaPolicy, TicketSla
        $sla = new SlaPolicy();
        $sla->setTenant($tenant);
        $sla->setName('SLA Servidores Críticos ' . $suffix);
        $sla->setPriority($priority);
        $sla->setResponseTimeMinutes(30);
        $sla->setResolutionTimeMinutes(120);
        $sla->setBusinessHoursOnly(true);
        $this->em->persist($sla);

        $ticketSla = new TicketSla();
        $ticketSla->setTicket($ticket);
        $ticketSla->setSlaPolicy($sla);
        $ticketSla->setResponseDueAt(new \DateTimeImmutable('+30 minutes'));
        $ticketSla->setResolutionDueAt(new \DateTimeImmutable('+2 hours'));
        $this->em->persist($ticketSla);

        // 5. Operations: Problem, problem_ticket, Change, change_ci
        $problem = new Problem();
        $problem->setTenant($tenant);
        $problem->setTitle('Inestabilidad de firmware ' . $suffix);
        $problem->setStatus(ProblemStatus::IDENTIFICADO);
        $problem->setRootCause('Bug en versión 15.2 del firmware');
        $problem->setWorkaround('Forzar reinicio cada 15 días');
        $problem->addTicket($ticket);
        $this->em->persist($problem);

        $change = new Change();
        $change->setTenant($tenant);
        $change->setTitle('Actualización a Firmware 15.4 ' . $suffix);
        $change->setRiskLevel(ChangeRiskLevel::ALTO);
        $change->setStatus(ChangeStatus::SOLICITADO);
        $change->setRequester($user);
        $change->addCiItem($ciSwitch);
        $this->em->persist($change);

        // 6. Wiki: WikiCategory, WikiArticle
        $wikiCat = new WikiCategory();
        $wikiCat->setTenant($tenant);
        $wikiCat->setName('Procedimientos de Red ' . $suffix);
        $this->em->persist($wikiCat);

        $wikiArticle = new WikiArticle();
        $wikiArticle->setTenant($tenant);
        $wikiArticle->setCategory($wikiCat);
        $wikiArticle->setAuthor($user);
        $wikiArticle->setTitle('Guía de actualización Cisco ' . $suffix);
        $wikiArticle->setContent('## Pasos para actualizar...');
        $wikiArticle->setIsPublic(false);
        $this->em->persist($wikiArticle);

        // Flush all entities
        $this->em->flush();

        // Assertions
        $this->assertNotNull($tenant->getId());
        $this->assertNotNull($user->getId());
        $this->assertNotNull($ticket->getId());
        $this->assertNotNull($ciSwitch->getId());
        $this->assertNotNull($problem->getId());
        $this->assertNotNull($change->getId());
        $this->assertNotNull($wikiArticle->getId());
        $this->assertCount(2, $ticket->getCiItems());
        $this->assertCount(1, $problem->getTickets());
        $this->assertCount(1, $change->getCiItems());
        $this->assertTrue($comment->isPrivate());
        $this->assertFalse($ticketSla->isResponseBreached());
    }
}
