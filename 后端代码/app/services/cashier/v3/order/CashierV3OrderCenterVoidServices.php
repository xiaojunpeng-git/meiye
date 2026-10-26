<?php
declare(strict_types=1);

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3CrossStoreEntitlementPolicy;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices;
use app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard;
use app\services\cashier\v3\cashier\CashierV3EntitlementResourceVersionProvider;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\user\UserBalanceAtomicServices;
use app\services\cashier\v3\presale\CashierV3PresaleClaimServices;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use app\services\report\StoreReportPaymentSaleAllocationFactServices;
use think\facade\Db;

/**
 * Order-center supplement/gift/project-replacement void entry point.
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
        if ($existing) {
            // A command key cannot be replayed for a different record/action;
            // gift replays must also stay in the original handling store.
            if ((in_array($action, ['void-order-center-project-replacement', 'void-order-center-refund', 'void-order-center-gift'], true)
                    && (int)$existing['store_id'] !== $operator->storeId())
                || (string)$existing['record_id'] !== $recordId || (string)$existing['source_kind'] !== $this->voidSourceKind($action, $recordId)
                || (string)$existing['status'] !== 'succeeded') {
                throw self::failure('作废请求与原记录不一致，请重新打开记录。', 'order_center_void_replay_mismatch');
            }
            return $this->replayedVoid($existing, $action);
        }
        $recordOperation = Db::name(self::OPERATION_TABLE)->where('tenant_id', $dataScope->tenantId())
            ->where('record_id', $recordId)->where('status', 'succeeded')->lock(true)->find();
        if ($recordOperation) {
            // Changing the command key cannot bypass the gift's store boundary.
            if ((in_array($action, ['void-order-center-project-replacement', 'void-order-center-refund', 'void-order-center-gift'], true)
                    && (int)$recordOperation['store_id'] !== $operator->storeId())
                || (string)$recordOperation['source_kind'] !== $this->voidSourceKind($action, $recordId)) {
                throw self::failure('记录作废类型不一致。', 'order_center_void_kind_mismatch');
            }
            // 另一个幂等键不能把已完成作废伪装成一次新事件；同键重放由网关回执处理。
            if ($action === 'void-order-center-refund') {
                throw self::failure('这笔退款已经作废，请刷新退款记录。', 'refund_void_already_completed');
            }
            return $this->replayedVoid($recordOperation, $action);
        }

        if ($action === 'void-order-center-supplement') {
            return $this->voidSupplement($recordId, $reason, $key, $operator, $dataScope, $recorder, $execution, (array)($scope['event_contract'] ?? []));
        }
        if ($action === 'void-order-center-gift') {
            return $this->voidGift($recordId, $reason, $key, $operator, $dataScope, $recorder, $execution, (array)($scope['event_contract'] ?? []));
        }
        if ($action === 'void-order-center-project-replacement') {
            return $this->voidProjectReplacement($recordId, $reason, $key, $operator, $dataScope, $recorder, $execution, (array)($scope['event_contract'] ?? []));
        }
        if ($action === 'void-order-center-refund') {
            return (new CashierV3RefundVoidServices())->executeInTx($recordId, $reason, $key,
                $operator, $dataScope, $recorder, $execution, (array)($scope['event_contract'] ?? []));
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
        // 独立免除是欠款终态，不能通过补交作废重新恢复应收或撤回已收款。
        if ((int)$debt['status'] === CashierV3DebtCancellationServices::CANCELLED_STATUS) {
            throw self::failure('欠款已取消，不能作废补交记录。', 'supplement_debt_cancelled');
        }
        if ($repaid < $amountCents) throw self::failure('当前欠款金额不足以冲销该补交记录。', 'supplement_debt_projection_mismatch');
        if ($type === 'sales') {
            // Do not reopen the receivable after its paid rights moved into a wallet.
            \app\services\cashier\v3\card\CashierV3CardConversionGuard::assertDebtNotConverted($debt, $scope->tenantId(), $repaymentId);
        }

        $now = time();
        $balanceResult = null;
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
            // A sales-debt balance tender is reversed by its original split, not principal-first again.
            $balanceResult = $this->restoreSalesDebtBalance($row, $scope, $operator, $key);
            $repaidAfter = $repaid - $amountCents;
            $total = $this->moneyCents($debt['total_debt'] ?? '0');
            Db::name('store_debt')->where('id', (int)$debt['id'])->update(['repaid_debt' => $this->money($repaidAfter), 'status' => $repaidAfter >= $total ? 1 : 0, 'update_time' => $now]);
            $items = Db::name('store_debt_item')->where('debt_id', (int)$debt['id'])->order('id asc')->lock(true)->select()->toArray();
            $frozen = Db::name(\app\services\cashier\v3\settlement\CashierV3SalesDebtBalanceServices::TABLE)
                ->where('tenant_id', $scope->tenantId())->where('repayment_id', $repaymentId)->lock(true)->select()->toArray();
            $allocation = $this->allocate($repaidAfter, $items);
            // A legacy row has no immutable per-item increment. Do not let its old cumulative
            // reallocation move a later frozen tender; void those later repayments first.
            if ($frozen === [] && Db::name(\app\services\cashier\v3\settlement\CashierV3SalesDebtBalanceServices::TABLE)->alias('s')
                ->join('cashier_v3_debt_repayment r', 'r.repayment_id=s.repayment_id AND r.tenant_id=s.tenant_id')
                ->where('r.tenant_id', $scope->tenantId())->where('r.debt_id', (int)$debt['id'])->where('r.status', 'succeeded')->count() > 0) {
                throw self::failure('该历史补交已有后续明细结算，请先作废后续补交再作废本笔。', 'supplement_legacy_reallocation_conflict');
            }
            if ($frozen !== []) {
                if (count($frozen) !== count($items) || array_sum(array_column($frozen, 'amount_cents')) !== $amountCents) {
                    throw self::failure('补交明细分摊不完整，无法作废。', 'supplement_item_settlement_invalid');
                }
                $byItem = array_column($frozen, null, 'debt_item_id');
                foreach ($items as $item) {
                    $itemId = (int)$item['id'];
                    if (!isset($byItem[$itemId])) throw self::failure('补交明细缺失。', 'supplement_item_settlement_missing');
                    $allocation[$itemId] = $this->moneyCents($item['repaid_debt']) - (int)$byItem[$itemId]['amount_cents'];
                    if ($allocation[$itemId] < 0) throw self::failure('补交明细已变化。', 'supplement_item_settlement_conflict');
                }
            }
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
        $this->appendRepaymentFactReversals($scope->tenantId(), (string)$row['repayment_id'], $type, $amountCents, (string)$operation['operation_id'], $key, $event, $operator, $scope, $now, $balanceResult);
        Db::name(self::OPERATION_TABLE)->where('id', (int)$operation['id'])->update(['business_event_no' => (string)$event['event_no'], 'status' => 'succeeded', 'updated_at' => $now]);
        return ['data' => ['void' => ['operationNo' => $operation['operation_no'], 'replayed' => false]], 'business_no' => $operation['operation_no'], 'touched' => ['supplement_record'], 'message' => '补交记录已取消，欠款已恢复。'];
    }

    /**
     * 只撤回当前门店独立赠送的这一条内容；权益变更、作废操作和反向事实共用外层事务。
     * 优惠券按发放时保存的券 ID 撤回，不按券名称或会员名下同模板的其他券猜测关联。
     */
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
        }
        $now = time();
        $couponRevocation = [];
        if ($kind === 'coupon') $couponRevocation = $this->revokeUnusedDirectCoupons($item, $fact, $authority);
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
        if ($kind === 'coupon') {
            // 原赠送事实不可覆盖；反向事实记录本次作废的操作人、请求与业务日。
            $reverse['command_idempotency_key'] = $key;
            $reverse['operator_id'] = $operator->operatorId();
            $reverse['business_date'] = date('Y-m-d', $now);
        }
        $reverse['occurred_at'] = $now; $reverse['settled_at'] = $now; $reverse['recorded_at'] = $now; $reverse['created_at'] = $now; $reverse['updated_at'] = $now;
        $event = $recorder->recordInTx($execution, $contract, ['event_type' => 'gift.voided', 'aggregate_type' => 'direct_gift', 'aggregate_id' => (string)$fact['source_id'], 'aggregate_version' => 2, 'event_version' => 1, 'detail_id' => $factId, 'source_type' => 'void-order-center-gift', 'source_id' => $operation['operation_id'], 'reversal_of' => (string)$fact['business_event_no'], 'member_id' => (int)$fact['member_id'], 'business_date' => date('Y-m-d', $now), 'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now, 'aggregate_name_snapshot' => (string)$fact['content_name_snapshot'], 'store_name_snapshot' => (string)Db::name('system_store')->where('id', $operator->storeId())->value('name'), 'payload' => array_merge(['recordId' => $recordId, 'giftKind' => $kind, 'reason' => $reason], $couponRevocation)]);
        $reverse['business_event_no'] = (string)$event['event_no'];
        Db::name('cashier_v3_gift_fact')->insert($reverse);
        Db::name(self::OPERATION_TABLE)->where('id', (int)$operation['id'])->update(['business_event_no' => (string)$event['event_no'], 'status' => 'succeeded', 'updated_at' => $now]);
        return ['data' => ['void' => ['operationNo' => $operation['operation_no'], 'replayed' => false]], 'business_no' => $operation['operation_no'], 'touched' => ['cashier_workspace'], 'message' => '赠送记录已作废。'];
    }

    /**
     * 项目替换无收款事实；后续替换已完整冲销且权益没有后续使用时，可按倒序继续反转。
     * 原操作及项目行不删除，作废操作、规则状态和权益版本在同一事务内落地。
     */
    private function voidProjectReplacement(string $recordId, string $reason, string $key, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, CashierV3BusinessEventRecorder $recorder, CashierV3BusinessEventExecution $execution, array $contract): array
    {
        if (!preg_match('/^card-operation:([1-9][0-9]*)$/D', $recordId, $matches)) {
            throw self::failure('仅支持作废V3项目替换记录。', 'replacement_void_v3_only');
        }
        $original = (array)Db::name('cashier_v3_card_operation')->where('id', (int)$matches[1])
            ->where('tenant_id', $scope->tenantId())->where('store_id', $operator->storeId())->find();
        if (!$original || (string)$original['operation_type'] !== 'project_replacement'
            || (string)$original['operation_status'] !== 'succeeded') {
            throw self::failure('项目替换记录不存在或不可作废。', 'replacement_void_original_invalid');
        }
        $operationId = (string)$original['operation_id'];
        // Match forward conversion/card commands and legacy terminal writers:
        // origin -> holder -> state, then revalidate the immutable operation.
        // Only inspect/lock operation history after this common serialization point.
        $order = (array)Db::name('store_order')->where('id', (int)$original['origin_order_id'])->lock(true)->find();
        $holder = (array)Db::name('user_card_holder')->where('id', (int)$original['source_card_holder_id'])->lock(true)->find();
        $state = (array)Db::name('cashier_v3_card_state')->where('tenant_id', $scope->tenantId())
            ->where('card_holder_id', (int)$original['source_card_holder_id'])->lock(true)->find();
        $lockedOriginal = (array)Db::name('cashier_v3_card_operation')->where('id', (int)$matches[1])
            ->where('tenant_id', $scope->tenantId())->where('store_id', $operator->storeId())->lock(true)->find();
        if ($lockedOriginal !== $original) {
            throw self::failure('项目替换记录已经变化，请刷新后重试。', 'replacement_void_original_changed');
        }
        // A waiter can have observed no reversal before blocking on the holder.
        // Check again under the shared lock before restoring a single entitlement.
        $completedVoid = Db::name(self::OPERATION_TABLE)->where('tenant_id', $scope->tenantId())
            ->where('record_id', $recordId)->where('source_kind', 'project_replacement')
            ->where('status', 'succeeded')->lock(true)->find();
        if ($completedVoid) return $this->replayedVoid((array)$completedVoid, 'void-order-center-project-replacement');
        $snapshot = json_decode((string)$original['result_snapshot_json'], true);
        if (!$state || !$holder || !$order || !is_array($snapshot) || (int)$original['settled_at'] <= 0
            || (string)$state['card_status'] !== 'enabled'
            || (int)$state['current_member_id'] !== (int)$original['member_id_after']
            || (int)$holder['uid'] !== (int)$original['member_id_after'] || (int)$holder['is_del'] !== 0
            || (int)$holder['oid'] !== (int)$original['origin_order_id']
            || (int)$order['uid'] !== (int)$original['origin_member_id']
            || (int)$order['terminal_action'] !== 0 || (int)$order['card_upgrade_use_oid'] !== 0) {
            throw self::failure('原卡或订单已有后续变化，不能作废项目替换。', 'replacement_void_card_changed');
        }
        $this->assertReplacementVoidChain($original, $snapshot, $state, $scope);
        $lines = Db::name('cashier_v3_card_operation_line')->where('tenant_id', $scope->tenantId())
            ->where('operation_id', $operationId)->order('line_no', 'asc')->lock(true)->select()->toArray();
        $sourceLines = array_values(array_filter($lines, static function (array $line): bool {
            return (string)$line['line_role'] === 'source_project';
        }));
        $targetLines = array_values(array_filter($lines, static function (array $line): bool {
            return (string)$line['line_role'] === 'target_project';
        }));
        if ($sourceLines === [] || count($targetLines) !== 1 || count($lines) !== count($sourceLines) + 1) {
            throw self::failure('项目替换快照不完整，不能安全作废。', 'replacement_void_lines_invalid');
        }
        $target = (array)Db::name('store_order_cart_info')->where('oid', (int)$original['origin_order_id'])
            ->where('cart_id', 'cop' . substr(hash('sha256', $operationId), 0, 28))->lock(true)->find();
        $targetSnapshot = json_decode((string)($target['cart_info'] ?? ''), true);
        $targetQuantity = (int)$targetLines[0]['quantity_after'];
        if (!$target || !is_array($targetSnapshot) || (string)($targetSnapshot['sourceType'] ?? '') !== 'cashier_v3_project_replacement'
            || (string)($targetSnapshot['cardOperationId'] ?? '') !== $operationId
            || (int)$target['uid'] !== (int)$original['origin_member_id']
            || (int)$target['cart_type'] !== 2 || (int)$target['product_type'] !== 6
            || (int)$target['product_id'] !== (int)$targetLines[0]['target_catalog_id']
            || $targetQuantity <= 0 || (int)$target['write_times'] !== $targetQuantity
            || (int)$target['write_surplus_times'] !== $targetQuantity
            || (int)$target['cart_num'] !== $targetQuantity
            || (int)$target['surplus_num'] !== $targetQuantity
            || (int)$target['split_surplus_num'] !== $targetQuantity
            || (int)$target['refund_num'] !== 0 || (int)$target['is_writeoff'] !== 0) {
            throw self::failure('目标项目权益已使用或变化，不能作废项目替换。', 'replacement_void_target_changed');
        }
        $sourceIds = [];
        foreach ($sourceLines as $line) {
            $detailId = (int)$line['source_detail_id'];
            $before = (int)$line['quantity_before'];
            $after = (int)$line['quantity_after'];
            $detail = (array)Db::name('store_order_cart_info')->where('id', $detailId)
                ->where('oid', (int)$original['origin_order_id'])->lock(true)->find();
            if ($detailId <= 0 || isset($sourceIds[$detailId]) || !$detail || $before <= $after
                || $after < 0 || $before > (int)$detail['write_times']
                || (int)$detail['uid'] !== (int)$original['origin_member_id']
                || (int)$detail['cart_type'] !== 2 || (int)$detail['product_type'] !== 6
                || (int)$detail['write_surplus_times'] !== $after
                || (int)$detail['is_writeoff'] !== ($after === 0 ? 1 : 0)) {
                throw self::failure('原项目权益已变化，不能作废项目替换。', 'replacement_void_source_changed');
            }
            $sourceIds[$detailId] = true;
        }
        $affectedIds = array_merge(array_keys($sourceIds), [(int)$target['id']]);
        // 已发生的服务、核销或预约即使后来取消，也不自动撤销其历史依赖链。
        if (Db::name('cashier_v3_entitlement_writeoff_fact')->where('tenant_id', $scope->tenantId())
                ->whereIn('source_detail_id', $affectedIds)->where('occurred_at', '>=', (int)$original['settled_at'])->count() > 0
            || Db::name('cashier_v3_service_order_operation')->where('tenant_id', $scope->tenantId())
                ->whereIn('entitlement_source_detail_id', $affectedIds)->where('occurred_at', '>=', (int)$original['settled_at'])->count() > 0
            || Db::name('cashier_v3_service_order_line')->where('tenant_id', $scope->tenantId())
                ->whereIn('entitlement_source_detail_id', $affectedIds)->where('status', 'ACTIVE')->count() > 0
            || Db::name('cashier_v3_reservation_entitlement_occupation')->where('tenant_id', $scope->tenantId())
                ->whereIn('entitlement_source_detail_id', $affectedIds)->where('status', 'ACTIVE')->count() > 0
            || Db::name('store_reservation_order')->whereIn('cart_info_id', $affectedIds)
                ->where('is_del', 0)->where('is_system_del', 0)->count() > 0) {
            throw self::failure('相关项目已有服务、核销或预约记录，不能作废项目替换。', 'replacement_void_downstream_use');
        }

        $now = time();
        $void = $this->operation($scope, $operator, $key, $recordId, 'project_replacement', $operationId, $reason, $now);
        (new CashierV3CardRuleEntitlementAuthorityServices())->reverseProjectComponentsInTx(
            $scope->tenantId(), (int)$original['source_card_holder_id'], $sourceLines, (int)$target['id'], $operationId, $now
        );
        foreach ($sourceLines as $line) {
            $after = (int)$line['quantity_after'];
            if ((int)Db::name('store_order_cart_info')->where('id', (int)$line['source_detail_id'])
                ->where('write_surplus_times', $after)->update([
                    'write_surplus_times' => (int)$line['quantity_before'], 'is_writeoff' => 0,
                ]) !== 1) throw self::failure('原项目权益并发变化，作废未提交。', 'replacement_void_source_race');
        }
        if ((int)Db::name('store_order_cart_info')->where('id', (int)$target['id'])
            ->where('write_surplus_times', $targetQuantity)->where('refund_num', 0)->update([
                'write_surplus_times' => 0, 'surplus_num' => 0, 'split_surplus_num' => 0,
                'refund_num' => $targetQuantity, 'is_writeoff' => 0,
            ]) !== 1) throw self::failure('目标项目权益并发变化，作废未提交。', 'replacement_void_target_race');
        if ((int)Db::name('cashier_v3_card_state')->where('id', (int)$state['id'])
            ->where('current_version', (int)$state['current_version'])->where('last_operation_id', (string)$state['last_operation_id'])
            ->update(['current_version' => (int)$state['current_version'] + 1,
                'last_operation_id' => (string)$void['operation_id'], 'status_reason_snapshot' => $reason, 'updated_at' => $now]) !== 1) {
            throw self::failure('原卡状态并发变化，作废未提交。', 'replacement_void_card_race');
        }
        $versions = new CashierV3EntitlementResourceVersionProvider(new CashierV3CashierReadinessGuard());
        foreach (array_keys($sourceIds) as $detailId) {
            $versions->synchronizeProjectionVersion('member_benefit_pool', (string)$detailId, $operator, $scope);
        }
        $versions->synchronizeProjectionVersion('card_holder', (string)$original['source_card_holder_id'], $operator, $scope);
        if ((int)Db::name('cashier_v3_entitlement_resource_version')->where('resource_kind', 'member_benefit_pool')
            ->where('resource_id', (string)$target['id'])->where('current_version', '>', 0)
            ->update(['current_version' => Db::raw('current_version+1'), 'last_action' => 'void-order-center-project-replacement', 'update_time' => $now]) !== 1) {
            throw self::failure('目标项目版本异常，作废未提交。', 'replacement_void_target_version_missing');
        }
        $event = $recorder->recordInTx($execution, $contract, [
            'event_type' => 'card.operation.voided', 'aggregate_type' => 'card_operation', 'aggregate_id' => $operationId,
            'aggregate_version' => (int)$state['current_version'] + 1, 'event_version' => 1,
            'source_type' => 'void-order-center-project-replacement', 'source_id' => (string)$void['operation_id'],
            'reversal_of' => $this->originalEventNo($scope->tenantId(), $operationId, ['card.operation.recorded']),
            'member_id' => (int)$original['member_id_after'], 'business_date' => date('Y-m-d', $now),
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'aggregate_name_snapshot' => (string)$original['card_name_snapshot'],
            'store_name_snapshot' => (string)$original['store_name_snapshot'],
            'payload' => ['recordId' => $recordId, 'reason' => $reason, 'sourceDetailIds' => array_keys($sourceIds), 'targetDetailId' => (int)$target['id']],
        ]);
        Db::name(self::OPERATION_TABLE)->where('id', (int)$void['id'])
            ->update(['business_event_no' => (string)$event['event_no'], 'status' => 'succeeded', 'updated_at' => $now]);
        return ['data' => ['void' => ['operationNo' => $void['operation_no'], 'replayed' => false]],
            'business_no' => $void['operation_no'], 'touched' => ['source_card'], 'message' => '项目替换已作废，原项目次数已恢复。'];
    }

    /**
     * A reversal advances the card version; it must never rewind it to an old snapshot.
     * Starting at the requested replacement, prove every later version is either a
     * replacement already reversed or its matching immutable reversal event. This
     * permits reverse-order cancellation without treating arbitrary newer card changes
     * (transfer/extension/upgrade, missing audit, or version gaps) as harmless.
     * The caller holds the card-state lock; ledger reads use current locks as well,
     * avoiding a stale repeatable-read snapshot after waiting for another command.
     */
    private function assertReplacementVoidChain(array $original, array $snapshot, array $state, CashierV3DataScopeContext $scope): void
    {
        $base = (int)($snapshot['stateVersionAfter'] ?? 0);
        $current = (int)$state['current_version'];
        $reject = static function (): void {
            throw self::failure('请先从最新一笔开始逐笔作废；原卡存在未撤销的后续操作或流水不完整。', 'replacement_void_card_changed');
        };
        if ($base <= 1 || $base !== (int)$original['source_card_holder_version'] + 1 || $current < $base) $reject();
        if ($current === $base) {
            if ((string)$state['last_operation_id'] !== (string)$original['operation_id']) $reject();
            return;
        }
        // Bound the audit walk; never silently truncate a long or corrupt chain.
        if ($current - $base > 1000) $reject();
        $later = Db::name('cashier_v3_card_operation')->where('tenant_id', $scope->tenantId())
            ->where('source_card_holder_id', (int)$original['source_card_holder_id'])
            ->where('source_card_holder_version', '>=', $base)
            ->order('id', 'asc')->limit(1001)->lock(true)->select()->toArray();
        if (count($later) > 1000) $reject();
        $steps = []; $reversed = [];
        foreach ($later as $operation) {
            $result = json_decode((string)$operation['result_snapshot_json'], true);
            $version = (int)($result['stateVersionAfter'] ?? 0);
            if ((string)$operation['operation_type'] !== 'project_replacement'
                || (string)$operation['operation_status'] !== 'succeeded'
                || (int)$operation['origin_order_id'] !== (int)$original['origin_order_id']
                || (int)$operation['member_id_after'] !== (int)$original['member_id_after']
                || $version !== (int)$operation['source_card_holder_version'] + 1
                || $version > $current || isset($steps[$version])) $reject();
            $steps[$version] = (string)$operation['operation_id'];
            $void = (array)Db::name(self::OPERATION_TABLE)->where('tenant_id', $scope->tenantId())
                ->where('record_id', 'card-operation:' . $operation['id'])
                ->where('source_kind', 'project_replacement')->where('status', 'succeeded')->lock(true)->find();
            if (!$void || (string)$void['source_id'] !== (string)$operation['operation_id']
                || (int)$void['store_id'] !== (int)$operation['store_id']) $reject();
            $event = (array)Db::name('cashier_v3_business_event')->where('tenant_id', $scope->tenantId())
                ->where('event_no', (string)$void['business_event_no'])->where('event_type', 'card.operation.voided')
                ->where('aggregate_type', 'card_operation')->where('aggregate_id', (string)$operation['operation_id'])
                ->where('source_type', 'void-order-center-project-replacement')->where('source_id', (string)$void['operation_id'])
                ->where('command_idempotency_key', (string)$void['command_idempotency_key'])->lock(true)->find();
            $voidVersion = (int)($event['aggregate_version'] ?? 0);
            if (!$event || $voidVersion <= $version || $voidVersion > $current
                || isset($reversed[$voidVersion])
                || (string)$event['reversal_of'] !== $this->originalEventNo($scope->tenantId(), (string)$operation['operation_id'], ['card.operation.recorded'])) $reject();
            $reversed[$voidVersion] = (string)$void['operation_id'];
        }
        foreach ($reversed as $version => $operationId) {
            if (isset($steps[$version])) $reject();
            $steps[$version] = $operationId;
        }
        if (count($steps) !== $current - $base) $reject();
        for ($version = $base + 1; $version <= $current; $version++) {
            if (!isset($steps[$version])) $reject();
        }
        if (($steps[$current] ?? '') !== (string)$state['last_operation_id']) $reject();
    }

    /** Preserve each existing void type's idempotent response without sharing its business identity. */
    private function replayedVoid(array $row, string $action): array
    {
        $replacement = $action === 'void-order-center-project-replacement';
        $refund = $action === 'void-order-center-refund';
        return ['data' => ['void' => ['operationNo' => (string)$row['operation_no'], 'replayed' => true]],
            'business_no' => (string)$row['operation_no'],
            'touched' => [$replacement ? 'source_card' : ($refund ? 'order_center' : ($action === 'void-order-center-gift' ? 'cashier_workspace' : 'supplement_record'))],
            'message' => $replacement ? '该项目替换已作废。' : ($refund ? '该退款记录已作废。' : ($action === 'void-order-center-gift' ? '该赠送记录已作废。' : '该补交记录已作废。'))];
    }

    private function voidSourceKind(string $action, string $recordId): string
    {
        if ($action === 'void-order-center-project-replacement') return 'project_replacement';
        if ($action === 'void-order-center-refund') return 'manual_refund';
        if ($action === 'void-order-center-gift') return 'gift';
        if ($action === 'void-order-center-supplement') {
            return strpos($recordId, 'v3-sales-supplement:') === 0 ? 'sales_supplement' : 'recharge_supplement';
        }
        throw self::failure('暂不支持该订单中心作废类型。', 'order_center_void_action_invalid');
    }

    /**
     * 撤回独立赠送的全部未使用券，并恢复限量发券额度；任一券已使用/转赠/资料不全则整笔失败。
     * 先锁模板、再按券 ID 排序加锁，与发券顺序一致；核销和转赠共用券行锁及未使用 CAS。
     * 发券流水仍是发生过的历史，不删除、不猜测流水 ID；券 ID 和恢复数量写入作废事件追溯。
     */
    private function revokeUnusedDirectCoupons(array $item, array $fact, array $authority): array
    {
        $ids = json_decode((string)($item['coupon_user_ids_json'] ?? ''), true);
        $quantity = (int)($item['quantity'] ?? 0);
        $issueId = (int)($item['coupon_issue_id'] ?? 0);
        $memberId = (int)($authority['member_id'] ?? 0);
        if (!is_array($ids) || $quantity <= 0 || $quantity > 999 || count($ids) !== $quantity
            || array_keys($ids) !== range(0, $quantity - 1) || $issueId <= 0 || $memberId <= 0
            || (string)$fact['tenant_id'] !== (string)$authority['tenant_id']
            || (int)$fact['store_id'] !== (int)$authority['store_id']
            || (int)$fact['member_id'] !== $memberId || (string)$fact['gift_kind'] !== 'coupon'
            || (int)$fact['quantity'] !== $quantity || (int)$fact['coupon_issue_id'] !== $issueId) {
            throw self::failure('赠送优惠券关联资料不完整，作废未提交。', 'gift_coupon_projection_invalid');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0) throw self::failure('赠送优惠券标识无效，作废未提交。', 'gift_coupon_identity_invalid');
        }
        if (count(array_unique($ids)) !== $quantity) throw self::failure('赠送优惠券标识重复，作废未提交。', 'gift_coupon_identity_duplicate');
        sort($ids, SORT_NUMERIC);
        // 模板下架不影响撤回已发券，但模板缺失或额度异常时不得凭空恢复库存。
        $issue = (array)Db::name('store_coupon_issue')->where('id', $issueId)->lock(true)->find();
        if (!$issue) throw self::failure('优惠券发放资料缺失，作废未提交。', 'gift_coupon_issue_missing');
        $coupons = Db::name('store_coupon_user')->whereIn('id', $ids)->order('id', 'asc')->lock(true)->select()->toArray();
        if (count($coupons) !== $quantity) throw self::failure('赠送优惠券资料缺失，作废未提交。', 'gift_coupon_user_missing');
        foreach ($coupons as $coupon) {
            if ((int)$coupon['uid'] !== $memberId || (int)$coupon['cid'] !== $issueId
                || (string)$coupon['type'] !== 'cashier_v3_direct_gift'
                || (int)$coupon['status'] !== 0 || (int)$coupon['is_fail'] !== 0
                || (int)$coupon['use_time'] !== 0 || (int)$coupon['oid'] !== 0) {
                throw self::failure('赠送优惠券已使用、转赠或状态已变化，不能作废。', 'gift_coupon_already_changed');
            }
        }
        $finite = (int)$issue['total_count'] > 0;
        if ($finite && ((int)$issue['remain_count'] < 0 || (int)$issue['remain_count'] + $quantity > (int)$issue['total_count'])) {
            throw self::failure('优惠券发放额度异常，作废未提交，请联系管理员核对。', 'gift_coupon_inventory_invalid');
        }
        $affected = Db::name('store_coupon_user')->whereIn('id', $ids)->where('uid', $memberId)->where('cid', $issueId)
            ->where('type', 'cashier_v3_direct_gift')->where('status', 0)->where('is_fail', 0)->where('use_time', 0)->where('oid', 0)
            ->update(['status' => 2, 'is_fail' => 1]);
        if ((int)$affected !== $quantity) throw self::failure('优惠券状态并发变化，作废未提交。', 'gift_coupon_revoke_race');
        if ($finite && (int)Db::name('store_coupon_issue')->where('id', $issueId)->inc('remain_count', $quantity)->update() !== 1) {
            throw self::failure('优惠券发放额度恢复失败，作废未提交。', 'gift_coupon_inventory_restore_failed');
        }
        return ['couponUserIds' => $ids, 'couponIssueId' => $issueId, 'restoredCouponInventoryCount' => $finite ? $quantity : 0];
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

    public function operation(CashierV3DataScopeContext $scope, CashierV3OperatorScope $operator, string $key, string $recordId, string $kind, string $sourceId, string $reason, int $now): array
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
    /** Reverse exactly the facts owned by this repayment in the void transaction. */
    private function appendRepaymentFactReversals(string $tenantId, string $repaymentId, string $type, int $amountCents, string $operationId, string $commandKey, array $event, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $now, ?array $balanceResult): void
    {
        $documentType = $type === 'sales' ? 'debt_repayment' : 'recharge_debt_repayment';
        $cashCents = $amountCents - ($type === 'sales'
            ? \app\services\cashier\v3\settlement\CashierV3SalesDebtBalanceServices::balanceCents($repaymentId) : 0);
        $paymentReversals = [];
        foreach (['cashier_v3_payment_fact', 'cashier_v3_performance_fact'] as $table) {
            $rows = Db::name($table)->where('tenant_id', $tenantId)->where('order_id', $repaymentId)
                ->where('source_document_type', $documentType)->where('fact_direction', 'forward')->where('status', 'effective')->lock(true)->select()->toArray();
            if ($table === 'cashier_v3_payment_fact' && $rows === [] && $cashCents !== 0) throw self::failure('补交收款事实缺失，无法安全作废。', 'supplement_payment_fact_missing');
            if ($table === 'cashier_v3_payment_fact' && array_sum(array_map(static function (array $row): int { return (int)$row['amount_cents']; }, $rows)) !== $cashCents) {
                throw self::failure('补交金额与收款事实不一致，无法安全作废。', 'supplement_payment_fact_amount_mismatch');
            }
            foreach ($rows as $source) {
                // A prior reversal without this successful operation is a
                // conflicting fact, never a reason to accept a partial void.
                if (Db::name($table)->where('tenant_id', $tenantId)->where('reversal_of', (string)$source['fact_id'])->where('fact_direction', 'reversal')->where('status', 'effective')->count() > 0) {
                    throw self::failure('补交事实已存在其他冲销，无法安全作废。', 'supplement_fact_already_reversed');
                }
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
                if ($table === 'cashier_v3_payment_fact') $paymentReversals[] = $copy;
            }
        }
        if ($type === 'sales') {
            $this->appendSalesRepaymentAllocationReversals($tenantId, $repaymentId, $paymentReversals, $event, $operator, $now);
            if ($balanceResult !== null) $this->appendRepaymentBalanceReversal($tenantId, $repaymentId, $operationId, $commandKey, $event, $operator, $scope, $now, $balanceResult, true);
        } elseif ($balanceResult !== null) {
            $this->appendRepaymentBalanceReversal($tenantId, $repaymentId, $operationId, $commandKey, $event, $operator, $scope, $now, $balanceResult);
        }
    }

    /** Sales repayment allocations belong to the original sale, but their payment identity belongs to this repayment. */
    private function appendSalesRepaymentAllocationReversals(string $tenantId, string $repaymentId, array $payments, array $event, CashierV3OperatorScope $operator, int $now): void
    {
        $allocations = new StoreReportPaymentSaleAllocationFactServices();
        foreach ($payments as $payment) {
            $originals = Db::name(StoreReportPaymentSaleAllocationFactServices::TABLE)
                ->where('tenant_id', $tenantId)->where('payment_fact_id', (string)$payment['reversal_of'])
                ->where('fact_direction', 'forward')->where('status', 'effective')->lock(true)->select()->toArray();
            if ($originals === []) continue; // Historical repayments may not have a V3 sale-line allocation.
            $first = $originals[0];
            $allocatedCents = array_sum(array_map(static function (array $row): int {
                return (int)$row['amount_cents'];
            }, $originals));
            if ($allocatedCents !== abs((int)$payment['amount_cents'])) {
                throw self::failure('补交销售分摊与收款金额不一致，无法安全作废。', 'supplement_allocation_amount_mismatch');
            }
            foreach ($originals as $original) {
                if ((string)$original['order_id'] !== (string)$first['order_id'] || (int)$original['store_id'] !== $operator->storeId()) {
                    throw self::failure('补交销售明细归属异常，无法安全作废。', 'supplement_allocation_scope_mismatch');
                }
            }
            $allocations->persistLifecycleReversalsInTx([
                'tenant_id' => $tenantId, 'organization_id' => (string)$first['organization_id'],
                'store_id' => $operator->storeId(), 'member_id' => (int)$first['member_id'],
                'order_id' => (string)$first['order_id'], 'order_no_snapshot' => (string)$first['order_no_snapshot'],
                'business_date' => date('Y-m-d', $now), 'occurred_at' => $now, 'settled_at' => $now,
                'recorded_at' => $now, 'business_event_no' => (string)$event['event_no'],
                'checkout_request_id' => $repaymentId,
            ], [$payment]);
        }
    }

    /** Publish the exact wallet reversal: recharge is debited, sales tender is credited back. */
    private function appendRepaymentBalanceReversal(string $tenantId, string $repaymentId, string $operationId, string $commandKey, array $event, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, int $now, array $balanceResult, bool $sales = false): void
    {
        $rows = Db::name('cashier_v3_balance_fact')->where('tenant_id', $tenantId)->where('order_id', $repaymentId)
            ->where('source_document_type', $sales ? 'debt_repayment' : 'recharge_debt_repayment')->where('fact_direction', 'forward')
            ->where('status', 'effective')->lock(true)->select()->toArray();
        if (count($rows) !== 1) throw self::failure('补交余额事实异常，无法安全作废。', 'supplement_balance_fact_ambiguous');
        $originalBalance = $rows[0];
        $principalChange = $sales ? $this->moneyCents($balanceResult['change_ben']) : -$this->moneyCents($balanceResult['paid_ben'] ?? '0');
        $bonusChange = $sales ? $this->moneyCents($balanceResult['change_give']) : -$this->moneyCents($balanceResult['paid_give'] ?? '0');
        if (($sales && ((int)$originalBalance['principal_delta_cents'] !== -$principalChange
                || (int)$originalBalance['bonus_delta_cents'] !== -$bonusChange))
            || (!$sales && (int)$originalBalance['principal_delta_cents'] + (int)$originalBalance['bonus_delta_cents'] !== -$principalChange - $bonusChange)
            || Db::name('cashier_v3_balance_fact')->where('tenant_id', $tenantId)
                ->where('reversal_of', (string)$originalBalance['fact_id'])
                ->where('fact_direction', 'reversal')->where('status', 'effective')->count() > 0) {
            throw self::failure('补交余额事实与本次扣回不一致，无法安全作废。', 'supplement_balance_fact_conflict');
        }
        $after = (new CashierV3MemberBalanceProvider())->lockSnapshotInTx((int)$rows[0]['member_id'], $operator, $scope);
        $copy = $rows[0]; unset($copy['id']);
        $copy['fact_id'] = 'OCV-' . strtoupper(substr(hash_hmac('sha256', 'balance|' . $rows[0]['fact_id'] . '|' . $operationId, (string)config('cashier_v3.checkout_namespace_secret')), 0, 40));
        $copy['natural_key'] = 'order_center_void:' . hash('sha256', 'balance|' . $rows[0]['fact_id'] . '|' . $operationId);
        $copy['fact_direction'] = 'reversal'; $copy['reversal_of'] = (string)$rows[0]['fact_id'];
        $copy['business_event_no'] = (string)$event['event_no']; $copy['command_idempotency_key'] = $commandKey;
        $copy['operator_id'] = $operator->operatorId(); $copy['fact_version'] = 1;
        $copy['business_date'] = date('Y-m-d', $now); $copy['occurred_at'] = $now;
        $copy['settled_at'] = $now; $copy['recorded_at'] = $now;
        $copy['principal_delta_cents'] = $principalChange;
        $copy['bonus_delta_cents'] = $bonusChange;
        $copy['balance_account_id'] = (string)$after['accountId'];
        $copy['account_version'] = (int)$after['accountVersion'];
        $copy['principal_after_cents'] = (int)$after['principalCents'];
        $copy['bonus_after_cents'] = (int)$after['giftCents'];
        $copy['immutable_fingerprint'] = hash('sha256', json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ((int)Db::name('cashier_v3_balance_fact')->insert($copy) !== 1) throw self::failure('补交余额冲销失败，已取消作废。', 'supplement_balance_reversal_insert_failed');
    }
    /** Return only a ledger-confirmed credit matching this repayment's immutable debit fact. */
    private function restoreSalesDebtBalance(array $row, CashierV3DataScopeContext $scope, CashierV3OperatorScope $operator, string $key): ?array
    {
        $balance = \app\services\cashier\v3\settlement\CashierV3SalesDebtBalanceServices::balanceCents((string)$row['repayment_id']);
        $facts = Db::name('cashier_v3_balance_fact')->where('tenant_id', $scope->tenantId())
            ->where('order_id', (string)$row['repayment_id'])->where('source_document_type', 'debt_repayment')
            ->where('fact_direction', 'forward')->where('status', 'effective')->lock(true)->select()->toArray();
        if ($balance === 0 && $facts === []) return null;
        $fact = $facts[0] ?? [];
        $principal = -(int)($fact['principal_delta_cents'] ?? 0); $bonus = -(int)($fact['bonus_delta_cents'] ?? 0);
        if (count($facts) !== 1 || $principal < 0 || $bonus < 0 || $principal + $bonus !== $balance
            || $principal % 100 !== 0 || $bonus % 100 !== 0) throw self::failure('补交余额事实不一致。', 'supplement_balance_tender_mismatch');
        (new CashierV3MemberBalanceProvider())->lockSnapshotInTx((int)$row['member_id'], $operator, $scope);
        $result = app()->make(UserBalanceAtomicServices::class)->creditBenGive((int)$row['member_id'],
            $this->money($principal), $this->money($bonus), 'order_void', (int)$row['id'], '销售欠款补交作废返还余额',
            'cv3-sales-debt-void-' . hash('sha256', $scope->tenantId() . '|' . $key));
        if ((int)($result['money_id'] ?? 0) <= 0 || !empty($result['idempotent'])
            || $this->moneyCents($result['change_ben']) !== $principal || $this->moneyCents($result['change_give']) !== $bonus) {
            throw self::failure('补交余额返还流水不完整。', 'supplement_balance_credit_invalid');
        }
        return $result;
    }

    private static function failure(string $message, string $reason): CashierV3CommandException { return new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, $message, CashierV3ResultCode::STATUS_FAILED, ['reason' => $reason]); }
}
