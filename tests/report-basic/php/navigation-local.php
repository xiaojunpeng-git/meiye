<?php
/** R51真实本地MySQL临时配置表测试；同名临时表遮蔽持久表，禁止复写客户元数据或岗位授权。 */
$root = getenv('BACKEND_ROOT') ?: getcwd();
require $root . '/vendor/autoload.php';
// Worktree tests share dependency vendor with the primary checkout; load the candidate catalog explicitly.
require $root . '/app/services/organization/StorePermissionCatalogServices.php';
$app = new \think\App($root . '/');
if ($envPath = getenv('BEAUTY_LOCAL_ENV')) $app->env->load($envPath);
$app->initialize();

use think\facade\Db;
use app\services\organization\StorePermissionCatalogServices as Catalog;
use app\services\report\BasicReportCatalogServices as Reports;

set_exception_handler(static function ($error) { file_put_contents('php://stderr', (string)$error . PHP_EOL); exit(1); });
$config = config('database.connections.mysql');
if (getenv('R51_LOCAL_TEST') !== '1' || !in_array($config['hostname'], ['mysql', 'mohe-mysql', 'localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Explicit local-only integration environment required');
}
if (($config['prefix'] ?? '') !== 'eb_') throw new RuntimeException('Unexpected local table prefix');
$checks = 0;
function navigation51check(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    ++$checks;
}

$catalog = new Catalog();
$tables = [];
try {
    // 仅创建合成配置和审计，绝不复制持久表内容；清理也必须带TEMPORARY限定。
    Db::execute('CREATE TEMPORARY TABLE eb_store_v3_permission_catalog (id INT UNSIGNED PRIMARY KEY,rules_json MEDIUMTEXT NOT NULL,revision INT UNSIGNED NOT NULL,updated_at INT UNSIGNED NOT NULL) ENGINE=InnoDB');
    $tables[] = 'store_v3_permission_catalog';
    Db::execute('CREATE TEMPORARY TABLE eb_store_v3_permission_audit (id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,operator_id INT UNSIGNED NOT NULL,action VARCHAR(40) NOT NULL,before_json MEDIUMTEXT NOT NULL,after_json MEDIUMTEXT NOT NULL,created_at INT UNSIGNED NOT NULL) ENGINE=InnoDB');
    $tables[] = 'store_v3_permission_audit';
    Db::execute('CREATE TEMPORARY TABLE eb_job_position_channel_rule (id INT PRIMARY KEY,channel VARCHAR(30),rules TEXT,version INT) ENGINE=InnoDB');
    $tables[] = 'job_position_channel_rule';
    foreach (['store_v3', 'platform', 'mobile'] as $index => $channel) {
        Db::name('job_position_channel_rule')->insert(['id'=>$index+1,'channel'=>$channel,'rules'=>'301200,301005','version'=>7]);
    }
    $grants = Db::name('job_position_channel_rule')->order('id')->select()->toArray();
    $defaults = Catalog::definitions() + Catalog::navigationGroups() + Catalog::navigationItems();
    $parents = [301001=>-2801,301002=>-2801,301003=>-2801,301004=>-2801,301005=>-2801,301006=>-2806,301008=>-2801,
        301100=>-2802,301009=>-2801,301062=>-2804,301201=>-2804,301200=>-2804,301007=>-2805];
    foreach ($parents as $id => $parent) $defaults[$id]['parent_id'] = $parent;
    $defaults[301200]['title'] = '既有数据标题';
    $defaults[301200]['sort'] = 73;
    $defaults[-29002]['enabled'] = 0;
    $defaults[-29002]['fixture_metadata'] = '不得丢失的既有扩展';

    // 显式锁定code→ID，避免目录插入新项后旧编号漂移，标题则始终取唯一业务目录。
    $codes = ['store_summary','cross_store_settlement','brush_registration','store_item_sales','item_consumption','writeoff_detail','product_sales',
        'salary_realtime','employee_summary','employee_item_sales','employee_service_home_store','employee_service_store','employee_performance_detail',
        'balance_order_detail','balance_order_summary','daily_reconciliation','unconsumed_summary','unconsumed_detail','followup_employee_detail','customer_entitlements','unconsumed_as_of_detail'];
    $entries = array_column(Reports::entries(), null, 'code');
    navigation51check(count($entries) === 21, 'Exactly twenty-one business reports');
    foreach ($codes as $index => $code) {
        $node = $defaults[-33001-$index];
        navigation51check($node['navigation_key'] === 'report.basic_'.$code && $node['title'] === $entries['basic_'.$code]['name'], 'Stable code identity and catalog title: '.$code);
        navigation51check($node['owner_feature'] === 'cashier.v3.data' && $node['parent_id'] === -29023 && $node['grantable'] === false, 'Navigation does not grant a business capability: '.$code);
    }
    navigation51check(!isset(Catalog::navigationGroups()[-29023]), 'Basic reports never becomes a top-level group');

    /** 只向当前连接的临时表写合成旧目录，旧审计刻意保留同名动作以覆盖重复升级路径。 */
    $seed = static function (array $rows): array {
        Db::name(Catalog::TABLE)->delete(true);
        Db::name('store_v3_permission_audit')->delete(true);
        Db::name(Catalog::TABLE)->insert(['id'=>1,'rules_json'=>json_encode(array_values($rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),'revision'=>41,'updated_at'=>123]);
        Db::name('store_v3_permission_audit')->insert(['id'=>10,'operator_id'=>77,'action'=>'INITIALIZE_BASIC_REPORT_NAVIGATION','before_json'=>'prior-before','after_json'=>'prior-after','created_at'=>1]);
        return Db::name('store_v3_permission_audit')->where('id',10)->find();
    };

    foreach ([0, 1] as $enabled) {
        $old = $defaults;
        foreach ($codes as $index => $code) unset($old[-33001-$index]);
        $old[-29023] = array_replace($old[-29023], ['navigation_key'=>'report.basic','parent_id'=>301200,'sort'=>220,'enabled'=>$enabled,'title'=>'基础报表原名']);
        // 部分执行后已经配置的子项也不能被重复启用或覆盖名称/顺序。
        $old[-33003] = array_replace($defaults[-33003], ['enabled'=>0,'title'=>'已配置刷单登记','sort'=>87]);
        $priorAudit = $seed($old);
        $catalog->initializeBasicReportNavigation();
        $snapshot = $catalog->snapshot();
        $byId = array_column($snapshot['rules'], null, 'id');
        navigation51check($snapshot['revision'] === 42, 'Upgrade advances revision once');
        navigation51check($byId[-29023]['navigation_key'] === '' && $byId[-29023]['parent_id'] === -2804 && $byId[-29023]['sort'] === 30, 'Old stable leaf becomes data-center heading');
        navigation51check($byId[-29023]['enabled'] === $enabled && $byId[-29023]['title'] === '基础报表原名', 'Heading enabled and configured title preserved');
        navigation51check($byId[-33003] === $old[-33003], 'Existing disabled child configuration preserved');
        navigation51check(count(array_filter($byId, static function ($row) { return $row['parent_id'] === -29023 && $row['id'] < 0; })) === 21, 'All twenty-one navigation children remain under the heading');
        navigation51check(count($catalog->validateRows($snapshot['rules'])) === count($defaults), 'Existing public validation accepts heading and direct report children');
        foreach ($old as $id => $row) if ($id !== -29023) navigation51check($byId[$id] === $row, 'Existing rule unchanged: '.$id);
        navigation51check(Db::name('job_position_channel_rule')->order('id')->select()->toArray() === $grants, 'Every channel grant and version untouched');
        navigation51check(Db::name('store_v3_permission_audit')->where('id',10)->find() === $priorAudit, 'Prior audit not overwritten');
        $audits = Db::name('store_v3_permission_audit')->order('id')->select()->toArray();
        navigation51check(count($audits) === 2 && json_decode($audits[1]['before_json'],true) === array_values($old) && json_decode($audits[1]['after_json'],true) === $snapshot['rules'], 'Migration appends exact before/after audit');
        $persisted = Db::name(Catalog::TABLE)->where('id',1)->find();
        $catalog->initializeBasicReportNavigation();
        navigation51check(Db::name(Catalog::TABLE)->where('id',1)->find() === $persisted && Db::name('store_v3_permission_audit')->order('id')->select()->toArray() === $audits, 'Repeat is byte-identical including revision, timestamp and audits');
    }

    $missing = $defaults;
    unset($missing[-29023]);
    foreach ($codes as $index => $code) unset($missing[-33001-$index]);
    $seed($missing);
    $catalog->initializeBasicReportNavigation();
    navigation51check(count($catalog->snapshot()['rules']) === count($defaults), 'Missing entire R51 subtree filled');

    // 已升级后的人工启停/重排属于配置；再次安装不把管理员的选择覆盖为默认。
    $configured = array_column($catalog->snapshot()['rules'], null, 'id');
    $configured[-29023]['enabled'] = 0;
    $configured[-29023]['sort'] = 84;
    $configured[-33021]['enabled'] = 0;
    $seed($configured);
    $persisted = Db::name(Catalog::TABLE)->where('id',1)->find();
    $catalog->initializeBasicReportNavigation();
    navigation51check(Db::name(Catalog::TABLE)->where('id',1)->find() === $persisted, 'Already migrated disabled subtree and custom sort preserved');

    // 其他模块目录缺口不能借R51安装补齐；原验证失败时本轮新增也必须全部回滚。
    $unrelatedMissing = $missing;
    unset($unrelatedMissing[-30001]);
    $seed($unrelatedMissing);
    $persisted = Db::name(Catalog::TABLE)->where('id',1)->find();
    $rejected = false;
    try { $catalog->initializeBasicReportNavigation(); } catch (\InvalidArgumentException $error) { $rejected = true; }
    navigation51check($rejected && Db::name(Catalog::TABLE)->where('id',1)->find() === $persisted, 'R51 cannot initialize unrelated missing navigation');
    navigation51check(Db::name('store_v3_permission_audit')->count() === 1, 'Invalid unrelated catalog does not append successful migration audit');

    // 故意让新增审计发生唯一键冲突，证明目录更新不能绕过审计独自提交。
    $seed($missing);
    Db::execute('ALTER TABLE eb_store_v3_permission_audit ADD UNIQUE KEY fixture_action (action)');
    $persisted = Db::name(Catalog::TABLE)->where('id',1)->find();
    $failed = false;
    try { $catalog->initializeBasicReportNavigation(); } catch (\think\db\exception\PDOException $error) { $failed = true; }
    navigation51check($failed && Db::name(Catalog::TABLE)->where('id',1)->find() === $persisted, 'Audit failure rolls back rules, revision and timestamp');
    navigation51check(Db::name('store_v3_permission_audit')->count() === 1, 'Failed migration adds no audit');
    echo json_encode(['pass'=>true,'checks'=>$checks,'source'=>'real_local_mysql_temporary_configuration_tables','persistent_writes'=>false,
        'catalog_sha256'=>hash_file('sha256',$root.'/app/services/organization/StorePermissionCatalogServices.php')], JSON_UNESCAPED_UNICODE), PHP_EOL;
} finally {
    foreach (array_reverse($tables) as $table) Db::execute('DROP TEMPORARY TABLE IF EXISTS eb_'.$table);
}
