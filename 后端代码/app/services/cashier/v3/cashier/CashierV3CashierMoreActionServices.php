<?php

namespace app\services\cashier\v3\cashier;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/** Transaction-only authority for the three cashier "more actions" edits. */
final class CashierV3CashierMoreActionServices
{
    private const DRAFT_TABLE = 'cashier_v3_workspace_draft';
    private const LINE_TABLE = 'cashier_v3_workspace_line';

    private const ACTION_ORDER_NOTE = 'update-cashier-order-note';
    private const ACTION_LINE_PRICE = 'update-cashier-line-price';
    private const ACTION_SUPPLEMENT = 'update-cashier-supplement';
    private const ACTION_CHANGE_SUPPLEMENT_DATE = 'change-supplement-date';
    private const ACTION_EXIT_SUPPLEMENT = 'exit-supplement';

    /** @var CashierV3CashierWorkspaceServices */
    private $workspace;

    public function __construct(CashierV3CashierWorkspaceServices $workspace)
    {
        $this->workspace = $workspace;
    }

    public function mutateInTx(string $action, array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('cashierMoreAction:' . $action);
        $operatorScope = $scope['operator_scope'];
        $dataScope = $scope['data_scope'];
        if (!$operatorScope instanceof CashierV3OperatorScope
            || !$dataScope instanceof CashierV3DataScopeContext) {
            throw self::invalid('cashier_more_action_scope_invalid');
        }
        $stateContextId = trim((string)($scope['state_context_id'] ?? ''));
        $workspaceId = \app\services\cashier\v3\CashierV3CheckoutWorkspaceIdentity::id(
            $operatorScope->storeId(),
            $stateContextId
        );
        $payload = is_array($scope['payload'] ?? null) ? $scope['payload'] : [];
        $draft = $this->lockDraft($workspaceId, $stateContextId, $operatorScope, $dataScope);
        $this->assertEditable($draft);

        switch ($action) {
            case self::ACTION_ORDER_NOTE:
                $message = $this->updateOrderNote($workspaceId, $payload);
                break;
            case self::ACTION_LINE_PRICE:
                $message = $this->updateLinePrice($workspaceId, $payload, $operatorScope, $dataScope);
                break;
            case self::ACTION_SUPPLEMENT:
            case self::ACTION_CHANGE_SUPPLEMENT_DATE:
                $message = $this->updateSupplement($workspaceId, $payload, $dataScope);
                break;
            case self::ACTION_EXIT_SUPPLEMENT:
                $message = $this->clearSupplement($workspaceId);
                break;
            default:
                throw self::invalid('cashier_more_action_not_supported');
        }

        return [
            'cashierDraft' => $this->workspace->readDraft(
                $workspaceId,
                $stateContextId,
                $operatorScope,
                true
            ),
            'message' => $message,
        ];
    }

    private function updateOrderNote(string $workspaceId, array $payload): string
    {
        $note = self::text($payload['orderNote'] ?? $payload['note'] ?? '', 500, true, 'order_note_invalid');
        $this->updateDraft($workspaceId, [
            'order_note' => $note,
        ]);
        return $note === '' ? '订单备注已清空。' : '订单备注已保存。';
    }

    private function updateLinePrice(
        string $workspaceId,
        array $payload,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): string {
        $lineKey = self::token(
            $payload['lineId'] ?? $payload['line_id'] ?? '',
            64,
            'price_change_line_invalid'
        );
        $reason = self::text(
            $payload['reason'] ?? $payload['priceChangeReason'] ?? '',
            255,
            false,
            'price_change_reason_invalid'
        );
        $lineAmountCents = self::money(
            $payload['lineAmountCents'] ?? $payload['amountCents'] ?? null,
            'price_change_amount_invalid'
        );
        if ($lineAmountCents % 100 !== 0) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '改价金额必须填写整数元。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'price_change_whole_yuan_required']
            );
        }
        $line = Db::name(self::LINE_TABLE)
            ->where('workspace_id', $workspaceId)
            ->where('line_key', $lineKey)
            ->lock(true)
            ->find();
        if (!$line || (string)($line['line_role'] ?? '') !== 'sale') {
            throw new CashierV3CommandException(
                CashierV3ResultCode::RESOURCE_NOT_FOUND,
                '该购物车商品不存在或已经删除。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'price_change_sale_line_missing', 'line_id' => $lineKey]
            );
        }
        $quantity = (int)($line['quantity'] ?? 0);
        $productId = (int)($line['catalog_product_id'] ?? 0);
        $skuId = (int)($line['catalog_sku_id'] ?? 0);
        if ($quantity <= 0 || $productId <= 0 || $skuId <= 0
            || $lineAmountCents % $quantity !== 0) {
            throw self::invalid('price_change_line_amount_not_divisible');
        }
        // Product then SKU matches the catalog gateway's stable lock order.
        $product = Db::name('store_product')
            ->where('id', $productId)
            ->where('type', 1)
            ->where('relation_id', $operatorScope->storeId())
            ->where('is_del', 0)
            ->field('id,store_name,product_type')
            ->lock(true)
            ->find();
        $sku = $product
            ? Db::name('store_product_attr_value')
                ->where('id', $skuId)
                ->where('product_id', $productId)
                ->where('type', 0)
                ->field('id,product_id,cost,price,ot_price')
                ->lock(true)
                ->find()
            : null;
        if (!$product || !$sku) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::RESOURCE_VERSION_CONFLICT,
                '商品资料已经变化，请刷新后重新操作。',
                CashierV3ResultCode::STATUS_CONFLICT,
                ['reason' => 'price_change_catalog_authority_missing', 'line_id' => $lineKey]
            );
        }
        $authoritySnapshot = json_decode((string)($line['authority_snapshot_json'] ?? ''), true);
        if (!is_array($authoritySnapshot)) {
            throw self::invalid('price_change_authority_snapshot_invalid');
        }
        $isCustomCard = (string)($authoritySnapshot['cardPurchase']['sourceKind'] ?? '') === 'custom_card';
        $configuredCostCents = $isCustomCard
            ? self::customCardConfiguredCostCents($authoritySnapshot)
            : self::decimalMoneyToCents($sku['cost'] ?? 0, 'sku_cost_invalid');
        $minimumLineAmountCents = self::roundUpToWholeYuan(
            self::multiply($configuredCostCents, $quantity, 'price_change_cost_overflow')
        );
        if ($lineAmountCents < $minimumLineAmountCents) {
            $name = trim((string)($product['store_name'] ?? '')) ?: '该商品';
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                $name . '最低可改为 ' . self::formatMoney($minimumLineAmountCents)
                    . ($isCustomCard ? '，不能低于卡内项目成本合计。' : '，不能低于成本价。'),
                CashierV3ResultCode::STATUS_FAILED,
                [
                    'reason' => 'price_change_below_configured_cost',
                    'line_id' => $lineKey,
                    'configured_cost_cents' => $configuredCostCents,
                    'minimum_line_amount_cents' => $minimumLineAmountCents,
                ]
            );
        }
        $now = time();
        $operatorName = self::operatorName($dataScope->operatorProfile());
        $affected = Db::name(self::LINE_TABLE)
            ->where('id', (int)$line['id'])
            ->where('workspace_id', $workspaceId)
            ->where('line_key', $lineKey)
            ->update([
                'unit_price_cents' => intdiv($lineAmountCents, $quantity),
                'configured_cost_cents' => $configuredCostCents,
                'price_change_reason' => $reason,
                'price_changed_by' => $operatorScope->operatorId(),
                'price_changed_by_name_snapshot' => $operatorName,
                'price_changed_at' => $now,
                'update_time' => $now,
            ]);
        if ((int)$affected !== 1) {
            throw self::invalid('price_change_line_update_failed');
        }
        $this->refreshLineFingerprint($workspaceId);
        return '商品价格已更新。';
    }

    private function updateSupplement(
        string $workspaceId,
        array $payload,
        CashierV3DataScopeContext $dataScope
    ): string {
        $businessDate = self::businessDate($payload['businessDate'] ?? $payload['business_date'] ?? '');
        if ($businessDate > date('Y-m-d')) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
                '补单日期不能晚于今天。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'supplement_business_date_in_future']
            );
        }
        $reason = self::text(
            $payload['reason'] ?? $payload['supplementReason'] ?? '',
            255,
            false,
            'supplement_reason_invalid'
        );
        $now = time();
        $this->updateDraft($workspaceId, [
            'supplement_enabled' => 1,
            'supplement_business_date' => $businessDate,
            'supplement_reason' => $reason,
            'supplement_operator_id' => $dataScope->operatorId(),
            'supplement_operator_name_snapshot' => self::operatorName($dataScope->operatorProfile()),
            'supplement_operated_at' => $now,
        ]);
        return '补单日期已保存。';
    }

    private function clearSupplement(string $workspaceId): string
    {
        $this->updateDraft($workspaceId, [
            'supplement_enabled' => 0,
            'supplement_business_date' => null,
            'supplement_reason' => '',
            'supplement_operator_id' => 0,
            'supplement_operator_name_snapshot' => '',
            'supplement_operated_at' => 0,
        ]);
        return '已退出补单。';
    }

    private function lockDraft(
        string $workspaceId,
        string $stateContextId,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        if ($stateContextId === '' || strlen($stateContextId) > 64
            || $operatorScope->storeId() !== $dataScope->forcedStoreId()
            || !hash_equals($operatorScope->tenantId(), $dataScope->tenantId())
            || !hash_equals($operatorScope->organizationId(), $dataScope->organizationId())) {
            throw self::invalid('cashier_more_action_scope_binding_invalid');
        }
        $draft = Db::name(self::DRAFT_TABLE)
            ->where('workspace_id', $workspaceId)
            ->where('state_context_id', $stateContextId)
            ->where('store_id', $operatorScope->storeId())
            ->lock(true)
            ->find();
        if (!$draft) {
            throw self::invalid('cashier_more_action_workspace_missing');
        }
        return (array)$draft;
    }

    private function assertEditable(array $draft): void
    {
        if ((string)($draft['draft_status'] ?? '') !== 'editing'
            || trim((string)($draft['resumed_hang_order_id'] ?? '')) !== '') {
            throw new CashierV3CommandException(
                CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
                '当前购物车不能修改，请完成或取消当前结账后重试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['reason' => 'cashier_more_action_workspace_not_editable']
            );
        }
    }

    private function updateDraft(string $workspaceId, array $changes): void
    {
        $changes['update_time'] = time();
        $affected = Db::name(self::DRAFT_TABLE)
            ->where('workspace_id', $workspaceId)
            ->update($changes);
        if ((int)$affected < 0) {
            throw self::invalid('cashier_more_action_draft_update_failed');
        }
    }

    /** Keep this byte-for-byte aligned with the workspace aggregate fingerprint. */
    private function refreshLineFingerprint(string $workspaceId): void
    {
        $rows = Db::name(self::LINE_TABLE)
            ->where('workspace_id', $workspaceId)
            ->order('sort_no asc,id asc')
            ->lock(true)
            ->select();
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        $canonical = [];
        foreach ((array)$rows as $row) {
            $item = [
                'line_key' => (string)($row['line_key'] ?? ''),
                'line_role' => (string)($row['line_role'] ?? ''),
                'member_id' => (int)($row['member_id'] ?? 0),
                'holder_id' => (int)($row['holder_id'] ?? 0),
                'source_detail_id' => (int)($row['source_detail_id'] ?? 0),
                'project_id' => (int)($row['project_id'] ?? 0),
                'quantity' => (int)($row['quantity'] ?? 0),
                'source_version' => (int)($row['source_version'] ?? 0),
                'detail_version' => (int)($row['detail_version'] ?? 0),
                'service_object' => (string)($row['service_object'] ?? ''),
                'craftsmen_json' => (string)($row['craftsmen_json'] ?? ''),
                'salespeople_json' => (string)($row['salespeople_json'] ?? ''),
                'is_experience' => (int)($row['is_experience'] ?? 0),
                'display_snapshot_json' => (string)($row['display_snapshot_json'] ?? ''),
                'sort_no' => (int)($row['sort_no'] ?? 0),
            ];
            if ((string)($row['line_role'] ?? '') === 'sale') {
                $item['catalog_product_id'] = (int)($row['catalog_product_id'] ?? 0);
                $item['catalog_sku_id'] = (int)($row['catalog_sku_id'] ?? 0);
                $item['catalog_product_type'] = (int)($row['catalog_product_type'] ?? 0);
                $item['unit_price_cents'] = (int)($row['unit_price_cents'] ?? 0);
                $item['original_unit_price_cents'] = (int)($row['original_unit_price_cents'] ?? 0);
                $item['configured_cost_cents'] = (int)($row['configured_cost_cents'] ?? 0);
                $item['debt_amount_cents'] = (int)($row['debt_amount_cents'] ?? 0);
                $item['price_change_reason'] = (string)($row['price_change_reason'] ?? '');
                $item['price_changed_by'] = (int)($row['price_changed_by'] ?? 0);
                $item['price_changed_by_name_snapshot'] = (string)($row['price_changed_by_name_snapshot'] ?? '');
                $item['price_changed_at'] = (int)($row['price_changed_at'] ?? 0);
                $item['authority_fingerprint'] = (string)($row['authority_fingerprint'] ?? '');
                $item['authority_snapshot_json'] = (string)($row['authority_snapshot_json'] ?? '');
            }
            $canonical[] = $item;
        }
        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw self::invalid('cashier_more_action_fingerprint_encode_failed');
        }
        $affected = Db::name(self::DRAFT_TABLE)
            ->where('workspace_id', $workspaceId)
            ->update(['line_fingerprint' => hash('sha256', $json), 'update_time' => time()]);
        if ((int)$affected < 0) {
            throw self::invalid('cashier_more_action_fingerprint_update_failed');
        }
    }

    private static function money($value, string $reason): int
    {
        if (is_bool($value) || is_array($value) || is_object($value) || $value === null) {
            throw self::invalid($reason);
        }
        $raw = trim((string)$value);
        if (preg_match('/^(0|[1-9][0-9]{0,11})$/D', $raw) !== 1) {
            throw self::invalid($reason);
        }
        return (int)$raw;
    }

    private static function decimalMoneyToCents($value, string $reason): int
    {
        $raw = trim((string)$value);
        if (preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2})(?:0{0,4})?)?$/D', $raw, $matches) !== 1) {
            throw self::invalid($reason);
        }
        $fraction = str_pad((string)($matches[2] ?? ''), 2, '0');
        return ((int)$matches[1] * 100) + (int)$fraction;
    }

    private static function multiply(int $amount, int $quantity, string $reason): int
    {
        if ($amount < 0 || $quantity <= 0
            || ($amount > 0 && $quantity > intdiv(PHP_INT_MAX, $amount))) {
            throw self::invalid($reason);
        }
        return $amount * $quantity;
    }

    private static function roundUpToWholeYuan(int $amountCents): int
    {
        if ($amountCents < 0 || $amountCents > PHP_INT_MAX - 99) {
            throw self::invalid('price_change_cost_overflow');
        }
        return intdiv($amountCents + 99, 100) * 100;
    }

    private static function customCardConfiguredCostCents(array $authoritySnapshot): int
    {
        $components = $authoritySnapshot['cardPurchase']['components'] ?? null;
        if (!is_array($components) || $components === []) {
            throw self::invalid('custom_card_cost_components_missing');
        }
        $total = 0;
        foreach ($components as $component) {
            if (!is_array($component)) {
                throw self::invalid('custom_card_cost_component_invalid');
            }
            $cost = (int)($component['configuredCostCents'] ?? -1);
            $quantity = (int)($component['writeTimes'] ?? 0);
            $lineCost = self::multiply($cost, $quantity, 'custom_card_cost_overflow');
            if ($lineCost > PHP_INT_MAX - $total) {
                throw self::invalid('custom_card_cost_overflow');
            }
            $total += $lineCost;
        }
        return $total;
    }

    private static function businessDate($value): string
    {
        $date = trim((string)$value);
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date) !== 1) {
            throw self::invalid('supplement_business_date_invalid');
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Asia/Shanghai'));
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw self::invalid('supplement_business_date_invalid');
        }
        return $date;
    }

    private static function text($value, int $maxLength, bool $allowEmpty, string $reason): string
    {
        if (is_array($value) || is_object($value) || is_bool($value)) {
            throw self::invalid($reason);
        }
        $text = trim((string)$value);
        if ((!$allowEmpty && $text === '') || mb_strlen($text) > $maxLength || strpos($text, "\0") !== false) {
            throw self::invalid($reason);
        }
        return $text;
    }

    private static function token($value, int $maxLength, string $reason): string
    {
        $token = self::text($value, $maxLength, false, $reason);
        if (preg_match('/^[A-Za-z0-9:_-]+$/D', $token) !== 1) {
            throw self::invalid($reason);
        }
        return $token;
    }

    private static function operatorName(array $profile): string
    {
        foreach (['staff_name', 'real_name', 'name', 'account'] as $field) {
            $name = trim((string)($profile[$field] ?? ''));
            if ($name !== '') {
                return mb_substr($name, 0, 128);
            }
        }
        throw self::invalid('cashier_more_action_operator_name_missing');
    }

    private static function formatMoney(int $amountCents): string
    {
        return '¥' . number_format($amountCents / 100, 2, '.', '');
    }

    private static function invalid(string $reason): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '本次操作资料不完整，请重新填写后再试。',
            CashierV3ResultCode::STATUS_FAILED,
            ['reason' => $reason]
        );
    }
}
