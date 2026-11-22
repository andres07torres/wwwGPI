<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CiCategory;
use App\Entity\CiItem;
use App\Entity\CiRelationship;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\CiCategoryType;
use App\Enum\CiItemStatus;
use App\Enum\CiRelationshipType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CmdbControllerTest extends WebTestCase
{
    public function testAssetsPageRedirectsAnonymousUser(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cmdb/assets');

        $this->assertResponseRedirects('http://localhost/');
    }

    public function testAssetsPageLoadsForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $testEmail = 'cmdb_test_user@example.com';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $testEmail]);
        if (!$user) {
            $user = new User();
            $user->setName('CMDB Test User');
            $user->setUsername('cmdb_test_user');
            $user->setEmail($testEmail);
            $user->setPassword($hasher->hashPassword($user, 'Password123#'));
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);
        $crawler = $client->request('GET', '/cmdb/assets');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Inventario de Activos');
        $this->assertSelectorExists('#ciItemsTable');
        $this->assertSelectorExists('#modalCreateCiItem');
        $this->assertSelectorExists('#modalEditCiItem');
        $this->assertSelectorExists('#modalDeleteCiItem');

        // Cleanup user
        $em->remove($user);
        $em->flush();
    }

    public function testCreateEditAndDeleteAsset(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $testEmail = 'cmdb_crud_user@example.com';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $testEmail]);
        if (!$user) {
            $user = new User();
            $user->setName('CMDB CRUD User');
            $user->setUsername('cmdb_crud_user');
            $user->setEmail($testEmail);
            $user->setPassword($hasher->hashPassword($user, 'Password123#'));
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);
        $crawler = $client->request('GET', '/cmdb/assets');
        $this->assertResponseIsSuccessful();

        // Obtener un token CSRF y una categoría válida
        $token = $crawler->filter('#modalCreateCiItem input[name="_csrf_token"]')->attr('value');
        $category = $em->getRepository(CiCategory::class)->findOneBy([]);
        if (!$category) {
            $tenant = $em->getRepository(Tenant::class)->findOneBy([]) ?? new Tenant();
            if (!$tenant->getId()) {
                $tenant->setName('Test Tenant');
                $tenant->setDomain('cmdbtest');
                $tenant->setStatus(true);
                $em->persist($tenant);
            }
            $category = new CiCategory();
            $category->setName('Test Category');
            $category->setType(CiCategoryType::FISICO);
            $category->setTenant($tenant);
            $em->persist($category);
            $em->flush();
        }
        $this->assertNotNull($category, 'Debe haber categorías en el sistema');

        $tenant = $em->getRepository(Tenant::class)->findOneBy([]);

        // 1. Crear activo
        $client->request('POST', '/cmdb/assets/create', [
            '_csrf_token' => $token,
            'name' => 'SRV-TEST-UNIT01',
            'category_id' => $category->getId(),
            'tenant_id' => $tenant ? $tenant->getId()->toRfc4122() : null,
            'status' => CiItemStatus::ACTIVO->value,
            'ip_address' => '10.0.0.99',
            'mac_address' => '00:11:22:33:44:55',
            'serial_number' => 'SN-TEST-1234',
            'location' => 'Rack Testing',
            'description' => 'Servidor de pruebas unitarias',
        ]);

        $this->assertResponseRedirects('/cmdb/assets');

        // Verificar persistencia
        $createdItem = $em->getRepository(CiItem::class)->findOneBy(['name' => 'SRV-TEST-UNIT01']);
        $this->assertNotNull($createdItem);
        $this->assertSame('10.0.0.99', $createdItem->getIpAddress());
        $this->assertSame('Rack Testing', $createdItem->getLocation());

        // 2. Editar activo
        $client->request('GET', '/cmdb/assets');
        $editToken = $client->getCrawler()->filter('#modalEditCiItem input[name="_csrf_token"]')->attr('value');

        $client->request('POST', sprintf('/cmdb/assets/%s/edit', $createdItem->getId()->toRfc4122()), [
            '_csrf_token' => $editToken,
            'name' => 'SRV-TEST-UNIT01-UPDATED',
            'category_id' => $category->getId(),
            'tenant_id' => $tenant ? $tenant->getId()->toRfc4122() : null,
            'status' => CiItemStatus::MANTENIMIENTO->value,
            'ip_address' => '10.0.0.100',
            'location' => 'Rack Testing Actualizado',
            'description' => 'Servidor actualizado',
        ]);

        $this->assertResponseRedirects('/cmdb/assets');

        $em->clear();
        $updatedItem = $em->getRepository(CiItem::class)->find($createdItem->getId());
        $this->assertNotNull($updatedItem);
        $this->assertSame('SRV-TEST-UNIT01-UPDATED', $updatedItem->getName());
        $this->assertSame(CiItemStatus::MANTENIMIENTO, $updatedItem->getStatus());
        $this->assertSame('10.0.0.100', $updatedItem->getIpAddress());

        // 3. Eliminar activo
        $client->request('GET', '/cmdb/assets');
        $deleteToken = $client->getCrawler()->filter('#modalDeleteCiItem input[name="_csrf_token"]')->attr('value');

        $client->request('POST', sprintf('/cmdb/assets/%s/delete', $updatedItem->getId()->toRfc4122()), [
            '_csrf_token' => $deleteToken,
        ]);

        $this->assertResponseRedirects('/cmdb/assets');

        $em->clear();
        $deletedItem = $em->getRepository(CiItem::class)->find($createdItem->getId());
        $this->assertNull($deletedItem);

        // Cleanup user
        $userToRemove = $em->getRepository(User::class)->find($user->getId());
        if ($userToRemove) {
            $em->remove($userToRemove);
            $em->flush();
        }
    }

    public function testTopologyPageRedirectsAnonymousUser(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cmdb/topology');

        $this->assertResponseRedirects('http://localhost/');
    }

    public function testTopologyPageLoadsForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $testEmail = 'topology_test_user@example.com';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $testEmail]);
        if (!$user) {
            $user = new User();
            $user->setName('Topology Test User');
            $user->setUsername('topology_test_user');
            $user->setEmail($testEmail);
            $user->setPassword($hasher->hashPassword($user, 'Password123#'));
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);
        $crawler = $client->request('GET', '/cmdb/topology');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Mapa de Topología');
        $this->assertSelectorExists('#topologyCanvas');
        $this->assertSelectorExists('#modalCreateRelationship');
        $this->assertSelectorExists('#modalDeleteRelationship');

        // Cleanup user
        $em->remove($user);
        $em->flush();
    }

    public function testCreateAndDeleteRelationship(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $testEmail = 'topology_rel_user@example.com';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $testEmail]);
        if (!$user) {
            $user = new User();
            $user->setName('Topology Rel User');
            $user->setUsername('topology_rel_user');
            $user->setEmail($testEmail);
            $user->setPassword($hasher->hashPassword($user, 'Password123#'));
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);
        $crawler = $client->request('GET', '/cmdb/topology');
        $this->assertResponseIsSuccessful();

        // Asegurar la existencia de al menos 2 activos
        $category = $em->getRepository(CiCategory::class)->findOneBy([]);
        $tenant = $em->getRepository(Tenant::class)->findOneBy([]);

        $parentCi = new CiItem();
        $parentCi->setName('SRV-PARENT-01');
        $parentCi->setCategory($category);
        $parentCi->setTenant($tenant);
        $parentCi->setStatus(CiItemStatus::ACTIVO);
        $em->persist($parentCi);

        $childCi = new CiItem();
        $childCi->setName('APP-CHILD-01');
        $childCi->setCategory($category);
        $childCi->setTenant($tenant);
        $childCi->setStatus(CiItemStatus::ACTIVO);
        $em->persist($childCi);
        $em->flush();

        // 1. Crear relación
        $crawler = $client->request('GET', '/cmdb/topology');
        $token = $crawler->filter('#modalCreateRelationship input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/cmdb/topology/relationship/create', [
            '_csrf_token' => $token,
            'parent_ci_id' => $parentCi->getId()->toRfc4122(),
            'child_ci_id' => $childCi->getId()->toRfc4122(),
            'relationship_type' => CiRelationshipType::DEPENDS_ON->value,
        ]);

        $this->assertResponseRedirects('/cmdb/topology');

        $em->clear();
        $rel = $em->getRepository(CiRelationship::class)->findOneBy([
            'relationshipType' => CiRelationshipType::DEPENDS_ON,
        ]);
        $this->assertNotNull($rel);

        // 2. Eliminar relación
        $crawler = $client->request('GET', '/cmdb/topology');
        $deleteToken = $crawler->filter('#modalDeleteRelationship input[name="_csrf_token"]')->attr('value');

        $client->request('POST', sprintf('/cmdb/topology/relationship/%d/delete', $rel->getId()), [
            '_csrf_token' => $deleteToken,
        ]);

        $this->assertResponseRedirects('/cmdb/topology');

        $em->clear();
        $deletedRel = $em->getRepository(CiRelationship::class)->find($rel->getId());
        $this->assertNull($deletedRel);

        // Cleanup items & user
        $p = $em->getRepository(CiItem::class)->find($parentCi->getId());
        if ($p) $em->remove($p);
        $c = $em->getRepository(CiItem::class)->find($childCi->getId());
        if ($c) $em->remove($c);
        $u = $em->getRepository(User::class)->find($user->getId());
        if ($u) $em->remove($u);
        $em->flush();
    }
}
