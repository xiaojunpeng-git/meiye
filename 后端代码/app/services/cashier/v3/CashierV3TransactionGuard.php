<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\cashier\v3;

use think\facade\Db;

/**
 * 活动事务门禁。
 *
 * 任何命名为 InTx、执行 SELECT ... FOR UPDATE 或推进版本的方法，都必须先过这道门。
 * 事务外执行 FOR UPDATE 在 MySQL 里是「立即加锁、立即释放」，看起来成功，
 * 实际上完全没有互斥效果——两个并发请求都会读到同一个旧版本并各自通过校验。
 *
 * 判定依据是 PDO 连接的真实事务状态（inTransaction()），不是自己维护的布尔值，
 * 也不使用任何 static：Swoole 常驻进程下自维护的标志会跨请求残留。
 */
class CashierV3TransactionGuard
{
    /**
     * @param string $operation 用于错误明细定位，不展示给用户
     * @throws CashierV3CommandException
     */
    public static function assertInTransaction(string $operation): void
    {
        if (!self::isInTransaction()) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_TRANSACTION_REQUIRED,
                '系统内部处理顺序异常，本次操作已取消，请重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['operation' => $operation]
            );
        }
    }

    /**
     * 读取当前默认连接的真实事务状态。
     */
    public static function isInTransaction(): bool
    {
        $connection = Db::connect();
        if (!method_exists($connection, 'getPdo')) {
            // 无法确认事务状态时按「不在事务内」处理：宁可拒绝，也不放行无锁写入
            return false;
        }
        $pdo = $connection->getPdo();
        // 尚未建立连接时 getPdo() 返回 false，此时必然不在事务内
        return $pdo instanceof \PDO && $pdo->inTransaction();
    }
}
