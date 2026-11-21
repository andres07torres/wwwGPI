<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Change;
use App\Entity\Problem;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\ChangeRiskLevel;
use App\Enum\ChangeStatus;
use App\Enum\ProblemStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class OperationsControllerTest extends WebTestCase
{
    private function createAuthenticatedUser(): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $email = 'operations_test@example.com';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $tenant = $em->getRepository(Tenant::class)->findOneBy([]) ?? new Tenant();
            if (!$tenant->getId()) {
                $tenant->setName('Test Tenant');
                $tenant->setDomain('test');
                $tenant->setStatus(true);
                $em->persist($tenant);
            }

            $user = new User();
            $user->setName('Operations Test User');
            $user->setUsername('op_user');
            $user->setEmail($email);
            $user->setTenant($tenant);
            $user->setPassword($hasher->hashPassword($user, 'Password123#'));
            $em->persist($user);
            $em->flush();
        }

        return $user;
    }

    public function testProblemIndexRedirectsAnonymous(): void
    {
        $client = static::createClient();
        $client->request('GET', '/operations/problems');

        $this->assertResponseRedirects('http://localhost/');
    }

    public function testProblemIndexLoadsForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $user = $this->createAuthenticatedUser();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/operations/problems');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Problemas (ITIL)');
        $this->assertSelectorExists('#modalCreateProblem');
        $this->assertSelectorExists('#modalEditProblem');
        $this->assertSelectorExists('#modalDetailProblem');
    }

    public function testProblemCreateEditDetailAndDelete(): void
    {
        $client = static::createClient();
        $user = $this->createAuthenticatedUser();
        $client->loginUser($user);

        // 1. Obtener token CSRF de la página de índice
        $crawler = $client->request('GET', '/operations/problems');
        $csrfToken = $crawler->filter('form[action*="problems/create"] input[name="_csrf_token"]')->attr('value');

        // 2. Crear Problema
        $client->request('POST', '/operations/problems/create', [
            '_csrf_token' => $csrfToken,
            'title' => 'Problema de Prueba Automatizada',
            'description' => 'Descripción de prueba para diagnóstico ITIL.',
            'status' => ProblemStatus::BAJO_INVESTIGACION->value,
            'root_cause' => 'Fuga de memoria detectada en worker Caddy.',
            'workaround' => 'Reiniciar el contenedor de fondo cada 6 horas.',
        ]);

        $this->assertResponseRedirects('/operations/problems');
        $client->followRedirect();

        // 3. Verificar que se creó en base de datos
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $problem = $em->getRepository(Problem::class)->findOneBy(['title' => 'Problema de Prueba Automatizada']);
        $this->assertNotNull($problem);
        $this->assertSame(ProblemStatus::BAJO_INVESTIGACION, $problem->getStatus());

        $id = (string) $problem->getId();

        // 4. Probar API de Detalle
        $client->request('GET', '/operations/problems/' . $id . '/detail');
        $this->assertResponseIsSuccessful();
        $detailData = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($detailData['success']);
        $this->assertSame('Problema de Prueba Automatizada', $detailData['problem']['title']);
        $this->assertSame('Fuga de memoria detectada en worker Caddy.', $detailData['problem']['rootCause']);

        // 5. Editar Problema
        $crawler = $client->request('GET', '/operations/problems');
        $csrfJson = $crawler->filter('#csrfTokensProblemData')->text();
        $csrfTokens = json_decode($csrfJson, true);
        $editToken = $csrfTokens[$id]['edit'] ?? null;
        $this->assertNotNull($editToken);

        $client->request('POST', '/operations/problems/' . $id . '/edit', [
            '_csrf_token' => $editToken,
            'title' => 'Problema de Prueba Actualizado',
            'status' => ProblemStatus::RESUELTO->value,
            'description' => 'Descripción actualizada.',
            'root_cause' => 'Parche de software aplicado en versión 1.2.',
            'workaround' => 'N/A corregido definitivamente.',
        ]);

        $this->assertResponseRedirects('/operations/problems');
        $client->followRedirect();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $updatedProblem = $em->getRepository(Problem::class)->find($problem->getId());
        $this->assertNotNull($updatedProblem);
        $this->assertSame('Problema de Prueba Actualizado', $updatedProblem->getTitle());
        $this->assertSame(ProblemStatus::RESUELTO, $updatedProblem->getStatus());
        $this->assertNotNull($updatedProblem->getResolvedAt());

        // 6. Eliminar Problema
        $deleteToken = $csrfTokens[$id]['delete'] ?? null;
        $this->assertNotNull($deleteToken);

        $client->request('POST', '/operations/problems/' . $id . '/delete', [
            '_csrf_token' => $deleteToken,
        ]);

        $this->assertResponseRedirects('/operations/problems');
        $client->followRedirect();

        $deletedProblem = $em->getRepository(Problem::class)->find($problem->getId());
        $this->assertNull($deletedProblem);
    }

    public function testChangeIndexRedirectsAnonymous(): void
    {
        $client = static::createClient();
        $client->request('GET', '/operations/changes');

        $this->assertResponseRedirects('http://localhost/');
    }

    public function testChangeIndexLoadsForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $user = $this->createAuthenticatedUser();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/operations/changes');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Cambios (CAB)');
        $this->assertSelectorExists('#modalCreateChange');
        $this->assertSelectorExists('#modalEditChange');
        $this->assertSelectorExists('#modalDetailChange');
        $this->assertSelectorExists('#modalEvaluateChange');
        $this->assertSelectorExists('#modalDeleteChange');
    }

    public function testChangeCreateEditEvaluateDetailAndDelete(): void
    {
        $client = static::createClient();
        $user = $this->createAuthenticatedUser();
        $client->loginUser($user);

        // 1. Obtener token CSRF de la página de índice
        $crawler = $client->request('GET', '/operations/changes');
        $csrfToken = $crawler->filter('form[action*="changes/create"] input[name="_csrf_token"]')->attr('value');

        // 2. Crear Solicitud de Cambio
        $client->request('POST', '/operations/changes/create', [
            '_csrf_token' => $csrfToken,
            'title' => 'Cambio de Prueba Automatizada CAB',
            'description' => 'Actualización de base de datos y optimización de índices.',
            'risk_level' => ChangeRiskLevel::MEDIO->value,
            'status' => ChangeStatus::SOLICITADO->value,
            'scheduled_start' => '2026-11-25T22:00',
            'scheduled_end' => '2026-11-26T02:00',
        ]);

        $this->assertResponseRedirects('/operations/changes');
        $client->followRedirect();

        // 3. Verificar que se persistió en la BD
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $change = $em->getRepository(Change::class)->findOneBy(['title' => 'Cambio de Prueba Automatizada CAB']);
        $this->assertNotNull($change);
        $this->assertSame(ChangeRiskLevel::MEDIO, $change->getRiskLevel());
        $this->assertSame(ChangeStatus::SOLICITADO, $change->getStatus());

        $id = (string) $change->getId();

        // 4. Detalle AJAX JSON
        $client->request('GET', '/operations/changes/' . $id . '/detail');
        $this->assertResponseIsSuccessful();
        $detailData = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($detailData['success']);
        $this->assertSame('Cambio de Prueba Automatizada CAB', $detailData['change']['title']);
        $this->assertSame('Medio', $detailData['change']['riskLevel']);

        // 5. Evaluación CAB (Aprobar cambio)
        $crawler = $client->request('GET', '/operations/changes');
        $csrfJson = $crawler->filter('#csrfTokensChangeData')->text();
        $csrfTokens = json_decode($csrfJson, true);
        $evalToken = $csrfTokens[$id]['evaluate'] ?? null;
        $this->assertNotNull($evalToken);

        $client->request('POST', '/operations/changes/' . $id . '/evaluate', [
            '_csrf_token' => $evalToken,
            'decision' => 'aprobar',
        ]);

        $this->assertResponseRedirects('/operations/changes');
        $client->followRedirect();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $approvedChange = $em->getRepository(Change::class)->find($change->getId());
        $this->assertNotNull($approvedChange);
        $this->assertSame(ChangeStatus::APROBADO, $approvedChange->getStatus());
        $this->assertNotNull($approvedChange->getApprover());

        // 6. Editar Cambio
        $editToken = $csrfTokens[$id]['edit'] ?? null;
        $this->assertNotNull($editToken);

        $client->request('POST', '/operations/changes/' . $id . '/edit', [
            '_csrf_token' => $editToken,
            'title' => 'Cambio de Prueba Actualizado CAB',
            'risk_level' => ChangeRiskLevel::ALTO->value,
            'status' => ChangeStatus::APROBADO->value,
            'description' => 'Descripción actualizada tras revisión CAB.',
            'scheduled_start' => '2026-11-27T01:00',
            'scheduled_end' => '2026-11-27T04:00',
        ]);

        $this->assertResponseRedirects('/operations/changes');
        $client->followRedirect();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $updatedChange = $em->getRepository(Change::class)->find($change->getId());
        $this->assertNotNull($updatedChange);
        $this->assertSame('Cambio de Prueba Actualizado CAB', $updatedChange->getTitle());
        $this->assertSame(ChangeRiskLevel::ALTO, $updatedChange->getRiskLevel());

        // 7. Eliminar Cambio
        $deleteToken = $csrfTokens[$id]['delete'] ?? null;
        $this->assertNotNull($deleteToken);

        $client->request('POST', '/operations/changes/' . $id . '/delete', [
            '_csrf_token' => $deleteToken,
        ]);

        $this->assertResponseRedirects('/operations/changes');
        $client->followRedirect();

        $deletedChange = $em->getRepository(Change::class)->find($change->getId());
        $this->assertNull($deletedChange);
    }

    public function testMaintenanceIndexRedirectsAnonymous(): void
    {
        $client = static::createClient();
        $client->request('GET', '/operations/maintenance');

        $this->assertResponseRedirects('http://localhost/');
    }

    public function testMaintenanceIndexLoadsForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $user = $this->createAuthenticatedUser();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/operations/maintenance');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Mantenimientos');
        $this->assertSelectorExists('#calendarMonthGrid');
        $this->assertSelectorExists('#modalScheduleMaintenance');
        $this->assertSelectorExists('#modalRescheduleMaintenance');
        $this->assertSelectorExists('#modalDetailMaintenance');
    }

    public function testMaintenanceScheduleAndReschedule(): void
    {
        $client = static::createClient();
        $user = $this->createAuthenticatedUser();
        $client->loginUser($user);

        // 1. Obtener token CSRF
        $crawler = $client->request('GET', '/operations/maintenance');
        $csrfToken = $crawler->filter('form[action*="maintenance/schedule"] input[name="_csrf_token"]')->attr('value');

        // 2. Programar Mantenimiento
        $client->request('POST', '/operations/maintenance/schedule', [
            '_csrf_token' => $csrfToken,
            'title' => 'Mantenimiento de Servidores Core Prod',
            'description' => 'Parches de seguridad mensuales en cluster Linux.',
            'risk_level' => ChangeRiskLevel::MEDIO->value,
            'status' => ChangeStatus::APROBADO->value,
            'scheduled_start' => '2026-11-28T23:00',
            'scheduled_end' => '2026-11-29T03:00',
        ]);

        $this->assertResponseRedirects('/operations/maintenance');
        $client->followRedirect();

        // 3. Verificar en BD
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $maint = $em->getRepository(Change::class)->findOneBy(['title' => 'Mantenimiento de Servidores Core Prod']);
        $this->assertNotNull($maint);
        $this->assertSame('2026-11-28 23:00', $maint->getScheduledStart()?->format('Y-m-d H:i'));

        $id = (string) $maint->getId();

        // 4. Reprogramar
        $crawler = $client->request('GET', '/operations/maintenance');
        $csrfJson = $crawler->filter('#csrfTokensMaintenanceData')->text();
        $csrfTokens = json_decode($csrfJson, true);
        $reschedToken = $csrfTokens[$id] ?? null;
        $this->assertNotNull($reschedToken);

        $client->request('POST', '/operations/maintenance/' . $id . '/reschedule', [
            '_csrf_token' => $reschedToken,
            'scheduled_start' => '2026-11-30T01:00',
            'scheduled_end' => '2026-11-30T04:00',
        ]);

        $this->assertResponseRedirects('/operations/maintenance');
        $client->followRedirect();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $updatedMaint = $em->getRepository(Change::class)->find($maint->getId());
        $this->assertNotNull($updatedMaint);
        $this->assertSame('2026-11-30 01:00', $updatedMaint->getScheduledStart()?->format('Y-m-d H:i'));

        // Limpieza
        $em->remove($updatedMaint);
        $em->flush();
    }
}
