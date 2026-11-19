<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProfileControllerTest extends WebTestCase
{
    public function testProfilePageRedirectsAnonymousUser(): void
    {
        $client = static::createClient();
        $client->request('GET', '/profile');

        $this->assertResponseRedirects('http://localhost/');
    }

    public function testProfilePageLoadsForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $testEmail = 'profile_test_user@example.com';
        $user = $em->getRepository(User::class)->findOneBy(['email' => $testEmail]);
        if (!$user) {
            $user = new User();
            $user->setName('Profile Test User');
            $user->setUsername('profile_test_user');
            $user->setEmail($testEmail);
            $user->setPassword($hasher->hashPassword($user, 'InitialPassword123#'));
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);
        $crawler = $client->request('GET', '/profile');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Mi Perfil de Usuario');
        $this->assertSelectorExists('#profileTabs');
        $this->assertSelectorExists('#formProfileInfo');
        $this->assertSelectorExists('#formProfileAvatar');
        $this->assertSelectorExists('#formProfilePassword');

        // Cleanup
        $em->remove($user);
        $em->flush();
    }
}
