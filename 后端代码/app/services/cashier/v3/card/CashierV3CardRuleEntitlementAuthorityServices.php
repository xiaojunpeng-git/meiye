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
        return $this->normalizeAuthority($snapshot['state'], $component);
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
                $component
            );
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
        } catch (\Throwable $exception) {
            if ($this->missingOptionalTable($exception)) {
                return null;
            }
            throw $exception;
        }
        $this->assertStateSnapshot($state, $components);
        return ['state' => $state, 'components' => $components];
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
        $occurredAt = (int)($context['recorded_at'] ?? 0);
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
            if ((int)$state['member_id'] !== $memberId || (string)$state['status'] !== 'active') {
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
            foreach ($holderDeductions as $deduction) {
                $detailId = (int)$deduction['source_detail_id'];
                $quantity = (int)$deduction['deduct_physical_times'];
                $expected = (int)$deduction['expected_physical_remaining_times'];
                $component = $componentsByDetail[$detailId] ?? null;
                if (!$component || (int)$component['project_product_id'] !== (int)$deduction['project_id']
                    || (string)$component['status'] !== 'active' || $quantity <= 0) {
                    throw self::failure('card_rule_deduction_component_invalid', ['detailId' => $detailId]);
                }
                $authority = $this->normalizeAuthority($state, $component);
                if ((int)$authority['remainingTimes'] !== $expected || $quantity > $expected) {
                    throw self::failure('card_rule_deduction_version_conflict', ['detailId' => $detailId]);
                }
                if ($ruleType === 'choice_kind'
                    && (string)$component['selection_status'] === 'candidate') {
                    $newSelections[$detailId] = true;
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
                && (int)$state['selected_kind_count'] + count($newSelections) > (int)$state['choice_limit']) {
                throw self::failure('card_rule_choice_limit_exceeded', ['holderId' => $holderId]);
            }
            if ($ruleType === 'choice_count') {
                $remaining = (int)$state['shared_remaining_times'];
                if ($totalQuantity > $remaining) {
                    throw self::failure('card_rule_shared_times_insufficient', ['holderId' => $holderId]);
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
                    $stateChanges['selected_kind_count'] = (int)$state['selected_kind_count'] + count($newSelections);
                }
                $this->updateState($state, $stateChanges, $occurredAt);
            }
        }
        return $results;
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
        array $target,
        int $targetDetailId,
        string $operationId,
        int $occurredAt
    ): void {
        CashierV3TransactionGuard::assertInTransaction('cardRuleProjectReplacement');
        if ($tenantId === '' || $holderId <= 0 || $targetDetailId <= 0 || $operationId === '' || $occurredAt <= 0
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
        $components = [];
        foreach ($snapshot['components'] as $component) {
            $components[(int)$component['legacy_detail_id']] = $component;
        }

        $sourceDetails = [];
        $totalQuantity = 0;
        $selectionStatus = null;
        $selectedAt = null;
        $selectedStoreId = null;
        $configuredPriceCents = null;
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
            $componentConfiguredPrice = (int)($componentSnapshot['configuredPriceCents'] ?? 0);
            $componentWriteoffAmount = (int)($component['writeoff_amount_cents'] ?? 0);
            $componentSelection = (string)$component['selection_status'];
            if ($configuredPriceCents !== null && $configuredPriceCents !== $componentConfiguredPrice
                || $writeoffAmountCents !== null && $writeoffAmountCents !== $componentWriteoffAmount
                || $selectionStatus !== null && $selectionStatus !== $componentSelection
                || $selectedAt !== null && $selectedAt !== (int)$component['selected_at']
                || $selectedStoreId !== null && $selectedStoreId !== (int)$component['selected_store_id']) {
                throw self::failure('card_rule_replacement_sources_incompatible');
            }
            $configuredPriceCents = $componentConfiguredPrice;
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
        if (!$sourceDetails || $totalQuantity <= 0 || $configuredPriceCents === null
            || $writeoffAmountCents === null || $selectionStatus === null
            || $selectedAt === null || $selectedStoreId === null) {
            throw self::failure('card_rule_replacement_source_invalid');
        }

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
        // Source rights can be deducted in aggregate, while a replacement
        // always grants exactly one target-project right.
        $targetTimes = $usesIndependentTimes ? 1 : 0;
        $componentSnapshot = [
            'relationId' => $relationId,
            'productId' => (int)$target['catalogId'],
            'skuId' => (int)$target['skuId'],
            'skuUnique' => trim((string)$target['skuUnique']),
            'productType' => 6,
            'nameSnapshot' => trim((string)($target['catalogName'] ?? '')) ?: '项目',
            'writeTimes' => $targetTimes,
            'configuredPriceCents' => $configuredPriceCents,
            'writeoffAmountCents' => $writeoffAmountCents,
            'replacementOperationId' => $operationId,
            'replacementSourceDetails' => $sourceDetails,
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

    private function normalizeAuthority(array $state, array $component): array
    {
        $ruleType = (string)$state['rule_type'];
        $remaining = in_array($ruleType, ['normal', 'choice_kind'], true)
            ? (int)$component['remaining_times']
            : ($ruleType === 'choice_count'
                ? (int)$state['shared_remaining_times']
                : self::TIME_CARD_VIRTUAL_TIMES);
        $total = in_array($ruleType, ['normal', 'choice_kind'], true)
            ? (int)$component['total_times']
            : ($ruleType === 'choice_count'
                ? (int)$state['shared_total_times']
                : self::TIME_CARD_VIRTUAL_TIMES);
        $componentSnapshot = json_decode((string)$component['component_snapshot_json'], true);
        $componentSnapshot = is_array($componentSnapshot) ? $componentSnapshot : [];
        $unitAmount = $ruleType === 'time'
            ? (int)$component['writeoff_amount_cents']
            : (int)($componentSnapshot['configuredPriceCents'] ?? 0);
        $selected = (string)$component['selection_status'];
        $choiceAvailable = $ruleType !== 'choice_kind'
            || $selected === 'selected'
            || ($selected === 'candidate'
                && (int)$state['selected_kind_count'] < (int)$state['choice_limit']);
        return [
            'ruleType' => $ruleType,
            'ruleVersion' => (int)$state['rule_version'],
            'stateVersion' => (int)$state['state_version'],
            'sourceKind' => $ruleType === 'time' ? 'time_card' : 'count_card',
            'sourceKindLabel' => [
                'normal' => '普通卡',
                'choice_kind' => '任选种数卡',
                'choice_count' => '任选次数卡',
                'time' => '时间卡',
            ][$ruleType],
            'remainingTimes' => $remaining,
            'totalTimes' => $total,
            'unitAmountCents' => $unitAmount,
            'purchaseAmountCents' => $this->multiply($unitAmount, $total),
            'writeoffAmountCents' => (int)$component['writeoff_amount_cents'],
            'selectionStatus' => $selected,
            'choiceAvailable' => $choiceAvailable,
            'unlimited' => $ruleType === 'time',
            'stateStatus' => (string)$state['status'],
            'validFrom' => (int)$state['valid_from'],
            'validThrough' => (int)$state['valid_through'],
        ];
    }

    private function assertStateSnapshot(array $state, array $components): void
    {
        $ruleType = (string)($state['rule_type'] ?? '');
        if (!in_array($ruleType, ['normal', 'choice_kind', 'choice_count', 'time'], true)
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
