<?php
// 本地只读验证：目录与数据库同步、叶子授权不扩散，读取不改写岗位或菜单。
require 'vendor/autoload.php';
(new think\App())->initialize();
use app\services\organization\StorePermissionCatalogServices as Catalog;
use app\services\organization\JobPositionPolicyServices as Policy;
use think\facade\Db;
function verify($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function digest() { return hash('sha256', json_encode([
    Db::name(Catalog::TABLE)->order('id')->select()->toArray(),
    Db::name('job_position_channel_rule')->order('id')->select()->toArray(),
])); }
// 目录登记不能使登录初始化误调用没有安装的报表 Reader。
$reports = app\services\report\BasicReportHostServices::catalog();
verify(count($reports) > 0, '报表启动目录为空');
app\services\query\UnifiedQueryRuntime::service('providers');
$before = digest(); $service = new Catalog(); $snapshot = $service->snapshot();
verify(count($snapshot['rules']) === count(Catalog::definitions()) + count(Catalog::navigationGroups()) + count(Catalog::navigationItems()), '目录数量不一致');
$tree = $service->menuTree(); $ids = [];
$walk = function ($nodes) use (&$walk, &$ids) { foreach ($nodes as $node) { $ids[] = $node['id']; $walk($node['children'] ?? []); } }; $walk($tree);
verify(in_array(33022, $ids, true), '缺少门店对账单叶子');
$features = Catalog::explicitFeatures([33022]);
verify(in_array(Catalog::basicReportFeatureCode('basic_store_reconciliation'), $features, true), '叶子能力丢失');
verify(!in_array(Catalog::basicReportFeatureCode('basic_store_summary'), $features, true), '不应自动授予其他报表');
verify(!in_array(Catalog::basicReportFeatureCode('basic_store_reconciliation'), Catalog::explicitFeatures([301202]), true), '总入口不应自动授予叶子');
verify(isset(Policy::storeV3FeatureMap()[33022]), '保存白名单缺少叶子编号');
verify(!in_array('cashier.v3.basic_report.basic_store_reconciliation', $service->filterEnabled($features), true), '本地已停用基础报表不得被恢复授权');
verify($before === digest(), '读取修改了既有权限');
echo "PASS: catalog/tree/leaf/save allowlist/no sibling grants/disabled group/read-only\n";
echo 'permission_digest='.$before."\n";
