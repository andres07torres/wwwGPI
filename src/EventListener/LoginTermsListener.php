<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

final class LoginTermsListener
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    #[AsEventListener(event: CheckPassportEvent::class, priority: 2048)]
    public function onCheckPassport(CheckPassportEvent $event): void
    {
        // Solo aplica a autenticaciones de tipo FormLogin (formulario web de login)
        if (!$event->getAuthenticator() instanceof FormLoginAuthenticator) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (!$request || !$request->isMethod('POST')) {
            return;
        }

        $terms = $request->request->get('_terms') ?? $request->request->get('terms');
        if (!$terms || ($terms !== 'on' && $terms !== '1' && $terms !== true)) {
            throw new CustomUserMessageAuthenticationException('Debes aceptar los términos y condiciones para iniciar sesión.');
        }
    }
}
