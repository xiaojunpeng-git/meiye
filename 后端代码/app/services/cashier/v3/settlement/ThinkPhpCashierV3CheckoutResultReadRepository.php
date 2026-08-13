<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use think\facade\Db;

/** ThinkPHP read adapter for result recovery. It never locks or writes. */
final class ThinkPhpCashierV3CheckoutResultReadRepository implements CashierV3CheckoutResultReadRepository
{
    private const RECEIPT_TABLE = 'cashier_v3_command_receipt';
    private const ORDER_TABLE = 'cashier_v3_sales_order';
    private const ENTITLEMENT_RECEIPT_TABLE = 'cashier_v3_entitlement_completion_receipt';
    private const REQUEST_TABLE = 'cashier_v3_checkout_request';

    public function findLatestCommittedCheckoutForWorkspace(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        return $this->findLatestCommittedForWorkspace(
            $workspaceId,
            $stateContextId,
            ['sale_only', 'mixed', 'entitlement_only'],
            $operatorScope,
            $dataScope
        );
    }

    public function findLatestCommittedSaleOnlyForWorkspace(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        return $this->findLatestCommittedForWorkspace(
            $workspaceId,
            $stateContextId,
            ['sale_only', 'mixed'],
            $operatorScope,
            $dataScope
        );
    }

    public function findReceiptForActor(
        string $idempotencyKey,
        int $storeId
    ) {
        return $this->row(Db::name(self::RECEIPT_TABLE)
            ->where('idempotency_key', $idempotencyKey)
            ->where('store_id', $storeId)
            ->field(
                'idempotency_key,action,store_id,operator_id,state_context_id,status,'
                . 'result_code,result_message,result_json,business_no,add_time,finish_time'
            )
            ->find());
    }

    public function findCommittedCheckoutResult(
        array $receipt,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        $locator = $this->locator($receipt);
        $idempotencyKey = trim((string)($receipt['idempotency_key'] ?? ''));
        $stateContextId = trim((string)($receipt['state_context_id'] ?? ''));
        if (!$this->allowsCurrentScope($operatorScope, $dataScope)
            || preg_match('/^CHECKOUT-[0-9a-f-]{36}$/D', $idempotencyKey) !== 1
            || $stateContextId === ''
            || strlen($stateContextId) > 64) {
            return null;
        }

        $request = $this->row(Db::name(self::REQUEST_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('organization_id', $dataScope->organizationId())
            ->where('store_id', $operatorScope->storeId())
            ->where('state_context_id', $stateContextId)
            ->where('last_idempotency_key', $idempotencyKey)
            ->where('request_status', CashierV3CheckoutSettlementStateMachine::SUCCEEDED)
            ->whereIn('composition', ['sale_only', 'mixed', 'entitlement_only'])
            ->where('last_operation', CashierV3CheckoutSettlementKernel::OPERATION_SUBMIT)
            ->field(
                'request_id,request_version,request_status,tenant_id,organization_id,'
                . 'workspace_id,store_id,member_id,operator_id,state_context_id,'
                . 'last_idempotency_key,last_operation,composition,debt_amount_cents,update_time'
            )
            ->find());
        if ($request === null) {
            return null;
        }
        if (isset($locator['checkoutRequestId'])
            && !hash_equals($locator['checkoutRequestId'], (string)$request['request_id'])) {
            return null;
        }
        return $this->committedResultForRequest($request, $locator, $operatorScope, $dataScope);
    }

    public function findCommittedSaleOnlyResult(
        array $receipt,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        $result = $this->findCommittedCheckoutResult($receipt, $operatorScope, $dataScope);
        return is_array($result)
            && in_array((string)($result['composition'] ?? ''), ['sale_only', 'mixed'], true)
            ? $result
            : null;
    }

    private function findLatestCommittedForWorkspace(
        string $workspaceId,
        string $stateContextId,
        array $allowedCompositions,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        $workspaceId = trim($workspaceId);
        $stateContextId = trim($stateContextId);
        if (!$this->allowsCurrentScope($operatorScope, $dataScope)
            || $stateContextId === ''
            || strlen($stateContextId) > 64
            || strlen($workspaceId) > 64
            || !preg_match('/^ws:' . preg_quote((string)$operatorScope->storeId(), '/') . ':(?:[0-9]+:)?' . preg_quote($stateContextId, '/') . '$/D', $workspaceId)) {
            return null;
        }

        $request = $this->row(Db::name(self::REQUEST_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('organization_id', $dataScope->organizationId())
            ->where('store_id', $operatorScope->storeId())
            ->where('workspace_id', $workspaceId)
            ->where('state_context_id', $stateContextId)
            ->where('request_status', CashierV3CheckoutSettlementStateMachine::SUCCEEDED)
            ->whereIn('composition', $allowedCompositions)
            ->where('last_operation', CashierV3CheckoutSettlementKernel::OPERATION_SUBMIT)
            ->field(
                'request_id,request_version,request_status,tenant_id,organization_id,'
                . 'workspace_id,state_context_id,store_id,member_id,operator_id,'
                . 'last_idempotency_key,last_operation,composition,debt_amount_cents,update_time'
            )
            ->order('update_time desc,id desc')
            ->find());
        if ($request === null) {
            return null;
        }

        $requestVersion = (int)($request['request_version'] ?? 0);
        $idempotencyKey = (string)($request['last_idempotency_key'] ?? '');
        if ($requestVersion <= 1
            || preg_match('/^CKR-[0-9a-f]{40}$/D', (string)($request['request_id'] ?? '')) !== 1
            || preg_match('/^CHECKOUT-[0-9a-f-]{36}$/D', $idempotencyKey) !== 1) {
            return null;
        }

        return $this->committedResultForRequest($request, [], $operatorScope, $dataScope);
    }

    private function committedResultForRequest(
        array $request,
        array $locator,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        $requestId = (string)($request['request_id'] ?? '');
        $requestVersion = (int)($request['request_version'] ?? 0);
        $composition = (string)($request['composition'] ?? '');
        $idempotencyKey = (string)($request['last_idempotency_key'] ?? '');
        if (preg_match('/^CKR-[0-9a-f]{40}$/D', $requestId) !== 1
            || $requestVersion <= 1
            || (string)($request['request_status'] ?? '')
                !== CashierV3CheckoutSettlementStateMachine::SUCCEEDED
            || (string)($request['last_operation'] ?? '')
                !== CashierV3CheckoutSettlementKernel::OPERATION_SUBMIT
            || preg_match('/^CHECKOUT-[0-9a-f-]{36}$/D', $idempotencyKey) !== 1
            || (string)($request['tenant_id'] ?? '') !== $dataScope->tenantId()
            || (string)($request['organization_id'] ?? '') !== $dataScope->organizationId()
            || (int)($request['store_id'] ?? 0) !== $operatorScope->storeId()) {
            return null;
        }

        if (in_array($composition, ['sale_only', 'mixed'], true)) {
            $order = $this->row(Db::name(self::ORDER_TABLE)
                ->where('tenant_id', $dataScope->tenantId())
                ->where('organization_id', $dataScope->organizationId())
                ->where('store_id', $operatorScope->storeId())
                ->where('checkout_request_id', $requestId)
                ->where('checkout_request_version', $requestVersion - 1)
                ->where('command_idempotency_key', $idempotencyKey)
                ->where('composition', $composition)
                ->where('order_status', 'settled')
                ->where('order_direction', 'forward')
                ->field(
                    'order_id,order_no,tenant_id,organization_id,store_id,operator_id,'
                    . 'checkout_request_id,checkout_request_version,command_idempotency_key,'
                    . 'composition,order_status,order_version,order_direction,settled_at'
                )
                ->find());
            if ($order === null
                || (isset($locator['orderId'])
                    && !hash_equals($locator['orderId'], (string)$order['order_id']))
                || (isset($locator['orderNo'])
                    && !hash_equals($locator['orderNo'], (string)$order['order_no']))) {
                return null;
            }
            $debt = $this->findCheckoutDebt($request, $order);
            if ($debt === false) {
                return null;
            }
            $entitlementReceipt = null;
            if ($composition === 'mixed') {
                $entitlementReceipt = $this->findEntitlementReceipt(
                    $request,
                    $operatorScope,
                    $dataScope
                );
                if ($entitlementReceipt === null
                    || (isset($locator['entitlementReceiptId'])
                        && !hash_equals(
                            $locator['entitlementReceiptId'],
                            (string)$entitlementReceipt['receipt_id']
                        ))) {
                    return null;
                }
            }
            return $this->committedSaleResult($request, $order, $entitlementReceipt, $debt);
        }

        if ($composition !== 'entitlement_only') {
            return null;
        }
        $receipt = $this->findEntitlementReceipt($request, $operatorScope, $dataScope);
        if ($receipt === null
            || (isset($locator['entitlementReceiptId'])
                && !hash_equals(
                    $locator['entitlementReceiptId'],
                    (string)$receipt['receipt_id']
                ))) {
            return null;
        }
        return $this->committedEntitlementResult($request, $receipt);
    }

    private function findEntitlementReceipt(
        array $request,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        return $this->row(Db::name(self::ENTITLEMENT_RECEIPT_TABLE)
            ->where('tenant_id', $dataScope->tenantId())
            ->where('organization_id', $dataScope->organizationId())
            ->where('store_id', $operatorScope->storeId())
            ->where('member_id', (int)($request['member_id'] ?? 0))
            ->where('workspace_id', (string)($request['workspace_id'] ?? ''))
            ->where('state_context_id', (string)($request['state_context_id'] ?? ''))
            ->where('checkout_request_id', (string)($request['request_id'] ?? ''))
            ->where('command_idempotency_key', (string)($request['last_idempotency_key'] ?? ''))
            ->where('status', 'completed')
            ->field(
                'receipt_id,tenant_id,organization_id,store_id,member_id,operator_id,'
                . 'workspace_id,state_context_id,checkout_request_id,command_idempotency_key,'
                . 'plan_fingerprint,status,settled_at,completed_at'
            )
            ->find());
    }

    private function committedSaleResult(
        array $request,
        array $order,
        ?array $receipt = null,
        ?array $debt = null
    ): ?array
    {
        if (!hash_equals((string)$request['request_id'], (string)$order['checkout_request_id'])
            || !hash_equals((string)$request['tenant_id'], (string)$order['tenant_id'])
            || !hash_equals((string)$request['organization_id'], (string)$order['organization_id'])
            || (int)$request['store_id'] !== (int)$order['store_id']
            || !hash_equals(
                (string)$request['last_idempotency_key'],
                (string)$order['command_idempotency_key']
            )
            || (string)$request['composition'] !== (string)$order['composition']
            || (int)$request['request_version']
                !== (int)$order['checkout_request_version'] + 1
            || (int)($order['settled_at'] ?? 0) <= 0) {
            return null;
        }
        $entitlement = null;
        if ((string)$request['composition'] === 'mixed') {
            if ($receipt === null
                || !$this->entitlementReceiptMatchesRequest($request, $receipt)
                || (int)$receipt['settled_at'] !== (int)$order['settled_at']) {
                return null;
            }
            $entitlement = $this->entitlementReceiptResult($receipt);
        }
        return [
            'composition' => (string)$request['composition'],
            'originalIdempotencyKey' => (string)$request['last_idempotency_key'],
            'settledAt' => (int)$order['settled_at'],
            'checkoutRequest' => $this->checkoutRequestResult($request),
            'salesOrder' => [
                'orderId' => (string)$order['order_id'],
                'orderNo' => (string)$order['order_no'],
                'orderStatus' => (string)$order['order_status'],
                'orderVersion' => (int)$order['order_version'],
                'checkoutRequestVersion' => (int)$order['checkout_request_version'],
            ],
            'entitlementCompletion' => $entitlement,
            'debt' => $debt,
        ];
    }

    /** Returns false for an incomplete/conflicting authority, null for no debt. */
    private function findCheckoutDebt(array $request, array $order)
    {
        $amount = (int)($request['debt_amount_cents'] ?? 0);
        if ($amount === 0) {
            return null;
        }
        // New V3 sale debts use the customer-facing QK sequence and are
        // linked through the V3 authority map. The old D3 derivation is only
        // valid for historical rows and cannot prove a newly committed debt.
        $authority = $this->row(Db::name('cashier_v3_debt_authority')
            ->where('tenant_id', (string)$request['tenant_id'])
            ->where('store_id', (int)$request['store_id'])
            ->where('member_id', (int)$request['member_id'])
            ->where('checkout_request_id', (string)$request['request_id'])
            ->where('sales_order_id', (string)$order['order_id'])
            ->field('debt_id,debt_no,sales_order_id')
            ->find());
        if ($authority === null) {
            return false;
        }
        $row = $this->row(Db::name('store_debt')
            ->where('id', (int)$authority['debt_id'])
            ->where('uid', (int)$request['member_id'])
            ->where('store_id', (int)$request['store_id'])
            ->field('id,debt_no,total_debt,repaid_debt,status')
            ->find());
        if ($row === null
            || $this->moneyCents((string)$row['total_debt']) !== $amount
            || !hash_equals((string)$authority['debt_no'], (string)$row['debt_no'])) {
            return false;
        }
        return [
            'debtId' => (int)$row['id'],
            'debtNo' => (string)$row['debt_no'],
            'amountCents' => $amount,
            'repaidAmountCents' => $this->moneyCents((string)$row['repaid_debt']),
            'status' => (int)$row['status'],
        ];
    }

    private function moneyCents(string $money): int
    {
        if (preg_match('/^(0|[1-9][0-9]*)\.([0-9]{2})$/D', $money, $match) !== 1) {
            return -1;
        }
        return (int)$match[1] * 100 + (int)$match[2];
    }

    private function committedEntitlementResult(array $request, array $receipt): ?array
    {
        if (!$this->entitlementReceiptMatchesRequest($request, $receipt)) {
            return null;
        }
        return [
            'composition' => 'entitlement_only',
            'originalIdempotencyKey' => (string)$request['last_idempotency_key'],
            'settledAt' => (int)$receipt['settled_at'],
            'checkoutRequest' => $this->checkoutRequestResult($request),
            'salesOrder' => null,
            'entitlementCompletion' => $this->entitlementReceiptResult($receipt),
        ];
    }

    private function entitlementReceiptMatchesRequest(array $request, array $receipt): bool
    {
        $invalid = !hash_equals((string)$request['request_id'], (string)$receipt['checkout_request_id'])
            || !hash_equals((string)$request['tenant_id'], (string)$receipt['tenant_id'])
            || !hash_equals((string)$request['organization_id'], (string)$receipt['organization_id'])
            || (int)$request['store_id'] !== (int)$receipt['store_id']
            || (int)($request['member_id'] ?? 0) !== (int)$receipt['member_id']
            || !hash_equals((string)$request['workspace_id'], (string)$receipt['workspace_id'])
            || !hash_equals((string)$request['state_context_id'], (string)$receipt['state_context_id'])
            || !hash_equals(
                (string)$request['last_idempotency_key'],
                (string)$receipt['command_idempotency_key']
            )
            || preg_match('/^ECR-[0-9a-f]{40}$/D', (string)$receipt['receipt_id']) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', (string)$receipt['plan_fingerprint']) !== 1
            || (string)$receipt['status'] !== 'completed'
            || (int)$receipt['settled_at'] <= 0
            || (int)$receipt['completed_at'] <= 0;
        return !$invalid;
    }

    private function entitlementReceiptResult(array $receipt): array
    {
        return [
            'receiptId' => (string)$receipt['receipt_id'],
            'receiptStatus' => (string)$receipt['status'],
            'planFingerprint' => (string)$receipt['plan_fingerprint'],
        ];
    }

    private function checkoutRequestResult(array $request): array
    {
        return [
            'requestId' => (string)$request['request_id'],
            'requestVersion' => (int)$request['request_version'],
            'requestStatus' => (string)$request['request_status'],
        ];
    }

    private function allowsCurrentScope(
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): bool {
        $mode = $dataScope->authorizationMode();
        $storeAllowed = $mode === CashierV3DataScopeContext::MODE_SELF_PARTICIPANT
            || $dataScope->allowsStore($operatorScope->storeId());
        return $mode !== CashierV3DataScopeContext::MODE_NONE
            && $storeAllowed
            && $operatorScope->storeId() === $dataScope->forcedStoreId()
            && $operatorScope->tenantId() !== ''
            && hash_equals($operatorScope->tenantId(), $dataScope->tenantId())
            && hash_equals($operatorScope->organizationId(), $dataScope->organizationId());
    }

    /** @return array<string,string> */
    private function locator(array $receipt): array
    {
        $decoded = json_decode((string)($receipt['result_json'] ?? ''), true);
        $data = is_array($decoded) && is_array($decoded['data'] ?? null)
            ? $decoded['data']
            : [];
        $candidates = [];
        foreach (['checkoutSubmission', 'checkoutResult'] as $key) {
            if (is_array($data[$key] ?? null)) {
                $candidates[] = $data[$key];
            }
        }
        $candidates[] = $data;

        $locator = [];
        foreach ($candidates as $candidate) {
            $salesOrder = is_array($candidate['salesOrder'] ?? null)
                ? $candidate['salesOrder']
                : [];
            $checkoutRequest = is_array($candidate['checkoutRequest'] ?? null)
                ? $candidate['checkoutRequest']
                : [];
            $entitlementCompletion = is_array($candidate['entitlementCompletion'] ?? null)
                ? $candidate['entitlementCompletion']
                : [];
            $requestId = trim((string)($candidate['checkoutRequestId']
                ?? ($checkoutRequest['requestId'] ?? '')));
            $orderId = trim((string)($candidate['orderId'] ?? ($salesOrder['orderId'] ?? '')));
            $orderNo = trim((string)($candidate['orderNo'] ?? ($salesOrder['orderNo'] ?? '')));
            $entitlementReceiptId = trim((string)($candidate['entitlementCompletionReceiptId']
                ?? ($candidate['receiptId'] ?? ($entitlementCompletion['receiptId'] ?? ''))));
            if (preg_match('/^CKR-[0-9a-f]{40}$/D', $requestId) === 1) {
                $locator['checkoutRequestId'] = $requestId;
            }
            if (preg_match('/^CSO-[0-9a-f]{40}$/D', $orderId) === 1) {
                $locator['orderId'] = $orderId;
            }
            if (CashierV3BusinessDocumentNumberServices::isSalesOrderNo($orderNo)) {
                $locator['orderNo'] = $orderNo;
            }
            if (preg_match('/^ECR-[0-9a-f]{40}$/D', $entitlementReceiptId) === 1) {
                $locator['entitlementReceiptId'] = $entitlementReceiptId;
            }
        }

        $businessNo = trim((string)($receipt['business_no'] ?? ''));
        if (!isset($locator['checkoutRequestId'])
            && preg_match('/^CKR-[0-9a-f]{40}$/D', $businessNo) === 1) {
            $locator['checkoutRequestId'] = $businessNo;
        }
        if (!isset($locator['orderId'])
            && preg_match('/^CSO-[0-9a-f]{40}$/D', $businessNo) === 1) {
            $locator['orderId'] = $businessNo;
        }
        if (!isset($locator['orderNo'])
            && CashierV3BusinessDocumentNumberServices::isSalesOrderNo($businessNo)) {
            $locator['orderNo'] = $businessNo;
        }
        if (!isset($locator['entitlementReceiptId'])
            && preg_match('/^ECR-[0-9a-f]{40}$/D', $businessNo) === 1) {
            $locator['entitlementReceiptId'] = $businessNo;
        }
        return $locator;
    }

    private function row($value)
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        return is_array($value) && $value !== [] ? $value : null;
    }
}
