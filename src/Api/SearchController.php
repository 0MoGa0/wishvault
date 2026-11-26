<?php

declare(strict_types=1);

namespace WishVault\Api;

use WishVault\Auth\Auth;
use WishVault\Http\Context;
use WishVault\Http\Response;
use WishVault\Wishes\Search;

final readonly class SearchController
{
    public function __construct(
        private Auth $auth,
        private Search $search,
    ) {
    }

    public function search(Context $ctx): Response
    {
        $query = $ctx->request->query;
        $in = new Input();
        $q = $in->string($query['q'] ?? null, 'q', 2, 100);
        $source = $in->enum($query['source'] ?? null, 'source', ['steam', 'provider'], 'steam');
        $in->check();
        $caller = $this->auth->caller($ctx);
        return Response::json($this->search->search($caller, $ctx->clientIp, $q, $source));
    }
}
