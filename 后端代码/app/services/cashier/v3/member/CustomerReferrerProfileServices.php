<?php

namespace app\services\cashier\v3\member;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use think\facade\Db;

/**
 * 平台端会员资料的 V3 推荐人命令。user.spread_uid 是权威关系，生命周期投影
 * 只用于首次疗程成交后的不可变锁；平台不得通过旧 UserServices 旁路写入。
 */
final class CustomerReferrerProfileServices
{
    public function read(int $memberId, array $allowedStoreIds): array
    {
        $member = $this->findVisibleMember($memberId, $allowedStoreIds, false);
        return $this->projection($member);
    }

    public function update(
        int $memberId,
        $referrerMemberId,
        array $allowedStoreIds,
        int $adminId,
        array $adminInfo,
        string $idempotencyKey
    ): array {
        $idempotencyKey = trim($idempotencyKey);
        if (!preg_match('/^CMD-[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $idempotencyKey)) {
            throw new \InvalidArgumentException('操作标识无效，请刷新资料后重试。');
        }
        if (!is_int($referrerMemberId) && !is_string($referrerMemberId)
            || preg_match('/^(?:0|[1-9][0-9]*)$/D', (string)$referrerMemberId) !== 1) {
            throw new \InvalidArgumentException('推荐人资料无效，请重新选择。');
        }
        $referrerId = (int)$referrerMemberId;
        if ($adminId <= 0) throw new \RuntimeException('当前管理员身份无效，请重新登录。');

        return Db::transaction(function () use ($memberId, $referrerId, $allowedStoreIds, $adminId, $adminInfo, $idempotencyKey): array {
            $replayed = Db::name(CashierV3BusinessEventRecorder::EVENT_TABLE)
                ->where('command_idempotency_key', $idempotencyKey)
                ->where('source_type', 'platform-v3-member-referrer')
                ->lock(true)->find();
            if ($replayed) {
                return $this->read($memberId, $allowedStoreIds) + ['replayed' => true, 'eventNo' => (string)$replayed['event_no']];
            }

            $member = $this->findVisibleMember($memberId, $allowedStoreIds, true);
            if ($referrerId === $memberId) throw new \InvalidArgumentException('推荐人不能是顾客本人。');
            if ($referrerId > 0) $this->findVisibleMember($referrerId, $allowedStoreIds, true);
            $currentReferrerId = (int)($member['spread_uid'] ?? 0);
            $locked = $this->firstCourseLocked($memberId);
            if ($locked && $referrerId !== $currentReferrerId) {
                throw new \InvalidArgumentException('首次疗程卡成交后不能修改推荐人。');
            }
            if ($referrerId !== $currentReferrerId) {
                $affected = Db::name('user')->where('uid', $memberId)->update(['spread_uid' => $referrerId]);
                if ($affected === false) throw new \RuntimeException('推荐人保存失败，本次修改已取消。');
            }
            $current = Db::name('user')->where('uid', $memberId)->lock(true)->find();
            $event = $this->recordPlatformEvent($current ?: $member, $allowedStoreIds, $adminId, $adminInfo, $idempotencyKey);
            return $this->projection($current ?: $member) + ['replayed' => false, 'eventNo' => (string)$event['event_no']];
        });
    }

    private function findVisibleMember(int $memberId, array $allowedStoreIds, bool $lock): array
    {
        if ($memberId <= 0 || !$allowedStoreIds) throw new \InvalidArgumentException('该顾客不存在或当前不可操作。');
        $query = Db::name('user')->where('uid', $memberId);
        if ($lock) $query->lock(true);
        $member = $query->find();
        if (!$member || (int)($member['status'] ?? 1) !== 1 || (int)($member['is_del'] ?? 0) !== 0) {
            throw new \InvalidArgumentException('该顾客不存在、已停用或已注销，不能继续操作。');
        }
        $visible = Db::name('store_user')->where('uid', $memberId)->where('status', 1)->whereIn('store_id', $allowedStoreIds)->value('uid');
        if (!$visible) throw new \InvalidArgumentException('当前账号没有操作该顾客的权限。');
        return $member;
    }

    private function projection(array $member): array
    {
        $memberId = (int)($member['uid'] ?? 0);
        $referrerId = (int)($member['spread_uid'] ?? 0);
        $referrer = $referrerId > 0
            ? Db::name('user')->where('uid', $referrerId)->field('uid,real_name,nickname,phone,bar_code')->find()
            : [];
        $name = trim((string)($referrer['real_name'] ?? '')) ?: trim((string)($referrer['nickname'] ?? ''));
        return [
            'memberId' => $memberId,
            'referrerMemberId' => $referrerId,
            'referrerMemberName' => $name,
            'referrerMemberPhone' => (string)($referrer['phone'] ?? ''),
            'referrerMemberNo' => (string)($referrer['bar_code'] ?? ''),
            'referrerLocked' => $this->firstCourseLocked($memberId),
        ];
    }

    private function firstCourseLocked(int $memberId): bool
    {
        return $memberId > 0 && (int)Db::name('cashier_v3_customer_lifecycle_projection')
            ->where('member_id', $memberId)->max('first_course_completed_at') > 0;
    }

    private function recordPlatformEvent(array $member, array $allowedStoreIds, int $adminId, array $adminInfo, string $idempotencyKey): array
    {
        CashierV3TransactionGuard::assertInTransaction('platformMemberReferrer.recordEvent');
        $memberId = (int)$member['uid'];
        $storeId = (int)Db::name('store_user')->where('uid', $memberId)->where('status', 1)->whereIn('store_id', $allowedStoreIds)->order('store_id asc')->value('store_id');
        if ($storeId <= 0) throw new \RuntimeException('顾客门店权限发生变化，请刷新后重试。');
        // 业务事件始终以 organization_store 的实时绑定为准。不能使用
        // OrganizationScopeService::getStoreOrgId()，因为该兼容服务在组织切源
        // 尚未正式启用时会返回 0，而 V3 事件层仍会对照真实组织绑定 fail-closed。
        /** @var CashierV3ScopeResolver $scopeResolver */
        $scopeResolver = app()->make(CashierV3ScopeResolver::class);
        $operatorScope = $scopeResolver->operatorScope($storeId, $adminId);
        $organizationId = $operatorScope->organizationId();
        if ($organizationId === '') {
            throw new \RuntimeException('顾客所属门店缺少组织绑定，请完成组织配置后重试。');
        }
        $dataScope = new CashierV3DataScopeContext(
            $adminId, 0, $storeId, '0', $organizationId, $allowedStoreIds,
            CashierV3DataScopeContext::MODE_STORES, [], false, 'platform_admin_scope',
            'platform-referrer-v1', ['member.profile.referrer.write'], [
                'real_name' => (string)($adminInfo['real_name'] ?? ''),
                'account' => (string)($adminInfo['account'] ?? ''),
            ]
        );
        $recorder = new CashierV3BusinessEventRecorder();
        $execution = $recorder->newExecution('platform-v3-member-referrer', $idempotencyKey, $operatorScope, $dataScope, 'platform-profile');
        $lastVersion = (int)Db::name(CashierV3BusinessEventRecorder::EVENT_TABLE)
            ->where('aggregate_type', 'member')->where('aggregate_id', (string)$memberId)->max('aggregate_version');
        $referrer = $this->projection($member);
        $now = time();
        $event = $recorder->recordInTx($execution, [
            'required_event_types' => ['member.updated'],
            'allowed_event_types' => ['member.updated'],
            'event_rules' => ['member.updated' => [
                'min_count' => 1, 'max_count' => 1, 'aggregate_type' => 'member',
                'source_type' => 'platform-v3-member-referrer', 'aggregate_version' => null,
            ]],
            'consumers' => ['member.updated' => []],
        ], [
            'event_type' => 'member.updated', 'aggregate_type' => 'member', 'aggregate_id' => (string)$memberId,
            'aggregate_version' => max(1, $lastVersion + 1), 'member_id' => $memberId,
            'source_type' => 'platform-v3-member-referrer', 'source_id' => (string)$memberId,
            'occurred_at' => $now, 'settled_at' => $now,
            'aggregate_name_snapshot' => trim((string)($member['real_name'] ?? '')) ?: trim((string)($member['nickname'] ?? '')),
            'store_name_snapshot' => (string)Db::name('system_store')->where('id', $storeId)->value('name'),
            'payload' => [
                'member_id' => $memberId, 'changed_fields' => ['referrerMemberId'], 'source' => 'platform_v3',
                'referrer_member_id' => (int)$referrer['referrerMemberId'],
                'referrer_member_name_snapshot' => (string)$referrer['referrerMemberName'],
                'referrer_locked' => (bool)$referrer['referrerLocked'],
            ],
        ]);
        $recorder->assertRequiredPersistedInTx($execution, [
            'required_event_types' => ['member.updated'], 'allowed_event_types' => ['member.updated'],
            'event_rules' => ['member.updated' => ['min_count' => 1, 'max_count' => 1, 'aggregate_type' => 'member', 'source_type' => 'platform-v3-member-referrer', 'aggregate_version' => null]],
            'consumers' => ['member.updated' => []],
        ]);
        return $event;
    }
}
