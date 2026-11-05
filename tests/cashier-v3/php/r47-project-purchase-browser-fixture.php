<?php
declare(strict_types=1);

/** Disposable local browser identities. Never modifies a real employee's password. */
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/'); $app->initialize();
use think\facade\Db;
$config = Db::connect()->getConfig();
if (getenv('R47_LOCAL_BROWSER') !== '1' || $config['hostname'] !== 'mysql' || $config['database'] !== 'ruihao') {
    throw new RuntimeException('Explicit local browser fixture only');
}
$tag = (string)getenv('R47_TAG');
if (!preg_match('/^R47UI[a-f0-9]{12}$/D', $tag)) throw new RuntimeException('Invalid synthetic tag');
$mode = (string)getenv('R47_MODE');
if ($mode === 'cleanup') {
    Db::transaction(static function () use ($tag): void {
        $employee = (int)Db::name('employee')->where('name', $tag)->value('id');
        $staff = (int)Db::name('system_store_staff')->where('account', $tag)->value('id');
        $position = (int)Db::name('position')->where('name', $tag)->value('id');
        $member = (int)Db::name('user')->where('account', $tag)->value('uid');
        Db::name('staff_job_position')->where('staff_id', $staff)->delete();
        Db::name('job_position_channel_rule')->where('position_id', $position)->delete();
        Db::name('position')->where('id', $position)->delete();
        Db::name('employee_internal_account')->where('employee_id', $employee)->delete();
        Db::name('system_store_staff')->where('id', $staff)->delete();
        Db::name('employee')->where('id', $employee)->delete();
        Db::name('system_admin')->where('account', $tag . 'admin')->delete();
        Db::name('store_user')->where('uid', $member)->delete();
        Db::name('user')->where('uid', $member)->delete();
    });
    echo "CLEANED\n";
    return;
}
if ($mode !== 'setup') throw new RuntimeException('Unsupported fixture operation');
$password = (string)getenv('R47_PASSWORD');
if (strlen($password) < 16) throw new RuntimeException('Temporary fixture password required');
$result = Db::transaction(static function () use ($tag, $password): array {
    $store = 23;
    $employee = (int)Db::name('employee')->insertGetId(['name' => $tag, 'status' => 1, 'is_del' => 0,
        'employment_type_code' => 'internal', 'employment_type_version' => 1]);
    $staff = (int)Db::name('system_store_staff')->insertGetId(['store_id' => $store, 'employee_id' => $employee,
        'staff_name' => $tag, 'account' => $tag, 'status' => 1, 'is_del' => 0, 'level' => 0]);
    $position = (int)Db::name('position')->insertGetId(['name' => $tag, 'status' => 1, 'use_store' => 1]);
    Db::name('staff_job_position')->insert(['employee_id' => $employee, 'staff_id' => $staff,
        'store_id' => $store, 'position_id' => $position, 'request_token' => $tag, 'status' => 1]);
    Db::name('job_position_channel_rule')->insert(['position_id' => $position, 'channel' => 'store_v3',
        'rules' => '301010,301012,301006,301032', 'status' => 1]);
    Db::name('employee_internal_account')->insert(['employee_id' => $employee, 'account' => $tag,
        'pwd' => password_hash($password, PASSWORD_BCRYPT), 'status' => 1, 'credential_version' => 1, 'is_del' => 0,
        'add_time' => time(), 'update_time' => time()]);
    Db::name('system_admin')->insert(['account' => $tag . 'admin', 'pwd' => password_hash($password, PASSWORD_BCRYPT),
        'real_name' => $tag, 'roles' => '1', 'level' => 0, 'status' => 1, 'is_del' => 0, 'admin_type' => 0]);
    $member = (int)Db::name('user')->insertGetId(['account' => $tag, 'real_name' => $tag,
        'nickname' => $tag, 'phone' => '19900004700', 'belong_store_id' => $store, 'status' => 1, 'is_del' => 0]);
    Db::name('store_user')->insert(['uid' => $member, 'store_id' => $store, 'status' => 1]);
    return ['tag' => $tag, 'member' => $member, 'store' => $store, 'switchBefore' => (string)Db::name('system_config')->where('menu_name', 'cashier_project_auto_complete_switch')->where('is_store', 0)->value('value')];
});
echo json_encode($result), "\n";
