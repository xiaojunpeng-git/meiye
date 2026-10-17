<?php

namespace app\services\report;

use app\services\cashier\v3\CashierV3ScopeResolver;
use think\exception\ValidateException;
use think\facade\Db;

/** 总部配置成交人数的原单现金门槛；历史版本只追加，旧订单不随调参漂移。 */
final class StoreDealPeopleThresholdPolicyServices
{
    private const TABLE = 'cashier_v3_deal_people_threshold_policy';
    private const TENANT_ID = CashierV3ScopeResolver::TENANT_SCOPE_ID;

    /** @return array{a_yuan:int,other_yuan:int,version:int,effective_at:int} */
    public function current(): array
    {
        $row = Db::name(self::TABLE)->where('tenant_id', self::TENANT_ID)
            ->order('version', 'desc')->find();
        return $row ? [
            'a_yuan' => intdiv((int)$row['a_threshold_cents'], 100),
            'other_yuan' => intdiv((int)$row['other_threshold_cents'], 100),
            'version' => (int)$row['version'], 'effective_at' => (int)$row['effective_at'],
        ] : ['a_yuan' => 1000, 'other_yuan' => 500, 'version' => 0, 'effective_at' => 0];
    }

    /** 两项门槛必须是正整数元；版本并发保护避免管理员互相覆盖。 */
    public function save($aYuan, $otherYuan, int $expectedVersion, int $adminId): array
    {
        foreach ([$aYuan, $otherYuan] as $value) {
            if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,7}$/D', (string)$value)) {
                throw new ValidateException('成交金额门槛必须是 1 至 99,999,999 的整数元');
            }
        }
        if ($adminId <= 0) throw new ValidateException('只有已登录总部管理员可以修改成交人数门槛');
        return Db::transaction(function () use ($aYuan, $otherYuan, $expectedVersion, $adminId): array {
            $last = Db::name(self::TABLE)->where('tenant_id', self::TENANT_ID)
                ->order('version', 'desc')->lock(true)->find();
            $version = (int)($last['version'] ?? 0);
            if ($version !== $expectedVersion) throw new ValidateException('设置已被其他管理员修改，请刷新后重试');
            if ($last && (int)$last['a_threshold_cents'] === (int)$aYuan * 100
                && (int)$last['other_threshold_cents'] === (int)$otherYuan * 100) return $this->current();
            $now = time();
            Db::name(self::TABLE)->insert([
                'tenant_id' => self::TENANT_ID, 'version' => $version + 1,
                'a_threshold_cents' => (int)$aYuan * 100,
                'other_threshold_cents' => (int)$otherYuan * 100,
                'effective_at' => $now, 'changed_by' => $adminId, 'created_at' => $now,
            ]);
            return $this->current();
        });
    }
}
