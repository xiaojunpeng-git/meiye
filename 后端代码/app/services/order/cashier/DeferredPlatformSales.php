<?php
declare(strict_types=1);

namespace app\services\order\cashier;

/**
 * @deprecated 2026-07-18 平台销量已恢复为支付同事务 INC。
 * 保留空实现以免旧调用致命错误；push 若被误用将抛错，禁止再走非持久化队列。
 */
class DeferredPlatformSales
{
    public static function reset(): void
    {
        // no-op
    }

    public static function push(int $platformPid, int $num): void
    {
        if ($platformPid > 0 && $num > 0) {
            throw new \RuntimeException(
                'DeferredPlatformSales::push 已禁用：平台销量必须在支付同事务内更新'
            );
        }
    }

    public static function flush(): void
    {
        // no-op：销量已在事务内完成
    }
}
