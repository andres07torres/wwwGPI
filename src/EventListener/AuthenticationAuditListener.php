<?php

namespace App\EventListener;

use App\Entity\AuthenticationLog;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class AuthenticationAuditListener
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $request = $event->getRequest();
        $user = $event->getUser();
        $email = method_exists($user, 'getUserIdentifier') ? $user->getUserIdentifier() : 'desconocido';

        $log = new AuthenticationLog();
        $log->setEmail($email);
        $log->setIpAddress($request->getClientIp());
        $log->setUserAgent($request->headers->get('User-Agent'));
        $log->setStatus('SUCCESS');
        $log->setDetails('Inicio de sesión exitoso');

        try {
            $this->entityManager->persist($log);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Error guardando log de autenticación: ' . $e->getMessage());
        }
    }

    #[AsEventListener(event: LoginFailureEvent::class)]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();
        $email = $request->request->get('_username', 'desconocido');

        $log = new AuthenticationLog();
        $log->setEmail(is_string($email) ? $email : 'desconocido');
        $log->setIpAddress($request->getClientIp());
        $log->setUserAgent($request->headers->get('User-Agent'));
        $log->setStatus('FAILED');
        $log->setDetails('Credenciales inválidas o intento fallido');

        try {
            $this->entityManager->persist($log);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Error guardando log de fallo de login: ' . $e->getMessage());
        }
    }

    #[AsEventListener(event: LogoutEvent::class)]
    public function onLogout(LogoutEvent $event): void
    {
        $request = $event->getRequest();
        $token = $event->getToken();
        $user = $token?->getUser();
        $email = $user && method_exists($user, 'getUserIdentifier') ? $user->getUserIdentifier() : 'desconocido';

        $log = new AuthenticationLog();
        $log->setEmail($email);
        $log->setIpAddress($request?->getClientIp());
        $log->setUserAgent($request?->headers->get('User-Agent'));
        $log->setStatus('LOGOUT');
        $log->setDetails('Cierre de sesión de usuario');

        try {
            $this->entityManager->persist($log);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Error guardando log de logout: ' . $e->getMessage());
        }
    }
}
