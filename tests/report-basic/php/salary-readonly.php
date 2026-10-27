<?php
/** 本地现有数据只读探测：仅输出数量和缺口类型，不导出员工姓名或个人工资。 */
$root = getenv('BACKEND_ROOT') ?: getcwd();
require $root.'/vendor/autoload.php';
(new \think\App())->initialize();
use think\facade\Db;
use app\services\report\BasicSalaryReportServices as Salary;
if (!in_array(config('database.connections.mysql.hostname'), ['mohe-mysql','mysql'], true)) throw new RuntimeException('Local-only salary probe');
set_exception_handler(static function ($e) { file_put_contents('php://stderr',(string)$e.PHP_EOL); exit(1); });
$person = Db::name('system_store_staff')->where('status',1)->where('is_del',0)->where('employee_id','>',0)->order('id')->find();
if (!$person) { echo json_encode(['available'=>false,'gap'=>'NO_STABLE_EMPLOYEE_IDENTITY']),PHP_EOL; exit; }
$today = (new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
$input = ['start_date'=>substr($today,0,7).'-01','end_date'=>$today,'as_of_date'=>$today,
    '_report_scope'=>['mode'=>'self_participant','employee_id'=>(int)$person['employee_id']]];
$result = (new Salary())->read('basic_salary_realtime',[(int)$person['store_id']],$input);
$gaps = [];
foreach ($result['gaps'] as $gap) $gaps[$gap['code']] = ($gaps[$gap['code']] ?? 0) + 1;
echo json_encode(['local_readonly'=>true,'row_count'=>count($result['rows']),'complete'=>$result['complete'],
    'gap_counts'=>$gaps,'summable_field_count'=>count($result['sum_fields']), 'range'=>$result['date_range'],
    'business_writes'=>false,'remote_access'=>false],JSON_UNESCAPED_UNICODE),PHP_EOL;
