<?php
declare(strict_types=1);

namespace app\services\order\terminal;

use app\listener\order\Refund as RefundListener;
use think\facade\Db;
use think\facade\Log;

/**
 * 退款副作用 Outbox（A2）：
 * - PENDING → CAS 领取 PROCESSING → 入队成功 → DISPATCHED
 * - 入队失败保持 PENDING/释放领取；消费者唯一键兜底至少一次投递
 */
class RefundSideEffectOutboxServices
{
    public const STATE_PENDING = 0;
    public const STATE_DISPATCHED = 1;
    public const STATE_PROCESSING = 2;

    public const STEP_STATUS_NOTIFY = 'status_notify';
    public const STEP_REFUND_EVENT = 'refund_event';
    public const STEP_RECHARGE_REFUND_EVENT = 'recharge_refund_event';
    public const STEP_VOID_STORE_FINANCE = 'void_store_finance';
    public const STEP_VOID_STAFF_FINANCE = 'void_staff_finance';
    public const STEP_VOID_CAPITAL_FLOW = 'void_capital_flow';

    public const CLAIM_LEASE_SEC = 30;

    public function enqueueInTx(string $operationNo, string $stepType, array $payload): array
    {
        $operationNo = trim($operationNo);
        $stepType = trim($stepType);
        if ($operationNo === '' || $stepType === '') {
            throw new \InvalidArgumentException('outbox requires operation_no and step_type');
        }
        $key = $operationNo . ':' . $stepType;
        $now = time();
        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $id = (int)Db::name('refund_side_effect_outbox')->insertGetId([
                'idempotency_key' => $key,
                'operation_no' => $operationNo,
                'step_type' => $stepType,
                'payload' => $json,
                'dispatch_state' => self::STATE_PENDING,
                'dispatch_owner' => '',
                'lease_until' => 0,
                'attempt_count' => 0,
                'last_error' => '',
                'add_time' => $now,
                'update_time' => $now,
            ]);
            $row = Db::name('refund_side_effect_outbox')->where('id', $id)->find();
            return $row ?: ['id' => $id, 'idempotency_key' => $key, 'dispatch_state' => self::STATE_PENDING];
        } catch (\JsonException $e) {
            throw new \RuntimeException('outbox payload json encode failed: ' . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            if (!$this->isDuplicate($e)) {
                throw $e;
            }
            $row = Db::name('refund_side_effect_outbox')->where('idempotency_key', $key)->find();
            if (!$row) {
                throw $e;
            }
            return $row;
        }
    }

    public function flushPending(string $operationNo, ?string $stepType = null): int
    {
        $owner = $this->newOwner('flush');
        $now = time();
        $build = function (int $state) use ($operationNo, $stepType, $now) {
            $q = Db::name('refund_side_effect_outbox')
                ->where('operation_no', $operationNo)
                ->where('dispatch_state', $state);
            if ($stepType !== null && $stepType !== '') {
                $q->where('step_type', $stepType);
            }
            if ($state === self::STATE_PROCESSING) {
                $q->where('lease_until', '<', $now);
            }
            return $q->select()->toArray();
        };
        $rows = array_merge($build(self::STATE_PENDING), $build(self::STATE_PROCESSING));
        $n = 0;
        foreach ($rows as $row) {
            if ($this->claimAndDispatch($row, $owner)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * @return array{scanned:int,dispatched:int,failed:int}
     */
    public function relayPending(int $limit = 100, string $operationNo = ''): array
    {
        $limit = max(1, min(500, $limit));
        $owner = $this->newOwner('relay');
        $now = time();
        $q = Db::name('refund_side_effect_outbox')
            ->where(function ($query) use ($now) {
                $query->where('dispatch_state', self::STATE_PENDING)
                    ->whereOr(function ($q2) use ($now) {
                        $q2->where('dispatch_state', self::STATE_PROCESSING)
                            ->where('lease_until', '<', $now);
                    });
            })
            ->order('id', 'asc')
            ->limit($limit);
        $operationNo = trim($operationNo);
        if ($operationNo !== '') {
            $q->where('operation_no', $operationNo);
        }
        $rows = $q->select()->toArray();
        $dispatched = 0;
        $failed = 0;
        foreach ($rows as $row) {
            if ($this->claimAndDispatch($row, $owner)) {
                $dispatched++;
            } else {
                $failed++;
            }
        }
        return ['scanned' => count($rows), 'dispatched' => $dispatched, 'failed' => $failed];
    }

    public function dispatchPayloadNow(string $stepType, array $payload): void
    {
        if ($stepType === self::STEP_STATUS_NOTIFY) {
            $this->dispatchStatusNotify($payload);
            return;
        }
        if ($stepType === self::STEP_REFUND_EVENT || $stepType === self::STEP_RECHARGE_REFUND_EVENT) {
            $this->dispatchRefundEvent($payload, $stepType);
            return;
        }
        if (in_array($stepType, [
            self::STEP_VOID_STORE_FINANCE,
            self::STEP_VOID_STAFF_FINANCE,
            self::STEP_VOID_CAPITAL_FLOW,
        ], true)) {
            $this->dispatchVoidFinance($payload, $stepType);
        }
    }

    /** @deprecated 使用 claimAndDispatch；保留给旧测试直接调用 */
    public function dispatchRow(array $row): bool
    {
        return $this->claimAndDispatch($row, $this->newOwner('row'));
    }

    public function claimAndDispatch(array $row, string $owner): bool
    {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            return false;
        }
        if (!$this->casClaim($id, $owner)) {
            return false;
        }
        $fresh = Db::name('refund_side_effect_outbox')->where('id', $id)->find();
        if (!$fresh) {
            return false;
        }
        try {
            $payload = json_decode((string)($fresh['payload'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->releaseClaim($id, $owner, 'bad payload json: ' . $e->getMessage());
            return false;
        }
        $step = (string)($fresh['step_type'] ?? '');
        try {
            $this->dispatchPayloadNow($step, is_array($payload) ? $payload : []);
            $affected = Db::name('refund_side_effect_outbox')
                ->where('id', $id)
                ->where('dispatch_state', self::STATE_PROCESSING)
                ->where('dispatch_owner', $owner)
                ->update([
                    'dispatch_state' => self::STATE_DISPATCHED,
                    'dispatch_owner' => '',
                    'lease_until' => 0,
                    'last_error' => '',
                    'update_time' => time(),
                ]);
            return $affected > 0;
        } catch (\Throwable $e) {
            Log::error('[refund_outbox_dispatch] id=' . $id . ' ' . $e->getMessage());
            $this->releaseClaim($id, $owner, $e->getMessage());
            return false;
        }
    }

    protected function casClaim(int $id, string $owner): bool
    {
        $now = time();
        $lease = $now + self::CLAIM_LEASE_SEC;
        $row = Db::name('refund_side_effect_outbox')->where('id', $id)->find();
        if (!$row) {
            return false;
        }
        $state = (int)($row['dispatch_state'] ?? -1);
        $leaseUntil = (int)($row['lease_until'] ?? 0);
        $claimable = $state === self::STATE_PENDING
            || ($state === self::STATE_PROCESSING && $leaseUntil < $now);
        if (!$claimable) {
            return false;
        }
        $affected = Db::name('refund_side_effect_outbox')
            ->where('id', $id)
            ->where('dispatch_state', $state)
            ->where('lease_until', $leaseUntil)
            ->update([
                'dispatch_state' => self::STATE_PROCESSING,
                'dispatch_owner' => $owner,
                'lease_until' => $lease,
                'attempt_count' => (int)($row['attempt_count'] ?? 0) + 1,
                'update_time' => $now,
            ]);
        return $affected > 0;
    }

    protected function releaseClaim(int $id, string $owner, string $err): void
    {
        Db::name('refund_side_effect_outbox')
            ->where('id', $id)
            ->where('dispatch_state', self::STATE_PROCESSING)
            ->where('dispatch_owner', $owner)
            ->update([
                'dispatch_state' => self::STATE_PENDING,
                'dispatch_owner' => '',
                'lease_until' => 0,
                'last_error' => mb_substr($err, 0, 500),
                'update_time' => time(),
            ]);
    }

    protected function newOwner(string $prefix): string
    {
        return $prefix . ':' . gethostname() . ':' . getmypid() . ':' . bin2hex(random_bytes(4));
    }

    protected function dispatchStatusNotify(array $payload): void
    {
        /** @var RefundSideEffectJobDispatcher $dispatcher */
        $dispatcher = app()->make(RefundSideEffectJobDispatcher::class);
        $dispatcher->dispatchStatusNotify($payload);
    }

    protected function dispatchRefundEvent(array $payload, string $stepType = self::STEP_REFUND_EVENT): void
    {
        $data = (array)($payload['data'] ?? []);
        $order = (array)($payload['order'] ?? []);
        $opNo = trim((string)($payload['operation_no'] ?? $data['operation_no'] ?? $order['terminal_operation_no'] ?? ''));
        if ($opNo !== '') {
            $order['terminal_operation_no'] = $opNo;
            $order['terminal_operation_id'] = (int)($payload['terminal_operation_id'] ?? $data['terminal_operation_id'] ?? 0);
            $order['terminal_store_finance_key'] = $opNo . ':store_finance';
            $order['terminal_staff_finance_key'] = $opNo . ':staff_finance';
            $order['terminal_capital_flow_key'] = $opNo . ':capital_flow';
            $order['terminal_product_log_key'] = $opNo . ':product_log';
            $order['terminal_refund_notice_key'] = $opNo . ':refund_notice';
            $data['operation_no'] = $opNo;
            $data['terminal_operation_id'] = $order['terminal_operation_id'];
            $data['terminal_idempotency_key'] = $order['terminal_refund_notice_key'];
        }
        if (!empty($payload['inject_fail_redis_enqueue'])) {
            $data['inject_fail_redis_enqueue'] = 1;
            $order['inject_fail_redis_enqueue'] = 1;
        }
        if ($stepType === self::STEP_RECHARGE_REFUND_EVENT) {
            $data['recharge_refund'] = 1;
            $order['recharge_refund'] = 1;
        }
        /** @var RefundListener $listener */
        $listener = app()->make(RefundListener::class);
        if ($stepType === self::STEP_RECHARGE_REFUND_EVENT) {
            $listener->handleRechargeRefundOutbox([$data, $order]);
        } else {
            $listener->handleTerminalOutbox([$data, $order]);
        }
    }

    protected function dispatchVoidFinance(array $payload, string $stepType): void
    {
        $order = (array)($payload['order'] ?? []);
        $data = (array)($payload['data'] ?? []);
        $opNo = trim((string)($payload['operation_no'] ?? $data['operation_no'] ?? $order['terminal_operation_no'] ?? ''));
        if ($opNo !== '') {
            $order['terminal_operation_no'] = $opNo;
            $order['terminal_operation_id'] = (int)($payload['terminal_operation_id'] ?? $data['terminal_operation_id'] ?? 0);
            $order['terminal_store_finance_key'] = $opNo . ':store_finance_void';
            $order['terminal_staff_finance_key'] = $opNo . ':staff_finance_void';
            $order['terminal_capital_flow_key'] = $opNo . ':capital_flow_void';
        }
        /** @var RefundSideEffectJobDispatcher $dispatcher */
        $dispatcher = app()->make(RefundSideEffectJobDispatcher::class);
        $dispatcher->dispatchVoidFinanceJob($order, $data, $stepType);
    }

    protected function isDuplicate(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        return stripos($msg, 'Duplicate') !== false || stripos($msg, '1062') !== false;
    }
}
