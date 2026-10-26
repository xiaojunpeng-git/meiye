<?php
declare(strict_types=1);

/**
 * Import one frozen old H5 "可使用" snapshot as hidden, redeemable rights.
 *
 * This script creates no sales, receipt, payment or performance fact. Its
 * zero-amount order rows are technical V3 entitlement carriers, hidden from
 * ordinary order queries by is_system_del=1. The source order's paid amount,
 * name, dates and old store remain in immutable migration scope snapshots.
 * Each source cart detail owns one order, one holder and one idempotency key.
 */

require_once __DIR__ . '/customer_008_morestore_rights_prepare.php';
require_once __DIR__ . '/customer_008_morestore_catalog_apply.php';

const RIGHTS_TARGET_ENV = '/www/wwwroot/008.cc3798.com/.env';
const RIGHTS_SCOPE_TABLE = 'eb_migration_entitlement_scope';
const RIGHTS_MEMBER_MAP = 'mig_morestore_20261013_member_map';
const RIGHTS_ORDER_MARK = 'migration_entitlement';

/** The target must be the 008 database on its dedicated MySQL port. */
function rightsTarget(string $envFile): PDO
{
    return catalogConnect($envFile, 'mohe_008', 3307);
}

/** Read the exact frozen source set, never a later moving source query. */
function rightsSnapshot(string $file, string $expectedSha): array
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $expectedSha) || !is_file($file)
        || !hash_equals($expectedSha, (string)hash_file('sha256', $file))) {
        throw new RuntimeException('Frozen source snapshot SHA-256 mismatch');
    }
    $rows = [];
    $handle = fopen($file, 'r');
    if ($handle === false) throw new RuntimeException('Cannot read source snapshot');
    try {
        while (($line = fgets($handle)) !== false) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $id = (int)($row['source_detail_id'] ?? 0);
            if ($id <= 0 || isset($rows[$id]) || (int)($row['source_remaining_times'] ?? 0) <= 0
                || (int)($row['source_remaining_times'] ?? 0) > (int)($row['source_total_times'] ?? 0)
                || trim((string)($row['purchase_name'] ?? '')) === '') {
                throw new RuntimeException('Invalid frozen right at source detail ' . $id);
            }
            $rows[$id] = $row;
        }
    } finally {
        fclose($handle);
    }
    if (!$rows) throw new RuntimeException('Empty rights snapshot');
    return $rows;
}

/** Insert only whitelisted columns; all other model defaults remain intact. */
function rightsInsert(PDO $target, string $table, array $row): int
{
    return catalogInsert($target, $table, $row);
}

/** Query the target UID assigned by the separate member batch. */
function rightsMemberMap(PDO $target, array $sourceUids): array
{
    $rows = $target->query('SELECT source_uid,target_uid FROM ' . RIGHTS_MEMBER_MAP)->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $row) {
        $map[(int)$row['source_uid']] = (int)$row['target_uid'];
    }
    foreach ($sourceUids as $uid) {
        if (($map[$uid] ?? 0) <= 0) throw new RuntimeException('Member map missing source UID ' . $uid);
    }
    return $map;
}

/** The brand organization is resolved from target data, not hardcoded in core services. */
function rightsOwnerOrg(PDO $target): int
{
    $rows = $target->query("SELECT id,pid FROM eb_organization WHERE name='渼约' AND status=1 AND is_del=0")->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 1 || (int)$rows[0]['pid'] <= 0) {
        throw new RuntimeException('Reviewed target owner organization is ambiguous');
    }
    return (int)$rows[0]['id'];
}

/** Existing catalogue IDs may be referenced only after their type and SKU match. */
function rightsCatalog(PDO $target, array $products): array
{
    $verified = [];
    $productQuery = $target->prepare('SELECT id,product_type,is_del,store_name FROM eb_store_product WHERE id=?');
    $skuQuery = $target->prepare('SELECT id,`unique` FROM eb_store_product_attr_value WHERE id=? AND product_id=? AND product_type=?');
    foreach ($products as $oldId => $row) {
        $targetId = (int)($row['target_product_id'] ?? 0);
        if ($targetId <= 0) continue;
        $productQuery->execute([$targetId]);
        $product = $productQuery->fetch(PDO::FETCH_ASSOC);
        $skuQuery->execute([(int)$row['target_sku_id'], $targetId, (int)$row['target_type']]);
        $sku = $skuQuery->fetch(PDO::FETCH_ASSOC);
        if (!$product || !$sku || (int)$product['is_del'] !== 0
            || (int)$product['product_type'] !== (int)$row['target_type']
            || (string)$sku['unique'] !== (string)$row['target_sku_unique']) {
            throw new RuntimeException('Catalog target changed for old product ' . $oldId);
        }
        $verified[$oldId] = $row;
    }
    return $verified;
}

/** Hidden product templates are reusable by old name, but never sold. */
function rightsHiddenProduct(PDO $target, string $name, int $type, array &$cache): array
{
    $name = mb_substr(trim($name), 0, 128);
    $key = $type . '|' . $name;
    if (isset($cache[$key])) return $cache[$key];
    $keyword = 'migration_entitlement_' . $type . '_' . substr(hash('sha256', $name), 0, 32);
    $find = $target->prepare('SELECT id,product_type,is_show,is_del FROM eb_store_product WHERE keyword=? AND type=0 LIMIT 2');
    $find->execute([$keyword]);
    $existing = $find->fetchAll(PDO::FETCH_ASSOC);
    if (count($existing) > 1) throw new RuntimeException('Ambiguous historical product keyword');
    if ($existing) {
        $productId = (int)$existing[0]['id'];
        if ((int)$existing[0]['product_type'] !== $type || (int)$existing[0]['is_show'] !== 0
            || (int)$existing[0]['is_del'] !== 0) throw new RuntimeException('Historical product changed');
    } else {
        $productId = rightsInsert($target, 'eb_store_product', [
            'pid' => 0, 'type' => 0, 'relation_id' => 0, 'product_type' => $type,
            'store_name' => $name, 'store_info' => '历史权益核销载体', 'keyword' => $keyword,
            'unit_name' => '次', 'price' => 0, 'stock' => 0, 'sales' => 0, 'ficti' => 0,
            'browse' => 0, 'is_show' => 0, 'is_del' => 0, 'is_inventory' => 0,
            'allow_negative_stock' => 1, 'salon_stock_enabled' => 0,
            'is_show_stock' => 0, 'is_support_refund' => 0, 'add_time' => time(),
        ]);
    }
    $skuFind = $target->prepare('SELECT id,`unique` FROM eb_store_product_attr_value WHERE product_id=? AND product_type=? LIMIT 2');
    $skuFind->execute([$productId, $type]);
    $skuRows = $skuFind->fetchAll(PDO::FETCH_ASSOC);
    if (count($skuRows) > 1) throw new RuntimeException('Ambiguous historical SKU');
    if ($skuRows) {
        $sku = $skuRows[0];
    } else {
        $unique = substr(hash('sha256', $keyword . '|sku'), 0, 8);
        $skuId = rightsInsert($target, 'eb_store_product_attr_value', [
            'type' => 0, 'product_id' => $productId, 'product_type' => $type,
            'suk' => '默认', 'price' => 0, 'stock' => 0, 'sum_stock' => 0,
            'is_show' => 0, 'is_default_select' => 1, 'write_times' => 1,
            'write_valid' => 1, 'unique' => $unique,
        ]);
        $sku = ['id' => $skuId, 'unique' => $unique];
    }
    return $cache[$key] = ['product_id' => $productId,
        'sku_id' => (int)$sku['id'], 'sku_unique' => (string)$sku['unique']];
}

/** Build a card/project selection from the reviewed catalogue rules. */
function rightsDefinition(PDO $target, array $source, array $mapped, array &$cache): array
{
    $kind = (string)($mapped['kind'] ?? 'unmapped');
    $name = (string)$source['purchase_name'];
    if ($kind === 'card') {
        $card = ['product_id' => (int)$mapped['target_product_id'],
            'sku_id' => (int)$mapped['target_sku_id'],
            'sku_unique' => (string)$mapped['target_sku_unique']];
        $query = $target->prepare('SELECT r.id relation_id,r.product_id,r.product_attr_unique,
            p.store_name,a.id sku_id FROM eb_store_card_related r
            JOIN eb_store_product p ON p.id=r.product_id AND p.product_type=6 AND p.is_del=0
            JOIN eb_store_product_attr_value a ON a.product_id=r.product_id
                AND a.`unique`=r.product_attr_unique AND a.product_type=6
            WHERE r.card_product_id=? AND r.status=1 ORDER BY r.id');
        $query->execute([$card['product_id']]);
        $projects = $query->fetchAll(PDO::FETCH_ASSOC);
        $rule = (string)$mapped['card_rule'];
        if (($rule === 'normal' && count($projects) !== 1)
            || ($rule === 'choice_count' && count($projects) < 2)) {
            throw new RuntimeException('Reviewed card projects changed: ' . $card['product_id']);
        }
        return [$card, $projects, $rule];
    }
    $card = $kind === 'shell'
        ? ['product_id' => (int)$mapped['target_product_id'],
            'sku_id' => (int)$mapped['target_sku_id'],
            'sku_unique' => (string)$mapped['target_sku_unique']]
        : rightsHiddenProduct($target, $name, 5, $cache);
    $project = $kind === 'project'
        ? ['product_id' => (int)$mapped['target_product_id'],
            'sku_id' => (int)$mapped['target_sku_id'],
            'sku_unique' => (string)$mapped['target_sku_unique']]
        : rightsHiddenProduct($target, $name, 6, $cache);
    $find = $target->prepare('SELECT id FROM eb_store_card_related WHERE card_product_id=? AND product_id=? AND product_attr_unique=? LIMIT 2');
    $find->execute([$card['product_id'], $project['product_id'], $project['sku_unique']]);
    $relations = $find->fetchAll(PDO::FETCH_ASSOC);
    if (count($relations) > 1) throw new RuntimeException('Ambiguous historical relation');
    $relationId = $relations ? (int)$relations[0]['id'] : rightsInsert($target,
        'eb_store_card_related', ['card_product_id' => $card['product_id'],
            'product_id' => $project['product_id'], 'product_type' => 6,
            'product_attr_unique' => $project['sku_unique'], 'cost' => 0,
            'price' => 0, 'write_times' => 0, 'writeoff_amount' => 0,
            'status' => 1, 'add_time' => time()]);
    return [$card, [[
        'relation_id' => $relationId, 'product_id' => $project['product_id'],
        'product_attr_unique' => $project['sku_unique'], 'store_name' => $name,
        'sku_id' => $project['sku_id'],
    ]], 'normal'];
}

/** Canonical JSON is the immutable V3 rule fingerprint contract. */
function rightsCanonical($value)
{
    if (!is_array($value)) return $value;
    if (array_keys($value) === ($value ? range(0, count($value) - 1) : [])) {
        return array_map('rightsCanonical', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = rightsCanonical($item);
    return $value;
}

function rightsJson(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function rightsFingerprint(array $value): string
{
    return hash('sha256', rightsJson(rightsCanonical($value)));
}

/**
 * V3 rule state owns a shared choice-count pool; physical project lines are
 * candidates, not independent grants. Normal cards use the same state shape
 * so catalogue rules, checkout and later member-right repairs stay aligned.
 */
function rightsRuleState(PDO $target, array $source, array $card,
    array $projects, array $cartIds, int $holderId, int $targetUid,
    string $orderNo, string $rule, int $time): void
{
    $sourceId = (int)$source['source_detail_id'];
    $total = (int)$source['source_total_times'];
    $remaining = (int)$source['source_remaining_times'];
    $end = (int)$source['target_write_end'];
    $start = (int)$source['source_write_start'];
    $receipt = 'M8R-' . $sourceId;
    $stateId = 'CRS-' . strtoupper(substr(hash('sha256', $receipt . '|' . $holderId), 0, 40));
    $snapshot = ['sourceSystem' => 'morestore', 'sourceDetailId' => $sourceId,
        'ruleType' => $rule, 'cardProductId' => $card['product_id'],
        'totalTimes' => $total, 'validFrom' => $start, 'validThrough' => $end,
        'projectIds' => array_map(static function (array $row): int { return (int)$row['product_id']; }, $projects)];
    $stateDbId = rightsInsert($target, 'eb_cashier_v3_card_rule_state', [
        'state_id' => $stateId, 'tenant_id' => '0', 'receipt_id' => $receipt,
        'sales_order_id' => $orderNo, 'sales_order_line_id' => 'M8R-LINE-' . $sourceId,
        'card_holder_id' => $holderId, 'member_id' => $targetUid, 'issue_store_id' => 0,
        'catalog_product_id' => $card['product_id'], 'catalog_sku_id' => $card['sku_id'],
        'rule_type' => $rule, 'rule_version' => 1, 'definition_version' => 1,
        'choice_limit' => 0, 'selected_kind_count' => 0,
        'shared_total_times' => $rule === 'choice_count' ? $total : 0,
        'shared_remaining_times' => $rule === 'choice_count' ? $remaining : 0,
        'validity_mode' => $end > 0 ? 3 : 1, 'valid_from' => $start,
        'valid_through' => $end, 'immutable_fingerprint' => rightsFingerprint($snapshot),
        'definition_snapshot_json' => rightsJson($snapshot), 'state_version' => 1,
        'status' => 'active', 'occurred_at' => $time, 'recorded_at' => $time,
        'add_time' => $time, 'update_time' => $time,
    ]);
    foreach ($projects as $index => $project) {
        $component = ['sourceDetailId' => $sourceId, 'relationId' => (int)$project['relation_id'],
            'productId' => (int)$project['product_id'], 'skuId' => (int)$project['sku_id'],
            'skuUnique' => (string)$project['product_attr_unique'],
            'nameSnapshot' => (string)$project['store_name'],
            'configuredPriceCents' => 0, 'configuredAmountCents' => 0,
            'writeTimes' => $rule === 'normal' ? $total : 0];
        rightsInsert($target, 'eb_cashier_v3_card_rule_component', [
            'component_state_id' => 'CRC-' . strtoupper(substr(hash('sha256', $stateId . '|' . $index), 0, 40)),
            'tenant_id' => '0', 'rule_state_id' => $stateDbId,
            'card_holder_id' => $holderId, 'legacy_detail_id' => $cartIds[$index],
            'relation_id' => (int)$project['relation_id'],
            'project_product_id' => (int)$project['product_id'],
            'project_sku_id' => (int)$project['sku_id'],
            'project_sku_unique' => (string)$project['product_attr_unique'],
            'project_type' => 6, 'project_name_snapshot' => (string)$project['store_name'],
            'total_times' => $rule === 'normal' ? $total : 0,
            'remaining_times' => $rule === 'normal' ? $remaining : 0,
            'writeoff_amount_cents' => 0, 'selection_status' => 'not_applicable',
            'selected_at' => 0, 'selected_store_id' => 0,
            'immutable_fingerprint' => rightsFingerprint($component),
            'component_snapshot_json' => rightsJson($component),
            'state_version' => 1, 'status' => 'active',
            'occurred_at' => $time, 'recorded_at' => $time,
            'add_time' => $time, 'update_time' => $time,
        ]);
    }
}

/** A rerun never rewrites a holder that might have been consumed meanwhile. */
function rightsExisting(PDO $target, int $sourceDetailId): ?array
{
    $query = $target->prepare('SELECT source_detail_id,card_holder_id,target_uid,source_snapshot_json
        FROM ' . RIGHTS_SCOPE_TABLE . ' WHERE tenant_id=? AND source_system=? AND source_detail_id=?');
    $query->execute(['0', 'morestore', $sourceDetailId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Insert one source line as a hidden, zero-amount entitlement carrier. */
function rightsApplyOne(PDO $target, array $source, int $targetUid,
    int $ownerOrgId, array $mapped, array &$hidden): bool
{
    $sourceId = (int)$source['source_detail_id'];
    $existing = rightsExisting($target, $sourceId);
    if ($existing) {
        $old = json_decode((string)$existing['source_snapshot_json'], true);
        if ((int)$existing['target_uid'] !== $targetUid || !is_array($old)
            || (int)($old['source_detail_id'] ?? 0) !== $sourceId) {
            throw new RuntimeException('Existing migrated right differs: ' . $sourceId);
        }
        // A later source export may contain changed remaining times. Never
        // overwrite a target holder that may already have 008 write-offs;
        // isolate the delta for a separate reconciliation decision.
        foreach ($source as $key => $value) {
            if (!array_key_exists($key, $old) || $old[$key] !== $value) {
                throw new RuntimeException('Source right changed after import: ' . $sourceId);
            }
        }
        return false;
    }
    [$card, $projects, $rule] = rightsDefinition($target, $source, $mapped, $hidden);
    $orderNo = 'M8R-' . $sourceId;
    $findOrder = $target->prepare('SELECT id FROM eb_store_order WHERE order_id=? LIMIT 1');
    $findOrder->execute([$orderNo]);
    if ($findOrder->fetchColumn()) throw new RuntimeException('Unscoped order collision: ' . $orderNo);
    $at = max(1, (int)$source['source_pay_time'] ?: (int)$source['source_order_time']);
    $name = mb_substr((string)$source['purchase_name'], 0, 255);
    $total = (int)$source['source_total_times'];
    $remaining = (int)$source['source_remaining_times'];
    $end = (int)$source['target_write_end'];
    $start = (int)$source['source_write_start'];
    $orderId = rightsInsert($target, 'eb_store_order', [
        'type' => 11, 'order_id' => $orderNo, 'store_id' => 0, 'uid' => $targetUid,
        'total_num' => 1, 'total_price' => 0, 'pay_price' => 0,
        'paid' => 1, 'pay_type' => 'migration', 'channel_type' => 'migration',
        'status' => 0, 'is_del' => 0, 'is_user_del' => 0, 'is_system_del' => 1,
        'product_type' => 5, 'shipping_type' => 4, 'mark' => RIGHTS_ORDER_MARK,
        'remark' => 'source_detail_id=' . $sourceId,
        'unique' => md5($orderNo), 'add_time' => $at, 'pay_time' => $at,
    ]);
    rightsInsert($target, 'eb_store_order_cart_info', [
        'uid' => $targetUid, 'oid' => $orderId, 'cart_id' => 'M8R-BASE-' . $sourceId,
        'cart_type' => 0, 'relation_id' => 0,
        'product_id' => $card['product_id'], 'product_type' => 5,
        'sku_unique' => $card['sku_unique'], 'is_card' => 1,
        'is_support_refund' => 0, 'cart_num' => 1,
        'write_times' => 1, 'write_surplus_times' => 1, 'surplus_num' => 1,
        'cart_info' => rightsJson(['sourceType' => RIGHTS_ORDER_MARK,
            'productInfo' => ['id' => $card['product_id'], 'store_name' => $name,
                'product_type' => 5]]),
        'unique' => md5('M8R-BASE-' . $sourceId), 'add_time' => $at,
        'source_type' => 'migration',
    ]);
    $cartIds = [];
    foreach ($projects as $index => $project) {
        $cartKey = 'M8R-ITEM-' . $sourceId . '-' . $index;
        $cartIds[] = rightsInsert($target, 'eb_store_order_cart_info', [
            'uid' => $targetUid, 'oid' => $orderId, 'cart_id' => $cartKey,
            'cart_type' => 2, 'relation_id' => 0,
            'product_id' => (int)$project['product_id'], 'product_type' => 6,
            'sku_unique' => (string)$project['product_attr_unique'],
            'is_card' => 1, 'is_support_refund' => 0, 'cart_num' => 1,
            'write_times' => $total, 'write_surplus_times' => $remaining,
            'surplus_num' => $remaining, 'is_writeoff' => 0,
            'write_start' => $start, 'write_end' => $end,
            'cart_info' => rightsJson(['sourceType' => RIGHTS_ORDER_MARK,
                'sourceDetailId' => $sourceId,
                'productInfo' => ['id' => (int)$project['product_id'],
                    'product_id' => (int)$project['product_id'],
                    'store_name' => (string)$project['store_name'],
                    'product_type' => 6,
                    'attrInfo' => ['unique' => (string)$project['product_attr_unique']]]]),
            'unique' => md5($cartKey), 'add_time' => $at,
            'source_type' => 'migration',
        ]);
    }
    $cardNo = 'm' . str_pad(base_convert((string)$sourceId, 10, 36), 6, '0', STR_PAD_LEFT);
    if (strlen($cardNo) !== 7) throw new RuntimeException('Card number overflow');
    $holderId = rightsInsert($target, 'eb_user_card_holder', [
        'uid' => $targetUid, 'oid' => $orderId, 'card_name' => $name,
        'card_no' => $cardNo, 'store_id' => 0,
        'product_id' => $card['product_id'], 'product_type' => 5,
        'verify_code' => substr(hash('sha256', 'M8R-VERIFY-' . $sourceId), 0, 12),
        'write_valid' => $end > 0 ? 3 : 1, 'write_days' => 0,
        'write_start' => $start, 'write_end' => $end,
        'write_times' => $total, 'write_surplus_times' => $remaining,
        'is_del' => 0, 'add_time' => $at,
    ]);
    rightsRuleState($target, $source, $card, $projects, $cartIds,
        $holderId, $targetUid, $orderNo, $rule, $at);
    $audit = $source;
    $audit['migration_reason'] = 'old_h5_available_list';
    $audit['expiry_override_reason'] = $end === 0 && (int)$source['source_write_end'] > 0
        ? 'old_h5_available_even_after_expiry_pm_approved' : '';
    $audit['technical_carrier_amount_yuan'] = '0.00';
    $audit['source_paid_amount_yuan'] = (string)$source['source_pay_price'];
    rightsInsert($target, RIGHTS_SCOPE_TABLE, [
        'tenant_id' => '0', 'source_system' => 'morestore',
        'source_detail_id' => $sourceId,
        'source_order_id' => (int)$source['source_order_id'],
        'source_uid' => (int)$source['source_uid'],
        'source_store_id' => (int)$source['source_store_id'],
        'target_uid' => $targetUid, 'card_holder_id' => $holderId,
        'owner_org_id' => $ownerOrgId, 'status' => 'active',
        'source_snapshot_json' => rightsJson($audit), 'created_at' => time(),
    ]);
    return true;
}

/**
 * Bidirectional source-to-target reconciliation runs before COMMIT. A holder
 * or V3 rule mismatch must roll the whole batch back; the visible sample is
 * compared by source detail ID, never merely by its aggregate count.
 */
function rightsVerify(PDO $target, array $sourceRows, array $memberMap, int $ownerOrgId): array
{
    $scopes = $target->query("SELECT s.source_detail_id,s.source_uid,s.target_uid,s.owner_org_id,
        s.card_holder_id,s.source_snapshot_json,h.oid,h.card_name,h.write_times,
        h.write_surplus_times,h.store_id,o.order_id,o.uid order_uid,o.store_id order_store_id,
        o.pay_price,o.is_system_del,o.mark,r.id rule_state_id,r.rule_type,
        r.shared_total_times,r.shared_remaining_times
        FROM " . RIGHTS_SCOPE_TABLE . " s
        LEFT JOIN eb_user_card_holder h ON h.id=s.card_holder_id
        LEFT JOIN eb_store_order o ON o.id=h.oid
        LEFT JOIN eb_cashier_v3_card_rule_state r ON r.card_holder_id=h.id AND r.tenant_id='0'
        WHERE s.tenant_id='0' AND s.source_system='morestore'
        ORDER BY s.source_detail_id")->fetchAll(PDO::FETCH_ASSOC);
    if (count($scopes) !== count($sourceRows)) {
        throw new RuntimeException('Source/target right count differs');
    }
    $seen = [];
    $sum = 0;
    $sample = [];
    $cartQuery = $target->prepare('SELECT id,product_id,write_times,write_surplus_times,
        cart_info FROM eb_store_order_cart_info WHERE oid=? AND cart_type=2 AND product_type=6 ORDER BY id');
    $componentQuery = $target->prepare('SELECT legacy_detail_id,remaining_times,total_times
        FROM eb_cashier_v3_card_rule_component WHERE rule_state_id=? ORDER BY id');
    foreach ($scopes as $scope) {
        $id = (int)$scope['source_detail_id'];
        $source = $sourceRows[$id] ?? null;
        if (!$source || isset($seen[$id])) throw new RuntimeException('Target has unexpected source detail ' . $id);
        $seen[$id] = true;
        $audit = json_decode((string)$scope['source_snapshot_json'], true);
        if (!is_array($audit)) throw new RuntimeException('Audit snapshot unavailable: ' . $id);
        foreach ($source as $key => $value) {
            if (!array_key_exists($key, $audit) || $audit[$key] !== $value) {
                throw new RuntimeException('Source audit differs: ' . $id . ':' . $key);
            }
        }
        $uid = $memberMap[(int)$source['source_uid']] ?? 0;
        $total = (int)$source['source_total_times'];
        $remaining = (int)$source['source_remaining_times'];
        if ($uid <= 0 || (int)$scope['target_uid'] !== $uid
            || (int)$scope['owner_org_id'] !== $ownerOrgId
            || (int)$scope['order_uid'] !== $uid
            || (int)$scope['store_id'] !== 0 || (int)$scope['order_store_id'] !== 0
            || (int)$scope['write_times'] !== $total
            || (int)$scope['write_surplus_times'] !== $remaining
            || (string)$scope['card_name'] !== mb_substr((string)$source['purchase_name'], 0, 255)
            || (string)$scope['order_id'] !== 'M8R-' . $id
            || (string)$scope['pay_price'] !== '0.00'
            || (int)$scope['is_system_del'] !== 1
            || (string)$scope['mark'] !== RIGHTS_ORDER_MARK
            || (int)$scope['rule_state_id'] <= 0) {
            throw new RuntimeException('Holder/order authority differs: ' . $id);
        }
        $cartQuery->execute([(int)$scope['oid']]);
        $carts = $cartQuery->fetchAll(PDO::FETCH_ASSOC);
        $componentQuery->execute([(int)$scope['rule_state_id']]);
        $components = $componentQuery->fetchAll(PDO::FETCH_ASSOC);
        if (!$carts || count($carts) !== count($components)) {
            throw new RuntimeException('Project component coverage differs: ' . $id);
        }
        $cartIds = array_fill_keys(array_map('intval', array_column($carts, 'id')), true);
        foreach ($carts as $cart) {
            $snapshot = json_decode((string)$cart['cart_info'], true);
            if ((int)$cart['product_id'] <= 0
                || (int)$cart['write_times'] !== $total
                || (int)$cart['write_surplus_times'] !== $remaining
                || !is_array($snapshot)
                || (int)($snapshot['sourceDetailId'] ?? 0) !== $id) {
                throw new RuntimeException('Project remainder differs: ' . $id);
            }
        }
        foreach ($components as $component) {
            if (!isset($cartIds[(int)$component['legacy_detail_id']])) {
                throw new RuntimeException('V3 component points outside source cart: ' . $id);
            }
            if ((string)$scope['rule_type'] === 'normal'
                && ((int)$component['total_times'] !== $total
                    || (int)$component['remaining_times'] !== $remaining)) {
                throw new RuntimeException('V3 normal component count differs: ' . $id);
            }
        }
        if ((string)$scope['rule_type'] === 'choice_count'
            && ((int)$scope['shared_total_times'] !== $total
                || (int)$scope['shared_remaining_times'] !== $remaining)) {
            throw new RuntimeException('V3 choice pool differs: ' . $id);
        }
        $sum += $remaining;
        if ((int)$source['source_uid'] === 421) {
            $sample[$id] = ['name' => (string)$scope['card_name'], 'remaining' => $remaining];
        }
    }
    if (count($seen) !== count($sourceRows) || count($sample) !== 15) {
        throw new RuntimeException('Source detail set or UID421 15-row sample differs');
    }
    return ['source_lines' => count($seen), 'remaining_times' => $sum,
        'sample_uid_421_lines' => count($sample), 'sample_uid_421' => $sample];
}

/** Preflight is read-only; apply holds one target transaction and an advisory lock. */
function rightsMain(array $argv): void
{
    $snapshot = rightsPrepareOption($argv, 'snapshot-file', '');
    $expected = rightsPrepareOption($argv, 'expected-source-sha', '');
    $targetEnv = rightsPrepareOption($argv, 'target-env', RIGHTS_TARGET_ENV);
    $mappingFile = rightsPrepareOption($argv, 'mapping-file', RIGHTS_MAPPING_FILE);
    $planFile = rightsPrepareOption($argv, 'catalog-plan', RIGHTS_CATALOG_PLAN_FILE);
    if ($snapshot === '' || $expected === '') throw new RuntimeException('Frozen snapshot and reviewed SHA are required');
    $rows = rightsSnapshot($snapshot, $expected);
    [$products] = rightsPrepareMapping($mappingFile, $planFile);
    $target = rightsTarget($targetEnv);
    $owner = rightsOwnerOrg($target);
    $members = rightsMemberMap($target, array_values(array_unique(array_map('intval', array_column($rows, 'source_uid')))));
    $products = rightsCatalog($target, $products);
    $mode = in_array('--execute', $argv, true) ? 'apply' : 'plan';
    if ($mode === 'plan') {
        $existing = 0;
        foreach (array_keys($rows) as $id) if (rightsExisting($target, $id)) $existing++;
        echo rightsJson(['mode' => 'plan', 'source_sha256' => $expected,
            'script_sha256' => hash_file('sha256', __FILE__),
            'mapping_sha256' => hash_file('sha256', $mappingFile),
            'source_lines' => count($rows), 'already_imported' => $existing,
            'pending' => count($rows) - $existing, 'owner_org_id' => $owner]) . PHP_EOL;
        return;
    }
    if (!in_array('--allow-008', $argv, true)) throw new RuntimeException('Explicit 008-only write guard missing');
    $scriptSha = rightsPrepareOption($argv, 'expected-script-sha', '');
    $mappingSha = rightsPrepareOption($argv, 'expected-mapping-sha', '');
    if (!preg_match('/^[a-f0-9]{64}$/D', $scriptSha)
        || !hash_equals($scriptSha, (string)hash_file('sha256', __FILE__))
        || !preg_match('/^[a-f0-9]{64}$/D', $mappingSha)
        || !hash_equals($mappingSha, (string)hash_file('sha256', $mappingFile))) {
        throw new RuntimeException('Reviewed script or catalogue mapping SHA-256 differs');
    }
    if ((int)$target->query("SELECT GET_LOCK('morestore_008_rights_import',15)")->fetchColumn() !== 1) {
        throw new RuntimeException('Target rights import lock unavailable');
    }
    try {
        $target->beginTransaction();
        $hidden = [];
        $written = 0;
        try {
            foreach ($rows as $source) {
                $oldId = (int)$source['source_product_id'];
                $targetUid = $members[(int)$source['source_uid']];
                if (rightsApplyOne($target, $source, $targetUid, $owner,
                    $products[$oldId] ?? [], $hidden)) $written++;
            }
            $verification = rightsVerify($target, $rows, $members, $owner);
            $target->commit();
        } catch (Throwable $error) {
            if ($target->inTransaction()) $target->rollBack();
            throw $error;
        }
        echo rightsJson(['mode' => 'applied', 'source_sha256' => $expected,
            'source_lines' => count($rows), 'new_rights' => $written,
            'existing_rights' => count($rows) - $written,
            'verification' => $verification]) . PHP_EOL;
    } finally {
        $target->query("SELECT RELEASE_LOCK('morestore_008_rights_import')");
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        rightsMain($argv);
    } catch (Throwable $error) {
        fwrite(STDERR, '008 historical rights import rejected: ' . $error->getMessage() . PHP_EOL);
        exit(2);
    }
}
