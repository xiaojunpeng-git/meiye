<?php
declare(strict_types=1);

namespace app\services\order;

use app\model\order\StoreOrderTerminalOperation;
use app\services\order\terminal\OrderTerminalError;
use app\services\order\terminal\RefundSideEffectOutboxServices;
use app\services\user\UserBalanceAtomicServices;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\Log;

/**
 * 整单作废：冲销本地业务影响；不计入退款统计；不写退款申请表；不对渠道自动退款。
 * 普通单余额支付：冲正回会员本金/赠金。充值单：整笔扣回本次充值本金/赠金。
 */
class StoreOrderVoidServices
{
    public const FINANCE_TYPE_VOID = 16;
    public const STAFF_FINANCE_TYPE_VOID = 7;

    public function voidWholeOrder(array $input): array
    {
        $storeOrderId = (int)($input['store_order_id'] ?? 0);
        $order = Db::name('store_order')->where('id', $storeOrderId)->find();
        if (!$order) {
            OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND);
        }
        $order = is_array($order) ? $order : $order->toArray();
        if ((int)($order['order_type'] ?? 0) === 2) {
            OrderTerminalError::throw(OrderTerminalError::WRITEOFF_SUB_ORDER);
        }
        if ((int)($order['order_type'] ?? 0) === 1) {
            return $this->voidRechargeOrder($order, $input);
        }
        return $this->voidSalesOrder($order, $input);
    }

    /**
     * 充值订单独立作废：扣回本次充值本金/赠金；标记 user_recharge；不写 refund_price；不走渠道退款。
     */
    protected function voidRechargeOrder(array $order, array $input): array
    {
        /** @var StoreOrderTerminalOperationServices $terminal */
        $terminal = app()->make(StoreOrderTerminalOperationServices::class);
        $storeOrderId = (int)$order['id'];
        $storeScope = (int)($input['store_scope'] ?? 0);
        $reason = $this->normalizeReason($input);
        $rechargeId = (int)($input['link_recharge_id'] ?? 0);
        if ($rechargeId <= 0) {
            $rechargeId = (int)($order['link_id'] ?? 0);
        }
        if ($rechargeId <= 0) {
            OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND, 'recharge link missing');
        }

        $recharge = Db::name('user_recharge')->where('id', $rechargeId)->find();
        if (!$recharge) {
            OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND, 'recharge missing');
        }
        $recharge = is_array($recharge) ? $recharge : $recharge->toArray();
        $this->assertRechargeBelongsToOrder($order, $recharge, $storeScope);

        if (bccomp((string)($recharge['refund_price'] ?? '0'), '0', 2) > 0) {
            OrderTerminalError::throw(OrderTerminalError::RECHARGE_ALREADY_REFUNDED);
        }

        $requestToken = trim((string)($input['request_token'] ?? ''));
        // 禁止在 beginOrResume 前无条件拒绝 terminal_action=2；先按本单终态操作判定可否恢复
        $this->assertRechargeVoidEntryAllowed($storeOrderId, $recharge, $requestToken);

        $refundBen = bcadd((string)($recharge['price'] ?? '0'), '0', 2);
        $refundGive = bcadd((string)($recharge['give_price'] ?? '0'), '0', 2);
        $refundTotal = bcadd($refundBen, $refundGive, 2);

        $execOwner = trim((string)($input['execution_owner'] ?? '')) ?: $terminal->makeExecutionOwner();
        $beginCtx = [
            'store_order_id' => $storeOrderId,
            'action_type' => StoreOrderTerminalOperation::ACTION_VOID,
            'business_type' => StoreOrderTerminalOperation::BUSINESS_RECHARGE,
            'source_type' => (int)($input['source_type'] ?? StoreOrderTerminalOperation::SOURCE_STORE),
            'operator_type' => (string)($input['operator_type'] ?? 'store'),
            'operator_id' => (int)($input['operator_id'] ?? 0),
            'store_scope' => $storeScope,
            'request_token' => $requestToken,
            'refund_amount' => $refundTotal,
            'refund_ben' => $refundBen,
            'refund_give' => $refundGive,
            'link_recharge_id' => $rechargeId,
            'reason' => $reason,
            'execution_owner' => $execOwner,
            'bookkeeping_confirmed' => (int)($input['bookkeeping_confirmed'] ?? 0),
            'bookkeeping_remark' => (string)($input['bookkeeping_remark'] ?? ''),
        ];
        if ($requestToken === '') {
            unset($beginCtx['request_token']);
        }

        $begin = $terminal->beginOrResume($beginCtx);
        if (!$terminal->shouldExecute($begin)) {
            return $this->observerResult($begin, $order);
        }
        // 执行权到手后再核对：同 operation_no 的部分成功可继续；其它操作作废须人工核对
        $this->assertRechargeVoidProgressAllowed($rechargeId, (string)$begin['operation_no']);

        $opId = (int)$begin['id'];
        $opNo = (string)$begin['operation_no'];
        $epoch = (int)($begin['execution_epoch'] ?? 0);
        $injectFail = (string)($input['inject_fail_after'] ?? '');
        $balanceDone = (int)($begin['balance_refund_done'] ?? 0) === 1;
        $localDone = (int)($begin['local_close_done'] ?? 0) === 1;
        $curState = (int)($begin['state'] ?? StoreOrderTerminalOperation::STATE_INIT);

        try {
            // 恢复时可能已在 LOCAL_CLOSING：禁止非法回退到 BALANCE_PENDING
            if ($curState !== StoreOrderTerminalOperation::STATE_LOCAL_CLOSING
                && $curState !== StoreOrderTerminalOperation::STATE_SUCCESS) {
                $step = $terminal->transition($opId, StoreOrderTerminalOperation::STATE_BALANCE_PENDING, [], $execOwner, $epoch);
                if (!$terminal->shouldExecute($step)
                    && (int)$step['state'] !== StoreOrderTerminalOperation::STATE_BALANCE_PENDING) {
                    return $this->observerResult($step, $order);
                }
            }

            if (!$balanceDone) {
                $this->deductRechargeBalanceOnce($recharge, $refundBen, $refundGive, $opNo, $opId, $execOwner, $epoch);
                $balanceDone = true;
            }

            if ($injectFail === 'hard_exit_after_balance_before_local') {
                fwrite(STDERR, "HARD_EXIT_AFTER_BALANCE_BEFORE_LOCAL op_no={$opNo}\n");
                exit(99);
            }

            $freshState = (int)Db::name('store_order_terminal_operation')->where('id', $opId)->value('state');
            if ($freshState !== StoreOrderTerminalOperation::STATE_LOCAL_CLOSING
                && $freshState !== StoreOrderTerminalOperation::STATE_SUCCESS) {
                $local = $terminal->transition($opId, StoreOrderTerminalOperation::STATE_LOCAL_CLOSING, [], $execOwner, $epoch);
                if (!$terminal->shouldExecute($local)
                    && (int)$local['state'] !== StoreOrderTerminalOperation::STATE_LOCAL_CLOSING) {
                    return $this->observerResult($local, $order);
                }
            }
            $terminal->renewExecutionLease($opId, $execOwner, $epoch);

            if (!$localDone) {
                if ($injectFail === 'hard_exit_after_local_before_finance') {
                    // 本地充值标记已落库，但未写 local_close_done、未入财务 Outbox
                    $this->applyRechargeVoidMarks($rechargeId, $storeOrderId, $opNo);
                    fwrite(STDERR, "HARD_EXIT_AFTER_LOCAL_BEFORE_FINANCE op_no={$opNo}\n");
                    exit(99);
                }
                $this->finalizeRechargeVoidLocal($rechargeId, $storeOrderId, $opId, $opNo, $execOwner, $epoch, $refundBen, $refundGive, true);
                if ($injectFail === 'hard_exit_after_finance_before_success') {
                    fwrite(STDERR, "HARD_EXIT_AFTER_FINANCE_BEFORE_SUCCESS op_no={$opNo}\n");
                    exit(99);
                }
            } else {
                /** @var RefundSideEffectOutboxServices $outbox */
                $outbox = app()->make(RefundSideEffectOutboxServices::class);
                $orderFresh = Db::name('store_order')->where('id', $storeOrderId)->find();
                $this->ensureVoidFinanceOutbox($outbox, is_array($orderFresh) ? $orderFresh : (array)$orderFresh, $opNo, $opId, '充值作废');
                $outbox->flushPending($opNo);
            }

            if ((int)Db::name('store_order_terminal_operation')->where('id', $opId)->value('state')
                !== StoreOrderTerminalOperation::STATE_SUCCESS) {
                $terminal->markSuccess($opId, [
                    'local_close_done' => 1,
                    'balance_refund_done' => 1,
                    'link_recharge_id' => $rechargeId,
                ], $execOwner, $epoch);
            }

            Db::name('store_order')->where('id', $storeOrderId)
                ->where('terminal_action', StoreOrderTerminalOperation::ACTION_VOID)
                ->update(['refund_status' => 0, 'refund_type' => 0]);

            /** @var RefundSideEffectOutboxServices $outboxFlush */
            $outboxFlush = app()->make(RefundSideEffectOutboxServices::class);
            $outboxFlush->flushPending($opNo);

            return [
                'ok' => true,
                'should_execute' => true,
                'execution_acquired' => true,
                'idempotent_observer' => false,
                'store_order_id' => $storeOrderId,
                'recharge_id' => $rechargeId,
                'operation_id' => $opId,
                'operation_no' => $opNo,
                'state' => StoreOrderTerminalOperation::STATE_SUCCESS,
                'action_type' => StoreOrderTerminalOperation::ACTION_VOID,
                'can_reopen' => false,
                'message' => '作废成功',
            ];
        } catch (\Throwable $e) {
            Log::warning('[void_recharge_fail] op=' . $opId . ' ' . $e->getMessage());
            try {
                if ($balanceDone && !($e instanceof ValidateException)) {
                    $terminal->markNeedManual($opId, OrderTerminalError::GENERIC_FAIL, $e->getMessage(), $execOwner, $epoch);
                } else {
                    $terminal->markFailedRetryable($opId, OrderTerminalError::GENERIC_FAIL, $e->getMessage(), $execOwner, $epoch);
                }
            } catch (\Throwable $ignore) {
            }
            if ($e instanceof ValidateException) {
                throw $e;
            }
            OrderTerminalError::throw(OrderTerminalError::GENERIC_FAIL, $e->getMessage());
        }
    }

    /**
     * 普通销售单作废
     */
    protected function voidSalesOrder(array $order, array $input): array
    {
        /** @var StoreOrderTerminalOperationServices $terminal */
        $terminal = app()->make(StoreOrderTerminalOperationServices::class);
        $storeOrderId = (int)$order['id'];
        $storeScope = (int)($input['store_scope'] ?? 0);
        $reason = $this->normalizeReason($input);
        $isDebtRepay = !empty($order['is_debt_repay']);
        $businessType = $isDebtRepay
            ? StoreOrderTerminalOperation::BUSINESS_DEBT_REPAY
            : StoreOrderTerminalOperation::BUSINESS_ORDER;

        $execOwner = trim((string)($input['execution_owner'] ?? '')) ?: $terminal->makeExecutionOwner();
        $beginCtx = [
            'store_order_id' => $storeOrderId,
            'action_type' => StoreOrderTerminalOperation::ACTION_VOID,
            'business_type' => $businessType,
            'source_type' => (int)($input['source_type'] ?? StoreOrderTerminalOperation::SOURCE_STORE),
            'operator_type' => (string)($input['operator_type'] ?? 'store'),
            'operator_id' => (int)($input['operator_id'] ?? 0),
            'store_scope' => $storeScope,
            'request_token' => (string)($input['request_token'] ?? ''),
            'refund_amount' => (string)($order['pay_price'] ?? '0'),
            'refund_ben' => (string)($order['paid_ben_amount'] ?? '0'),
            'refund_give' => (string)($order['paid_give_amount'] ?? '0'),
            'reason' => $reason,
            'execution_owner' => $execOwner,
            'bookkeeping_confirmed' => (int)($input['bookkeeping_confirmed'] ?? 0),
            'bookkeeping_remark' => (string)($input['bookkeeping_remark'] ?? ''),
        ];
        if (trim((string)$beginCtx['request_token']) === '') {
            unset($beginCtx['request_token']);
        }

        $begin = $terminal->beginOrResume($beginCtx);
        if (!$terminal->shouldExecute($begin)) {
            return $this->observerResult($begin, $order);
        }

        $opId = (int)$begin['id'];
        $opNo = (string)$begin['operation_no'];
        $epoch = (int)($begin['execution_epoch'] ?? 0);
        $injectFail = (string)($input['inject_fail_after'] ?? '');
        $balanceDone = (int)($begin['balance_refund_done'] ?? 0) === 1;
        $localDone = (int)($begin['local_close_done'] ?? 0) === 1;
        $curState = (int)($begin['state'] ?? StoreOrderTerminalOperation::STATE_INIT);

        try {
            if ($curState !== StoreOrderTerminalOperation::STATE_LOCAL_CLOSING
                && $curState !== StoreOrderTerminalOperation::STATE_SUCCESS) {
                $step = $terminal->transition($opId, StoreOrderTerminalOperation::STATE_BALANCE_PENDING, [], $execOwner, $epoch);
                if (!$terminal->shouldExecute($step)
                    && (int)$step['state'] !== StoreOrderTerminalOperation::STATE_BALANCE_PENDING) {
                    return $this->observerResult($step, $order);
                }
            }

            if (!$balanceDone) {
                $this->assertSalesBalanceSnapshotClosed($order);
                $this->creditSalesBalanceIfNeeded($order, $opNo, $opId, $execOwner, $epoch);
                $balanceDone = true;
            }

            if ($injectFail === 'hard_exit_after_balance_before_local') {
                fwrite(STDERR, "HARD_EXIT_AFTER_BALANCE_BEFORE_LOCAL op_no={$opNo}\n");
                exit(99);
            }

            $freshState = (int)Db::name('store_order_terminal_operation')->where('id', $opId)->value('state');
            if ($freshState !== StoreOrderTerminalOperation::STATE_LOCAL_CLOSING
                && $freshState !== StoreOrderTerminalOperation::STATE_SUCCESS) {
                $local = $terminal->transition($opId, StoreOrderTerminalOperation::STATE_LOCAL_CLOSING, [], $execOwner, $epoch);
                if (!$terminal->shouldExecute($local)
                    && (int)$local['state'] !== StoreOrderTerminalOperation::STATE_LOCAL_CLOSING) {
                    return $this->observerResult($local, $order);
                }
            }
            $terminal->renewExecutionLease($opId, $execOwner, $epoch);

            if (!$localDone) {
                /** @var StoreOrderRollbackServices $rollback */
                $rollback = app()->make(StoreOrderRollbackServices::class);
                $orderFresh = Db::name('store_order')->where('id', $storeOrderId)->find();
                $orderFresh = is_array($orderFresh) ? $orderFresh : (array)$orderFresh;
                $rollback->runLocalBusinessRollback(
                    $orderFresh,
                    $opNo,
                    $reason,
                    !isset($input['return_coupon']) || (int)$input['return_coupon'] !== 0,
                    (int)($input['stock_in_type'] ?? 0),
                    $storeScope,
                    true
                );

                if ($injectFail === 'hard_exit_after_local_before_finance') {
                    fwrite(STDERR, "HARD_EXIT_AFTER_LOCAL_BEFORE_FINANCE op_no={$opNo}\n");
                    exit(99);
                }

                /** @var RefundSideEffectOutboxServices $outbox */
                $outbox = app()->make(RefundSideEffectOutboxServices::class);
                Db::transaction(function () use (
                    $outbox, $orderFresh, $opNo, $opId, $terminal, $execOwner, $epoch, $reason
                ) {
                    $this->enqueueVoidFinanceOutboxInTx($outbox, $orderFresh, $opNo, $opId, $reason);
                    $n = $terminal->fencedUpdate($opId, $execOwner, $epoch, [
                        'entitlement_rollback_done' => 1,
                        'inventory_rollback_done' => 1,
                        'performance_rollback_done' => 1,
                        'local_close_done' => 1,
                        'balance_refund_done' => 1,
                        'external_refund_done' => 1,
                        'external_refund_state' => 0,
                        'refund_event_done' => 1,
                    ]);
                    if ($n <= 0) {
                        $hold = Db::name('store_order_terminal_operation')->where('id', $opId)->find();
                        if (!$hold
                            || (string)($hold['execution_owner'] ?? '') !== $execOwner
                            || (int)($hold['execution_epoch'] ?? 0) !== $epoch) {
                            OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'void local fence lost');
                        }
                    }
                });

                if ($injectFail === 'hard_exit_after_finance_before_success') {
                    fwrite(STDERR, "HARD_EXIT_AFTER_FINANCE_BEFORE_SUCCESS op_no={$opNo}\n");
                    exit(99);
                }
            } else {
                /** @var RefundSideEffectOutboxServices $outbox */
                $outbox = app()->make(RefundSideEffectOutboxServices::class);
                $orderFresh = Db::name('store_order')->where('id', $storeOrderId)->find();
                $this->ensureVoidFinanceOutbox($outbox, is_array($orderFresh) ? $orderFresh : (array)$orderFresh, $opNo, $opId, $reason);
                $outbox->flushPending($opNo);
            }

            $terminal->markSuccess($opId, [
                'local_close_done' => 1,
                'balance_refund_done' => 1,
            ], $execOwner, $epoch);

            Db::name('store_order')->where('id', $storeOrderId)
                ->where('terminal_action', StoreOrderTerminalOperation::ACTION_VOID)
                ->update(['refund_status' => 0, 'refund_type' => 0]);

            /** @var RefundSideEffectOutboxServices $outboxFlush */
            $outboxFlush = app()->make(RefundSideEffectOutboxServices::class);
            $outboxFlush->flushPending($opNo);

            $canReopen = !$isDebtRepay && $terminal->canReopen(
                array_merge($order, ['terminal_action' => StoreOrderTerminalOperation::ACTION_VOID])
            );

            return [
                'ok' => true,
                'should_execute' => true,
                'execution_acquired' => true,
                'idempotent_observer' => false,
                'store_order_id' => $storeOrderId,
                'operation_id' => $opId,
                'operation_no' => $opNo,
                'state' => StoreOrderTerminalOperation::STATE_SUCCESS,
                'action_type' => StoreOrderTerminalOperation::ACTION_VOID,
                'can_reopen' => $canReopen,
                'message' => '作废成功',
            ];
        } catch (\Throwable $e) {
            Log::warning('[void_fail] op=' . $opId . ' ' . $e->getMessage());
            try {
                $terminal->markFailedRetryable($opId, OrderTerminalError::GENERIC_FAIL, $e->getMessage(), $execOwner, $epoch);
            } catch (\Throwable $ignore) {
            }
            if ($e instanceof ValidateException) {
                throw $e;
            }
            OrderTerminalError::throw(OrderTerminalError::GENERIC_FAIL, $e->getMessage());
        }
    }

    protected function assertRechargeBelongsToOrder(array $order, array $recharge, int $storeScope): void
    {
        if ((int)($order['link_id'] ?? 0) !== (int)$recharge['id']) {
            OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND, 'recharge order mismatch');
        }
        if ((int)($order['uid'] ?? 0) !== (int)($recharge['uid'] ?? 0)) {
            OrderTerminalError::throw(OrderTerminalError::STORE_SCOPE_DENIED, 'recharge uid mismatch');
        }
        if ($storeScope > 0) {
            if ((int)($order['store_id'] ?? 0) !== $storeScope
                || (int)($recharge['store_id'] ?? 0) !== $storeScope) {
                OrderTerminalError::throw(OrderTerminalError::STORE_SCOPE_DENIED);
            }
        }
    }

    protected function deductRechargeBalanceOnce(
        array $recharge,
        string $ben,
        string $give,
        string $opNo,
        int $opId,
        string $owner,
        int $epoch
    ): void {
        /** @var UserBalanceAtomicServices $balance */
        $balance = app()->make(UserBalanceAtomicServices::class);
        /** @var StoreOrderTerminalOperationServices $terminal */
        $terminal = app()->make(StoreOrderTerminalOperationServices::class);
        $idemKey = $opNo . ':void_recharge_balance_deduct';
        try {
            Db::transaction(function () use (
                $balance, $recharge, $ben, $give, $idemKey, $terminal, $opId, $owner, $epoch
            ) {
                $deduct = $balance->deductBenGive(
                    (int)$recharge['uid'],
                    $ben,
                    $give,
                    'user_recharge_void',
                    (int)$recharge['id'],
                    '充值作废扣回',
                    $idemKey
                );
                $n = $terminal->fencedUpdate($opId, $owner, $epoch, [
                    'before_ben' => $deduct['before']['ben'],
                    'before_give' => $deduct['before']['give'],
                    'before_total' => $deduct['before']['total'],
                    'after_ben' => $deduct['after']['ben'],
                    'after_give' => $deduct['after']['give'],
                    'after_total' => $deduct['after']['total'],
                    'balance_refund_done' => 1,
                    'refund_ben' => $ben,
                    'refund_give' => $give,
                    'refund_balance_total' => bcadd($ben, $give, 2),
                    'link_recharge_id' => (int)$recharge['id'],
                ]);
                if ($n <= 0) {
                    OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'void recharge balance fence lost');
                }
            });
        } catch (ValidateException $e) {
            // 不足时整笔拒绝，不部分扣回
            $msg = $e->getMessage();
            if (mb_strpos($msg, '本金不足') !== false
                || mb_strpos($msg, '赠金不足') !== false
                || mb_strpos($msg, '余额') !== false) {
                throw new ValidateException('当前会员本金或赠金不足，无法整笔作废该充值，请先核对后再操作。');
            }
            throw $e;
        }
    }

    /**
     * 充值已标作废时：按本单终态操作 + request_token + void_operation_no 决定观察者/恢复/拒绝。
     * 不得无条件把 terminal_action=2 当成终局拒绝。
     * token 等价规则与 beginOrResume 一致：双方皆空 / 双方非空且相等 = 同一次操作。
     */
    protected function assertRechargeVoidEntryAllowed(int $storeOrderId, array $recharge, string $requestToken): void
    {
        if ((int)($recharge['terminal_action'] ?? 0) !== StoreOrderTerminalOperation::ACTION_VOID) {
            return;
        }
        $voidOpNo = trim((string)($recharge['void_operation_no'] ?? ''));
        $existing = Db::name('store_order_terminal_operation')
            ->where('store_order_id', $storeOrderId)
            ->order('id', 'desc')
            ->find();
        if (!$existing) {
            throw new ValidateException('该充值记录已作废但订单终态不一致，请人工核对后再处理。');
        }
        $existing = is_array($existing) ? $existing : $existing->toArray();
        // 先严格校验归属与操作号，再比 token
        if ((int)($existing['store_order_id'] ?? 0) !== $storeOrderId) {
            throw new ValidateException('该充值记录已作废，与当前订单终态冲突，请人工核对后再处理。');
        }
        if ((int)($existing['action_type'] ?? 0) !== StoreOrderTerminalOperation::ACTION_VOID) {
            throw new ValidateException('该充值记录已作废，与当前订单终态冲突，请人工核对后再处理。');
        }
        $opNo = (string)($existing['operation_no'] ?? '');
        if ($voidOpNo === '' || $voidOpNo !== $opNo) {
            throw new ValidateException(
                $voidOpNo === ''
                    ? '该充值记录已作废但缺少操作号，请人工核对后再处理。'
                    : '该充值记录已被其他操作作废，请人工核对后再处理。'
            );
        }

        $existingTok = trim((string)($existing['request_token'] ?? ''));
        $requestTok = trim($requestToken);
        // 与 beginOrResume 一致：空串视为 null
        $existingNorm = $existingTok !== '' ? $existingTok : null;
        $requestNorm = $requestTok !== '' ? $requestTok : null;
        $sameToken = ($existingNorm === null && $requestNorm === null)
            || ($existingNorm !== null && $requestNorm !== null && $existingNorm === $requestNorm);

        $state = (int)($existing['state'] ?? -1);
        // 同一次操作：SUCCESS→观察者；处理中/可恢复→继续
        if ($sameToken) {
            return;
        }
        // 一空一非空、或两个非空但不同：冲突
        if ($state === StoreOrderTerminalOperation::STATE_SUCCESS) {
            OrderTerminalError::throw(OrderTerminalError::ALREADY_VOIDED);
        }
        OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'recharge void in progress');
    }

    /**
     * 取得执行权后：仅允许同 operation_no 的部分成功继续收口。
     */
    protected function assertRechargeVoidProgressAllowed(int $rechargeId, string $operationNo): void
    {
        $row = Db::name('user_recharge')->where('id', $rechargeId)->find();
        if (!$row) {
            OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND, 'recharge missing');
        }
        $row = is_array($row) ? $row : $row->toArray();
        if ((int)($row['terminal_action'] ?? 0) !== StoreOrderTerminalOperation::ACTION_VOID) {
            return;
        }
        $voidOpNo = trim((string)($row['void_operation_no'] ?? ''));
        if ($voidOpNo === '' || $voidOpNo !== $operationNo) {
            throw new ValidateException('该充值记录已被其他操作作废，请人工核对后再处理。');
        }
    }

    protected function applyRechargeVoidMarks(int $rechargeId, int $storeOrderId, string $opNo): void
    {
        $now = time();
        $row = Db::name('user_recharge')->where('id', $rechargeId)->lock(true)->find();
        if (!$row) {
            OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND, 'recharge missing');
        }
        $row = is_array($row) ? $row : $row->toArray();
        $ta = (int)($row['terminal_action'] ?? 0);
        $existingOp = trim((string)($row['void_operation_no'] ?? ''));
        if ($ta === StoreOrderTerminalOperation::ACTION_VOID) {
            // 同 operation_no 幂等重放；禁止把本次部分成功误判为另一笔作废
            if ($existingOp !== '' && $existingOp !== $opNo) {
                throw new ValidateException('该充值记录已被其他操作作废，请人工核对后再处理。');
            }
            if ($existingOp === '') {
                Db::name('user_recharge')->where('id', $rechargeId)->update([
                    'void_operation_no' => $opNo,
                    'voided_at' => (int)($row['voided_at'] ?? 0) > 0 ? (int)$row['voided_at'] : $now,
                ]);
            }
        } else {
            $n = Db::name('user_recharge')->where('id', $rechargeId)->where('terminal_action', 0)->update([
                'terminal_action' => StoreOrderTerminalOperation::ACTION_VOID,
                'void_operation_no' => $opNo,
                'voided_at' => $now,
            ]);
            if ($n <= 0) {
                $again = Db::name('user_recharge')->where('id', $rechargeId)->find();
                $again = is_array($again) ? $again : (array)$again;
                if ((int)($again['terminal_action'] ?? 0) === StoreOrderTerminalOperation::ACTION_VOID
                    && trim((string)($again['void_operation_no'] ?? '')) === $opNo) {
                    // 并发同号落库，视为幂等成功
                } else {
                    throw new ValidateException('该充值记录已被其他操作作废，请人工核对后再处理。');
                }
            }
        }
        Db::name('store_coupon_user')->where('use_time', 0)->where('oid', $storeOrderId)->where('type', 'recharge_get')->delete();
        Db::name('staff_yeji')->where('link_id', $rechargeId)->where('type', 1)->update(['status' => 1]);
    }

    protected function finalizeRechargeVoidLocal(
        int $rechargeId,
        int $storeOrderId,
        int $opId,
        string $opNo,
        string $owner,
        int $epoch,
        string $refundBen,
        string $refundGive,
        bool $enqueueFinance
    ): void {
        /** @var StoreOrderTerminalOperationServices $terminal */
        $terminal = app()->make(StoreOrderTerminalOperationServices::class);
        /** @var RefundSideEffectOutboxServices $outbox */
        $outbox = app()->make(RefundSideEffectOutboxServices::class);
        $order = Db::name('store_order')->where('id', $storeOrderId)->find();
        $order = is_array($order) ? $order : (array)$order;

        Db::transaction(function () use (
            $rechargeId, $storeOrderId, $opId, $opNo, $owner, $epoch, $terminal, $outbox,
            $order, $refundBen, $refundGive, $enqueueFinance
        ) {
            Db::name('store_order')->where('id', $storeOrderId)->lock(true)->find();
            $opLocked = Db::name('store_order_terminal_operation')->where('id', $opId)->lock(true)->find();
            if (!$opLocked
                || (string)($opLocked['execution_owner'] ?? '') !== $owner
                || (int)($opLocked['execution_epoch'] ?? 0) !== $epoch) {
                OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'void recharge local fence lost');
            }
            if ((int)$opLocked['local_close_done'] === 1) {
                return;
            }

            $this->applyRechargeVoidMarks($rechargeId, $storeOrderId, $opNo);

            if ($enqueueFinance) {
                $this->enqueueVoidFinanceOutboxInTx($outbox, $order, $opNo, $opId, '充值作废');
            }

            $n = $terminal->fencedUpdate($opId, $owner, $epoch, [
                'local_close_done' => 1,
                'balance_refund_done' => 1,
                'link_recharge_id' => $rechargeId,
                'refund_ben' => $refundBen,
                'refund_give' => $refundGive,
                'refund_balance_total' => bcadd($refundBen, $refundGive, 2),
                'external_refund_done' => 1,
                'external_refund_state' => 0,
                'refund_event_done' => $enqueueFinance ? 1 : 0,
            ]);
            if ($n <= 0) {
                OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'void recharge local update lost');
            }
        });

        if ($enqueueFinance) {
            $outbox->flushPending($opNo);
        }
    }

    protected function ensureVoidFinanceOutbox(
        RefundSideEffectOutboxServices $outbox,
        array $order,
        string $opNo,
        int $opId,
        string $reason
    ): void {
        $pending = (int)Db::name('refund_side_effect_outbox')
            ->where('operation_no', $opNo)
            ->whereIn('step_type', [
                RefundSideEffectOutboxServices::STEP_VOID_STORE_FINANCE,
                RefundSideEffectOutboxServices::STEP_VOID_STAFF_FINANCE,
                RefundSideEffectOutboxServices::STEP_VOID_CAPITAL_FLOW,
            ])
            ->count();
        if ($pending > 0) {
            return;
        }
        Db::transaction(function () use ($outbox, $order, $opNo, $opId, $reason) {
            $this->enqueueVoidFinanceOutboxInTx($outbox, $order, $opNo, $opId, $reason);
        });
    }

    /**
     * 普通单余额快照闭合校验（阶段2同口径）
     */
    public function assertSalesBalanceSnapshotClosed(array $order): void
    {
        $yue = bcadd((string)($order['yue_pay_price'] ?? '0'), '0', 2);
        $ben = bcadd((string)($order['paid_ben_amount'] ?? '0'), '0', 2);
        $give = bcadd((string)($order['paid_give_amount'] ?? '0'), '0', 2);
        $pay = bcadd((string)($order['pay_price'] ?? '0'), '0', 2);

        if (bccomp($yue, '0', 2) === 0) {
            if (bccomp($ben, '0', 2) !== 0 || bccomp($give, '0', 2) !== 0) {
                OrderTerminalError::throw(OrderTerminalError::BALANCE_SOURCE_UNKNOWN);
            }
            return;
        }
        if ((int)($order['paid_balance_ready'] ?? 0) !== 1) {
            OrderTerminalError::throw(OrderTerminalError::BALANCE_SOURCE_UNKNOWN);
        }
        if (bccomp(bcadd($ben, $give, 2), $yue, 2) !== 0) {
            OrderTerminalError::throw(OrderTerminalError::BALANCE_SOURCE_UNKNOWN);
        }
        if (bccomp($yue, $pay, 2) > 0) {
            OrderTerminalError::throw(OrderTerminalError::BALANCE_SOURCE_UNKNOWN);
        }
    }

    protected function creditSalesBalanceIfNeeded(array $order, string $opNo, int $opId, string $owner, int $epoch): void
    {
        $yue = bcadd((string)($order['yue_pay_price'] ?? '0'), '0', 2);
        $ben = bcadd((string)($order['paid_ben_amount'] ?? '0'), '0', 2);
        $give = bcadd((string)($order['paid_give_amount'] ?? '0'), '0', 2);
        /** @var StoreOrderTerminalOperationServices $terminal */
        $terminal = app()->make(StoreOrderTerminalOperationServices::class);
        if (bccomp($yue, '0', 2) <= 0) {
            $terminal->fencedUpdate($opId, $owner, $epoch, ['balance_refund_done' => 1]);
            return;
        }

        /** @var UserBalanceAtomicServices $balance */
        $balance = app()->make(UserBalanceAtomicServices::class);
        $idemKey = $opNo . ':void_balance_credit';
        Db::transaction(function () use ($balance, $order, $ben, $give, $idemKey, $opId, $owner, $epoch, $terminal) {
            $credit = $balance->creditBenGive(
                (int)$order['uid'],
                $ben,
                $give,
                'order_void',
                (int)$order['id'],
                '订单作废冲正',
                $idemKey
            );
            $n = $terminal->fencedUpdate($opId, $owner, $epoch, [
                'before_ben' => $credit['before']['ben'],
                'before_give' => $credit['before']['give'],
                'before_total' => $credit['before']['total'],
                'after_ben' => $credit['after']['ben'],
                'after_give' => $credit['after']['give'],
                'after_total' => $credit['after']['total'],
                'balance_refund_done' => 1,
                'refund_ben' => $ben,
                'refund_give' => $give,
                'refund_balance_total' => bcadd($ben, $give, 2),
            ]);
            if ($n <= 0) {
                $hold = Db::name('store_order_terminal_operation')->where('id', $opId)->find();
                if (!$hold
                    || (string)($hold['execution_owner'] ?? '') !== $owner
                    || (int)($hold['execution_epoch'] ?? 0) !== $epoch) {
                    OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'void balance fence lost');
                }
            }
        });
    }

    protected function enqueueVoidFinanceOutboxInTx(
        RefundSideEffectOutboxServices $outbox,
        array $order,
        string $opNo,
        int $opId,
        string $reason
    ): void {
        $price = (float)($order['pay_price'] ?? 0);
        $orderPayload = array_merge($order, [
            'terminal_operation_no' => $opNo,
            'terminal_operation_id' => $opId,
            'terminal_store_finance_key' => $opNo . ':store_finance_void',
            'terminal_staff_finance_key' => $opNo . ':staff_finance_void',
            'terminal_capital_flow_key' => $opNo . ':capital_flow_void',
            'void_reason' => $reason,
            'refund_price' => $order['pay_price'] ?? 0,
            'price' => $order['pay_price'] ?? 0,
        ]);
        $base = [
            'operation_no' => $opNo,
            'terminal_operation_id' => $opId,
            'order' => $orderPayload,
            'data' => [
                'operation_no' => $opNo,
                'refund_price' => $price,
                'void_finance' => 1,
            ],
        ];
        $outbox->enqueueInTx($opNo, RefundSideEffectOutboxServices::STEP_VOID_STORE_FINANCE, $base);
        $outbox->enqueueInTx($opNo, RefundSideEffectOutboxServices::STEP_VOID_STAFF_FINANCE, $base);
        $outbox->enqueueInTx($opNo, RefundSideEffectOutboxServices::STEP_VOID_CAPITAL_FLOW, $base);
    }

    protected function normalizeReason(array $input): string
    {
        $reason = trim((string)($input['reason'] ?? $input['void_reason'] ?? ''));
        return $reason !== '' ? $reason : '整单作废';
    }

    protected function observerResult(array $op, array $order): array
    {
        $state = (int)($op['state'] ?? -1);
        $msg = '作废处理中或已完成，请刷新查看结果。';
        if ($state === StoreOrderTerminalOperation::STATE_SUCCESS
            || (int)($order['terminal_action'] ?? 0) === StoreOrderTerminalOperation::ACTION_VOID) {
            $msg = '该订单已作废，请刷新查看。';
        } elseif ((int)($order['terminal_action'] ?? 0) === StoreOrderTerminalOperation::ACTION_REFUND
            || (int)($order['refund_status'] ?? 0) === 2) {
            $msg = OrderTerminalError::message(OrderTerminalError::ALREADY_REFUNDED);
        }
        $isRecharge = (int)($order['order_type'] ?? 0) === 1;
        $isDebtRepay = !empty($order['is_debt_repay']);
        return [
            'ok' => true,
            'should_execute' => false,
            'execution_acquired' => false,
            'idempotent_observer' => true,
            'store_order_id' => (int)$order['id'],
            'operation_id' => (int)($op['id'] ?? 0),
            'operation_no' => (string)($op['operation_no'] ?? ''),
            'state' => $state,
            'action_type' => StoreOrderTerminalOperation::ACTION_VOID,
            'can_reopen' => $state === StoreOrderTerminalOperation::STATE_SUCCESS && !$isRecharge && !$isDebtRepay,
            'message' => $msg,
        ];
    }
}
