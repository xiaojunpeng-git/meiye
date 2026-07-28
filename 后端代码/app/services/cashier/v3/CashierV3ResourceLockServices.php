<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\cashier\v3;

use think\facade\Db;

/**
 * 固定 kind 全序加锁器。
 *
 * MySQL 5.6／5.7 没有 SKIP LOCKED / NOWAIT，唯一可靠的防死锁手段是让所有写命令
 * 按同一顺序取锁。凡是需要在事务内最终锁定的对象——余额、次数池、库存、房间、
 * 房间时段、人员时段、服务单、预约、收银工作台——都必须通过本服务取锁，
 * 不允许各领域自行决定加锁顺序。
 *
 * 加锁目标必须带 canonical scope：不同门店的同 id 房间是两个对象，
 * 少了 scope 就会把 A 店的锁当成 B 店的锁。
 *
 * 本轮（C1-A）交付契约与单元级验证：
 * - plan() 为纯函数，可直接断言排序与去重；
 * - lockAllInTx() 需要各领域先注册自己的行锁实现（业务主表是业务权威源，
 *   C1 不替 C2／C3 猜测表名与主键）。
 */
class CashierV3ResourceLockServices
{
    /**
     * @var array<string,callable> kind => function(CashierV3ResourceScope $scope, string $kind, string $resourceId): bool
     *   命中返回 true
     */
    protected $lockers = [];

    /** @var bool */
    protected $frozen = false;

    /** @var string C1 权威锁说明：与 version lock 合同对齐，禁止平行未接线合同 */
    protected $deferredReason = '';

    /**
     * C1：明确权威锁由 ResourceVersionServices::lockAndAssert 承担，
     * 本类仅保留领域行锁注册能力，不得声称生产 gateway 走平行第二套锁。
     */
    public function markDeferredToVersionLock(string $reason): void
    {
        $this->deferredReason = $reason;
    }

    public function deferredReason(): string
    {
        return $this->deferredReason;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    /**
     * C2／C3 注册自己 kind 的行锁实现。实现内必须使用 lock(true)／FOR UPDATE，
     * 且不得开启或提交事务。重复注册在写入前拒绝。
     */
    public function registerLocker(string $kind, callable $locker): void
    {
        if ($this->frozen) {
            throw new \LogicException('resource locker registry 已 freeze');
        }
        CashierV3ResourceKindCatalog::assertKnown($kind);
        if (isset($this->lockers[$kind])) {
            throw new \LogicException(sprintf('resource locker %s 重复注册', $kind));
        }
        $this->lockers[$kind] = $locker;
    }

    public function hasLocker(string $kind): bool
    {
        return isset($this->lockers[$kind]);
    }

    /**
     * 生成加锁计划：按 kind 全序 + scope + resource_id 升序排序并去重。
     *
     * @param array<int,array{kind:string,id:string|int,scope:CashierV3ResourceScope}> $targets
     * @return array<int,array{kind:string,id:string,scope:CashierV3ResourceScope,order:int}>
     */
    public function plan(array $targets): array
    {
        $normalized = [];
        $seen = [];
        foreach ($targets as $target) {
            $kind = is_array($target) ? trim((string)($target['kind'] ?? '')) : '';
            $rawId = is_array($target) ? ($target['id'] ?? null) : null;
            $scope = is_array($target) ? ($target['scope'] ?? null) : null;
            if ($kind === '' || $rawId === null || is_array($rawId) || is_bool($rawId)) {
                throw CashierV3CommandException::invalidContext('加锁目标格式无效。', ['kind' => $kind]);
            }
            if (!$scope instanceof CashierV3ResourceScope) {
                // 没有 scope 的加锁目标会把不同门店的同 id 对象当成同一个
                throw new CashierV3CommandException(
                    CashierV3ResultCode::RESOURCE_SCOPE_UNRESOLVED,
                    '系统未能确定该对象的归属范围，请刷新当前工作台后重试。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['kind' => $kind]
                );
            }
            $id = trim((string)$rawId);
            if ($id === '' || $id === '0') {
                throw CashierV3CommandException::invalidContext('加锁目标缺少对象标识。', ['kind' => $kind]);
            }
            $order = CashierV3ResourceKindCatalog::lockOrderOf($kind);
            $key = $scope->signature() . ':' . $kind . ':' . $id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $normalized[] = ['kind' => $kind, 'id' => $id, 'scope' => $scope, 'order' => $order, 'key' => $key];
        }

        usort($normalized, function (array $left, array $right) {
            if ($left['order'] !== $right['order']) {
                return $left['order'] < $right['order'] ? -1 : 1;
            }
            return strcmp($left['key'], $right['key']);
        });

        return array_values($normalized);
    }

    /**
     * 断言一份加锁序列符合全序。用于单元测试与领域自检。
     */
    public function isMonotonic(array $plan): bool
    {
        $previousOrder = -1;
        $previousKey = '';
        foreach ($plan as $item) {
            $order = CashierV3ResourceKindCatalog::lockOrderOf((string)$item['kind']);
            $scope = $item['scope'] ?? null;
            $key = ($scope instanceof CashierV3ResourceScope ? $scope->signature() : '') . ':'
                . (string)$item['kind'] . ':' . (string)$item['id'];
            if ($order < $previousOrder) {
                return false;
            }
            if ($order === $previousOrder && strcmp($key, $previousKey) < 0) {
                return false;
            }
            $previousOrder = $order;
            $previousKey = $key;
        }
        return true;
    }

    /**
     * 在调用方已开启的事务内按全序逐个加锁。
     *
     * @param array<int,array{kind:string,id:string|int,scope:CashierV3ResourceScope}> $targets
     * @return array<int,array> 实际加锁顺序
     */
    public function lockAllInTx(array $targets): array
    {
        CashierV3TransactionGuard::assertInTransaction('lockAllInTx');

        $plan = $this->plan($targets);
        foreach ($plan as $item) {
            $kind = $item['kind'];
            if (!isset($this->lockers[$kind])) {
                throw CashierV3CommandException::invalidContext(
                    '该对象类型尚未接入事务内锁定：' . $kind,
                    ['kind' => $kind, 'id' => $item['id']]
                );
            }
            $hit = call_user_func($this->lockers[$kind], $item['scope'], $kind, $item['id']);
            if (!$hit) {
                throw CashierV3ScopeResolver::notFound($kind, $item['id']);
            }
        }
        return $plan;
    }

    /**
     * 集中版本登记表的通用行锁实现，供尚未具备版本列的 kind 直接复用。
     */
    public function centralVersionRowLocker(): callable
    {
        return function (CashierV3ResourceScope $scope, string $kind, string $resourceId): bool {
            $row = Db::name(CashierV3ResourceVersionServices::TABLE)
                ->where('scope_type', $scope->type())
                ->where('scope_id', $scope->id())
                ->where('resource_kind', $kind)
                ->where('resource_id', $resourceId)
                ->lock(true)
                ->find();
            return !empty($row);
        };
    }
}
