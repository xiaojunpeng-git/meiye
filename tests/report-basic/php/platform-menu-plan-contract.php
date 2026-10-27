<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';
define('BASIC_MENU_PLAN_ONLY', true);
require dirname(__DIR__, 3) . '/后端代码/database/upgrades/2026-10-26-基础报表平台菜单分组/01-正式升级.php';

use app\services\report\BasicReportCatalogServices;

$groups = basicMenuGroups();
$catalog = BasicReportCatalogServices::entries();
if (array_column($groups, 'title') !== ['门店统计', '员工统计', '其他报表']) throw new RuntimeException('分组顺序错误');
if ($groups[2]['auth'] !== 'admin-report-other-reports') throw new RuntimeException('未复用现有其他报表菜单');
if ($groups[0]['path'] !== '/report/basic-reports' || $groups[1]['path'] !== '/report/basic-reports'
    || $groups[2]['path'] !== '/report/other-reports') throw new RuntimeException('分组入口缺少已部署路由');
$reports = array_merge(...array_column($groups, 'reports'));
if (count($reports) !== count($catalog) || count($reports) !== 21) throw new RuntimeException('菜单没有覆盖全部报表');
if (count(array_unique(array_column($reports, 'auth'))) !== 21 || count(array_unique(array_column($reports, 'path'))) !== 21) {
    throw new RuntimeException('报表菜单身份或路径重复');
}
foreach ($catalog as $index => $entry) {
    $report = $reports[$index];
    if ($report['title'] !== $entry['name'] || $report['path'] !== '/report/basic-reports/' . $entry['code']) {
        throw new RuntimeException('菜单与报表目录不一致：' . $entry['code']);
    }
    if ($report['sort'] < -128 || $report['sort'] > 127) throw new RuntimeException('菜单排序超出 010 tinyint 范围');
}
if (inheritedBasicMenuRules('1,2', 3, [4, 5]) !== null) throw new RuntimeException('无基础报表权限的角色被授权');
if (inheritedBasicMenuRules('1,2', 2, [3, 4, 5]) !== '1,2,3,4,5') throw new RuntimeException('已有权限未继承分组与叶子菜单');
if (inheritedBasicMenuRules('1,2,4,5', 2, [4, 5]) !== null) throw new RuntimeException('重复执行改变角色规则');
if (inheritedBasicMenuRules('1,2,2', 2, [4]) !== '1,2,2,4') throw new RuntimeException('继承菜单改写了原角色规则');
echo "PASS platform basic report menu: 3 groups, 21 exact report routes\n";
