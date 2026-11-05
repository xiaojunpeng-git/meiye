<?php
declare(strict_types=1);

namespace app\services\cashier\v3\order;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\card\CashierV3CardSaleActualAmountServices;
use app\services\cashier\v3\card\CashierV3CardPurchaseIssuanceServices;
use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices;
use app\services\cashier\v3\card\CashierV3CardComponentPurchaseAmountAuthority;
use app\services\cashier\v3\cashier\CashierV3CardOriginDebtResolver;
use app\services\cashier\v3\cashier\CashierV3EntitlementActualAmountAllocator;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
use app\services\cashier\v3\cashier\CashierV3SharedCardPurchaseAmount;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use app\services\order\store\WriteOffOrderServices;
use app\services\user\UserBalanceAtomicServices;
use think\facade\Db;

/**
 * One manual refund targets card rights, a sales line, a catalog item, or the
 * member wallet. Recorded cash refund and wallet credit/debit are independent
 * facts; none invokes an external payment-channel refund.
 */
final class CashierV3UnifiedRefundServices
{
    public const TABLE = 'cashier_v3_refund_adjustment';
    private array $otherCollectionOrders = [];

    /** Candidate projection is advisory; executeInTx locks and recomputes it. */
    public function candidates(array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $this->otherCollectionOrders = [];
        $this->assertAccess($operator, $scope);
        $memberId = (int)($payload['memberId'] ?? 0);
        if ($memberId <= 0 || !Db::name('user')->where('uid', $memberId)->where('is_del', 0)->find()) {
            throw self::fail('请选择有效会员。', 'refund_member_invalid');
        }
        $mode = (string)($payload['mode'] ?? 'card');
        if (!in_array($mode, ['card', 'document', 'catalog', 'balance'], true)) throw self::fail('请选择退款类型。', 'refund_mode_invalid');
        if ($mode === 'balance') {
            // Candidate balances are advisory. The command compares the account version under lock.
            $balance = (new CashierV3MemberBalanceProvider())->readSnapshot($memberId, $operator, $scope);
            return ['mode' => 'balance', 'balance' => [
                'principalCents' => (int)$balance['principalCents'],
                'giftCents' => (int)$balance['giftCents'],
                'totalCents' => (int)$balance['totalCents'],
                'accountVersion' => (int)$balance['accountVersion'],
            ]];
        }
        if ($mode === 'catalog') {
            // The catalog is a label authority only: this mode never creates a sale or restores stock/rights.
            return ['mode' => 'catalog', 'items' => $this->catalogItems($operator, $scope)];
        }
        $keyword = trim((string)($payload['keyword'] ?? ''));
        if (mb_strlen($keyword) > 64) throw self::fail('查询内容过长。', 'refund_keyword_invalid');
        if ($mode === 'card') {
            $page = max(1, (int)($payload['page'] ?? 1));
            $query = Db::name('user_card_holder')->alias('h')->join('store_order o', 'o.id=h.oid')
                ->where('h.uid', $memberId)->where('h.is_del', 0)->where('o.paid', 1)
                ->where('o.is_del', 0)->where('o.is_system_del', 0)->where('o.is_user_del', 0)
                ->where('o.refund_status', 0)->where('o.terminal_action', 0);
            // 先按当前会员的卡号、卡名或来源单号筛选，再分页；页面不能只过滤已加载的一页。
            if ($keyword !== '') {
                $like = '%' . addcslashes($keyword, '%_\\') . '%';
                $query->where(function ($search) use ($like): void {
                    $search->where('h.card_no', 'like', $like)
                        ->whereOr('h.card_name', 'like', $like)
                        ->whereOr('o.order_id', 'like', $like);
                });
            }
            $total = (int)(clone $query)->count('h.id');
            $rows = $query
                ->field('h.id,h.uid,h.oid,h.card_no,h.card_name,h.write_times,h.write_surplus_times,h.write_end,o.order_id,o.pay_price,o.coupon_price,o.uid AS origin_uid,o.store_id AS origin_store_id,o.debt_amount,o.repaid_debt_amount,o.refund_status,o.terminal_action')
                ->order('h.id desc')->page($page, 50)->select()->toArray();
            // One receipt lookup per page avoids another query for every card;
            // holder and legacy-order IDs must both match before showing an XS number.
            $salesNos = [];
            $salesOrderIds = [];
            if ($rows !== []) {
                $receipts = Db::name(CashierV3CardPurchaseIssuanceServices::RECEIPT_TABLE)->alias('cpr')
                    ->join('cashier_v3_sales_order so', 'so.order_id=cpr.sales_order_id AND so.tenant_id=cpr.tenant_id')
                    ->where('cpr.tenant_id', $scope->tenantId())
                    ->whereIn('cpr.card_holder_id', array_column($rows, 'id'))
                    ->where('cpr.status', 'completed')
                    ->field('cpr.card_holder_id,cpr.legacy_order_id,cpr.sales_order_id,so.order_no')->select()->toArray();
                foreach ($receipts as $receipt) {
                    $salesNos[(int)$receipt['card_holder_id'] . ':' . (int)$receipt['legacy_order_id']] = (string)$receipt['order_no'];
                    $salesOrderIds[(int)$receipt['card_holder_id'] . ':' . (int)$receipt['legacy_order_id']] = (string)$receipt['sales_order_id'];
                }
                $this->primeOtherCollectionOrders($scope->tenantId(), array_values($salesOrderIds));
            }
            $cards = [];
            foreach ($rows as $row) {
                $receiptKey = (int)$row['id'] . ':' . (int)$row['oid'];
                $row['sales_order_no'] = $salesNos[$receiptKey] ?? '';
                $row['sales_order_id'] = $salesOrderIds[$receiptKey] ?? '';
                $cards[] = $this->cardSnapshot((int)$row['id'], $memberId, $scope->tenantId(), false, $row);
            }
            return ['mode' => 'card', 'cards' => $cards, 'total' => $total, 'page' => $page];
        }
        $query = Db::name('cashier_v3_sales_order_line')->alias('l')
            ->join('cashier_v3_sales_order so', 'so.order_id=l.order_id AND so.tenant_id=l.tenant_id')
            ->where('l.tenant_id', $scope->tenantId())->where('l.member_id', $memberId)
            ->where('l.line_status', 'settled')->where('l.line_direction', 'forward')
            ->where('so.order_status', 'settled')->where('so.order_direction', 'forward')
            ->whereIn('l.item_type', ['project', 'product']);
        if ($keyword !== '') $query->where('so.order_no', 'like', '%' . addcslashes($keyword, '%_\\') . '%');
        $lines = $query->field('l.order_line_id,l.order_id,l.item_name_snapshot,l.item_type,l.sale_amount_cents,l.coupon_payment_cents,l.store_id,l.member_id,l.tenant_id,l.line_status,l.line_direction,so.order_no,so.order_status,so.order_direction')
            ->order('l.id desc')->limit(100)->select()->toArray();
        $this->primeOtherCollectionOrders($scope->tenantId(), array_column($lines, 'order_id'));
        $documents = [];
        foreach ($lines as $line) {
            $state = $this->lineSnapshot((string)$line['order_line_id'], $memberId, $scope->tenantId(), false, $line);
            if ($state['remainingAmountCents'] > 0 && !$state['voided']) $documents[] = $state;
        }
        return ['mode' => 'document', 'lines' => $documents];
    }

    /** The gateway owns the transaction, receipt and event contract. */
    public function executeInTx(array $command): array
    {
        // Handler instances may outlive a request; never reuse a prior tender decision for a refund command.
        $this->otherCollectionOrders = [];
        CashierV3TransactionGuard::assertInTransaction('unifiedRefund.execute');
        $operator = $command['operator_scope'] ?? null;
        $scope = $command['data_scope'] ?? null;
        if (!$operator instanceof CashierV3OperatorScope || !$scope instanceof CashierV3DataScopeContext) {
            throw self::fail('退款上下文无效，请刷新重试。', 'refund_context_invalid');
        }
        $this->assertAccess($operator, $scope);
        $payload = (array)($command['payload'] ?? []);
        $mode = (string)($payload['mode'] ?? '');
        if (!in_array($mode, ['card', 'document', 'catalog', 'balance'], true)) throw self::fail('请选择退款类型。', 'refund_mode_invalid');
        $memberId = (int)($payload['memberId'] ?? 0);
        $void = $payload['voidTarget'] ?? false;
        if (!is_bool($void) || $memberId <= 0) throw self::fail('退款对象无效，请重新选择。', 'refund_target_invalid');
        // A zero-yuan card refund is an auditable manual record, not a cash reversal;
        // document and unlinked refunds still require a positive payment amount.
        $refundCents = self::enteredWholeYuanCents($payload['refundAmount'] ?? null, $mode === 'card');
        $balanceCents = self::enteredWholeYuanCents($payload['balanceAmount'] ?? null, true);
        $balanceKind = (string)($payload['balanceKind'] ?? 'bonus');
        // 钱包模式扣本金再扣赠金；其余模式只能增加赠金。两条方向不能由客户端混用。
        if ($balanceKind !== ($mode === 'balance' ? 'principal_first' : 'bonus')) {
            throw self::fail($mode === 'balance' ? '扣减余额必须本金优先。' : '退款转入余额只能记入赠金。',
                'refund_balance_kind_invalid');
        }
        $reason = trim((string)($payload['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 255) throw self::fail('请填写退款原因（不超过255字）。', 'refund_reason_invalid');
        $holderId = (int)($payload['holderId'] ?? 0);
        $componentId = (int)($payload['componentId'] ?? 0);
        // 新页面明确提交整卡／部分项目；旧请求保留原项目语义，不能默认为作废整卡。
        $cardRefundScope = (string)($payload['cardRefundScope'] ?? 'partial');
        if (!in_array($cardRefundScope, ['whole', 'partial'], true)
            || ($mode !== 'card' && $cardRefundScope !== 'partial')) {
            throw self::fail('请选择有效的卡项退款方式。', 'refund_card_scope_invalid');
        }
        $entireCard = $mode === 'card' && $cardRefundScope === 'whole';
        if ($entireCard && (!$void || $componentId !== 0)) {
            throw self::fail('整张卡退款需要作废本卡，无需选择项目。', 'refund_whole_card_target_invalid');
        }
        $lineId = trim((string)($payload['lineId'] ?? ''));
        $catalogItemId = (int)($payload['catalogItemId'] ?? 0);
        if (($mode === 'card' && $holderId <= 0)
            || ($mode === 'document' && !preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $lineId))
            || ($mode === 'catalog' && ($catalogItemId <= 0 || $void || $balanceCents !== 0))
            || ($mode === 'balance' && ($void || $holderId !== 0 || $componentId !== 0
                || $lineId !== '' || $catalogItemId !== 0 || $balanceCents <= 0
                || (int)($payload['balanceVersion'] ?? 0) <= 0))) {
            throw self::fail('请选择一张卡的项目或一条购买明细。', 'refund_line_invalid');
        }
        $key = (string)($command['idempotency_key'] ?? '');
        if ($key === '') throw self::fail('请求编号无效，请重新打开退款。', 'refund_key_missing');
        $fingerprint = hash('sha256', json_encode([$payload, $operator->storeId(), $operator->operatorId()], JSON_UNESCAPED_UNICODE));
        $old = Db::name(CashierV3OrderLifecycleServices::OPERATION_TABLE)
            ->where('tenant_id', $scope->tenantId())->where('command_idempotency_key', $key)->lock(true)->find();
        if ($old) {
            if ((string)$old['immutable_fingerprint'] !== $fingerprint) throw self::fail('该请求编号已用于其他退款。', 'refund_key_conflict');
            return $this->result((array)$old, true);
        }
        $snapshot = $mode === 'card'
            ? $this->cardSnapshot($holderId, $memberId, $scope->tenantId(), true)
            : ($mode === 'document' ? $this->lineSnapshot($lineId, $memberId, $scope->tenantId(), true)
                : ($mode === 'balance' ? $this->balanceSnapshot($memberId, $balanceCents, $payload, $operator, $scope)
                    : $this->catalogSnapshot($catalogItemId, $operator, $scope)));
        if ($snapshot['voided'] || !$snapshot['canRefund']) {
            throw self::fail((string)$snapshot['unavailableReason'], 'refund_target_unavailable');
        }
        $wholeCardRule = $mode === 'card' && in_array($snapshot['ruleType'], ['choice_count', 'combination'], true);
        if ($mode === 'card' && !$entireCard && (($wholeCardRule && $componentId !== 0)
            || (!$wholeCardRule && $componentId <= 0))) {
            throw self::fail('请重新选择可退款的卡项项目。', 'refund_component_invalid');
        }
        // 组合卡只能整卡终止，服务已用金额仍按原权益事实结算。
        if ($mode === 'card' && $snapshot['ruleType'] === 'combination' && !$void) {
            throw self::fail('组合卡仅支持整卡退款，请选择整卡作废。', 'refund_combination_whole_card_required');
        }
        $target = $mode === 'card'
            ? ($entireCard ? array_replace($snapshot, ['id' => 0]) : ($wholeCardRule ? $snapshot['components'][0]
                : $this->selectedCardComponent($snapshot, $componentId)))
            : $snapshot;
        $remaining = (int)$target['usableRemainingAmountCents'];
        // 卡项退款现金、转入赠金均按手填值登记，不以原成交或剩余权益作上限。
        // 权益扣减只消耗当前目标的正向余值，现金、赠金和权益分别留事实，不能产生负权益。
        $rightsRefundCents = $mode === 'card'
            ? CashierV3CardRefundQuotaPolicy::rightsReductionCents($refundCents, (int)$target['remainingAmountCents']) : $refundCents;
        // 保留零价卡转赠金的既有审计标记，不再把它作为允许超额登记的特殊条件。
        $zeroPriceBonusGrant = $mode === 'card' && (int)$snapshot['purchaseAmountCents'] === 0
            && $refundCents === 0;
        if ($mode === 'document' && ($refundCents > $remaining
            || $balanceCents > (int)$snapshot['usableRemainingAmountCents'])) {
            throw self::fail('本次退款金额或转入余额超过实际剩余金额，请调整后重试。', 'refund_amount_exceeds_remaining');
        }
        // 单据退款继续排除原单券抵付和余额支付；卡项手填登记不复用这项原单金额上限。
        if ($mode === 'document' && $refundCents
            > (int)($snapshot['cashRefundRemainingCents'] ?? $remaining)) {
            throw self::fail('本次退款超过原单实际支付的可退金额，抵用券不参与退款。', 'refund_coupon_tender_exceeds_cash');
        }
        $newEnd = (int)($payload['validThrough'] ?? 0);
        if ($mode === 'card' && $snapshot['isTimeCard']) {
            if ($entireCard) {
                // 作废整卡不修改历史有效期，也无需用户再填写一个不会生效的新期限。
                $newEnd = (int)$snapshot['validThrough'];
            } elseif ($newEnd <= 0 || $newEnd > (int)$snapshot['validThrough']) {
                throw self::fail('时间卡新有效期只能保持或缩短。', 'refund_time_expiry_invalid');
            }
        } elseif ($newEnd !== 0) {
            throw self::fail('该退款对象不需要修改有效期。', 'refund_expiry_not_applicable');
        }
        $now = time();
        $actualDate = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
        $date = self::refundBusinessDate($payload['businessDate'] ?? $actualDate, $actualDate);
        $operationId = 'OLO-' . strtoupper(substr(hash('sha256', $scope->tenantId() . '|' . $key), 0, 40));
        // Repeated refunds of one SKU are distinct refund facts; the source line must be the
        // refund operation, while the catalog SKU remains a separate searchable dimension.
        if ($mode === 'catalog') $target['lineId'] = $operationId;
        // Only a successful, non-replayed command allocates a visible refund number.
        // The allocator's locked daily sequence rolls back with this refund transaction.
        $operationNo = (new CashierV3BusinessDocumentNumberServices())->allocateForSourceInTx(
            $scope->tenantId(), CashierV3BusinessDocumentNumberServices::REFUND,
            // 单号按实际登记日连续编号；可补录的经营归属日不倒排单号。
            'unified_refund', $operationId, $actualDate, $now
        );
        $event = $command['event_recorder']->recordInTx($command['event_execution'],
            (array)($command['event_contract'] ?? []), [
                'event_type' => 'manual_refund.completed', 'aggregate_type' => 'manual_refund',
                'aggregate_id' => $operationId, 'aggregate_version' => 1, 'event_version' => 1,
                'source_type' => 'refund-card-or-line', 'source_id' => $operationId,
                'member_id' => $memberId, 'business_date' => $date,
                'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
                'aggregate_name_snapshot' => (string)$target['name'],
                'store_name_snapshot' => (string)Db::name('system_store')->where('id', $operator->storeId())->value('name'),
                'payload' => ['mode' => $mode, 'refundAmountCents' => $refundCents,
                    'balanceCreditCents' => $mode === 'balance' ? 0 : $balanceCents,
                    'balanceDebitCents' => $mode === 'balance' ? $balanceCents : 0,
                    'voidTarget' => $void,
                    'cardRefundScope' => $mode === 'card' ? $cardRefundScope : null,
                    'cardRightsReductionCents' => $mode === 'card' ? $rightsRefundCents : 0,
                    'zeroPriceBonusGrantCents' => $zeroPriceBonusGrant ? $balanceCents : 0],
            ]);
        // 卡项作废必须能精确恢复整张卡的既有状态；请求事实同时保存前后快照，
        // 不用当前卡规则猜测退款前哪些项目原本已停用。
        $rightsBefore = $mode === 'card' ? $this->refundReversalRightsSnapshotInTx($scope->tenantId(), $holderId) : null;
        $after = $mode === 'card'
            ? $this->adjustCard($snapshot, $target, $rightsRefundCents, $void, $newEnd, $scope->tenantId(), $now, $entireCard)
            : ($mode === 'document' ? $this->adjustLine($snapshot, $refundCents, $void)
                : ['remainingAmountCents' => $mode === 'balance' ? $remaining - $balanceCents : 0,
                    'closedRemainingCents' => 0, 'remainingTimes' => 0,
                    'timesRemoved' => 0, 'timesClosed' => 0]);
        $sourceOrderId = (string)$snapshot['sourceOrderId'];
        $sourceOrderNo = (string)$snapshot['sourceOrderNo'];
        $requestSnapshot = $payload;
        if ($mode === 'card') $requestSnapshot['_cardRightsReductionCents'] = $rightsRefundCents;
        if ($zeroPriceBonusGrant && $balanceCents > 0) {
            $requestSnapshot['_zeroPriceBonusGrantCents'] = $balanceCents;
        }
        // 无原单没有原销售明细；退款落账时冻结目录分类，避免历史列表回读可变档案。
        if ($mode === 'catalog') $requestSnapshot['_productCategoryNameSnapshot'] = (string)($target['categoryName'] ?? '');
        if ($rightsBefore !== null) $requestSnapshot['_reversal'] = [
            'rightsBefore' => $rightsBefore,
            'rightsAfter' => $this->refundReversalRightsSnapshotInTx($scope->tenantId(), $holderId),
        ];
        $row = [
            'operation_id' => $operationId, 'operation_no' => $operationNo, 'tenant_id' => $scope->tenantId(),
            'store_id' => $operator->storeId(), 'member_id' => $memberId, 'operator_id' => $operator->operatorId(),
            'source_type' => $mode, 'source_order_id' => $sourceOrderId,
            'source_order_no_snapshot' => $sourceOrderNo, 'operation_type' => 'refund',
            'command_idempotency_key' => $key, 'immutable_fingerprint' => $fingerprint,
            'reason_snapshot' => $reason, 'request_json' => json_encode($requestSnapshot, JSON_UNESCAPED_UNICODE),
            'business_event_no' => (string)$event['event_no'], 'amount_cents' => -$refundCents,
            'cash_refund_cents' => $refundCents, 'reversed_cash_cents' => 0,
            'restored_principal_cents' => 0,
            'restored_bonus_cents' => $mode === 'balance' ? 0 : $balanceCents,
            'status' => 'succeeded', 'version' => 1, 'business_date' => $date,
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ];
        Db::name(CashierV3OrderLifecycleServices::OPERATION_TABLE)->insert($row);
        $this->writePerformanceAdjustment($row, $refundCents, $scope, $operator, $event, $target);
        $adjustmentRowId = Db::name(self::TABLE)->insertGetId([
            'adjustment_id' => 'R26-' . substr($operationId, 4), 'operation_id' => $operationId,
            'command_idempotency_key' => $key, 'tenant_id' => $scope->tenantId(),
            'store_id' => $operator->storeId(), 'member_id' => $memberId, 'operator_id' => $operator->operatorId(),
            'target_type' => $mode, 'card_holder_id' => $mode === 'card' ? $holderId : 0,
            'card_component_id' => $mode === 'card' ? $componentId : 0,
            'source_order_id' => $sourceOrderId, 'source_line_id' => $mode === 'document' ? $lineId : '',
            'catalog_item_id' => $mode === 'catalog' ? $catalogItemId : 0,
            'catalog_kind' => $mode === 'catalog' ? (string)$target['kind'] : '',
            'source_order_no_snapshot' => $sourceOrderNo, 'target_name_snapshot' => (string)$target['name'],
            'refund_amount_cents' => $refundCents,
            'balance_credit_cents' => $mode === 'balance' ? 0 : $balanceCents,
            'balance_debit_cents' => $mode === 'balance' ? $balanceCents : 0,
            'balance_kind' => $balanceKind, 'remaining_before_cents' => (int)$snapshot['remainingAmountCents'],
            'remaining_after_cents' => $after['remainingAmountCents'],
            'closed_remaining_cents' => $after['closedRemainingCents'],
            'times_before' => (int)($snapshot['remainingTimes'] ?? 0), 'times_after' => $after['remainingTimes'],
            'times_removed' => $after['timesRemoved'], 'times_closed' => $after['timesClosed'],
            'valid_through_before' => (int)($snapshot['validThrough'] ?? 0),
            'valid_through_after' => $mode === 'card' && $snapshot['isTimeCard'] ? $newEnd : (int)($snapshot['validThrough'] ?? 0),
            'void_target' => $void ? 1 : 0, 'reason_snapshot' => $reason,
            'business_date' => $date, 'occurred_at' => $now, 'settled_at' => $now,
            'recorded_at' => $now, 'status' => 'effective',
        ]);
        // 退款人员归属独立于原单，负向工资事实与退款在同一事务落账。
        (new CashierV3RefundSalespersonServices())->createInTx($row, $payload,
            (int)$adjustmentRowId, $event, $operator, $scope);
        if ($mode === 'balance') {
            $this->debitBalance($memberId, $balanceCents, $key, (int)$adjustmentRowId,
                $operationId, $operationNo, $operator, $scope, $event, $date, $now, $snapshot);
        } else {
            $this->creditBalance($memberId, $balanceCents, $key, (int)$adjustmentRowId,
                $operationId, $sourceOrderNo, $operator, $scope, $event, $date, $now);
        }
        return $this->result($row, false, $after + ['voidTarget' => $void]);
    }

    /** 补录日期只改变经营归属；真实发生、结算和记录时间仍取服务端时钟。 */
    private static function refundBusinessDate($value, string $today): string
    {
        $value = trim((string)$value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Asia/Shanghai'));
        if (!$date || $date->format('Y-m-d') !== $value || $value > $today) {
            throw self::fail('请选择不晚于今天的有效退款日期。', 'refund_business_date_invalid');
        }
        return $value;
    }

    /** Manual refund performance is a signed adjustment, never a channel payment reversal. */
    private function writePerformanceAdjustment(array $operation, int $refundCents,
        CashierV3DataScopeContext $scope, CashierV3OperatorScope $operator, array $event, array $target): void
    {
        $operationId = (string)$operation['operation_id'];
        $memberId = (int)$operation['member_id'];
        $storeId = $operator->storeId();
        $storeName = (string)Db::name('system_store')->where('id', $storeId)->value('name');
        $organizationId = (string)$operator->organizationId();
        $member = (array)Db::name('user')->where('uid', $memberId)->field('real_name,nickname')->find();
        $staff = (array)Db::name('system_store_staff')->where('id', $operator->operatorId())
            ->field('staff_name')->find();
        $fact = [
            'fact_id' => 'R26P-' . strtoupper(substr(hash('sha256', $operationId), 0, 40)),
            'business_event_no' => (string)$event['event_no'],
            'fact_type' => 'manual_refund_adjustment', 'fact_direction' => 'adjustment',
            'natural_key' => 'manual_refund_adjustment:' . $operationId,
            'command_idempotency_key' => (string)$operation['command_idempotency_key'],
            'fact_version' => 2, 'reversal_of' => '', 'status' => 'effective',
            'tenant_id' => $scope->tenantId(), 'tenant_name_snapshot' => '',
            'organization_id' => $organizationId, 'organization_name_snapshot' => '',
            'organization_path_snapshot' => $organizationId,
            'store_id' => $storeId, 'store_name_snapshot' => $storeName,
            'member_id' => $memberId,
            'member_name_snapshot' => (string)(($member['real_name'] ?? '') ?: ($member['nickname'] ?? '')),
            'operator_id' => $operator->operatorId(),
            'operator_name_snapshot' => (string)($staff['staff_name'] ?? ''),
            'business_date' => (string)$operation['business_date'], 'business_timezone' => 'Asia/Shanghai',
            'occurred_at' => (int)$operation['occurred_at'], 'settled_at' => (int)$operation['settled_at'],
            'recorded_at' => (int)$operation['recorded_at'], 'checkout_request_id' => $operationId,
            'order_id' => $operationId, 'order_no_snapshot' => (string)$operation['operation_no'],
            'source_document_type' => 'manual_refund',
            'source_line_id' => (string)($target['lineId'] ?? $target['id']),
            'payment_method' => 'manual_adjustment',
            'payment_authority_key' => 'manual_refund_adjustment:' . $operationId,
            'collection_reference' => (string)$operation['source_order_no_snapshot'],
            'amount_cents' => -$refundCents,
        ];
        $fact['immutable_fingerprint'] = hash('sha256', json_encode($fact, JSON_UNESCAPED_UNICODE));
        Db::name('cashier_v3_payment_fact')->insert($fact);
    }

    private function selectedCardComponent(array $card, int $componentId): array
    {
        foreach ($card['components'] as $component) {
            if ((int)$component['id'] === $componentId &&
                ((int)$component['usableRemainingAmountCents'] > 0
                    || ((int)$card['purchaseAmountCents'] === 0 && (int)$component['remainingTimes'] > 0))) return $component;
        }
        throw self::fail('所选卡项目没有可退金额，请重新选择。', 'refund_component_unavailable');
    }

    /** Both picker and final write resolve the same store-scoped SKU authority; price is never a refund cap. */
    private function catalogItems(CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $items = (new CashierV3SaleCatalogServices())->catalog($operator, $scope)['items'] ?? [];
        return array_values(array_filter($items, static fn(array $item): bool =>
            empty($item['disabled']) && in_array((string)($item['kind'] ?? ''), ['项目', '产品', '卡项'], true)));
    }

    /** An unlinked refund has no remaining sale value, card state, or source order to mutate. */
    private function catalogSnapshot(int $skuId, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        foreach ($this->catalogItems($operator, $scope) as $item) {
            if ((int)($item['skuId'] ?? 0) !== $skuId) continue;
            return ['id' => 'catalog:' . $skuId, 'name' => (string)$item['name'],
                'categoryName' => (string)($item['category'] ?? ''),
                'kind' => (string)$item['kind'], 'sourceOrderId' => '', 'sourceOrderNo' => '',
                'remainingAmountCents' => 0, 'usableRemainingAmountCents' => 0,
                'remainingTimes' => 0, 'voided' => false,
                'canRefund' => true, 'unavailableReason' => ''];
        }
        throw self::fail('所选商品已不在当前门店目录中，请重新选择。', 'refund_catalog_item_unavailable');
    }

    /** Wallet refund has no sale or rights target; lock the account and reject a stale picker version. */
    private function balanceSnapshot(int $memberId, int $debitCents, array $payload,
        CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $wallet = (new CashierV3MemberBalanceProvider())->lockSnapshotInTx($memberId, $operator, $scope);
        if ((int)$wallet['accountVersion'] !== (int)($payload['balanceVersion'] ?? 0)) {
            throw self::fail('会员余额已变化，请重新查询后再退款。', 'refund_balance_version_changed');
        }
        if ($debitCents > (int)$wallet['totalCents']) {
            throw self::fail('扣减余额超过会员当前剩余余额。', 'refund_balance_insufficient');
        }
        return ['id' => 'balance:' . $memberId, 'lineId' => 'balance:' . $memberId,
            'name' => '会员余额', 'sourceOrderId' => '', 'sourceOrderNo' => '',
            'remainingAmountCents' => (int)$wallet['totalCents'],
            'usableRemainingAmountCents' => (int)$wallet['totalCents'],
            'remainingTimes' => 0, 'validThrough' => 0, 'voided' => false,
            'canRefund' => true, 'unavailableReason' => '', 'wallet' => $wallet];
    }

    /** Transfer reads the same monetary authority, without refund tender/owner eligibility. */
    public function transferSnapshot(int $holderId, int $memberId, string $tenantId, bool $lock = false): array
    {
        if ($lock) CashierV3TransactionGuard::assertInTransaction('cardToBalance.snapshot');
        return $this->cardSnapshot($holderId, $memberId, $tenantId, $lock, null, true);
    }

    private function cardSnapshot(int $holderId, int $memberId, string $tenantId, bool $lock, ?array $candidate = null, bool $transfer = false): array
    {
        if (!$candidate) {
            $query = Db::name('user_card_holder')->alias('h')->join('store_order o', 'o.id=h.oid')
                ->where('h.id', $holderId)->where('h.uid', $memberId)
                ->field('h.id,h.uid,h.oid,h.card_no,h.card_name,h.write_times,h.write_surplus_times,h.write_end,h.is_del,o.order_id,o.pay_price,o.coupon_price,o.uid AS origin_uid,o.store_id AS origin_store_id,o.debt_amount,o.repaid_debt_amount,o.refund_status,o.terminal_action,o.paid,o.is_del AS order_deleted,o.is_system_del,o.is_user_del,o.mark,o.card_upgrade_use_oid');
            $candidate = (array)$query->lock($lock)->find();
        }
        if (!$candidate || (int)($candidate['id'] ?? 0) !== $holderId || (int)($candidate['uid'] ?? 0) !== $memberId) {
            throw self::fail('本卡归属已变化，请重新选择。', 'refund_card_owner_changed');
        }
        $sourceTerminated = (int)($candidate['is_del'] ?? 0) !== 0 || (int)($candidate['refund_status'] ?? 0) !== 0
            || (int)($candidate['terminal_action'] ?? 0) !== 0
            || (int)($candidate['paid'] ?? 1) !== 1
            || (int)($candidate['order_deleted'] ?? 0) !== 0
            || ((int)($candidate['is_system_del'] ?? 0) !== 0
                && !($transfer && (int)$candidate['origin_store_id'] === 0
                    && (string)($candidate['mark'] ?? '') === 'migration_entitlement'))
            || (int)($candidate['is_user_del'] ?? 0) !== 0;
        $voided = $sourceTerminated;
        if ($transfer && (int)($candidate['card_upgrade_use_oid'] ?? 0) > 0) $voided = true;
        $originId = (int)$candidate['oid'];
        $cardState = (array)Db::name('cashier_v3_card_state')->where('tenant_id', $tenantId)
            ->where('card_holder_id', $holderId)->lock($lock)->find();
        if ($cardState && ((int)$cardState['current_member_id'] !== $memberId
            || !in_array((string)$cardState['card_status'], $transfer ? ['enabled', 'disabled'] : ['enabled'], true))) $voided = true;
        $rule = (array)Db::name('cashier_v3_card_rule_state')->where('tenant_id', $tenantId)
            ->where('card_holder_id', $holderId)->lock($lock)->find();
        if ($rule && !in_array((string)$rule['status'], $transfer ? ['active', 'exhausted', 'disabled'] : ['active', 'exhausted'], true)) $voided = true;
        // 仅当来源、归属和规则仍有效时提示“先启用”；真正终止的卡不能误导操作员尝试启用。
        $disabledReason = !$sourceTerminated && (int)($cardState['current_member_id'] ?? 0) === $memberId
            && (string)($cardState['card_status'] ?? '') === 'disabled'
            && (!$rule || in_array((string)$rule['status'], ['active', 'exhausted'], true))
            ? mb_substr(trim((string)($cardState['status_reason_snapshot'] ?? '')), 0, 100) : null;
        $isTime = (string)($rule['rule_type'] ?? '') === 'time' || (int)$candidate['write_times'] >= 1000000;
        $carts = Db::name('store_order_cart_info')->where('oid', $originId)->where('cart_type', 2)
            ->where('product_type', 6)->field('id,oid,product_id,cart_info,pay_price,debt_amount,repaid_debt_amount,is_gift,write_times,write_surplus_times,write_end,is_writeoff')
            ->order('id asc')->lock($lock)->select()->toArray();
        $allCarts = $carts;
        if ($transfer) {
            // Closed/replaced detail rows can retain physical surplus for audit.
            // They cannot create new money. Keep all rows for sale allocation so
            // filtering a dead component never reallocates its price to live ones.
            $componentStates = Db::name('cashier_v3_card_rule_component')->where('tenant_id', $tenantId)
                ->where('card_holder_id', $holderId)->lock($lock)->select()->toArray();
            $componentStates = array_column($componentStates, null, 'legacy_detail_id');
            $closedGroups = [];
            if (($rule['rule_type'] ?? '') === 'combination') {
                foreach (Db::name('cashier_v3_card_rule_group_state')->where('tenant_id', $tenantId)
                    ->where('card_holder_id', $holderId)->lock($lock)->select()->toArray() as $group) {
                    if (!in_array((string)$group['status'], ['active', 'exhausted'], true)) $closedGroups[(int)$group['group_no']] = true;
                }
            }
            $carts = array_values(array_filter($carts, static function (array $cart) use ($componentStates, $closedGroups): bool {
                $component = $componentStates[(int)$cart['id']] ?? null;
                return (int)$cart['is_writeoff'] === 0 && (!$component
                    || (in_array((string)$component['status'], ['active', 'exhausted'], true)
                        && !isset($closedGroups[(int)($component['combination_group_no'] ?? 0)])));
            }));
        }
        $components = [];
        $sum = 0;
        $transferIndependentSum = 0;
        $saleOrder = ['id' => $originId, 'pay_price' => (string)$candidate['pay_price']];
        if ($transfer && (int)$candidate['origin_store_id'] === 0
            && (string)($candidate['mark'] ?? '') === 'migration_entitlement') {
            // A storeless migration carrier is deliberately zero-priced. Its
            // immutable paid source, not that technical zero, values shared pools.
            $sourcePaid = \app\services\cashier\v3\card\CashierV3HistoricalEntitlementScopePolicy::sourcePaidPrice($holderId, $tenantId);
            if ($sourcePaid !== null) $saleOrder['pay_price'] = $sourcePaid;
        }
        $sharedCount = (string)($rule['rule_type'] ?? '') === 'choice_count';
        $combination = (string)($rule['rule_type'] ?? '') === 'combination';
        if ($sharedCount) {
            // A choice-count card has one monetary value and one usage pool. Legacy
            // project carts are selectable services, not independent refundable balances.
            $purchase = self::moneyCents((string)$saleOrder['pay_price'], true);
            $total = (int)($rule['shared_total_times'] ?? 0);
            $left = (int)($rule['shared_remaining_times'] ?? -1);
            $history = Db::name(self::TABLE)->where('tenant_id', $tenantId)
                ->where('card_holder_id', $holderId)->where('status', 'effective')
                ->lock($lock)->select()->toArray();
            $refunded = array_sum(array_map([CashierV3CardRefundQuotaPolicy::class, 'returnedRightsCents'], $history));
            $removed = array_sum(array_map(static fn(array $row): int => (int)$row['times_removed'], $history));
            $used = $total - $left - $removed;
            if ($total <= 0 || $left < 0 || $left > $total || $used < 0
                || (int)$candidate['write_times'] !== $total
                || (int)$candidate['write_surplus_times'] !== $left || $carts === []) {
                throw self::fail('本卡共享次数资料不一致，请联系管理员核对。', 'refund_shared_state_invalid');
            }
            if ($purchase === 0) {
                // Zero-price rights have no monetary quota; only a positive sale can infer removed uses from money.
                if ($refunded !== 0 || $removed !== 0) throw self::fail('本卡共享次数资料不一致，请联系管理员核对。', 'refund_shared_state_invalid');
            } else {
                try {
                    $quota = CashierV3CardRefundQuotaPolicy::afterReturn(
                        $purchase, $total, $used, $refunded, $purchase % 100 === 0
                    );
                } catch (\InvalidArgumentException $error) {
                    throw self::fail('本卡共享次数与成交金额无法核对，请联系管理员。', 'refund_shared_amount_invalid');
                }
                if ((int)$quota['usableTimes'] !== $left) {
                    throw self::fail('本卡共享次数与剩余金额不一致，请联系管理员核对。', 'refund_shared_quota_mismatch');
                }
                $sum = (int)$quota['remainingValueCents'];
            }
            $components[] = ['id' => 0, 'name' => (string)$candidate['card_name'] . '（共享次数）',
                'purchaseAmountCents' => $purchase, 'remainingAmountCents' => $sum,
                'totalTimes' => $total, 'remainingTimes' => $left, 'refundedCents' => $refunded,
                'removedTimes' => $removed];
            if ($transfer) {
                $hasSharedOption = false;
                foreach ($carts as $cart) {
                    $detailSnapshot = json_decode((string)$cart['cart_info'], true) ?: [];
                    if (!CashierV3EntitlementActualAmountAllocator::usesIndependentAmountSnapshot($detailSnapshot)) {
                        $hasSharedOption = true;
                        continue;
                    }
                    // Replacement-created rights retain their own price/quantity;
                    // they are not another selectable copy of the original pool.
                    $detailAmount = (new CashierV3CardComponentPurchaseAmountAuthority())->resolve(
                        $cart, $detailSnapshot, [], null)['purchaseAmountCents'];
                    $detailTotal = (int)$cart['write_times']; $detailLeft = (int)$cart['write_surplus_times'];
                    if ($detailTotal <= 0 || $detailLeft < 0 || $detailLeft > $detailTotal) {
                        throw self::fail('独立权益次数异常，请先核对。', 'card_transfer_independent_times_invalid');
                    }
                    $detailRemaining = self::moneyCents(CashierV3EntitlementActualAmountAllocator::remainingForSnapshot(
                        self::centsToMoney($detailAmount), $detailTotal, $detailTotal - $detailLeft, $detailSnapshot), true);
                    $transferIndependentSum += $detailRemaining;
                    $components[] = ['id' => (int)$cart['id'], 'name' => $this->projectName($detailSnapshot),
                        'purchaseAmountCents' => $detailAmount, 'remainingAmountCents' => $detailRemaining,
                        'totalTimes' => $detailTotal, 'remainingTimes' => $detailLeft, 'independent' => true];
                }
                if (!$hasSharedOption) {
                    $sum = 0;
                    $components[0]['remainingAmountCents'] = 0;
                }
                $sum += $transferIndependentSum;
            }
        } elseif ($combination) {
            // 每个共享组只计一次金额与余次；任选种数项目仍用既有项目实收分摊。
            $allocations = (new CashierV3CardSaleActualAmountServices())->wholeYuanForOrders([$saleOrder], $allCarts);
            $authorities = (new CashierV3CardRuleEntitlementAuthorityServices())
                ->authoritiesForHolders($tenantId, [$holderId])[$holderId] ?? [];
            $seenSharedGroups = [];
            $purchaseSum = 0;
            foreach ($carts as $cart) {
                $cartInfo = json_decode((string)($cart['cart_info'] ?? ''), true);
                $cartInfo = is_array($cartInfo) ? $cartInfo : [];
                $authority = $authorities[(int)$cart['id']] ?? null;
                $groupNo = (int)($cartInfo['combination_group_no'] ?? 0);
                if (!is_array($authority) || $groupNo <= 0
                    || (int)($authority['combinationGroupNo'] ?? 0) !== $groupNo) {
                    throw self::fail('组合卡分组权益资料不一致。', 'refund_combination_group_invalid');
                }
                if ((string)$authority['ruleType'] === 'choice_count') {
                    if (isset($seenSharedGroups[$groupNo])) continue;
                    $seenSharedGroups[$groupNo] = true;
                    $projection = CashierV3SharedCardPurchaseAmount::project($saleOrder, $authority, $cartInfo);
                    if ($projection === null) throw self::fail('组合卡共享组金额无法核对。', 'refund_combination_amount_invalid');
                    $purchaseSum += self::moneyCents($projection['purchaseAmount'], true);
                    $sum += self::moneyCents($projection['remainingAmount'], true);
                } else {
                    $amount = (new CashierV3CardComponentPurchaseAmountAuthority())->resolve(
                        $cart, $cartInfo, $allocations, $authority)['purchaseAmountCents'];
                    $purchaseSum += $amount;
                    $total = (int)$authority['totalTimes'];
                    $left = (int)$authority['remainingTimes'];
                    if ($total <= 0 || $left < 0 || $left > $total) {
                        throw self::fail('组合卡项目次数无法核对。', 'refund_combination_times_invalid');
                    }
                    $sum += self::moneyCents(CashierV3EntitlementActualAmountAllocator::remainingForSnapshot(
                        self::centsToMoney($amount), $total, $total - $left,
                        $cartInfo + ['amountCalculationVersion' =>
                            CashierV3EntitlementActualAmountAllocator::CALCULATION_VERSION]), true);
                }
            }
            if (!$transfer && $purchaseSum !== self::moneyCents((string)$saleOrder['pay_price'], true)) {
                throw self::fail('组合卡已售金额与项目分摊不一致。', 'refund_combination_sale_mismatch');
            }
            $components[] = ['id' => 0, 'name' => (string)$candidate['card_name'] . '（整卡）',
                'purchaseAmountCents' => $purchaseSum, 'remainingAmountCents' => $sum,
                'totalTimes' => (int)$candidate['write_times'],
                'remainingTimes' => (int)$candidate['write_surplus_times'],
                'refundedCents' => 0, 'removedTimes' => 0];
        } else {
            $allocations = (new CashierV3CardSaleActualAmountServices())->wholeYuanForOrders([$saleOrder], $allCarts);
            $ruleAuthorities = (new CashierV3CardRuleEntitlementAuthorityServices())
                ->authoritiesForHolders($tenantId, [$holderId]);
            foreach ($carts as $cart) {
                $snapshot = json_decode((string)($cart['cart_info'] ?? ''), true);
                $snapshot = is_array($snapshot) ? $snapshot : [];
                $authority = (new CashierV3CardComponentPurchaseAmountAuthority())->resolve(
                    $cart, $snapshot, $allocations, $ruleAuthorities[$holderId][(int)$cart['id']] ?? null);
                $purchase = $authority['purchaseAmountCents'];
                $ruleAuthority = $ruleAuthorities[$holderId][(int)$cart['id']] ?? null;
                $useRuleTimes = $transfer && is_array($ruleAuthority)
                    && !CashierV3EntitlementActualAmountAllocator::usesIndependentAmountSnapshot($snapshot);
                $total = $useRuleTimes ? (int)$ruleAuthority['totalTimes'] : (int)$cart['write_times'];
                $left = $useRuleTimes ? (int)$ruleAuthority['remainingTimes'] : (int)$cart['write_surplus_times'];
                $refunded = (int)($snapshot['r26RefundedCents'] ?? 0);
                $removed = (int)($snapshot['r26RefundRemovedTimes'] ?? 0);
                if ($total <= 0 || $left < 0 || $left > $total || $removed > $total - $left) {
                    throw self::fail('本卡次数资料不完整，请联系管理员核对。', 'refund_card_times_invalid');
                }
                if ($isTime) $remaining = max(0, $purchase - $refunded);
                else {
                    $calcSnapshot = $authority['allocationSnapshot'];
                    $calcSnapshot['r26RefundedCents'] = $refunded;
                    $calcSnapshot['r26RefundRemovedTimes'] = $removed;
                    $remaining = self::moneyCents(CashierV3EntitlementActualAmountAllocator::remainingForSnapshot(
                        self::centsToMoney($purchase), $total, $total - $left, $calcSnapshot), true);
                }
                $components[] = ['id' => (int)$cart['id'], 'name' => $this->projectName($snapshot),
                    'purchaseAmountCents' => $purchase, 'remainingAmountCents' => $remaining,
                    'totalTimes' => $total, 'remainingTimes' => $left, 'refundedCents' => $refunded,
                    'removedTimes' => $removed, 'cartInfo' => $snapshot, 'validThrough' => (int)$cart['write_end']];
                $sum += $remaining;
            }
        }
        $order = ['id' => $originId, 'uid' => (int)$candidate['origin_uid'],
            'store_id' => (int)$candidate['origin_store_id'], 'tenant_id' => $tenantId,
            'debt_amount' => $candidate['debt_amount'], 'repaid_debt_amount' => $candidate['repaid_debt_amount']];
        // Debt belongs to the immutable origin sale, even after a holder transfer.
        $debtMemberId = $transfer ? (int)$candidate['origin_uid'] : $memberId;
        $debt = (new CashierV3CardOriginDebtResolver())->pendingV3CardDebt($order, $debtMemberId, $lock);
        if ($debt === null) {
            $debts = Db::name('store_debt')->where('order_id', $originId)->lock($lock)->select()->toArray();
            if (count($debts) > 1) throw self::fail('本卡欠款资料不一致，请联系管理员。', 'refund_debt_duplicate');
            $one = (array)($debts[0] ?? []);
            $debt = $one && (int)$one['status'] === 0
                ? bcsub((string)$one['total_debt'], (string)$one['repaid_debt'], 2)
                : ($one ? '0.00' : bcsub((string)$candidate['debt_amount'], (string)$candidate['repaid_debt_amount'], 2));
        }
        $debtCents = self::moneyCents((string)$debt, true);
        // The refund record retains the gross unconsumed value, while the picker and
        // execution cap share the writeoff projection's debt/cancellation restriction.
        // Cancelled debt never becomes cash-refundable merely because pending debt is zero.
        $cancelledCents = self::moneyCents((new CashierV3CardOriginDebtResolver())
            ->cancelledCardDebt($order, $debtMemberId, $tenantId, $lock), true);
        $usableSum = 0;
        if ($sharedCount || $combination) {
            $usableSum = max(0, $sum - $transferIndependentSum - $debtCents - $cancelledCents) + $transferIndependentSum;
            foreach ($components as &$component) {
                $component['usableRemainingAmountCents'] = max(0,
                    (int)$component['remainingAmountCents'] - (!empty($component['independent']) ? 0 : $debtCents + $cancelledCents));
            }
            unset($component);
        } else {
            $writeoff = app()->make(WriteOffOrderServices::class);
            $cartsById = array_column($carts, null, 'id');
            foreach ($components as &$component) {
                $cart = $cartsById[(int)$component['id']];
                $usable = $writeoff->usableEntitlementAmount($cart, $order,
                    self::centsToMoney((int)$component['remainingAmountCents']),
                    (float)$debt, $transfer ? $allCarts : $carts, $lock);
                $component['usableRemainingAmountCents'] = self::moneyCents((string)$usable, true);
                $usableSum += $component['usableRemainingAmountCents'];
            }
            unset($component);
        }
        if ($transfer) {
            // A conversion closes all rights and credits only the actual remaining
            // value. No cash cap, original purchaser or refund qualification applies.
            $reason = $voided ? '本卡已作废、退款或终止，不能转余额。'
                : ($debtCents > 0 ? '本卡存在待还欠款，请先补交或取消。'
                    : ($usableSum <= 0 ? '本卡没有实际剩余金额。'
                        : ($usableSum % 100 !== 0 ? '本卡剩余金额不是整数元，请先核对。' : '')));
            return ['holderId' => $holderId, 'memberId' => $memberId,
                'originMemberId' => (int)$candidate['origin_uid'], 'originStoreId' => (int)$candidate['origin_store_id'],
                'sourceOrderId' => (string)$originId, 'sourceOrderNo' => (string)$candidate['order_id'],
                'cardName' => (string)$candidate['card_name'], 'cardNo' => (string)$candidate['card_no'],
                'amountCents' => $usableSum, 'remainingAmountCents' => $sum,
                'pendingDebtCents' => $debtCents, 'cancelledDebtCents' => $cancelledCents,
                'components' => $components, 'eligible' => $reason === '', 'unavailableReason' => $reason];
        }
        // Imported zero-price cards still have real remaining rights. They must not
        // abort the entire picker merely because their refundable cash value is zero.
        $cardPurchaseCents = self::moneyCents((string)$candidate['pay_price'], true);
        $cardCouponCents = 0;
        $salesOrderId = trim((string)($candidate['sales_order_id'] ?? ''));
        $receipt = [];
        if (!array_key_exists('sales_order_id', $candidate)
            || strncmp((string)$candidate['order_id'], 'v3c', 3) === 0) {
            // The holder's legacy order ID alone cannot identify its V3 sale;
            // the issuance receipt links even historical purchases to the original tender.
            $receipt = (array)Db::name(CashierV3CardPurchaseIssuanceServices::RECEIPT_TABLE)
                ->where('tenant_id', $tenantId)->where('legacy_order_id', $originId)
                ->where('card_holder_id', $holderId)->where('status', 'completed')
                ->lock($lock)->find();
            if ($receipt) $salesOrderId = trim((string)$receipt['sales_order_id']);
        }
        if (strncmp((string)$candidate['order_id'], 'v3c', 3) === 0) {
            // The V3 receipt is the immutable per-card tender snapshot.
            // Legacy coupon_price means a discount and cannot be used here.
            $receiptResult = json_decode((string)($receipt['result_snapshot_json'] ?? ''), true);
            if (!$receipt || !is_array($receiptResult)) {
                throw self::fail('购卡回执缺失，暂不能退款。', 'refund_card_receipt_missing');
            }
            $cardCouponCents = (int)($receiptResult['couponPaymentCents'] ?? 0);
            if ($cardCouponCents < 0 || $cardCouponCents > $cardPurchaseCents) {
                throw self::fail('购卡抵用券资料异常，暂不能退款。', 'refund_card_coupon_snapshot_invalid');
            }
        }
        $priorRefundCents = (int)Db::name(self::TABLE)->where('tenant_id', $tenantId)
            ->where('card_holder_id', $holderId)->where('status', 'effective')->sum('refund_amount_cents');
        // New orders freeze how much of this card was paid by stored balance.
        // A manual cash refund must never convert that non-cash tender to cash.
        $balancePaidCents = $receipt && (string)($receipt['sales_order_line_id'] ?? '') !== ''
            ? (int)Db::name('cashier_v3_order_balance_allocation')
                ->where('tenant_id', $tenantId)
                ->where('order_line_id', (string)$receipt['sales_order_line_id'])->value('amount_cents')
            : 0;
        // Balance-funded debt settlement is also non-cash; keep the original checkout share immutable.
        $balancePaidCents += \app\services\cashier\v3\settlement\CashierV3SalesDebtBalanceServices::nonCashForSource(
            $tenantId, (string)($receipt['sales_order_line_id'] ?? ''), $originId);
        // 历史转入赠金不属于再次退款；现金额度仅扣有效退款，权益消耗另由 usableSum 限制。
        $cashRefundRemainingCents = max(0, $cardPurchaseCents - $cardCouponCents - $balancePaidCents
            - $priorRefundCents);
        $otherCollection = $this->usesOtherCollection($tenantId, $salesOrderId);
        $cashRefundRemainingCents = $otherCollection ? 0 : min($cashRefundRemainingCents, $usableSum);
        $hasZeroPriceRights = $cardPurchaseCents === 0 && (int)$candidate['write_surplus_times'] > 0;
        $canRefund = !$voided && ($usableSum > 0 || $hasZeroPriceRights) && $components !== [] && $debtCents === 0
            && (int)$candidate['origin_uid'] === $memberId && !$otherCollection;
        $reason = $otherCollection ? '原单使用了其他收款，只能整单作废，不能办理退款。'
            : ($debtCents > 0 ? '本卡存在未结清欠款，请先补交或取消欠款后再办理退款。'
            : ($voided ? ($disabledReason !== null
                    ? '本卡已停用' . ($disabledReason !== '' ? '（原因：' . $disabledReason . '）' : '') . '，请先到卡操作中核对并启用，再办理退款。'
                    : '本卡已作废或退款终止，不能再次退款。')
                : ($usableSum <= 0 ? '本卡没有可退的剩余金额。' : '本卡来源状态已变化，请联系管理员核对。')));
        // V3 newly purchased cards have an internal v3c... entitlement order; only
        // the issuance receipt links that order to the customer-facing XS... sale.
        $salesOrderNo = array_key_exists('sales_order_no', $candidate)
            ? trim((string)$candidate['sales_order_no'])
            : trim((string)Db::name(CashierV3CardPurchaseIssuanceServices::RECEIPT_TABLE)->alias('cpr')
                ->join('cashier_v3_sales_order so', 'so.order_id=cpr.sales_order_id AND so.tenant_id=cpr.tenant_id')
                ->where('cpr.tenant_id', $tenantId)->where('cpr.card_holder_id', $holderId)
                ->where('cpr.legacy_order_id', $originId)->where('cpr.status', 'completed')
                ->value('so.order_no'));
        return ['holderId' => $holderId, 'memberId' => $memberId, 'cardName' => (string)$candidate['card_name'],
            'cardNo' => (string)$candidate['card_no'], 'name' => (string)$candidate['card_name'],
            'sourceOrderId' => (string)$originId,
            'sourceOrderNo' => $salesOrderNo !== '' ? $salesOrderNo : (string)$candidate['order_id'],
            'purchaseAmountCents' => array_sum(array_column($components, 'purchaseAmountCents')),
            'remainingAmountCents' => $sum, 'usableRemainingAmountCents' => $usableSum,
            'remainingTimes' => (int)$candidate['write_surplus_times'],
            'cashRefundRemainingCents' => $cashRefundRemainingCents,
            'historicalRefundCents' => $priorRefundCents,
            'validThrough' => (int)$candidate['write_end'], 'isTimeCard' => $isTime,
            'ruleType' => (string)($rule['rule_type'] ?? ''), 'components' => $components,
            'voided' => $voided, 'canRefund' => $canRefund, 'unavailableReason' => $canRefund ? '' : $reason];
    }

    /** 只保存本次退款可能改变的权益列；作废时对比整个后快照再恢复原值。 */
    public function refundReversalRightsSnapshotInTx(string $tenantId, int $holderId): array
    {
        CashierV3TransactionGuard::assertInTransaction('refundReversalRightsSnapshot');
        $holder = (array)Db::name('user_card_holder')->where('id', $holderId)
            ->field('id,uid,oid,is_del,write_times,write_surplus_times,write_end')->lock(true)->find();
        if (!$holder) throw self::fail('卡项权威记录缺失，无法保存退款恢复快照。', 'refund_rights_holder_missing');
        $state = (array)Db::name('cashier_v3_card_state')->where('tenant_id', $tenantId)
            ->where('card_holder_id', $holderId)->field('id,current_member_id,card_status,status_reason_snapshot,current_version,last_operation_id')->lock(true)->find();
        $rule = (array)Db::name('cashier_v3_card_rule_state')->where('tenant_id', $tenantId)
            ->where('card_holder_id', $holderId)->field('id,status,shared_remaining_times,valid_through,selected_kind_count,state_version')->lock(true)->find();
        $components = Db::name('cashier_v3_card_rule_component')->where('tenant_id', $tenantId)
            ->where('card_holder_id', $holderId)->field('id,status,remaining_times,selection_status,state_version')->order('id asc')->lock(true)->select()->toArray();
        $groups = (string)($rule['status'] ?? '') !== ''
            && (string)Db::name('cashier_v3_card_rule_state')->where('tenant_id', $tenantId)
                ->where('card_holder_id', $holderId)->value('rule_type') === 'combination'
            ? Db::name('cashier_v3_card_rule_group_state')->where('tenant_id', $tenantId)
                ->where('card_holder_id', $holderId)
                ->field('id,status,shared_remaining_times,selected_kind_count,state_version')
                ->order('group_no asc')->lock(true)->select()->toArray() : [];
        $carts = Db::name('store_order_cart_info')->where('oid', (int)$holder['oid'])
            ->where('cart_type', 2)->where('product_type', 6)
            ->field('id,cart_info,write_times,write_surplus_times,write_end')->order('id asc')->lock(true)->select()->toArray();
        return compact('holder', 'state', 'rule', 'components', 'groups', 'carts');
    }

    private function lineSnapshot(string $lineId, int $memberId, string $tenantId, bool $lock, ?array $line = null): array
    {
        $line = $line ?: (array)Db::name('cashier_v3_sales_order_line')->alias('l')
            ->join('cashier_v3_sales_order so', 'so.order_id=l.order_id AND so.tenant_id=l.tenant_id')
            ->where('l.order_line_id', $lineId)->where('l.tenant_id', $tenantId)
            ->where('l.member_id', $memberId)
            ->field('l.*,so.order_no,so.order_status,so.order_direction')->lock($lock)->find();
        if (!$line || (int)$line['member_id'] !== $memberId || (string)$line['tenant_id'] !== $tenantId
            || (string)$line['line_status'] !== 'settled' || (string)$line['line_direction'] !== 'forward'
            || (string)$line['order_status'] !== 'settled' || (string)$line['order_direction'] !== 'forward'
            || !in_array((string)$line['item_type'], ['project', 'product'], true)) {
            throw self::fail('这条明细不能办理退款，请重新选择。', 'refund_line_ineligible');
        }
        $history = Db::name(self::TABLE)->where('tenant_id', $tenantId)->where('source_line_id', $lineId)
            ->where('status', 'effective')->order('id asc')->lock($lock)->select()->toArray();
        // 与 adjustLine 的落账结果一致：只按有效退款扣剩余金额，独立赠金不再重复扣减。
        $used = array_sum(array_map(static fn(array $r): int => (int)$r['refund_amount_cents'], $history));
        $voided = (bool)array_filter($history, static fn(array $r): bool => (int)$r['void_target'] === 1);
        $remaining = max(0, (int)$line['sale_amount_cents']
            - (int)($line['coupon_payment_cents'] ?? 0) - $used);
        $otherCollection = $this->usesOtherCollection($tenantId, (string)$line['order_id']);
        // The frozen balance share is not refundable as cash. Historical
        // orders have no allocation row and retain their old refund boundary.
        $balancePaidCents = (int)Db::name('cashier_v3_order_balance_allocation')
            ->where('tenant_id', $tenantId)->where('order_line_id', $lineId)->value('amount_cents');
        $balancePaidCents += \app\services\cashier\v3\settlement\CashierV3SalesDebtBalanceServices::nonCashForSource($tenantId, $lineId);
        $cashRefundRemaining = $otherCollection ? 0 : max(0, $remaining - $balancePaidCents);
        return ['lineId' => $lineId, 'memberId' => $memberId, 'name' => (string)$line['item_name_snapshot'],
            'itemType' => (string)$line['item_type'], 'sourceOrderId' => (string)$line['order_id'],
            'sourceOrderNo' => (string)$line['order_no'], 'purchaseAmountCents' => (int)$line['sale_amount_cents'],
            'remainingAmountCents' => $remaining, 'usableRemainingAmountCents' => $remaining,
            'cashRefundRemainingCents' => $cashRefundRemaining,
            'remainingTimes' => 0, 'voided' => $voided,
            'canRefund' => !$voided && !$otherCollection && $remaining > 0,
            'unavailableReason' => $otherCollection ? '原单使用了其他收款，只能整单作废，不能办理退款。'
                : ($voided ? '这条明细已作废，不能再次退款。' : '这条明细没有可退金额。')];
    }

    /** The committed tender draft, not a cash collection row, records both historical and new other-collection orders. */
    private function primeOtherCollectionOrders(string $tenantId, array $orderIds): void
    {
        $missing = [];
        foreach (array_unique(array_filter(array_map('strval', $orderIds))) as $orderId) {
            $key = $tenantId . ':' . $orderId;
            if (!array_key_exists($key, $this->otherCollectionOrders)) {
                $this->otherCollectionOrders[$key] = false;
                $missing[] = $orderId;
            }
        }
        if ($missing === []) return;
        $rows = Db::name('cashier_v3_sales_order')->alias('so')
            ->join('cashier_v3_checkout_payment_draft d', 'd.request_id=so.checkout_request_id AND d.tenant_id=so.tenant_id')
            ->where('so.tenant_id', $tenantId)->whereIn('so.order_id', $missing)
            ->where('d.payment_method', 'other_collection')->where('d.draft_status', 'committed')
            ->field('so.order_id')->select()->toArray();
        foreach ($rows as $row) $this->otherCollectionOrders[$tenantId . ':' . (string)$row['order_id']] = true;
    }

    private function usesOtherCollection(string $tenantId, string $orderId): bool
    {
        if ($orderId === '') return false;
        $this->primeOtherCollectionOrders($tenantId, [$orderId]);
        return $this->otherCollectionOrders[$tenantId . ':' . $orderId];
    }

    private function adjustLine(array $line, int $refund, bool $void): array
    {
        $left = $line['remainingAmountCents'] - $refund;
        return ['remainingAmountCents' => $void ? 0 : $left,
            'closedRemainingCents' => $void ? $left : 0,
            'remainingTimes' => 0, 'timesRemoved' => 0, 'timesClosed' => 0];
    }

    private function adjustCard(array $card, array $target, int $refund, bool $void, int $newEnd, string $tenantId, int $now, bool $entireCard = false): array
    {
        // 整卡使用整张卡的已锁定余值，不伪造一个项目或重复逐项目登记退款。
        if ($entireCard || $card['ruleType'] === 'combination') {
            if (!$void) throw self::fail('组合卡只能整卡退款。', 'refund_combination_whole_card_required');
            $this->voidCard($card['holderId'], $tenantId, $now);
            return ['remainingAmountCents' => 0,
                'closedRemainingCents' => max(0, (int)$card['remainingAmountCents'] - $refund),
                'remainingTimes' => 0, 'timesRemoved' => 0,
                'timesClosed' => (int)$card['remainingTimes']];
        }
        if ($card['ruleType'] === 'choice_count') {
            return $this->adjustSharedCard($card, $target, $refund, $void, $tenantId, $now);
        }
        $removed = 0;
        $newTimes = (int)$target['remainingTimes'];
        if (!$card['isTimeCard'] && (int)$target['purchaseAmountCents'] > 0) {
            $used = (int)$target['totalTimes'] - (int)$target['remainingTimes'] - (int)$target['removedTimes'];
            $quota = CashierV3CardRefundQuotaPolicy::afterReturn(
                (int)$target['purchaseAmountCents'], (int)$target['totalTimes'], $used,
                (int)$target['refundedCents'] + $refund,
                (int)$target['purchaseAmountCents'] % 100 === 0
            );
            $newTimes = min((int)$target['remainingTimes'], (int)$quota['usableTimes']);
            $removed = (int)$target['remainingTimes'] - $newTimes;
        }
        $snapshot = $target['cartInfo'];
        $snapshot['r26RefundedCents'] = (int)$target['refundedCents'] + $refund;
        $snapshot['r26RefundRemovedTimes'] = (int)$target['removedTimes'] + $removed;
        $update = ['cart_info' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        if (!$card['isTimeCard']) $update['write_surplus_times'] = $newTimes;
        if ($card['isTimeCard']) $update['write_end'] = $newEnd;
        Db::name('store_order_cart_info')->where('id', $target['id'])->update($update);
        $totalTimes = $card['remainingTimes'] - $removed;
        $closedTimes = 0;
        if ($void) {
            $closedTimes = $totalTimes;
            $totalTimes = 0;
            $this->voidCard($card['holderId'], $tenantId, $now);
        } else {
            Db::name('user_card_holder')->where('id', $card['holderId'])->update([
                'write_surplus_times' => $totalTimes,
                'write_end' => $card['isTimeCard'] ? $newEnd : $card['validThrough'],
            ]);
            if (!$card['isTimeCard']) Db::name('cashier_v3_card_rule_component')
                ->where('tenant_id', $tenantId)->where('legacy_detail_id', $target['id'])
                ->where('status', 'active')->update(['remaining_times' => $newTimes,
                    'state_version' => Db::raw('state_version+1'), 'update_time' => $now]);
            if ($card['isTimeCard']) Db::name('cashier_v3_card_rule_state')->where('tenant_id', $tenantId)
                ->where('card_holder_id', $card['holderId'])->where('status', 'active')
                ->update(['valid_through' => $newEnd, 'state_version' => Db::raw('state_version+1'), 'update_time' => $now]);
        }
        $remaining = (int)$card['remainingAmountCents'] - $refund;
        return ['remainingAmountCents' => $void ? 0 : $remaining,
            'closedRemainingCents' => $void ? $remaining : 0,
            'remainingTimes' => $totalTimes, 'timesRemoved' => $removed, 'timesClosed' => $closedTimes];
    }

    /** Refund against the original whole-card value and atomically reduce its shared pool. */
    private function adjustSharedCard(array $card, array $target, int $refund, bool $void, string $tenantId, int $now): array
    {
        $total = (int)$target['totalTimes'];
        $left = (int)$target['remainingTimes'];
        $used = $total - $left - (int)$target['removedTimes'];
        $purchase = (int)$target['purchaseAmountCents'];
        // A zero-price shared card has no cash-based quota to recompute; a zero refund
        // leaves its uses intact unless the operator explicitly voids the whole card.
        if ($purchase === 0 && $refund === 0) {
            $newTimes = $left;
        } else {
            $quota = CashierV3CardRefundQuotaPolicy::afterReturn(
                $purchase, $total, $used, (int)$target['refundedCents'] + $refund, $purchase % 100 === 0
            );
            $newTimes = (int)$quota['usableTimes'];
        }
        $removed = $left - $newTimes;
        if ($removed < 0) throw self::fail('本卡共享次数已变化，请重新查询。', 'refund_shared_quota_changed');
        if ($void) {
            $this->voidCard($card['holderId'], $tenantId, $now);
        } else {
            Db::name('cashier_v3_card_rule_state')->where('tenant_id', $tenantId)
                ->where('card_holder_id', $card['holderId'])->whereIn('status', ['active', 'exhausted'])
                ->update(['shared_remaining_times' => $newTimes,
                    'status' => $newTimes === 0 ? 'exhausted' : 'active',
                    'state_version' => Db::raw('state_version+1'), 'update_time' => $now]);
            Db::name('user_card_holder')->where('id', $card['holderId'])
                ->update(['write_surplus_times' => $newTimes]);
        }
        $remaining = (int)$card['remainingAmountCents'] - $refund;
        return ['remainingAmountCents' => $void ? 0 : $remaining,
            'closedRemainingCents' => $void ? $remaining : 0,
            'remainingTimes' => $void ? 0 : $newTimes, 'timesRemoved' => $removed,
            'timesClosed' => $void ? $newTimes : 0];
    }

    /** Voiding closes the card and all future service rights without rewriting its sale. */
    private function voidCard(int $holderId, string $tenantId, int $now): void
    {
        Db::name('user_card_holder')->where('id', $holderId)
            ->update(['is_del' => 1, 'write_surplus_times' => 0]);
        Db::name('cashier_v3_card_state')->where('tenant_id', $tenantId)
            ->where('card_holder_id', $holderId)
            ->update(['card_status' => 'disabled', 'status_reason_snapshot' => '卡项退款作废本卡',
                'current_version' => Db::raw('current_version+1'), 'updated_at' => $now]);
        Db::name('cashier_v3_card_rule_state')->where('tenant_id', $tenantId)
            ->where('card_holder_id', $holderId)->whereIn('status', ['active', 'exhausted'])
            ->update(['status' => 'revoked', 'shared_remaining_times' => 0,
                'state_version' => Db::raw('state_version+1'), 'update_time' => $now]);
        Db::name('cashier_v3_card_rule_component')->where('tenant_id', $tenantId)
            ->where('card_holder_id', $holderId)->whereIn('status', ['active', 'exhausted'])
            ->update(['status' => 'revoked', 'state_version' => Db::raw('state_version+1'), 'update_time' => $now]);
        // 组合组状态跟随整卡终止，作废退款时可按前后快照安全恢复。
        if ((string)Db::name('cashier_v3_card_rule_state')->where('tenant_id', $tenantId)
            ->where('card_holder_id', $holderId)->value('rule_type') === 'combination') {
            Db::name('cashier_v3_card_rule_group_state')->where('tenant_id', $tenantId)
                ->where('card_holder_id', $holderId)->whereIn('status', ['active', 'exhausted'])
                ->update(['status' => 'revoked', 'state_version' => Db::raw('state_version+1'), 'update_time' => $now]);
        }
    }

    /** Debit and refund are distinct amounts; the ledger and split fact must match the same locked wallet. */
    private function debitBalance(int $memberId, int $amount, string $key, int $adjustmentRowId,
        string $operationId, string $operationNo, CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $scope, array $event, string $date, int $now, array $snapshot): void
    {
        $before = $snapshot['wallet'];
        try {
            $changed = (new UserBalanceAtomicServices())->deductPreferBen($memberId,
                self::centsToMoney($amount), 'manual_refund_debit', $adjustmentRowId,
                '余额退款扣减', 'cv3-balance-refund-' . hash('sha256', $scope->tenantId() . '|' . $key));
        } catch (\Throwable $error) {
            throw self::fail('会员余额不足或资料已变化，本次退款已回滚。', 'refund_balance_debit_failed');
        }
        $after = (new CashierV3MemberBalanceProvider())->lockSnapshotInTx($memberId, $operator, $scope);
        $principal = self::moneyCents((string)($changed['paid_ben'] ?? ''), true);
        $bonus = self::moneyCents((string)($changed['paid_give'] ?? ''), true);
        if (empty($changed['money_id']) || !empty($changed['idempotent']) || $principal + $bonus !== $amount
            || (int)$after['accountVersion'] !== (int)$before['accountVersion'] + 1
            || (int)$after['principalCents'] !== (int)$before['principalCents'] - $principal
            || (int)$after['giftCents'] !== (int)$before['giftCents'] - $bonus) {
            throw self::fail('余额扣减流水与本金赠金不一致，本次退款已回滚。', 'refund_balance_debit_mismatch');
        }
        Db::name(self::TABLE)->where('id', $adjustmentRowId)->where('tenant_id', $scope->tenantId())
            ->update(['principal_debit_cents' => $principal, 'bonus_debit_cents' => $bonus]);
        $storeName = (string)Db::name('system_store')->where('id', $operator->storeId())->value('name');
        $organizationId = (string)Db::name('organization_store')->where('store_id', $operator->storeId())->value('org_id');
        $memberName = (string)Db::name('user')->where('uid', $memberId)->value('real_name');
        $fact = [
            'fact_id' => 'R29B-' . substr(hash('sha256', $operationId), 0, 40),
            'business_event_no' => (string)$event['event_no'],
            'fact_type' => 'balance_changed', 'fact_direction' => 'forward',
            'natural_key' => 'manual_refund_debit:' . $operationId,
            'command_idempotency_key' => $key, 'fact_version' => 1, 'reversal_of' => '',
            'status' => 'effective', 'tenant_id' => $scope->tenantId(),
            'tenant_name_snapshot' => '', 'organization_id' => $organizationId,
            'organization_name_snapshot' => '', 'organization_path_snapshot' => $organizationId,
            'store_id' => $operator->storeId(), 'store_name_snapshot' => $storeName,
            'member_id' => $memberId, 'member_name_snapshot' => $memberName,
            'operator_id' => $operator->operatorId(), 'operator_name_snapshot' => '',
            'business_date' => $date, 'business_timezone' => 'Asia/Shanghai',
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'checkout_request_id' => $operationId, 'order_id' => $operationId,
            'order_no_snapshot' => $operationNo, 'source_document_type' => 'manual_refund',
            'source_line_id' => (string)$adjustmentRowId, 'balance_change_type' => 'manual_refund_debit',
            'balance_account_id' => (string)$after['authorityKey'],
            'account_version' => (int)$after['accountVersion'],
            'principal_delta_cents' => -$principal, 'bonus_delta_cents' => -$bonus,
            'principal_after_cents' => (int)$after['principalCents'],
            'bonus_after_cents' => (int)$after['giftCents'],
        ];
        $fact['immutable_fingerprint'] = hash('sha256', json_encode($fact, JSON_UNESCAPED_UNICODE));
        Db::name('cashier_v3_balance_fact')->insert($fact);
    }

    private function creditBalance(int $memberId, int $amount, string $key, int $adjustmentRowId,
        string $operationId, string $sourceOrderNo, CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $scope, array $event, string $date, int $now): void
    {
        if ($amount === 0) return;
        $principal = 0;
        $bonus = $amount;
        $provider = new \app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider();
        $before = $provider->lockSnapshotInTx($memberId, $operator, $scope);
        $changed = (new UserBalanceAtomicServices())->creditBenGive(
            $memberId, self::centsToMoney($principal), self::centsToMoney($bonus),
            'manual_refund_credit', $adjustmentRowId, '退款转入会员余额',
            'cv3-manual-refund-' . hash('sha256', $scope->tenantId() . '|' . $key)
        );
        $after = $provider->lockSnapshotInTx($memberId, $operator, $scope);
        if (empty($changed['after']) || (int)$after['accountVersion'] !== (int)$before['accountVersion'] + 1
            || (int)$after['principalCents'] !== (int)$before['principalCents'] + $principal
            || (int)$after['giftCents'] !== (int)$before['giftCents'] + $bonus) {
            throw self::fail('会员余额增加失败，退款已回滚。', 'refund_balance_failed');
        }
        $storeName = (string)Db::name('system_store')->where('id', $operator->storeId())->value('name');
        $organizationId = (string)Db::name('organization_store')->where('store_id', $operator->storeId())->value('org_id');
        $memberName = (string)Db::name('user')->where('uid', $memberId)->value('real_name');
        $factId = 'R26B-' . substr(hash('sha256', $operationId), 0, 40);
        $fact = [
            'fact_id' => $factId, 'business_event_no' => (string)$event['event_no'],
            'fact_type' => 'balance_changed', 'fact_direction' => 'forward',
            'natural_key' => 'manual_refund_credit:' . $operationId,
            'command_idempotency_key' => $key, 'fact_version' => 1, 'reversal_of' => '',
            'status' => 'effective', 'tenant_id' => $scope->tenantId(),
            'tenant_name_snapshot' => '', 'organization_id' => $organizationId,
            'organization_name_snapshot' => '', 'organization_path_snapshot' => $organizationId,
            'store_id' => $operator->storeId(), 'store_name_snapshot' => $storeName,
            'member_id' => $memberId, 'member_name_snapshot' => $memberName,
            'operator_id' => $operator->operatorId(), 'operator_name_snapshot' => '',
            'business_date' => $date, 'business_timezone' => 'Asia/Shanghai',
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'checkout_request_id' => $operationId, 'order_id' => $operationId,
            'order_no_snapshot' => $sourceOrderNo, 'source_document_type' => 'manual_refund',
            'source_line_id' => (string)$adjustmentRowId, 'balance_change_type' => 'manual_refund_credit',
            'balance_account_id' => (string)$after['authorityKey'],
            'account_version' => (int)$after['accountVersion'],
            'principal_delta_cents' => $principal, 'bonus_delta_cents' => $bonus,
            'principal_after_cents' => (int)$after['principalCents'],
            'bonus_after_cents' => (int)$after['giftCents'],
        ];
        $fact['immutable_fingerprint'] = hash('sha256', json_encode($fact, JSON_UNESCAPED_UNICODE));
        Db::name('cashier_v3_balance_fact')->insert($fact);
    }

    private function result(array $row, bool $replay, array $after = []): array
    {
        // A repeated command must return its committed result, not today's changed card state.
        $saved = (array)Db::name(self::TABLE)->where('operation_id', (string)$row['operation_id'])->find();
        if ($replay && $saved) $after = [
                'remainingAmountCents' => (int)$saved['remaining_after_cents'],
                'remainingTimes' => (int)$saved['times_after'],
                'closedRemainingCents' => (int)$saved['closed_remaining_cents'],
                'voidTarget' => (int)$saved['void_target'] === 1,
        ];
        return ['data' => ['unifiedRefund' => [
            'operationId' => (string)$row['operation_id'], 'operationNo' => (string)$row['operation_no'],
            'mode' => (string)$row['source_type'],
            'targetName' => (string)($saved['target_name_snapshot'] ?? ''),
            'sourceOrderNo' => (string)$row['source_order_no_snapshot'],
            'refundAmountCents' => (int)$row['cash_refund_cents'],
            'balanceCreditCents' => (int)$row['restored_principal_cents'] + (int)$row['restored_bonus_cents'],
            'balanceDebitCents' => (int)($saved['balance_debit_cents'] ?? 0),
            'principalDebitCents' => (int)($saved['principal_debit_cents'] ?? 0),
            'bonusDebitCents' => (int)($saved['bonus_debit_cents'] ?? 0),
            'remainingAmountCents' => $after['remainingAmountCents'] ?? null,
            'remainingTimes' => $after['remainingTimes'] ?? null,
            'closedRemainingCents' => $after['closedRemainingCents'] ?? null,
            'voidTarget' => $after['voidTarget'] ?? null, 'replayed' => $replay,
        ]], 'business_no' => (string)$row['operation_no'], 'touched' => ['order_center', 'member_balance', 'member'],
            'message' => '退款登记已完成。'];
    }

    private function projectName(array $snapshot): string
    {
        $info = (array)($snapshot['productInfo'] ?? []);
        return trim((string)($info['store_name'] ?? $info['product_name'] ?? $snapshot['name'] ?? '卡内项目'));
    }

    private function assertAccess(CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): void
    {
        if ($operator->tenantId() !== $scope->tenantId() || $operator->storeId() !== $scope->forcedStoreId()
            || !$scope->allowsStore($operator->storeId()) || $scope->isReadOnlySession()
            || !$scope->hasFeature('cashier.v3.order.refund')) {
            throw self::fail('当前账号没有退款权限。', 'refund_permission_denied');
        }
    }

    /** Only manual refund inputs are whole yuan; historic card values retain cent precision. */
    private static function enteredWholeYuanCents($value, bool $allowZero): int
    {
        if (!is_string($value) && !is_int($value)) throw self::fail('请填写有效金额。', 'refund_amount_type');
        $raw = trim((string)$value);
        if (!preg_match('/^(?:0|[1-9][0-9]{0,7})$/D', $raw)) {
            throw self::fail('金额只能填写整数元。', 'refund_amount_format');
        }
        $cents = (int)$raw * 100;
        if ((!$allowZero && $cents <= 0) || $cents < 0) throw self::fail('退款金额必须大于0。', 'refund_amount_zero');
        return $cents;
    }

    private static function moneyCents($value, bool $allowZero): int
    {
        if (!is_string($value) && !is_int($value)) throw self::fail('请填写有效金额。', 'refund_amount_type');
        $raw = trim((string)$value);
        if (!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D', $raw)) {
            throw self::fail('金额最多保留两位小数。', 'refund_amount_format');
        }
        [$yuan, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $cents = (int)$yuan * 100 + (int)str_pad($fraction, 2, '0');
        if ((!$allowZero && $cents <= 0) || $cents < 0) throw self::fail('退款金额必须大于0。', 'refund_amount_zero');
        return $cents;
    }

    private static function centsToMoney(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function fail(string $message, string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
            $message, CashierV3ResultCode::STATUS_FAILED, ['reason' => $reason]);
    }
}
