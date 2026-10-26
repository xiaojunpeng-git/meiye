<?php
declare(strict_types=1);

namespace app\services\order;

use app\dao\order\StoreOrderTerminalOperationDao;
use app\model\order\StoreOrderTerminalOperation;
use app\services\BaseServices;
use app\services\order\terminal\OrderTerminalError;
use think\facade\Db;
use think\facade\Log;

/**
 * 订单退款/作废统一终态状态机（阶段 1）
 *
 * 职责：行锁、幂等、互斥、执行权、退款业务日校验、状态流转。
 * 真正的渠道退款与副作用回滚在阶段 2/3 接入。
 *
 * 返回约定：业务数组附带
 * - execution_acquired / should_execute：本请求是否取得推进权（唯一执行者）
 * - idempotent_observer：同 token 重放观察者，禁止再跑渠道/余额/本地回滚
 */
class StoreOrderTerminalOperationServices extends BaseServices
{
    /** 历史退款 / 部分退 / 未区分（升级默认与本地修复口径） */
    public const REQUEST_SCOPE_LEGACY = 0;
    /** 新整单退款申请（业务创建时必须显式写入） */
    public const REQUEST_SCOPE_WHOLE_ORDER = 1;

    public function __construct(StoreOrderTerminalOperationDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 拒绝拆单/部分退旧参数（任意非空格式均拒绝）
     */
    public function assertWholeOrderOnly(array $input): void
    {
        if ($this->isTruthySplitFlag($input['is_split_order'] ?? null)) {
            OrderTerminalError::throw(OrderTerminalError::SPLIT_NOT_ALLOWED, 'is_split_order');
        }
        if (array_key_exists('cart_ids', $input) && !$this->isEmptyCartIds($input['cart_ids'])) {
            OrderTerminalError::throw(OrderTerminalError::SPLIT_NOT_ALLOWED, 'cart_ids');
        }
        if ($this->isNonEmptyScalar($input['merge_refund_id'] ?? null) && (string)$input['merge_refund_id'] !== '0') {
            OrderTerminalError::throw(OrderTerminalError::SPLIT_NOT_ALLOWED, 'merge_refund_id');
        }
        foreach (['refund_num', 'cart_num'] as $key) {
            if (array_key_exists($key, $input) && $this->isNonEmptyScalar($input[$key])) {
                OrderTerminalError::throw(OrderTerminalError::PARTIAL_NOT_ALLOWED, $key);
            }
        }
    }

    /**
     * 严格解析金额：非数字/负数/超过两位小数直接拒绝，禁止静默转 0.00
     * 仅 null 或空字符串视为未传，返回 0.00
     */
    public function parseMoneyField($value, string $field = 'amount'): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }
        if (is_bool($value) || is_array($value) || is_object($value)) {
            OrderTerminalError::throw(OrderTerminalError::AMOUNT_INVALID, $field . ' type');
        }
        $raw = trim((string)$value);
        if ($raw === '') {
            return '0.00';
        }
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
            OrderTerminalError::throw(OrderTerminalError::AMOUNT_INVALID, $field . '=' . $raw);
        }
        return bcadd($raw, '0', 2);
    }

    /**
     * 校验并规范化退款业务归属日期（Y-m-d）
     */
    public function normalizeRefundBusinessDate(?string $inputDate, int $payTime, ?int $now = null): string
    {
        $now = $now ?? time();
        $today = date('Y-m-d', $now);
        $date = trim((string)$inputDate);
        if ($date === '') {
            $date = $today;
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $date);
        $errors = \DateTime::getLastErrors();
        $badDate = !$dt || $dt->format('Y-m-d') !== $date;
        if (is_array($errors)) {
            $badDate = $badDate || (($errors['warning_count'] ?? 0) > 0) || (($errors['error_count'] ?? 0) > 0);
        }
        if ($badDate) {
            OrderTerminalError::throw(OrderTerminalError::REFUND_DATE_INVALID, 'bad date=' . $date);
        }
        if ($date > $today) {
            OrderTerminalError::throw(OrderTerminalError::REFUND_DATE_FUTURE, $date);
        }
        $payDay = $payTime > 0 ? date('Y-m-d', $payTime) : $today;
        if ($date < $payDay) {
            OrderTerminalError::throw(OrderTerminalError::REFUND_DATE_BEFORE_PAY, 'payDay=' . $payDay . ' input=' . $date);
        }
        return $date;
    }

    /**
     * 锁订单并校验是否允许发起终态动作
     *
     * @return array{order: array, existing: ?array}
     */
    public function lockOrderForTerminal(int $storeOrderId, int $actionType, int $storeScope = 0): array
    {
        if ($storeOrderId <= 0) {
            OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND);
        }
        if (!in_array($actionType, [StoreOrderTerminalOperation::ACTION_REFUND, StoreOrderTerminalOperation::ACTION_VOID], true)) {
            OrderTerminalError::throw(OrderTerminalError::ACTION_CONFLICT, 'bad action_type');
        }

        $order = Db::name('store_order')->where('id', $storeOrderId)->lock(true)->find();
        if (!$order) {
            OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND);
        }
        $order = is_array($order) ? $order : $order->toArray();

        if ((int)($order['is_del'] ?? 0) === 1 || (int)($order['is_system_del'] ?? 0) === 1) {
            OrderTerminalError::throw(OrderTerminalError::ORDER_DELETED);
        }
        if ((int)($order['paid'] ?? 0) !== 1) {
            OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_PAID);
        }
        if ($storeScope > 0 && (int)($order['store_id'] ?? 0) !== $storeScope) {
            OrderTerminalError::throw(OrderTerminalError::STORE_SCOPE_DENIED);
        }

        $existingArr = $this->dao->getByStoreOrderId($storeOrderId, true);
        $terminalAction = (int)($order['terminal_action'] ?? 0);

        // 已成功终态：同动作留给 beginOrResume 按 token 观察者；不同动作按已退款/已作废拒绝
        if ($existingArr && (int)$existingArr['state'] === StoreOrderTerminalOperation::STATE_SUCCESS) {
            if ((int)$existingArr['action_type'] !== $actionType) {
                if ((int)$existingArr['action_type'] === StoreOrderTerminalOperation::ACTION_REFUND) {
                    OrderTerminalError::throw(OrderTerminalError::ALREADY_REFUNDED);
                }
                OrderTerminalError::throw(OrderTerminalError::ALREADY_VOIDED);
            }
            return ['order' => $order, 'existing' => $existingArr];
        }

        // 处理中异动作：互斥
        if ($existingArr && (int)$existingArr['action_type'] !== $actionType) {
            OrderTerminalError::throw(OrderTerminalError::ACTION_CONFLICT);
        }

        // 终态未成功但已有进度：即使订单已标退款，也允许同单恢复未完成步骤
        $terminalResumePending = $existingArr
            && (int)$existingArr['action_type'] === $actionType
            && (int)$existingArr['state'] !== StoreOrderTerminalOperation::STATE_SUCCESS
            && in_array((int)$existingArr['state'], [
                StoreOrderTerminalOperation::STATE_NEED_MANUAL,
                StoreOrderTerminalOperation::STATE_LOCAL_CLOSING,
                StoreOrderTerminalOperation::STATE_FAILED_RETRYABLE,
            ], true)
            && (
                (int)($existingArr['external_refund_state'] ?? 0) === 2
                || (int)($existingArr['external_refund_done'] ?? 0) === 1
                || (int)($existingArr['balance_refund_done'] ?? 0) === 1
                || (int)($existingArr['local_close_done'] ?? 0) === 1
                || (string)($existingArr['error_code'] ?? '') === OrderTerminalError::BOOKKEEPING_NEED_MANUAL
                || (string)($existingArr['error_code'] ?? '') === OrderTerminalError::CHANNEL_DONE_LOCAL_FAIL
            );

        if ($terminalAction === StoreOrderTerminalOperation::ACTION_REFUND && !$terminalResumePending) {
            OrderTerminalError::throw(OrderTerminalError::ALREADY_REFUNDED);
        }
        if ($terminalAction === StoreOrderTerminalOperation::ACTION_VOID) {
            OrderTerminalError::throw(OrderTerminalError::ALREADY_VOIDED);
        }
        if (((int)($order['refund_status'] ?? 0) === 2 || (int)($order['refund_type'] ?? 0) === 6)
            && !$terminalResumePending) {
            OrderTerminalError::throw(OrderTerminalError::ALREADY_REFUNDED);
        }

        return ['order' => $order, 'existing' => $existingArr];
    }

    /**
     * 创建或复用终态操作（幂等）
     *
     * 同 token 重放可返回已有行，但仅创建者 / FAILED_RETRYABLE→INIT 的 CAS 成功者取得执行权。
     *
     * @param array $ctx store_order_id, action_type, store_scope, request_token, ...
     */
    public function beginOrResume(array $ctx): array
    {
        $storeOrderId = (int)($ctx['store_order_id'] ?? 0);
        $actionType = (int)($ctx['action_type'] ?? 0);
        $storeScope = (int)($ctx['store_scope'] ?? 0);
        $requestToken = trim((string)($ctx['request_token'] ?? ''));
        $requestToken = $requestToken !== '' ? $requestToken : null;

        return $this->transaction(function () use ($ctx, $storeOrderId, $actionType, $storeScope, $requestToken) {
            $this->assertWholeOrderOnly($ctx);

            $locked = $this->lockOrderForTerminal($storeOrderId, $actionType, $storeScope);
            $order = $locked['order'];
            $existing = $locked['existing'];
            $orderStoreId = (int)($order['store_id'] ?? 0);

            if ($requestToken !== null) {
                $byToken = $this->dao->getByRequestToken($requestToken, true);
                if ($byToken) {
                    if ((int)$byToken['store_order_id'] !== $storeOrderId || (int)$byToken['action_type'] !== $actionType) {
                        OrderTerminalError::throw(OrderTerminalError::IDEMPOTENT_REPLAY, 'token conflict');
                    }
                    if ($storeScope > 0 && $orderStoreId !== $storeScope) {
                        OrderTerminalError::throw(OrderTerminalError::STORE_SCOPE_DENIED);
                    }
                    $tokenState = (int)$byToken['state'];
                    $tokenChannelDone = (int)($byToken['external_refund_state'] ?? 0) === 2
                        || (string)($byToken['error_code'] ?? '') === OrderTerminalError::CHANNEL_DONE_LOCAL_FAIL;
                    $tokenBookkeepingPark = (string)($byToken['error_code'] ?? '') === OrderTerminalError::BOOKKEEPING_NEED_MANUAL;
                    $tokenLocalProgress = (int)($byToken['balance_refund_done'] ?? 0) === 1
                        || (int)($byToken['local_close_done'] ?? 0) === 1
                        || (int)($byToken['entitlement_rollback_done'] ?? 0) === 1;
                    // 可恢复：处理中（BALANCE/CHANNEL/LOCAL，靠租约）；普通失败；渠道半成功/本地进度；记账待人工
                    $tokenProcessing = in_array($tokenState, StoreOrderTerminalOperation::processingStates(), true);
                    $tokenResumable = $tokenProcessing
                        || $tokenState === StoreOrderTerminalOperation::STATE_FAILED_RETRYABLE
                        || (($tokenChannelDone || $tokenLocalProgress) && in_array($tokenState, [
                            StoreOrderTerminalOperation::STATE_NEED_MANUAL,
                            StoreOrderTerminalOperation::STATE_LOCAL_CLOSING,
                            StoreOrderTerminalOperation::STATE_FAILED_RETRYABLE,
                        ], true))
                        || ($tokenBookkeepingPark && $tokenState === StoreOrderTerminalOperation::STATE_NEED_MANUAL);
                    if (!$tokenResumable) {
                        return $this->withExecutionMeta($byToken, false);
                    }
                    // 与 existing 对齐（同单唯一行）
                    $existing = $byToken;
                }
            }

            $payTime = (int)($order['pay_time'] ?? 0);
            $businessDate = null;
            if ($actionType === StoreOrderTerminalOperation::ACTION_REFUND) {
                $businessDate = $this->normalizeRefundBusinessDate(
                    $ctx['refund_business_date'] ?? null,
                    $payTime
                );
            }

            $refundBen = $this->parseMoneyField($ctx['refund_ben'] ?? null, 'refund_ben');
            $refundGive = $this->parseMoneyField($ctx['refund_give'] ?? null, 'refund_give');
            $refundAmount = $this->parseMoneyField($ctx['refund_amount'] ?? null, 'refund_amount');
            $refundBalanceTotal = bcadd($refundBen, $refundGive, 2);
            $originBenLimit = $this->parseMoneyField($ctx['origin_ben_limit'] ?? null, 'origin_ben_limit');
            $originGiveLimit = $this->parseMoneyField($ctx['origin_give_limit'] ?? null, 'origin_give_limit');

            $now = time();
            if ($existing) {
                $state = (int)$existing['state'];
                $existingToken = $existing['request_token'] ?? null;
                if ($existingToken === '') {
                    $existingToken = null;
                }

                // 已成功：同 token（或双方皆无）= 观察者；否则拒绝再次退款/作废
                if ($state === StoreOrderTerminalOperation::STATE_SUCCESS) {
                    if ($existingToken !== null) {
                        if ($requestToken === null || $requestToken !== $existingToken) {
                            if ((int)$existing['action_type'] === StoreOrderTerminalOperation::ACTION_REFUND) {
                                OrderTerminalError::throw(OrderTerminalError::ALREADY_REFUNDED);
                            }
                            OrderTerminalError::throw(OrderTerminalError::ALREADY_VOIDED);
                        }
                    } elseif ($requestToken !== null) {
                        if ((int)$existing['action_type'] === StoreOrderTerminalOperation::ACTION_REFUND) {
                            OrderTerminalError::throw(OrderTerminalError::ALREADY_REFUNDED);
                        }
                        OrderTerminalError::throw(OrderTerminalError::ALREADY_VOIDED);
                    }
                    return $this->withExecutionMeta($existing, false);
                }

                if (in_array($state, StoreOrderTerminalOperation::processingStates(), true)) {
                    if ($existingToken !== null) {
                        if ($requestToken === null || $requestToken !== $existingToken) {
                            OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'processing different token');
                        }
                    } elseif ($requestToken !== null) {
                        OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'processing attach token denied');
                    }
                    // A2-R1：同 token 租约未过期→观察者；过期则同事务 CAS 领取 owner+lease+epoch
                    $owner = $this->requireExecutionOwner($ctx);
                    if ($this->tryAcquireExecutionLease((int)$existing['id'], $owner, (array)$existing)) {
                        $fresh = $this->getRowById((int)$existing['id']);
                        return $this->withExecutionMeta($fresh, true);
                    }
                    return $this->withExecutionMeta($existing, false);
                }

                // NEED_MANUAL 恢复：
                // - 记账待人工：允许回到 INIT 完整重跑（须带人工确认）
                // - 渠道已成功本地未收口：只进 LOCAL_CLOSING，禁止重打渠道
                if ($state === StoreOrderTerminalOperation::STATE_NEED_MANUAL) {
                    $bookkeepingPark = (string)($existing['error_code'] ?? '') === OrderTerminalError::BOOKKEEPING_NEED_MANUAL;
                    $channelDone = (int)($existing['external_refund_state'] ?? 0) === 2
                        || (string)($existing['error_code'] ?? '') === OrderTerminalError::CHANNEL_DONE_LOCAL_FAIL;
                    if (!$bookkeepingPark && !$channelDone) {
                        OrderTerminalError::throw(OrderTerminalError::CHANNEL_DONE_LOCAL_FAIL, 'need manual non-channel');
                    }
                    if ($existingToken !== null && $requestToken !== null && $requestToken !== $existingToken) {
                        OrderTerminalError::throw(OrderTerminalError::IDEMPOTENT_REPLAY, 'manual resume token mismatch');
                    }
                    if ($existingToken !== null && $requestToken === null) {
                        OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'manual resume requires original token');
                    }
                    $owner = $this->requireExecutionOwner($ctx);
                    $epochBump = (int)($existing['execution_epoch'] ?? 0) + 1;
                    if ($bookkeepingPark) {
                        // 人工确认后整单重跑：复用 operation_no / external_refund_no；同 CAS 写入租约+纪元
                        $manualPatch = array_merge([
                            'update_time' => $now,
                            'retry_count' => (int)$existing['retry_count'] + 1,
                            'state' => StoreOrderTerminalOperation::STATE_INIT,
                            'error_code' => '',
                            'error_message' => '',
                            'user_message' => '',
                            'bookkeeping_confirmed' => (int)($ctx['bookkeeping_confirmed'] ?? 0),
                            'bookkeeping_remark' => (string)($ctx['bookkeeping_remark'] ?? ''),
                            'operator_type' => (string)($ctx['operator_type'] ?? $existing['operator_type']),
                            'operator_id' => (int)($ctx['operator_id'] ?? $existing['operator_id']),
                            'refund_amount' => $refundAmount,
                            'refund_ben' => $refundBen,
                            'refund_give' => $refundGive,
                            'refund_balance_total' => $refundBalanceTotal,
                            'operation_no' => (string)$existing['operation_no'],
                            'external_refund_no' => (string)($existing['external_refund_no'] ?: $existing['operation_no']),
                        ], $this->leaseClaimFields($owner, $epochBump));
                        if ($businessDate !== null) {
                            $manualPatch['refund_business_date'] = $businessDate;
                        }
                        if ($requestToken !== null && $existingToken === null) {
                            $manualPatch['request_token'] = $requestToken;
                        }
                        $affected = $this->dao->casUpdateByState(
                            (int)$existing['id'],
                            StoreOrderTerminalOperation::STATE_NEED_MANUAL,
                            $manualPatch
                        );
                        $fresh = $this->getRowById((int)$existing['id']);
                        if ($affected <= 0) {
                            return $this->withExecutionMeta($fresh, false);
                        }
                        return $this->withExecutionMeta($fresh, true);
                    }
                    $manualPatch = array_merge([
                        'update_time' => $now,
                        'retry_count' => (int)$existing['retry_count'] + 1,
                        'state' => StoreOrderTerminalOperation::STATE_LOCAL_CLOSING,
                        'error_code' => '',
                        'error_message' => '',
                        'user_message' => '',
                        'operation_no' => (string)$existing['operation_no'],
                        'external_refund_no' => (string)($existing['external_refund_no'] ?: $existing['operation_no']),
                        'external_refund_state' => 2,
                    ], $this->leaseClaimFields($owner, $epochBump));
                    if ($requestToken !== null && $existingToken === null) {
                        $manualPatch['request_token'] = $requestToken;
                    }
                    $affected = $this->dao->casUpdateByState(
                        (int)$existing['id'],
                        StoreOrderTerminalOperation::STATE_NEED_MANUAL,
                        $manualPatch
                    );
                    $fresh = $this->getRowById((int)$existing['id']);
                    if ($affected <= 0) {
                        return $this->withExecutionMeta($fresh, false);
                    }
                    return $this->withExecutionMeta($fresh, true);
                }

                if ($state !== StoreOrderTerminalOperation::STATE_FAILED_RETRYABLE) {
                    OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'state=' . $state);
                }
                // 渠道已成功却落在 FAILED_RETRYABLE：禁止按普通失败重打渠道，改走人工/本地收口
                if ((int)($existing['external_refund_state'] ?? 0) === 2) {
                    OrderTerminalError::throw(OrderTerminalError::CHANNEL_DONE_LOCAL_FAIL, 'channel done but failed_retryable');
                }
                if ($existingToken !== null && $requestToken !== null && $requestToken !== $existingToken) {
                    OrderTerminalError::throw(OrderTerminalError::IDEMPOTENT_REPLAY, 'retry token mismatch');
                }
                if ($existingToken !== null && $requestToken === null) {
                    OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'retry requires original token');
                }

                $owner = $this->requireExecutionOwner($ctx);
                $epochBump = (int)($existing['execution_epoch'] ?? 0) + 1;
                $patch = array_merge([
                    'update_time' => $now,
                    'retry_count' => (int)$existing['retry_count'] + 1,
                    'state' => StoreOrderTerminalOperation::STATE_INIT,
                    'error_code' => '',
                    'error_message' => '',
                    'user_message' => '',
                    'refund_amount' => $refundAmount,
                    'refund_ben' => $refundBen,
                    'refund_give' => $refundGive,
                    'refund_balance_total' => $refundBalanceTotal,
                    'origin_ben_limit' => $originBenLimit,
                    'origin_give_limit' => $originGiveLimit,
                    'operation_no' => (string)$existing['operation_no'],
                    'external_refund_no' => (string)($existing['external_refund_no'] ?: $existing['operation_no']),
                ], $this->leaseClaimFields($owner, $epochBump));
                if ($businessDate !== null) {
                    $patch['refund_business_date'] = $businessDate;
                }
                if ((int)$existing['operated_at'] <= 0) {
                    $patch['operated_at'] = $now;
                }
                if ($requestToken !== null && $existingToken === null) {
                    $patch['request_token'] = $requestToken;
                }

                // CAS：仅 FAILED_RETRYABLE → INIT 成功者取得执行权（含租约+纪元）
                $affected = $this->dao->casUpdateByState(
                    (int)$existing['id'],
                    StoreOrderTerminalOperation::STATE_FAILED_RETRYABLE,
                    $patch
                );
                $fresh = $this->getRowById((int)$existing['id']);
                if ($affected <= 0) {
                    return $this->withExecutionMeta($fresh, false);
                }
                return $this->withExecutionMeta($fresh, true);
            }

            $owner = $this->requireExecutionOwner($ctx);
            $operationNo = $this->makeOperationNo($storeOrderId, $actionType);
            $data = array_merge([
                'operation_no' => $operationNo,
                'store_order_id' => $storeOrderId,
                'link_refund_id' => (int)($ctx['link_refund_id'] ?? 0),
                'link_recharge_id' => (int)($ctx['link_recharge_id'] ?? 0),
                'action_type' => $actionType,
                'business_type' => (int)($ctx['business_type'] ?? StoreOrderTerminalOperation::BUSINESS_ORDER),
                'state' => StoreOrderTerminalOperation::STATE_INIT,
                'source_type' => (int)($ctx['source_type'] ?? 0),
                'operator_type' => (string)($ctx['operator_type'] ?? ''),
                'operator_id' => (int)($ctx['operator_id'] ?? 0),
                'store_id' => $orderStoreId,
                'request_token' => $requestToken,
                'refund_business_date' => $businessDate,
                'operated_at' => $now,
                'refund_amount' => $refundAmount,
                'refund_ben' => $refundBen,
                'refund_give' => $refundGive,
                'refund_balance_total' => $refundBalanceTotal,
                'origin_ben_limit' => $originBenLimit,
                'origin_give_limit' => $originGiveLimit,
                'external_refund_no' => $operationNo,
                'external_refund_state' => 0,
                'bookkeeping_confirmed' => (int)($ctx['bookkeeping_confirmed'] ?? 0),
                'bookkeeping_remark' => (string)($ctx['bookkeeping_remark'] ?? ''),
                'reason' => (string)($ctx['reason'] ?? ''),
                'create_time' => $now,
                'update_time' => $now,
            ], $this->leaseClaimFields($owner, 1));

            try {
                if ($data['request_token'] === null || $data['request_token'] === '') {
                    unset($data['request_token']);
                    $id = (int)Db::name('store_order_terminal_operation')->insertGetId($data);
                    Db::name('store_order_terminal_operation')->where('id', $id)->update(['request_token' => null]);
                } else {
                    $id = (int)Db::name('store_order_terminal_operation')->insertGetId($data);
                }
                return $this->withExecutionMeta($this->getRowById($id), true);
            } catch (\Throwable $e) {
                if (!$this->isDuplicateKeyException($e)) {
                    throw $e;
                }
                // 并发插入落败：同 token → 观察者；异 token → 处理中冲突
                $race = $this->dao->getByStoreOrderId($storeOrderId, true);
                if (!$race) {
                    OrderTerminalError::throw(OrderTerminalError::GENERIC_FAIL, 'duplicate but missing row');
                }
                if ($requestToken !== null) {
                    $raceToken = $race['request_token'] ?? null;
                    if ($raceToken !== null && $raceToken !== '' && $raceToken === $requestToken) {
                        if ($storeScope > 0 && (int)$race['store_id'] !== $storeScope) {
                            OrderTerminalError::throw(OrderTerminalError::STORE_SCOPE_DENIED);
                        }
                        return $this->withExecutionMeta($race, false);
                    }
                }
                if ((int)$race['action_type'] !== $actionType) {
                    OrderTerminalError::throw(OrderTerminalError::ACTION_CONFLICT);
                }
                OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'create race lost');
            }
        });
    }

    public const EXECUTION_LEASE_SEC = 90;

    /**
     * 是否取得业务执行权（渠道/余额/本地回滚仅此为 true 时可跑）
     */
    public function shouldExecute(array $result): bool
    {
        return !empty($result['should_execute']) || !empty($result['execution_acquired']);
    }

    protected function withExecutionMeta(array $row, bool $acquired): array
    {
        $row['execution_acquired'] = $acquired;
        $row['should_execute'] = $acquired;
        $row['idempotent_observer'] = !$acquired;
        return $row;
    }

    public function makeExecutionOwner(array $ctx = []): string
    {
        $hint = trim((string)($ctx['execution_owner'] ?? ''));
        if ($hint !== '') {
            return $hint;
        }
        return 'exec:' . gethostname() . ':' . getmypid() . ':' . bin2hex(random_bytes(4));
    }

    /**
     * 解析执行 owner：优先透传 ctx；缺省时在 beginOrResume 入口生成一次。
     * 禁止在 should_execute=true 之后由领域层再次 makeExecutionOwner。
     */
    public function requireExecutionOwner(array $ctx): string
    {
        $owner = trim((string)($ctx['execution_owner'] ?? ''));
        if ($owner !== '') {
            return $owner;
        }
        return $this->makeExecutionOwner();
    }

    protected function leaseClaimFields(string $owner, int $epoch): array
    {
        return [
            'execution_owner' => $owner,
            'lease_until' => time() + self::EXECUTION_LEASE_SEC,
            'execution_epoch' => max(1, $epoch),
        ];
    }

    /**
     * 下一次 lease_until：至少推到 now+LEASE，且严格大于当前值，避免同秒续租 affected=0 假失败。
     */
    protected function nextLeaseUntil(int $currentLeaseUntil = 0): int
    {
        $now = time();
        return max($now + self::EXECUTION_LEASE_SEC, $currentLeaseUntil + 1);
    }

    /** 同 owner+epoch 且租约未过期 → 续租语义上仍持有 */
    protected function confirmSameOwnerLeaseHeld(int $operationId, string $owner, int $epoch): bool
    {
        $fresh = $this->getRowById($operationId);
        if (!$fresh) {
            return false;
        }
        return (string)($fresh['execution_owner'] ?? '') === $owner
            && (int)($fresh['execution_epoch'] ?? 0) === $epoch
            && (int)($fresh['lease_until'] ?? 0) > time();
    }

    /**
     * 租约未过期且持有者不同 → false；同 owner+epoch 续租；否则 CAS 接管并递增 epoch。
     */
    public function tryAcquireExecutionLease(int $operationId, string $owner, ?array $row = null): bool
    {
        $row = $row ?: $this->getRowById($operationId);
        $now = time();
        $leaseUntil = (int)($row['lease_until'] ?? 0);
        $currentOwner = (string)($row['execution_owner'] ?? '');
        $currentEpoch = (int)($row['execution_epoch'] ?? 0);
        if ($leaseUntil > $now && $currentOwner !== '' && $currentOwner !== $owner) {
            return false;
        }
        if ($leaseUntil > $now && $currentOwner === $owner && $currentEpoch > 0) {
            // 同 owner+epoch 续租，不递增纪元；lease 必须单调前进
            $newLease = $this->nextLeaseUntil($leaseUntil);
            $affected = $this->dao->casUpdateByFence($operationId, $owner, $currentEpoch, [
                'lease_until' => $newLease,
                'update_time' => $now,
            ]);
            if ($affected > 0) {
                return true;
            }
            return $this->confirmSameOwnerLeaseHeld($operationId, $owner, $currentEpoch);
        }
        $nextEpoch = $currentEpoch + 1;
        if ($nextEpoch < 1) {
            $nextEpoch = 1;
        }
        $newLease = $this->nextLeaseUntil($leaseUntil);
        // 抢占过期或空租约：原子写入 owner + lease + 递增 epoch
        $affected = Db::name('store_order_terminal_operation')
            ->where('id', $operationId)
            ->where('execution_epoch', $currentEpoch)
            ->where(function ($query) use ($now, $currentOwner) {
                $query->where('lease_until', '<=', $now)
                    ->whereOr('execution_owner', '')
                    ->whereOr('execution_owner', $currentOwner);
            })
            ->update([
                'execution_owner' => $owner,
                'lease_until' => $newLease,
                'execution_epoch' => $nextEpoch,
                'update_time' => $now,
            ]);
        return $affected > 0;
    }

    /** 长步骤续租：必须 owner+epoch 匹配 */
    public function renewExecutionLease(int $operationId, string $owner, int $epoch): bool
    {
        if ($owner === '' || $epoch <= 0) {
            return false;
        }
        $row = $this->getRowById($operationId);
        if (!$row) {
            return false;
        }
        if ((string)($row['execution_owner'] ?? '') !== $owner
            || (int)($row['execution_epoch'] ?? 0) !== $epoch) {
            return false;
        }
        $now = time();
        $newLease = $this->nextLeaseUntil((int)($row['lease_until'] ?? 0));
        $affected = $this->dao->casUpdateByFence($operationId, $owner, $epoch, [
            'lease_until' => $newLease,
            'update_time' => $now,
        ]);
        if ($affected > 0) {
            return true;
        }
        return $this->confirmSameOwnerLeaseHeld($operationId, $owner, $epoch);
    }

    /**
     * 仅当前 owner+epoch 可释放；旧执行者影响行数必须为 0。
     */
    public function releaseExecutionLease(int $operationId, string $owner = '', int $epoch = 0): int
    {
        if ($owner === '' || $epoch <= 0) {
            return 0;
        }
        return $this->dao->casUpdateByFence($operationId, $owner, $epoch, [
            'execution_owner' => '',
            'lease_until' => 0,
            'update_time' => time(),
        ]);
    }

    /**
     * fencing 写：owner+epoch 不匹配返回 0。
     * - 调用方未传 lease_until：自动续租（单调前进）以保证影响行数可辨；
     * - 调用方明确传 lease_until（含 0）：必须保留，不得覆盖（SUCCESS 清零租约）。
     */
    public function fencedUpdate(int $operationId, string $owner, int $epoch, array $data): int
    {
        $now = time();
        $data['update_time'] = $now;
        if (!array_key_exists('lease_until', $data)) {
            $row = $this->getRowById($operationId);
            $currentLease = (int)($row['lease_until'] ?? 0);
            $data['lease_until'] = $this->nextLeaseUntil($currentLease);
        }
        return $this->dao->casUpdateByFence($operationId, $owner, $epoch, $data);
    }

    /**
     * recovery：租约过期的 BALANCE/CHANNEL/LOCAL_CLOSING（可按 operation_no 定向）
     */
    public function listExpiredRecoverable(int $limit = 20, string $operationNo = ''): array
    {
        $now = time();
        // 处理中租约过期；或 FAILED_RETRYABLE 且已有余额/本地进度（可恢复，含作废）
        $q = Db::name('store_order_terminal_operation')
            ->where(function ($query) use ($now) {
                $query->where(function ($q1) use ($now) {
                    $q1->whereIn('state', [
                        StoreOrderTerminalOperation::STATE_BALANCE_PENDING,
                        StoreOrderTerminalOperation::STATE_CHANNEL_PENDING,
                        StoreOrderTerminalOperation::STATE_LOCAL_CLOSING,
                    ])->where('lease_until', '<', $now);
                })->whereOr(function ($q2) {
                    $q2->where('state', StoreOrderTerminalOperation::STATE_FAILED_RETRYABLE)
                        ->where(function ($q3) {
                            $q3->where('balance_refund_done', 1)
                                ->whereOr('local_close_done', 1)
                                ->whereOr('external_refund_state', 2);
                        });
                });
            })
            ->where('state', '<>', StoreOrderTerminalOperation::STATE_SUCCESS)
            ->order('id', 'asc')
            ->limit(max(1, min(100, $limit)));
        if ($operationNo !== '') {
            $q->where('operation_no', $operationNo);
        }
        return $q->select()->toArray();
    }

    /** @deprecated 使用 listExpiredRecoverable */
    public function listExpiredLocalClosing(int $limit = 20): array
    {
        return $this->listExpiredRecoverable($limit, '');
    }

    protected function getRowById(int $id): array
    {
        $row = Db::name('store_order_terminal_operation')->where('id', $id)->find();
        if (!$row) {
            OrderTerminalError::throw(OrderTerminalError::GENERIC_FAIL, 'operation missing after write');
        }
        return is_array($row) ? $row : $row->toArray();
    }

    /**
     * 状态推进：禁止同态自循环当成功；带 from-state CAS，仅一名推进者取得执行权
     */
    public function transition(int $operationId, int $toState, array $patch = [], string $owner = '', int $epoch = 0): array
    {
        return $this->transaction(function () use ($operationId, $toState, $patch, $owner, $epoch) {
            $row = $this->dao->getLocked($operationId);
            if (!$row) {
                OrderTerminalError::throw(OrderTerminalError::GENERIC_FAIL, 'operation missing');
            }
            $from = (int)$row['state'];

            // 已在目标态：幂等观察，禁止再次当执行者
            if ($from === $toState) {
                return $this->withExecutionMeta($row, false);
            }
            if ($from === StoreOrderTerminalOperation::STATE_SUCCESS) {
                OrderTerminalError::throw(OrderTerminalError::ALREADY_REFUNDED, 'success immutable');
            }
            if (!$this->canTransition($from, $toState)) {
                OrderTerminalError::throw(
                    OrderTerminalError::GENERIC_FAIL,
                    'illegal transition ' . $from . '->' . $toState
                );
            }
            if ($owner === '' || $epoch <= 0) {
                OrderTerminalError::throw(OrderTerminalError::TERMINAL_PROCESSING, 'transition requires owner+epoch');
            }

            $now = time();
            $data = array_merge($patch, [
                'state' => $toState,
                'update_time' => $now,
                'lease_until' => $now + self::EXECUTION_LEASE_SEC,
            ]);
            if ($toState === StoreOrderTerminalOperation::STATE_CHANNEL_PENDING
                && empty($row['channel_started_at'])) {
                $data['channel_started_at'] = $now;
            }
            if (in_array($toState, [
                StoreOrderTerminalOperation::STATE_SUCCESS,
                StoreOrderTerminalOperation::STATE_FAILED_RETRYABLE,
                StoreOrderTerminalOperation::STATE_NEED_MANUAL,
            ], true)) {
                $data['channel_finished_at'] = $now;
            }

            $affected = $this->dao->casUpdateByStateAndFence($operationId, $from, $owner, $epoch, $data);
            $fresh = $this->getRowById($operationId);
            if ($affected <= 0) {
                if ((int)$fresh['state'] === $toState) {
                    return $this->withExecutionMeta($fresh, false);
                }
                OrderTerminalError::throw(
                    OrderTerminalError::TERMINAL_PROCESSING,
                    'cas/fence lost ' . $from . '->' . $toState . ' now=' . (int)$fresh['state']
                );
            }
            return $this->withExecutionMeta($fresh, true);
        });
    }

    public function markSuccess(int $operationId, array $patch = [], string $owner = '', int $epoch = 0): array
    {
        return $this->transaction(function () use ($operationId, $patch, $owner, $epoch) {
            // 锁顺序必须与 beginOrResume 一致：先订单后终态，避免与同 token 观察者死锁
            $peek = Db::name('store_order_terminal_operation')->where('id', $operationId)->find();
            if (!$peek) {
                OrderTerminalError::throw(OrderTerminalError::GENERIC_FAIL, 'operation missing');
            }
            $peek = is_array($peek) ? $peek : $peek->toArray();
            $orderId = (int)$peek['store_order_id'];
            $order = Db::name('store_order')->where('id', $orderId)->lock(true)->find();
            if (!$order) {
                OrderTerminalError::throw(OrderTerminalError::ORDER_NOT_FOUND);
            }

            $op = $this->transition($operationId, StoreOrderTerminalOperation::STATE_SUCCESS, array_merge($patch, [
                'success_at' => time(),
                'user_message' => '',
                'error_code' => '',
                'error_message' => '',
            ]), $owner, $epoch);
            // 未取得 SUCCESS 推进权：禁止重复写订单终态/副作用
            if (!$this->shouldExecute($op)) {
                return $op;
            }
            $action = (int)$op['action_type'];
            $now = time();
            $orderPatch = [
                'terminal_action' => $action,
                'terminal_operation_id' => (int)$op['id'],
                'terminal_action_time' => $now,
            ];
            if ($action === StoreOrderTerminalOperation::ACTION_REFUND) {
                $orderPatch['refund_status'] = 2;
                $orderPatch['refund_type'] = 6;
            }
            // 订单终态也用 CAS，防止双写（已持有订单行锁）
            Db::name('store_order')
                ->where('id', $orderId)
                ->where('terminal_action', 0)
                ->update($orderPatch);
            // 成功后仅当前 owner+epoch 可释放租约
            $this->releaseExecutionLease($operationId, $owner, $epoch);
            return $this->getRowById($operationId);
        });
    }

    public function markFailedRetryable(int $operationId, string $errorCode, string $internalDetail = '', string $owner = '', int $epoch = 0): array
    {
        $userMessage = OrderTerminalError::message($errorCode);
        Log::warning('[terminal_op_fail] id=' . $operationId . ' code=' . $errorCode . ' detail=' . $internalDetail);
        $op = $this->transition($operationId, StoreOrderTerminalOperation::STATE_FAILED_RETRYABLE, [
            'error_code' => $errorCode,
            'error_message' => mb_substr($internalDetail, 0, 1000),
            'user_message' => $userMessage,
        ], $owner, $epoch);
        $this->releaseExecutionLease($operationId, $owner, $epoch);
        return $op;
    }

    public function markNeedManual(int $operationId, string $errorCode, string $internalDetail = '', string $owner = '', int $epoch = 0): array
    {
        $userMessage = OrderTerminalError::message($errorCode);
        Log::error('[terminal_op_manual] id=' . $operationId . ' code=' . $errorCode . ' detail=' . $internalDetail);
        return $this->transition($operationId, StoreOrderTerminalOperation::STATE_NEED_MANUAL, [
            'error_code' => $errorCode,
            'error_message' => mb_substr($internalDetail, 0, 1000),
            'user_message' => $userMessage,
        ], $owner, $epoch);
    }

    public function assertRefundBalanceLimits(
        string $refundBen,
        string $refundGive,
        string $originBenLimit,
        string $originGiveLimit,
        string $availableBen,
        string $availableGive,
        bool $balanceReady
    ): void {
        if (!$balanceReady) {
            OrderTerminalError::throw(OrderTerminalError::BALANCE_SOURCE_UNKNOWN);
        }
        $refundBen = $this->parseMoneyField($refundBen, 'refund_ben');
        $refundGive = $this->parseMoneyField($refundGive, 'refund_give');
        $originBenLimit = $this->parseMoneyField($originBenLimit, 'origin_ben_limit');
        $originGiveLimit = $this->parseMoneyField($originGiveLimit, 'origin_give_limit');
        $availableBen = $this->parseMoneyField($availableBen, 'available_ben');
        $availableGive = $this->parseMoneyField($availableGive, 'available_give');
        if (bccomp($refundBen, $originBenLimit, 2) > 0) {
            OrderTerminalError::throw(OrderTerminalError::ORIGIN_BEN_EXCEED);
        }
        if (bccomp($refundGive, $originGiveLimit, 2) > 0) {
            OrderTerminalError::throw(OrderTerminalError::ORIGIN_GIVE_EXCEED);
        }
        if (bccomp($refundBen, $availableBen, 2) > 0) {
            OrderTerminalError::throw(OrderTerminalError::BALANCE_BEN_NOT_ENOUGH);
        }
        if (bccomp($refundGive, $availableGive, 2) > 0) {
            OrderTerminalError::throw(OrderTerminalError::BALANCE_GIVE_NOT_ENOUGH);
        }
    }

    public function canReopen(array $order): bool
    {
        if ((int)($order['terminal_action'] ?? 0) !== StoreOrderTerminalOperation::ACTION_VOID) {
            return false;
        }
        if ((int)($order['order_type'] ?? 0) === 1) {
            return false;
        }
        if (!empty($order['is_debt_repay'])) {
            return false;
        }
        return true;
    }

    /**
     * 禁止 from===to 视为合法推进；同态由 transition 按观察者处理
     */
    protected function canTransition(int $from, int $to): bool
    {
        if ($from === $to) {
            return false;
        }
        $map = [
            StoreOrderTerminalOperation::STATE_INIT => [
                StoreOrderTerminalOperation::STATE_BALANCE_PENDING,
                StoreOrderTerminalOperation::STATE_CHANNEL_PENDING,
                StoreOrderTerminalOperation::STATE_LOCAL_CLOSING,
                StoreOrderTerminalOperation::STATE_FAILED_RETRYABLE,
                StoreOrderTerminalOperation::STATE_NEED_MANUAL,
                StoreOrderTerminalOperation::STATE_SUCCESS,
            ],
            StoreOrderTerminalOperation::STATE_BALANCE_PENDING => [
                StoreOrderTerminalOperation::STATE_CHANNEL_PENDING,
                StoreOrderTerminalOperation::STATE_LOCAL_CLOSING,
                StoreOrderTerminalOperation::STATE_FAILED_RETRYABLE,
                StoreOrderTerminalOperation::STATE_NEED_MANUAL,
                StoreOrderTerminalOperation::STATE_SUCCESS,
            ],
            StoreOrderTerminalOperation::STATE_CHANNEL_PENDING => [
                StoreOrderTerminalOperation::STATE_LOCAL_CLOSING,
                StoreOrderTerminalOperation::STATE_FAILED_RETRYABLE,
                StoreOrderTerminalOperation::STATE_NEED_MANUAL,
                StoreOrderTerminalOperation::STATE_SUCCESS,
            ],
            StoreOrderTerminalOperation::STATE_LOCAL_CLOSING => [
                StoreOrderTerminalOperation::STATE_SUCCESS,
                StoreOrderTerminalOperation::STATE_FAILED_RETRYABLE,
                StoreOrderTerminalOperation::STATE_NEED_MANUAL,
            ],
            StoreOrderTerminalOperation::STATE_FAILED_RETRYABLE => [
                StoreOrderTerminalOperation::STATE_INIT,
                StoreOrderTerminalOperation::STATE_BALANCE_PENDING,
                StoreOrderTerminalOperation::STATE_CHANNEL_PENDING,
                StoreOrderTerminalOperation::STATE_NEED_MANUAL,
            ],
            StoreOrderTerminalOperation::STATE_NEED_MANUAL => [
                StoreOrderTerminalOperation::STATE_INIT,
                StoreOrderTerminalOperation::STATE_CHANNEL_PENDING,
                StoreOrderTerminalOperation::STATE_LOCAL_CLOSING,
                StoreOrderTerminalOperation::STATE_SUCCESS,
            ],
            StoreOrderTerminalOperation::STATE_SUCCESS => [],
        ];
        return in_array($to, $map[$from] ?? [], true);
    }

    /**
     * 阶段6：将 today/week/日期串 解析为 [startTs, endTs]（含当日结束）。
     *
     * @param mixed $time
     * @return array{0:int,1:int}
     */
    public function resolveStatTimeRange($time): array
    {
        $endOfDay = static function (int $ts): int {
            return strtotime(date('Y-m-d 23:59:59', $ts));
        };
        $startOfDay = static function (int $ts): int {
            return strtotime(date('Y-m-d 00:00:00', $ts));
        };
        if (is_array($time) && count($time) >= 2) {
            $start = is_numeric($time[0]) ? (int)$time[0] : (int)strtotime((string)$time[0]);
            $end = is_numeric($time[1]) ? (int)$time[1] : (int)strtotime((string)$time[1]);
            if ($start > 0 && $end > 0) {
                if ($end === $startOfDay($end) || date('H:i:s', $end) === '00:00:00') {
                    $end = $endOfDay($end);
                }
                return [$start, $end];
            }
        }
        if (!is_string($time) || $time === '') {
            $now = time();
            return [$startOfDay($now), $endOfDay($now)];
        }
        switch ($time) {
            case 'today':
                $now = time();
                return [$startOfDay($now), $endOfDay($now)];
            case 'yesterday':
                $y = strtotime('-1 day');
                return [$startOfDay($y), $endOfDay($y)];
            case 'week':
                return [(int)strtotime('monday this week 00:00:00'), $endOfDay(time())];
            case 'month':
                return [(int)strtotime(date('Y-m-01 00:00:00')), $endOfDay(time())];
            case 'year':
                return [(int)strtotime(date('Y-01-01 00:00:00')), $endOfDay(time())];
            case 'last week':
                return [(int)strtotime('monday last week 00:00:00'), (int)strtotime('sunday last week 23:59:59')];
            case 'last month':
                return [(int)strtotime(date('Y-m-01 00:00:00', strtotime('first day of last month'))), (int)strtotime(date('Y-m-t 23:59:59', strtotime('last day of last month')))];
            default:
                break;
        }
        // 支持 2026/07/05-2026/07/19 与 2026-07-05 - 2026-07-19（禁止简单 explode('-') 拆坏 ISO 日期）
        if (preg_match('/^(\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2})\s*-\s*(\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2})$/', $time, $m)) {
            return $this->resolveStatTimeRange([$m[1], $m[2]]);
        }
        $one = (int)strtotime($time);
        if ($one > 0) {
            return [$startOfDay($one), $endOfDay($one)];
        }
        $now = time();
        return [$startOfDay($now), $endOfDay($now)];
    }

    /**
     * 阶段6 方案A：排除已有「成功退款终态」的售后单（防重复计算）。
     * 关联：link_refund_id / terminal_operation_id / store_order_id。
     */
    protected function historicalRefundNoSuccessfulTerminalSql(string $refundAlias = 'r'): string
    {
        $prefix = (string)(config('database.connections.mysql.prefix') ?: 'eb_');
        $table = $prefix . 'store_order_terminal_operation';
        $action = (int)StoreOrderTerminalOperation::ACTION_REFUND;
        $state = (int)StoreOrderTerminalOperation::STATE_SUCCESS;
        return "NOT EXISTS (
            SELECT 1 FROM `{$table}` t
            WHERE t.action_type = {$action}
              AND t.state = {$state}
              AND (
                t.link_refund_id = {$refundAlias}.id
                OR ({$refundAlias}.terminal_operation_id > 0 AND t.id = {$refundAlias}.terminal_operation_id)
                OR ({$refundAlias}.store_order_id > 0 AND t.store_order_id = {$refundAlias}.store_order_id)
              )
        )";
    }

    /**
     * 历史兼容金额字段：优先实际退款金额，否则申请退款金额。
     */
    protected function historicalRefundAmountExpr(string $alias = 'r'): string
    {
        return "IF({$alias}.refunded_price > 0, {$alias}.refunded_price, {$alias}.refund_price)";
    }

    /**
     * 阶段6 方案A：可验证的历史成功退款查询（只读兼容，不写回终态表）。
     * 条件：refund_type=6、未取消、未删、非供应商；且无对应成功退款终态。
     * 日期：按 add_time 归属——这是「历史兼容日期」，不是 refund_business_date。
     * 作废永不进入本查询。
     *
     * @param int[] $storeIds
     */
    protected function queryHistoricalSuccessfulRefundWithoutTerminal(array $storeIds, int $startTs, int $endTs, int $staffId = 0)
    {
        $q = Db::name('store_order_refund')->alias('r')
            ->where('r.refund_type', 6)
            ->where('r.is_cancel', 0)
            ->where('r.is_del', 0)
            ->where('r.supplier_id', 0)
            ->whereBetween('r.add_time', [$startTs, $endTs])
            ->whereRaw($this->historicalRefundNoSuccessfulTerminalSql('r'));
        if ($storeIds) {
            $q->whereIn('r.store_id', $storeIds);
        }
        if ($staffId > 0) {
            $q->join('store_order o', 'o.id = r.store_order_id')->where('o.staff_id', $staffId);
        }
        return $q;
    }

    /**
     * @param array<int, array{days?:string,num?:mixed}> $rows
     * @return array<int, array{days:string,num:float}>
     */
    protected function mergeRefundTrendBuckets(array ...$groups): array
    {
        $map = [];
        foreach ($groups as $rows) {
            foreach ($rows as $row) {
                $days = (string)($row['days'] ?? '');
                if ($days === '') {
                    continue;
                }
                $map[$days] = ($map[$days] ?? 0.0) + (float)($row['num'] ?? 0);
            }
        }
        $out = [];
        foreach ($map as $days => $num) {
            $out[] = ['days' => (string)$days, 'num' => round((float)$num, 2)];
        }
        return $out;
    }

    /**
     * 阶段6：成功退款金额。
     * 主口径：终态成功退款按 refund_business_date；
     * 兼容层：无成功终态的历史售后成功单按 add_time（历史兼容日期）归属。
     * 作废永不计入。
     *
     * @param int[] $storeIds
     */
    public function sumSuccessfulRefundAmountByBusinessDate(array $storeIds, int $startTs, int $endTs, int $staffId = 0): float
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if ($startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            return 0.0;
        }
        $q = Db::name('store_order_terminal_operation')->alias('t')
            ->where('t.action_type', StoreOrderTerminalOperation::ACTION_REFUND)
            ->where('t.state', StoreOrderTerminalOperation::STATE_SUCCESS)
            ->whereBetween('t.refund_business_date', [date('Y-m-d', $startTs), date('Y-m-d', $endTs)]);
        if ($storeIds) {
            $q->whereIn('t.store_id', $storeIds);
        }
        if ($staffId > 0) {
            $q->join('store_order o', 'o.id = t.store_order_id')->where('o.staff_id', $staffId);
        }
        $terminalAmt = (float)$q->sum('t.refund_amount');
        // 历史兼容：按 add_time 归属（非业务退款日期）
        $histRow = $this->queryHistoricalSuccessfulRefundWithoutTerminal($storeIds, $startTs, $endTs, $staffId)
            ->field('SUM(' . $this->historicalRefundAmountExpr('r') . ') as amt')
            ->find();
        $histAmt = (float)((array)($histRow ?: []))['amt'] ?? 0;
        return round($terminalAmt + $histAmt, 2);
    }

    /**
     * 阶段6：成功退款笔数（终态成功条数 + 无终态历史成功售后笔数）。
     *
     * @param int[] $storeIds
     */
    public function countSuccessfulRefundByBusinessDate(array $storeIds, int $startTs, int $endTs, int $staffId = 0): int
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if ($startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            return 0;
        }
        $q = Db::name('store_order_terminal_operation')->alias('t')
            ->where('t.action_type', StoreOrderTerminalOperation::ACTION_REFUND)
            ->where('t.state', StoreOrderTerminalOperation::STATE_SUCCESS)
            ->whereBetween('t.refund_business_date', [date('Y-m-d', $startTs), date('Y-m-d', $endTs)]);
        if ($storeIds) {
            $q->whereIn('t.store_id', $storeIds);
        }
        if ($staffId > 0) {
            $q->join('store_order o', 'o.id = t.store_order_id')->where('o.staff_id', $staffId);
        }
        $terminalCnt = (int)$q->count();
        $histCnt = (int)$this->queryHistoricalSuccessfulRefundWithoutTerminal($storeIds, $startTs, $endTs, $staffId)->count();
        return $terminalCnt + $histCnt;
    }

    /**
     * 阶段6：成功退款件数（终态关联售后 refund_num + 无终态历史售后 refund_num）。
     *
     * @param int[] $storeIds
     */
    public function sumSuccessfulRefundNumByBusinessDate(array $storeIds, int $startTs, int $endTs, int $staffId = 0): float
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if ($startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            return 0.0;
        }
        $refundIds = Db::name('store_order_terminal_operation')->alias('t')
            ->where('t.action_type', StoreOrderTerminalOperation::ACTION_REFUND)
            ->where('t.state', StoreOrderTerminalOperation::STATE_SUCCESS)
            ->whereBetween('t.refund_business_date', [date('Y-m-d', $startTs), date('Y-m-d', $endTs)])
            ->when($storeIds, function ($query) use ($storeIds) {
                $query->whereIn('t.store_id', $storeIds);
            })
            ->when($staffId > 0, function ($query) use ($staffId) {
                $query->join('store_order o', 'o.id = t.store_order_id')->where('o.staff_id', $staffId);
            })
            ->where('t.link_refund_id', '>', 0)
            ->column('t.link_refund_id');
        $refundIds = array_values(array_unique(array_filter(array_map('intval', $refundIds))));
        $terminalNum = 0.0;
        if ($refundIds) {
            $terminalNum = (float)Db::name('store_order_refund')
                ->whereIn('id', $refundIds)
                ->where('is_cancel', 0)
                ->where('is_del', 0)
                ->sum('refund_num');
        }
        $histNum = (float)$this->queryHistoricalSuccessfulRefundWithoutTerminal($storeIds, $startTs, $endTs, $staffId)
            ->sum('r.refund_num');
        return round($terminalNum + $histNum, 2);
    }

    /**
     * 阶段6：成功作废金额（独立指标，绝不进入退款统计），按 operated_at 日期归属。
     *
     * @param int[] $storeIds
     */
    public function sumSuccessfulVoidAmountByOperatedAt(array $storeIds, int $startTs, int $endTs): float
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if ($startTs <= 0 || $endTs <= 0 || $endTs < $startTs) {
            return 0.0;
        }
        $q = Db::name('store_order_terminal_operation')
            ->where('action_type', StoreOrderTerminalOperation::ACTION_VOID)
            ->where('state', StoreOrderTerminalOperation::STATE_SUCCESS)
            ->whereBetween('operated_at', [$startTs, $endTs]);
        if ($storeIds) {
            $q->whereIn('store_id', $storeIds);
        }
        return round((float)$q->sum('refund_amount'), 2);
    }

    /**
     * 阶段6：退款金额趋势。
     * 新记录：日/月按 refund_business_date；单日按小时用 operated_at。
     * 历史兼容：按 add_time（历史兼容日期）分桶，禁止与终态重复。
     *
     * @param int[] $storeIds
     * @return array<int, array{days:string,num:string|float}>
     */
    public function getSuccessfulRefundAmountTrend(array $storeIds, array $time, string $timeType, int $staffId = 0): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if (count($time) < 2) {
            return [];
        }
        $startTs = is_numeric($time[0]) ? (int)$time[0] : (int)strtotime((string)$time[0]);
        $endTs = is_numeric($time[1]) ? (int)$time[1] : (int)strtotime((string)$time[1]);
        if ($endTs === strtotime(date('Y-m-d 00:00:00', $endTs))) {
            $endTs = (int)strtotime(date('Y-m-d 23:59:59', $endTs));
        }
        $startDate = date('Y-m-d', $startTs);
        $endDate = date('Y-m-d', $endTs);
        $q = Db::name('store_order_terminal_operation')->alias('t')
            ->where('t.action_type', StoreOrderTerminalOperation::ACTION_REFUND)
            ->where('t.state', StoreOrderTerminalOperation::STATE_SUCCESS)
            ->whereBetween('t.refund_business_date', [$startDate, $endDate]);
        if ($storeIds) {
            $q->whereIn('t.store_id', $storeIds);
        }
        if ($staffId > 0) {
            $q->join('store_order o', 'o.id = t.store_order_id')->where('o.staff_id', $staffId);
        }
        if ($timeType === '%H') {
            $rows = $q->field("FROM_UNIXTIME(t.operated_at,'%H') as days, sum(t.refund_amount) as num")
                ->group("FROM_UNIXTIME(t.operated_at,'%H')")
                ->select()
                ->toArray();
            $histField = "FROM_UNIXTIME(r.add_time,'%H')";
        } elseif ($timeType === '%Y-%m') {
            $rows = $q->field("DATE_FORMAT(t.refund_business_date,'%Y-%m') as days, sum(t.refund_amount) as num")
                ->group("DATE_FORMAT(t.refund_business_date,'%Y-%m')")
                ->select()
                ->toArray();
            $histField = "FROM_UNIXTIME(r.add_time,'%Y-%m')";
        } else {
            $rows = $q->field('t.refund_business_date as days, sum(t.refund_amount) as num')
                ->group('t.refund_business_date')
                ->select()
                ->toArray();
            $histField = "FROM_UNIXTIME(r.add_time,'%Y-%m-%d')";
        }
        $histRows = $this->queryHistoricalSuccessfulRefundWithoutTerminal($storeIds, $startTs, $endTs, $staffId)
            ->field("{$histField} as days, SUM(" . $this->historicalRefundAmountExpr('r') . ') as num')
            ->group($histField)
            ->select()
            ->toArray();
        return $this->mergeRefundTrendBuckets($rows, $histRows);
    }

    /**
     * 阶段6：成功退款件数趋势（终态 + 历史兼容）。
     *
     * @param int[] $storeIds
     * @return array<int, array{days:string,num:float|int}>
     */
    public function getSuccessfulRefundNumTrend(array $storeIds, array $time, string $timeType, int $staffId = 0): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if (count($time) < 2) {
            return [];
        }
        $startTs = is_numeric($time[0]) ? (int)$time[0] : (int)strtotime((string)$time[0]);
        $endTs = is_numeric($time[1]) ? (int)$time[1] : (int)strtotime((string)$time[1]);
        if ($endTs === strtotime(date('Y-m-d 00:00:00', $endTs))) {
            $endTs = (int)strtotime(date('Y-m-d 23:59:59', $endTs));
        }
        $startDate = date('Y-m-d', $startTs);
        $endDate = date('Y-m-d', $endTs);
        $opsQ = Db::name('store_order_terminal_operation')->alias('t')
            ->where('t.action_type', StoreOrderTerminalOperation::ACTION_REFUND)
            ->where('t.state', StoreOrderTerminalOperation::STATE_SUCCESS)
            ->whereBetween('t.refund_business_date', [$startDate, $endDate])
            ->when($storeIds, function ($query) use ($storeIds) {
                $query->whereIn('t.store_id', $storeIds);
            })
            ->when($staffId > 0, function ($query) use ($staffId) {
                $query->join('store_order o', 'o.id = t.store_order_id')->where('o.staff_id', $staffId);
            })
            ->where('t.link_refund_id', '>', 0)
            ->field('t.id,t.link_refund_id,t.refund_business_date,t.operated_at');
        $ops = $opsQ->select()->toArray();
        $bucket = [];
        if ($ops) {
            $refundIds = array_values(array_unique(array_filter(array_map(static function ($row) {
                return (int)($row['link_refund_id'] ?? 0);
            }, $ops))));
            $numMap = $refundIds
                ? Db::name('store_order_refund')->whereIn('id', $refundIds)->where('is_cancel', 0)->where('is_del', 0)->column('refund_num', 'id')
                : [];
            foreach ($ops as $row) {
                $rid = (int)($row['link_refund_id'] ?? 0);
                $num = (float)($numMap[$rid] ?? 0);
                if ($timeType === '%H') {
                    $key = date('H', (int)($row['operated_at'] ?? 0));
                } elseif ($timeType === '%Y-%m') {
                    $key = date('Y-m', strtotime((string)$row['refund_business_date']));
                } else {
                    $key = (string)$row['refund_business_date'];
                }
                if ($key === '' || $key === '0') {
                    continue;
                }
                $bucket[$key] = ($bucket[$key] ?? 0) + $num;
            }
        }
        $terminalRows = [];
        foreach ($bucket as $days => $num) {
            $terminalRows[] = ['days' => (string)$days, 'num' => $num];
        }
        if ($timeType === '%H') {
            $histField = "FROM_UNIXTIME(r.add_time,'%H')";
        } elseif ($timeType === '%Y-%m') {
            $histField = "FROM_UNIXTIME(r.add_time,'%Y-%m')";
        } else {
            $histField = "FROM_UNIXTIME(r.add_time,'%Y-%m-%d')";
        }
        $histRows = $this->queryHistoricalSuccessfulRefundWithoutTerminal($storeIds, $startTs, $endTs, $staffId)
            ->field("{$histField} as days, SUM(r.refund_num) as num")
            ->group($histField)
            ->select()
            ->toArray();
        return $this->mergeRefundTrendBuckets($terminalRows, $histRows);
    }

    /**
     * 阶段6：成功退款笔数趋势（终态条数 + 历史兼容笔数）。
     *
     * @param int[] $storeIds
     * @return array<int, array{days:string,num:int|float}>
     */
    public function getSuccessfulRefundCountTrend(array $storeIds, array $time, string $timeType, int $staffId = 0): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if (count($time) < 2) {
            return [];
        }
        $startTs = is_numeric($time[0]) ? (int)$time[0] : (int)strtotime((string)$time[0]);
        $endTs = is_numeric($time[1]) ? (int)$time[1] : (int)strtotime((string)$time[1]);
        if ($endTs === strtotime(date('Y-m-d 00:00:00', $endTs))) {
            $endTs = (int)strtotime(date('Y-m-d 23:59:59', $endTs));
        }
        $startDate = date('Y-m-d', $startTs);
        $endDate = date('Y-m-d', $endTs);
        $q = Db::name('store_order_terminal_operation')->alias('t')
            ->where('t.action_type', StoreOrderTerminalOperation::ACTION_REFUND)
            ->where('t.state', StoreOrderTerminalOperation::STATE_SUCCESS)
            ->whereBetween('t.refund_business_date', [$startDate, $endDate]);
        if ($storeIds) {
            $q->whereIn('t.store_id', $storeIds);
        }
        if ($staffId > 0) {
            $q->join('store_order o', 'o.id = t.store_order_id')->where('o.staff_id', $staffId);
        }
        if ($timeType === '%H') {
            $rows = $q->field("FROM_UNIXTIME(t.operated_at,'%H') as days, count(*) as num")
                ->group("FROM_UNIXTIME(t.operated_at,'%H')")
                ->select()
                ->toArray();
            $histField = "FROM_UNIXTIME(r.add_time,'%H')";
        } elseif ($timeType === '%Y-%m') {
            $rows = $q->field("DATE_FORMAT(t.refund_business_date,'%Y-%m') as days, count(*) as num")
                ->group("DATE_FORMAT(t.refund_business_date,'%Y-%m')")
                ->select()
                ->toArray();
            $histField = "FROM_UNIXTIME(r.add_time,'%Y-%m')";
        } else {
            $rows = $q->field('t.refund_business_date as days, count(*) as num')
                ->group('t.refund_business_date')
                ->select()
                ->toArray();
            $histField = "FROM_UNIXTIME(r.add_time,'%Y-%m-%d')";
        }
        $histRows = $this->queryHistoricalSuccessfulRefundWithoutTerminal($storeIds, $startTs, $endTs, $staffId)
            ->field("{$histField} as days, COUNT(*) as num")
            ->group($histField)
            ->select()
            ->toArray();
        return $this->mergeRefundTrendBuckets($rows, $histRows);
    }

    /**
     * 阶段6：按售后单 ID 批量附上退款日期与实际操作时间。
     *
     * @param int[] $refundIds
     * @return array<int, array{refund_business_date:string,operated_at:int,operated_at_text:string}>
     */
    public function mapBusinessAndOperatedByRefundIds(array $refundIds): array
    {
        $refundIds = array_values(array_unique(array_filter(array_map('intval', $refundIds))));
        if (!$refundIds) {
            return [];
        }
        $rows = Db::name('store_order_terminal_operation')
            ->whereIn('link_refund_id', $refundIds)
            ->where('action_type', StoreOrderTerminalOperation::ACTION_REFUND)
            ->field('link_refund_id,refund_business_date,operated_at')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $rid = (int)($row['link_refund_id'] ?? 0);
            if ($rid <= 0 || isset($map[$rid])) {
                continue;
            }
            $opAt = (int)($row['operated_at'] ?? 0);
            $map[$rid] = [
                'refund_business_date' => (string)($row['refund_business_date'] ?? ''),
                'operated_at' => $opAt,
                'operated_at_text' => $opAt > 0 ? date('Y-m-d H:i:s', $opAt) : '',
            ];
        }
        return $map;
    }

    /**
     * 新整单退款申请写 request_scope=1（阶段 2 创建售后单时必须调用；禁止依赖列默认值）
     */
    public function markRefundAsWholeOrderRequest(int $refundId): void
    {
        if ($refundId <= 0) {
            OrderTerminalError::throw(OrderTerminalError::GENERIC_FAIL, 'refund id required');
        }
        $n = Db::name('store_order_refund')->where('id', $refundId)->update([
            'request_scope' => self::REQUEST_SCOPE_WHOLE_ORDER,
        ]);
        if ($n <= 0 && !Db::name('store_order_refund')->where('id', $refundId)->find()) {
            OrderTerminalError::throw(OrderTerminalError::GENERIC_FAIL, 'refund missing');
        }
    }

    protected function makeOperationNo(int $storeOrderId, int $actionType): string
    {
        $prefix = $actionType === StoreOrderTerminalOperation::ACTION_VOID ? 'VO' : 'RF';
        return sprintf('%s%d%s%s', $prefix, $storeOrderId, date('YmdHis'), substr(str_replace('.', '', uniqid('', true)), -6));
    }

    protected function isDuplicateKeyException(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        if (stripos($msg, 'Duplicate') !== false || stripos($msg, '1062') !== false) {
            return true;
        }
        $prev = $e->getPrevious();
        if ($prev) {
            $pmsg = $prev->getMessage();
            if (stripos($pmsg, 'Duplicate') !== false || stripos($pmsg, '1062') !== false) {
                return true;
            }
        }
        return false;
    }

    protected function isTruthySplitFlag($value): bool
    {
        if ($value === null || $value === '' || $value === false) {
            return false;
        }
        if (is_array($value)) {
            return count($value) > 0;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            return !in_array($v, ['0', 'false', 'off', 'no'], true);
        }
        return (int)$value !== 0;
    }

    protected function isEmptyCartIds($value): bool
    {
        if ($value === null || $value === '' || $value === false) {
            return true;
        }
        if (is_array($value)) {
            return count($value) === 0;
        }
        return false;
    }

    protected function isNonEmptyScalar($value): bool
    {
        if ($value === null || $value === '' || $value === false) {
            return false;
        }
        if (is_array($value)) {
            return count($value) > 0;
        }
        return true;
    }
}
