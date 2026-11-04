<?php

namespace App\Tests;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class HomeControllerTest extends WebTestCase
{
    public function testHomePageRedirectsAnonymousUser(): void
    {
        $client = static::createClient();
        $client->request('GET', '/home');

        $this->assertResponseRedirects('http://localhost/');
    }

    public function testHomePageLoadsSuccessfullyForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $testEmail = 'home_test_user@example.com';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $testEmail]);
        if (!$user) {
            $user = new User();
            $user->setName('Test Home User');
            $user->setUsername('home_test_user');
            $user->setEmail($testEmail);
            $user->setPassword($hasher->hashPassword($user, 'Password123#'));
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);
        $client->request('GET', '/home');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.app-sidebar');

        // Limpiar
        $em->remove($user);
        $em->flush();
    }
}
