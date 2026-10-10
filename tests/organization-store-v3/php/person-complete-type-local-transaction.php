<?php

/**
 * 人员分类真实控制器回归：只允许本机 Docker 测试库，整组写入最终回滚。
 * 用真实 :id 参数绑定、POST 请求和保存编排验证，不以字符串合同代替落库验证。
 */
require '/var/www/html/vendor/autoload.php';

use app\services\employee\EmployeePersonCompleteWriteServices;
use app\services\employee\EmployeeTypeAuthorityServices;
use think\facade\Db;

$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao'
    || getenv('PERSON_TYPE_LOCAL_TEST') !== '1') {
    throw new RuntimeException('仅允许显式授权的本机 Docker 测试库');
}

class PersonCompleteTypeLocalController extends \app\controller\admin\v1\merchant\SystemStoreStaff
{
    /** 只提供本地测试请求与身份；不替换任何保存、权限或事务业务方法。 */
    public function __construct(array $post)
    {
        $this->request = (new \app\Request())->withPost($post)
            ->withHeader(['x-request-token' => $post['request_token']]);
        $this->services = app()->make(\app\services\store\SystemStoreStaffServices::class);
        $this->adminId = 1;
        $this->adminType = 0;
        $this->adminInfo = ['id' => 1, 'level' => 0, 'admin_type' => 0,
            'account' => 'local-type-test', 'real_name' => '本地分类回滚测试'];
    }
}

function typeTestAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS ', $message, "\n";
}

function typeTestToken(): string
{
    return '70a00000-0000-4000-8000-' . bin2hex(random_bytes(6));
}

Db::startTrans();
try {
    $orgId = (int)Db::name('organization')->where('is_del', 0)->value('id');
    if (!$orgId) throw new RuntimeException('测试库缺少组织');
    $employeeId = (int)Db::name('employee')->insertGetId([
        'name' => 'R7分类事务测试', 'phone' => '19900007101',
        'employment_type_code' => null, 'employment_type_version' => 0,
    ]);
    Db::name('organization_employee')->insert([
        'org_id' => $orgId, 'employee_id' => $employeeId, 'source' => 'local-type-test',
    ]);
    $input = [
        'staff_name' => 'R7分类事务测试', 'phone' => '19900007101',
        'org_id' => $orgId, 'store_id' => 0, 'scope_mode' => 'personal',
        'avatar' => '/static/images/staff/avatar_male.png',
        'employment_type_code' => 'partner', 'employment_type_version' => 0,
        'request_token' => typeTestToken(),
    ];
    // 与前端一致：身份只由路径 :id 提供，不在请求体偷偷补 employee_id 掩盖路由问题。
    $save = static function (array $post) use ($app, $employeeId): array {
        return $app->invokeMethod([new PersonCompleteTypeLocalController($post), 'savePersonComplete'],
            ['id' => $employeeId])->getData();
    };
    $first = $save($input);
    typeTestAssert((int)$first['status'] === 200
        && (int)$first['data']['employee_id'] === $employeeId
        && $first['data']['employment_type_code'] === 'partner'
        && (int)$first['data']['employment_type_version'] === 1,
        '未分类员工首次保存合作方，路径身份不丢失且版本从零递增');
    $complete = app()->make(EmployeePersonCompleteWriteServices::class);
    $read = $complete->getComplete($employeeId, 0, 'hq', true);
    typeTestAssert($read['employment_type_code'] === 'partner'
        && (int)$read['employment_type_version'] === 1, '重新读取详情恢复已保存类型');
    $replay = $save($input);
    typeTestAssert($replay['data'] === $first['data'], '重复请求回放同一结果');
    $input['employment_type_code'] = 'internal';
    $input['employment_type_version'] = 1;
    $input['request_token'] = typeTestToken();
    $second = $save($input);
    typeTestAssert((int)$second['status'] === 200
        && $second['data']['employment_type_code'] === 'internal'
        && (int)$second['data']['employment_type_version'] === 2, '再次编辑内部员工正确保存');
    $input['employment_type_code'] = 'partner';
    $input['request_token'] = typeTestToken();
    $stale = $save($input);
    typeTestAssert((int)$stale['status'] === 400 && strpos($stale['msg'], '人员类型已被其他操作修改') !== false,
        '旧版本请求被拒绝，不能覆盖已保存类型');
    typeTestAssert((int)Db::name('employee_change_log')->where('employee_id', $employeeId)
        ->where('action', EmployeeTypeAuthorityServices::ACTION)->count() === 2, '仅两次真实类型变更产生审计');
    $mismatched = $input;
    $mismatched['employee_id'] = $employeeId + 1;
    $mismatched['request_token'] = typeTestToken();
    $denied = $save($mismatched);
    typeTestAssert((int)$denied['status'] === 400 && $denied['msg'] === '路径员工与提交员工不一致',
        '请求体不能把保存对象切换为其他员工');
} finally {
    Db::rollback();
}
typeTestAssert(!Db::name('employee')->where('phone', '19900007101')->find(), '测试员工及所有写入整体回滚');
