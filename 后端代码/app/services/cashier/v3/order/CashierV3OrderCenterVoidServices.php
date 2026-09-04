<?php
declare(strict_types=1);

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3CrossStoreEntitlementPolicy;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\user\UserBalanceAtomicServices;
use app\services\cashier\v3\presale\CashierV3PresaleClaimServices;
use think\facade\Db;

/**
 * Order-center supplement/gift void entry point.
 *
 * Only V3 authority rows are accepted. Original facts remain immutable; this
 * service appends one operation row and one reversal business event.
 */
final class CashierV3OrderCenterVoidServices
{
    public const OPERATION_TABLE = 'cashier_v3_order_center_void_operation';

    /** @return array{resources:array} */
    public function discover(array $scope): array
    {
        if ((string)($scope['action'] ?? '') === 'void-order-center-supplement') {
            // The order-center row is not the cashier workspace. Discover the
            // authoritative repayment resource directly so a stale workspace
            // revision cannot block cancelling an already-settled debt.
            return (new CashierV3SupplementSalespersonAdjustmentServices())->discover($scope);
        }
        return ['resources' => []];
    }

    /** @return array<string,mixed> */
    public function executeInTx(string $action, array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('orderCenterVoid.executeInTx');
        $this->assertOperationTableReady();
        [$operator, $dataScope, $recorder, $execution] = $this->scopes($scope);
        $payload = (array)($scope['payload'] ?? []);
        $reason = trim((string)($payload['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw self::failure('请填写作废原因（不超过255字）。', 'order_center_void_reason_required');
        }
        $recordId = trim((string)($payload['recordId'] ?? $payload['id'] ?? ''));
        if ($recordId === '') throw self::failure('记录标识无效，请重新打开记录。', 'order_center_void_record_missing');
        $key = trim((string)($scope['idempotency_key'] ?? $payload['idempotencyKey'] ?? ''));
        if ($key === '') throw self::failure('作废请求缺少幂等键。', 'order_center_void_idempotency_missing');

        $existing = Db::name(self::OPERATION_TABLE)->where('tenant_id', $dataScope->tenantId())
            ->where('command_idempotency_key', $key)->lock(true)->find();
        if ($existing) return ['data' => ['void' => ['operationNo' => (string)$existing['operation_no'], 'replayed' => true]],
            'business_no' => (string)$existing['operation_no'], 'touched' => ['supplement_record'], 'message' => '该补交记录已作废。'];
        $recordOperation = Db::name(self::OPERATION_TABLE)->where('tenant_id', $dataScope->tenantId())
            ->where('record_id', $recordId)->where('status', 'succeeded')->lock(true)->find();
        if ($recordOperation) return ['data' => ['void' => ['operationNo' => (string)$recordOperation['operation_no'], 'replayed' => true]],
            'business_no' => (string)$recordOperation['operation_no'], 'touched' => ['supplement_record'], 'message' => '该补交记录已作废。'];

        if ($action === 'void-order-center-supplement') {
            return $this->voidSupplement($recordId, $reason, $key, $operator, $dataScope, $recorder, $execution, (array)($scope['event_contract'] ?? []));
        }
        if ($action === 'void-order-center-gift') {
            return $this->voidGift($recordId, $reason, $key, $operator, $dataScope, $recorder, $execution, (array)($scope['event_contract'] ?? []));
        }
        throw self::failure('暂不支持该订单中心作废类型。', 'order_center_void_action_invalid');
    }

    private function voidSupplement(string $recordId, string $reason, string $key, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, CashierV3BusinessEventRecorder $recorder, CashierV3BusinessEventExecution $execution, array $contract): array
    {
        $type = '';
        if (strpos($recordId, 'v3-sales-supplement:') === 0) { $type = 'sales'; $repaymentId = substr($recordId, 20); }
        elseif (strpos($recordId, 'v3-supplement:') === 0) { $type = 'recharge'; $repaymentId = substr($recordId, 14); }
        else throw self::failure('旧版补交记录暂不支持作废，请使用V3补交记录。', 'legacy_supplement_not_reversible');
        if ($repaymentId === '') throw self::failure('补交记录标识无效。', 'supplement_identity_invalid');

        $table = $type === 'sales' ? 'cashier_v3_debt_repayment' : 'cashier_v3_recharge_debt_repayment';
        $row = (array)Db::name($table)->where('tenant_id', $scope->tenantId())->where('repayment_id', $repaymentId)
            ->where('store_id', $operator->storeId())->lock(true)->find();
        if (!$row || (string)($row['status'] ?? '') !== 'succeeded') throw self::failure('补交记录不存在或已作废。', 'supplement_not_active');
        $amountCents = (int)($row['repayment_amount_cents'] ?? $row['amount_cents'] ?? 0);
        if ($amountCents <= 0) throw self::failure('补交金额资料异常，无法作废。', 'supplement_amount_invalid');

        $debt = (array)Db::name('store_debt')->where('id', (int)$row['debt_id'])->lock(true)->find();
        $sourceStoreId = (int)($debt['store_id'] ?? 0);
        if (!$debt || $sourceStoreId <= 0
            || (!CashierV3CrossStoreEntitlementPolicy::enabled() && $sourceStoreId !== $operator->storeId())
            || (int)($debt['uid'] ?? 0) !== (int)$row['member_id']) {
            throw self::failure('原欠款资料已变化，无法作废。', 'supplement_debt_missing');
        }
        $repaid = $this->moneyCents($debt['repaid_debt'] ?? '0');
        if ($repaid < $amountCents) throw self::failure('当前欠款金额不足以冲销该补交记录。', 'supplement_debt_projection_mismatch');

        $now = time();
        if ($type === 'recharge') {
            $balanceLedgerId = 0;
            try {
                $balanceResult = app()->make(UserBalanceAtomicServices::class)->deductPreferBen(
                    (int)$row['member_id'], $this->money($amountCents), 'recharge_debt_void', (int)$row['id'],
                    '作废充值欠款补交', 'cv3-order-center-void-' . hash('sha256', $scope->tenantId() . '|' . $key)
                );
                $balanceLedgerId = (int)($balanceResult['money_id'] ?? 0);
                if ($balanceLedgerId <= 0
                    || $this->moneyCents($balanceResult['paid_ben'] ?? '0') + $this->moneyCents($balanceResult['paid_give'] ?? '0') !== $amountCents) {
                    throw new \RuntimeException('balance_reversal_ledger_incomplete');
                }
            } catch (\Throwable $e) {
                $ledgerInvalid = $e instanceof \RuntimeException && $e->getMessage() === 'balance_reversal_ledger_incomplete';
                $message = $ledgerInvalid
                    ? '会员余额流水资料异常，无法安全作废该补交记录。'
                    : '会员本金和赠金总余额不足，无法作废该补交记录。';
                throw self::failure($message, $ledgerInvalid ? 'supplement_balance_ledger_invalid' : 'supplement_balance_insufficient');
            }
            $repaidAfter = $repaid - $amountCents;
            $total = $this->moneyCents($debt['total_debt'] ?? '0');
            Db::name('store_debt')->where('id', (int)$debt['id'])->update(['repaid_debt' => $this->money($repaidAfter), 'status' => $repaidAfter >= $total ? 1 : 0, 'update_time' => $now]);
            $items = Db::name('store_debt_item')->where('debt_id', (int)$debt['id'])->order('id asc')->lock(true)->select()->toArray();
            $allocation = $this->allocate($repaidAfter, $items);
            foreach ($items as $item) Db::name('store_debt_item')->where('id', (int)$item['id'])->update(['repaid_debt' => $this->money($allocation[(int)$item['id']] ?? 0), 'update_time' => $now]);
            Db::name('user_recharge')->where('id', (int)$row['recharge_id'])->where('uid', (int)$row['member_id'])
                ->update(['repaid_debt_amount' => $this->money($repaidAfter)]);
            $this->updateRepaymentRow($table, (int)$row['id'], $type, $now, ['balance_ledger_id' => $balanceLedgerId]);
        } else {
            $repaidAfter = $repaid - $amountCents;
            $total = $this->moneyCents($debt['total_debt'] ?? '0');
            Db::name('store_debt')->where('id', (int)$debt['id'])->update(['repaid_debt' => $this->money($repaidAfter), 'status' => $repaidAfter >= $total ? 1 : 0, 'update_time' => $now]);
            $items = Db::name('store_debt_item')->where('debt_id', (int)$debt['id'])->order('id asc')->lock(true)->select()->toArray();
            $allocation = $this->allocate($repaidAfter, $items);
            foreach ($items as $item) Db::name('store_debt_item')->where('id', (int)$item['id'])->update(['repaid_debt' => $this->money($allocation[(int)$item['id']] ?? 0), 'update_time' => $now]);
            // Sales-debt repayment authority uses the legacy V3 timestamp name
            // `update_time`; recharge-debt repayment uses `updated_at`.
            $this->updateRepaymentRow($table, (int)$row['id'], $type, $now);
        }
        $operation = $this->operation($scope, $operator, $key, $recordId, $type . '_supplement', (string)$row['repayment_id'], $reason, $now);
        $event = $recorder->recordInTx($execution, $contract, [
            'event_type' => 'debt.repayment.voided', 'aggregate_type' => 'debt_repayment', 'aggregate_id' => (string)$row['repayment_id'],
            'aggregate_version' => 2, 'event_version' => 1, 'source_type' => 'void-order-center-supplement', 'source_id' => $operation['operation_id'],
            'reversal_of' => $this->originalEventNo($scope->tenantId(), (string)$row['repayment_id'], ['debt.repaid']), 'member_id' => (int)$row['member_id'], 'business_date' => date('Y-m-d', $now),
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now, 'aggregate_name_snapshot' => (string)($row['repayment_no'] ?? ''),
            'store_name_snapshot' => (string)Db::name('system_store')->where('id', $operator->storeId())->value('name'),
            'payload' => ['recordId' => $recordId, 'amountCents' => $amountCents, 'reason' => $reason, 'operationId' => $operation['operation_id']],
        ]);
        $this->appendRepaymentFactReversals($scope->tenantId(), (string)$row['repayment_id'], (string)$operation['operation_id'], $key, $event, $operator, $now);
        Db::name(self::OPERATION_TABLE)->where('id', (int)$operation['id'])->update(['business_event_no' => (string)$event['event_no'], 'status' => 'succeeded', 'updated_at' => $now]);
        return ['data' => ['void' => ['operationNo' => $operation['operation_no'], 'replayed' => false]], 'business_no' => $operation['operation_no'], 'touched' => ['supplement_record'], 'message' => '补交记录已取消，欠款已恢复。'];
    }

    private function voidGift(string $recordId, string $reason, string $key, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, CashierV3BusinessEventRecorder $recorder, CashierV3BusinessEventExecution $execution, array $contract): array
    {
        if (strpos($recordId, 'v3-direct-gift:') !== 0) throw self::failure('该赠送记录暂不支持作废，请使用V3独立赠送记录。', 'gift_not_reversible');
        $factId = substr($recordId, 15);
        $fact = (array)Db::name('cashier_v3_gift_fact')->where('tenant_id', $scope->tenantId())->where('gift_fact_id', $factId)->where('source_type', 'direct_gift')->lock(true)->find();
        if (!$fact || (string)($fact['status'] ?? '') !== 'effective') throw self::failure('赠送记录不存在或已作废。', 'gift_not_active');
        $authority = (array)Db::name('cashier_v3_direct_gift_authority')->where('tenant_id', $scope->tenantId())->where('gift_id', (string)$fact['source_id'])->where('store_id', $operator->storeId())->lock(true)->find();
        $item = (array)Db::name('cashier_v3_direct_gift_item')->where('gift_id', (string)$fact['source_id'])->where('item_id', (string)$fact['source_detail_id'])->lock(true)->find();
        if (!$authority || !$item || (string)($authority['status'] ?? '') !== 'issued' || (string)($item['status'] ?? '') !== 'issued') throw self::failure('赠送资料已变化，无法作废。', 'gift_authority_changed');
        $kind = (string)($item['gift_kind'] ?? $fact['gift_kind'] ?? '');
        $claims = [];
        if ($kind === 'product') {
            // Gift identity is stored on the claimable-line projection. The
            // claim fact only carries claimable_line_id, so do not query
            // non-existent gift_id/gift_item_id columns on the claim table.
            $claimableLines = Db::name('cashier_v3_presale_claimable_line')
                ->where('tenant_id', $scope->tenantId())
                ->where('source_kind', 'GIFT')
                ->where('gift_id', (string)$fact['source_id'])
                ->where('gift_item_id', (string)$fact['source_detail_id'])
                ->lock(true)->select()->toArray();
            foreach ($claimableLines as $claimableLine) {
                $claims = array_merge($claims, Db::name('cashier_v3_presale_claim')
                    ->where('tenant_id', $scope->tenantId())
                    ->where('claimable_line_id', (string)$claimableLine['claimable_line_id'])
                    ->where('claim_status', 'SETTLED')
                    ->order('id', 'asc')->lock(true)->select()->toArray());
            }
        } elseif ($kind === 'project') {
            $detailId = (int)($item['benefit_detail_id'] ?? 0);
            if ($detailId > 0 && (Db::name('cashier_v3_entitlement_writeoff_fact')->where('tenant_id', $scope->tenantId())->where('source_detail_id', (string)$detailId)->count() > 0
                || Db::name('cashier_v3_service_order_line')->where('tenant_id', $scope->tenantId())->where('entitlement_source_detail_id', (string)$detailId)->count() > 0
                || Db::name('store_reservation_order')->where('cart_info_id', $detailId)->where('is_del', 0)->where('is_system_del', 0)->count() > 0
                || Db::name('cashier_v3_card_operation')->where('tenant_id', $scope->tenantId())->where('source_card_holder_id', (int)($item['card_holder_id'] ?? 0))->count() > 0)) {
                throw self::failure('赠送项目已有核销、服务、预约或卡操作记录，当前版本暂不能安全作废。', 'gift_project_usage_exists');
            }
        } elseif ($kind === 'coupon') {
            throw self::failure('优惠券赠送作废暂只支持未核销记录。', 'gift_coupon_reversal_not_supported');
        }
        $now = time();
        if ($kind === 'project') $this->revokeUnusedDirectProject($item, (int)$fact['member_id'], $scope, $now);
        $operation = $this->operation($scope, $operator, $key, $recordId, 'gift', (string)$fact['gift_fact_id'], $reason, $now);
        if ($kind === 'product' && $claims !== []) {
            $profile = $scope->operatorProfile();
            $claimScope = ['tenantId' => $scope->tenantId(), 'organizationId' => $scope->organizationId(), 'organizationPath' => '/', 'storeId' => $operator->storeId(), 'storeIds' => [$operator->storeId()], 'operatorId' => $operator->operatorId(), 'operatorType' => 'STORE_STAFF', 'operatorName' => trim((string)($profile['staff_name'] ?? $profile['name'] ?? '操作员'))];
            $claimService = new CashierV3PresaleClaimServices();
            foreach ($claims as $claim) {
                $claimService->voidInExistingTransaction($claimScope, (string)$claim['claim_id'], ['reason' => $reason, 'idempotencyKey' => $key . ':claim:' . (string)$claim['claim_id']]);
            }
        }
        if ($kind === 'product') {
            Db::name('cashier_v3_presale_claimable_line')
                ->where('tenant_id', $scope->tenantId())->where('source_kind', 'GIFT')
                ->where('gift_id', (string)$fact['source_id'])->where('gift_item_id', (string)$fact['source_detail_id'])
                ->whereIn('claim_status', ['AVAILABLE', 'FULLY_CLAIMED'])
                ->update(['claim_status' => 'CLOSED_AFTER_SALE_REVERSAL', 'close_operation_id' => $operation['operation_id'], 'closed_at' => $now, 'updated_at' => $now]);
        }
        Db::name('cashier_v3_direct_gift_item')->where('id', (int)$item['id'])->where('status', 'issued')->update(['status' => 'voided', 'voided_at' => $now, 'void_reason_snapshot' => $reason, 'updated_at' => $now]);
        $remainingItems = (int)Db::name('cashier_v3_direct_gift_item')->where('gift_id', (string)$fact['source_id'])->where('status', 'issued')->count();
        if ($remainingItems === 0) Db::name('cashier_v3_direct_gift_authority')->where('id', (int)$authority['id'])->where('status', 'issued')->update(['status' => 'voided', 'updated_at' => $now]);
        $reverse = $fact; unset($reverse['id']);
        $reverse['gift_fact_id'] = 'GFR-' . strtoupper(substr(hash('sha256', $factId . '|' . $operation['operation_id']), 0, 40));
        $reverse['natural_key'] = 'direct_gift_void:' . hash('sha256', $factId . '|' . $operation['operation_id']);
        $reverse['status'] = 'voided'; $reverse['reversal_of'] = $factId;
        $reverse['occurred_at'] = $now; $reverse['settled_at'] = $now; $reverse['recorded_at'] = $now; $reverse['created_at'] = $now; $reverse['updated_at'] = $now;
        $event = $recorder->recordInTx($execution, $contract, ['event_type' => 'gift.voided', 'aggregate_type' => 'direct_gift', 'aggregate_id' => (string)$fact['source_id'], 'aggregate_version' => 2, 'event_version' => 1, 'detail_id' => $factId, 'source_type' => 'void-order-center-gift', 'source_id' => $operation['operation_id'], 'reversal_of' => (string)$fact['business_event_no'], 'member_id' => (int)$fact['member_id'], 'business_date' => date('Y-m-d', $now), 'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now, 'aggregate_name_snapshot' => (string)$fact['content_name_snapshot'], 'store_name_snapshot' => (string)Db::name('system_store')->where('id', $operator->storeId())->value('name'), 'payload' => ['recordId' => $recordId, 'giftKind' => $kind, 'reason' => $reason]]);
        $reverse['business_event_no'] = (string)$event['event_no'];
        Db::name('cashier_v3_gift_fact')->insert($reverse);
        Db::name(self::OPERATION_TABLE)->where('id', (int)$operation['id'])->update(['business_event_no' => (string)$event['event_no'], 'status' => 'succeeded', 'updated_at' => $now]);
        return ['data' => ['void' => ['operationNo' => $operation['operation_no'], 'replayed' => false]], 'business_no' => $operation['operation_no'], 'touched' => ['cashier_workspace'], 'message' => '赠送记录已作废。'];
    }

    private function revokeUnusedDirectProject(array $item, int $memberId, CashierV3DataScopeContext $scope, int $now): void
    {
        $detailId = (int)($item['benefit_detail_id'] ?? 0); $holderId = (int)($item['card_holder_id'] ?? 0); $orderId = (int)($item['legacy_order_id'] ?? 0);
        if ($detailId <= 0 || $holderId <= 0 || $orderId <= 0) throw self::failure('赠送项目权益资料不完整，无法作废。', 'gift_project_projection_missing');
        $detail = (array)Db::name('store_order_cart_info')->where('id', $detailId)->where('uid', $memberId)->lock(true)->find();
        $holder = (array)Db::name('user_card_holder')->where('id', $holderId)->lock(true)->find();
        $order = (array)Db::name('store_order')->where('id', $orderId)->lock(true)->find();
        if (!$detail || !$holder || !$order || (int)($holder['uid'] ?? 0) !== $memberId || (int)($holder['is_del'] ?? 1) !== 0
            || (int)($holder['write_surplus_times'] ?? -1) !== (int)($holder['write_times'] ?? -2)
            || (string)($detail['source_type'] ?? '') !== 'cashier_v3_direct_gift'
            || (int)($detail['write_surplus_times'] ?? -1) !== (int)($detail['write_times'] ?? -2)) {
            throw self::failure('赠送项目权益已有变化，无法安全作废。', 'gift_project_projection_changed');
        }
        if ((int)Db::name('user_card_holder')->where('id', $holderId)->where('is_del', 0)->update(['is_del' => 1]) !== 1) throw self::failure('赠送项目权益并发变化，作废未提交。', 'gift_project_holder_race');
        if ((int)Db::name('store_order')->where('id', $orderId)->where('terminal_action', 0)->update(['terminal_action' => 3, 'terminal_action_time' => $now]) !== 1) throw self::failure('赠送项目来源订单已变化，无法安全作废。', 'gift_project_order_race');
        foreach ([['member_benefit_pool', $detailId], ['card_holder', $holderId]] as [$resourceKind, $resourceId]) {
            Db::name('cashier_v3_entitlement_resource_version')->where('resource_kind', $resourceKind)->where('resource_id', (string)$resourceId)
                ->update(['current_version' => Db::raw('current_version+1'), 'last_action' => 'void-order-center-gift', 'update_time' => $now]);
        }
    }

    private function operation(CashierV3DataScopeContext $scope, CashierV3OperatorScope $operator, string $key, string $recordId, string $kind, string $sourceId, string $reason, int $now): array
    {
        $operationId = 'OCV-' . strtoupper(substr(hash_hmac('sha256', $scope->tenantId() . '|' . $recordId . '|' . $key, (string)config('cashier_v3.checkout_namespace_secret')), 0, 40));
        $operationNo = 'ZF' . date('ymdHis', $now) . strtoupper(substr(hash('sha256', $operationId), 0, 6));
        $id = (int)Db::name(self::OPERATION_TABLE)->insertGetId(['operation_id' => $operationId, 'operation_no' => $operationNo, 'tenant_id' => $scope->tenantId(), 'store_id' => $operator->storeId(), 'operator_id' => $operator->operatorId(), 'record_id' => $recordId, 'source_kind' => $kind, 'source_id' => $sourceId, 'reason_snapshot' => $reason, 'command_idempotency_key' => $key, 'status' => 'processing', 'created_at' => $now, 'updated_at' => $now]);
        if ($id <= 0) throw self::failure('作废操作记录写入失败。', 'order_center_void_operation_insert_failed');
        return ['id' => $id, 'operation_id' => $operationId, 'operation_no' => $operationNo];
    }

    private function assertOperationTableReady(): void
    {
        $rows = Db::query(
            'SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['eb_' . self::OPERATION_TABLE]
        );
        if ((string)($rows[0]['t'] ?? $rows[0]['TABLE_NAME'] ?? '') === 'eb_' . self::OPERATION_TABLE) {
            return;
        }
        throw new CashierV3CommandException(
            CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
            '订单作废功能尚未完成数据库升级，请联系管理员完成数据库升级后重试。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => 'order_center_void_operation_table_missing', 'missing_tables' => ['eb_' . self::OPERATION_TABLE]]
        );
    }

    private function updateRepaymentRow(string $table, int $id, string $type, int $now, array $extra = []): void
    {
        $preferred = $type === 'sales' ? 'update_time' : 'updated_at';
        $fallback = $preferred === 'update_time' ? 'updated_at' : 'update_time';
        $columns = Db::query(
            'SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN (?, ?)',
            ['eb_' . $table, $preferred, $fallback]
        );
        $available = [];
        foreach ((array)$columns as $column) {
            $name = (string)($column['c'] ?? $column['COLUMN_NAME'] ?? '');
            if ($name !== '') $available[$name] = true;
        }
        $timestampField = isset($available[$preferred]) ? $preferred : (isset($available[$fallback]) ? $fallback : '');
        if ($timestampField === '') {
            throw self::failure('补交记录时间字段缺失，无法安全作废。', 'supplement_timestamp_field_missing');
        }
        $data = array_merge(['status' => 'voided', $timestampField => $now], $extra);
        if ((int)Db::name($table)->where('id', $id)->update($data) !== 1) {
            throw self::failure('补交记录并发变化，作废未提交。', 'supplement_repayment_update_race');
        }
    }

    private function allocate(int $repaidCents, array $items): array
    {
        $total = 0; foreach ($items as $item) $total += $this->moneyCents($item['debt_amount'] ?? '0');
        if ($total <= 0) return [];
        $out = []; $allocated = 0; $remainders = [];
        foreach ($items as $item) { $amount = $this->moneyCents($item['debt_amount'] ?? '0'); $product = $repaidCents * $amount; $base = intdiv($product, $total); $out[(int)$item['id']] = $base; $allocated += $base; $remainders[(int)$item['id']] = $product % $total; }
        $ids = array_keys($out); usort($ids, static fn($a, $b) => ($remainders[$b] <=> $remainders[$a]) ?: ($a <=> $b));
        for ($i = 0; $i < $repaidCents - $allocated; $i++) $out[$ids[$i % count($ids)]]++;
        return $out;
    }

    private function scopes(array $scope): array
    {
        $operator = $scope['operator_scope'] ?? null; $dataScope = $scope['data_scope'] ?? null; $recorder = $scope['event_recorder'] ?? null; $execution = $scope['event_execution'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$dataScope instanceof CashierV3DataScopeContext || !$recorder instanceof CashierV3BusinessEventRecorder || !$execution instanceof CashierV3BusinessEventExecution) throw self::failure('作废上下文不完整。', 'order_center_void_scope_incomplete');
        return [$operator, $dataScope, $recorder, $execution];
    }
    private function moneyCents($value): int
    {
        $raw = trim((string)$value);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $raw)) return 0;
        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        return ((int)$whole * 100) + (int)str_pad($fraction, 2, '0');
    }
    private function money(int $cents): string { return number_format($cents / 100, 2, '.', ''); }
    private function originalEventNo(string $tenantId, string $aggregateId, array $types): string
    {
        $query = Db::name('cashier_v3_business_event')->where('tenant_id', $tenantId)->where('aggregate_id', $aggregateId);
        if ($types !== []) $query->whereIn('event_type', $types);
        $eventNo = trim((string)$query->order('id', 'desc')->value('event_no'));
        if (!preg_match('/^EV-[A-Fa-f0-9]{32}$/', $eventNo)) throw self::failure('原始业务事件缺失，无法安全作废。', 'order_center_void_original_event_missing');
        return $eventNo;
    }
    private function appendRepaymentFactReversals(string $tenantId, string $repaymentId, string $operationId, string $commandKey, array $event, CashierV3OperatorScope $operator, int $now): void
    {
        foreach (['cashier_v3_payment_fact', 'cashier_v3_performance_fact'] as $table) {
            $rows = Db::name($table)->where('tenant_id', $tenantId)->where('order_id', $repaymentId)
                ->where('source_document_type', 'debt_repayment')->where('fact_direction', 'forward')->where('status', 'effective')->lock(true)->select()->toArray();
            foreach ($rows as $source) {
                if (Db::name($table)->where('tenant_id', $tenantId)->where('reversal_of', (string)$source['fact_id'])->where('fact_direction', 'reversal')->where('status', 'effective')->count() > 0) continue;
                $copy = $source; unset($copy['id']);
                $copy['fact_id'] = 'OCV-' . strtoupper(substr(hash_hmac('sha256', $table . '|' . $source['fact_id'] . '|' . $operationId, (string)config('cashier_v3.checkout_namespace_secret')), 0, 40));
                $copy['business_event_no'] = (string)$event['event_no']; $copy['fact_direction'] = 'reversal';
                $copy['natural_key'] = 'order_center_void:' . hash('sha256', $table . '|' . $source['fact_id'] . '|' . $operationId);
                $copy['command_idempotency_key'] = $commandKey; $copy['fact_version'] = 1; $copy['reversal_of'] = (string)$source['fact_id'];
                $copy['operator_id'] = $operator->operatorId(); $copy['business_date'] = date('Y-m-d', $now);
                $copy['occurred_at'] = $now; $copy['settled_at'] = $now; $copy['recorded_at'] = $now;
                foreach ($table === 'cashier_v3_performance_fact' ? ['allocation_base_amount_cents', 'amount_cents', 'labor_fee_amount_cents'] : ['amount_cents'] as $column) {
                    if (array_key_exists($column, $copy)) $copy[$column] = -(int)$source[$column];
                }
                $copy['immutable_fingerprint'] = hash('sha256', json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                if ((int)Db::name($table)->insert($copy) !== 1) throw self::failure('order_center_void_fact_reversal_insert_failed', 'order_center_void_fact_reversal_insert_failed');
            }
        }
    }
    private static function failure(string $message, string $reason): CashierV3CommandException { return new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, $message, CashierV3ResultCode::STATUS_FAILED, ['reason' => $reason]); }
}
