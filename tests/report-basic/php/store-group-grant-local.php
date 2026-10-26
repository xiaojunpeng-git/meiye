<?php
/** 本地隔离库的临时表集成测试；旧岗位、现有目录和正式库均不写入。 */
declare(strict_types=1);
error_reporting(E_ERROR);
$root = dirname(__DIR__, 3) . '/后端代码';
require $root . '/vendor/autoload.php';
require $root . '/app/services/organization/StorePermissionCatalogServices.php';
$envPath = getenv('BEAUTY_LOCAL_ENV');
if (!$envPath || !is_file($envPath)) throw new RuntimeException('需要显式指定本机开发库环境文件');
$app = new \think\App($root . '/');
$app->env->load($envPath);
$app->initialize();
use think\facade\Db;
use app\services\organization\StorePermissionCatalogServices as Catalog;
$config = config('database.connections.mysql');
if (!in_array((string)$config['database'], ['ruihao', 'mohe_ruihao', 'mohe31'], true)
    || !in_array((string)$config['hostname'], ['mohe-mysql', 'mysql'], true)) {
    throw new RuntimeException('仅允许本机隔离开发库');
}
if ((string)Db::query('SELECT DATABASE() AS name')[0]['name'] !== (string)$config['database']) {
    throw new RuntimeException('本机开发库连接不符');
}
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$tables = [];
try {
    Db::execute('CREATE TEMPORARY TABLE eb_store_v3_permission_catalog (id INT PRIMARY KEY,rules_json MEDIUMTEXT NOT NULL,revision INT NOT NULL,updated_at INT NOT NULL) ENGINE=InnoDB');
    $tables[] = 'eb_store_v3_permission_catalog';
    Db::execute('CREATE TEMPORARY TABLE eb_store_v3_permission_audit (id INT PRIMARY KEY AUTO_INCREMENT,operator_id INT NOT NULL,action VARCHAR(50),before_json MEDIUMTEXT,after_json MEDIUMTEXT,created_at INT) ENGINE=InnoDB');
    $tables[] = 'eb_store_v3_permission_audit';
    Db::execute('CREATE TEMPORARY TABLE eb_job_position_channel_rule (id INT PRIMARY KEY,channel VARCHAR(30),rules TEXT,version INT,update_time INT DEFAULT 0) ENGINE=InnoDB');
    $tables[] = 'eb_job_position_channel_rule';
    $rows = Catalog::definitions() + Catalog::navigationGroups() + Catalog::navigationItems();
    unset($rows[301202]);
    foreach ([301001=>-2801,301002=>-2801,301003=>-2801,301004=>-2801,301005=>-2801,
        301006=>-2806,301008=>-2801,301100=>-2802,301009=>-2801,301062=>-2804,
        301201=>-2804,301200=>-2804,301007=>-2805] as $id=>$parent) $rows[$id]['parent_id'] = $parent;
    $rows[301200]['enabled'] = 0;
    Db::name(Catalog::TABLE)->insert(['id'=>1,'rules_json'=>json_encode(array_values($rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),'revision'=>17,'updated_at'=>1]);
    Db::name('job_position_channel_rule')->insert(['id'=>1,'channel'=>'store_v3','rules'=>'301200','version'=>7]);
    $catalog = new Catalog();
    $catalog->initializeBasicReportPermission();
    $snapshot = $catalog->snapshot();
    $byId = array_column($snapshot['rules'], null, 'id');
    $check($snapshot['revision'] === 18 && ($byId[301202]['feature_code'] ?? '') === 'cashier.v3.basic_reports', '目录安装失败');
    $check($catalog->filterEnabled(['cashier.v3.basic_reports', 'cashier.v3.data']) === ['cashier.v3.basic_reports'], '新权限不得打开停用的数据报表');
    $tree = $catalog->menuTree();
    $group = $tree[array_search(-2804, array_column($tree, 'id'), true)];
    $heading = $group['children'][array_search(-29023, array_column($group['children'], 'id'), true)];
    $check(count($heading['children']) === 21 && $heading['children'][0]['id'] === 33001, '岗位树未动态显示报表规则');
    $check(!in_array(301202, array_column($heading['children'], 'id'), true), '旧总权限不能继续作为可勾选叶子');
    $check(Db::name('job_position_channel_rule')->where('id',1)->value('rules') === '301200', '安装不应自动给已有岗位授权');
    $catalog->initializeBasicReportPermission();
    $check($catalog->snapshot()['revision'] === 18 && Db::name('store_v3_permission_audit')->count() === 1, '重复安装不应改动版本或审计');
    // 目录名称和状态取当前数据库规则；岗位配置不用复制第二份静态叶子。
    $changed = $catalog->snapshot()['rules'];
    foreach ($changed as &$row) if ($row['id'] === -33002) { $row['title'] = '自定义跨店名称'; $row['enabled'] = 0; }
    unset($row);
    Db::name(Catalog::TABLE)->where('id',1)->update(['rules_json'=>json_encode($changed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $tree = $catalog->menuTree();
    $group = $tree[array_search(-2804, array_column($tree, 'id'), true)];
    $heading = $group['children'][array_search(-29023, array_column($group['children'], 'id'), true)];
    $leaf = $heading['children'][array_search(33002, array_column($heading['children'], 'id'), true)];
    $check($leaf['title'] === '自定义跨店名称（已停用）', '岗位树未读取权限规则的实时名称和状态');
    $check(!in_array(Catalog::basicReportFeatureCode('basic_cross_store_settlement'),
        $catalog->filterEnabled(Catalog::explicitFeatures([33002])), true), '停用报表仍被授权');
    // 恢复目录状态后迁移旧总权限，其他旧数据节点已停用不能借迁移扩权。
    foreach ($changed as &$row) if ($row['id'] === -33002) $row['enabled'] = 1;
    unset($row);
    Db::name(Catalog::TABLE)->where('id',1)->update(['rules_json'=>json_encode($changed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    Db::name('job_position_channel_rule')->insert(['id'=>2,'channel'=>'store_v3','rules'=>'301202','version'=>3]);
    $check(Db::name('job_position_channel_rule')->where('id',1)->value('rules') === '301200', '停用旧数据权限不应自动扩权');
    $check($catalog->migrateBasicReportLeafGrants() === 1, '旧总权限未展开为逐表授权');
    $role = Db::name('job_position_channel_rule')->where('id',2)->find();
    $check(count(array_intersect(array_keys(Catalog::basicReportGrantDefinitions()), explode(',', $role['rules']))) === 21,
        '旧岗位未保留原有全部报表访问');
    $features = $catalog->filterEnabled(Catalog::explicitFeatures(explode(',', $role['rules'])));
    $check(count(array_filter($features, static function ($feature) { return strpos($feature, 'cashier.v3.basic_report.basic_') === 0; })) === 21,
        '迁移后的会话未保留全部逐表访问');
    $check(Db::name('job_position_channel_rule')->where('id',1)->value('rules') === '301200', '迁移不能给停用旧数据岗位新增访问');
    Db::name('job_position_channel_rule')->where('id',2)->update(['rules'=>str_replace(',33002', '', $role['rules'])]);
    $check($catalog->migrateBasicReportLeafGrants() === 0, '重复迁移不应重新扩权');
    $check(!in_array('33002', explode(',', (string)Db::name('job_position_channel_rule')->where('id',2)->value('rules')), true),
        '重跑不应恢复管理员已撤销的单表权限');
    $revoked = $catalog->filterEnabled(Catalog::explicitFeatures(explode(',', (string)Db::name('job_position_channel_rule')->where('id',2)->value('rules'))));
    $check(!in_array(Catalog::basicReportFeatureCode('basic_cross_store_settlement'), $revoked, true),
        '旧总权限不能绕过撤销的单表授权');
    echo "PASS local temporary-table basic report grant install\n";
} finally {
    foreach (array_reverse($tables) as $table) Db::execute('DROP TEMPORARY TABLE IF EXISTS ' . $table);
}
