<?php

/**
 * 使用 ThinkPHP 的真实参数绑定验证人员保存路径，防止 :id 被默认值零吞掉。
 * 仅探测控制器委托的身份，不运行保存编排，不连接数据库或修改人员资料。
 */
require '/var/www/html/vendor/autoload.php';

class PersonCompleteRouteIdentityProbe extends \app\controller\admin\v1\merchant\SystemStoreStaff
{
    public function __construct()
    {
    }

    /** 捕获身份边界；业务服务的写入回归由独立事务测试覆盖。 */
    protected function saveStaffByIdentity(int $id, bool $explicitEmployee)
    {
        return ['id' => $id, 'explicit_employee' => $explicitEmployee];
    }
}

$container = new \think\Container();
$controller = new PersonCompleteRouteIdentityProbe();
foreach ([1803, 437, 0] as $id) {
    $result = $container->invokeMethod([$controller, 'savePersonComplete'], ['id' => $id]);
    if ($result !== ['id' => $id, 'explicit_employee' => true]) {
        throw new RuntimeException('人员保存路由未绑定 employee_id：' . json_encode($result));
    }
}
$staff = $container->invokeMethod([$controller, 'saveStaff'], ['id' => 1565]);
if ($staff !== ['id' => 1565, 'explicit_employee' => false]) {
    throw new RuntimeException('兼容店员入口的身份边界变化');
}
echo "PASS :id 路由绑定既有员工/新建人员，店员与员工身份边界保持独立\n";
