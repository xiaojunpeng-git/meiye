<?php
declare(strict_types=1);

/** 只读目录审计：按真实门店+平台主档+品类核对，不查询会员个人信息或修改业务数据。 */
$root = getenv('CATALOG_AUDIT_APP_ROOT') ?: '/var/www/html/';
require rtrim($root, '/') . '/vendor/autoload.php';
$app = new think\App($root);
$app->initialize();
use think\facade\Db;

// 远程尚未发布新配置时显式传入本地待验收规则；记录指纹，不能冒充线上已生效。
$regexp = getenv('CATALOG_AUDIT_REGEXP') ?: (string)config('product_catalog.historical_store_keyword_regexp', '');
if ($regexp === '') throw new RuntimeException('An explicit audit policy is required');
$database = (string)config('database.connections.mysql.database');
if ($database !== 'ruihao') throw new RuntimeException('This audit is scoped to the requested instance');
$prefix = (string)config('database.connections.mysql.prefix');
if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)) throw new RuntimeException('Invalid prefix');
$historical = "(COALESCE(p.keyword,'') REGEXP BINARY ? AND SUBSTRING_INDEX(COALESCE(p.keyword,''),':STORE:',-1)=CAST(p.relation_id AS CHAR))";
$eligible = "FROM {$prefix}store_product p JOIN {$prefix}system_store s ON s.id=p.relation_id AND s.is_del=0
 WHERE p.type=1 AND p.is_del=0 AND p.is_show=1 AND p.is_verify=1 AND p.product_type IN (0,4,5,6)
 AND EXISTS(SELECT 1 FROM {$prefix}store_product_attr_value a WHERE a.product_id=p.id AND a.type=0 AND a.is_show=1)";

Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $groups = Db::query("SELECT p.relation_id store_id,MAX(s.name) store_name,p.pid platform_id,p.product_type,
        MAX(p.store_name) example_name,COUNT(*) copies,SUM({$historical}) historical_copies,
        GROUP_CONCAT(p.id ORDER BY p.id) product_ids {$eligible} AND p.pid>0
        GROUP BY p.relation_id,p.pid,p.product_type HAVING COUNT(*)>1", [$regexp]);
    $summary = [];
    $stores = [];
    foreach ($groups as $group) {
        $type = (int)$group['product_type'];
        if (!isset($summary[$type])) $summary[$type] = ['groups'=>0,'copies'=>0,'historical_copies'=>0,'mixed_groups'=>0];
        $summary[$type]['groups']++;
        $summary[$type]['copies'] += (int)$group['copies'];
        $summary[$type]['historical_copies'] += (int)$group['historical_copies'];
        if ((int)$group['historical_copies'] > 0 && (int)$group['historical_copies'] < (int)$group['copies']) {
            $summary[$type]['mixed_groups']++;
        }
        $stores[(int)$group['store_id']] = true;
    }
    $carriers = Db::query("SELECT p.product_type,COUNT(*) historical_copies,COUNT(DISTINCT p.relation_id) store_count
        {$eligible} AND {$historical} GROUP BY p.product_type", [$regexp]);
    $query = "SELECT p.relation_id store_id,MAX(s.name) store_name,p.pid platform_id,p.product_type,
        MAX(p.store_name) example_name,GROUP_CONCAT(p.id ORDER BY p.id) product_ids {$eligible} AND {$historical}
        AND NOT EXISTS(SELECT 1 FROM {$prefix}store_product n WHERE n.type=1 AND n.relation_id=p.relation_id
          AND n.pid=p.pid AND n.product_type=p.product_type AND n.is_del=0 AND n.is_show=1 AND n.is_verify=1
          AND NOT (COALESCE(n.keyword,'') REGEXP BINARY ? AND SUBSTRING_INDEX(COALESCE(n.keyword,''),':STORE:',-1)=CAST(n.relation_id AS CHAR))
          AND EXISTS(SELECT 1 FROM {$prefix}store_product_attr_value a WHERE a.product_id=n.id AND a.type=0 AND a.is_show=1))
        GROUP BY p.relation_id,p.pid,p.product_type";
    $withoutSaleCounterpart = Db::query($query, [$regexp, $regexp]);
    $plan = Db::query('EXPLAIN SELECT p.id ' . $eligible . ' AND p.relation_id=132 AND NOT ' . $historical, [$regexp]);
    echo json_encode([
        'scope'=>'rh/ruihao; existing nondeleted stores; active sale products with visible SKUs',
        'generated_at'=>date(DATE_ATOM), 'read_only_transaction'=>true,
        'policy_regexp'=>$regexp, 'policy_sha256'=>hash('sha256', $regexp),
        'database_version'=>Db::query('SELECT VERSION() version')[0]['version'],
        'migration_latest_id'=>Db::name('database_upgrade_log')->max('id'),
        'duplicate_store_count'=>count($stores), 'duplicate_summary'=>$summary,
        'active_historical_summary'=>$carriers,
        'without_active_sale_counterpart_count'=>count($withoutSaleCounterpart),
        'without_active_sale_counterparts'=>$withoutSaleCounterpart,
        'duplicate_groups'=>$groups, 'store132_query_plan'=>$plan,
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    Db::rollback();
}
