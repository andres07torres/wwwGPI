<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\SlaPolicy;
use App\Entity\TicketPriority;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SlaControllerTest extends WebTestCase
{
    public function testPoliciesPageRedirectsAnonymousUser(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sla/policies');

        $this->assertResponseRedirects('http://localhost/');
    }

    public function testPoliciesPageLoadsForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $testEmail = 'sla_test_user@example.com';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $testEmail]);
        if (!$user) {
            $user = new User();
            $user->setName('SLA Test User');
            $user->setUsername('sla_test_user');
            $user->setEmail($testEmail);
            $user->setPassword($hasher->hashPassword($user, 'Password123#'));
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);
        $crawler = $client->request('GET', '/sla/policies');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Políticas y Plazos de SLA');
        $this->assertSelectorExists('#slaPoliciesTable');
        $this->assertSelectorExists('#modalSaveSlaPolicy');
        $this->assertSelectorExists('#modalDeleteSlaPolicy');
        $this->assertSelectorExists('#slaPaginationFooter');

        // Cleanup user
        $em->remove($user);
        $em->flush();
    }

    public function testPolicySaveValidationFailsWithInvalidCsrf(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setName('SLA CSRF User');
        $user->setUsername('sla_csrf_user');
        $user->setEmail('sla_csrf_user@example.com');
        $user->setPassword($hasher->hashPassword($user, 'Password123#'));
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request(
            'POST',
            '/sla/policies/save',
            [
                '_csrf_token' => 'invalid_token',
                'name' => 'Test Policy',
                'priority_id' => 1,
                'response_time_minutes' => 30,
                'resolution_time_minutes' => 120,
            ],
            [],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $this->assertResponseStatusCodeSame(422);

        $em->remove($user);
        $em->flush();
    }

    public function testCompliancePageLoadsForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $testEmail = 'sla_comp_user@example.com';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $testEmail]);
        if (!$user) {
            $user = new User();
            $user->setName('SLA Comp User');
            $user->setUsername('sla_comp_user');
            $user->setEmail($testEmail);
            $user->setPassword($hasher->hashPassword($user, 'Password123#'));
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);
        $client->request('GET', '/sla/compliance');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Monitor de Cumplimiento SLA');
        $this->assertSelectorExists('#complianceTable');
        $this->assertSelectorExists('#compliancePaginationFooter');

        $em->remove($user);
        $em->flush();
    }

    public function testEscalationsPageLoadsForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $testEmail = 'sla_esc_user@example.com';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $testEmail]);
        if (!$user) {
            $user = new User();
            $user->setName('SLA Esc User');
            $user->setUsername('sla_esc_user');
            $user->setEmail($testEmail);
            $user->setPassword($hasher->hashPassword($user, 'Password123#'));
            $em->persist($user);
            $em->flush();
        }

        $tenant = $em->getRepository(\App\Entity\Tenant::class)->findOneBy([]);
        if (!$tenant) {
            $tenant = new \App\Entity\Tenant();
            $tenant->setName('Test Tenant');
            $tenant->setDomain('test-tenant');
            $em->persist($tenant);
        }

        $priority = $em->getRepository(\App\Entity\TicketPriority::class)->findOneBy([]);
        if (!$priority) {
            $priority = new \App\Entity\TicketPriority();
            $priority->setName('Alta');
            $priority->setLevel(3);
            $priority->setColor('#f59e0b');
            $priority->setTenant($tenant);
            $em->persist($priority);
        }

        $status = $em->getRepository(\App\Entity\TicketStatus::class)->findOneBy([]);
        if (!$status) {
            $status = new \App\Entity\TicketStatus();
            $status->setName('En Proceso');
            $status->setOrderIndex(2);
            $status->setIsClosedState(false);
            $status->setTenant($tenant);
            $em->persist($status);
        }

        $ticket = new \App\Entity\Ticket();
        $ticket->setTicketCode('INC-TEST-' . bin2hex(random_bytes(2)));
        $ticket->setTitle('Ticket de prueba escalamiento');
        $ticket->setDescription('Descripción de prueba');
        $ticket->setType(\App\Enum\TicketType::INCIDENTE);
        $ticket->setTenant($tenant);
        $ticket->setRequester($user);
        $ticket->setAssignee($user);
        $ticket->setPriority($priority);
        $ticket->setStatus($status);
        $ticket->setCreatedAt(new \DateTimeImmutable('-30 minutes'));
        $ticket->setUpdatedAt(new \DateTimeImmutable('-30 minutes'));
        $em->persist($ticket);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/sla/escalations');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Alertas y Escalamiento SLA');
        $this->assertSelectorExists('#escalationTable');
        $this->assertSelectorExists('#modalEscalateTicket');
        $this->assertSelectorExists('#modalEscalationRules');
        $this->assertSelectorTextContains('#escalationTable', 'Consumo SLA');
        $this->assertSelectorTextContains('#escalationTable', 'Ingreso:');

        $em->remove($ticket);
        $em->remove($user);
        $em->flush();
    }
}
