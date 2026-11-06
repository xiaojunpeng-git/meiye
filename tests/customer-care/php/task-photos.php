<?php
declare(strict_types=1);

// 复用完整领域夹具验证事务与幂等，不用字符串匹配冒充保存行为。
require __DIR__ . '/core-contract.php';

use app\services\customer\care\CustomerCareCommandContract;
use app\services\customer\care\CustomerCareCommandService;
use app\services\customer\care\CustomerCareDomainException;

$repo = new InMemoryCustomerCareRepository();
$repo->seedAssignment('TENANT_1', 8, 101, 1101, '员工101');
$care = new CustomerCareCommandService($repo, new FixedCustomerCareClock(1785286800));
$command = careCreateCommand(9001);
$photos = ['https://example.invalid/a.jpg', 'https://example.invalid/b.jpg'];
$command['taskPhotos'] = $photos;
$result = $care->createTask(careActor(), $command);
$saved = $repo->tasks[$result['taskId']];
$check = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ' . $label . "\n";
};
$check(json_decode($saved['task_photos'], true) === $photos && $saved['note'] === '测试备注', '多图与备注分别保存在同一个任务');
$check($care->createTask(careActor(), $command)['replayed'] && count($repo->tasks) === 1, '相同多图创建重放不重复');
$command['taskPhotos'] = ['https://example.invalid/c.jpg'];
try { $care->createTask(careActor(), $command); throw new RuntimeException('changed photos accepted'); }
catch (CustomerCareDomainException $expected) { $check($expected->getErrorCode() === 'CARE_IDEMPOTENCY_CONFLICT', '同幂等键换图拒绝'); }
$old = CustomerCareCommandContract::normalizeCreateTask(careCreateCommand(9002));
$actor = CustomerCareCommandContract::normalizeActor(careActor());
$before = $old; unset($before['taskPhotos']);
$check(CustomerCareCommandContract::fingerprint('CREATE_TASK', $actor, $old) === CustomerCareCommandContract::fingerprint('CREATE_TASK', $actor, $before), '升级前无图指纹保持兼容');
$duplicate = $old; $duplicate['taskPhotos'] = [$photos[0], $photos[0], $photos[1]];
$check(CustomerCareCommandContract::normalizeCreateTask($duplicate)['taskPhotos'] === $photos, '重复URL去重并保留选择顺序');
foreach ([['javascript:alert(1)'], ['file:///tmp/photo.jpg'], ['data:image/png;base64,AAAA'], ['blob:temporary'], [null], 'not-an-array', array_fill(0, 10, $photos[0])] as $bad) {
    $invalid = $old; $invalid['taskPhotos'] = $bad;
    try { CustomerCareCommandContract::normalizeCreateTask($invalid); throw new RuntimeException('invalid photos accepted'); }
    catch (CustomerCareDomainException $expected) { $check(true, '非法图片或超量拒绝'); }
}
$plain = $care->createTask(careActor(), careCreateCommand(9003));
$check(json_decode($repo->tasks[$plain['taskId']]['task_photos'], true) === [], '旧客户端无图仍可创建');
echo "TASK_PHOTOS_CONTRACT=PASS\n";
