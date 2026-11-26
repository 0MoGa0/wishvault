<?php

declare(strict_types=1);

namespace WishVault\Api;

use WishVault\Auth\Auth;
use WishVault\Auth\Caller;
use WishVault\Events\Events;
use WishVault\Http\Context;
use WishVault\Http\Response;

final readonly class AuthController
{
    public function __construct(
        private Auth $auth,
        private Events $events,
    ) {
    }

    public function me(Context $ctx): Response
    {
        return Response::json($this->meData($this->auth->caller($ctx)));
    }

    public function login(Context $ctx): Response
    {
        $body = $ctx->request->json();
        $in = new Input();
        $username = $in->string($body['username'] ?? null, 'username', 1, 255);
        $password = $in->string($body['password'] ?? null, 'password', 1, 1024, trim: false);
        $in->check();
        $result = $this->auth->login($ctx, $username, $password);
        return Response::json(['me' => $this->meData($result['caller']), 'moved' => $result['moved']]);
    }

    public function logout(Context $ctx): Response
    {
        $this->auth->logout($ctx);
        return Response::noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function meData(Caller $caller): array
    {
        if (!$caller->isAccount()) {
            return ['kind' => 'anonymous', 'user' => null, 'can_wish' => $caller->canWish, 'unread_events' => 0];
        }
        return [
            'kind' => 'user',
            'user' => [
                'id' => $caller->userId,
                'username' => $caller->username,
                'role' => $caller->role,
                'is_admin' => $caller->isAdmin,
            ],
            'can_wish' => $caller->canWish,
            'unread_events' => $this->events->unreadCount((int) $caller->userId),
        ];
    }
}
