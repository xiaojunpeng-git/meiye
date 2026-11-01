<?php
declare(strict_types=1);

/** 集团其他销售人直属岗位回归：本地事务夹具，结束时全部回滚。 */
require '/var/www/html/vendor/autoload.php';

use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3PersonnelIdentity;
use app\services\cashier\v3\member\CashierV3QueryEntitySelectorServices;
use app\services\cashier\v3\order\CashierV3RefundSalespersonServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if (getenv('R21_DIRECT_POSITION_LOCAL_TEST') !== '1'
    || config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao') {
    throw new RuntimeException('仅允许显式授权的本机 Docker 测试库');
}

function r21DirectPositionAssert(bool $ok, string $name): void
{
    if (!$ok) throw new RuntimeException('FAIL ' . $name);
    echo 'PASS ', $name, "\n";
}

function r21DirectPositionToken(): string
{
    return '21000000-0000-4000-8000-' . bin2hex(random_bytes(6));
}

Db::startTrans();
try {
    $binding = (array)Db::name('organization_store')->alias('os')
        ->join('system_store s', 's.id=os.store_id')
        ->join('organization o', 'o.id=os.org_id')
        ->where('s.is_del', 0)->where('o.is_del', 0)
        ->field('os.store_id,os.org_id')->find();
    $storeId = (int)($binding['store_id'] ?? 0);
    $organizationId = (int)($binding['org_id'] ?? 0);
    r21DirectPositionAssert($storeId > 0 && $organizationId > 0, '取得本地门店和组织边界');

    $employeeId = (int)Db::name('employee')->insertGetId([
        'name' => 'R21集团独立销售', 'phone' => '19900210001',
        'employment_type_code' => 'internal', 'employment_type_version' => 1,
        'status' => 1, 'is_del' => 0,
    ]);
    Db::name('organization_employee')->insert([
        'org_id' => $organizationId, 'employee_id' => $employeeId,
        'job_title' => '直属员工', 'source' => 'r21-local-test', 'status' => 1, 'is_del' => 0,
    ]);
    $normalPositionId = (int)Db::name('position')->insertGetId([
        'name' => 'R21普通岗位', 'status' => 1, 'performance_independent' => 0,
        'use_cashier' => 1, 'remark' => 'R21本地回滚夹具',
    ]);
    $independentPositionId = (int)Db::name('position')->insertGetId([
        'name' => 'R21独立岗位', 'status' => 1, 'performance_independent' => 1,
        'use_cashier' => 1, 'remark' => 'R21本地回滚夹具',
    ]);
    // 先插入普通岗位，证明选择结果由独立核算优先级决定，而不是碰巧取第一条。
    Db::name('staff_job_position')->insert([
        'employee_id' => $employeeId, 'store_id' => 0, 'staff_id' => 0,
        'position_id' => $normalPositionId, 'status' => 1, 'start_time' => time() - 100,
        'request_token' => r21DirectPositionToken(), 'operator_name' => 'R21',
    ]);
    Db::name('staff_job_position')->insert([
        'employee_id' => $employeeId, 'store_id' => 0, 'staff_id' => 0,
        'position_id' => $independentPositionId, 'status' => 1, 'start_time' => time() - 100,
        'request_token' => r21DirectPositionToken(), 'operator_name' => 'R21',
    ]);

    $selector = new CashierV3QueryEntitySelectorServices();
    // Exercise the shared public reader after removing the superseded private implementation.
    $query = static function ($keyword, $operator) {
        return (new \app\services\cashier\v3\member\CashierV3OtherCraftsmanQueryServices())->query(
            ['selectorContext' => ['scope' => 'cashier_other_salespeople'], 'keyword' => $keyword, 'page' => 1, 'pageSize' => 20], $operator);
    };
    $operator = new CashierV3OperatorScope($storeId, 1, (string)$organizationId, '0');
    $page = $query('R21集团独立销售', $operator);
    $candidate = $page['records'][0] ?? [];
    r21DirectPositionAssert(count($page['records']) === 1
        && (int)$candidate['employeeId'] === $employeeId
        && (int)$candidate['positionId'] === $independentPositionId
        && $candidate['positionName'] === 'R21独立岗位'
        && $candidate['performanceIndependent'] === true
        && $candidate['allocationGroupKey'] === 'independent:' . $independentPositionId,
        '候选读取集团直属独立岗位并进入独立核算组');

    $locked = $selector->lockOrganizationSalesperson(
        CashierV3PersonnelIdentity::organizationStaffId($employeeId),
        $operator
    );
    r21DirectPositionAssert((int)$locked['position_id'] === $independentPositionId
        && $locked['position_name'] === 'R21独立岗位'
        && (int)$locked['performance_independent'] === 1,
        '结账事务重读同一直属独立岗位');

    $refundNormalize = new ReflectionMethod(CashierV3RefundSalespersonServices::class, 'normalize');
    $refundNormalize->setAccessible(true);
    $refundPeople = $refundNormalize->invoke(new CashierV3RefundSalespersonServices(), [[
        'staffId' => CashierV3PersonnelIdentity::organizationStaffId($employeeId),
        'personnelSource' => 'other', 'allocationWeight' => 100,
    ]], $operator);
    r21DirectPositionAssert(count($refundPeople) === 1
        && (int)$refundPeople[0]['positionId'] === $independentPositionId
        && $refundPeople[0]['performanceIndependent'] === true
        && $refundPeople[0]['allocationGroupKey'] === 'independent:' . $independentPositionId,
        '退款重校验沿用直属独立核算组');

    Db::name('position')->where('id', $independentPositionId)->update(['status' => 0]);
    $fallbackPage = $query('R21集团独立销售', $operator);
    $fallback = $fallbackPage['records'][0] ?? [];
    r21DirectPositionAssert((int)$fallback['positionId'] === $normalPositionId
        && $fallback['positionName'] === 'R21普通岗位'
        && $fallback['performanceIndependent'] === false
        && $fallback['allocationGroupKey'] === 'normal',
        '停用岗位不参与核算，稳定回退有效普通岗位');
} finally {
    Db::rollback();
}

r21DirectPositionAssert(!Db::name('employee')->where('phone', '19900210001')->find(), '全部测试夹具回滚');
echo "R21_ORGANIZATION_SALESPERSON_DIRECT_POSITION=PASS\n";
