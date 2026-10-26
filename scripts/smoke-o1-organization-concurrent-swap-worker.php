<?php
/**
 * 并发移动 worker：将 $id 的父级改为 $newPid（独立进程/连接）
 * argv: id newPid outJson
 */
declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use app\services\organization\OrganizationManageServices;

$id = (int)($argv[1] ?? 0);
$newPid = (int)($argv[2] ?? 0);
$out = (string)($argv[3] ?? '');

$result = [
    'pid' => getmypid(),
    'id' => $id,
    'new_pid' => $newPid,
    'ok' => 0,
    'error' => '',
];

try {
    if ($id <= 0 || $newPid <= 0 || $out === '') {
        throw new RuntimeException('bad args');
    }
    /** @var OrganizationManageServices $manage */
    $manage = app()->make(OrganizationManageServices::class);
    $row = \think\facade\Db::name('organization')->where('id', $id)->where('is_del', 0)->find();
    if (!$row) {
        throw new RuntimeException('org missing');
    }
    $manage->saveOrganization($id, [
        'pid' => $newPid,
        'name' => (string)$row['name'],
        'sort' => (int)$row['sort'],
    ], 0, 'conc-worker');
    $result['ok'] = 1;
} catch (\Throwable $e) {
    $result['ok'] = 0;
    $result['error'] = $e->getMessage();
}

file_put_contents($out, json_encode($result, JSON_UNESCAPED_UNICODE));
exit(0);
