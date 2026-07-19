<?php
/**
 * O1 source-mode 并发 worker
 *
 * 用法：
 *   php worker.php <action> <out.json> [ready] [go] [extra...]
 *
 * action:
 *   publish_org_hold_gate          — publish organization，门禁后暂停
 *   publish_org_fail_hold_set      — publish organization，SET 后暂停再失败补偿
 *   clear_source                   — clearSourceModeCache
 *   publish_dual_read              — publish dual_read
 *   save_org                       — saveOrganization 修改 sort（阻塞于结构锁）
 *   bind_store                     — bindStoreToOrg（阻塞于结构锁）
 */
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use app\services\organization\OrganizationManageServices;
use app\services\organization\OrganizationScopeService;

$action = (string)($argv[1] ?? '');
$out = (string)($argv[2] ?? '');
$ready = (string)($argv[3] ?? '');
$go = (string)($argv[4] ?? '');

$result = [
    'pid' => getmypid(),
    'action' => $action,
    'ok' => 0,
    'error' => '',
    'started_at' => microtime(true),
    'finished_at' => 0,
    'blocked_observed' => 0,
];

try {
    if ($action === 'publish_org_hold_gate') {
        OrganizationScopeService::setTestHold('after_cutover_gate', $ready, $go);
        OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_ORGANIZATION);
        OrganizationScopeService::clearTestHold();
        $result['ok'] = 1;
    } elseif ($action === 'publish_org_fail_hold_set') {
        OrganizationScopeService::setTestFailAfterRuntimeSet(true);
        OrganizationScopeService::setTestHold('after_runtime_set_before_fail', $ready, $go);
        try {
            OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_ORGANIZATION);
            $result['error'] = 'unexpected success';
        } catch (\Throwable $e) {
            $result['ok'] = 1;
            $result['error'] = $e->getMessage();
        }
        OrganizationScopeService::setTestFailAfterRuntimeSet(false);
        OrganizationScopeService::clearTestHold();
    } elseif ($action === 'clear_source') {
        OrganizationScopeService::clearSourceModeCache();
        $result['ok'] = 1;
    } elseif ($action === 'publish_dual_read') {
        OrganizationScopeService::publishSourceMode(OrganizationScopeService::MODE_DUAL_READ);
        $result['ok'] = 1;
    } elseif ($action === 'save_org') {
        $orgId = (int)($argv[5] ?? 0);
        $row = \think\facade\Db::name('organization')->where('id', $orgId)->find();
        if (!$row) {
            throw new RuntimeException('org not found');
        }
        $newPid = (int)($argv[6] ?? $row['pid']);
        $newSort = (int)$row['sort'] + 1;
        /** @var OrganizationManageServices $manage */
        $manage = app()->make(OrganizationManageServices::class);
        $manage->saveOrganization($orgId, [
            'pid' => $newPid,
            'name' => (string)$row['name'],
            'sort' => $newSort,
        ], 0, 'smoke');
        $result['ok'] = 1;
        $result['org_id'] = $orgId;
        $result['new_pid'] = $newPid;
        $result['new_sort'] = $newSort;
    } elseif ($action === 'bind_store') {
        $storeId = (int)($argv[5] ?? 0);
        $orgId = (int)($argv[6] ?? 0);
        /** @var OrganizationManageServices $manage */
        $manage = app()->make(OrganizationManageServices::class);
        $manage->bindStoreToOrg($storeId, $orgId, 0, 'smoke');
        $result['ok'] = 1;
        $result['store_id'] = $storeId;
        $result['org_id'] = $orgId;
    } else {
        throw new RuntimeException('unknown action=' . $action);
    }
} catch (\Throwable $e) {
    $result['ok'] = 0;
    $result['error'] = $e->getMessage();
}

$result['finished_at'] = microtime(true);
file_put_contents($out, json_encode($result, JSON_UNESCAPED_UNICODE));
exit(0);
