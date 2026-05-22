<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
class ApprovalChecker
{
    private const ALLOWED_ROUTES = [
        'login', 'logout', 'auth_google', 'auth_google_callback',
        'pending_approval', '_wdt', '_profiler',
    ];

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly RouterInterface $router,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if (!$token) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return;
        }

        if ($user->isApproved()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route', '');
        foreach (self::ALLOWED_ROUTES as $allowed) {
            if (str_starts_with($route, $allowed)) {
                return;
            }
        }

        $event->setResponse(new RedirectResponse($this->router->generate('pending_approval')));
    }
}
