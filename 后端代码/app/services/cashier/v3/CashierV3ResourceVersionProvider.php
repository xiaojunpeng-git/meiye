<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\cashier\v3;

/**
 * 业务主表版本提供器。
 *
 * C2～C5 把某个 kind 的权威版本从中央登记表切换到业务主表时实现本接口。
 * 三个方法都必须在同一业务事务内被调用，实现方不得自行开启新事务。
 */
interface CashierV3ResourceVersionProvider
{
    /**
     * 判定对象的真实归属并返回 canonical scope。
     *
     * 实现要求：
     * - 必须用业务主表里的真实归属（例如房间的所属门店、会员的所属租户）构造 scope，
     *   不得直接回传操作人当前门店；
     * - 对象不存在，或存在但不在 $operatorScope 允许的操作范围内时，一律返回 null。
     *   调用方会把 null 统一翻译成「对象不存在或不可操作」，不回传当前版本，
     *   因此不会让 A 店通过版本冲突探测到 B 店对象是否存在。
     *
     * @return CashierV3ResourceScope|null
     */
    public function resolveScope(string $kind, string $resourceId, CashierV3OperatorScope $operatorScope);

    /**
     * 事务内加行锁并读取当前版本；不存在返回 null。
     *
     * 必须使用 SELECT ... FOR UPDATE（或等价的排他锁），否则乐观版本校验会失效。
     *
     * @return int|null
     */
    public function lockAndReadVersion(CashierV3ResourceScope $scope, string $kind, string $resourceId);

    /**
     * 事务内推进版本，返回推进后的新版本。
     *
     * 必须严格 +1：调用方会断言「新版本 == 锁定时版本 + 1」，不满足即整事务回滚。
     */
    public function bumpVersion(CashierV3ResourceScope $scope, string $kind, string $resourceId, string $action): int;
}
