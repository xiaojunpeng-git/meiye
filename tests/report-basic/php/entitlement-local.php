<?php
declare(strict_types=1);

/**
 * Real cashier authority + MySQL 5.7 temporary fixtures; no persistent writes.
 * Product-confirmed delivery (2026-10-23): realtime detail supports today/no
 * date only. Historical reconstruction is outside the confirmed scope, not a
 * pending choice; catalog/UI time-mode updates belong to the main task.
 * Previous full local run passed 394 checks; this is technical self-test
 * evidence, not product acceptance of a fixed version.
 */
require getcwd() . '/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
use app\services\report\BasicEntitlementReportServices as Reports;
use app\services\cashier\v3\CashierV3DataScopeContext as Scope;
use app\services\cashier\v3\CashierV3OperatorScope as Operator;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices as Authority;
use app\services\query\metric\MemberCurrentAssetMetricReadServices as Metrics;
use app\services\report\StoreReportCurrentProductCategoryServices as Categories;

set_exception_handler(static function (Throwable $e): void { file_put_contents('php://stderr', (string)$e . PHP_EOL); exit(1); });
$config = config('database.connections.mysql');
if (!in_array($config['hostname'], ['mysql', 'mohe-mysql'], true)
    || (Db::query('SELECT DATABASE() db')[0]['db'] ?? '') !== 'ruihao') throw new RuntimeException('LOCAL_ONLY');
$n = 0;
function check(bool $ok, string $label): void { global $n; ++$n; if (!$ok) throw new RuntimeException($label); }
function rejects(callable $fn, string $message): void {
    try { $fn(); } catch (Throwable $e) { check(strpos($e->getMessage(), $message) !== false, $e->getMessage()); return; }
    throw new RuntimeException('Missing rejection: ' . $message);
}
function scope(array $stores): Scope { return new Scope(1, 0, $stores[0], '0', '', $stores, 'stores', [], false, '', 'r51-local', [], [], true); }
function total(array $rows, string $key): int {
    $cents = 0;
    foreach ($rows as $row) if ($row[$key] !== null) $cents += (int)round($row[$key] * 100);
    return $cents;
}
$reader = new Authority();
// Select an actual positive-valued card to exercise native refund/allocation,
// expiry and debt rules instead of stubbing the report's authority dependency.
$candidates = Db::name('user_card_holder')->alias('h')->join('user u', 'u.uid=h.uid')
    ->join('store_order o', 'o.id=h.oid')->where('u.status', 1)->where('u.is_del', 0)
    ->where('h.is_del', 0)->where('h.store_id', '>', 0)->where('h.write_surplus_times', '>', 0)
    ->where('o.paid', 1)->where('o.refund_status', 0)->where('o.terminal_action', 0)
    ->where('o.is_del', 0)->where('o.is_system_del', 0)->where('o.is_user_del', 0)->where('o.card_upgrade_use_oid', 0)
    ->field('h.uid,h.store_id')->order('h.id', 'asc')->limit(100)->select()->toArray();
$chosen = null;
foreach ($candidates as $candidate) {
    $store = (int)$candidate['store_id']; $id = (int)$candidate['uid'];
    $original = $reader->readAiRightsSet([$id], new Operator($store, 1, '', '0'), scope([$store]))[$id];
    foreach ($original['cards'] as $card) if ($card['statusCode'] === 'enabled' && (float)$card['remainingAmount'] > 0
        && count($card['projects']) >= 2 && !in_array($card['cardRuleType'], ['choice_count', 'time'], true)) {
        $holder = Db::name('user_card_holder')->where('id', (int)$card['id'])->find();
        if ((int)$holder['store_id'] > 0) { $chosen = $holder; break 2; }
    }
}
if (!$chosen) throw new RuntimeException('LOCAL_POSITIVE_CARD_REQUIRED');
$chosenProjects = $card['projects'];
$productIds = array_map('intval', Db::name('store_order_cart_info')->whereIn('id', array_column($chosenProjects, 'id'))->column('product_id'));
$productIds[] = (int)$chosen['product_id'];
$productRows = Db::name('store_product')->whereIn('id', $productIds)->select()->toArray();
$parentIds = array_filter(array_map('intval', array_column($productRows, 'pid')));
if ($parentIds) $productRows = array_merge($productRows, Db::name('store_product')->whereIn('id', $parentIds)->select()->toArray());
$productRows = array_values(array_column($productRows, null, 'id'));
$categoryRows = Db::name('store_product_category')->select()->toArray();
$template = Db::name('user')->where('uid', (int)$chosen['uid'])->find();
$orderTemplate = Db::name('store_order')->where('id', (int)$chosen['oid'])->find();
$relation = Db::name('store_user')->where('status', 1)->find();
$store1 = (int)$chosen['store_id'];
$store2 = (int)Db::name('system_store')->where('id', '<>', $store1)->where('id', '>', 0)->order('id')->value('id');
if (!$template || !$relation || !$store2) throw new RuntimeException('LOCAL_FIXTURE_SOURCE_REQUIRED');
$tables = ['user', 'store_user', 'user_card_holder', 'store_order', 'store_product', 'store_product_category']; $created = [];
try {
    foreach ($tables as $table) {
        // MySQL 5.7 cannot CREATE TEMPORARY x LIKE x; reuse the server's DDL
        // under the same session-only name, leaving original tables untouched.
        $ddl = Db::query('SHOW CREATE TABLE eb_' . $table)[0]['Create Table'];
        Db::execute(str_replace('CREATE TABLE `eb_' . $table . '`', 'CREATE TEMPORARY TABLE `eb_' . $table . '`', $ddl));
        $created[] = $table;
    }
    // 105 members cross the Reader's 100-member batch; member 106 is forbidden,
    // 107 is deleted and 108 has no active relation. Duplicate relations cannot fan out.
    for ($i = 1; $i <= 108; ++$i) {
        $user = $template; $user['uid'] = 1999000000 + $i;
        $user['phone'] = '199' . str_pad((string)$i, 8, '0', STR_PAD_LEFT);
        $user['now_money'] = '13.05'; $user['ben_money'] = '10.01'; $user['give_money'] = '3.04';
        $user['balance_version'] = 1; $user['status'] = 1; $user['is_del'] = $i === 107 ? 1 : 0;
        $user['belong_store_id'] = $i === 106 ? $store2 : $store1;
        Db::name('user')->insert($user);
        $link = $relation; unset($link['id']); $link['uid'] = $user['uid'];
        $link['store_id'] = $user['belong_store_id']; $link['status'] = $i === 108 ? 0 : 1;
        Db::name('store_user')->insert($link);
    }
    $link['uid'] = 1999000001; $link['status'] = 1; $link['store_id'] = $store2;
    Db::name('store_user')->insert($link);
    // Simulate transferred ownership with native original-order allocation facts;
    // its source store differs from the member's reporting store.
    $chosen['uid'] = 1999000001; $chosen['store_id'] = $store2;
    Db::name('user_card_holder')->insert($chosen);
    Db::name('store_order')->insert($orderTemplate);
    Db::name('store_product')->insertAll($productRows);
    if ($categoryRows) Db::name('store_product_category')->insertAll($categoryRows);
    Db::execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); Db::startTrans();
    try {
        $service = new Reports(); $input = ['_report_scope' => scope([$store1, $store2])];
        $customers = $service->read('basic_customer_entitlements', [$store1], $input);
        check(count($customers['rows']) === 105, 'Complete population beyond 100; no deleted/inactive/foreign members');
        check(total($customers['rows'], 'balance_amount') === 105 * 1305, 'Historical cents preserved in yuan numbers');
        check(total($customers['rows'], 'rights_amount') === 0, 'Cross-store card excluded by numeric source-store scope');
        check(count(array_unique(array_column($customers['rows'], 'row_id'))) === 105, 'Stable unique member rows');
        $both = $service->read('basic_customer_entitlements', [$store1, $store2], $input);
        check(count($both['rows']) === 106 + count($chosenProjects), 'One balance per member plus one row per actual card project');
        check(total($both['rows'], 'rights_amount') > 0, 'Real native card authority provides positive rights');
        $metrics = (new Metrics())->values('0', [$store1, $store2], [1999000001], ['member_account_balance', 'member_remaining_project_amount']);
        $memberRows = array_values(array_filter($both['rows'], static function (array $row): bool { return $row['member_id'] === 1999000001; }));
        check(total($memberRows, 'balance_amount') === $metrics[1999000001]['member_account_balance'], 'Registered balance Reader parity without project repetition');
        check(total($memberRows, 'rights_amount') === $metrics[1999000001]['member_remaining_project_amount'], 'Independent project rights reconcile with registered Reader');
        $projectRows = array_values(array_filter($memberRows, static function (array $row): bool { return $row['asset_type'] === '卡内项目'; }));
        $authorityProjects = array_column($chosenProjects, null, 'id'); $categoryLinks = [];
        foreach ($projectRows as $row) $categoryLinks[$row['project_detail_id']] = ['product_id' => $row['product_id']];
        $expectedCategories = (new Categories())->resolveVerifiedFactItems($categoryLinks);
        $hasClassification = false;
        foreach ($projectRows as $row) {
            $expected = $authorityProjects[$row['project_detail_id']];
            check($row['row_id'] === 'entitlement:project:' . $chosen['id'] . ':' . $expected['id'], 'Stable holder + project row ID');
            check($row['project_name'] === $expected['projectName'] && $row['remaining_times'] === $expected['remainingTimes'], 'Project label and remaining times use actual authority');
            check((int)round($row['rights_amount'] * 100) === (int)bcmul($expected['remainingAmount'], '100', 0), 'Project money retains authority cents');
            check($row['order_no'] === $orderTemplate['order_id'] && $row['origin_order_id'] === (int)$chosen['oid'], 'Original order number is not a numeric ID surrogate');
            check($row['principal_amount'] === null && $row['gift_amount'] === null && $row['balance_amount'] === null, 'Project rows do not repeat member wallet');
            $expectedCategory = $expectedCategories[$row['project_detail_id']] ?? null;
            check($row['category_id'] === ($expectedCategory['id'] ?? null) && $row['category_name'] === ($expectedCategory['path'] ?? '未分类'), 'Current category is resolved by each project identity');
            $hasClassification = $hasClassification || $expectedCategory !== null;
        }
        check($hasClassification, 'Actual fixture has at least one resolvable project classification');
        check(!in_array('rights_amount', $both['sum_fields'], true) && !in_array('remaining_times', $both['sum_fields'], true), 'Shared pools are never blindly summed');
        $detail = $service->read('basic_unconsumed_detail', [$store1, $store2], $input);
        $summary = $service->read('basic_unconsumed_summary', [$store1, $store2], $input);
        foreach ($both['sum_fields'] as $key) {
            check(total($both['rows'], $key) === total($detail['rows'], $key), 'Detail does not multiply balance: ' . $key);
            check(total($both['rows'], $key) === total($summary['rows'], $key), 'Store summary reconciles: ' . $key);
        }
        check(count($detail['rows']) === 107 && count($summary['rows']) === 2, 'Separate balance/card grains');
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $asOf = $service->read('basic_unconsumed_as_of_detail', [$store1, $store2], $input + ['as_of_date' => $today]);
        check($asOf['rows'] === $detail['rows'] && $asOf['historical_as_of_supported'] === false, 'Product-confirmed today-only delivery reads live assets');
        check($service->read('basic_unconsumed_as_of_detail', [$store1, $store2], $input)['rows'] === $detail['rows'], 'No date means current on fourth entry');
        foreach ([$both, $detail, $summary] as $result) {
            check(array_keys($result['field_sources']) === array_column($result['fields'], 'key'), 'Every column has business source in field order');
            foreach ($result['rows'] as $row) check(in_array($row['store_id'], [$store1, $store2], true), 'Every row has verifiable authorized store');
        }
        check($service->read('basic_customer_entitlements', [$store1, $store2], $input)['rows'] === $both['rows'], 'Repeat reads preserve rows and identities');
        // Mutate only the session's category copy: a fresh read must show the
        // current project category while names, times, amounts and row IDs stay.
        $firstCategory = reset($expectedCategories);
        Db::name('store_product_category')->where('id', $firstCategory['id'])->update(['cate_name' => 'R51项目当前分类']);
        $recategorized = $service->read('basic_customer_entitlements', [$store1, $store2], $input);
        $changedCategory = false;
        foreach ($recategorized['rows'] as $index => $row) {
            check($row['row_id'] === $both['rows'][$index]['row_id'] && $row['rights_amount'] === $both['rows'][$index]['rights_amount'], 'Category rename preserves rights and row identity');
            $changedCategory = $changedCategory || strpos($row['category_name'], 'R51项目当前分类') !== false;
        }
        check($changedCategory, 'Current project category updates without using card-shell category');
        Db::name('store_product')->whereIn('id', $productIds)->update(['pid' => 0, 'cate_id' => '']);
        $unclassified = $service->read('basic_customer_entitlements', [$store1, $store2], $input);
        foreach ($unclassified['rows'] as $row) if ($row['asset_type'] === '卡内项目') check($row['category_name'] === '未分类' && $row['category_id'] === null, 'Unresolved project mapping preserves row as unclassified');
        check(total($unclassified['rows'], 'rights_amount') === total($both['rows'], 'rights_amount'), 'Unclassified rights are not dropped');
        // Restore the temporary catalog so repeatability checks below use the
        // same source snapshot as the original customer result.
        foreach ($productRows as $product) Db::name('store_product')->where('id', $product['id'])->update(['pid' => $product['pid'], 'cate_id' => $product['cate_id']]);
        foreach ($categoryRows as $category) if ((int)$category['id'] === (int)$firstCategory['id']) Db::name('store_product_category')->where('id', $category['id'])->update(['cate_name' => $category['cate_name']]);
        $host = ['tenant_id' => '0', 'operator_id' => 1, 'visible_store_ids' => [$store1, $store2], 'basic_scope_mode' => 'stores', 'permission_version' => 'local'];
        check($service->read('basic_customer_entitlements', [$store1], ['_report_scope' => $host])['rows'] === $customers['rows'], 'Trusted Host array matches object scope');
        $thinHost = ['mode' => 'stores', 'employee_id' => 0, 'operator_id' => 1, 'tenant_id' => '0'];
        check($service->read('basic_customer_entitlements', [$store1], ['_report_scope' => $thinHost])['rows'] === $customers['rows'], 'Host injection with mode/operator/tenant accepted');
        rejects(static function () use ($service, $store1) { $service->read('basic_customer_entitlements', [$store1], []); }, 'ENTITLEMENT_TRUSTED_SCOPE_REQUIRED');
        rejects(static function () use ($service, $store1, $store2) { $service->read('basic_customer_entitlements', [$store2], ['_report_scope' => scope([$store1])]); }, 'ENTITLEMENT_STORE_DENIED');
        $none = new Scope(1, 0, $store1, '0', '', [], 'self_participant', [], false, '', 'local', [], []);
        rejects(static function () use ($service, $store1, $none) { $service->read('basic_customer_entitlements', [$store1], ['_report_scope' => $none]); }, 'ENTITLEMENT_TRUSTED_SCOPE_REQUIRED');
        // Native authority must remove expired/refunded/voided rights. The
        // original order and holder are temporary session-only copies here.
        Db::name('user_card_holder')->where('id', (int)$chosen['id'])->update(['write_end' => time() - 60]);
        check(total($service->read('basic_customer_entitlements', [$store1, $store2], $input)['rows'], 'rights_amount') === 0, 'Expired card excluded');
        Db::name('user_card_holder')->where('id', (int)$chosen['id'])->update(['write_end' => $chosen['write_end']]);
        foreach (['refund_status' => 2, 'terminal_action' => 1, 'card_upgrade_use_oid' => 123] as $column => $value) {
            Db::name('store_order')->where('id', (int)$chosen['oid'])->update([$column => $value]);
            check(total($service->read('basic_customer_entitlements', [$store1, $store2], $input)['rows'], 'rights_amount') === 0, 'Native card exclusion: ' . $column);
            Db::name('store_order')->where('id', (int)$chosen['oid'])->update([$column => $orderTemplate[$column]]);
        }
        Db::name('user')->where('uid', 1999000001)->update(['now_money' => '13.06']);
        $invalidRejected = false;
        try { $service->read('basic_customer_entitlements', [$store1], $input); }
        catch (Throwable $error) { $invalidRejected = true; }
        check($invalidRejected, 'Broken native balance invariant fails instead of yielding zero');
        Db::name('user')->where('uid', 1999000001)->update(['now_money' => '13.05']);
        // Empty scope population is genuinely empty, not a row of fabricated zeros.
        Db::name('store_user')->where('uid', '>', 0)->update(['status' => 0]);
        check($service->read('basic_unconsumed_summary', [$store1], $input)['rows'] === [], 'Empty population has no synthetic zero row');
        // Force 10001 authorized members. Reject before computing any assets,
        // even when the requested store summary would have just one output row.
        Db::name('store_user')->where('uid', '>', 0)->update(['status' => 1]);
        $users = []; $links = [];
        for ($i = 109; $i <= 10004; ++$i) {
            $user = $template; $user['uid'] = 1999000000 + $i;
            $user['phone'] = '199' . str_pad((string)$i, 8, '0', STR_PAD_LEFT);
            $user['belong_store_id'] = $store1; $user['is_del'] = 0; $user['status'] = 1;
            $users[] = $user;
            $link = $relation; unset($link['id']); $link['uid'] = $user['uid']; $link['store_id'] = $store1; $link['status'] = 1; $links[] = $link;
            if (count($users) === 500 || $i === 10004) {
                Db::name('user')->insertAll($users); Db::name('store_user')->insertAll($links); $users = []; $links = [];
            }
        }
        rejects(static function () use ($service, $store1, $input) { $service->read('basic_unconsumed_summary', [$store1], $input); }, 'ENTITLEMENT_SOURCE_LIMIT_EXCEEDED');
    } finally { Db::rollback(); }
    echo json_encode(['pass' => true, 'checks' => $n, 'fixture_members' => 108,
        'source' => 'real_cashier_and_registered_asset_readers_mysql57_temporary_tables', 'persistent_writes' => false]), PHP_EOL;
} finally {
    foreach (array_reverse($created) as $table) Db::execute('DROP TEMPORARY TABLE IF EXISTS eb_' . $table);
}
