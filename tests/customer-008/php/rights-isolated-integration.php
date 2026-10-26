<?php
declare(strict_types=1);

/**
 * Disposable MySQL 5.7 integration for one-source-line/one-holder rights.
 *
 * Run only against a local isolated database with the production-compatible
 * table DDL and the migration-scope DDL installed. No remote credentials are
 * read; this test resets only the explicitly named disposable schema.
 */

require_once __DIR__ . '/../../../scripts/import/customer_008_morestore_rights_apply.php';

function rightsTestAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function rightsTestSource(int $detailId, int $uid, int $oldProduct,
    string $name, int $total, int $remain, int $oldEnd = 0): array
{
    return [
        'source_order_id' => $detailId + 100000,
        'source_order_no' => 'OLD-' . $detailId,
        'source_uid' => $uid, 'source_store_id' => 999,
        'source_order_time' => 1700000000, 'source_pay_time' => 1700000100,
        'source_total_price' => '129.00', 'source_pay_price' => '119.37',
        'source_status' => 0, 'source_refund_status' => 0,
        'source_detail_id' => $detailId, 'source_cart_id' => $detailId + 50000,
        'source_product_id' => $oldProduct, 'source_sku_unique' => 'oldsku',
        'source_total_times' => $total, 'source_remaining_times' => $remain,
        'source_write_start' => 0, 'source_write_end' => $oldEnd,
        'source_cart_info' => '{}', 'current_product_name' => $name,
        'purchase_name' => $name,
        'target_write_end' => $oldEnd > 0 && $oldEnd < time() ? 0 : $oldEnd,
    ];
}

/** The test tests real inserts, V3 rule rows, source audit and replay guards. */
function rightsTestMain(): void
{
    $port = (int)(getenv('M008_RIGHTS_TEST_PORT') ?: 13318);
    if ($port !== 13318) throw new RuntimeException('Disposable test port guard changed');
    $target = new PDO('mysql:host=127.0.0.1;port=13318;dbname=mohe_008;charset=utf8mb4',
        'root', 'rights-test-local', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $target->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['eb_cashier_v3_card_rule_component', 'eb_cashier_v3_card_rule_state',
        RIGHTS_SCOPE_TABLE, 'eb_user_card_holder', 'eb_store_order_cart_info',
        'eb_store_order', 'eb_store_card_related', 'eb_store_product_attr_value',
        'eb_store_product', 'eb_user', 'eb_organization_store', 'eb_system_store',
        'eb_organization'] as $table) {
        $target->exec('TRUNCATE TABLE ' . $table);
    }
    $target->exec('SET FOREIGN_KEY_CHECKS=1');
    rightsInsert($target, 'eb_organization', ['id' => 1, 'pid' => 0,
        'name' => '集团', 'status' => 1, 'is_del' => 0]);
    rightsInsert($target, 'eb_organization', ['id' => 3, 'pid' => 1,
        'name' => '渼约', 'status' => 1, 'is_del' => 0]);
    rightsInsert($target, 'eb_organization', ['id' => 2, 'pid' => 1,
        'name' => '歌妮', 'status' => 1, 'is_del' => 0]);
    rightsInsert($target, 'eb_system_store', ['id' => 11, 'name' => '渼约隔离店',
        'is_show' => 1, 'is_del' => 0, 'business_status' => 1]);
    rightsInsert($target, 'eb_system_store', ['id' => 22, 'name' => '歌妮隔离店',
        'is_show' => 1, 'is_del' => 0, 'business_status' => 1]);
    rightsInsert($target, 'eb_organization_store', ['org_id' => 3, 'store_id' => 11]);
    rightsInsert($target, 'eb_organization_store', ['org_id' => 2, 'store_id' => 22]);
    rightsInsert($target, 'eb_user', ['uid' => 500, 'phone' => '13900000500',
        'nickname' => '隔离测试', 'status' => 1, 'is_del' => 0]);
    $normalCard = rightsInsert($target, 'eb_store_product', [
        'product_type' => 5, 'type' => 0, 'store_name' => '普通卡',
        'card_rule_type' => 'normal', 'is_show' => 1, 'is_del' => 0]);
    $normalCardSku = rightsInsert($target, 'eb_store_product_attr_value', [
        'product_id' => $normalCard, 'product_type' => 5,
        'unique' => 'norm0001']);
    $normalProject = rightsInsert($target, 'eb_store_product', [
        'product_type' => 6, 'type' => 0, 'store_name' => '项目甲', 'is_del' => 0]);
    rightsInsert($target, 'eb_store_product_attr_value', [
        'product_id' => $normalProject, 'product_type' => 6, 'unique' => 'proj0001']);
    rightsInsert($target, 'eb_store_card_related', [
        'card_product_id' => $normalCard, 'product_id' => $normalProject,
        'product_type' => 6, 'product_attr_unique' => 'proj0001',
        'write_times' => 10, 'status' => 1]);
    $choiceCard = rightsInsert($target, 'eb_store_product', [
        'product_type' => 5, 'type' => 0, 'store_name' => '任选次数卡',
        'card_rule_type' => 'choice_count', 'is_show' => 1, 'is_del' => 0]);
    $choiceSku = rightsInsert($target, 'eb_store_product_attr_value', [
        'product_id' => $choiceCard, 'product_type' => 5, 'unique' => 'choi0001']);
    foreach ([2, 3] as $n) {
        $project = rightsInsert($target, 'eb_store_product', [
            'product_type' => 6, 'type' => 0, 'store_name' => '项目' . $n,
            'is_del' => 0]);
        $sku = 'proj000' . $n;
        rightsInsert($target, 'eb_store_product_attr_value', [
            'product_id' => $project, 'product_type' => 6, 'unique' => $sku]);
        rightsInsert($target, 'eb_store_card_related', [
            'card_product_id' => $choiceCard, 'product_id' => $project,
            'product_type' => 6, 'product_attr_unique' => $sku,
            'write_times' => 0, 'status' => 1]);
    }
    $shell = rightsInsert($target, 'eb_store_product', [
        'product_type' => 5, 'type' => 0, 'store_name' => '旧卡壳',
        'is_show' => 0, 'is_del' => 0]);
    $shellSku = rightsInsert($target, 'eb_store_product_attr_value', [
        'product_id' => $shell, 'product_type' => 5, 'unique' => 'shel0001']);
    $projectOnly = rightsInsert($target, 'eb_store_product', [
        'product_type' => 6, 'type' => 0, 'store_name' => '单次项目',
        'is_show' => 1, 'is_del' => 0]);
    $projectSku = rightsInsert($target, 'eb_store_product_attr_value', [
        'product_id' => $projectOnly, 'product_type' => 6, 'unique' => 'only0001']);
    $mapped = [
        101 => ['kind' => 'card', 'target_product_id' => $normalCard,
            'target_sku_id' => $normalCardSku, 'target_sku_unique' => 'norm0001',
            'card_rule' => 'normal'],
        102 => ['kind' => 'card', 'target_product_id' => $choiceCard,
            'target_sku_id' => $choiceSku, 'target_sku_unique' => 'choi0001',
            'card_rule' => 'choice_count'],
        103 => ['kind' => 'shell', 'target_product_id' => $shell,
            'target_sku_id' => $shellSku, 'target_sku_unique' => 'shel0001'],
        104 => ['kind' => 'project', 'target_product_id' => $projectOnly,
            'target_sku_id' => $projectSku, 'target_sku_unique' => 'only0001'],
    ];
    $sources = [];
    for ($i = 1; $i <= 15; $i++) {
        $oldProduct = [101, 102, 103, 104, 999][$i % 5];
        $end = $i <= 6 ? 1600000000 : 0;
        $sources[9000 + $i] = rightsTestSource(9000 + $i, 421,
            $oldProduct, '旧购买名-' . $i, 10, $i, $end);
    }
    $sources[9100] = rightsTestSource(9100, 422, 102,
        '合并会员的卡', 20, 17);
    $memberMap = [421 => 500, 422 => 500];
    $hidden = [];
    $target->beginTransaction();
    foreach ($sources as $source) {
        rightsTestAssert(rightsApplyOne($target, $source, 500, 3,
            $mapped[(int)$source['source_product_id']] ?? [], $hidden),
            'Source line was not inserted');
    }
    $verification = rightsVerify($target, $sources, $memberMap, 3);
    $target->commit();
    rightsTestAssert($verification['source_lines'] === 16, 'Source line count');
    rightsTestAssert($verification['sample_uid_421_lines'] === 15, 'UID421 count');
    rightsTestAssert((int)$target->query("SELECT COUNT(*) FROM eb_store_order WHERE is_system_del=0")->fetchColumn() === 0,
        'Technical orders leak into ordinary order set');
    rightsTestAssert((int)$target->query("SELECT COUNT(*) FROM eb_store_order WHERE pay_price<>0")->fetchColumn() === 0,
        'Technical orders create revenue');
    $paidSnapshot = $target->query('SELECT source_snapshot_json FROM '
        . RIGHTS_SCOPE_TABLE . ' WHERE source_detail_id=9001')->fetchColumn();
    rightsTestAssert((string)(json_decode((string)$paidSnapshot, true)['source_paid_amount_yuan'] ?? '') === '119.37',
        'Original cents were not preserved for member-only display');
    $target->beginTransaction();
    foreach ($sources as $source) {
        rightsTestAssert(!rightsApplyOne($target, $source, 500, 3,
            $mapped[(int)$source['source_product_id']] ?? [], $hidden),
            'Replay duplicated a source right');
    }
    $target->rollBack();
    $changed = $sources[9001];
    $changed['source_remaining_times'] = 2;
    $driftRejected = false;
    try {
        rightsApplyOne($target, $changed, 500, 3,
            $mapped[(int)$changed['source_product_id']] ?? [], $hidden);
    } catch (RuntimeException $e) {
        $driftRejected = strpos($e->getMessage(), 'Source right changed') !== false;
    }
    rightsTestAssert($driftRejected, 'Changed source was not rejected');
    echo rightsJson(['ok' => true, 'verification' => $verification,
        'replay_duplicate_count' => 0, 'changed_source_rejected' => true]) . PHP_EOL;
}

rightsTestMain();
