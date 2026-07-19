<?php
declare(strict_types=1);

namespace app\services\order\terminal;

use think\facade\Db;

/**
 * 退款下游副作用恰好一次：唯一键 claim，重复消费直接跳过。
 */
class RefundSideEffectOnceServices
{
    public const STEP_STATUS_NOTIFY = 'status_notify';
    public const STEP_STORE_FINANCE = 'store_finance';
    public const STEP_STAFF_FINANCE = 'staff_finance';
    public const STEP_CAPITAL_FLOW = 'capital_flow';
    public const STEP_PRODUCT_LOG = 'product_log';
    public const STEP_REFUND_NOTICE = 'refund_notice';
    public const STEP_DEBT_REVERSE = 'debt_reverse';
    public const STEP_DEBT_VOID = 'debt_void';
    public const STEP_COUPON_ROLLBACK = 'coupon_rollback';
    public const STEP_WRITEOFF_CHILD = 'writeoff_child';
    public const STEP_YEJI_ROLLBACK = 'yeji_rollback';
    public const STEP_GIFT_REVOKE = 'gift_revoke';
    public const STEP_INVOICE_REFUND = 'invoice_refund';
    public const STEP_SUPPLIER_FINANCE = 'supplier_finance';

    public static function key(string $operationNo, string $stepType): string
    {
        $operationNo = trim($operationNo);
        $stepType = trim($stepType);
        if ($operationNo === '' || $stepType === '') {
            return '';
        }
        return $operationNo . ':' . $stepType;
    }

    /**
     * @return bool true=首次获得执行权；false=已执行过应跳过；空键=不拦截（兼容旧路径）
     */
    public function claim(string $idempotencyKey, string $operationNo = '', string $stepType = ''): bool
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            return true;
        }
        if ($operationNo === '' || $stepType === '') {
            $parts = explode(':', $idempotencyKey, 2);
            $operationNo = $parts[0] ?? '';
            $stepType = $parts[1] ?? '';
        }
        try {
            $this->insertOnceRow($idempotencyKey, $operationNo, $stepType);
            return true;
        } catch (RefundSideEffectAlreadyExecuted $e) {
            return false;
        }
    }

    /**
     * 在同一事务内 claim + 写业务；仅 once 表唯一冲突视为重复消费。
     * @return bool true=本次执行或无键兼容执行；false=重复消费已跳过
     */
    public function runOnce(string $idempotencyKey, callable $fn, string $operationNo = '', string $stepType = ''): bool
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            $fn();
            return true;
        }
        if ($operationNo === '' || $stepType === '') {
            $parts = explode(':', $idempotencyKey, 2);
            $operationNo = $parts[0] ?? '';
            $stepType = $parts[1] ?? '';
        }
        try {
            Db::transaction(function () use ($idempotencyKey, $fn, $operationNo, $stepType) {
                $this->insertOnceRow($idempotencyKey, $operationNo, $stepType);
                $fn();
            });
            return true;
        } catch (RefundSideEffectAlreadyExecuted $e) {
            return false;
        }
    }

    /**
     * 仅包裹 once 表插入；唯一冲突 → AlreadyExecuted；其它异常原样抛出。
     */
    protected function insertOnceRow(string $idempotencyKey, string $operationNo, string $stepType): void
    {
        try {
            Db::name('refund_side_effect_once')->insert([
                'idempotency_key' => $idempotencyKey,
                'operation_no' => $operationNo,
                'step_type' => $stepType,
                'add_time' => time(),
            ]);
        } catch (\Throwable $e) {
            if ($this->isOnceTableDuplicate($e)) {
                throw new RefundSideEffectAlreadyExecuted(
                    'refund side effect already executed: ' . $idempotencyKey,
                    0,
                    $e
                );
            }
            throw $e;
        }
    }

    public function exists(string $idempotencyKey): bool
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            return false;
        }
        return (int)Db::name('refund_side_effect_once')->where('idempotency_key', $idempotencyKey)->count() > 0;
    }

    /**
     * 仅识别 once 表自身 uk 冲突（避免业务表 1062 被当成重复消费）。
     */
    protected function isOnceTableDuplicate(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        if (stripos($msg, '1062') === false && stripos($msg, 'Duplicate') === false) {
            return false;
        }
        // MySQL 通常带索引名；同时要求出现 once 表名，防止误伤业务 1062
        if (stripos($msg, 'uk_idempotency_key') !== false) {
            return true;
        }
        if (stripos($msg, 'refund_side_effect_once') !== false) {
            return true;
        }
        // ThinkORM 有时只给 Duplicate entry 'xxx' for key 'uk_idempotency_key'
        if (stripos($msg, "for key 'uk_idempotency_key'") !== false
            || stripos($msg, 'for key `uk_idempotency_key`') !== false) {
            return true;
        }
        return false;
    }
}
