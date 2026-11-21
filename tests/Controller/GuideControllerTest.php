<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WikiArticle;
use App\Entity\WikiCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class GuideControllerTest extends WebTestCase
{
    private function createAuthenticatedUser(): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $email = 'guide_test@example.com';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $tenant = $em->getRepository(Tenant::class)->findOneBy([]) ?? new Tenant();
            if (!$tenant->getId()) {
                $tenant->setName('Guide Test Tenant');
                $tenant->setDomain('guidetest');
                $tenant->setStatus(true);
                $em->persist($tenant);
            }

            $user = new User();
            $user->setName('Guide Test User');
            $user->setUsername('guide_user');
            $user->setEmail($email);
            $user->setTenant($tenant);
            $user->setPassword($hasher->hashPassword($user, 'Password123#'));
            $em->persist($user);
            $em->flush();
        }

        return $user;
    }

    public function testSelfServiceIndexRedirectsAnonymous(): void
    {
        $client = static::createClient();
        $client->request('GET', '/guides/self-service');

        $this->assertResponseRedirects('http://localhost/');
    }

    public function testSelfServiceIndexLoadsForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $user = $this->createAuthenticatedUser();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/guides/self-service');
        $this->assertResponseIsSuccessful();
        $this->assertAnySelectorTextContains('h1', 'Autoservicio (Clientes)');
        $this->assertSelectorExists('#guideSearchInput');
        $this->assertSelectorExists('#modalCreateGuide');
        $this->assertSelectorExists('#modalDetailGuide');
        $this->assertSelectorExists('#modalEditGuide');
        $this->assertSelectorExists('#modalDeleteGuide');
    }

    public function testArticleDetailReturnsJsonAndIncrementsViews(): void
    {
        $client = static::createClient();
        $user = $this->createAuthenticatedUser();
        $client->loginUser($user);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $article = $em->getRepository(WikiArticle::class)->findOneBy(['isPublic' => true]);
        $this->assertNotNull($article);

        $initialViews = $article->getViewsCount();
        $id = (string) $article->getId();

        $client->request('GET', '/guides/self-service/' . $id . '/detail');
        $this->assertResponseIsSuccessful();

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame($id, $data['article']['id']);
        $this->assertSame($initialViews + 1, $data['article']['viewsCount']);
    }

    public function testArticleDetailReturns404ForNonexistent(): void
    {
        $client = static::createClient();
        $user = $this->createAuthenticatedUser();
        $client->loginUser($user);

        $fakeId = '00000000-0000-0000-0000-000000000000';
        $client->request('GET', '/guides/self-service/' . $fakeId . '/detail');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testCreateGuideLifecycle(): void
    {
        $client = static::createClient();
        $user = $this->createAuthenticatedUser();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/guides/self-service');
        $this->assertResponseIsSuccessful();

        $csrfToken = $crawler->filter('form[action*="self-service/create"] input[name="_csrf_token"]')->attr('value');
        $this->assertNotEmpty($csrfToken);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $cat = $em->getRepository(WikiCategory::class)->findOneBy([]);
        $this->assertNotNull($cat);

        $title = 'Guía de Prueba Funcional ' . uniqid();
        $client->request('POST', '/guides/self-service/create', [
            '_csrf_token' => $csrfToken,
            'title' => $title,
            'content' => 'Pasos técnicos de prueba para autoservicio.',
            'category_id' => $cat->getId(),
        ]);

        $this->assertResponseRedirects('/guides/self-service');
        $client->followRedirect();

        $created = $em->getRepository(WikiArticle::class)->findOneBy(['title' => $title]);
        $this->assertNotNull($created);
        $this->assertTrue($created->isPublic());
        $id = (string) $created->getId();

        // Obtener tokens CSRF para editar y eliminar
        $crawler = $client->request('GET', '/guides/self-service');
        $csrfJson = $crawler->filter('#csrfTokensGuideData')->text();
        $csrfTokens = json_decode($csrfJson, true);
        $editToken = $csrfTokens[$id]['edit'] ?? null;
        $deleteToken = $csrfTokens[$id]['delete'] ?? null;
        $this->assertNotNull($editToken);
        $this->assertNotNull($deleteToken);

        // Test Edit
        $updatedTitle = $title . ' (Actualizado)';
        $client->request('POST', '/guides/self-service/' . $id . '/edit', [
            '_csrf_token' => $editToken,
            'title' => $updatedTitle,
            'content' => 'Contenido actualizado.',
            'category_id' => $cat->getId(),
        ]);

        $this->assertResponseRedirects('/guides/self-service');
        $client->followRedirect();

        $em->clear();
        $updated = $em->getRepository(WikiArticle::class)->find($created->getId());
        $this->assertNotNull($updated);
        $this->assertSame($updatedTitle, $updated->getTitle());

        // Test Delete
        $client->request('POST', '/guides/self-service/' . $id . '/delete', [
            '_csrf_token' => $deleteToken,
        ]);

        $this->assertResponseRedirects('/guides/self-service');
        $client->followRedirect();

        $em->clear();
        $deleted = $em->getRepository(WikiArticle::class)->find($created->getId());
        $this->assertNull($deleted);
    }
}
