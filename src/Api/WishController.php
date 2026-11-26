<?php

declare(strict_types=1);

namespace WishVault\Api;

use WishVault\Auth\Auth;
use WishVault\Http\ApiError;
use WishVault\Http\Context;
use WishVault\Http\Response;
use WishVault\Wishes\Wishes;

final readonly class WishController
{
    public function __construct(
        private Auth $auth,
        private Wishes $wishes,
    ) {
    }

    public function list(Context $ctx): Response
    {
        $query = $ctx->request->query;
        $in = new Input();
        $filter = $in->enum($query['filter'] ?? null, 'filter', ['open', 'available', 'rejected', 'all'], 'open');
        $sort = $in->enum($query['sort'] ?? null, 'sort', ['votes', 'new'], 'votes');
        $limit = $in->int($query['limit'] ?? null, 'limit', 1, 200, 100);
        $offset = $in->int($query['offset'] ?? null, 'offset', 0, 1_000_000_000, 0);
        $in->check();
        return Response::json($this->wishes->list($this->auth->caller($ctx), $filter, $sort, $limit, $offset));
    }

    public function create(Context $ctx): Response
    {
        $body = $ctx->request->json();
        $in = new Input();
        $source = $in->enum($body['source'] ?? null, 'source', ['steam', 'provider'], 'steam');
        if (!array_key_exists('source', $body)) {
            $in->fail('source', 'required');
        }
        $sourceId = $body['source_id'] ?? null;
        if (is_int($sourceId)) {
            $sourceId = (string) $sourceId;
        }
        $sourceId = $in->string($sourceId, 'source_id', 1, 64);
        $in->check();
        $caller = $this->auth->writer($ctx);
        $result = $this->wishes->create($caller, $ctx->clientIp, $source, $sourceId);
        return Response::json($result, $result['created'] ? 201 : 200);
    }

    public function get(Context $ctx): Response
    {
        return Response::json($this->wishes->get($this->auth->caller($ctx), $ctx->request->routeId()));
    }

    public function update(Context $ctx): Response
    {
        $caller = $this->auth->admin($ctx);
        return Response::json($this->wishes->update($caller, $ctx->request->routeId(), $ctx->request->json()));
    }

    public function delete(Context $ctx): Response
    {
        $this->auth->admin($ctx);
        $this->wishes->delete($ctx->request->routeId());
        return Response::noContent();
    }

    public function vote(Context $ctx): Response
    {
        $caller = $this->auth->writer($ctx);
        return Response::json($this->wishes->vote($caller, $ctx->request->routeId()));
    }

    public function unvote(Context $ctx): Response
    {
        $caller = $this->auth->caller($ctx);
        if ($caller->isAccount() && !$caller->canWish) {
            throw ApiError::forbidden('role_too_low', 'Role below wish_min_role');
        }
        if (!$caller->isAccount() && !$caller->canWish) {
            throw ApiError::forbidden('anonymous_disabled', 'Anonymous participation is disabled');
        }
        return Response::json($this->wishes->unvote($caller, $ctx->request->routeId()));
    }

    public function tags(Context $ctx): Response
    {
        $this->auth->admin($ctx);
        return Response::json(['tags' => $this->wishes->tags($ctx->request->routeId())]);
    }
}
