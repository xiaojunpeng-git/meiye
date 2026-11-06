<?php
declare(strict_types=1);

/**
 * 010 缺失服务恢复的只读证据采集。原核销 ID 是唯一来源身份；输出包含
 * 私人业务数据，只能保存到私有验收目录，不能提交 Git 或打印到聊天。
 * 不改旧订单、卡持有人、余额、次数或任何生产事实。
 */
require getcwd() . '/vendor/autoload.php';
(new \think\App())->initialize();
use think\facade\Db;
$config = config('database.connections.mysql');
if (realpath(getcwd()) !== '/www/wwwroot/010.cc3798.com' || $config['database'] !== 'mohe_010_app') {
    throw new RuntimeException('TARGET_NOT_010');
}
/** 精确 ID 分批查询；空集合不允许退化为全表读取。 */
function recoveryRows(string $table, string $column, array $ids): array
{
    $out = [];
    foreach (array_chunk(array_values(array_unique($ids)), 250) as $chunk) {
        foreach (Db::name($table)->whereIn($column, $chunk)->order('id')->select()->toArray() as $row) $out[] = $row;
    }
    return $out;
}
Db::execute('START TRANSACTION READ ONLY');
try {
    $known = array_fill_keys(array_map('intval', Db::name('cashier_v3_entitlement_service_fact')
        ->where('tenant_id', '0')->whereIn('source_document_type', ['legacy_writeoff', 'legacy_paid_project'])
        ->column('source_document_id')), true);
    $cooperation = array_fill_keys(array_map('intval', Db::name('store_product_relation')
        ->where('relation_id', 78)->where('type', 1)->column('product_id')), true);
    $missing = [];
    foreach (Db::name('store_order_writeoff')->where('status', 0)->where('add_time', '>', 0)->order('id')->select()->toArray() as $w) {
        if (!isset($known[(int)$w['id']]) && !isset($cooperation[(int)$w['product_id']])) $missing[] = $w;
    }
    $orders = array_column($missing, 'oid');
    $data = ['target' => '010.cc3798.com/mohe_010_app', 'missing' => $missing, 'tables' => [], 'schema' => []];
    foreach (['store_order'=>'id', 'store_order_cart_info'=>'oid', 'user_card_holder'=>'oid',
        'store_order_writeoff'=>'oid', 'cashier_v3_sales_order'=>'source_document_id'] as $table=>$column) {
        $data['tables'][$table] = recoveryRows($table, $column, $orders);
    }
    $data['tables']['cashier_v3_sales_order_line'] = recoveryRows('cashier_v3_sales_order_line', 'order_id',
        array_column($data['tables']['cashier_v3_sales_order'], 'order_id'));
    $data['tables']['staff_yeji'] = recoveryRows('staff_yeji', 'link_id', array_column($missing, 'id'));
    $data['tables']['system_store_staff'] = recoveryRows('system_store_staff', 'id', array_merge(
        array_column($missing, 'staff_id'), array_column($data['tables']['staff_yeji'], 'staff_id')));
    $data['tables']['store_product'] = recoveryRows('store_product', 'id', array_column($missing, 'product_id'));
    // 010 自己的迁移前快照只补原始元数据证据，不恢复已删除的员工或商品。
    $data['historical_staff'] = [];
    $credential = parse_ini_file('/root/.mysql57-008.cnf', true, INI_SCANNER_RAW)['client'];
    $archive = new PDO('mysql:host=127.0.0.1;port=3307;dbname=mohe_010_refresh;charset=utf8mb4',
        $credential['user'] ?? 'root', $credential['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $archive->exec('SET TRANSACTION READ ONLY');
    $archive->beginTransaction();
    foreach (array_chunk(array_values(array_unique(array_column($data['tables']['staff_yeji'], 'staff_id'))), 250) as $ids) {
        $list = implode(',', array_map('intval', $ids));
        foreach ($archive->query('SELECT id,is_hezuofang,staff_name FROM eb_system_store_staff WHERE id IN (' . $list . ')')->fetchAll(PDO::FETCH_ASSOC) as $row) $data['historical_staff'][] = $row;
    }
    $archive->rollBack();
    // user 的主键是 uid，单独查询，避免假设通用 id。
    $data['tables']['user'] = [];
    foreach (array_chunk(array_values(array_unique(array_column($missing, 'uid'))), 250) as $ids) {
        foreach (Db::name('user')->whereIn('uid', $ids)->field('uid,real_name,nickname')->select()->toArray() as $row) $data['tables']['user'][] = $row;
    }
    foreach (['organization','organization_store','system_store','store_product_category'] as $table) {
        $data['tables'][$table] = Db::name($table)->select()->toArray();
    }
    foreach (['cashier_v3_entitlement_service_fact','cashier_v3_entitlement_writeoff_fact'] as $table) {
        $data['tables'][$table] = recoveryRows($table, 'source_document_id', array_column($data['tables']['store_order_writeoff'], 'id'));
    }
    $lineIds = array_merge(array_column($data['tables']['cashier_v3_entitlement_service_fact'], 'source_line_id'),
        array_column($data['tables']['cashier_v3_sales_order_line'], 'order_line_id'));
    $data['tables']['cashier_v3_performance_fact'] = recoveryRows('cashier_v3_performance_fact', 'source_line_id', $lineIds);
    $data['tables']['cashier_v3_order_lifecycle_operation'] = Db::name('cashier_v3_order_lifecycle_operation')
        ->where('status','succeeded')->where('source_type','sales')->where('operation_type','void')->select()->toArray();
    foreach (['cashier_v3_entitlement_service_fact','cashier_v3_entitlement_writeoff_fact',
        'cashier_v3_performance_fact','cashier_v3_business_event'] as $table) {
        $data['schema'][$table] = Db::query('SHOW COLUMNS FROM ' . Db::name($table)->getTable());
    }
    Db::execute('ROLLBACK');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    Db::execute('ROLLBACK');
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
