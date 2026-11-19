<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/profile')]
final class ProfileController extends AbstractController
{
    #[Route('', name: 'app_profile_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $tab = (string) $request->query->get('tab', 'info');
        if (!in_array($tab, ['info', 'avatar', 'security'], true)) {
            $tab = 'info';
        }

        return $this->render('profile/index.html.twig', [
            'user' => $user,
            'activeTab' => $tab,
        ]);
    }

    #[Route('/update-info', name: 'app_profile_update_info', methods: ['POST'])]
    public function updateInfo(
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfManager,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('profile_info', $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.', 'info', Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) $request->request->get('name'));
        $username = mb_strtolower(trim((string) $request->request->get('username')));
        $email = mb_strtolower(trim((string) $request->request->get('email')));

        if (mb_strlen($name) < 3) {
            return $this->handleResponse($request, false, 'El nombre completo debe tener al menos 3 caracteres.', 'info');
        }

        if (mb_strlen($username) < 3 || !preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            return $this->handleResponse($request, false, 'El nombre de usuario debe tener al menos 3 caracteres y solo letras, números, puntos o guiones.', 'info');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->handleResponse($request, false, 'El correo electrónico no es válido.', 'info');
        }

        // Validar unicidad si cambió el username o email
        $existingUser = $userRepository->findOneBy(['username' => $username]);
        if ($existingUser && $existingUser->getId()?->toRfc4122() !== $user->getId()?->toRfc4122()) {
            return $this->handleResponse($request, false, 'El nombre de usuario ya está registrado por otra cuenta.', 'info');
        }

        $existingEmail = $userRepository->findOneBy(['email' => $email]);
        if ($existingEmail && $existingEmail->getId()?->toRfc4122() !== $user->getId()?->toRfc4122()) {
            return $this->handleResponse($request, false, 'El correo electrónico ya se encuentra en uso por otra cuenta.', 'info');
        }

        $user->setName($name);
        $user->setUsername($username);
        $user->setEmail($email);

        $em->flush();

        return $this->handleResponse($request, true, 'Información del perfil actualizada exitosamente.', 'info');
    }

    #[Route('/update-avatar', name: 'app_profile_update_avatar', methods: ['POST'])]
    public function updateAvatar(
        Request $request,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfManager,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('profile_avatar', $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.', 'avatar', Response::HTTP_BAD_REQUEST);
        }

        /** @var UploadedFile|null $avatarFile */
        $avatarFile = $request->files->get('avatar');

        if (!$avatarFile instanceof UploadedFile) {
            return $this->handleResponse($request, false, 'Por favor, selecciona una imagen para tu foto de perfil.', 'avatar');
        }

        if (!$avatarFile->isValid()) {
            return $this->handleResponse($request, false, 'Error al cargar la imagen seleccionada.', 'avatar');
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($avatarFile->getMimeType(), $allowedMimes, true)) {
            return $this->handleResponse($request, false, 'Formato no admitido. Usa JPG, PNG, WEBP o GIF.', 'avatar');
        }

        // Límite de 4MB
        if ($avatarFile->getSize() > 4 * 1024 * 1024) {
            return $this->handleResponse($request, false, 'La imagen supera el límite permitido de 4 MB.', 'avatar');
        }

        $targetDirectory = $this->getParameter('kernel.project_dir') . '/public/uploads/avatars';
        if (!is_dir($targetDirectory)) {
            @mkdir($targetDirectory, 0775, true);
        }

        $extension = $avatarFile->guessExtension() ?: 'jpg';
        $newFilename = sprintf('avatar_%s_%s.%s', $user->getId()?->toRfc4122() ?? 'user', bin2hex(random_bytes(6)), $extension);

        try {
            // Eliminar foto anterior si existía
            if ($user->getAvatarPath()) {
                $oldPath = $this->getParameter('kernel.project_dir') . '/public' . $user->getAvatarPath();
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }

            $avatarFile->move($targetDirectory, $newFilename);
            $user->setAvatarPath('/uploads/avatars/' . $newFilename);
            $em->flush();
        } catch (FileException $e) {
            return $this->handleResponse($request, false, 'Ocurrió un error al guardar la foto en el servidor.', 'avatar');
        }

        return $this->handleResponse($request, true, 'Foto de perfil actualizada correctamente.', 'avatar');
    }

    #[Route('/remove-avatar', name: 'app_profile_remove_avatar', methods: ['POST'])]
    public function removeAvatar(
        Request $request,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfManager,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('profile_remove_avatar', $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.', 'avatar', Response::HTTP_BAD_REQUEST);
        }

        if ($user->getAvatarPath()) {
            $oldPath = $this->getParameter('kernel.project_dir') . '/public' . $user->getAvatarPath();
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
            $user->setAvatarPath(null);
            $em->flush();
        }

        return $this->handleResponse($request, true, 'Foto de perfil eliminada. Se usará el avatar predeterminado.', 'avatar');
    }

    #[Route('/update-password', name: 'app_profile_update_password', methods: ['POST'])]
    public function updatePassword(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfManager,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $token = (string) $request->request->get('_csrf_token');
        if (!$csrfManager->isTokenValid(new CsrfToken('profile_password', $token))) {
            return $this->handleResponse($request, false, 'Token de seguridad inválido.', 'security', Response::HTTP_BAD_REQUEST);
        }

        $currentPassword = (string) $request->request->get('current_password');
        $newPassword = (string) $request->request->get('new_password');
        $confirmPassword = (string) $request->request->get('confirm_password');

        if (empty($currentPassword)) {
            return $this->handleResponse($request, false, 'Debes ingresar tu contraseña actual para confirmar los cambios.', 'security');
        }

        if (!$hasher->isPasswordValid($user, $currentPassword)) {
            return $this->handleResponse($request, false, 'La contraseña actual ingresada es incorrecta.', 'security');
        }

        if (mb_strlen($newPassword) < 8) {
            return $this->handleResponse($request, false, 'La nueva contraseña debe tener al menos 8 caracteres.', 'security');
        }

        if ($newPassword !== $confirmPassword) {
            return $this->handleResponse($request, false, 'La nueva contraseña y su confirmación no coinciden.', 'security');
        }

        if ($currentPassword === $newPassword) {
            return $this->handleResponse($request, false, 'La nueva contraseña no puede ser idéntica a la contraseña actual.', 'security');
        }

        $user->setPassword($hasher->hashPassword($user, $newPassword));
        $em->flush();

        return $this->handleResponse($request, true, 'Tu contraseña ha sido actualizada con éxito.', 'security');
    }

    private function handleResponse(
        Request $request,
        bool $success,
        string $message,
        string $tab = 'info',
        int $status = Response::HTTP_OK,
    ): Response {
        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse(['success' => $success, 'message' => $message], $success ? $status : Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash($success ? 'success' : 'error', $message);
        return $this->redirectToRoute('app_profile_index', ['tab' => $tab]);
    }
}
