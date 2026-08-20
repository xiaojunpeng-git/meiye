<?php

namespace app\services\cashier\v3\card;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
use think\facade\Db;

/**
 * Creates the immutable configuration behind a custom-card sale line.
 * The configuration is not an entitlement and becomes one only through the
 * final checkout transaction and the normal card-purchase issuer.
 */
final class CashierV3CustomCardConfigurationServices
{
    public const TABLE = 'cashier_v3_custom_card_configuration';

    private $catalog;

    public function __construct(CashierV3SaleCatalogServices $catalog)
    {
        $this->catalog = $catalog;
    }

    /** Resource discovery is server-derived; clients never submit versions. */
    public function discoverCreateResources(
        array $payload,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        bool $directSnapshot = false
    ): array {
        $input = $this->normalizeInput($payload);
        // Browser snapshot checkout has no pre-submit catalogue eligibility
        // phase. The final transaction itself locks and materializes the
        // custom-card shell and components, then performs the applicable
        // entitlement/balance/inventory checks. A later show/verify flag must
        // not reject the browser snapshot before that final boundary.
        if ($directSnapshot) {
            return [];
        }
        $resources = $this->catalog->discoverCustomCardShellResources($operatorScope, $dataScope);
        foreach ($input['components'] as $component) {
            foreach ($this->catalog->discoverItemResources($component['skuId'], $operatorScope, $dataScope) as $resource) {
                $resources[] = $resource;
            }
        }
        return $this->uniqueResources($resources);
    }

    public function createSaleLineAfterGatewayLocksInTx(
        array $payload,
        string $workspaceId,
        string $stateContextId,
        string $idempotencyKey,
        array $lockedContexts,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        int $snapshotMemberId = 0,
        bool $directSnapshot = false,
        string $snapshotLineId = ''
    ): array {
        CashierV3TransactionGuard::assertInTransaction('customCardConfigurationCreate');
        $input = $this->normalizeInput($payload);
        $idempotencyKey = trim($idempotencyKey);
        if ($workspaceId === '' || $stateContextId === '' || $idempotencyKey === '') {
            throw self::failure('custom_card_command_context_invalid', '定制卡工作台已失效，请刷新后重新配置。');
        }
        $memberId = $snapshotMemberId;
        if (!$directSnapshot) {
            $draft = (array)Db::name('cashier_v3_workspace_draft')
                ->where('workspace_id', $workspaceId)
                ->where('store_id', $operatorScope->storeId())
                ->lock(true)
                ->find();
            $memberId = (int)($draft['member_id'] ?? 0);
            if ((string)($draft['state_context_id'] ?? '') !== $stateContextId || $memberId <= 0
                || (string)($draft['customer_mode'] ?? '') !== 'member') {
                throw self::failure('custom_card_member_required', '请先创建会员档案，再购买卡项。');
            }
            $existingRows = Db::name('cashier_v3_workspace_line')
                ->where('workspace_id', $workspaceId)
                ->lock(true)
                ->field('line_key')
                ->select();
            $existingRows = is_object($existingRows) && method_exists($existingRows, 'toArray') ? $existingRows->toArray() : (array)$existingRows;
            if ($existingRows) {
                throw self::failure('custom_card_cart_conflict', '定制卡不能与其他商品同时结账，请先处理当前订单。');
            }
        }
        if ($memberId <= 0) {
            throw self::failure('custom_card_member_required', '请先选择会员，再购买卡项。');
        }

        $components = [];
        $configuredCostTotalCents = 0;
        foreach ($input['components'] as $index => $component) {
            $line = $directSnapshot
                ? $this->catalog->selectDraftSaleLineAfterGatewayLocksInTx(
                    $component['skuId'],
                    $idempotencyKey . '-C' . ($index + 1),
                    $operatorScope,
                    $dataScope
                )
                : $this->catalog->selectSaleLineAfterGatewayLocksInTx(
                    $component['skuId'],
                    $idempotencyKey . '-C' . ($index + 1),
                    $lockedContexts,
                    $operatorScope,
                    $dataScope
                );
            if ((int)($line['catalog_product_type'] ?? 0) !== 6) {
                throw self::failure('custom_card_component_not_project', '定制卡只能配置项目。');
            }
            $authority = is_array($line['authority_snapshot'] ?? null) ? $line['authority_snapshot'] : [];
            $display = is_array($line['display_snapshot'] ?? null) ? $line['display_snapshot'] : [];
            $categoryId = (int)($display['categoryId'] ?? 0);
            $categoryName = trim((string)($display['category'] ?? ''));
            if ($categoryId <= 0) {
                throw self::failure('custom_card_component_category_invalid', '定制卡卡内项目分类资料不完整，请重新配置。');
            }
            if ($categoryName === '' || $categoryName === '全部') {
                $categoryName = '未分类';
            }
            $configuredCostCents = (int)($line['configured_cost_cents'] ?? -1);
            if ($configuredCostCents < 0) {
                throw self::failure('custom_card_component_cost_invalid', '定制卡卡内项目成本资料不完整，请重新配置。');
            }
            if ($configuredCostCents > 0 && $component['times'] > intdiv(PHP_INT_MAX, $configuredCostCents)) {
                throw self::failure('custom_card_component_cost_overflow', '定制卡卡内项目成本金额过大。');
            }
            $componentCostTotalCents = $configuredCostCents * $component['times'];
            if ($componentCostTotalCents > PHP_INT_MAX - $configuredCostTotalCents) {
                throw self::failure('custom_card_component_cost_overflow', '定制卡卡内项目成本金额过大。');
            }
            $configuredCostTotalCents += $componentCostTotalCents;
            $components[] = [
                'productId' => (int)$line['catalog_product_id'],
                'productType' => 6,
                'skuId' => (int)$line['catalog_sku_id'],
                'skuUnique' => (string)($authority['sku']['unique'] ?? ''),
                'nameSnapshot' => (string)($display['name'] ?? ''),
                'categoryIdSnapshot' => $categoryId,
                'categoryNameSnapshot' => $categoryName,
                'writeTimes' => $component['times'],
                // A configured amount belongs to the entire project entitlement,
                // not to each individual use. It therefore may not be multiplied.
                'configuredAmountCents' => $component['amountCents'],
                'configuredCostCents' => $configuredCostCents,
                'resourceSources' => (array)($authority['resourceSources'] ?? []),
            ];
        }
        $total = 0;
        foreach ($components as $component) {
            $amount = (int)$component['configuredAmountCents'];
            if ($amount > PHP_INT_MAX - $total) {
                throw self::failure('custom_card_total_overflow', '定制卡合计金额过大。');
            }
            $total += $amount;
        }
        if ($total <= 0) {
            throw self::failure('custom_card_total_invalid', '定制卡合计金额必须大于 0。');
        }
        $minimumTotalCents = self::roundUpToWholeYuan($configuredCostTotalCents);
        if ($total < $minimumTotalCents) {
            throw self::failure(
                'custom_card_total_below_cost',
                '定制卡合计金额不能低于卡内项目成本合计 ' . self::formatMoney($minimumTotalCents) . '。'
            );
        }
        $snapshot = [
            'contractVersion' => 'cashier-v3-custom-card-configuration-v1',
            'cardName' => $input['cardName'],
            'validityEnd' => $input['validityEnd'],
            'activateOnPurchase' => $input['activateOnPurchase'],
            'totalAmountCents' => $total,
            'components' => $components,
        ];
        $fingerprint = hash('sha256', $this->canonicalJson($snapshot));
        $configurationId = 'CCD-' . strtoupper(substr(hash('sha256', implode('|', [
            $dataScope->tenantId(), $operatorScope->storeId(), $workspaceId, $idempotencyKey,
        ])), 0, 40));
        // The persisted checkout line is keyed from the browser snapshot's
        // lineId. Use exactly that identity for a direct snapshot so card
        // issuance can find this configuration without falling back to the
        // hidden legacy shell SKU. Legacy mutable-workspace commands retain
        // their idempotency-derived line key.
        $snapshotLineId = trim($snapshotLineId);
        $lineKey = $directSnapshot && $snapshotLineId !== ''
            ? 'sale:' . substr($snapshotLineId, 0, 59)
            : 'sale:' . substr(hash('sha256', $idempotencyKey), 0, 48);
        $configuration = (array)Db::name(self::TABLE)
            ->where('configuration_id', $configurationId)
            ->where('tenant_id', $dataScope->tenantId())
            ->lock(true)
            ->find();
        if ($configuration) {
            if (!hash_equals((string)($configuration['immutable_fingerprint'] ?? ''), $fingerprint)
                || (string)($configuration['workspace_id'] ?? '') !== $workspaceId
                || (int)($configuration['member_id'] ?? 0) !== $memberId) {
                throw self::failure('custom_card_idempotency_conflict', '本次定制卡配置已被其他内容占用，请重新操作。');
            }
        } else {
            $now = time();
            $inserted = Db::name(self::TABLE)->insert([
                'configuration_id' => $configurationId,
                'tenant_id' => $dataScope->tenantId(),
                'store_id' => $operatorScope->storeId(),
                'workspace_id' => $workspaceId,
                'workspace_line_key' => $lineKey,
                'member_id' => $memberId,
                'card_name_snapshot' => $input['cardName'],
                'validity_end_at' => $input['validityEnd'],
                'activate_on_purchase' => $input['activateOnPurchase'] ? 1 : 0,
                'total_amount_cents' => $total,
                'configuration_snapshot_json' => $this->canonicalJson($snapshot),
                'immutable_fingerprint' => $fingerprint,
                'status' => 'in_cart',
                'resource_version' => 1,
                'created_command_idempotency_key' => $idempotencyKey,
                'checkout_request_id' => '',
                'sales_order_line_id' => '',
                'card_holder_id' => 0,
                'settled_at' => 0,
                'add_time' => $now,
                'update_time' => $now,
            ]);
            if ((int)$inserted !== 1) {
                throw self::failure('custom_card_configuration_insert_failed', '定制卡配置保存失败，已回滚。');
            }
            $configuration = (array)Db::name(self::TABLE)
                ->where('configuration_id', $configurationId)
                ->lock(true)
                ->find();
        }
        $configuration['configuration_snapshot'] = $snapshot;
        return $this->catalog->customCardSaleLineAfterGatewayLocksInTx(
            $configuration,
            $idempotencyKey,
            $lockedContexts,
            $operatorScope,
            $dataScope,
            $directSnapshot
        );
    }

    private function normalizeInput(array $payload): array
    {
        $cardName = trim((string)($payload['cardName'] ?? ''));
        if ($cardName === '' || mb_strlen($cardName) > 64) {
            throw self::failure('custom_card_name_invalid', '请填写 1 至 64 个字的卡片名称。');
        }
        $date = trim((string)($payload['validityEnd'] ?? ''));
        $dateObject = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Asia/Shanghai'));
        if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
            throw self::failure('custom_card_validity_invalid', '请选择有效期。');
        }
        $validityEnd = $dateObject->setTime(23, 59, 59)->getTimestamp();
        if ($validityEnd <= time()) {
            throw self::failure('custom_card_validity_expired', '有效期必须晚于当前日期。');
        }
        if (!array_key_exists('activateOnPurchase', $payload) || !is_bool($payload['activateOnPurchase'])) {
            throw self::failure('custom_card_activation_invalid', '请选择是否购卡后立即开卡。');
        }
        $rawComponents = is_array($payload['components'] ?? null) ? array_values($payload['components']) : [];
        if (!$rawComponents || count($rawComponents) > 30) {
            throw self::failure('custom_card_components_invalid', '请至少选择一项、至多选择 30 项项目。');
        }
        $components = [];
        $seen = [];
        foreach ($rawComponents as $raw) {
            if (!is_array($raw)) {
                throw self::failure('custom_card_component_invalid', '定制卡项目配置无效。');
            }
            $skuId = $this->positiveInt($raw['skuId'] ?? null, 'custom_card_component_sku_invalid');
            $times = $this->positiveInt($raw['times'] ?? null, 'custom_card_component_times_invalid');
            if ($times > 10000 || isset($seen[$skuId])) {
                throw self::failure('custom_card_component_duplicate_or_times_invalid', '项目不能重复，次数最多为 10000。');
            }
            $seen[$skuId] = true;
            $components[] = ['skuId' => $skuId, 'times' => $times, 'amountCents' => $this->moneyToCents($raw['amount'] ?? null)];
        }
        return [
            'cardName' => $cardName,
            'validityEnd' => $validityEnd,
            'activateOnPurchase' => $payload['activateOnPurchase'],
            'components' => $components,
        ];
    }

    private function positiveInt($value, string $reason): int
    {
        if (is_bool($value) || is_array($value) || is_object($value) || $value === null
            || preg_match('/^[1-9][0-9]*$/D', trim((string)$value)) !== 1) {
            throw self::failure($reason, '定制卡项目配置无效。');
        }
        return (int)$value;
    }

    private function moneyToCents($value): int
    {
        if (is_bool($value) || is_array($value) || is_object($value) || $value === null) {
            throw self::failure('custom_card_component_amount_invalid', '项目金额必须是有效金额。');
        }
        $value = trim((string)$value);
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw self::failure('custom_card_component_amount_invalid', '项目金额必须填写整数元。');
        }
        $whole = ltrim($value, '0');
        if ($whole === '') {
            return 0;
        }
        if (strlen($whole) > 13) {
            throw self::failure('custom_card_component_amount_overflow', '项目金额过大。');
        }
        return (int)$whole * 100;
    }

    private static function roundUpToWholeYuan(int $amountCents): int
    {
        if ($amountCents < 0 || $amountCents > PHP_INT_MAX - 99) {
            throw self::failure('custom_card_component_cost_overflow', '定制卡卡内项目成本金额过大。');
        }
        return intdiv($amountCents + 99, 100) * 100;
    }

    private static function formatMoney(int $amountCents): string
    {
        return '¥' . number_format($amountCents / 100, 2, '.', ',');
    }

    private function uniqueResources(array $resources): array
    {
        $out = [];
        foreach ($resources as $resource) {
            $key = (string)($resource['kind'] ?? '') . ':' . (string)($resource['id'] ?? '');
            if ($key !== ':' && !isset($out[$key])) {
                $out[$key] = $resource;
            }
        }
        return array_values($out);
    }

    private function canonicalJson(array $value): string
    {
        $json = json_encode($this->canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw self::failure('custom_card_snapshot_encode_failed', '定制卡配置保存失败。');
        }
        return $json;
    }

    private function canonicalize($value)
    {
        if (!is_array($value)) return $value;
        if (array_keys($value) === range(0, count($value) - 1)) {
            return array_map([$this, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }

    private static function failure(string $reason, string $message): CashierV3CommandException
    {
        return new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, $message, CashierV3ResultCode::STATUS_FAILED, ['reason' => $reason]);
    }
}
