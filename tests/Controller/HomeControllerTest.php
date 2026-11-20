<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class HomeControllerTest extends WebTestCase
{
    public function testHomePageRedirectsAnonymousUser(): void
    {
        $client = static::createClient();
        $client->request('GET', '/home');

        $this->assertResponseRedirects('http://localhost/');
    }

    public function testHomePageLoadsWithCardsAndChartsForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $testEmail = 'dashboard_user@example.com';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $testEmail]);
        if (!$user) {
            $user = new User();
            $user->setName('Dashboard Test User');
            $user->setUsername('dashboard_user');
            $user->setEmail($testEmail);
            $user->setPassword($hasher->hashPassword($user, 'Password123#'));
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);
        $client->request('GET', '/home');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Panel de Control Operativo');
        $this->assertSelectorExists('#chartTicketsStatus');
        $this->assertSelectorExists('#chartSlaCompliance');
        $this->assertSelectorExists('#chartTicketsPriority');
        $this->assertSelectorExists('#chartCisCategory');
    }
}
