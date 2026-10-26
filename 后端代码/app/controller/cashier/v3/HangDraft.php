<?php
namespace app\controller\cashier\v3;

use app\controller\cashier\AuthController;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity;
use app\services\cashier\v3\hang\CashierV3HangSubmissionServices;
use app\services\cashier\v3\hang\CashierV3HangResumeServices;
use app\services\cashier\v3\hang\CashierV3HangVoidServices;
use app\services\cashier\v3\settlement\CashierV3CheckoutDraftDiscardServices;
use think\facade\App;
use think\facade\Db;

final class HangDraft extends AuthController
{
    private $dispatcher;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->dispatcher = CashierV3Bootstrap::dispatcher();
    }

    public function delete()
    {
        $body = $this->request->post();
        $body = is_array($body) ? $body : [];
        $hangOrderId = trim((string)($body['hangOrderId'] ?? $body['hang_order_id'] ?? ''));
        try {
            $operator = $this->dispatcher->scopeResolver()->operatorScope(
                (int)$this->storeId,
                (int)$this->cashierId
            );
            $dataScope = $this->dispatcher->dataScopeFactory()->build(
                (int)$this->storeId,
                (int)$this->cashierId,
                is_array($this->cashierInfo) ? $this->cashierInfo : [],
                $operator->tenantId(),
                $operator->organizationId()
            );
            $result = Db::transaction(function () use ($hangOrderId, $operator, $dataScope): array {
                return (new CashierV3HangVoidServices())->deleteDirectInTx(
                    $hangOrderId,
                    $operator,
                    $dataScope
                );
            });
            return $this->success('ok', ['result' => ['status' => 'success'], 'data' => $result]);
        } catch (\Throwable $exception) {
            return $this->fail($exception->getMessage() ?: '挂单删除失败。');
        }
    }

    public function save()
    {
        $body = $this->request->post();
        $body = is_array($body) ? $body : [];
        try {
            $operator = $this->dispatcher->scopeResolver()->operatorScope(
                (int)$this->storeId,
                (int)$this->cashierId
            );
            $dataScope = $this->dispatcher->dataScopeFactory()->build(
                (int)$this->storeId,
                (int)$this->cashierId,
                is_array($this->cashierInfo) ? $this->cashierInfo : [],
                $operator->tenantId(),
                $operator->organizationId()
            );
            $result = Db::transaction(function () use ($body, $operator, $dataScope): array {
                return (new CashierV3HangSubmissionServices(
                    new \app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices(
                        new \app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard()
                    )
                ))->saveDraftDirectInTx([
                    'operator_scope' => $operator,
                    'data_scope' => $dataScope,
                    'state_context_id' => trim((string)($body['stateContextId'] ?? '')),
                    'idempotency_key' => trim((string)($body['idempotencyKey'] ?? '')),
                    'payload' => $body,
                ]);
            });
            return $this->success('ok', ['result' => ['status' => 'succeeded'], 'data' => $result]);
        } catch (\Throwable $exception) {
            return $this->fail($exception->getMessage() ?: '挂单草稿保存失败。');
        }
    }

    public function resume()
    {
        $body = $this->request->post();
        $body = is_array($body) ? $body : [];
        try {
            $operator = $this->dispatcher->scopeResolver()->operatorScope((int)$this->storeId, (int)$this->cashierId);
            $dataScope = $this->dispatcher->dataScopeFactory()->build(
                (int)$this->storeId,
                (int)$this->cashierId,
                is_array($this->cashierInfo) ? $this->cashierInfo : [],
                $operator->tenantId(),
                $operator->organizationId()
            );
            $result = Db::transaction(function () use ($body, $operator, $dataScope): array {
                return (new CashierV3HangResumeServices(
                    new \app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices(
                        new \app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard()
                    ),
                    new \app\services\cashier\v3\cashier\CashierV3SaleCatalogServices()
                ))->resumeDirectInTx(
                    trim((string)($body['hangOrderId'] ?? $body['hang_order_id'] ?? '')),
                    trim((string)($body['stateContextId'] ?? '')),
                    $operator,
                    $dataScope
                );
            });
            return $this->success('ok', ['result' => ['status' => 'succeeded'], 'data' => $result]);
        } catch (\Throwable $exception) {
            return $this->fail($exception->getMessage() ?: '提取挂单失败。');
        }
    }

    public function clearCart()
    {
        $body = $this->request->post();
        $body = is_array($body) ? $body : [];
        try {
            $operator = $this->dispatcher->scopeResolver()->operatorScope((int)$this->storeId, (int)$this->cashierId);
            $dataScope = $this->dispatcher->dataScopeFactory()->build(
                (int)$this->storeId,
                (int)$this->cashierId,
                is_array($this->cashierInfo) ? $this->cashierInfo : [],
                $operator->tenantId(),
                $operator->organizationId()
            );
            $stateContextId = trim((string)($body['stateContextId'] ?? ''));
            $workspaceId = CashierV3CheckoutWorkspaceIdentity::id($operator->storeId(), $stateContextId);
            $result = Db::transaction(function () use ($workspaceId, $stateContextId, $operator, $dataScope): array {
                $workspace = new \app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices(
                    new \app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard()
                );
                $discardedCheckout = (new CashierV3CheckoutDraftDiscardServices())
                    ->discardWorkspaceDraftsInTx($workspaceId, $stateContextId, $operator, $dataScope);
                return [
                    'cashierDraft' => $workspace->clearLinesInTx($workspaceId, $stateContextId, $operator),
                    'checkout' => ['resumeOnLoad' => false, 'discarded' => $discardedCheckout],
                ];
            });
            return $this->success('ok', ['result' => ['status' => 'succeeded'], 'data' => $result]);
        } catch (\Throwable $exception) {
            return $this->fail($exception->getMessage() ?: '购物车清空失败。');
        }
    }

    /**
     * 结账失败返回收银时仅废弃旧结账会话，保留购物车草稿供重新发起一张新单。
     */
    public function discardCheckout()
    {
        $body = $this->request->post();
        $body = is_array($body) ? $body : [];
        try {
            $operator = $this->dispatcher->scopeResolver()->operatorScope((int)$this->storeId, (int)$this->cashierId);
            $dataScope = $this->dispatcher->dataScopeFactory()->build(
                (int)$this->storeId,
                (int)$this->cashierId,
                is_array($this->cashierInfo) ? $this->cashierInfo : [],
                $operator->tenantId(),
                $operator->organizationId()
            );
            $stateContextId = trim((string)($body['stateContextId'] ?? ''));
            $workspaceId = CashierV3CheckoutWorkspaceIdentity::id($operator->storeId(), $stateContextId);
            $result = Db::transaction(function () use ($workspaceId, $stateContextId, $operator, $dataScope): array {
                return (new CashierV3CheckoutDraftDiscardServices())
                    ->discardWorkspaceDraftsInTx($workspaceId, $stateContextId, $operator, $dataScope);
            });
            return $this->success('ok', ['result' => ['status' => 'succeeded'], 'data' => ['checkout' => ['resumeOnLoad' => false, 'discarded' => $result]]]);
        } catch (\Throwable $exception) {
            return $this->fail($exception->getMessage() ?: '结账草稿清理失败。');
        }
    }

}
