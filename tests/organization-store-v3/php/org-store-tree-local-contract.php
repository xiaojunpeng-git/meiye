<?php

/** 本地真实数据回归：正式组织选择器不得因切源凭证缺失返回旧区域 ID。 */
require '/var/www/html/vendor/autoload.php';

use app\services\organization\OrganizationResourceSelectorServices;
use app\services\organization\OrganizationScopeService;
use mohe\exceptions\AdminException;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('仅允许本地 ruihao Docker 数据库');
}

function assertScope(bool $passed, string $message): void
{
    if (!$passed) {
        throw new RuntimeException($message);
    }
    echo 'PASS ', $message, PHP_EOL;
}

/** @return int[] */
function treeStoreIds(array $nodes): array
{
    $ids = [];
    foreach ($nodes as $node) {
        if (($node['node_type'] ?? '') === 'store') {
            $ids[] = (int)$node['id'];
        }
        $ids = array_merge($ids, treeStoreIds($node['children'] ?? []));
    }
    return $ids;
}

Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $scope = app()->make(OrganizationScopeService::class);
    assertScope($scope->getSourceMode() === OrganizationScopeService::MODE_ORGANIZATION
        && !$scope->isMigrated(), '复现本地配置为正式组织但旧切源门禁未通过');

    $storeId = (int)Db::name('system_store')->where('is_del', 0)->where('is_show', 1)->value('id');
    assertScope($storeId > 0, '本地存在可见门店');
    $selector = app()->make(OrganizationResourceSelectorServices::class);
    $full = $selector->search(['resource' => 'org_store_tree'],
        ['id' => 1, 'level' => 0, 'admin_type' => 0]);
    $result = $selector->searchScoped(['resource' => 'org_store_tree'], [$storeId]);
    $rootId = (int)Db::name('organization')->where('is_del', 0)->where('pid', 0)->value('id');
    assertScope(count($full['tree']) === 1 && (int)$full['tree'][0]['id'] === $rootId,
        '平台全量选择器从正式组织根节点开始');
    assertScope(count(treeStoreIds($full['tree'])) === (int)Db::name('system_store')
        ->where('is_del', 0)->where('is_show', 1)->count(),
        '平台全量选择器覆盖全部可见门店');
    assertScope(count($result['tree']) === 1 && (int)$result['tree'][0]['id'] === $rootId,
        '选择器根节点来自正式组织，不是旧区域');
    assertScope(treeStoreIds($result['tree']) === [$storeId], '受限范围仅返回被授权门店');
    assertScope($selector->searchScoped(['resource' => 'org_store_tree'], [])['tree'] === [],
        '空权限范围不会展示纯组织节点');

    OrganizationScopeService::setTestIntegrityPassed(false);
    try {
        $selector->searchScoped(['resource' => 'org_store_tree'], [$storeId]);
        throw new RuntimeException('原生组织不完整时错误地返回了选择项');
    } catch (AdminException $e) {
        assertScope(strpos($e->getMessage(), '正式组织数据不完整') !== false,
            '原生组织不完整时明确失败，不回退旧区域');
    } finally {
        OrganizationScopeService::setTestIntegrityPassed(null);
    }

    OrganizationScopeService::setTestSourceMode(OrganizationScopeService::MODE_LEGACY);
    OrganizationScopeService::setTestIntegrityPassed(false);
    try {
        $legacy = $selector->searchScoped(['resource' => 'org_store_tree'], [$storeId]);
        assertScope(treeStoreIds($legacy['tree']) === [$storeId],
            '真正 legacy 模式仍按原范围返回门店，不依赖正式组织完整性');
    } finally {
        OrganizationScopeService::setTestIntegrityPassed(null);
        OrganizationScopeService::setTestSourceMode(null);
    }
} finally {
    Db::rollback();
}
