<?php

namespace app\services\cashier\v3\cashier;

use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3CrossStoreEntitlementPolicy;
use app\services\cashier\v3\CashierV3DataScopedVersionProvider;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/**
 * 旧会员、卡实例与权益明细的只读单调版本适配器。
 *
 * 旧写路径尚未统一推进 V3 版本，因此只有投影成功事务可以依据权威指纹创建或
 * 推进影子版本。写命令发现指纹已变化时只返回冲突并要求重查，绝不在随后回滚
 * 的命令事务里签发临时版本。A1 不允许通过本 provider 修改旧权益。
 */
final class CashierV3EntitlementResourceVersionProvider implements CashierV3DataScopedVersionProvider
{
    public const VERSION_TABLE = 'cashier_v3_entitlement_resource_version';
    public const KINDS = ['member', 'member_benefit_pool', 'card_holder', 'debt_record'];

    /** @var CashierV3CashierReadinessGuard */
    private $readiness;

    /** @var CashierV3CardRuleEntitlementAuthorityServices */
    private $cardRules;

    public function __construct(
        CashierV3CashierReadinessGuard $readiness,
        CashierV3CardRuleEntitlementAuthorityServices $cardRules = null
    )
    {
        $this->readiness = $readiness;
        $this->cardRules = $cardRules ?: new CashierV3CardRuleEntitlementAuthorityServices();
    }

    public function resolveScopeWithDataScope(
        string $kind,
        string $resourceId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ) {
        $this->assertSupported($kind);
        if (!$this->isCanonicalPositiveId($resourceId) || !$this->allowsBusinessSelection($dataScope)) {
            return null;
        }
        $authority = $this->loadAuthority($kind, $resourceId, $operatorScope, false);
        if ($authority === null) {
            return null;
        }
        return CashierV3ResourceScope::of(
            CashierV3ResourceScope::TYPE_TENANT,
            $operatorScope->tenantId() !== '' ? $operatorScope->tenantId() : CashierV3ScopeResolver::TENANT_SCOPE_ID
        );
    }

    public function lockAndReadVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        CashierV3DataScopeContext $dataScope
    ) {
        CashierV3TransactionGuard::assertInTransaction('entitlementVersion:' . $kind);
        $this->assertSupported($kind);
        if (!$this->validTenantScope($scope, $dataScope)
            || !$this->isCanonicalPositiveId($resourceId)
            || !$this->allowsBusinessSelection($dataScope)) {
            return null;
        }
        $operatorScope = $this->operatorScopeFromDataScope($dataScope);
        $authority = $this->loadAuthority($kind, $resourceId, $operatorScope, true);
        if ($authority === null) {
            return null;
        }
        $row = $this->lockShadowRow($kind, $resourceId);
        if (!$row || (int)($row['member_id'] ?? 0) !== (int)$authority['member_id']) {
            throw CashierV3CommandException::versionConflict(
                '该会员权益已经变化，请重新打开后选择。',
                ['kind' => $kind, 'id' => $resourceId, 'reason' => 'projection_version_missing']
            );
        }
        $current = (int)($row['current_version'] ?? 0);
        if ($current <= 0) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '会员权益版本异常，请联系管理员核对。',
                CashierV3ResultCode::STATUS_FAILED,
                ['kind' => $kind, 'id' => $resourceId]
            );
        }
        if (!hash_equals((string)($row['source_fingerprint'] ?? ''), (string)$authority['fingerprint'])) {
            throw CashierV3CommandException::versionConflict(
                '该会员权益已经变化，请重新打开后选择。',
                ['kind' => $kind, 'id' => $resourceId, 'reason' => 'legacy_source_changed']
            );
        }
        return $current;
    }

    public function bumpVersionWithDataScope(
        CashierV3ResourceScope $scope,
        string $kind,
        string $resourceId,
        string $action,
        CashierV3DataScopeContext $dataScope
    ): int {
        CashierV3TransactionGuard::assertInTransaction('entitlementVersionBump:' . $kind);
        // Card operation has two authoritative current-right mutations:
        // holder state (transfer/enable/disable/extension) and selected
        // benefit pools (project replacement).  The Gateway has already
        // locked and version-checked this exact set before the writer runs.
        $memberMutation = $kind === 'member'
            && in_array($action, ['update-member', 'deactivate-member'], true);
        $cardMutation = $action === 'submit-card-operation'
            && in_array($kind, ['card_holder', 'member_benefit_pool'], true);
        $debtMutation = $action === 'submit-debt-repayment' && $kind === 'debt_record';
        if (!$memberMutation && !$cardMutation && !$debtMutation) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '会员权益写入尚未接入统一版本推进，本次操作已停止。',
                CashierV3ResultCode::STATUS_FAILED,
                ['kind' => $kind, 'action' => $action, 'reason' => 'a1_entitlement_provider_read_only']
            );
        }
        if (!$this->validTenantScope($scope, $dataScope)) {
            return null;
        }
        $operatorScope = $this->operatorScopeFromDataScope($dataScope);
        $authority = $this->loadAuthority(
            $kind,
            $resourceId,
            $operatorScope,
            true,
            false,
            $action === 'deactivate-member'
        );
        if ($authority === null) {
            throw CashierV3ScopeResolver::notFound($kind, $resourceId);
        }
        $row = $this->lockShadowRow($kind, $resourceId);
        if (!$row || (int)($row['current_version'] ?? 0) <= 0) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '会员权益版本异常，请刷新后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['kind' => $kind, 'id' => $resourceId, 'reason' => 'card_operation_shadow_missing']
            );
        }
        $current = (int)$row['current_version'];
        $affected = Db::name(self::VERSION_TABLE)
            ->where('resource_kind', $kind)
            ->where('resource_id', $resourceId)
            ->where('current_version', $current)
            ->update([
                'member_id' => (int)$authority['member_id'],
                'source_fingerprint' => (string)$authority['fingerprint'],
                'current_version' => $current + 1,
                'last_action' => $action,
                'update_time' => time(),
            ]);
        if ((int)$affected !== 1) {
            throw CashierV3CommandException::versionConflict(
                '该会员权益已被其他操作更新，请重新打开后选择。',
                ['kind' => $kind, 'id' => $resourceId, 'reason' => 'card_operation_shadow_update_race']
            );
        }
        return $current + 1;
    }

    /**
     * 投影专用：在独立成功事务内同步指纹并返回可公开的正版本。
     */
    public function synchronizeProjectionVersion(
        string $kind,
        string $resourceId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        bool $allowDisabledBenefitPool = false
    ): int {
        CashierV3TransactionGuard::assertInTransaction('entitlementProjectionVersion:' . $kind);
        $this->assertSupported($kind);
        if (!$this->isCanonicalPositiveId($resourceId) || !$this->allowsBusinessSelection($dataScope)) {
            throw CashierV3ScopeResolver::notFound($kind, $resourceId);
        }
        $authority = $this->loadAuthority(
            $kind,
            $resourceId,
            $operatorScope,
            true,
            $allowDisabledBenefitPool
        );
        if ($authority === null) {
            throw CashierV3ScopeResolver::notFound($kind, $resourceId);
        }
        $row = $this->lockShadowRow($kind, $resourceId);
        if (!$row) {
            try {
                Db::name(self::VERSION_TABLE)->insert([
                    'resource_kind' => $kind,
                    'resource_id' => $resourceId,
                    'member_id' => (int)$authority['member_id'],
                    'source_fingerprint' => (string)$authority['fingerprint'],
                    'current_version' => 1,
                    'last_action' => 'legacy_sync_init',
                    'add_time' => time(),
                    'update_time' => time(),
                ]);
                return 1;
            } catch (\Throwable $exception) {
                if (!$this->isDuplicateResourceKey($exception)) {
                    throw $exception;
                }
                $row = $this->lockShadowRow($kind, $resourceId);
                if (!$row) {
                    throw $exception;
                }
            }
        }
        if ((int)($row['member_id'] ?? 0) !== (int)$authority['member_id']) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::RESOURCE_NOT_FOUND,
                '该会员权益不存在或当前不可见。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'entitlement_shadow_member_mismatch']
            );
        }
        $current = (int)($row['current_version'] ?? 0);
        if ($current <= 0) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '会员权益版本异常，请联系管理员核对。',
                CashierV3ResultCode::STATUS_FAILED
            );
        }
        if (hash_equals((string)($row['source_fingerprint'] ?? ''), (string)$authority['fingerprint'])) {
            return $current;
        }
        $affected = Db::name(self::VERSION_TABLE)
            ->where('resource_kind', $kind)
            ->where('resource_id', $resourceId)
            ->where('current_version', $current)
            ->update([
                'source_fingerprint' => (string)$authority['fingerprint'],
                'current_version' => $current + 1,
                'last_action' => 'legacy_source_changed',
                'update_time' => time(),
            ]);
        if ((int)$affected !== 1) {
            throw CashierV3CommandException::versionConflict(
                '该会员权益已被其他操作更新，请重新打开后选择。',
                ['kind' => $kind, 'id' => $resourceId]
            );
        }
        return $current + 1;
    }

    /** @return array{member_id:int,fingerprint:string}|null */
    private function loadAuthority(
        string $kind,
        string $resourceId,
        CashierV3OperatorScope $operatorScope,
        bool $lock,
        bool $allowDisabledBenefitPool = false,
        bool $allowInactiveMember = false
    ) {
        $id = (int)$resourceId;
        if ($kind === 'member') {
            $query = Db::name('user')
                ->field('uid,nickname,real_name,phone,avatar,status,is_del,delete_time')
                ->where('uid', $id);
            if ($lock) {
                $query->lock(true);
            }
            $row = $query->find();
            if (!$row || (!$allowInactiveMember && !$this->activeMember($row))) {
                return null;
            }
            return [
                'member_id' => (int)$row['uid'],
                'fingerprint' => $this->fingerprint([
                    'uid' => (int)$row['uid'],
                    'status' => (int)$row['status'],
                    'is_del' => (int)$row['is_del'],
                    'delete_time' => (string)($row['delete_time'] ?? ''),
                ]),
            ];
        }

        if ($kind === 'card_holder') {
            $query = Db::name('user_card_holder')
                ->field('id,uid,oid,card_name,card_no,store_id,product_type,write_times,write_surplus_times,write_start,write_end,is_del')
                ->where('id', $id)
                ->where('is_del', 0);
            if ($lock) {
                $query->lock(true);
            }
            $holder = $query->find();
            if (!$holder) {
                return null;
            }
            $order = $this->loadActiveOrder((int)$holder['oid'], 0, $lock);
            if (!$order
                || !$this->allowsSourceStore((int)$holder['store_id'], $operatorScope)
                || !$this->allowsSourceStore((int)$order['store_id'], $operatorScope)) {
                return null;
            }
            $state = $this->cardOperationState((int)$holder['id'], $lock);
            if ($state !== null) {
                if ((int)($state['origin_order_id'] ?? 0) !== (int)$holder['oid']
                    || (int)($state['current_member_id'] ?? 0) !== (int)$holder['uid']
                    || !in_array((string)($state['card_status'] ?? ''), ['enabled', 'disabled', 'upgraded'], true)) {
                    return null;
                }
            }
            $ruleState = $this->cardRules->fingerprintSnapshotForHolder(
                $operatorScope->tenantId(),
                (int)$holder['id'],
                $lock
            );
            return [
                'member_id' => (int)$holder['uid'],
                'fingerprint' => $this->fingerprint([
                    'holder' => $holder,
                    'order' => $this->orderFingerprintFields($order),
                    'card_state' => $this->entitlementCardStateSnapshot($state, $holder, $order),
                    'card_rule_state' => $ruleState,
                ]),
            ];
        }

        if ($kind === 'debt_record') {
            $query = Db::name('cashier_v3_debt_authority')->alias('a')
                ->join('store_debt d', 'd.id=a.debt_id')
                ->where('a.debt_id', $id)
                ->where('a.tenant_id', $operatorScope->tenantId())
                ->where('a.store_id', $operatorScope->storeId())
                ->field('a.debt_id,a.member_id,a.sales_order_id,a.authority_fingerprint,d.uid,d.store_id,d.total_debt,d.repaid_debt,d.status,d.update_time');
            if ($lock) {
                $query->lock(true);
            }
            $debt = $query->find();
            if (!$debt
                || (int)$debt['member_id'] !== (int)$debt['uid']
                || !$this->allowsSourceStore((int)$debt['store_id'], $operatorScope)) {
                // 充值欠款使用 order_id=0，并由独立的充值欠款权威表维护。
                // 版本发现必须和会员欠款投影使用同一权威分支，否则有效充值欠款
                // 在打开“补交”时会被误判为不存在，无法进入收款流程。
                $rechargeQuery = Db::name('cashier_v3_recharge_debt_authority')->alias('a')
                    ->join('store_debt d', 'd.id=a.debt_id')
                    ->where('a.debt_id', $id)
                    ->where('a.tenant_id', $operatorScope->tenantId())
                    ->where('a.store_id', $operatorScope->storeId())
                    ->field('a.debt_id,a.member_id,a.recharge_id,a.recharge_order_no_snapshot,a.authority_fingerprint,d.uid,d.store_id,d.order_id,d.total_debt,d.repaid_debt,d.status,d.update_time');
                if ($lock) {
                    $rechargeQuery->lock(true);
                }
                $rechargeDebt = $rechargeQuery->find();
                if (!$rechargeDebt
                    || (int)$rechargeDebt['member_id'] !== (int)$rechargeDebt['uid']
                    || (int)($rechargeDebt['order_id'] ?? 0) !== 0
                    || !$this->allowsSourceStore((int)$rechargeDebt['store_id'], $operatorScope)) {
                    return null;
                }
                return [
                    'member_id' => (int)$rechargeDebt['member_id'],
                    'fingerprint' => $this->fingerprint([
                        'debt_id' => (int)$rechargeDebt['debt_id'],
                        'recharge_id' => (int)$rechargeDebt['recharge_id'],
                        'recharge_order_no_snapshot' => (string)$rechargeDebt['recharge_order_no_snapshot'],
                        'authority_fingerprint' => (string)$rechargeDebt['authority_fingerprint'],
                        'total_debt' => (string)$rechargeDebt['total_debt'],
                        'repaid_debt' => (string)$rechargeDebt['repaid_debt'],
                        'status' => (int)$rechargeDebt['status'],
                        'update_time' => (int)$rechargeDebt['update_time'],
                    ]),
                ];
            }
            return [
                'member_id' => (int)$debt['member_id'],
                'fingerprint' => $this->fingerprint([
                    'debt_id' => (int)$debt['debt_id'],
                    'sales_order_id' => (string)$debt['sales_order_id'],
                    'authority_fingerprint' => (string)$debt['authority_fingerprint'],
                    'total_debt' => (string)$debt['total_debt'],
                    'repaid_debt' => (string)$debt['repaid_debt'],
                    'status' => (int)$debt['status'],
                    'update_time' => (int)$debt['update_time'],
                ]),
            ];
        }

        $query = Db::name('store_order_cart_info')
            ->field('id,oid,cart_id,product_id,cart_type,product_type,cart_info,write_times,write_surplus_times,is_writeoff,write_start,write_end,pay_price,debt_amount,repaid_debt_amount,is_gift')
            ->where('id', $id)
            ->where('cart_type', 2)
            ->where('product_type', 6);
        if ($lock) {
            $query->lock(true);
        }
        $cart = $query->find();
        if (!$cart) {
            return null;
        }
        $order = $this->loadActiveOrder((int)$cart['oid'], 0, $lock);
        if (!$order) {
            return null;
        }
        $holderQuery = Db::name('user_card_holder')
            ->field('id,uid,oid,store_id,product_type,write_times,write_surplus_times,write_start,write_end,is_del')
            ->where('oid', (int)$order['id'])
            ->where('is_del', 0);
        if ($lock) {
            $holderQuery->lock(true);
        }
        $holderRows = $holderQuery->select();
        if (is_object($holderRows) && method_exists($holderRows, 'toArray')) {
            $holderRows = $holderRows->toArray();
        }
        $holderRows = is_array($holderRows) ? array_values($holderRows) : [];
        if (count($holderRows) !== 1) {
            if (count($holderRows) > 1) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                    '该订单存在重复卡实例，请联系管理员核对后再使用权益。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['order_id' => (int)$order['id'], 'reason' => 'duplicate_active_card_holder']
                );
            }
            return null;
        }
        $holder = $holderRows[0];
        if (!$this->allowsSourceStore((int)$holder['store_id'], $operatorScope)
            || !$this->allowsSourceStore((int)$order['store_id'], $operatorScope)) {
            return null;
        }
        // A transfer changes the current holder only. The historic order UID
        // remains the sale snapshot and must never decide who can use the
        // current benefit pool. A disabled or mismatched V3 card-state row
        // makes this pool unavailable before an entitlement command can lock
        // and consume it.
        $state = $this->cardOperationState((int)$holder['id'], $lock);
        if ($state !== null) {
            $cardStatus = (string)($state['card_status'] ?? '');
            if ((int)($state['origin_order_id'] ?? 0) !== (int)$order['id']
                || (int)($state['current_member_id'] ?? 0) !== (int)$holder['uid']
                || ($cardStatus !== 'enabled'
                    && !($allowDisabledBenefitPool && $cardStatus === 'disabled'))) {
                return null;
            }
        }
        $ruleState = $this->cardRules->fingerprintSnapshotForDetail(
            $operatorScope->tenantId(),
            (int)$holder['id'],
            (int)$cart['id'],
            $lock
        );
        $reservationQuery = Db::name('store_reservation_order')
            ->field('id,cart_info_id,status,is_del,is_system_del')
            ->where('cart_info_id', (int)$cart['id'])
            ->whereIn('status', [0, 1, 3])
            ->where('is_del', 0)
            ->where('is_system_del', 0)
            ->order('id asc');
        // A1 只写工作台草稿。预约／欠款由旧路径以相反顺序加锁，故这里只读取
        // 当前快照；正式服务完成必须在后续业务事务内重新锁定并最终复核。
        $reservations = $reservationQuery->select();
        if (is_object($reservations) && method_exists($reservations, 'toArray')) {
            $reservations = $reservations->toArray();
        }
        $pendingDebt = $this->orderPendingDebt($order);
        return [
            'member_id' => (int)$holder['uid'],
            'fingerprint' => $this->fingerprint([
                'cart' => $cart,
                'order' => $this->orderFingerprintFields($order),
                'holder' => $holder,
                'card_state' => $this->entitlementCardStateSnapshot($state, $holder, $order),
                'card_rule_state' => $ruleState,
                'reservation_ids' => array_values(array_map(static function (array $row): int {
                    return (int)$row['id'];
                }, is_array($reservations) ? $reservations : [])),
                'pending_debt' => $pendingDebt,
            ]),
        ];
    }

    /**
     * A newly materialized card-state row is only a V3 baseline for an
     * existing legacy card.  It must not invalidate the entitlement version
     * that was used to create the same pending upgrade.  Once any business
     * field changes, retain the complete row in the fingerprint so genuine
     * transfer, status and validity changes remain observable.
     */
    private function entitlementCardStateSnapshot(?array $state, array $holder, array $order): ?array
    {
        if ($state === null) {
            return null;
        }
        $isBaseline = (int)($state['origin_order_id'] ?? 0) === (int)($order['id'] ?? 0)
            && (int)($state['origin_member_id'] ?? 0) === (int)($order['uid'] ?? 0)
            && (int)($state['current_member_id'] ?? 0) === (int)($holder['uid'] ?? 0)
            && (string)($state['card_status'] ?? '') === 'enabled'
            && (string)($state['status_reason_snapshot'] ?? '') === ''
            && (int)($state['effective_write_start'] ?? -1) === (int)($holder['write_start'] ?? 0)
            && (int)($state['effective_write_end'] ?? -1) === (int)($holder['write_end'] ?? 0)
            && (int)($state['current_version'] ?? 0) === 1
            && (string)($state['last_operation_id'] ?? '') === '';
        return $isBaseline ? null : $state;
    }

    private function loadActiveOrder(int $orderId, int $expectedMemberId, bool $lock)
    {
        if ($orderId <= 0) {
            return null;
        }
        $query = Db::name('store_order')
            ->field('id,uid,store_id,paid,is_del,is_system_del,is_user_del,refund_status,terminal_action,card_upgrade_use_oid,order_id,mark,pay_price,cash_pay_price,yue_pay_price,debt_amount,repaid_debt_amount')
            ->where('id', $orderId);
        if ($lock) {
            $query->lock(true);
        }
        $order = $query->find();
        if (!$order
            || ($expectedMemberId > 0 && (int)$order['uid'] !== $expectedMemberId)
            || (int)$order['paid'] !== 1
            || (int)$order['is_del'] !== 0
            || (int)$order['is_system_del'] !== 0
            || (int)$order['is_user_del'] !== 0
            || (int)$order['refund_status'] !== 0
            || (int)$order['terminal_action'] !== 0
            || (int)$order['card_upgrade_use_oid'] !== 0) {
            return null;
        }
        return $order;
    }

    private function orderPendingDebt(array $order): string
    {
        $query = Db::name('store_debt')
            ->field('id,order_id,status,total_debt,repaid_debt')
            ->where('order_id', (int)$order['id']);
        $debtRows = $query->select();
        if (is_object($debtRows) && method_exists($debtRows, 'toArray')) {
            $debtRows = $debtRows->toArray();
        }
        $debtRows = is_array($debtRows) ? array_values($debtRows) : [];
        if (count($debtRows) > 1) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '该订单存在重复欠款记录，请联系管理员核对后再使用权益。',
                CashierV3ResultCode::STATUS_FAILED,
                ['order_id' => (int)$order['id'], 'reason' => 'duplicate_order_debt']
            );
        }
        $debt = $debtRows[0] ?? null;
        if ($debt) {
            if ((int)$debt['status'] !== 0) {
                return '0.00';
            }
            $pending = bcsub((string)$debt['total_debt'], (string)$debt['repaid_debt'], 2);
            return bccomp($pending, '0', 2) > 0 ? $pending : '0.00';
        }
        $pending = bcsub((string)$order['debt_amount'], (string)$order['repaid_debt_amount'], 2);
        return bccomp($pending, '0', 2) > 0 ? $pending : '0.00';
    }

    private function lockShadowRow(string $kind, string $resourceId)
    {
        return Db::name(self::VERSION_TABLE)
            ->where('resource_kind', $kind)
            ->where('resource_id', $resourceId)
            ->lock(true)
            ->find();
    }

    /**
     * The card-operation migration is independently optional to C2. Missing
     * state rows mean no V3 override; a missing table keeps pre-upgrade C2
     * reads working while the card command itself remains readiness-gated.
     */
    private function cardOperationState(int $holderId, bool $lock): ?array
    {
        try {
            $query = Db::name('cashier_v3_card_state')
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->where('card_holder_id', $holderId);
            if ($lock) {
                $query->lock(true);
            }
            $row = $query->find();
        } catch (\Throwable $exception) {
            // The only tolerated failure is the optional table not yet being
            // present. Other DB errors must not be mistaken for an empty state.
            $message = strtolower($exception->getMessage());
            if (strpos($message, 'cashier_v3_card_state') !== false
                && (strpos($message, 'doesn\'t exist') !== false || strpos($message, 'not found') !== false)) {
                return null;
            }
            throw $exception;
        }
        if (is_object($row) && method_exists($row, 'toArray')) {
            $row = $row->toArray();
        }
        return is_array($row) && $row ? $row : null;
    }

    private function activeMember($row): bool
    {
        if (!$row || !is_array($row) || (int)($row['uid'] ?? 0) <= 0
            || (int)($row['status'] ?? 0) !== 1 || (int)($row['is_del'] ?? 0) !== 0) {
            return false;
        }
        $deleteTime = $row['delete_time'] ?? null;
        return $deleteTime === null || $deleteTime === '' || $deleteTime === 0 || $deleteTime === '0'
            || $deleteTime === '0000-00-00 00:00:00';
    }

    private function allowsBusinessSelection(CashierV3DataScopeContext $scope): bool
    {
        if ($scope->forcedStoreId() <= 0) {
            return false;
        }
        if ($scope->authorizationMode() === CashierV3DataScopeContext::MODE_ALL) {
            return true;
        }
        if ($scope->authorizationMode() === CashierV3DataScopeContext::MODE_STORES) {
            return $scope->allowsStore($scope->forcedStoreId());
        }
        return false;
    }

    private function allowsSourceStore(int $sourceStoreId, CashierV3OperatorScope $operatorScope): bool
    {
        if ($sourceStoreId <= 0) {
            return false;
        }
        return $this->crossStoreEnabled() || $sourceStoreId === $operatorScope->storeId();
    }

    private function crossStoreEnabled(): bool
    {
        return CashierV3CrossStoreEntitlementPolicy::enabled();
    }

    private function validTenantScope(CashierV3ResourceScope $scope, CashierV3DataScopeContext $dataScope): bool
    {
        $tenantId = $dataScope->tenantId() !== '' ? $dataScope->tenantId() : CashierV3ScopeResolver::TENANT_SCOPE_ID;
        return $scope->type() === CashierV3ResourceScope::TYPE_TENANT
            && hash_equals($tenantId, $scope->id());
    }

    private function operatorScopeFromDataScope(CashierV3DataScopeContext $scope): CashierV3OperatorScope
    {
        return new CashierV3OperatorScope(
            $scope->forcedStoreId(),
            $scope->operatorId(),
            $scope->organizationId(),
            $scope->tenantId() !== '' ? $scope->tenantId() : CashierV3ScopeResolver::TENANT_SCOPE_ID
        );
    }

    private function orderFingerprintFields(array $order): array
    {
        return [
            'id' => (int)$order['id'],
            'uid' => (int)$order['uid'],
            'store_id' => (int)$order['store_id'],
            'paid' => (int)$order['paid'],
            'is_del' => (int)$order['is_del'],
            'is_system_del' => (int)$order['is_system_del'],
            'is_user_del' => (int)$order['is_user_del'],
            'refund_status' => (int)$order['refund_status'],
            'terminal_action' => (int)$order['terminal_action'],
            'card_upgrade_use_oid' => (int)$order['card_upgrade_use_oid'],
            'mark' => (string)($order['mark'] ?? ''),
            'debt_amount' => (string)$order['debt_amount'],
            'repaid_debt_amount' => (string)$order['repaid_debt_amount'],
            'pay_price' => (string)$order['pay_price'],
            'cash_pay_price' => (string)$order['cash_pay_price'],
            'yue_pay_price' => (string)$order['yue_pay_price'],
        ];
    }

    private function fingerprint(array $value): string
    {
        $normalized = $this->normalizeForHash($value);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('entitlement_authority_fingerprint_failed');
        }
        return hash('sha256', $json);
    }

    private function normalizeForHash($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_keys($value) === range(0, count($value) - 1)) {
            return array_map([$this, 'normalizeForHash'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeForHash($item);
        }
        return $value;
    }

    private function isDuplicateResourceKey(\Throwable $exception): bool
    {
        $message = (string)$exception->getMessage();
        return (strpos($message, '1062') !== false || stripos($message, 'Duplicate') !== false)
            && strpos($message, 'uk_resource') !== false;
    }

    private function isCanonicalPositiveId(string $resourceId): bool
    {
        return preg_match('/^[1-9][0-9]*$/', $resourceId) === 1
            && (string)(int)$resourceId === $resourceId;
    }

    private function assertSupported(string $kind): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \LogicException('unsupported entitlement resource kind: ' . $kind);
        }
    }
}
