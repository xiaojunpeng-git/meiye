<?php
declare(strict_types=1);

namespace app\services\cashier\v3\card;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/** Current entitlement authority for newly issued four-rule cards. */
final class CashierV3CardRuleEntitlementAuthorityServices
{
    public const TIME_CARD_VIRTUAL_TIMES = 1000000;

    private const REPLACEMENT_RELATION_OFFSET = 1000000000000;

    /**
     * 共享次数必须以整卡成交额扣除当前不可用债务后折算，不能用零金额子项目逃逸。
     * 返回 null 表示不是正式签发的共享次数卡；物理余次及历史成交快照不被改写。
     * 最终结账事务中读取并锁定权威池，选择器只读同一口径。
     * 报表批读可传入同一源链已核实的不可用债务；有锁的写事务始终重读权威。
     */
    public function debtLimitForDetail(array $detail, array $order, bool $allowRowLock = true,
        ?string $readOnlyUnavailableDebt = null): ?int
    {
        // A write-capable caller always resolves current debt itself, even
        // when it happens to run outside a transaction and acquires no lock.
        if ($allowRowLock) $readOnlyUnavailableDebt = null;
        // 任选次数卡的卡级债务上限已在替换来源选择时校验；目标按自身转入
        // 次数使用，不能再被原卡同一笔欠款限制一次。
        if (CashierV3ProjectReplacementDebtPolicy::isTransferredRight($detail)) return null;
        // A read-only AI snapshot cannot issue FOR UPDATE. Checkout keeps the
        // default lock; the member detail reader explicitly opts out.
        $lock = $allowRowLock && CashierV3TransactionGuard::isInTransaction();
        $component = $this->row(Db::name(CashierV3IssuedCardRuleStateServices::COMPONENT_TABLE)
            ->where('legacy_detail_id', (int)($detail['id'] ?? 0))
            ->where('tenant_id', (string)($order['tenant_id'] ?? '0'))->find());
        if (!$component) return null;
        $state = $this->row(Db::name(CashierV3IssuedCardRuleStateServices::STATE_TABLE)
            ->where('id', (int)$component['rule_state_id'])->find());
        if (!$state || !in_array((string)$state['rule_type'], ['choice_count', 'combination'], true)) return null;
        $holder = $this->row(Db::name('user_card_holder')
            ->where('id', (int)$state['card_holder_id'])->lock($lock)->find());
        if (!$holder || (int)$holder['oid'] !== (int)($order['id'] ?? 0)
            || (int)($detail['oid'] ?? 0) !== (int)$holder['oid']) {
            throw self::failure('card_rule_cancellation_origin_mismatch');
        }
        $snapshot = $this->snapshotForHolder((string)$state['tenant_id'], (int)$holder['id'], $lock);
        if ($snapshot === null) throw self::failure('card_rule_cancellation_snapshot_missing');
        if ((string)$state['rule_type'] === 'combination') {
            $group = $this->groupForComponent($snapshot, $component);
            return (string)($group['group_rule_type'] ?? '') === 'choice_count'
                ? $this->debtLimitedCombinationTimes($snapshot, $group, $order, $lock, $readOnlyUnavailableDebt) : null;
        }
        return $this->debtLimitedSharedTimes($snapshot, $order, $lock, $readOnlyUnavailableDebt);
    }

    /** 保留 R50 已有调用入口；取消与恢复后的待还状态均走同一整卡上限。 */
    public function cancellationLimitForDetail(array $detail, array $order): ?int
    {
        return $this->debtLimitForDetail($detail, $order);
    }

    /** 仅正式共享池使用整卡销售明细的成交分；债务状态切换不是收款。 */
    private function debtLimitedSharedTimes(array $snapshot, array $order, bool $lock,
        ?string $readOnlyUnavailableDebt = null): int
    {
        $state = $snapshot['state'];
        $remaining = (int)$state['shared_remaining_times'];
        $unavailable = $readOnlyUnavailableDebt !== null && !$lock
            ? $readOnlyUnavailableDebt
            : (new \app\services\cashier\v3\cashier\CashierV3CardOriginDebtResolver())
                ->unavailableCardDebt($order, (int)($order['uid'] ?? 0), (string)$state['tenant_id'], $lock);
        if (bccomp($unavailable, '0', 2) <= 0) return $remaining;
        $line = $this->row(Db::name('cashier_v3_sales_order_line')
            ->where('order_id', (string)$state['sales_order_id'])
            ->where('order_line_id', (string)$state['sales_order_line_id'])
            ->field('sale_amount_cents')->lock($lock)->find());
        if (!$line) throw self::failure('card_rule_cancellation_sale_missing');
        return self::sharedTimesAfterCancellation(
            (int)$state['shared_total_times'], $remaining,
            (int)$line['sale_amount_cents'], (int)bcmul($unavailable, '100', 0)
        );
    }

    /**
     * 整数分公式：floor((成交-取消)/成交×总次)-已用共享次数。
     * 每次都减累计已用次数，不能在剩余池上反复按比例计算；作废恢复物理池后自然恢复额度。
     */
    public static function sharedTimesAfterCancellation(int $total, int $remaining, int $purchaseCents, int $cancelledCents): int
    {
        if ($total <= 0 || $remaining < 0 || $remaining > $total || $purchaseCents < 0 || $cancelledCents < 0) {
            throw new \LogicException('card_rule_cancellation_amount_invalid');
        }
        if ($cancelledCents === 0) return $remaining;
        if ($purchaseCents === 0 || $cancelledCents >= $purchaseCents) return 0;
        $paidTimes = (int)bcdiv(bcmul((string)($purchaseCents - $cancelledCents), (string)$total, 0), (string)$purchaseCents, 0);
        return min($remaining, max(0, $paidTimes - ($total - $remaining)));
    }

    public function authorityForDetail(
        string $tenantId,
        int $holderId,
        int $detailId,
        bool $lock = false
    ): ?array {
        $snapshot = $this->snapshotForHolder($tenantId, $holderId, $lock);
        if ($snapshot === null) {
            return null;
        }
        $component = null;
        foreach ($snapshot['components'] as $candidate) {
            if ((int)($candidate['legacy_detail_id'] ?? 0) === $detailId) {
                $component = $candidate;
                break;
            }
        }
        if ($component === null) {
            throw self::failure('card_rule_component_missing', ['holderId' => $holderId, 'detailId' => $detailId]);
        }
        return $this->normalizeAuthority($snapshot['state'], $component, $this->groupForComponent($snapshot, $component));
    }

    /** @return array<int,array> keyed by legacy detail id */
    public function authoritiesForHolder(string $tenantId, int $holderId, bool $lock = false): array
    {
        $snapshot = $this->snapshotForHolder($tenantId, $holderId, $lock);
        if ($snapshot === null) {
            return [];
        }
        $result = [];
        foreach ($snapshot['components'] as $component) {
            $result[(int)$component['legacy_detail_id']] = $this->normalizeAuthority(
                $snapshot['state'],
                $component,
                $this->groupForComponent($snapshot, $component)
            );
        }
        return $result;
    }

    /**
     * Read many issued-card authorities with one state and one component read.
     * Every immutable snapshot receives the same validation as a single-card
     * read; missing optional tables retain the legacy-card fallback.
     * This read-only path is for member lists and never acquires write locks.
     *
     * @param array<int,int> $holderIds
     * @return array<int,array<int,array>> holder ID => legacy detail ID => authority
     */
    public function authoritiesForHolders(string $tenantId, array $holderIds): array
    {
        $holderIds = array_values(array_unique(array_map('intval', $holderIds)));
        if ($tenantId === '' || !$holderIds) return [];
        foreach ($holderIds as $holderId) if ($holderId <= 0) throw self::failure('card_rule_holder_invalid');
        try {
            $states = $this->rows(Db::name(CashierV3IssuedCardRuleStateServices::STATE_TABLE)
                ->where('tenant_id', $tenantId)->whereIn('card_holder_id', $holderIds)->select());
            if (!$states) return [];
            $components = $this->rows(Db::name(CashierV3IssuedCardRuleStateServices::COMPONENT_TABLE)
                ->where('tenant_id', $tenantId)->whereIn('rule_state_id', array_column($states, 'id'))
                ->order('id asc')->select());
        } catch (\Throwable $exception) {
            if ($this->missingOptionalTable($exception)) return [];
            throw $exception;
        }
        $byState = [];
        foreach ($components as $component) $byState[(int)$component['rule_state_id']][] = $component;
        $groupStates = array_values(array_filter($states, static function (array $state): bool {
            return (string)$state['rule_type'] === 'combination';
        }));
        $groupsByState = [];
        if ($groupStates) {
            $groups = $this->rows(Db::name(CashierV3IssuedCardRuleStateServices::GROUP_TABLE)
                ->where('tenant_id', $tenantId)->whereIn('rule_state_id', array_column($groupStates, 'id'))
                ->order('group_no asc')->select());
            foreach ($groups as $group) $groupsByState[(int)$group['rule_state_id']][(int)$group['group_no']] = $group;
        }
        $result = [];
        foreach ($states as $state) {
            $stateComponents = $byState[(int)$state['id']] ?? [];
            $stateGroups = $groupsByState[(int)$state['id']] ?? [];
            $this->assertStateSnapshot($state, $stateComponents, $stateGroups);
            $holderId = (int)$state['card_holder_id'];
            foreach ($stateComponents as $component) {
                $group = $stateGroups[(int)($component['combination_group_no'] ?? 0)] ?? [];
                $result[$holderId][(int)$component['legacy_detail_id']] = $this->normalizeAuthority($state, $component, $group);
            }
        }
        return $result;
    }

    public function snapshotForHolder(string $tenantId, int $holderId, bool $lock = false): ?array
    {
        if ($holderId <= 0 || $tenantId === '') {
            return null;
        }
        try {
            $stateQuery = Db::name(CashierV3IssuedCardRuleStateServices::STATE_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('card_holder_id', $holderId);
            if ($lock) {
                CashierV3TransactionGuard::assertInTransaction('cardRuleEntitlementSnapshot');
                $stateQuery->lock(true);
            }
            $state = $this->row($stateQuery->find());
            if (!$state) {
                return null;
            }
            $componentQuery = Db::name(CashierV3IssuedCardRuleStateServices::COMPONENT_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('rule_state_id', (int)$state['id'])
                ->order('id asc');
            if ($lock) {
                $componentQuery->lock(true);
            }
            $components = $this->rows($componentQuery->select());
            $groups = [];
            if ((string)$state['rule_type'] === 'combination') {
                $groupQuery = Db::name(CashierV3IssuedCardRuleStateServices::GROUP_TABLE)
                    ->where('tenant_id', $tenantId)->where('rule_state_id', (int)$state['id'])
                    ->order('group_no asc');
                if ($lock) $groupQuery->lock(true);
                foreach ($this->rows($groupQuery->select()) as $group) $groups[(int)$group['group_no']] = $group;
            }
        } catch (\Throwable $exception) {
            if ($this->missingOptionalTable($exception)) {
                return null;
            }
            throw $exception;
        }
        $this->assertStateSnapshot($state, $components, $groups);
        return ['state' => $state, 'components' => $components, 'groups' => $groups];
    }

    public function fingerprintSnapshotForHolder(string $tenantId, int $holderId, bool $lock = false): ?array
    {
        $snapshot = $this->snapshotForHolder($tenantId, $holderId, $lock);
        if ($snapshot === null) {
            return null;
        }
        return [
            'state' => $this->mutableStateFingerprintFields($snapshot['state']),
            'components' => array_values(array_map(function (array $row): array {
                return $this->mutableComponentFingerprintFields($row);
            }, $snapshot['components'])),
            'groups' => array_values(array_map(function (array $row): array {
                return $this->mutableGroupFingerprintFields($row);
            }, $snapshot['groups'])),
        ];
    }

    public function fingerprintSnapshotForDetail(
        string $tenantId,
        int $holderId,
        int $detailId,
        bool $lock = false
    ): ?array {
        $snapshot = $this->snapshotForHolder($tenantId, $holderId, $lock);
        if ($snapshot === null) {
            return null;
        }
        foreach ($snapshot['components'] as $component) {
            if ((int)$component['legacy_detail_id'] === $detailId) {
                return [
                    'state' => $this->mutableStateFingerprintFields($snapshot['state']),
                    'component' => $this->mutableComponentFingerprintFields($component),
                    'group' => $this->groupForComponent($snapshot, $component)
                        ? $this->mutableGroupFingerprintFields($this->groupForComponent($snapshot, $component)) : null,
                ];
            }
        }
        throw self::failure('card_rule_component_missing', ['holderId' => $holderId, 'detailId' => $detailId]);
    }

    /**
     * @param array<int,array> $deductions normalized persistence deductions
     * @return array<int,array> keyed by legacy detail id
     */
    public function applyDeductionsInTx(array $deductions, array $context): array
    {
        CashierV3TransactionGuard::assertInTransaction('cardRuleEntitlementDeduction');
        $tenantId = trim((string)($context['tenant_id'] ?? ''));
        $storeId = (int)($context['store_id'] ?? 0);
        $memberId = (int)($context['member_id'] ?? 0);
        // Entitlement validity belongs to the submitted checkout snapshot,
        // not to the server's later persistence timestamp.
        $occurredAt = (int)($context['occurred_at'] ?? 0);
        if ($tenantId === '' || $storeId <= 0 || $memberId <= 0 || $occurredAt <= 0) {
            throw self::failure('card_rule_deduction_context_invalid');
        }

        $byHolder = [];
        foreach ($deductions as $deduction) {
            $holderId = (int)($deduction['holder_id'] ?? 0);
            if ($holderId > 0) {
                $byHolder[$holderId][] = $deduction;
            }
        }
        ksort($byHolder, SORT_NUMERIC);
        $results = [];
        foreach ($byHolder as $holderId => $holderDeductions) {
            $snapshot = $this->snapshotForHolder($tenantId, $holderId, true);
            if ($snapshot === null) {
                continue;
            }
            $state = $snapshot['state'];
            // Issuance keeps the original purchaser for audit. After transfer,
            // authorize consumption against the locked current holder instead
            // of rewriting that historical member snapshot.
            $currentMemberId = (int)$state['member_id'];
            $currentHolder = $this->row(Db::name('user_card_holder')
                ->where('id', $holderId)->where('is_del', 0)->lock(true)->find());
            if ($currentHolder) {
                $currentMemberId = (int)$currentHolder['uid'];
            }
            if ($currentMemberId !== $memberId || (string)$state['status'] !== 'active') {
                throw self::failure('card_rule_state_not_active', ['holderId' => $holderId]);
            }
            $this->assertUsableAt($state, $occurredAt);
            $componentsByDetail = [];
            foreach ($snapshot['components'] as $component) {
                $componentsByDetail[(int)$component['legacy_detail_id']] = $component;
            }
            $ruleType = (string)$state['rule_type'];
            $totalQuantity = 0;
            $newSelections = [];
            $groupQuantities = [];
            foreach ($holderDeductions as $deduction) {
                $detailId = (int)$deduction['source_detail_id'];
                $quantity = (int)$deduction['deduct_physical_times'];
                $expected = (int)$deduction['expected_physical_remaining_times'];
                $component = $componentsByDetail[$detailId] ?? null;
                if (!$component || (int)$component['project_product_id'] !== (int)$deduction['project_id']
                    || (string)$component['status'] !== 'active' || $quantity <= 0) {
                    throw self::failure('card_rule_deduction_component_invalid', ['detailId' => $detailId]);
                }
                $group = $this->groupForComponent($snapshot, $component);
                $authority = $this->normalizeAuthority($state, $component, $group);
                // A replacement target owns an independent legacy entitlement
                // line even when its source card uses one shared choice-count
                // pool. The shared pool remains the rule-level service limit,
                // but concurrency for this target must compare its own locked
                // remainder; comparing 1 target use with (for example) 62
                // shared card uses makes every valid replacement fail checkout.
                $replacementRemaining = $this->replacementPhysicalRemainingInTx($component);
                $expectedAuthorityRemaining = $replacementRemaining === null
                    ? (int)$authority['remainingTimes']
                    : $replacementRemaining;
                if ($expectedAuthorityRemaining !== $expected || $quantity > $expected) {
                    throw self::failure('card_rule_deduction_version_conflict', ['detailId' => $detailId]);
                }
                if (($ruleType === 'choice_kind'
                    || ($ruleType === 'combination' && (string)($group['group_rule_type'] ?? '') === 'choice_kind'))
                    && (string)$component['selection_status'] === 'candidate') {
                    $newSelections[(int)($component['combination_group_no'] ?? 0)][$detailId] = true;
                }
                if ($ruleType === 'combination') {
                    $groupNo = (int)$component['combination_group_no'];
                    $groupQuantities[$groupNo] = (int)($groupQuantities[$groupNo] ?? 0) + $quantity;
                }
                $totalQuantity += $quantity;
                $results[$detailId] = [
                    'ruleType' => $ruleType,
                    'holderId' => $holderId,
                    'detailId' => $detailId,
                    'quantity' => $quantity,
                    'legacyMode' => $ruleType === 'time' ? 'keep' : 'deduct',
                ];
            }

            if ($ruleType === 'choice_kind'
                && (int)$state['selected_kind_count'] + count($newSelections[0] ?? []) > (int)$state['choice_limit']) {
                throw self::failure('card_rule_choice_limit_exceeded', ['holderId' => $holderId]);
            }
            if ($ruleType === 'combination') {
                $this->deductCombinationInTx($snapshot, $holderDeductions, $componentsByDetail,
                    $groupQuantities, $newSelections, $currentHolder, $storeId, $occurredAt);
            } elseif ($ruleType === 'choice_count') {
                $remaining = (int)$state['shared_remaining_times'];
                if ($totalQuantity > $remaining) {
                    throw self::failure('card_rule_shared_times_insufficient', ['holderId' => $holderId]);
                }
                // 同一卡多个项目要合计复核；逐行<=23不能允许两个项目各用15次。
                // 本分支位于原核销事务内，超额抛错会回滚所有明细/事实，不产生部分扣减。
                $originOrder = $this->row(Db::name('store_order')
                    ->where('id', (int)($currentHolder['oid'] ?? 0))->lock(true)->find());
                if (!$originOrder) throw self::failure('card_rule_cancellation_origin_missing');
                $limit = $this->debtLimitedSharedTimes($snapshot, $originOrder, true);
                if ($totalQuantity > $limit) {
                    throw self::failure('card_rule_shared_times_insufficient', [
                        'holderId' => $holderId, 'requested' => $totalQuantity, 'available' => $limit,
                    ]);
                }
                $after = $remaining - $totalQuantity;
                $this->updateState($state, [
                    'shared_remaining_times' => $after,
                    'status' => $after === 0 ? 'exhausted' : 'active',
                ], $occurredAt);
            } else {
                foreach ($holderDeductions as $deduction) {
                    $detailId = (int)$deduction['source_detail_id'];
                    $quantity = (int)$deduction['deduct_physical_times'];
                    $component = $componentsByDetail[$detailId];
                    if ($ruleType === 'time') {
                        continue;
                    }
                    $after = (int)$component['remaining_times'] - $quantity;
                    if ($after < 0) {
                        throw self::failure('card_rule_component_times_insufficient', ['detailId' => $detailId]);
                    }
                    $changes = [
                        'remaining_times' => $after,
                        'status' => $after === 0 ? 'exhausted' : 'active',
                    ];
                    if ($ruleType === 'choice_kind'
                        && (string)$component['selection_status'] === 'candidate') {
                        $changes['selection_status'] = 'selected';
                        $changes['selected_at'] = $occurredAt;
                        $changes['selected_store_id'] = $storeId;
                    }
                    $this->updateComponent($component, $changes, $occurredAt);
                }
                $stateChanges = [];
                if ($ruleType === 'choice_kind' && $newSelections) {
                    $stateChanges['selected_kind_count'] = (int)$state['selected_kind_count'] + count($newSelections[0] ?? []);
                }
                $this->updateState($state, $stateChanges, $occurredAt);
            }
        }
        return $results;
    }

    /**
     * 一个组合的共享池或种数只在本组扣减；整单先汇总同组多个项目，
     * 再一次校验并写入，异常由外层核销事务整体回滚。
     */
    private function deductCombinationInTx(
        array $snapshot,
        array $deductions,
        array $componentsByDetail,
        array $groupQuantities,
        array $newSelections,
        array $holder,
        int $storeId,
        int $occurredAt
    ): void {
        $state = $snapshot['state'];
        foreach ($groupQuantities as $no => $quantity) {
            $group = $snapshot['groups'][$no] ?? null;
            if (!$group || $quantity <= 0) throw self::failure('card_rule_combination_group_missing');
            if ((string)$group['group_rule_type'] === 'choice_count') {
                $remaining = (int)$group['shared_remaining_times'];
                if ($quantity > $remaining) throw self::failure('card_rule_combination_shared_insufficient');
                $order = $this->row(Db::name('store_order')
                    ->where('id', (int)($holder['oid'] ?? 0))->lock(true)->find());
                if (!$order) throw self::failure('card_rule_cancellation_origin_missing');
                $limit = $this->debtLimitedCombinationTimes($snapshot, $group, $order, true);
                if ($quantity > $limit) throw self::failure('card_rule_combination_debt_limit');
                $after = $remaining - $quantity;
                $this->updateGroup($group, [
                    'shared_remaining_times' => $after,
                    'status' => $after === 0 ? 'exhausted' : 'active',
                ], $occurredAt);
            } elseif ((string)$group['group_rule_type'] === 'choice_kind') {
                $selected = count($newSelections[$no] ?? []);
                if ((int)$group['selected_kind_count'] + $selected > (int)$group['choice_limit']) {
                    throw self::failure('card_rule_combination_choice_limit');
                }
                if ($selected) $this->updateGroup($group, [
                    'selected_kind_count' => (int)$group['selected_kind_count'] + $selected,
                ], $occurredAt);
            } else {
                throw self::failure('card_rule_combination_group_type_invalid');
            }
        }
        foreach ($deductions as $deduction) {
            $detailId = (int)$deduction['source_detail_id'];
            $component = $componentsByDetail[$detailId];
            $group = $snapshot['groups'][(int)$component['combination_group_no']];
            if ((string)$group['group_rule_type'] !== 'choice_kind') continue;
            $after = (int)$component['remaining_times'] - (int)$deduction['deduct_physical_times'];
            if ($after < 0) throw self::failure('card_rule_combination_component_insufficient');
            $changes = ['remaining_times' => $after, 'status' => $after === 0 ? 'exhausted' : 'active'];
            if ((string)$component['selection_status'] === 'candidate') {
                $changes['selection_status'] = 'selected';
                $changes['selected_at'] = $occurredAt;
                $changes['selected_store_id'] = $storeId;
            }
            $this->updateComponent($component, $changes, $occurredAt);
        }
        // 卡级版本使持卡人清单和核销上下文在任一组变化后失效。
        $this->updateState($state, [], $occurredAt);
    }

    /** 债务按整卡可收金额比例限制每组物理池，不让未收款抵扣任意组合。 */
    private function debtLimitedCombinationTimes(array $snapshot, array $group, array $order, bool $lock,
        ?string $readOnlyUnavailableDebt = null): int
    {
        $remaining = (int)$group['shared_remaining_times'];
        $unavailable = $readOnlyUnavailableDebt !== null && !$lock
            ? $readOnlyUnavailableDebt
            : (new \app\services\cashier\v3\cashier\CashierV3CardOriginDebtResolver())
                ->unavailableCardDebt($order, (int)($order['uid'] ?? 0), (string)$snapshot['state']['tenant_id'], $lock);
        if (bccomp($unavailable, '0', 2) <= 0) return $remaining;
        $line = $this->row(Db::name('cashier_v3_sales_order_line')
            ->where('order_id', (string)$snapshot['state']['sales_order_id'])
            ->where('order_line_id', (string)$snapshot['state']['sales_order_line_id'])
            ->field('sale_amount_cents')->lock($lock)->find());
        if (!$line) throw self::failure('card_rule_cancellation_sale_missing');
        return self::sharedTimesAfterCancellation((int)$group['shared_total_times'], $remaining,
            (int)$line['sale_amount_cents'], (int)bcmul($unavailable, '100', 0));
    }

    /**
     * Return the physical remainder of an operation-created replacement line.
     * The immutable component snapshot must match both the operation id and
     * the legacy cart snapshot, preventing a normal component from opting into
     * this independent-count path merely through mutable row values.
     */
    private function replacementPhysicalRemainingInTx(array $component): ?int
    {
        $snapshot = json_decode((string)($component['component_snapshot_json'] ?? ''), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        // Both replacement and upgrade create bounded rights on the same card.
        // Match the immutable operation identity to the physical detail before
        // using its own counter instead of the card's shared count.
        $isUpgrade = isset($snapshot['upgradeOperationId']);
        $operationId = trim((string)($snapshot[$isUpgrade ? 'upgradeOperationId' : 'replacementOperationId'] ?? ''));
        if ($operationId === '') {
            return null;
        }
        $detailId = (int)($component['legacy_detail_id'] ?? 0);
        $projectId = (int)($component['project_product_id'] ?? 0);
        if ($detailId <= 0 || $projectId <= 0) {
            throw self::failure('card_rule_replacement_detail_invalid');
        }
        $detail = $this->row(Db::name('store_order_cart_info')
            ->where('id', $detailId)
            ->where('product_id', $projectId)
            ->where('cart_type', 2)
            ->where('product_type', 6)
            ->lock(true)
            ->find());
        // A missing locked row is a stale or forged replacement snapshot. Fail
        // before decoding fields so the rejection is deterministic and does
        // not depend on PHP's undefined-offset warning behavior.
        if (!$detail) {
            throw self::failure('card_rule_replacement_detail_invalid', ['detailId' => $detailId]);
        }
        $cartSnapshot = json_decode((string)($detail['cart_info'] ?? ''), true);
        $cartSnapshot = is_array($cartSnapshot) ? $cartSnapshot : [];
        $total = (int)($detail['write_times'] ?? 0);
        $remaining = (int)($detail['write_surplus_times'] ?? -1);
        if ((string)($cartSnapshot['sourceType'] ?? '') !== ($isUpgrade ? 'cashier_v3_project_upgrade' : 'cashier_v3_project_replacement')
            || !hash_equals($operationId, (string)($cartSnapshot['cardOperationId'] ?? ''))
            || $total <= 0 || $remaining < 0 || $remaining > $total) {
            throw self::failure('card_rule_replacement_detail_invalid', ['detailId' => $detailId]);
        }
        return $remaining;
    }

    /**
     * A project replacement changes current rights, rather than the card's
     * issue-time definition. Keep the rule-component projection aligned with
     * the legacy entitlement rows in the same transaction so the next
     * selector cannot observe a right without its authoritative rule state.
     *
     * @param array<int,array{detailId:int,quantity:int}> $sourceLines
     */
    public function replaceProjectComponentsInTx(
        string $tenantId,
        int $holderId,
        array $sourceLines,
        int $targetQuantity,
        int $targetValueCents,
        array $target,
        int $targetDetailId,
        string $operationId,
        int $occurredAt,
        string $operationType = 'project_replacement'
    ): void {
        CashierV3TransactionGuard::assertInTransaction('cardRuleProjectReplacement');
        // Upgrades share the atomic source-to-target rule transfer, but freeze
        // the paid target value and their own operation type for later writeoff.
        if (!in_array($operationType, ['project_replacement', 'project_upgrade'], true)) {
            throw self::failure('card_rule_replacement_context_invalid');
        }
        if ($tenantId === '' || $holderId <= 0 || $targetQuantity <= 0 || $targetValueCents < 0
            || $targetDetailId <= 0 || $operationId === '' || $occurredAt <= 0
            || (int)($target['catalogId'] ?? 0) <= 0 || (int)($target['skuId'] ?? 0) <= 0
            || trim((string)($target['skuUnique'] ?? '')) === '') {
            throw self::failure('card_rule_replacement_context_invalid');
        }

        $snapshot = $this->snapshotForHolder($tenantId, $holderId, true);
        // Cards issued before the four-rule authority existed retain their
        // legacy behavior. New-rule cards must always keep this projection.
        if ($snapshot === null) {
            return;
        }
        $state = $snapshot['state'];
        $ruleType = (string)$state['rule_type'];
        // 项目替换/升级会生成新权益明细，目前没有组归属迁移契约；
        // 明确拒绝，不能生成无组项目破坏整张组合卡后续核销。
        if ($ruleType === 'combination') {
            throw self::failure('card_rule_combination_project_operation_unsupported');
        }
        $components = [];
        foreach ($snapshot['components'] as $component) {
            $components[(int)$component['legacy_detail_id']] = $component;
        }

        $sourceDetails = [];
        $totalQuantity = 0;
        $selectionStatus = null;
        $selectedAt = null;
        $selectedStoreId = null;
        $writeoffAmountCents = null;
        foreach ($sourceLines as $line) {
            $detailId = (int)($line['detailId'] ?? 0);
            $quantity = (int)($line['quantity'] ?? 0);
            if ($detailId <= 0 || $quantity <= 0 || isset($sourceDetails[$detailId])) {
                throw self::failure('card_rule_replacement_source_invalid');
            }
            $component = $components[$detailId] ?? null;
            if (!$component || (string)$component['status'] !== 'active') {
                throw self::failure('card_rule_component_missing', ['holderId' => $holderId, 'detailId' => $detailId]);
            }
            $componentSnapshot = json_decode((string)$component['component_snapshot_json'], true);
            if (!is_array($componentSnapshot)) {
                throw self::failure('card_rule_component_fingerprint_invalid');
            }
            $componentWriteoffAmount = (int)($component['writeoff_amount_cents'] ?? 0);
            $componentSelection = (string)$component['selection_status'];
            // Different purchase prices are valid replacement sources. Rule
            // selection and write-off semantics remain compatible-only until
            // the product defines how unlike choice slots or labor values are
            // merged; silently taking either source would corrupt authority.
            if ($writeoffAmountCents !== null && $writeoffAmountCents !== $componentWriteoffAmount
                || $selectionStatus !== null && $selectionStatus !== $componentSelection
                || $selectedAt !== null && $selectedAt !== (int)$component['selected_at']
                || $selectedStoreId !== null && $selectedStoreId !== (int)$component['selected_store_id']) {
                throw self::failure('card_rule_replacement_sources_incompatible');
            }
            $writeoffAmountCents = $componentWriteoffAmount;
            $selectionStatus = $componentSelection;
            $selectedAt = (int)$component['selected_at'];
            $selectedStoreId = (int)$component['selected_store_id'];

            if (in_array($ruleType, ['normal', 'choice_kind'], true)) {
                $remaining = (int)$component['remaining_times'];
                if ($remaining < $quantity) {
                    throw self::failure('card_rule_component_times_insufficient', ['detailId' => $detailId]);
                }
                $after = $remaining - $quantity;
                $this->updateComponent($component, [
                    'remaining_times' => $after,
                    'status' => $after === 0 ? 'exhausted' : 'active',
                ], $occurredAt);
            }
            $sourceDetails[$detailId] = $quantity;
            $totalQuantity += $quantity;
        }
        if (!$sourceDetails || $totalQuantity <= 0 || $writeoffAmountCents === null
            || $selectionStatus === null || $selectedAt === null || $selectedStoreId === null) {
            throw self::failure('card_rule_replacement_source_invalid');
        }

        // Replacement is allowed to merge projects with different configured
        // prices. The target freezes the exact value that
        // the operation authority already allocated from all selected source
        // rights; inheriting the first source silently loses or invents value.
        $configuredPriceCents = intdiv($targetValueCents, $targetQuantity);

        if ($targetDetailId > PHP_INT_MAX - self::REPLACEMENT_RELATION_OFFSET) {
            throw self::failure('card_rule_replacement_relation_invalid');
        }
        $relationId = self::REPLACEMENT_RELATION_OFFSET + $targetDetailId;
        $existing = $this->row(Db::name(CashierV3IssuedCardRuleStateServices::COMPONENT_TABLE)
            ->where('rule_state_id', (int)$state['id'])
            ->where('relation_id', $relationId)
            ->lock(true)
            ->find());
        if ($existing) {
            throw self::failure('card_rule_replacement_relation_conflict');
        }

        $usesIndependentTimes = in_array($ruleType, ['normal', 'choice_kind'], true);
        // For independent-count rules the replacement exposes the target
        // count frozen in its operation plan. Choice-count rules use the
        // card-level shared counter, so their component keeps the established
        // virtual count of zero.
        $targetTimes = $usesIndependentTimes ? $targetQuantity : 0;
        $componentSnapshot = [
            'relationId' => $relationId,
            'productId' => (int)$target['catalogId'],
            'skuId' => (int)$target['skuId'],
            'skuUnique' => trim((string)$target['skuUnique']),
            'productType' => 6,
            'nameSnapshot' => trim((string)($target['catalogName'] ?? '')) ?: '项目',
            'writeTimes' => $targetTimes,
            'configuredPriceCents' => $configuredPriceCents,
            // The total is authoritative when the source value cannot be
            // divided evenly by the independently selected target quantity.
            'configuredAmountCents' => $targetValueCents,
            'writeoffAmountCents' => $writeoffAmountCents,
            ($operationType === 'project_upgrade' ? 'upgradeOperationId' : 'replacementOperationId') => $operationId,
            ($operationType === 'project_upgrade' ? 'upgradeSourceDetails' : 'replacementSourceDetails') => $sourceDetails,
        ];
        $componentStateId = 'CRC-' . strtoupper(substr(hash(
            'sha256',
            (string)$state['state_id'] . '|replacement|' . $targetDetailId . '|' . $operationId
        ), 0, 40));
        $inserted = (int)Db::name(CashierV3IssuedCardRuleStateServices::COMPONENT_TABLE)->insertGetId([
            'component_state_id' => $componentStateId,
            'tenant_id' => $tenantId,
            'rule_state_id' => (int)$state['id'],
            'card_holder_id' => $holderId,
            'legacy_detail_id' => $targetDetailId,
            'relation_id' => $relationId,
            'project_product_id' => (int)$target['catalogId'],
            'project_sku_id' => (int)$target['skuId'],
            'project_sku_unique' => trim((string)$target['skuUnique']),
            'project_type' => 6,
            'project_name_snapshot' => trim((string)($target['catalogName'] ?? '')) ?: '项目',
            'total_times' => $targetTimes,
            'remaining_times' => $targetTimes,
            'writeoff_amount_cents' => $writeoffAmountCents,
            'selection_status' => $selectionStatus,
            'selected_at' => $selectedAt,
            'selected_store_id' => $selectedStoreId,
            'immutable_fingerprint' => self::fingerprint($componentSnapshot),
            'component_snapshot_json' => self::json($componentSnapshot),
            'state_version' => 1,
            'status' => 'active',
            'occurred_at' => $occurredAt,
            'recorded_at' => $occurredAt,
            'add_time' => $occurredAt,
            'update_time' => $occurredAt,
        ]);
        if ($inserted <= 0) {
            throw self::failure('card_rule_replacement_component_create_failed');
        }
    }

    /**
     * Reverse an unused project replacement without changing the issued rule definition.
     * Source quantities come from the immutable operation lines; every mutable component
     * must still match the state left by that operation before any rights are restored.
     */
    public function reverseProjectComponentsInTx(string $tenantId, int $holderId, array $sourceLines, int $targetDetailId, string $operationId, int $time): void
    {
        CashierV3TransactionGuard::assertInTransaction('cardRuleProjectReplacementVoid');
        $snapshot = $this->snapshotForHolder($tenantId, $holderId, true);
        if ($snapshot === null) return; // Legacy cards have no rule projection.
        $state = $snapshot['state'];
        if (!in_array((string)$state['status'], ['active', 'exhausted'], true)) {
            throw self::failure('card_rule_replacement_void_state_invalid');
        }
        $components = [];
        foreach ($snapshot['components'] as $component) $components[(int)$component['legacy_detail_id']] = $component;
        $target = $components[$targetDetailId] ?? null;
        $targetSnapshot = json_decode((string)($target['component_snapshot_json'] ?? ''), true);
        if (!$target || !is_array($targetSnapshot) || (string)$target['status'] !== 'active'
            || (int)$target['state_version'] !== 1
            || !hash_equals($operationId, (string)($targetSnapshot['replacementOperationId'] ?? ''))) {
            throw self::failure('card_rule_replacement_void_target_changed');
        }
        $expectedSources = [];
        $independent = in_array((string)$state['rule_type'], ['normal', 'choice_kind'], true);
        foreach ($sourceLines as $line) {
            $id = (int)($line['source_detail_id'] ?? 0);
            $before = (int)($line['quantity_before'] ?? 0);
            $after = (int)($line['quantity_after'] ?? -1);
            $component = $components[$id] ?? null;
            if ($id <= 0 || isset($expectedSources[$id]) || $before <= $after || $after < 0 || !$component
                || !in_array((string)$component['status'], ['active', 'exhausted'], true)
                || ($independent && ((int)$component['remaining_times'] !== $after || $before > (int)$component['total_times']))) {
                throw self::failure('card_rule_replacement_void_source_changed');
            }
            $expectedSources[$id] = $before - $after;
        }
        $frozenSources = (array)($targetSnapshot['replacementSourceDetails'] ?? []);
        ksort($expectedSources);
        ksort($frozenSources);
        if (!$expectedSources || $expectedSources !== array_map('intval', $frozenSources)
            || ($independent && (int)$target['remaining_times'] !== (int)$target['total_times'])) {
            throw self::failure('card_rule_replacement_void_snapshot_mismatch');
        }
        foreach ($sourceLines as $line) {
            if (!$independent) continue; // Shared-count and time rules do not decrement source components on replacement.
            $component = $components[(int)$line['source_detail_id']];
            $this->updateComponent($component, ['remaining_times' => (int)$line['quantity_before'], 'status' => 'active'], $time);
        }
        $this->updateComponent($target, ['remaining_times' => 0, 'status' => 'revoked'], $time);
        if ($independent && (string)$state['status'] === 'exhausted') {
            $this->updateState($state, ['status' => 'active'], $time);
        }
    }

    private function groupForComponent(array $snapshot, array $component): array
    {
        if ((string)$snapshot['state']['rule_type'] !== 'combination') return [];
        $no = (int)($component['combination_group_no'] ?? 0);
        $group = $snapshot['groups'][$no] ?? null;
        if (!$group || (int)$group['rule_state_id'] !== (int)$snapshot['state']['id']) {
            throw self::failure('card_rule_combination_group_missing');
        }
        return $group;
    }

    private function normalizeAuthority(array $state, array $component, array $group = []): array
    {
        $ruleType = (string)$state['rule_type'];
        $effectiveRule = $ruleType === 'combination' ? (string)($group['group_rule_type'] ?? '') : $ruleType;
        if ($ruleType === 'combination' && !in_array($effectiveRule, ['choice_kind', 'choice_count'], true)) {
            throw self::failure('card_rule_combination_group_missing');
        }
        $remaining = in_array($effectiveRule, ['normal', 'choice_kind'], true)
            ? (int)$component['remaining_times']
            : ($effectiveRule === 'choice_count'
                ? (int)($ruleType === 'combination' ? $group['shared_remaining_times'] : $state['shared_remaining_times'])
                : self::TIME_CARD_VIRTUAL_TIMES);
        $total = in_array($effectiveRule, ['normal', 'choice_kind'], true)
            ? (int)$component['total_times']
            : ($effectiveRule === 'choice_count'
                ? (int)($ruleType === 'combination' ? $group['shared_total_times'] : $state['shared_total_times'])
                : self::TIME_CARD_VIRTUAL_TIMES);
        $componentSnapshot = json_decode((string)$component['component_snapshot_json'], true);
        $componentSnapshot = is_array($componentSnapshot) ? $componentSnapshot : [];
        $unitAmount = $ruleType === 'time'
            ? (int)$component['writeoff_amount_cents']
            : (int)($componentSnapshot['configuredPriceCents'] ?? 0);
        $configuredAmount = array_key_exists('configuredAmountCents', $componentSnapshot)
            ? (int)$componentSnapshot['configuredAmountCents']
            : null;
        if ($configuredAmount !== null && $configuredAmount < 0) {
            throw self::failure('card_rule_component_amount_invalid');
        }
        $selected = (string)$component['selection_status'];
        $choiceAvailable = $effectiveRule !== 'choice_kind'
            || $selected === 'selected'
            || ($selected === 'candidate'
                && (int)($ruleType === 'combination' ? $group['selected_kind_count'] : $state['selected_kind_count'])
                    < (int)($ruleType === 'combination' ? $group['choice_limit'] : $state['choice_limit']));
        return [
            // 现有收银选择器继续识别两种任选规则；真实卡类型单独保留供退款与追溯。
            'ruleType' => $effectiveRule,
            'cardRuleType' => $ruleType,
            'combinationGroupNo' => $ruleType === 'combination' ? (int)$group['group_no'] : 0,
            'combinationGroupName' => $ruleType === 'combination' ? (string)$group['group_name_snapshot'] : '',
            // 父卡展示需要扣除已无选择名额的候选项目，读取已签发组状态而非现售配置。
            'combinationChoiceLimit' => $ruleType === 'combination' ? (int)$group['choice_limit'] : 0,
            'combinationSelectedKindCount' => $ruleType === 'combination' ? (int)$group['selected_kind_count'] : 0,
            'ruleVersion' => (int)$state['rule_version'],
            'stateVersion' => (int)$state['state_version'],
            'sourceKind' => $effectiveRule === 'time' ? 'time_card' : 'count_card',
            'sourceKindLabel' => [
                'normal' => '普通卡',
                'choice_kind' => '任选种数卡',
                'choice_count' => '任选次数卡',
                'time' => '时间卡',
            ][$effectiveRule],
            'remainingTimes' => $remaining,
            'totalTimes' => $total,
            'unitAmountCents' => $unitAmount,
            'purchaseAmountCents' => $configuredAmount ?? $this->multiply($unitAmount, $total),
            'writeoffAmountCents' => (int)$component['writeoff_amount_cents'],
            'selectionStatus' => $selected,
            'choiceAvailable' => $choiceAvailable,
            'unlimited' => $ruleType === 'time',
            'stateStatus' => (string)$state['status'],
            'validFrom' => (int)$state['valid_from'],
            'validThrough' => (int)$state['valid_through'],
        ];
    }

    private function assertStateSnapshot(array $state, array $components, array $groups = []): void
    {
        $ruleType = (string)($state['rule_type'] ?? '');
        if (!in_array($ruleType, ['normal', 'choice_kind', 'choice_count', 'time', 'combination'], true)
            || (int)($state['state_version'] ?? 0) <= 0 || !$components) {
            throw self::failure('card_rule_state_snapshot_invalid');
        }
        $snapshot = json_decode((string)($state['definition_snapshot_json'] ?? ''), true);
        if (!is_array($snapshot)
            || !hash_equals((string)$state['immutable_fingerprint'], self::fingerprint($snapshot))) {
            throw self::failure('card_rule_state_fingerprint_invalid');
        }
        foreach ($components as $component) {
            $componentSnapshot = json_decode((string)($component['component_snapshot_json'] ?? ''), true);
            if (!is_array($componentSnapshot)
                || !hash_equals((string)$component['immutable_fingerprint'], self::fingerprint($componentSnapshot))
                || (int)$component['rule_state_id'] !== (int)$state['id']
                || (int)$component['card_holder_id'] !== (int)$state['card_holder_id']) {
                throw self::failure('card_rule_component_fingerprint_invalid');
            }
            if ($ruleType === 'combination' && !isset($groups[(int)($component['combination_group_no'] ?? 0)])) {
                throw self::failure('card_rule_combination_component_group_invalid');
            }
            if ($ruleType === 'combination' && (int)$component['combination_group_no']
                !== (int)($componentSnapshot['combinationGroupNo'] ?? 0)) {
                throw self::failure('card_rule_combination_component_group_mismatch');
            }
        }
        if ($ruleType === 'combination') {
            $definitions = (array)($snapshot['combinationGroups'] ?? []);
            if (!$groups || count($groups) !== count($definitions)) throw self::failure('card_rule_combination_groups_missing');
            foreach ($groups as $group) {
                $groupSnapshot = json_decode((string)$group['definition_snapshot_json'], true);
                if (!is_array($groupSnapshot)
                    || !hash_equals((string)$group['immutable_fingerprint'], self::fingerprint($groupSnapshot))
                    || (int)$group['rule_state_id'] !== (int)$state['id']
                    || (int)$group['group_no'] !== (int)($groupSnapshot['groupNo'] ?? 0)
                    || (string)$group['group_rule_type'] !== (string)($groupSnapshot['ruleType'] ?? '')
                    || (int)$group['choice_limit'] !== (int)($groupSnapshot['choiceLimit'] ?? -1)
                    || (int)$group['shared_total_times'] !== (int)($groupSnapshot['sharedTimes'] ?? -1)) {
                    throw self::failure('card_rule_combination_group_fingerprint_invalid');
                }
            }
        }
    }

    private function assertUsableAt(array $state, int $time): void
    {
        $start = (int)$state['valid_from'];
        $end = (int)$state['valid_through'];
        if (($start > 0 && $time < $start) || ($end > 0 && $time > $end)) {
            throw self::failure('card_rule_state_outside_validity');
        }
    }

    /**
     * 服务作废返还已扣次数；调用方必须先锁定服务并检查作废幂等账本。
     * 与旧卡余额及返还事实处于同一事务，不单独提交。不重置任选种类的历史选择，
     * 不延长时间卡有效期；旧卡无规则返回null沿用旧余额，时间卡返回0禁止虚增次数。
     */
    public function restoreServiceTimesInTx(string $tenantId, int $holderId, int $detailId, int $quantity, int $time): ?int
    {
        CashierV3TransactionGuard::assertInTransaction('cardRuleServiceRestore');
        if ($quantity <= 0 || $holderId <= 0 || $detailId <= 0) throw self::failure('card_rule_restore_context_invalid');
        $snapshot = $this->snapshotForHolder($tenantId, $holderId, true);
        if ($snapshot === null) return null;
        $state = $snapshot['state'];
        $component = null;
        foreach ($snapshot['components'] as $candidate) {
            if ((int)$candidate['legacy_detail_id'] === $detailId) { $component = $candidate; break; }
        }
        // 不得把已转移/取消的权益重新激活；异常时整笔作废回滚，不能留下半返还。
        if (!$component || !in_array($state['status'], ['active', 'exhausted'], true)
            || !in_array($component['status'], ['active', 'exhausted'], true)) {
            throw self::failure('card_rule_restore_state_invalid');
        }
        $type = (string)$state['rule_type'];
        if ($type === 'time') return 0;
        if ($type === 'combination') {
            $group = $this->groupForComponent($snapshot, $component);
            if ((string)$group['group_rule_type'] === 'choice_count') {
                $remaining = (int)$group['shared_remaining_times'];
                if ($quantity > (int)$group['shared_total_times'] - $remaining) throw self::failure('card_rule_restore_overflow');
                $this->updateGroup($group, ['shared_remaining_times' => $remaining + $quantity, 'status' => 'active'], $time);
            } else {
                $remaining = (int)$component['remaining_times'];
                if ($quantity > (int)$component['total_times'] - $remaining) throw self::failure('card_rule_restore_overflow');
                $this->updateComponent($component, ['remaining_times' => $remaining + $quantity, 'status' => 'active'], $time);
            }
            $this->updateState($state, ['status' => 'active'], $time);
        } elseif ($type === 'choice_count') {
            $remaining = (int)$state['shared_remaining_times'];
            if ($quantity > (int)$state['shared_total_times'] - $remaining) throw self::failure('card_rule_restore_overflow');
            $this->updateState($state, ['shared_remaining_times' => $remaining + $quantity, 'status' => 'active'], $time);
        } elseif (in_array($type, ['normal', 'choice_kind'], true)) {
            $remaining = (int)$component['remaining_times'];
            if ($quantity > (int)$component['total_times'] - $remaining) throw self::failure('card_rule_restore_overflow');
            $this->updateComponent($component, ['remaining_times' => $remaining + $quantity, 'status' => 'active'], $time);
            $this->updateState($state, ['status' => 'active'], $time);
        } else {
            throw self::failure('card_rule_restore_type_invalid');
        }
        return $quantity;
    }

    private function updateState(array $state, array $changes, int $time): void
    {
        $version = (int)$state['state_version'];
        $changes['state_version'] = $version + 1;
        $changes['update_time'] = $time;
        $affected = (int)Db::name(CashierV3IssuedCardRuleStateServices::STATE_TABLE)
            ->where('id', (int)$state['id'])
            ->where('state_version', $version)
            ->update($changes);
        if ($affected !== 1) {
            throw self::failure('card_rule_state_update_conflict');
        }
    }

    private function updateComponent(array $component, array $changes, int $time): void
    {
        $version = (int)$component['state_version'];
        $changes['state_version'] = $version + 1;
        $changes['update_time'] = $time;
        $affected = (int)Db::name(CashierV3IssuedCardRuleStateServices::COMPONENT_TABLE)
            ->where('id', (int)$component['id'])
            ->where('state_version', $version)
            ->update($changes);
        if ($affected !== 1) {
            throw self::failure('card_rule_component_update_conflict');
        }
    }

    private function updateGroup(array $group, array $changes, int $time): void
    {
        $version = (int)$group['state_version'];
        $changes['state_version'] = $version + 1;
        $changes['update_time'] = $time;
        $affected = (int)Db::name(CashierV3IssuedCardRuleStateServices::GROUP_TABLE)
            ->where('id', (int)$group['id'])->where('state_version', $version)->update($changes);
        if ($affected !== 1) throw self::failure('card_rule_combination_group_update_conflict');
    }

    private function mutableGroupFingerprintFields(array $group): array
    {
        return $this->pick($group, [
            'id', 'tenant_id', 'rule_state_id', 'card_holder_id', 'group_no', 'group_rule_type',
            'choice_limit', 'selected_kind_count', 'shared_total_times', 'shared_remaining_times',
            'immutable_fingerprint', 'state_version', 'status',
        ]);
    }

    private function mutableStateFingerprintFields(array $state): array
    {
        return $this->pick($state, [
            'id', 'state_id', 'tenant_id', 'card_holder_id', 'member_id', 'issue_store_id',
            'rule_type', 'rule_version', 'definition_version', 'choice_limit',
            'selected_kind_count', 'shared_total_times', 'shared_remaining_times',
            'validity_mode', 'valid_from', 'valid_through', 'immutable_fingerprint',
            'state_version', 'status',
        ]);
    }

    private function mutableComponentFingerprintFields(array $component): array
    {
        return $this->pick($component, [
            'id', 'component_state_id', 'tenant_id', 'rule_state_id', 'card_holder_id',
            'legacy_detail_id', 'relation_id', 'project_product_id', 'project_sku_id',
            'combination_group_no',
            'project_sku_unique', 'project_type', 'total_times', 'remaining_times',
            'writeoff_amount_cents', 'selection_status', 'selected_at', 'selected_store_id',
            'immutable_fingerprint', 'state_version', 'status',
        ]);
    }

    private function pick(array $row, array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $row)) {
                throw self::failure('card_rule_fingerprint_field_missing', ['field' => $field]);
            }
            $result[$field] = $row[$field];
        }
        return $result;
    }

    private function multiply(int $left, int $right): int
    {
        if ($left < 0 || $right <= 0 || ($left > 0 && $right > intdiv(PHP_INT_MAX, $left))) {
            throw self::failure('card_rule_amount_overflow');
        }
        return $left * $right;
    }

    private function missingOptionalTable(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        return (strpos($message, CashierV3IssuedCardRuleStateServices::STATE_TABLE) !== false
                || strpos($message, CashierV3IssuedCardRuleStateServices::COMPONENT_TABLE) !== false)
            && (strpos($message, 'doesn\'t exist') !== false || strpos($message, 'not found') !== false);
    }

    private function row($value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        return is_array($value) ? $value : [];
    }

    private function rows($value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        return is_array($value) ? array_values($value) : [];
    }

    private static function fingerprint(array $value): string
    {
        $json = json_encode(self::canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw self::failure('card_rule_fingerprint_encode_failed');
        }
        return hash('sha256', $json);
    }

    private static function json(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw self::failure('card_rule_component_encode_failed');
        }
        return $json;
    }

    private static function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_keys($value) === ($value ? range(0, count($value) - 1) : [])) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }

    private static function failure(string $reason, array $detail = []): CashierV3CommandException
    {
        $detail['reason'] = $reason;
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '卡项规则状态已经变化，本次操作已取消，请重新选择。',
            CashierV3ResultCode::STATUS_FAILED,
            $detail
        );
    }
}
