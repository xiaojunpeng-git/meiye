<?php
declare(strict_types=1);

namespace app\http\middleware\cashier;

use app\Request;
use app\services\store\channel\StoreChannelLoginTicketServices;
use mohe\exceptions\AdminException;
use mohe\interfaces\MiddlewareInterface;

/**
 * 收银台：会话门店强制注入，不信任前端 store_id。
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
        }
    }

    protected function auditDeny(Request $request, int $clientStoreId, string $field): void
    {
        try {
            /** @var StoreChannelLoginTicketServices $ticketSvc */
            $ticketSvc = app()->make(StoreChannelLoginTicketServices::class);
            $info = (array)($request->cashierInfo ?? $request->storeStaffInfo ?? []);
            $ticketSvc->auditDeny('force_store_session_deny', [
                'employee_id' => (int)($info['employee_id'] ?? 0),
                'channel' => 'cashier',
                'store_id' => $clientStoreId,
                'session_store_id' => (int)($request->storeId ?? 0),
                'field' => $field,
                'account' => (string)($info['account'] ?? ''),
                'ip' => method_exists($request, 'ip') ? (string)$request->ip() : '',
            ]);
        } catch (\Throwable $e) {
        }
    }
}
