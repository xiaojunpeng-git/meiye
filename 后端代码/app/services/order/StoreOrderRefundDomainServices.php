<?php
declare(strict_types=1);

namespace app\services\order;

use app\model\order\StoreOrderTerminalOperation;
use app\services\BaseServices;
use app\services\order\terminal\OrderTerminalError;
use app\services\pay\PayServices;
use app\services\user\UserBalanceAtomicServices;
use app\services\user\UserRechargeServices;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\Log;

/**
 * 阶段 2：整单退款领域编排（修复：本金来源/金额闭合/组合拆分/渠道半成功）
 */
class StoreOrderRefundDomainServices extends BaseServices
{
    public function refundWholeOrder(array $input): array
    {
        /** @var StoreOrderTerminalOperationServices $terminal */
        $terminal = app()->make(StoreOrderTerminalOperationServices::class);
        $terminal->assertWholeOrderOnly($input);

        $order = $this->loadSalesOrder((int)($input['store_order_id'] ?? 0));
        $money = $this->resolveAndValidateRefundMoney($order, $input, true);
        $plan = $this->buildPaymentPlan($order, $money, $input);
        $money['refund_ben'] = $plan['refund_ben'];
        $money['refund_give'] = $plan['refund_give'];
        $money['balance_total'] = $plan['balance_total'];
        if (!empty($plan['park_need_manual'])) {
            return $this->parkBookkeepingNeedManual($order, $money, $plan, $input, 0);
        }

        return $this->executeWholeRefund($order, $money, $plan, $input, 0);
    }

    public function agreeAfterSaleRefund(int $refundId, array $input): array
    {
        $refundRow = Db::name('store_order_refund')->where('id', $refundId)->find();
        if (!$refundRow) {
            OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND, 'refund missing');
        }
        // 累计多次退款：已退完或订单已退款直接拒绝
        if (bccomp((string)($refundRow['refunded_price'] ?? '0'), (string)($refundRow['refund_price'] ?? '0'), 2) >= 0
            && bccomp((string)($refundRow['refund_price'] ?? '0'), '0', 2) > 0
            && (int)$refundRow['refund_type'] === 6) {
            OrderTerminalError::throw(OrderTerminalError::ALREADY_REFUNDED);
        }
        $input['store_order_id'] = (int)$refundRow['store_order_id'];
        if (!array_key_exists('refund_amount', $input) && !array_key_exists('refund_price', $input)) {
            $input['refund_amount'] = $refundRow['refund_price'] ?? '0';
            $input['refund_amount_provided'] = true;
        }
        $order = $this->loadSalesOrder((int)$input['store_order_id']);
        /** @var StoreOrderTerminalOperationServices $terminal */
        $terminal = app()->make(StoreOrderTerminalOperationServices::class);
        $terminal->assertWholeOrderOnly($input);
        $money = $this->resolveAndValidateRefundMoney($order, $input, true);
        $plan = $this->buildPaymentPlan($order, $money, $input);
        $money['refund_ben'] = $plan['refund_ben'];
        $money['refund_give'] = $plan['refund_give'];
        $money['balance_total'] = $plan['balance_total'];
        if (!empty($plan['park_need_manual'])) {
            return $this->parkBookkeepingNeedManual($order, $money, $plan, $input, $refundId);
        }
        return $this->executeWholeRefund($order, $money, $plan, $input, $refundId);
    }

    /**
     * 自动任务遇记账收款：落终态 NEED_MANUAL，不执行资金副作用
     */
    protected function parkBookkeepingNeedManual(array $order, array $money, array $plan, array $input, int $existingRefundId): array
    {
        /** @var StoreOrderTerminalOperationServices $terminal */
        $terminal = app()->make(StoreOrderTerminalOperationServices::class);
        $businessDate = $terminal->normalizeRefundBusinessDate(
            isset($input['refund_business_date']) ? (string)$input['refund_business_date'] : null,
            (int)($order['pay_time'] ?? 0)
        );
        $beginCtx = [
            'store_order_id' => (int)$order['id'],
            'action_type' => StoreOrderTerminalOperation::ACTION_REFUND,
            'business_type' => StoreOrderTerminalOperation::BUSINESS_ORDER,
            'source_type' => (int)($input['source_type'] ?? StoreOrderTerminalOperation::SOURCE_ADMIN),
            'operator_type' => (string)($input['operator_type'] ?? 'system'),
            'operator_id' => (int)($input['operator_id'] ?? 0),
            'store_scope' => (int)($input['store_scope'] ?? 0),
            'request_token' => (string)($input['request_token'] ?? ''),
            'refund_business_date' => $businessDate,
            'refund_amount' => $money['refund_amount'],
            'refund_ben' => $money['refund_ben'],
            'refund_give' => $money['refund_give'],
            'bookkeeping_confirmed' => 0,
            'bookkeeping_remark' => '',
            'link_refund_id' => $existingRefundId,
            'reason' => (string)($input['refund_reason'] ?? '记账收款待人工确认'),
        ];
        if (trim((string)$beginCtx['request_token']) === '') {
            unset($beginCtx['request_token']);
        }
        $execOwner = trim((string)($input['execution_owner'] ?? '')) ?: $terminal->makeExecutionOwner();
        $beginCtx['execution_owner'] = $execOwner;
        $begin = $terminal->beginOrResume($beginCtx);
        if (!$terminal->shouldExecute($begin)) {
            return $this->observerResult($begin, $order);
        }
        $opId = (int)$begin['id'];
        $epoch = (int)($begin['execution_epoch'] ?? 0);
        if ($existingRefundId > 0) {
            $terminal->markRefundAsWholeOrderRequest($existingRefundId);
            $terminal->fencedUpdate($opId, $execOwner, $epoch, [
                'link_refund_id' => $existingRefundId,
            ]);
        }
        if ((int)($begin['state'] ?? -1) !== StoreOrderTerminalOperation::STATE_NEED_MANUAL) {
            try {
                $terminal->markNeedManual($opId, OrderTerminalError::BOOKKEEPING_NEED_MANUAL, 'auto bookkeeping park', $execOwner, $epoch);
            } catch (\Throwable $e) {
                // 若已在 NEED_MANUAL，忽略
            }
        }
        $fresh = Db::name('store_order_terminal_operation')->where('id', $opId)->find();
        return [
            'ok' => false,
            'need_manual' => true,
            'park_need_manual' => true,
            'should_execute' => false,
            'execution_acquired' => false,
            'idempotent_observer' => false,
            'store_order_id' => (int)$order['id'],
            'refund_id' => $existingRefundId,
            'operation_id' => $opId,
            'operation_no' => (string)($fresh['operation_no'] ?? $begin['operation_no'] ?? ''),
            'state' => StoreOrderTerminalOperation::STATE_NEED_MANUAL,
            'message' => OrderTerminalError::message(OrderTerminalError::BOOKKEEPING_NEED_MANUAL),
        ];
    }

    public function applyMobileWholeOrder(int $storeOrderId, int $uid, array $input): array
    {
        /** @var StoreOrderTerminalOperationServices $terminal */
        $terminal = app()->make(StoreOrderTerminalOperationServices::class);
        $terminal->assertWholeOrderOnly($input);

        /** @var StoreOrderRefundServices $refundServices */
        $refundServices = app()->make(StoreOrderRefundServices::class);

        $refundDataMeta = [
            'refund_reason' => (string)($input['text'] ?? $input['refund_reason'] ?? ''),
            'refund_explain' => (string)($input['refund_reason_wap_explain'] ?? $input['refund_explain'] ?? ''),
            'refund_img' => $input['refund_img'] ?? json_encode([]),
        ];
        if (is_array($input['refund_reason_wap_img'] ?? null)) {
            $refundDataMeta['refund_img'] = json_encode($input['refund_reason_wap_img']);
        } elseif (!empty($input['refund_reason_wap_img'])) {
            $refundDataMeta['refund_img'] = json_encode(explode(',', (string)$input['refund_reason_wap_img']));
        }

        $applyType = (int)($input['refund_type'] ?? 1);
        $afterCommit = null;

        // 方案 A：锁单 + 校验 + 活动申请检查 + 落库 + request_scope 同一事务；副作用仅在提交后执行
        Db::transaction(function () use (
            $storeOrderId,
            $uid,
            $terminal,
            $refundServices,
            $refundDataMeta,
            $applyType,
            &$afterCommit
        ) {
            $locked = Db::name('store_order')->where('id', $storeOrderId)->lock(true)->find();
            if (!$locked) {
                OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND);
            }
            $order = is_array($locked) ? $locked : $locked->toArray();
            if ((int)($order['order_type'] ?? 0) === 2) {
                OrderTerminalError::throw(OrderTerminalError::WRITEOFF_SUB_ORDER);
            }
            if ((int)$order['uid'] !== $uid) {
                OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND, 'uid mismatch');
            }
            if ((int)($order['paid'] ?? 0) !== 1) {
                OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_PAID);
            }
            if ((int)($order['refund_status'] ?? 0) === 2
                || (int)($order['terminal_action'] ?? 0) === StoreOrderTerminalOperation::ACTION_REFUND) {
                OrderTerminalError::throw(OrderTerminalError::ALREADY_REFUNDED);
            }
            $this->assertMobileWriteoffGate((int)$order['id']);

            // 金额唯一取锁定订单 pay_price，不信任客户端
            $refundAmount = $terminal->parseMoneyField($order['pay_price'] ?? '0', 'refund_amount');
            $payPrice = bcadd((string)($order['pay_price'] ?? '0'), '0', 2);
            if (bccomp($refundAmount, $payPrice, 2) !== 0) {
                OrderTerminalError::throw(OrderTerminalError::REFUND_AMOUNT_EXCEED);
            }

            $refundServices->assertNoActiveRefundApply((int)$order['id']);

            $payload = $refundServices->buildApplyRefundPersistPayload(
                (int)$order['id'],
                $uid,
                $order,
                [],
                $applyType,
                (float)$refundAmount,
                $refundDataMeta,
                0
            );
            // 再次强制实付口径（防止组装过程偏离）
            $payload['refundData']['refund_price'] = $payPrice;

            $refundId = $refundServices->persistApplyRefundInTransaction((int)$order['id'], $payload);
            $terminal->markRefundAsWholeOrderRequest($refundId);

            $afterCommit = [
                'order' => $payload['order'],
                'refund_id' => $refundId,
                'refund_data' => $payload['refundData'],
                'refund_amount' => $payPrice,
            ];
        });

        if (!is_array($afterCommit) || (int)($afterCommit['refund_id'] ?? 0) <= 0) {
            OrderTerminalError::throw(OrderTerminalError::GENERIC_FAIL, 'mobile refund apply failed');
        }

        $refundServices->dispatchApplyRefundAfterCommit(
            $afterCommit['order'],
            (int)$afterCommit['refund_id'],
            $afterCommit['refund_data'],
            true
        );

        return [
            'ok' => true,
            'refund_id' => (int)$afterCommit['refund_id'],
            'store_order_id' => (int)$afterCommit['order']['id'],
            'refund_price' => $afterCommit['refund_amount'],
            'message' => '提交申请成功',
        ];
    }

    public function refundRecharge(int $rechargeId, array $input): array
    {
        // 保持原充值路径，但禁止拆单参数
        /** @var StoreOrderTerminalOperationServices $terminal */
        $terminal = app()->make(StoreOrderTerminalOperationServices::class);
        $terminal->assertWholeOrderOnly($input);

        /** @var UserRechargeServices $rechargeServices */
        $rechargeServices = app()->make(UserRechargeServices::class);
        $recharge = $rechargeServices->getRecharge($rechargeId);
        if (!$recharge) {
            OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND, 'recharge missing');
        }
        $recharge = is_array($recharge) ? $recharge : $recharge->toArray();
        if (($recharge['recharge_type'] ?? '') === 'balance') {
            throw new ValidateException('佣金转入余额，不能退款');
        }
        if (bccomp((string)($recharge['price'] ?? '0'), (string)($recharge['refund_price'] ?? '0'), 2) === 0
            && bccomp((string)($recharge['refund_price'] ?? '0'), '0', 2) > 0) {
            OrderTerminalError::throw(OrderTerminalError::RECHARGE_ALREADY_REFUNDED);
        }

        $orderId = (int)Db::name('store_order')->where('link_id', $rechargeId)->where('order_type', 1)->value('id');
        if ($orderId <= 0) {
            OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND, 'recharge order missing');
        }
        $order = $this->loadSalesOrder($orderId);

        $refundBen = $terminal->parseMoneyField($input['price'] ?? $input['refund_ben'] ?? null, 'refund_ben');
        $refundGive = $terminal->parseMoneyField($input['give_price'] ?? $input['refund_give'] ?? null, 'refund_give');
        if (bccomp($refundBen, '0', 2) === 0 && bccomp($refundGive, '0', 2) === 0) {
            $refundBen = $terminal->parseMoneyField($recharge['price'] ?? '0', 'refund_ben');
        }
        $refundTotal = bcadd($refundBen, $refundGive, 2);
        if (bccomp($refundBen, (string)$recharge['price'], 2) > 0) {
            OrderTerminalError::throw(OrderTerminalError::ORIGIN_BEN_EXCEED);
        }
        if (bccomp($refundGive, (string)($recharge['give_price'] ?? '0'), 2) > 0) {
            OrderTerminalError::throw(OrderTerminalError::ORIGIN_GIVE_EXCEED);
        }

        $businessDate = $terminal->normalizeRefundBusinessDate(
            isset($input['refund_business_date']) ? (string)$input['refund_business_date'] : null,
            (int)($order['pay_time'] ?? $recharge['pay_time'] ?? 0)
        );

        $beginCtx = [
            'store_order_id' => $orderId,
            'action_type' => StoreOrderTerminalOperation::ACTION_REFUND,
            'business_type' => StoreOrderTerminalOperation::BUSINESS_RECHARGE,
            'source_type' => (int)($input['source_type'] ?? StoreOrderTerminalOperation::SOURCE_STORE),
            'operator_type' => (string)($input['operator_type'] ?? 'store'),
            'operator_id' => (int)($input['operator_id'] ?? 0),
            'store_scope' => (int)($input['store_scope'] ?? 0),
            'request_token' => (string)($input['request_token'] ?? ''),
            'refund_business_date' => $businessDate,
            'refund_amount' => $refundTotal,
            'refund_ben' => $refundBen,
            'refund_give' => $refundGive,
            'origin_ben_limit' => (string)$recharge['price'],
            'origin_give_limit' => (string)($recharge['give_price'] ?? '0'),
            'link_recharge_id' => $rechargeId,
            'reason' => (string)($input['refund_reason'] ?? ''),
        ];
        if (trim((string)$beginCtx['request_token']) === '') {
            unset($beginCtx['request_token']);
        }
        $execOwner = trim((string)($input['execution_owner'] ?? '')) ?: $terminal->makeExecutionOwner();
        $beginCtx['execution_owner'] = $execOwner;

        $begin = $terminal->beginOrResume($beginCtx);
        if (!$terminal->shouldExecute($begin)) {
            return $this->observerResult($begin, $order);
        }
        /** @var \app\services\order\terminal\RefundOutboxRelayGateServices $relayGate */
        $relayGate = app()->make(\app\services\order\terminal\RefundOutboxRelayGateServices::class);
        $relayGate->assertReadyForTerminalRefund();

        $opId = (int)$begin['id'];
        $opNo = (string)$begin['operation_no'];
        $epoch = (int)($begin['execution_epoch'] ?? 0);
        $extNo = (string)($begin['external_refund_no'] ?: $opNo);
        $injectFail = (string)($input['inject_fail_after'] ?? '');
        $balanceDone = (int)($begin['balance_refund_done'] ?? 0) === 1;
        $channelDone = (int)($begin['external_refund_state'] ?? 0) === 2
            || (int)($begin['external_refund_done'] ?? 0) === 1;
        $localDone = (int)($begin['local_close_done'] ?? 0) === 1;
        $stateNow = (int)($begin['state'] ?? 0);

        // CHANNEL_PENDING 且渠道结果未确认：转人工，禁止盲目重打
        if ($stateNow === StoreOrderTerminalOperation::STATE_CHANNEL_PENDING && !$channelDone) {
            $terminal->markNeedManual($opId, OrderTerminalError::CHANNEL_DONE_LOCAL_FAIL, 'recharge channel uncertain on recover', $execOwner, $epoch);
            OrderTerminalError::throw(OrderTerminalError::CHANNEL_DONE_LOCAL_FAIL, 'recharge channel uncertain');
        }

        try {
            if (!$channelDone) {
                if (!$balanceDone) {
                    $step = $terminal->transition($opId, StoreOrderTerminalOperation::STATE_BALANCE_PENDING, [], $execOwner, $epoch);
                    if (!$terminal->shouldExecute($step)) {
                        return $this->observerResult($step, $order);
                    }
                    $terminal->renewExecutionLease($opId, $execOwner, $epoch);
                    $balance = app()->make(UserBalanceAtomicServices::class);
                    $idemKey = $opNo . ':recharge_balance_deduct';
                    // 余额扣回流水 + 用户余额 + balance_refund_done 同事务
                    Db::transaction(function () use (
                        $balance, $recharge, $refundBen, $refundGive, $rechargeId, $idemKey,
                        $terminal, $opId, $execOwner, $epoch
                    ) {
                        $deduct = $balance->deductBenGive(
                            (int)$recharge['uid'],
                            $refundBen,
                            $refundGive,
                            'user_recharge_refund',
                            $rechargeId,
                            '充值退款',
                            $idemKey
                        );
                        $n = $terminal->fencedUpdate($opId, $execOwner, $epoch, [
                            'before_ben' => $deduct['before']['ben'],
                            'before_give' => $deduct['before']['give'],
                            'before_total' => $deduct['before']['total'],
                            'after_ben' => $deduct['after']['ben'],
                            'after_give' => $deduct['after']['give'],
                            'after_total' => $deduct['after']['total'],
                            'balance_refund_done' => 1,
                        ]);
                        if ($n <= 0) {
                            OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'recharge balance fence lost');
                        }
                    });
                    $balanceDone = true;
                }

                if ($injectFail === 'hard_exit_after_balance_before_channel') {
                    fwrite(STDERR, "HARD_EXIT_AFTER_BALANCE_BEFORE_CHANNEL op_no={$opNo}\n");
                    exit(99);
                }

                $channelStep = $terminal->transition($opId, StoreOrderTerminalOperation::STATE_CHANNEL_PENDING, [], $execOwner, $epoch);
                if (!$terminal->shouldExecute($channelStep)) {
                    $terminal->markNeedManual($opId, OrderTerminalError::GENERIC_FAIL, 'balance deducted channel cas lost', $execOwner, $epoch);
                    OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'channel cas lost');
                }
                $this->callRechargeChannelRefund($recharge, $refundBen, $extNo);
                $n = $terminal->fencedUpdate($opId, $execOwner, $epoch, [
                    'external_refund_state' => 2,
                    'external_refund_done' => 1,
                    'external_refund_amount' => $refundBen,
                    'external_refund_no' => $extNo,
                    'channel_finished_at' => time(),
                ]);
                if ($n <= 0) {
                    OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'recharge channel done fence lost');
                }
                $channelDone = true;
            }

            if (!$localDone) {
                $local = $terminal->transition($opId, StoreOrderTerminalOperation::STATE_LOCAL_CLOSING, [], $execOwner, $epoch);
                if (!$terminal->shouldExecute($local) && (int)$local['state'] !== StoreOrderTerminalOperation::STATE_LOCAL_CLOSING) {
                    return $this->observerResult($local, $order);
                }
                $terminal->renewExecutionLease($opId, $execOwner, $epoch);

                if ($injectFail === 'hard_exit_after_local_before_finalize') {
                    fwrite(STDERR, "HARD_EXIT_AFTER_LOCAL_BEFORE_FINALIZE op_no={$opNo}\n");
                    exit(99);
                }

                /** @var \app\services\order\terminal\RefundSideEffectOutboxServices $outbox */
                $outbox = app()->make(\app\services\order\terminal\RefundSideEffectOutboxServices::class);
                $rechargeArr = is_array($recharge) ? $recharge : (array)$recharge;
                $rechargeArr['terminal_operation_no'] = $opNo;
                $rechargeArr['terminal_operation_id'] = $opId;
                $rechargeArr['terminal_store_finance_key'] = $opNo . ':store_finance';
                $rechargeArr['terminal_staff_finance_key'] = $opNo . ':staff_finance';
                $rechargeArr['terminal_capital_flow_key'] = $opNo . ':capital_flow';
                $rechargeArr['terminal_refund_notice_key'] = $opNo . ':refund_notice';
                $rechargeArr['recharge_refund'] = 1;

                Db::transaction(function () use (
                    $rechargeId, $refundTotal, $refundBen, $refundGive, $orderId, $opId, $opNo,
                    $outbox, $rechargeArr, $terminal, $execOwner, $epoch, $extNo
                ) {
                    Db::name('store_order')->where('id', $orderId)->lock(true)->find();
                    $opLocked = Db::name('store_order_terminal_operation')->where('id', $opId)->lock(true)->find();
                    if (!$opLocked
                        || (string)($opLocked['execution_owner'] ?? '') !== $execOwner
                        || (int)($opLocked['execution_epoch'] ?? 0) !== $epoch) {
                        OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'recharge finalize fence lost');
                    }
                    if ((int)$opLocked['state'] === StoreOrderTerminalOperation::STATE_SUCCESS
                        && (int)($opLocked['local_close_done'] ?? 0) === 1) {
                        return;
                    }

                    Db::name('user_recharge')->where('id', $rechargeId)->update([
                        'refund_price' => $refundTotal,
                        'refund_ben' => $refundBen,
                        'refund_give' => $refundGive,
                    ]);
                    Db::name('store_coupon_user')->where('use_time', 0)->where('oid', $orderId)->where('type', 'recharge_get')->delete();
                    Db::name('staff_yeji')->where('link_id', $rechargeId)->where('type', 1)->update(['status' => 1]);

                    $outbox->enqueueInTx($opNo, \app\services\order\terminal\RefundSideEffectOutboxServices::STEP_RECHARGE_REFUND_EVENT, [
                        'operation_no' => $opNo,
                        'terminal_operation_id' => $opId,
                        'data' => [
                            'refund_price' => $refundTotal,
                            'refund_ben' => $refundBen,
                            'refund_give' => $refundGive,
                            'operation_no' => $opNo,
                            'recharge_refund' => 1,
                        ],
                        'order' => $rechargeArr,
                    ]);
                    $now = time();
                    $n = $terminal->fencedUpdate($opId, $execOwner, $epoch, [
                        'refund_event_done' => 1,
                        'local_close_done' => 1,
                        'balance_refund_done' => 1,
                        'link_recharge_id' => $rechargeId,
                        'external_refund_state' => 2,
                        'external_refund_done' => 1,
                        'external_refund_no' => $extNo,
                        'refund_amount' => $refundTotal,
                        'refund_ben' => $refundBen,
                        'refund_give' => $refundGive,
                        'refund_balance_total' => $refundTotal,
                        'state' => StoreOrderTerminalOperation::STATE_SUCCESS,
                        'success_at' => $now,
                        'execution_owner' => '',
                        'lease_until' => 0,
                    ]);
                    if ($n <= 0) {
                        OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'recharge success fence lost');
                    }
                    Db::name('store_order')->where('id', $orderId)->where('terminal_action', 0)->update([
                        'refund_status' => 2,
                        'refund_type' => 6,
                        'terminal_action' => StoreOrderTerminalOperation::ACTION_REFUND,
                        'terminal_operation_id' => $opId,
                        'terminal_action_time' => $now,
                    ]);
                });
                $outbox->flushPending($opNo);
            } else {
                /** @var \app\services\order\terminal\RefundSideEffectOutboxServices $outboxFlush */
                $outboxFlush = app()->make(\app\services\order\terminal\RefundSideEffectOutboxServices::class);
                $outboxFlush->flushPending($opNo);
                if ((int)Db::name('store_order_terminal_operation')->where('id', $opId)->value('state')
                    !== StoreOrderTerminalOperation::STATE_SUCCESS) {
                    $terminal->markSuccess($opId, [
                        'link_recharge_id' => $rechargeId,
                        'local_close_done' => 1,
                    ], $execOwner, $epoch);
                }
            }
            return [
                'ok' => true,
                'should_execute' => true,
                'execution_acquired' => true,
                'idempotent_observer' => false,
                'recharge_id' => $rechargeId,
                'store_order_id' => $orderId,
                'operation_id' => $opId,
                'operation_no' => $opNo,
                'state' => StoreOrderTerminalOperation::STATE_SUCCESS,
                'message' => '退款成功',
            ];
        } catch (\Throwable $e) {
            Log::warning('[recharge_refund_fail] op=' . $opId . ' ' . $e->getMessage());
            if ($channelDone) {
                try {
                    $terminal->markNeedManual($opId, OrderTerminalError::CHANNEL_DONE_LOCAL_FAIL, $e->getMessage(), $execOwner, $epoch);
                } catch (\Throwable $ignore) {
                }
                OrderTerminalError::throw(OrderTerminalError::CHANNEL_DONE_LOCAL_FAIL, $e->getMessage());
            }
            // 渠道未确认成功：禁止自动补偿后盲目重打；可重试失败则 FAILED_RETRYABLE
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

    /**
     * 解析并校验退款金额/本金/赠金（整单与售后共用；含锁行后当前余额上限）
     *
     * @return array{refund_amount:string,refund_ben:string,refund_give:string,balance_total:string,ben_provided:bool,give_provided:bool}
     */
    public function resolveAndValidateRefundMoney(array $order, array $input, bool $lockUser = true): array
    {
        /** @var StoreOrderTerminalOperationServices $terminal */
        $terminal = app()->make(StoreOrderTerminalOperationServices::class);

        $amountRaw = $input['refund_amount'] ?? null;
        if ($amountRaw === null && array_key_exists('refund_price', $input)) {
            $amountRaw = $input['refund_price'];
        }
        $amountProvided = !empty($input['refund_amount_provided'])
            || array_key_exists('refund_amount', $input)
            || array_key_exists('refund_price', $input);

        if (!$amountProvided || $amountRaw === null || $amountRaw === '') {
            $refundAmount = $terminal->parseMoneyField($order['pay_price'] ?? '0', 'refund_amount');
        } else {
            $refundAmount = $terminal->parseMoneyField($amountRaw, 'refund_amount');
        }

        $benProvided = array_key_exists('refund_ben', $input) && $input['refund_ben'] !== null && $input['refund_ben'] !== '';
        $giveProvided = array_key_exists('refund_give', $input) && $input['refund_give'] !== null && $input['refund_give'] !== '';
        $refundBen = $benProvided ? $terminal->parseMoneyField($input['refund_ben'], 'refund_ben') : '0.00';
        $refundGive = $giveProvided ? $terminal->parseMoneyField($input['refund_give'], 'refund_give') : '0.00';

        $payPrice = bcadd((string)($order['pay_price'] ?? '0'), '0', 2);
        if (bccomp($payPrice, '0', 2) === 0) {
            if (bccomp($refundAmount, '0', 2) > 0 || bccomp(bcadd($refundBen, $refundGive, 2), '0', 2) > 0) {
                OrderTerminalError::throw(OrderTerminalError::REFUND_AMOUNT_EXCEED);
            }
        } elseif (bccomp($refundAmount, $payPrice, 2) > 0) {
            OrderTerminalError::throw(OrderTerminalError::REFUND_AMOUNT_EXCEED);
        }

        $yueCap = bcadd((string)($order['yue_pay_price'] ?? '0'), '0', 2);
        $usedBalance = bccomp($yueCap, '0', 2) > 0
            || in_array((string)($order['pay_type'] ?? ''), [PayServices::YUE_PAY], true);

        if (!$usedBalance) {
            if (bccomp($refundBen, '0', 2) > 0 || bccomp($refundGive, '0', 2) > 0) {
                OrderTerminalError::throw(OrderTerminalError::BALANCE_NOT_ON_ORDER);
            }
            $balanceTotal = '0.00';
        } else {
            if ((int)($order['paid_balance_ready'] ?? 0) !== 1) {
                OrderTerminalError::throw(OrderTerminalError::BALANCE_SOURCE_UNKNOWN);
            }
            $originBen = $terminal->parseMoneyField($order['paid_ben_amount'] ?? '0', 'origin_ben');
            $originGive = $terminal->parseMoneyField($order['paid_give_amount'] ?? '0', 'origin_give');
            // 快照必须精确等于本单余额支付额
            if (bccomp(bcadd($originBen, $originGive, 2), $yueCap, 2) !== 0) {
                OrderTerminalError::throw(OrderTerminalError::BALANCE_SOURCE_UNKNOWN);
            }
            if (!$benProvided && !$giveProvided) {
                // 按退款总额相对余额上限比例拆分本金/赠金
                if (bccomp($yueCap, '0', 2) > 0 && bccomp($refundAmount, $yueCap, 2) < 0
                    && (string)($order['pay_type'] ?? '') === PayServices::YUE_PAY) {
                    $ratioBen = bcdiv($originBen, $yueCap, 6);
                    $refundBen = bcmul($refundAmount, $ratioBen, 2);
                    $refundGive = bcsub($refundAmount, $refundBen, 2);
                } else {
                    $refundBen = $originBen;
                    $refundGive = $originGive;
                }
            } elseif (!$benProvided) {
                $refundBen = '0.00';
            } elseif (!$giveProvided) {
                $refundGive = '0.00';
            }
            if (bccomp($refundBen, $originBen, 2) > 0) {
                OrderTerminalError::throw(OrderTerminalError::ORIGIN_BEN_EXCEED);
            }
            if (bccomp($refundGive, $originGive, 2) > 0) {
                OrderTerminalError::throw(OrderTerminalError::ORIGIN_GIVE_EXCEED);
            }
            $balanceTotal = bcadd($refundBen, $refundGive, 2);
            if (bccomp($balanceTotal, $yueCap, 2) > 0) {
                OrderTerminalError::throw(OrderTerminalError::ORIGIN_BEN_EXCEED);
            }
            // 锁行后再次校验当前余额上限
            if ($lockUser && (bccomp($refundBen, '0', 2) > 0 || bccomp($refundGive, '0', 2) > 0)) {
                $this->assertAvailableBalanceLimits((int)$order['uid'], $refundBen, $refundGive);
            }
        }

        if (bccomp($balanceTotal, $refundAmount, 2) > 0) {
            OrderTerminalError::throw(OrderTerminalError::REFUND_BALANCE_GT_AMOUNT);
        }

        return [
            'refund_amount' => $refundAmount,
            'refund_ben' => $refundBen,
            'refund_give' => $refundGive,
            'balance_total' => $balanceTotal,
            'ben_provided' => $benProvided,
            'give_provided' => $giveProvided,
        ];
    }

    /**
     * 锁会员行后校验当前本金/赠金上限
     */
    public function assertAvailableBalanceLimits(int $uid, string $refundBen, string $refundGive): void
    {
        $user = Db::name('user')->where('uid', $uid)->lock(true)->find();
        if (!$user) {
            OrderTerminalError::throw(OrderTerminalError::GENERIC_FAIL, 'user missing');
        }
        if (bccomp($refundBen, bcadd((string)($user['ben_money'] ?? '0'), '0', 2), 2) > 0) {
            OrderTerminalError::throw(OrderTerminalError::BALANCE_BEN_NOT_ENOUGH);
        }
        if (bccomp($refundGive, bcadd((string)($user['give_money'] ?? '0'), '0', 2), 2) > 0) {
            OrderTerminalError::throw(OrderTerminalError::BALANCE_GIVE_NOT_ENOUGH);
        }
    }

    /**
     * 原单支付构成
     * @return array{yue:string,bookkeeping:string,external:string,external_pay_type:string}
     */
    public function resolveOriginPaymentParts(array $order): array
    {
        $payType = (string)($order['pay_type'] ?? '');
        $yue = bcadd((string)($order['yue_pay_price'] ?? '0'), '0', 2);
        $cash = bcadd((string)($order['cash_pay_price'] ?? '0'), '0', 2);
        $bookkeeping = '0.00';
        $external = '0.00';
        $externalPayType = '';

        if ($payType === PayServices::YUE_PAY) {
            $yue = bcadd((string)($order['pay_price'] ?? $yue), '0', 2);
        } elseif ($payType === PayServices::CASH_PAY) {
            $bookkeeping = bcadd((string)($order['pay_price'] ?? $cash), '0', 2);
        } elseif ($payType === PayServices::WEIXIN_PAY) {
            $external = bcsub(bcadd((string)$order['pay_price'], '0', 2), $yue, 2);
            if (bccomp($external, '0', 2) < 0) {
                $external = '0.00';
            }
            $externalPayType = PayServices::WEIXIN_PAY;
        } elseif ($payType === PayServices::ALIAPY_PAY) {
            $external = bcsub(bcadd((string)$order['pay_price'], '0', 2), $yue, 2);
            if (bccomp($external, '0', 2) < 0) {
                $external = '0.00';
            }
            $externalPayType = PayServices::ALIAPY_PAY;
        } elseif ($payType === PayServices::COMBINATION_PAY) {
            $lines = Db::name('combination_order')->where('order_id', (int)$order['id'])->select()->toArray();
            foreach ($lines as $line) {
                $active = (int)($line['active_pay'] ?? 0);
                $price = bcadd((string)($line['price'] ?? '0'), '0', 2);
                if ($active === 2) {
                    $bookkeeping = bcadd($bookkeeping, $price, 2);
                } elseif ($active === 3) {
                    continue;
                } else {
                    $external = bcadd($external, $price, 2);
                    if ($externalPayType === '') {
                        $name = (string)($line['name'] ?? '');
                        if (stripos($name, '支付宝') !== false || stripos($name, 'alipay') !== false) {
                            $externalPayType = PayServices::ALIAPY_PAY;
                        } else {
                            $externalPayType = PayServices::WEIXIN_PAY;
                        }
                    }
                }
            }
            if (bccomp($bookkeeping, '0', 2) === 0 && bccomp($cash, '0', 2) > 0) {
                $bookkeeping = $cash;
            }
            if (bccomp($yue, '0', 2) === 0) {
                $yue = bcadd((string)($order['yue_pay_price'] ?? '0'), '0', 2);
            }
        } else {
            if (bccomp($cash, '0', 2) > 0) {
                $bookkeeping = $cash;
            }
        }

        return [
            'yue' => $yue,
            'bookkeeping' => $bookkeeping,
            'external' => $external,
            'external_pay_type' => $externalPayType,
        ];
    }

    /**
     * 强制：balance + bookkeeping + external = refund_amount。
     * 操作员已明确传入的本金/赠金禁止改写；无法闭合则拒绝。
     */
    public function buildPaymentPlan(array $order, array $money, array $input): array
    {
        $origin = $this->resolveOriginPaymentParts($order);
        $refundAmount = $money['refund_amount'];
        $refundBen = $money['refund_ben'];
        $refundGive = $money['refund_give'];
        $balanceTotal = $money['balance_total'];
        $explicitBalance = !empty($money['ben_provided']) || !empty($money['give_provided']);

        // 分配：固定操作员余额额；剩余仅分配记账与外部，禁止自动增减本金/赠金
        $remain = bcsub($refundAmount, $balanceTotal, 2);
        if (bccomp($remain, '0', 2) < 0) {
            OrderTerminalError::throw(OrderTerminalError::REFUND_BALANCE_GT_AMOUNT);
        }

        $bookkeeping = '0.00';
        $external = '0.00';
        if (bccomp($remain, '0', 2) > 0) {
            $bookkeeping = bccomp($origin['bookkeeping'], $remain, 2) <= 0 ? $origin['bookkeeping'] : $remain;
            $remain = bcsub($remain, $bookkeeping, 2);
        }
        if (bccomp($remain, '0', 2) > 0) {
            $external = bccomp($origin['external'], $remain, 2) <= 0 ? $origin['external'] : $remain;
            $remain = bcsub($remain, $external, 2);
        }
        // 仅当本金/赠金均未传入（走默认快照）且仍有差额时，才可用未占用余额容量补齐
        if (bccomp($remain, '0', 2) > 0 && !$explicitBalance) {
            $spareYue = bcsub($origin['yue'], $balanceTotal, 2);
            if (bccomp($spareYue, '0', 2) < 0) {
                $spareYue = '0.00';
            }
            $extraBal = bccomp($spareYue, $remain, 2) <= 0 ? $spareYue : $remain;
            if (bccomp($extraBal, '0', 2) > 0) {
                $originBen = bcadd((string)($order['paid_ben_amount'] ?? '0'), '0', 2);
                $originGive = bcadd((string)($order['paid_give_amount'] ?? '0'), '0', 2);
                $spareBen = bcsub($originBen, $refundBen, 2);
                if (bccomp($spareBen, '0', 2) < 0) {
                    $spareBen = '0.00';
                }
                $addBen = bccomp($spareBen, $extraBal, 2) <= 0 ? $spareBen : $extraBal;
                $refundBen = bcadd($refundBen, $addBen, 2);
                $left = bcsub($extraBal, $addBen, 2);
                if (bccomp($left, '0', 2) > 0) {
                    $spareGive = bcsub($originGive, $refundGive, 2);
                    if (bccomp($spareGive, '0', 2) < 0) {
                        $spareGive = '0.00';
                    }
                    $addGive = bccomp($spareGive, $left, 2) <= 0 ? $spareGive : $left;
                    $refundGive = bcadd($refundGive, $addGive, 2);
                    $left = bcsub($left, $addGive, 2);
                }
                $balanceTotal = bcadd($refundBen, $refundGive, 2);
                $remain = bcsub($remain, bcsub($extraBal, $left, 2), 2);
            }
        }
        if (bccomp($remain, '0', 2) !== 0) {
            OrderTerminalError::throw(OrderTerminalError::PAYMENT_PLAN_MISMATCH);
        }

        if (bccomp($balanceTotal, $origin['yue'], 2) > 0
            || bccomp($bookkeeping, $origin['bookkeeping'], 2) > 0
            || bccomp($external, $origin['external'], 2) > 0) {
            OrderTerminalError::throw(OrderTerminalError::PAYMENT_PLAN_MISMATCH);
        }

        $sum = bcadd(bcadd($balanceTotal, $bookkeeping, 2), $external, 2);
        if (bccomp($sum, $refundAmount, 2) !== 0) {
            OrderTerminalError::throw(OrderTerminalError::PAYMENT_PLAN_MISMATCH);
        }

        if ((string)($order['pay_type'] ?? '') === PayServices::YUE_PAY
            && bccomp($balanceTotal, $refundAmount, 2) !== 0) {
            OrderTerminalError::throw(OrderTerminalError::PAYMENT_PLAN_MISMATCH);
        }

        $needConfirm = bccomp($bookkeeping, '0', 2) > 0;
        $opType = (string)($input['operator_type'] ?? '');
        $opId = (int)($input['operator_id'] ?? 0);
        $confirmed = (int)($input['bookkeeping_confirmed'] ?? 0) === 1;
        $remark = trim((string)($input['bookkeeping_remark'] ?? ''));
        $autoOperator = in_array($opType, ['system', 'pink', 'erp', 'out'], true);
        $parkNeedManual = false;
        if ($needConfirm) {
            if ($autoOperator || $opId <= 0) {
                // 自动任务不得确认记账：进入人工核对
                $parkNeedManual = true;
            } elseif (!$confirmed || $remark === '') {
                OrderTerminalError::throw(OrderTerminalError::BOOKKEEPING_CONFIRM_REQUIRED);
            }
        }

        if (!$parkNeedManual && bccomp($balanceTotal, '0', 2) > 0) {
            $this->assertAvailableBalanceLimits((int)$order['uid'], $refundBen, $refundGive);
        }

        return [
            'bookkeeping_amount' => $bookkeeping,
            'external_amount' => $external,
            'external_pay_type' => $origin['external_pay_type'],
            'balance_total' => $balanceTotal,
            'refund_ben' => $refundBen,
            'refund_give' => $refundGive,
            'refund_amount' => $refundAmount,
            'need_bookkeeping_confirm' => $needConfirm,
            'bookkeeping_confirmed' => $parkNeedManual ? 0 : (int)($input['bookkeeping_confirmed'] ?? 0),
            'bookkeeping_remark' => (string)($input['bookkeeping_remark'] ?? ''),
            'operator_type' => $opType,
            'operator_id' => $opId,
            'park_need_manual' => $parkNeedManual,
            'origin' => $origin,
        ];
    }

    protected function executeWholeRefund(array $order, array $money, array $plan, array $input, int $existingRefundId): array
    {
        /** @var StoreOrderTerminalOperationServices $terminal */
        $terminal = app()->make(StoreOrderTerminalOperationServices::class);
        $storeOrderId = (int)$order['id'];
        $storeScope = (int)($input['store_scope'] ?? 0);
        $businessDate = $terminal->normalizeRefundBusinessDate(
            isset($input['refund_business_date']) ? (string)$input['refund_business_date'] : null,
            (int)($order['pay_time'] ?? 0)
        );

        $beginCtx = [
            'store_order_id' => $storeOrderId,
            'action_type' => StoreOrderTerminalOperation::ACTION_REFUND,
            'business_type' => StoreOrderTerminalOperation::BUSINESS_ORDER,
            'source_type' => (int)($input['source_type'] ?? StoreOrderTerminalOperation::SOURCE_STORE),
            'operator_type' => (string)($input['operator_type'] ?? 'store'),
            'operator_id' => (int)($input['operator_id'] ?? 0),
            'store_scope' => $storeScope,
            'request_token' => (string)($input['request_token'] ?? ''),
            'refund_business_date' => $businessDate,
            'refund_amount' => $money['refund_amount'],
            'refund_ben' => $money['refund_ben'],
            'refund_give' => $money['refund_give'],
            'origin_ben_limit' => (string)($order['paid_ben_amount'] ?? '0'),
            'origin_give_limit' => (string)($order['paid_give_amount'] ?? '0'),
            'reason' => (string)($input['refund_reason'] ?? $input['refund_explain'] ?? ''),
            'bookkeeping_confirmed' => (int)$plan['bookkeeping_confirmed'],
            'bookkeeping_remark' => (string)$plan['bookkeeping_remark'],
            'link_refund_id' => $existingRefundId,
        ];
        if (trim((string)$beginCtx['request_token']) === '') {
            unset($beginCtx['request_token']);
        }
        // A2-R1：owner 在 begin 前生成并原样透传，禁止领域层补领租约
        $execOwner = trim((string)($input['execution_owner'] ?? '')) ?: $terminal->makeExecutionOwner();
        $beginCtx['execution_owner'] = $execOwner;

        $begin = $terminal->beginOrResume($beginCtx);
        if (!$terminal->shouldExecute($begin)) {
            return $this->observerResult($begin, $order);
        }
        // 门禁仅拦新执行，幂等观察者已在上方返回
        /** @var \app\services\order\terminal\RefundOutboxRelayGateServices $relayGate */
        $relayGate = app()->make(\app\services\order\terminal\RefundOutboxRelayGateServices::class);
        $relayGate->assertReadyForTerminalRefund();

        $opId = (int)$begin['id'];
        $opNo = (string)$begin['operation_no'];
        $extNo = (string)($begin['external_refund_no'] ?: $opNo);
        $epoch = (int)($begin['execution_epoch'] ?? 0);

        try {
            /** @var StoreOrderRefundServices $refundServices */
            $refundServices = app()->make(StoreOrderRefundServices::class);
            $refundId = $existingRefundId > 0 ? $existingRefundId : (int)($begin['link_refund_id'] ?? 0);
            if ($refundId <= 0) {
                $refundId = (int)Db::name('store_order_refund')
                    ->where('store_order_id', $storeOrderId)
                    ->where('is_cancel', 0)
                    ->whereIn('refund_type', [0, 1, 2, 4, 5, 6])
                    ->order('id', 'desc')
                    ->value('id');
            }
            if ($refundId <= 0) {
                $refundId = (int)$refundServices->splitApplyRefund(
                    $storeOrderId,
                    $order,
                    [],
                    6,
                    (float)$money['refund_amount'],
                    [
                        'refund_reason' => (string)($input['refund_reason'] ?? ''),
                        'refund_explain' => (string)($input['refund_explain'] ?? ''),
                        'refund_status' => 2,
                        'refund_type' => 6,
                    ]
                );
            }
            $terminal->markRefundAsWholeOrderRequest($refundId);
            if (!$this->fencedUpdateOrHold($opId, $execOwner, $epoch, [
                'link_refund_id' => $refundId,
                'refund_amount' => $money['refund_amount'],
                'refund_ben' => $money['refund_ben'],
                'refund_give' => $money['refund_give'],
                'refund_balance_total' => $money['balance_total'],
                'external_refund_amount' => $plan['external_amount'],
            ])) {
                return $this->observerResult($begin, $order);
            }
            Db::name('store_order_refund')->where('id', $refundId)->update([
                'terminal_operation_id' => $opId,
            ]);

            $opRow = Db::name('store_order_terminal_operation')->where('id', $opId)->find();
            $externalDone = (int)($opRow['external_refund_done'] ?? 0) === 1
                || (int)($opRow['external_refund_state'] ?? 0) === 2;
            $balanceDone = (int)($opRow['balance_refund_done'] ?? 0) === 1;
            $localDone = (int)($opRow['local_close_done'] ?? 0) === 1;
            $stateNow = (int)($opRow['state'] ?? 0);
            // CHANNEL_PENDING 且无法确认渠道结果：转人工，禁止盲目重打
            if ($stateNow === StoreOrderTerminalOperation::STATE_CHANNEL_PENDING && !$externalDone) {
                $terminal->markNeedManual($opId, OrderTerminalError::CHANNEL_DONE_LOCAL_FAIL, 'channel uncertain on recover', $execOwner, $epoch);
                OrderTerminalError::throw(OrderTerminalError::CHANNEL_DONE_LOCAL_FAIL, 'channel uncertain on recover');
            }
            $terminal->renewExecutionLease($opId, $execOwner, $epoch);

            // —— 1) 外部渠道：事务外调用，成功后立即持久化，禁止依赖 refund_status ——
            if (bccomp((string)$plan['external_amount'], '0', 2) > 0 && !$externalDone) {
                $step = $terminal->transition($opId, StoreOrderTerminalOperation::STATE_CHANNEL_PENDING, [], $execOwner, $epoch);
                if (!$terminal->shouldExecute($step)
                    && (int)$step['state'] !== StoreOrderTerminalOperation::STATE_CHANNEL_PENDING) {
                    return $this->observerResult($step, $order);
                }
                $refundServices->setItem('terminal_authorized', 1)
                    ->setItem('terminal_external_refund_no', $extNo);
                try {
                    $refundServices->executeExternalChannelRefund(
                        $order,
                        [
                            'pay_price' => $plan['external_amount'],
                            'refund_price' => $plan['external_amount'],
                            'refund_id' => $extNo,
                        ],
                        (string)$plan['external_amount'],
                        (string)$plan['external_pay_type']
                    );
                } catch (\Throwable $e) {
                    $refundServices->reset();
                    throw $e;
                }
                $refundServices->reset();
                // 渠道成功立即可靠落库（独立更新，不与后续本地事务捆绑）
                if ($terminal->fencedUpdate($opId, $execOwner, $epoch, [
                    'external_refund_state' => 2,
                    'external_refund_done' => 1,
                    'external_refund_amount' => $plan['external_amount'],
                    'external_refund_no' => $extNo,
                    'channel_finished_at' => time(),
                ]) <= 0) {
                    OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'channel done fence lost');
                }
                $externalDone = true;
            } elseif (bccomp((string)$plan['external_amount'], '0', 2) <= 0 && !$externalDone) {
                if ($terminal->fencedUpdate($opId, $execOwner, $epoch, [
                    'external_refund_done' => 1,
                    'external_refund_state' => 0,
                    'external_refund_amount' => '0.00',
                ]) <= 0) {
                    OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'zero channel fence lost');
                }
                $externalDone = true;
            }

            // —— 2) 余额退回（幂等键=操作号+业务类型；锁行再验当前上限）——
            if (bccomp((string)$money['balance_total'], '0', 2) > 0 && !$balanceDone) {
                $this->assertAvailableBalanceLimits((int)$order['uid'], $money['refund_ben'], $money['refund_give']);
                $idemKey = $opNo . ':pay_product_refund';
                $refundServices->setItem('terminal_authorized', 1)
                    ->setItem('terminal_balance_idempotency_key', $idemKey);
                $refundServices->yueRefund(
                    $order,
                    ['refund_price' => 0],
                    $money['refund_ben'],
                    $money['refund_give'],
                    $idemKey
                );
                $refundServices->reset();
                if ($terminal->fencedUpdate($opId, $execOwner, $epoch, [
                    'balance_refund_done' => 1,
                ]) <= 0) {
                    OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'balance done fence lost');
                }
                $balanceDone = true;
            } elseif (!$balanceDone) {
                if ($terminal->fencedUpdate($opId, $execOwner, $epoch, [
                    'balance_refund_done' => 1,
                ]) <= 0) {
                    OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'balance skip fence lost');
                }
                $balanceDone = true;
            }

            // —— 3) 本地收口：每步与完成标记同事务；失败恢复只补未完成步 ——
            if (!$localDone) {
                $localStep = $terminal->transition($opId, StoreOrderTerminalOperation::STATE_LOCAL_CLOSING, [], $execOwner, $epoch);
                if (!$terminal->shouldExecute($localStep)
                    && !in_array((int)$localStep['state'], [
                        StoreOrderTerminalOperation::STATE_LOCAL_CLOSING,
                        StoreOrderTerminalOperation::STATE_NEED_MANUAL,
                    ], true)) {
                    return $this->observerResult($localStep, $order);
                }
                $recoverCoupon = isset($input['return_coupon']) && (int)$input['return_coupon'] === 0 ? 0 : 1;
                $injectFail = (string)($input['inject_fail_after'] ?? '');
                $refundDataLocal = [
                    'pay_price' => $order['pay_price'],
                    'refund_price' => $money['refund_amount'],
                    'refund_id' => $extNo,
                    'operation_no' => $opNo,
                    'terminal_operation_id' => $opId,
                ];
                $refundServices->setItem('terminal_authorized', 1)
                    ->setItem('terminal_operation_no', $opNo)
                    ->setItem('terminal_operation_id', $opId);
                $orderModel = $refundServices->prepareRefundOrder($refundId);

                $this->runLocalStep($opId, 'entitlement_rollback_done', function () use ($refundServices, $refundId, $recoverCoupon, $input, $orderModel) {
                    $refundServices->setItem('terminal_authorized', 1)
                        ->setItem('change_manager_type', (string)($input['operator_type'] ?? 'store'))
                        ->setItem('change_manager_id', (int)($input['operator_id'] ?? 0))
                        ->setItem('recover_coupon', $recoverCoupon);
                    $refundServices->runEntitlementRollback($orderModel, $refundId, $recoverCoupon);
                }, $injectFail, 'entitlement', $execOwner, $epoch);

                $this->runLocalStep($opId, 'inventory_rollback_done', function () use ($refundServices, $refundId, $input, $orderModel) {
                    $refundServices->setItem('terminal_authorized', 1)
                        ->setItem('stock_in_type', (int)($input['stock_in_type'] ?? 0));
                    $refundServices->runInventoryRollback($orderModel, $refundId);
                }, $injectFail, 'inventory', $execOwner, $epoch);

                $this->runLocalStep($opId, 'performance_rollback_done', function () use ($refundServices, $orderModel) {
                    $refundServices->setItem('terminal_authorized', 1);
                    $refundServices->runPerformanceRollback($orderModel);
                }, $injectFail, 'performance', $execOwner, $epoch);
                $terminal->renewExecutionLease($opId, $execOwner, $epoch);

                $orderArr = is_array($orderModel) ? $orderModel : $orderModel->toArray();
                // 兼容旧逐步注入（entitlement/inventory/...）
                if (in_array($injectFail, ['status_notify', 'refund_event', 'status_notify_before_done', 'refund_event_before_done', 'status_notify_after_commit_before_flush', 'refund_event_after_commit_before_flush'], true)) {
                    $this->runOutboxLocalStep(
                        $opId,
                        'status_notify_done',
                        \app\services\order\terminal\RefundSideEffectOutboxServices::STEP_STATUS_NOTIFY,
                        $opNo,
                        function () use ($money, $opNo, $opId, $input, $orderArr) {
                            return [
                                'order_id' => (int)$orderArr['id'],
                                'change_type' => 'refund_price',
                                'data' => [
                                    'change_message' => '退款给用户：' . $money['refund_amount'] . '元',
                                    'change_manager_type' => (string)($input['operator_type'] ?? 'store'),
                                    'change_manager_id' => (int)($input['operator_id'] ?? 0),
                                    'terminal_idempotency_key' => $opNo . ':status_notify',
                                    'operation_no' => $opNo,
                                    'terminal_operation_id' => $opId,
                                ],
                            ];
                        },
                        $injectFail,
                        'status_notify',
                        $execOwner,
                        $epoch
                    );
                    $this->runOutboxLocalStep(
                        $opId,
                        'refund_event_done',
                        \app\services\order\terminal\RefundSideEffectOutboxServices::STEP_REFUND_EVENT,
                        $opNo,
                        function () use ($refundDataLocal, $orderArr, $opNo, $opId) {
                            return [
                                'operation_no' => $opNo,
                                'terminal_operation_id' => $opId,
                                'data' => $refundDataLocal,
                                'order' => $orderArr,
                            ];
                        },
                        $injectFail,
                        'refund_event',
                        $execOwner,
                        $epoch
                    );
                }

                $refundServices->reset();
                if ($injectFail === 'local_close') {
                    throw new \RuntimeException('inject_fail_after=local_close');
                }

                // A2：最终收口事务（Outbox + done + 订单终态 + SUCCESS）后才 flush
                $this->finalizeRefundWithOutbox(
                    $opId,
                    $opNo,
                    $storeOrderId,
                    $refundId,
                    $extNo,
                    $money,
                    $plan,
                    $input,
                    $orderArr,
                    $refundDataLocal,
                    $injectFail,
                    $execOwner,
                    $epoch
                );
            } else {
                // local 已完成但可能 PENDING 未投递：仅补 flush
                /** @var \app\services\order\terminal\RefundSideEffectOutboxServices $outboxFlush */
                $outboxFlush = app()->make(\app\services\order\terminal\RefundSideEffectOutboxServices::class);
                $outboxFlush->flushPending($opNo);
                if ((int)Db::name('store_order_terminal_operation')->where('id', $opId)->value('state')
                    !== StoreOrderTerminalOperation::STATE_SUCCESS) {
                    $terminal->markSuccess($opId, [
                        'link_refund_id' => $refundId,
                        'local_close_done' => 1,
                    ], $execOwner, $epoch);
                }
            }

            return [
                'ok' => true,
                'should_execute' => true,
                'execution_acquired' => true,
                'idempotent_observer' => false,
                'store_order_id' => $storeOrderId,
                'refund_id' => $refundId,
                'operation_id' => $opId,
                'operation_no' => $opNo,
                'external_refund_no' => $extNo,
                'external_refund_amount' => $plan['external_amount'],
                'state' => StoreOrderTerminalOperation::STATE_SUCCESS,
                'refund_business_date' => $businessDate,
                'message' => '退款成功',
            ];
        } catch (\Throwable $e) {
            if ($e instanceof ValidateException && isset($e->errorCode)
                && $e->errorCode === OrderTerminalError::CHANNEL_DONE_LOCAL_FAIL) {
                throw $e;
            }
            Log::warning('[whole_refund_fail] op=' . $opId . ' ' . $e->getMessage());
            $opRow = Db::name('store_order_terminal_operation')->where('id', $opId)->find();
            // 仅真实外部渠道已成功（state=2）才强制 NEED_MANUAL；余额/零渠道完成不算渠道半成功
            $alreadyChannel = $opRow && (int)($opRow['external_refund_state'] ?? 0) === 2;
            try {
                if ($alreadyChannel) {
                    $terminal->markNeedManual($opId, OrderTerminalError::CHANNEL_DONE_LOCAL_FAIL, $e->getMessage(), $execOwner, $epoch);
                    OrderTerminalError::throw(OrderTerminalError::CHANNEL_DONE_LOCAL_FAIL, $e->getMessage());
                }
                $terminal->markFailedRetryable($opId, OrderTerminalError::GENERIC_FAIL, $e->getMessage(), $execOwner, $epoch);
            } catch (ValidateException $ve) {
                throw $ve;
            } catch (\Throwable $e2) {
                Log::error('[whole_refund_mark_fail] ' . $e2->getMessage());
            }
            if ($e instanceof ValidateException) {
                throw $e;
            }
            OrderTerminalError::throw(OrderTerminalError::GENERIC_FAIL, $e->getMessage());
        }
    }

    /**
     * A2 最终收口：同事务写 Outbox + done 标记 + 订单终态 + SUCCESS；提交后再 flush。
     * hard_exit_after_finalize_before_flush：事务已提交后 exit(99)，不 catch。
     */
    protected function finalizeRefundWithOutbox(
        int $opId,
        string $opNo,
        int $storeOrderId,
        int $refundId,
        string $extNo,
        array $money,
        array $plan,
        array $input,
        array $orderArr,
        array $refundDataLocal,
        string $injectFail,
        string $execOwner = '',
        int $epoch = 0
    ): void {
        /** @var \app\services\order\terminal\RefundSideEffectOutboxServices $outbox */
        $outbox = app()->make(\app\services\order\terminal\RefundSideEffectOutboxServices::class);
        /** @var StoreOrderTerminalOperationServices $terminal */
        $terminal = app()->make(StoreOrderTerminalOperationServices::class);

        Db::transaction(function () use (
            $opId, $opNo, $storeOrderId, $refundId, $extNo, $money, $plan, $input, $orderArr, $refundDataLocal, $outbox, $terminal, $execOwner, $epoch
        ) {
            $orderLocked = Db::name('store_order')->where('id', $storeOrderId)->lock(true)->find();
            if (!$orderLocked) {
                OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND);
            }
            $opLocked = Db::name('store_order_terminal_operation')->where('id', $opId)->lock(true)->find();
            if (!$opLocked) {
                OrderTerminalError::throw(OrderTerminalError::GENERIC_FAIL, 'op missing finalize');
            }
            if ($execOwner === '' || $epoch <= 0
                || (string)($opLocked['execution_owner'] ?? '') !== $execOwner
                || (int)($opLocked['execution_epoch'] ?? 0) !== $epoch) {
                OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'finalize fence lost');
            }
            if ((int)$opLocked['state'] === StoreOrderTerminalOperation::STATE_SUCCESS
                && (int)($opLocked['local_close_done'] ?? 0) === 1) {
                return;
            }

            $statusPayload = [
                'order_id' => (int)$orderArr['id'],
                'change_type' => 'refund_price',
                'data' => [
                    'change_message' => '退款给用户：' . $money['refund_amount'] . '元',
                    'change_manager_type' => (string)($input['operator_type'] ?? 'store'),
                    'change_manager_id' => (int)($input['operator_id'] ?? 0),
                    'terminal_idempotency_key' => $opNo . ':status_notify',
                    'operation_no' => $opNo,
                    'terminal_operation_id' => $opId,
                ],
            ];
            $eventPayload = [
                'operation_no' => $opNo,
                'terminal_operation_id' => $opId,
                'data' => $refundDataLocal,
                'order' => $orderArr,
            ];
            if ((int)($opLocked['status_notify_done'] ?? 0) !== 1) {
                $outbox->enqueueInTx($opNo, \app\services\order\terminal\RefundSideEffectOutboxServices::STEP_STATUS_NOTIFY, $statusPayload);
            }
            if ((int)($opLocked['refund_event_done'] ?? 0) !== 1) {
                $outbox->enqueueInTx($opNo, \app\services\order\terminal\RefundSideEffectOutboxServices::STEP_REFUND_EVENT, $eventPayload);
            }

            $now = time();
            $n = $terminal->fencedUpdate($opId, $execOwner, $epoch, [
                'status_notify_done' => 1,
                'refund_event_done' => 1,
                'local_close_done' => 1,
                'external_refund_done' => 1,
                'balance_refund_done' => 1,
                'link_refund_id' => $refundId,
                'external_refund_no' => $extNo,
                'external_refund_state' => bccomp((string)$plan['external_amount'], '0', 2) > 0 ? 2 : 0,
                'external_refund_amount' => $plan['external_amount'],
                'refund_amount' => $money['refund_amount'],
                'refund_ben' => $money['refund_ben'],
                'refund_give' => $money['refund_give'],
                'refund_balance_total' => $money['balance_total'],
                'bookkeeping_confirmed' => (int)$plan['bookkeeping_confirmed'],
                'bookkeeping_remark' => (string)$plan['bookkeeping_remark'],
                'state' => StoreOrderTerminalOperation::STATE_SUCCESS,
                'success_at' => $now,
                'execution_owner' => '',
                'lease_until' => 0,
                'error_code' => '',
                'error_message' => '',
                'user_message' => '',
            ]);
            if ($n <= 0) {
                OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'finalize update fence lost');
            }
            Db::name('store_order')->where('id', $storeOrderId)->where('terminal_action', 0)->update([
                'terminal_action' => StoreOrderTerminalOperation::ACTION_REFUND,
                'terminal_operation_id' => $opId,
                'terminal_action_time' => $now,
                'refund_status' => 2,
                'refund_type' => 6,
            ]);
        });

        // 真实崩溃：事务已提交、尚未 flush — 进程硬退出（不可 catch）
        if ($injectFail === 'hard_exit_after_finalize_before_flush'
            || $injectFail === 'after_commit_before_flush') {
            fwrite(STDERR, "HARD_EXIT_AFTER_FINALIZE_BEFORE_FLUSH op_no={$opNo}\n");
            exit(99);
        }

        $outbox->flushPending($opNo);
    }

    /**
     * 本地子步骤：未完成才执行；副作用与 done 标记同事务
     */
    protected function runLocalStep(int $opId, string $doneColumn, callable $fn, string $injectFail, string $injectName, string $execOwner = '', int $epoch = 0): void
    {
        /** @var StoreOrderTerminalOperationServices $terminal */
        $terminal = app()->make(StoreOrderTerminalOperationServices::class);
        $opRow = Db::name('store_order_terminal_operation')->where('id', $opId)->find();
        if (!$opRow) {
            OrderTerminalError::throw(OrderTerminalError::GENERIC_FAIL, 'op missing for local step');
        }
        if ((int)($opRow[$doneColumn] ?? 0) === 1) {
            if ($injectFail === $injectName || $injectFail === $injectName . '_before_done') {
                // 已完成的步骤不再注入，避免破坏恢复用例
                return;
            }
            return;
        }
        Db::transaction(function () use ($opId, $doneColumn, $fn, $terminal, $execOwner, $epoch) {
            $locked = Db::name('store_order_terminal_operation')->where('id', $opId)->lock(true)->find();
            if ((int)($locked[$doneColumn] ?? 0) === 1) {
                return;
            }
            if ($execOwner === '' || $epoch <= 0
                || (string)($locked['execution_owner'] ?? '') !== $execOwner
                || (int)($locked['execution_epoch'] ?? 0) !== $epoch) {
                OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'local step fence lost');
            }
            $fn();
            if ($terminal->fencedUpdate($opId, $execOwner, $epoch, [
                $doneColumn => 1,
            ]) <= 0) {
                OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'local step done fence lost');
            }
        });
        // 注入点在步骤事务提交之后，模拟「后续步骤/事件失败」
        if ($injectFail === $injectName) {
            throw new \RuntimeException('inject_fail_after=' . $injectName);
        }
    }

    /**
     * 队列类本地步骤：事务内写 outbox + done；提交后再投递 Redis。
     * inject *_before_done：先入队 Redis，再回滚 done/outbox，用于验证消费侧幂等。
     */
    protected function runOutboxLocalStep(
        int $opId,
        string $doneColumn,
        string $stepType,
        string $operationNo,
        callable $payloadBuilder,
        string $injectFail,
        string $injectName,
        string $execOwner = '',
        int $epoch = 0
    ): void {
        /** @var StoreOrderTerminalOperationServices $terminal */
        $terminal = app()->make(StoreOrderTerminalOperationServices::class);
        $opRow = Db::name('store_order_terminal_operation')->where('id', $opId)->find();
        if (!$opRow) {
            OrderTerminalError::throw(OrderTerminalError::GENERIC_FAIL, 'op missing for outbox step');
        }
        if ((int)($opRow[$doneColumn] ?? 0) === 1) {
            // 已完成：仍尝试补投递 PENDING（投递失败恢复）
            /** @var \app\services\order\terminal\RefundSideEffectOutboxServices $outbox */
            $outbox = app()->make(\app\services\order\terminal\RefundSideEffectOutboxServices::class);
            $outbox->flushPending($operationNo, $stepType);
            return;
        }
        /** @var \app\services\order\terminal\RefundSideEffectOutboxServices $outbox */
        $outbox = app()->make(\app\services\order\terminal\RefundSideEffectOutboxServices::class);
        $payloadRef = [];
        Db::transaction(function () use (
            $opId, $doneColumn, $stepType, $operationNo, $payloadBuilder, $injectFail, $injectName,
            $outbox, &$payloadRef, $terminal, $execOwner, $epoch
        ) {
            $locked = Db::name('store_order_terminal_operation')->where('id', $opId)->lock(true)->find();
            if ((int)($locked[$doneColumn] ?? 0) === 1) {
                return;
            }
            if ($execOwner === '' || $epoch <= 0
                || (string)($locked['execution_owner'] ?? '') !== $execOwner
                || (int)($locked['execution_epoch'] ?? 0) !== $epoch) {
                OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'outbox local step fence lost');
            }
            $payloadRef = $payloadBuilder();
            $outbox->enqueueInTx($operationNo, $stepType, $payloadRef);
            if ($injectFail === $injectName . '_before_done') {
                // Redis 入队成功，随后事务回滚 → done/outbox 未提交
                $outbox->dispatchPayloadNow($stepType, $payloadRef);
                throw new \RuntimeException('inject_fail_after_enqueue_before_done=' . $injectName);
            }
            if ($terminal->fencedUpdate($opId, $execOwner, $epoch, [
                $doneColumn => 1,
            ]) <= 0) {
                OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'outbox local step done fence lost');
            }
        });
        // 单步：事务已提交、flush 前退出
        if ($injectFail === $injectName . '_after_commit_before_flush') {
            throw new \RuntimeException('inject_fail_after_commit_before_flush=' . $injectName);
        }
        // 全局 after_commit_before_flush：跳过本步 flush，等两步都写完后由外层抛出
        if ($injectFail === 'after_commit_before_flush') {
            return;
        }
        // 正常路径：事务已提交，再投递 Redis
        $outbox->flushPending($operationNo, $stepType);
        if ($injectFail === $injectName) {
            throw new \RuntimeException('inject_fail_after=' . $injectName);
        }
    }

    public function assertMobileWriteoffGate(int $storeOrderId): void
    {
        $cnt = (int)Db::name('store_order_writeoff')
            ->where('oid', $storeOrderId)
            ->where('status', 0)
            ->count();
        if ($cnt > 0) {
            OrderTerminalError::throw(OrderTerminalError::MOBILE_WRITEOFF_DENIED);
        }
        // 仅有核销子单（无 writeoff 行）也拦截
        $sub = (int)Db::name('store_order')
            ->where('link_order', $storeOrderId)
            ->where('order_type', 2)
            ->where('is_del', 0)
            ->where('is_system_del', 0)
            ->where('refund_status', '<>', 2)
            ->count();
        if ($sub > 0) {
            OrderTerminalError::throw(OrderTerminalError::MOBILE_WRITEOFF_DENIED);
        }
    }

    protected function loadSalesOrder(int $storeOrderId): array
    {
        if ($storeOrderId <= 0) {
            OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND);
        }
        $order = Db::name('store_order')->where('id', $storeOrderId)->find();
        if (!$order) {
            OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND);
        }
        $order = is_array($order) ? $order : $order->toArray();
        if ((int)($order['order_type'] ?? 0) === 2) {
            OrderTerminalError::throw(OrderTerminalError::WRITEOFF_SUB_ORDER);
        }
        return $order;
    }


    /**
     * fencing 写：影响行数 0 时区分「值未变但仍持有租约」与「租约已丢」。
     * @return bool true=仍持有执行权可继续；false=应改观察者返回
     */
    protected function fencedUpdateOrHold(int $opId, string $owner, int $epoch, array $data): bool
    {
        /** @var StoreOrderTerminalOperationServices $terminal */
        $terminal = app()->make(StoreOrderTerminalOperationServices::class);
        $n = $terminal->fencedUpdate($opId, $owner, $epoch, $data);
        if ($n > 0) {
            return true;
        }
        $row = Db::name('store_order_terminal_operation')->where('id', $opId)->find();
        if (!$row) {
            return false;
        }
        return (string)($row['execution_owner'] ?? '') === $owner
            && (int)($row['execution_epoch'] ?? 0) === $epoch;
    }

    protected function observerResult(array $op, array $order): array
    {
        $state = (int)($op['state'] ?? -1);
        $msg = '退款处理中或已完成，请刷新查看结果。';
        if ($state === StoreOrderTerminalOperation::STATE_SUCCESS
            || (int)($order['terminal_action'] ?? 0) === StoreOrderTerminalOperation::ACTION_REFUND
            || (int)($order['refund_status'] ?? 0) === 2) {
            $msg = '该订单已退款，请刷新查看。';
        } elseif ($state === StoreOrderTerminalOperation::STATE_NEED_MANUAL) {
            $msg = OrderTerminalError::message(OrderTerminalError::CHANNEL_DONE_LOCAL_FAIL);
        }
        return [
            'ok' => true,
            'should_execute' => false,
            'execution_acquired' => false,
            'idempotent_observer' => true,
            'store_order_id' => (int)$order['id'],
            'operation_id' => (int)($op['id'] ?? 0),
            'operation_no' => (string)($op['operation_no'] ?? ''),
            'external_refund_no' => (string)($op['external_refund_no'] ?? ''),
            'state' => $state,
            'message' => $msg,
        ];
    }

    protected function callRechargeChannelRefund(array $recharge, string $refundPrice, string $stableRefundNo): void
    {
        $refund_data = [
            'pay_price' => $refundPrice,
            'refund_price' => $refundPrice,
            'refund_id' => $stableRefundNo !== '' ? $stableRefundNo : ((string)$recharge['order_id'] . 'R'),
        ];
        $recharge_type = (string)($recharge['recharge_type'] ?? '');
        switch ($recharge_type) {
            case 'alipay':
                \mohe\services\AliPayService::instance()->refund(
                    $recharge['order_id'],
                    $refund_data['refund_price'],
                    $refund_data['refund_id']
                );
                break;
            case 'weixin':
                $transaction_id = $recharge['trade_no'] ?: $recharge['order_id'];
                $refund_data['type'] = !empty($recharge['trade_no']) ? 'transaction_id' : 'out_trade_no';
                \mohe\services\wechat\Payment::instance()->setAccessEnd(\mohe\services\wechat\Payment::WEB)
                    ->payOrderRefund($transaction_id, $refund_data);
                break;
            case 'routine':
                $transaction_id = $recharge['trade_no'] ?: $recharge['order_id'];
                $refund_data['type'] = !empty($recharge['trade_no']) ? 'transaction_id' : 'out_trade_no';
                \mohe\services\wechat\Payment::instance()->setAccessEnd(\mohe\services\wechat\Payment::MINI)
                    ->payOrderRefund($transaction_id, $refund_data);
                break;
            default:
                break;
        }
    }
}
