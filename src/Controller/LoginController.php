<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class LoginController extends AbstractController
{
    #[Route('/', name: 'app_login')]
    #[Route('/login', name: 'app_login_alias')]
    public function index(AuthenticationUtils $authenticationUtils): Response
    {
        // Si el usuario ya está autenticado, redirigir directamente al dashboard
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        // Obtener el error de login si existe
        $error = $authenticationUtils->getLastAuthenticationError();

        // Último nombre de usuario introducido
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('login/index.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        // Este método es interceptado por la configuración de logout del firewall
        throw new \LogicException('Este método es interceptado por la configuración de logout del firewall.');
    }
}
