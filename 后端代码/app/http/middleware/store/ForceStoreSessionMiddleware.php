<?php
declare(strict_types=1);

namespace app\http\middleware\store;

use app\Request;
use app\services\store\channel\StoreChannelLoginTicketServices;
use mohe\exceptions\AdminException;
use mohe\interfaces\MiddlewareInterface;

/**
 * 门店后台：会话门店强制注入，不信任前端 store_id。
 */
class ForceStoreSessionMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next)
    {
        $sessionStoreId = (int)($request->storeId ?? 0);
        if ($sessionStoreId <= 0) {
            throw new AdminException('缺少会话门店，请重新登录');
        }

        $this->assertNoClientStoreTamper($request, $sessionStoreId);
        $this->overwriteClientStoreParams($request, $sessionStoreId);

        return $next($request);
    }

    protected function assertNoClientStoreTamper(Request $request, int $sessionStoreId): void
    {
        $candidates = [];
        foreach (['store_id', 'storeId', 'selected_store_id', 'current_store_id'] as $key) {
            $v = $request->param($key, null);
            if ($v !== null && $v !== '' && $v !== false) {
                $candidates[$key] = (int)$v;
            }
        }
        // 调拨双方：不得双方都不是会话门店
        $storeA = (int)$request->param('store_a', 0);
        $storeB = (int)$request->param('store_b', 0);
        if ($storeA > 0 || $storeB > 0) {
            try {
                /** @var \app\services\store\channel\StoreSessionContext $ctx */
                $ctx = app()->make(\app\services\store\channel\StoreSessionContext::class);
                $partyA = (string)$request->param('party_a', 'store');
                $partyB = (string)$request->param('party_b', 'store');
                $ctx->assertTransferTouchesSession($request, $storeA, $storeB, $partyA, $partyB);
            } catch (AdminException $e) {
                $this->auditDeny($request, max($storeA, $storeB), 'store_a_b');
                throw $e;
            }
        }
        // 路径参数常见 :id 不在此强制为门店；仅当名为 store_id 时核对
        $routeStore = $request->param('store_id');
        if ($routeStore !== null && $routeStore !== '') {
            $candidates['route.store_id'] = (int)$routeStore;
        }

        foreach ($candidates as $key => $client) {
            if ($client > 0 && $client !== $sessionStoreId) {
                $this->auditDeny($request, $client, $key);
                throw new AdminException('无权访问该门店数据');
            }
        }
    }

    protected function overwriteClientStoreParams(Request $request, int $sessionStoreId): void
    {
        $request->storeId = $sessionStoreId;
        // 覆盖 get/post 原始参数，使 getMore/postMore 读到会话门店
        try {
            $get = $request->get();
            if (is_array($get)) {
                foreach (['store_id', 'storeId', 'selected_store_id', 'current_store_id'] as $k) {
                    if (array_key_exists($k, $get) || $k === 'store_id') {
                        $get[$k] = $sessionStoreId;
                    }
                }
                $request->withGet($get);
            }
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            $post = $request->post();
            if (is_array($post)) {
                foreach (['store_id', 'storeId', 'selected_store_id', 'current_store_id'] as $k) {
                    if (array_key_exists($k, $post) || $k === 'store_id') {
                        $post[$k] = $sessionStoreId;
                    }
                }
                $request->withPost($post);
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    protected function auditDeny(Request $request, int $clientStoreId, string $field): void
    {
        try {
            /** @var StoreChannelLoginTicketServices $ticketSvc */
            $ticketSvc = app()->make(StoreChannelLoginTicketServices::class);
            $info = (array)($request->storeStaffInfo ?? []);
            $ticketSvc->auditDeny('force_store_session_deny', [
                'employee_id' => (int)($info['employee_id'] ?? 0),
                'channel' => 'store',
                'store_id' => $clientStoreId,
                'session_store_id' => (int)($request->storeId ?? 0),
                'field' => $field,
                'account' => (string)($info['account'] ?? ''),
                'ip' => method_exists($request, 'ip') ? (string)$request->ip() : '',
            ]);
        } catch (\Throwable $e) {
            // 审计失败不阻断拒绝
        }
    }
}
