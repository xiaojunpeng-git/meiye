<?php
declare(strict_types=1);

namespace app\services\cashier\v3\card;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/** Immutable issue-time definition plus mutable counters for newly configured cards. */
final class CashierV3IssuedCardRuleStateServices
{
    public const STATE_TABLE = 'cashier_v3_card_rule_state';
    public const COMPONENT_TABLE = 'cashier_v3_card_rule_component';
    public const CONTRACT_VERSION = 'cashier-v3-issued-card-rule-state-v1';

    public function issueInTx(
        string $receiptId,
        array $header,
        array $salesLine,
        array $line,
        array $purchase,
        array $validity,
        array $issued,
        array $holder,
        int $occurredAt
    ): array {
        $ruleType = trim((string)($purchase['ruleType'] ?? ''));
        if ($ruleType === '') {
            return [];
        }
        CashierV3TransactionGuard::assertInTransaction('issuedCardRuleState');
        $this->assertReady();

        $ruleVersion = (int)($purchase['ruleVersion'] ?? 0);
        $definitionVersion = (int)($purchase['definitionVersion'] ?? 0);
        $choiceLimit = (int)($purchase['choiceLimit'] ?? 0);
        $sharedTimes = (int)($purchase['sharedTimes'] ?? 0);
        $holderId = (int)($holder['holderId'] ?? 0);
        $components = array_values((array)($issued['issuedComponents'] ?? []));
        $this->assertDefinition(
            $ruleType,
            $ruleVersion,
            $definitionVersion,
            $choiceLimit,
            $sharedTimes,
            $holderId,
            $components
        );

        $stateId = 'CRS-' . strtoupper(substr(hash('sha256', $receiptId . '|' . $holderId), 0, 40));
        $snapshot = [
            'contractVersion' => self::CONTRACT_VERSION,
            'receiptId' => $receiptId,
            'salesOrderId' => (string)($header['order_id'] ?? ''),
            'salesOrderLineId' => (string)($salesLine['order_line_id'] ?? ''),
            'holderId' => $holderId,
            'catalogProductId' => (int)($line['catalog_product_id'] ?? 0),
            'catalogSkuId' => (int)($line['catalog_sku_id'] ?? 0),
            'ruleType' => $ruleType,
            'ruleVersion' => $ruleVersion,
            'definitionVersion' => $definitionVersion,
            'choiceLimit' => $choiceLimit,
            'sharedTimes' => $sharedTimes,
            'validity' => $validity,
            'components' => array_values((array)($purchase['components'] ?? [])),
        ];
        $fingerprint = self::fingerprint($snapshot);
        $stateDbId = (int)Db::name(self::STATE_TABLE)->insertGetId([
            'state_id' => $stateId,
            'tenant_id' => (string)($header['tenant_id'] ?? ''),
            'receipt_id' => $receiptId,
            'sales_order_id' => (string)($header['order_id'] ?? ''),
            'sales_order_line_id' => (string)($salesLine['order_line_id'] ?? ''),
            'card_holder_id' => $holderId,
            'member_id' => (int)($header['member_id'] ?? 0),
            'issue_store_id' => (int)($header['store_id'] ?? 0),
            'catalog_product_id' => (int)($line['catalog_product_id'] ?? 0),
            'catalog_sku_id' => (int)($line['catalog_sku_id'] ?? 0),
            'rule_type' => $ruleType,
            'rule_version' => $ruleVersion,
            'definition_version' => $definitionVersion,
            'choice_limit' => $choiceLimit,
            'selected_kind_count' => 0,
            'shared_total_times' => $sharedTimes,
            'shared_remaining_times' => $sharedTimes,
            'validity_mode' => (int)($validity['writeValid'] ?? 0),
            'valid_from' => (int)($validity['writeStart'] ?? 0),
            'valid_through' => (int)($validity['writeEnd'] ?? 0),
            'immutable_fingerprint' => $fingerprint,
            'definition_snapshot_json' => self::json($snapshot),
            'state_version' => 1,
            'status' => 'active',
            'occurred_at' => $occurredAt,
            'recorded_at' => $occurredAt,
            'add_time' => $occurredAt,
            'update_time' => $occurredAt,
        ]);
        if ($stateDbId <= 0) {
            throw self::failure('issued_card_rule_state_insert_failed');
        }

        $componentStateIds = [];
        foreach ($components as $index => $issuedComponent) {
            $component = is_array($issuedComponent['snapshot'] ?? null)
                ? $issuedComponent['snapshot'] : [];
            $detailId = (int)($issuedComponent['detailId'] ?? 0);
            $relationId = (int)($component['relationId'] ?? 0);
            $productId = (int)($component['productId'] ?? 0);
            $skuUnique = trim((string)($component['skuUnique'] ?? ''));
            $totalTimes = in_array($ruleType, ['normal', 'choice_kind'], true)
                ? (int)($component['writeTimes'] ?? 0) : 0;
            if ($detailId <= 0 || $relationId <= 0 || $productId <= 0 || $skuUnique === '') {
                throw self::failure('issued_card_rule_component_invalid');
            }
            $componentStateId = 'CRC-' . strtoupper(substr(hash(
                'sha256',
                $stateId . '|' . $relationId . '|' . $productId . '|' . $skuUnique
            ), 0, 40));
            $componentFingerprint = self::fingerprint($component);
            $id = (int)Db::name(self::COMPONENT_TABLE)->insertGetId([
                'component_state_id' => $componentStateId,
                'tenant_id' => (string)($header['tenant_id'] ?? ''),
                'rule_state_id' => $stateDbId,
                'card_holder_id' => $holderId,
                'legacy_detail_id' => $detailId,
                'relation_id' => $relationId,
                'project_product_id' => $productId,
                'project_sku_id' => (int)($component['skuId'] ?? 0),
                'project_sku_unique' => $skuUnique,
                'project_type' => (int)($component['productType'] ?? 0),
                'project_name_snapshot' => (string)($component['nameSnapshot'] ?? ''),
                'total_times' => $totalTimes,
                'remaining_times' => $totalTimes,
                'writeoff_amount_cents' => (int)($component['writeoffAmountCents'] ?? 0),
                'selection_status' => $ruleType === 'choice_kind' ? 'candidate' : 'not_applicable',
                'selected_at' => 0,
                'selected_store_id' => 0,
                'immutable_fingerprint' => $componentFingerprint,
                'component_snapshot_json' => self::json($component),
                'state_version' => 1,
                'status' => 'active',
                'occurred_at' => $occurredAt,
                'recorded_at' => $occurredAt,
                'add_time' => $occurredAt,
                'update_time' => $occurredAt,
            ]);
            if ($id <= 0) {
                throw self::failure('issued_card_rule_component_insert_failed', ['component' => $index]);
            }
            $componentStateIds[] = $componentStateId;
        }

        return [
            'stateId' => $stateId,
            'stateDbId' => $stateDbId,
            'ruleType' => $ruleType,
            'immutableFingerprint' => $fingerprint,
            'componentStateIds' => $componentStateIds,
        ];
    }

    private function assertDefinition(
        string $ruleType,
        int $ruleVersion,
        int $definitionVersion,
        int $choiceLimit,
        int $sharedTimes,
        int $holderId,
        array $components
    ): void {
        if (!in_array($ruleType, ['normal', 'choice_kind', 'choice_count', 'time'], true)
            || $ruleVersion <= 0 || $definitionVersion <= 0 || $holderId <= 0 || !$components
            || ($ruleType === 'choice_kind' ? ($choiceLimit <= 0 || $choiceLimit > count($components)) : $choiceLimit !== 0)
            || ($ruleType === 'choice_count' ? $sharedTimes <= 0 : $sharedTimes !== 0)) {
            throw self::failure('issued_card_rule_definition_invalid');
        }
    }

    private function assertReady(): void
    {
        $tables = ['eb_' . self::STATE_TABLE, 'eb_' . self::COMPONENT_TABLE];
        $rows = Db::query(
            'SELECT TABLE_NAME AS table_name FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?)',
            $tables
        );
        $available = [];
        foreach ((array)$rows as $row) {
            $available[] = (string)($row['table_name'] ?? $row['TABLE_NAME'] ?? '');
        }
        $missing = array_values(array_diff($tables, $available));
        if ($missing) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_DEPENDENCY_NOT_READY,
                '新卡项规则底座尚未完成升级，请联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'issued_card_rule_tables_missing', 'missing_tables' => $missing]
            );
        }
    }

    private static function fingerprint(array $value): string
    {
        $json = json_encode(self::canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw self::failure('issued_card_rule_fingerprint_failed');
        }
        return hash('sha256', $json);
    }

    private static function json(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw self::failure('issued_card_rule_json_failed');
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
            '卡项规则签发未完成，本次结账已全部回滚，请重试。',
            CashierV3ResultCode::STATUS_FAILED,
            $detail
        );
    }
}
