<?php
declare(strict_types=1);

namespace app\services\member;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ScopeResolver;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * Member authentication boundary for shared settlement, not a cashier session.
 * The member UID is an actor identity, distinguished by actor_type and the
 * immutable member_checkout source. No employee or cashier token is invented.
 */
final class MemberCommerceScope
{
    public static function create(int $uid, int $storeId): array
    {
        $member = Db::name('user')->where('uid', $uid)->where('status', 1)
            ->where('is_del', 0)->where(function ($query) {
                $query->whereNull('delete_time')->whereOr('delete_time', 0);
            })->find();
        $store = Db::name('system_store')->where('id', $storeId)->where('is_show', 1)->where('is_del', 0)->find();
        $org = (string)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
        if (!$member || !$store || (int)$org <= 0) throw new ValidateException('请选择有效的交易门店。');
        $tenant = CashierV3ScopeResolver::TENANT_SCOPE_ID;
        $name = trim((string)($member['real_name'] ?: $member['nickname'])) ?: '会员';
        $profile = ['actor_type' => 'member', 'actor_member_id' => $uid,
            'staff_name' => '会员自助·' . $name, 'name' => $name];
        $operator = new CashierV3OperatorScope($storeId, $uid, $org, $tenant);
        $data = new CashierV3DataScopeContext($uid, 0, $storeId, $tenant, $org, [$storeId],
            CashierV3DataScopeContext::MODE_STORES, [], false, '',
            hash('sha256', 'member-commerce:' . $uid . ':' . $storeId),
            ['cashier.v3.cashier', 'cashier.v3.cashier.checkout', 'cashier.v3.order_center'], $profile);
        return ['member' => $member, 'store' => $store, 'operator' => $operator, 'data' => $data];
    }
}
