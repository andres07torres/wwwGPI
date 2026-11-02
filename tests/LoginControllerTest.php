<?php

namespace App\Tests;

use App\Entity\AuthenticationLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class LoginControllerTest extends WebTestCase
{
    public function testRootLoadsLoginPage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Cada cliente en su propio espacio.');
        $this->assertSelectorTextContains('h2', 'Bienvenido de vuelta');
        $this->assertSelectorExists('input[name="_username"]');
        $this->assertSelectorExists('input[name="_password"]');
    }

    public function testLoginAliasLoadsLoginPage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Bienvenido de vuelta');
    }

    public function testLoginWithInvalidCredentialsRecordsAuditLog(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');
        $csrfToken = $crawler->filter('form#f-login input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/', [
            '_username' => 'inexistente@example.com',
            '_password' => 'PasswordErronea#123',
            '_csrf_token' => $csrfToken,
        ]);

        // Redirige al login con error
        $this->assertResponseRedirects('http://localhost/');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $log = $em->getRepository(AuthenticationLog::class)->findOneBy(['email' => 'inexistente@example.com']);
        $this->assertNotNull($log);
        $this->assertSame('FAILED', $log->getStatus());

        if ($log) {
            $em->remove($log);
            $em->flush();
        }
    }

    public function testLoginWithValidCredentialsSuccessAndAuditLog(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $testEmail = 'login_success@example.com';
        $existing = $em->getRepository(User::class)->findOneBy(['email' => $testEmail]);
        if ($existing) {
            $em->remove($existing);
            $em->flush();
        }

        $user = new User();
        $user->setName('Usuario Exitoso');
        $user->setEmail($testEmail);
        $user->setPassword($hasher->hashPassword($user, 'ClaveCorrecta#2026'));
        $em->persist($user);
        $em->flush();

        $crawler = $client->request('GET', '/');
        $csrfToken = $crawler->filter('form#f-login input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/', [
            '_username' => $testEmail,
            '_password' => 'ClaveCorrecta#2026',
            '_csrf_token' => $csrfToken,
        ]);

        // Redirige al home configurado en default_target_path
        $this->assertResponseRedirects('http://localhost/home');

        $log = $em->getRepository(AuthenticationLog::class)->findOneBy(['email' => $testEmail]);
        $this->assertNotNull($log);
        $this->assertSame('SUCCESS', $log->getStatus());

        // Limpiar
        $userToRemove = $em->getRepository(User::class)->findOneBy(['email' => $testEmail]);
        if ($userToRemove) {
            $em->remove($userToRemove);
        }
        $logToRemove = $em->getRepository(AuthenticationLog::class)->findOneBy(['email' => $testEmail]);
        if ($logToRemove) {
            $em->remove($logToRemove);
        }
        $em->flush();
    }
}
