<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        CsrfTokenManagerInterface $csrfTokenManager,
    ): JsonResponse {
        $data = $request->request->all();
        $name = trim((string) ($data['name'] ?? ''));
        $username = mb_strtolower(trim((string) ($data['username'] ?? '')));
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');
        $password2 = (string) ($data['password2'] ?? '');
        $terms = isset($data['terms']) || isset($data['r-terms']);
        $csrfToken = (string) ($data['_csrf_token'] ?? '');

        // 1. Validación de Token CSRF
        if (!$csrfTokenManager->isTokenValid(new CsrfToken('register', $csrfToken))) {
            return new JsonResponse([
                'success' => false,
                'errors' => ['general' => 'Token de seguridad inválido. Recarga la página.'],
            ], Response::HTTP_BAD_REQUEST);
        }

        $errors = [];

        // 2. Validación de Nombre
        if (mb_strlen($name) < 3) {
            $errors['r-name'] = 'El nombre completo debe tener al menos 3 caracteres.';
        }

        // 3. Validación de Usuario
        if (mb_strlen($username) < 3) {
            $errors['r-user'] = 'El nombre de usuario debe tener al menos 3 caracteres.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            $errors['r-user'] = 'El usuario solo puede contener letras, números, puntos, guiones y guiones bajos.';
        } elseif ($userRepository->findOneBy(['username' => $username])) {
            $errors['r-user'] = 'Este nombre de usuario ya está registrado.';
        }

        // 4. Validación de Correo
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['r-mail'] = 'Escribe un correo electrónico válido.';
        } elseif ($userRepository->findOneBy(['email' => $email])) {
            $errors['r-mail'] = 'Este correo electrónico ya está registrado.';
        }

        // 5. Políticas de Contraseña Estricta (Mínimo 12 caracteres, mayúsculas, números y símbolos)
        if (mb_strlen($password) < 12) {
            $errors['r-pass'] = 'La contraseña debe tener al menos 12 caracteres.';
        } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors['r-pass'] = 'Debe incluir mayúsculas, minúsculas, números y al menos un símbolo.';
        }

        if ($password !== $password2) {
            $errors['r-pass2'] = 'Las contraseñas no coinciden.';
        }

        // 5. Aceptación de términos
        if (!$terms) {
            $errors['r-terms'] = 'Debes aceptar las políticas para continuar.';
        }

        if (!empty($errors)) {
            return new JsonResponse(['success' => false, 'errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 6. Creación segura del Usuario con Hashing de contraseña (Argon2id/Sodium)
        $user = new User();
        $user->setName($name);
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setIsVerified(false); // Para flujo de activación / Double Opt-In
        $user->setPassword(
            $userPasswordHasher->hashPassword($user, $password)
        );

        $entityManager->persist($user);
        $entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'message' => '¡Cuenta creada con éxito! Tu solicitud quedó registrada y está pendiente de activación.',
        ], Response::HTTP_CREATED);
    }
}
