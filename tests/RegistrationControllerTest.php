<?php

namespace App\Tests;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RegistrationControllerTest extends WebTestCase
{
    private function cleanUser(EntityManagerInterface $em, string $email): void
    {
        $existing = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing) {
            $em->remove($existing);
            $em->flush();
        }
    }

    public function testRegistrationValidationFailsOnShortPassword(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');
        $token = $crawler->filter('form#f-reg input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/register', [
            'name' => 'Carlos Torres',
            'email' => 'test_short@example.com',
            'password' => '12345',
            'password2' => '12345',
            'terms' => 'on',
            '_csrf_token' => $token,
        ]);

        $this->assertResponseStatusCodeSame(422);
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($response['success']);
        $this->assertArrayHasKey('r-pass', $response['errors']);
    }

    public function testSuccessfulRegistration(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $testEmail = 'carlos_test@example.com';
        $this->cleanUser($em, $testEmail);

        $crawler = $client->request('GET', '/');
        $token = $crawler->filter('form#f-reg input[name="_csrf_token"]')->attr('value');

        $client->request('POST', '/register', [
            'name' => 'Carlos Torres',
            'email' => $testEmail,
            'password' => 'SuperSegura#2026',
            'password2' => 'SuperSegura#2026',
            'terms' => 'on',
            '_csrf_token' => $token,
        ]);

        $this->assertResponseStatusCodeSame(201);
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);

        // Verificar que el usuario fue guardado y hasheado en la base de datos
        $savedUser = $em->getRepository(User::class)->findOneBy(['email' => $testEmail]);
        $this->assertNotNull($savedUser);
        $this->assertSame('Carlos Torres', $savedUser->getName());
        $this->assertNotSame('SuperSegura#2026', $savedUser->getPassword());
        $this->assertStringStartsWith('$', $savedUser->getPassword());

        // Limpiar después del test
        $this->cleanUser($em, $testEmail);
    }
}
