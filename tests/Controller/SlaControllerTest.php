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
}
