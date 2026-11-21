<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Problem;
use App\Entity\Tenant;
use App\Entity\User;
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
}
