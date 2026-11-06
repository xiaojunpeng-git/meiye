<?php
declare(strict_types=1);

// 仅本地绑定后端：迁移可重跑；实际任务、回执、操作和序号在外层事务回滚。
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
$db = config('database.connections.mysql');
if ($db['hostname'] !== 'mysql' || $db['database'] !== 'ruihao') throw new RuntimeException('LOCAL_ONLY');
require __DIR__ . '/core-contract.php';

use app\services\customer\care\CustomerCareCommandService;
use app\services\customer\care\ThinkPhpCustomerCareRepository;
use app\services\customer\care\integration\CustomerCareActionInputMapper;
use app\services\customer\care\query\CustomerCareCursorCodec;
use app\services\customer\care\query\CustomerCareQueryScope;
use app\services\customer\care\query\CustomerCareWorkbenchQueryService;
use app\services\customer\care\query\ThinkPhpCustomerCareQueryRepository;
use think\facade\Db;

$check = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ' . $label . "\n";
};
$migration = require '/var/www/html/database/upgrades/20261107_customer_care_task_photos.php';
$migration(); $check($migration() === [], '任务图片迁移重复执行无变化');
$staff = Db::name('system_store_staff')->alias('s')->join('employee e', 'e.id=s.employee_id')
    ->where('s.status', 1)->where('s.is_del', 0)->where('e.status', 1)->where('e.is_del', 0)
    ->field('s.id,s.employee_id,s.store_id,s.staff_name')->find();
if (!$staff) throw new RuntimeException('LOCAL_ASSIGNMENT_REQUIRED');
$tenant = 'R54_LOCAL_TASK_PHOTOS';
Db::startTrans();
try {
    $actor = careActor((int)$staff['id'], true, [(int)$staff['store_id']]);
    $actor['tenantId'] = $tenant; $actor['employeeId'] = (int)$staff['employee_id'];
    $actor['operationStoreId'] = (int)$staff['store_id'];
    $context = $actor + ['businessTimezone'=>'Asia/Shanghai','canViewAllTasks'=>true,'canCreateTask'=>true,'canCreateRecord'=>true,'canViewStatistics'=>true];
    $clock = new FixedCustomerCareClock(time());
    $queryRepo = new ThinkPhpCustomerCareQueryRepository();
    $mapper = new CustomerCareActionInputMapper($queryRepo, $clock);
    $member = Db::name('store_user')->alias('su')->join('user u','u.uid=su.uid')
        ->where('su.store_id', $actor['operationStoreId'])->where('su.status',1)
        ->where('u.status',1)->where('u.is_del',0)->value('u.uid');
    if (!$member) throw new RuntimeException('LOCAL_MEMBER_REQUIRED');
    $photos = ['https://example.invalid/first.jpg','https://example.invalid/second.jpg'];
    $mapped = $mapper->map('create-care-task', CustomerCareQueryScope::fromTrustedContext($context), [
        'memberId'=>$member,'ownerId'=>$actor['staffId'],'taskTypeCode'=>'FOLLOWUP',
        'plannedAt'=>date('Y-m-d H:i', time()+3600),'content'=>'R54多图备注','taskPhotos'=>$photos,
    ], 'r54-local-photos-create-20261107');
    $service = new CustomerCareCommandService(new ThinkPhpCustomerCareRepository(), $clock);
    $created = $service->createTask($actor, $mapped['command']);
    $stored = Db::name('customer_care_task')->where('id', $created['taskId'])->find();
    $check(json_decode($stored['task_photos'], true) === $photos && $stored['note'] === 'R54多图备注', '真实数据库存储完整多图与备注');
    // 新建查询实例模拟刷新，使用正式授权详情与列表路径，不读前端草稿。
    $reader = new CustomerCareWorkbenchQueryService($queryRepo, $clock, new CustomerCareCursorCodec(str_repeat('r54',16)));
    $detail = $reader->taskDetail($context, (int)$created['taskId']);
    $check($detail['taskPhotos'] === $photos && $detail['note'] === 'R54多图备注', '刷新后正式详情完整读回');
    $list = $reader->query($context, ['view'=>'tasks','query'=>['bucket'=>'all','statusGroup'=>'open']]);
    $rows = $list['taskView']['records'];
    $check(count($rows) === 1 && $rows[0]['taskPhotos'] === $photos, '列表投影支持同一任务详情查看');
    $denied = $context; $denied['allowedBusinessStoreIds'] = [2147483647]; $denied['operationStoreId'] = 2147483647;
    $check($reader->taskDetail($denied, (int)$created['taskId']) === null, '越权门店不可读图片详情');
    $check($service->createTask($actor, $mapped['command'])['replayed'] === true && Db::name('customer_care_task')->where('tenant_id',$tenant)->count() === 1, '真实回执重放不重复创建');
    Db::name('customer_care_task')->where('id',$created['taskId'])->update(['task_photos'=>null]);
    $check($reader->taskDetail($context,(int)$created['taskId'])['taskPhotos'] === [], '历史空图片详情兼容');
} finally { Db::rollback(); }
$check(Db::name('customer_care_task')->where('tenant_id',$tenant)->count() === 0, '本地测试任务全部回滚');
echo "TASK_PHOTOS_LOCAL_DB=PASS\n";
