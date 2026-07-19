<?php
declare(strict_types=1);

namespace app\services\order\cashier;

/**
 * 收银原子结账：非库存/资金安全副作用延后到支付事务提交后执行，
 * 缩短 store_order / 院装 SKU 持锁时间。
 *
 * 仍在支付事务内：扣库存/出库、核销权益、核销记录、院装扣料、订单 paid/status。
 */
class DeferredCashierPostWriteoff
{
    /** @var array<int, array> */
    private static $takes = [];

    /** @var array<int, array> notice payloads: [payload, type] */
    private static $notices = [];

    /** @var array<int, array> order.pay 等支付后副作用 */
    private static $paySideEffects = [];

    /** @var array<int, array> 延后业绩入账 */
    private static $yeji = [];

    public static function reset(): void
    {
        self::$takes = [];
        self::$notices = [];
        self::$paySideEffects = [];
        self::$yeji = [];
    }

    public static function pushTake(array $orderInfo): void
    {
        $oid = (int)($orderInfo['id'] ?? 0);
        if ($oid <= 0) {
            return;
        }
        self::$takes[$oid] = $orderInfo;
    }

    /**
     * @param array $payload
     * @param string $type notice 事件第二参
     */
    public static function pushNotice(array $payload, string $type): void
    {
        if ($type === '') {
            return;
        }
        self::$notices[] = [$payload, $type];
    }

    public static function pushPaySideEffects(array $orderInfo): void
    {
        $oid = (int)($orderInfo['id'] ?? 0);
        if ($oid <= 0) {
            return;
        }
        self::$paySideEffects[$oid] = $orderInfo;
    }

    /**
     * @param string $kind saveYeji|saveOrder
     * @param array $payload
     */
    public static function pushYeji(string $kind, array $payload): void
    {
        if ($kind === '' || !$payload) {
            return;
        }
        self::$yeji[] = [$kind, $payload];
    }

    /**
     * 投递队列执行，避免挡在收银 HTTP 尾延迟上；核销/院装已在支付事务内完成。
     */
    public static function flush(): void
    {
        $takes = self::$takes;
        $notices = self::$notices;
        $pays = self::$paySideEffects;
        $yeji = self::$yeji;
        self::$takes = [];
        self::$notices = [];
        self::$paySideEffects = [];
        self::$yeji = [];

        if (!$takes && !$notices && !$pays && !$yeji) {
            return;
        }
        $payload = [
            'takes' => $takes,
            'notices' => $notices,
            'pays' => $pays,
            'yeji' => $yeji,
        ];
        try {
            \app\jobs\order\CashierPostWriteoffFlushJob::dispatch([$payload]);
        } catch (\Throwable $e) {
            \think\facade\Log::error('DeferredCashierPostWriteoff dispatch fail: ' . $e->getMessage());
            try {
                app()->make(\app\jobs\order\CashierPostWriteoffFlushJob::class)->doJob($payload);
            } catch (\Throwable $e2) {
                \think\facade\Log::error('DeferredCashierPostWriteoff sync fallback fail: ' . $e2->getMessage());
            }
        }
    }
}
