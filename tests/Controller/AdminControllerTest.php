<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminControllerTest extends WebTestCase
{
    private function createAuthenticatedUser(): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $email = 'admin_test@example.com';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $tenant = $em->getRepository(Tenant::class)->findOneBy([]) ?? new Tenant();
            if (!$tenant->getId()) {
                $tenant->setName('Admin Test Tenant');
                $tenant->setDomain('admintest');
                $tenant->setStatus(true);
                $em->persist($tenant);
            }

            $user = new User();
            $user->setName('Admin Test User');
            $user->setUsername('admin_test_user');
            $user->setEmail($email);
            $user->setTenant($tenant);
            $user->setPassword($hasher->hashPassword($user, 'Password123#'));
            $em->persist($user);
            $em->flush();
        }

        return $user;
    }

    public function testOrganizationsIndexRedirectsAnonymous(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/organizations');

        $this->assertResponseRedirects('http://localhost/');
    }

    public function testOrganizationsIndexLoadsForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $user = $this->createAuthenticatedUser();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/admin/organizations');
        $this->assertResponseIsSuccessful();
        $this->assertAnySelectorTextContains('h1', 'Organizaciones');
        $this->assertSelectorExists('#organizationSearchInput');
        $this->assertSelectorExists('#organizationStatusFilter');
        $this->assertSelectorExists('#btnViewCards');
        $this->assertSelectorExists('#btnViewTable');
        $this->assertSelectorExists('#modalCreateOrganization');
        $this->assertSelectorExists('#modalDetailOrganization');
        $this->assertSelectorExists('#modalEditOrganization');
        $this->assertSelectorExists('#modalDeleteOrganization');
    }

    public function testOrganizationDetailReturnsJson(): void
    {
        $client = static::createClient();
        $user = $this->createAuthenticatedUser();
        $client->loginUser($user);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = $em->getRepository(Tenant::class)->findOneBy([]);
        $this->assertNotNull($tenant);

        $id = (string) $tenant->getId();
        $client->request('GET', '/admin/organizations/' . $id . '/detail');
        $this->assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame($id, $data['organization']['id']);
        $this->assertSame($tenant->getName(), $data['organization']['name']);
        $this->assertSame($tenant->getDomain(), $data['organization']['domain']);
        $this->assertArrayHasKey('users', $data['organization']);
    }

    public function testOrganizationDetailReturns404ForNonexistent(): void
    {
        $client = static::createClient();
        $user = $this->createAuthenticatedUser();
        $client->loginUser($user);

        $fakeId = '00000000-0000-0000-0000-000000000000';
        $client->request('GET', '/admin/organizations/' . $fakeId . '/detail');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testCreateOrganizationLifecycle(): void
    {
        $client = static::createClient();
        $user = $this->createAuthenticatedUser();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/admin/organizations');
        $this->assertResponseIsSuccessful();

        $csrfToken = $crawler->filter('form[action*="organizations/create"] input[name="_csrf_token"]')->attr('value');
        $this->assertNotEmpty($csrfToken);

        $name = 'Organización de Prueba ' . uniqid();
        $domain = 'org-test-' . substr(md5(uniqid()), 0, 8);

        // 1. Crear
        $client->request('POST', '/admin/organizations/create', [
            '_csrf_token' => $csrfToken,
            'name' => $name,
            'domain' => $domain,
            'status' => '1',
        ]);

        $this->assertResponseRedirects('/admin/organizations');
        $client->followRedirect();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $created = $em->getRepository(Tenant::class)->findOneBy(['domain' => $domain]);
        $this->assertNotNull($created);
        $this->assertSame($name, $created->getName());
        $this->assertTrue($created->isStatus());
        $id = (string) $created->getId();

        // 2. Obtener tokens CSRF para editar, toggle y eliminar
        $crawler = $client->request('GET', '/admin/organizations');
        $csrfJson = $crawler->filter('#csrfTokensOrgData')->text();
        $csrfTokens = json_decode($csrfJson, true);
        $editToken = $csrfTokens[$id]['edit'] ?? null;
        $deleteToken = $csrfTokens[$id]['delete'] ?? null;
        $toggleToken = $crawler->filter('form[action*="organizations/' . $id . '/toggle-status"] input[name="_csrf_token"]')->attr('value');

        $this->assertNotNull($editToken);
        $this->assertNotNull($deleteToken);
        $this->assertNotNull($toggleToken);

        // 3. Editar
        $updatedName = $name . ' (Actualizada)';
        $client->request('POST', '/admin/organizations/' . $id . '/edit', [
            '_csrf_token' => $editToken,
            'name' => $updatedName,
            'domain' => $domain,
            'status' => '1',
        ]);
        $this->assertResponseRedirects('/admin/organizations');
        $client->followRedirect();

        $em->clear();
        $updated = $em->getRepository(Tenant::class)->find($created->getId());
        $this->assertNotNull($updated);
        $this->assertSame($updatedName, $updated->getName());

        // 4. Alternar Estado (Desactivar)
        $client->request('POST', '/admin/organizations/' . $id . '/toggle-status', [
            '_csrf_token' => $toggleToken,
        ]);
        $this->assertResponseRedirects('/admin/organizations');
        $client->followRedirect();

        $em->clear();
        $toggled = $em->getRepository(Tenant::class)->find($created->getId());
        $this->assertNotNull($toggled);
        $this->assertFalse($toggled->isStatus());

        // 5. Eliminar (No tiene usuarios, debe permitir eliminar)
        $client->request('POST', '/admin/organizations/' . $id . '/delete', [
            '_csrf_token' => $deleteToken,
        ]);
        $this->assertResponseRedirects('/admin/organizations');
        $client->followRedirect();

        $em->clear();
        $deleted = $em->getRepository(Tenant::class)->find($created->getId());
        $this->assertNull($deleted);
    }

    public function testCannotDeleteCurrentTenant(): void
    {
        $client = static::createClient();
        $user = $this->createAuthenticatedUser();
        $client->loginUser($user);

        $currentTenant = $user->getTenant();
        $this->assertNotNull($currentTenant);
        $id = (string) $currentTenant->getId();

        $crawler = $client->request('GET', '/admin/organizations');
        $csrfJson = $crawler->filter('#csrfTokensOrgData')->text();
        $csrfTokens = json_decode($csrfJson, true);
        $deleteToken = $csrfTokens[$id]['delete'] ?? null;

        $client->request('POST', '/admin/organizations/' . $id . '/delete', [
            '_csrf_token' => $deleteToken,
        ]);

        $this->assertResponseRedirects('/admin/organizations');
        $client->followRedirect();

        // Debe seguir existiendo
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $stillExists = $em->getRepository(Tenant::class)->find($currentTenant->getId());
        $this->assertNotNull($stillExists);
    }

    public function testCannotCreateDuplicateDomain(): void
    {
        $client = static::createClient();
        $user = $this->createAuthenticatedUser();
        $client->loginUser($user);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $existing = $em->getRepository(Tenant::class)->findOneBy([]);
        $this->assertNotNull($existing);

        $crawler = $client->request('GET', '/admin/organizations');
        $csrfToken = $crawler->filter('form[action*="organizations/create"] input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/admin/organizations/create', [
            '_csrf_token' => $csrfToken,
            'name' => 'Duplicado Test',
            'domain' => $existing->getDomain(),
            'status' => '1',
        ]);

        $this->assertResponseRedirects('/admin/organizations');
        $crawlerAfter = $client->followRedirect();
        $this->assertSelectorExists('.app-flash-payload[data-type="error"]');
        $flashMessage = $crawlerAfter->filter('.app-flash-payload[data-type="error"]')->attr('data-message');
        $this->assertStringContainsString('Ya existe una organización registrada con el dominio', (string) $flashMessage);
    }
}
