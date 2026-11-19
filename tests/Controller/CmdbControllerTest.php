<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CiCategory;
use App\Entity\CiItem;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\CiCategoryType;
use App\Enum\CiItemStatus;
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
}
