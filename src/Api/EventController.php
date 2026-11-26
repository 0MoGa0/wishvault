<?php

declare(strict_types=1);

namespace WishVault\Api;

use WishVault\Auth\Auth;
use WishVault\Events\Events;
use WishVault\Http\Context;
use WishVault\Http\Response;

final readonly class EventController
{
    public function __construct(
        private Auth $auth,
        private Events $events,
    ) {
    }

    public function list(Context $ctx): Response
    {
        $userId = (int) $this->auth->account($ctx)->userId;
        $in = new Input();
        $unread = $in->bool($ctx->request->query['unread'] ?? null, 'unread', false);
        $in->check();
        return Response::json([
            'items' => $this->events->list($userId, $unread),
            'unread' => $this->events->unreadCount($userId),
        ]);
    }

    public function read(Context $ctx): Response
    {
        $userId = (int) $this->auth->account($ctx)->userId;
        $body = $ctx->request->json();
        $ids = $body['ids'] ?? [];
        $in = new Input();
        $list = [];
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 1000) {
            $in->fail('ids', 'invalid');
        } else {
            foreach ($ids as $id) {
                if (!is_int($id)) {
                    $in->fail('ids', 'invalid');
                    break;
                }
                $list[] = $id;
            }
        }
        $in->check();
        $this->events->markRead($userId, $list);
        return Response::json(['unread' => $this->events->unreadCount($userId)]);
    }
}
