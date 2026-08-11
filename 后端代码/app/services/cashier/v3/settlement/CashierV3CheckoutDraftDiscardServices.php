<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/**
 * Discards only an unfinished checkout draft when its cashier cart is reset.
 * A successful or unsettled payment must never be removed by this path.
 */
final class CashierV3CheckoutDraftDiscardServices
{
    private const REQUEST_TABLE = 'cashier_v3_checkout_request';
    private const LINE_TABLE = 'cashier_v3_checkout_line_draft';
    private const PAYMENT_TABLE = 'cashier_v3_checkout_payment_draft';
    private const SOURCE_TABLE = 'cashier_v3_checkout_source_reference';
    private const RESOURCE_PLAN_TABLE = 'cashier_v3_checkout_resource_plan';
    private const RESOURCE_PLAN_ROW_TABLE = 'cashier_v3_checkout_resource_plan_row';
    private const SALES_ORDER_TABLE = 'cashier_v3_sales_order';
    private const ENTITLEMENT_COMPLETION_RECEIPT_TABLE = 'cashier_v3_entitlement_completion_receipt';
    private const PAYMENT_FACT_TABLE = 'cashier_v3_payment_fact';
    private const BALANCE_FACT_TABLE = 'cashier_v3_balance_fact';

    /**
     * @return array{requestCount:int,lineCount:int,paymentCount:int,sourceCount:int,resourcePlanCount:int}
     */
    public function discardWorkspaceDraftsInTx(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        CashierV3TransactionGuard::assertInTransaction('checkoutDraftDiscard');
        $workspaceId = trim($workspaceId);
        $stateContextId = trim($stateContextId);
        $expectedWorkspaceId = sprintf(
            'ws:%d:%d:%s',
            $operatorScope->storeId(),
            $operatorScope->operatorId(),
            $stateContextId
        );
        if ($stateContextId === '' || !hash_equals($expectedWorkspaceId, $workspaceId)) {
            throw new \RuntimeException('当前收银草稿上下文无效，请刷新后重试。');
        }
        if ($operatorScope->storeId() !== $dataScope->forcedStoreId()) {
            throw new \RuntimeException('当前门店上下文无效，请刷新后重试。');
        }

        $requests = Db::name(self::REQUEST_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('store_id', $dataScope->forcedStoreId())
            ->where('operator_id', $operatorScope->operatorId())
            ->where('workspace_id', $workspaceId)
            ->where('state_context_id', $stateContextId)
            ->whereIn('request_status', ['editing', 'ready_for_submit', 'failed'])
            ->lock(true)
            ->field('request_id')
            ->select()
            ->toArray();
        $requestIds = array_values(array_filter(array_map(static function (array $row): string {
            return trim((string)($row['request_id'] ?? ''));
        }, $requests)));
        if (!$requestIds) {
            return ['requestCount' => 0, 'lineCount' => 0, 'paymentCount' => 0, 'sourceCount' => 0, 'resourcePlanCount' => 0];
        }

        // A checkout with any formal business fact is no longer a draft,
        // regardless of a stale request status. In particular, a successful
        // collection or balance deduction must never be erased by a “return to
        // cashier” action.
        $salesCount = (int)Db::name(self::SALES_ORDER_TABLE)
            ->whereIn('checkout_request_id', $requestIds)
            ->lock(true)
            ->count();
        $completionCount = (int)Db::name(self::ENTITLEMENT_COMPLETION_RECEIPT_TABLE)
            ->whereIn('checkout_request_id', $requestIds)
            ->lock(true)
            ->count();
        $paymentFactCount = (int)Db::name(self::PAYMENT_FACT_TABLE)
            ->whereIn('checkout_request_id', $requestIds)
            ->lock(true)
            ->count();
        $balanceFactCount = (int)Db::name(self::BALANCE_FACT_TABLE)
            ->whereIn('checkout_request_id', $requestIds)
            ->lock(true)
            ->count();
        if ($salesCount > 0 || $completionCount > 0 || $paymentFactCount > 0 || $balanceFactCount > 0) {
            throw new \RuntimeException('该结账已产生业务事实，不能直接清空，请先核对收款结果。');
        }

        $planRows = Db::name(self::RESOURCE_PLAN_TABLE)
            ->whereIn('request_id', $requestIds)
            ->lock(true)
            ->field('id')
            ->select()
            ->toArray();
        $planIds = array_values(array_filter(array_map(static function (array $row): int {
            return (int)($row['id'] ?? 0);
        }, $planRows)));
        if ($planIds) {
            Db::name(self::RESOURCE_PLAN_ROW_TABLE)->whereIn('plan_id', $planIds)->delete();
            Db::name(self::RESOURCE_PLAN_TABLE)->whereIn('id', $planIds)->delete();
        }

        $lineCount = (int)Db::name(self::LINE_TABLE)->whereIn('request_id', $requestIds)->delete();
        $paymentCount = (int)Db::name(self::PAYMENT_TABLE)->whereIn('request_id', $requestIds)->delete();
        $sourceCount = (int)Db::name(self::SOURCE_TABLE)->whereIn('request_id', $requestIds)->delete();
        $requestCount = (int)Db::name(self::REQUEST_TABLE)->whereIn('request_id', $requestIds)->delete();

        if ($requestCount !== count($requestIds)) {
            throw new \RuntimeException('旧结账草稿已变化，请刷新后重试。');
        }

        return [
            'requestCount' => $requestCount,
            'lineCount' => $lineCount,
            'paymentCount' => $paymentCount,
            'sourceCount' => $sourceCount,
            'resourcePlanCount' => count($planIds),
        ];
    }
}
