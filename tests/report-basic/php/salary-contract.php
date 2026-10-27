<?php
/** R51 无框架、无数据库契约：静态元数据、日期权限边界、白名单表达式解析。 */
require_once __DIR__.'/../../../后端代码/app/services/payroll/PayrollAlgorithmPolicy.php';
require_once __DIR__.'/../../../后端代码/app/services/report/BasicSalaryManualServices.php';
require_once __DIR__.'/../../../后端代码/app/services/report/BasicSalaryReportServices.php';
use app\services\report\BasicSalaryReportServices as Salary;
$checks = 0;
function verify($pass, string $label): void { global $checks; ++$checks; if (!$pass) throw new RuntimeException($label); }
function denied(callable $fn, string $label): void {
    try { $fn(); } catch (InvalidArgumentException | DomainException $e) { verify(true, $label); return; }
    throw new RuntimeException('Expected rejection: '.$label);
}
$never = new class { public function run($fn) { throw new RuntimeException('Must reject before beginning DB read'); } };
$salary = new Salary(new stdClass(), $never);
verify(Salary::codes() === ['basic_salary_realtime'], 'Only implemented code');
$fields = $salary->definitions(Salary::codes()[0]);
verify(count(array_unique(array_column($fields,'key'))) === count($fields), 'Unique field keys');
foreach ($fields as $field) {
    foreach (['key','label','type','defaultVisible','defaultQuick','width','fixed','money','summable'] as $key)
        verify(array_key_exists($key, $field), 'Field contract '.$key);
    verify(is_bool($field['money']) && is_bool($field['summable']) && $field['editable'] === isset(\app\services\report\BasicSalaryManualServices::FIELDS[$field['key']]), 'Typed metadata and manual-only editable fields');
}
// 来自已对照的007工资列；断言顺序而非只断言列集合，避免前端自行重排。
$visible=array_values(array_filter($fields,static function($field){return $field['defaultVisible'];}));
verify(array_column($visible,'label')===['日期','门店','职务','职级','姓名','门店现金业绩','门店现金提成','门店实际扣卡业绩','门店实际扣卡提成','门店耗卡业绩','门店耗卡提成','项目业绩','项目提点','项目提成','产品业绩','产品提点','产品提成','劳动业绩','劳动提点','劳动提成','绩效工资','指定客','好评数','餐补','加班','岗位补贴','其他调整','应发工资','考勤','医社保','公积金','个税','其他扣款','预支工资','实发工资','备注'],'007 column names and order');
verify(count(array_filter($fields,static function($field){return $field['editable'];}))===12,'Exactly twelve manual fields');
$rates=new ReflectionMethod(Salary::class,'displayRates');
$rateRow=['xiao_money'=>100,'product_money'=>null,'work_get'=>0,'rule_details'=>[
    ['field'=>'xiao_money','rate_percent'=>30],['field'=>'xiao_money','rate_percent'=>25],['field'=>'xiao_money','rate_percent'=>30],['field'=>'work_get','rate_percent'=>0]]];
$rates->invokeArgs($salary,[&$rateRow]);
verify($rateRow['xiao_commission']==='25% / 30%' && $rateRow['product_commission']===null && $rateRow['work_commission']==='0%','Actual rates are distinct, stable, never averaged, missing remains null');
denied(function () use ($salary) { $salary->definitions('bad'); }, 'Unknown code fails without DB');
$input = ['start_date'=>'2026-10-01','end_date'=>'2026-10-31','as_of_date'=>'2026-10-23','_report_scope'=>['mode'=>'stores']];
foreach ([[],[0],[-1],['1']] as $stores) denied(function () use ($salary,$input,$stores) { $salary->read('basic_salary_realtime',$stores,$input); }, 'Invalid trusted stores');
foreach ([['start_date'=>'2026-10-02'],['end_date'=>'2026-11-01'],['as_of_date'=>'2026-02-30'],
    ['as_of_date'=>'2026-09-30'],['_report_scope'=>[]],['_report_scope'=>['mode'=>'none']],
    ['_report_scope'=>['mode'=>'self_participant','employee_id'=>0]]] as $change)
    denied(function () use ($salary,$input,$change) { $salary->read('basic_salary_realtime',[1],array_replace($input,$change)); }, 'Reject invalid month/scope before read');
$formula = new ReflectionMethod(Salary::class,'formula');
if (PHP_VERSION_ID < 80100) $formula->setAccessible(true);
$values = ['a'=>'12.34','b'=>'0.01','long_a'=>'2','zero'=>'0'];
$resolve = function ($key) use ($values) { if (!isset($values[$key])) throw new DomainException('Unknown variable'); return $values[$key]; };
$calc = function ($expression) use ($formula,$resolve) { return $formula->invoke(null,$expression,$resolve); };
verify(bccomp($calc('a+b*2'), '12.36', 12) === 0, 'Decimal arithmetic and precedence');
verify(bccomp($calc('(a+b)*2-long_a'), '22.70', 12) === 0, 'Parentheses and exact variable names');
verify(bccomp($calc('8/2/2'), '2', 12) === 0, 'Left associative division');
verify(bccomp($calc('-a + +b'), '-12.33', 12) === 0, 'Unary operators');
foreach (['','system(a)','a;1','a[0]','a.b','a**2','a%2','a/zero','missing+1','a b','(a','a)',str_repeat('(',33).'a'.str_repeat(')',33),str_repeat('a+',140).'a'] as $bad)
    denied(function () use ($calc,$bad) { $calc($bad); }, 'Reject unsafe/invalid formula');
$round = new ReflectionMethod(Salary::class,'roundDecimal');
if (PHP_VERSION_ID < 80100) $round->setAccessible(true);
verify($round->invoke(null,'1.005',2) === '1.01', 'Positive cent remainder');
verify($round->invoke(null,'-1.005',2) === '-1.01', 'Negative cent remainder');
$allocate = new ReflectionMethod(Salary::class,'allocatePersonalCents');
if (PHP_VERSION_ID < 80100) $allocate->setAccessible(true);
foreach ([-10007,-1001,-2,-1,0,1,2,1001,10007] as $amount) {
    $parts = $allocate->invoke(null,$amount,['3335','6672','0']);
    verify(array_sum($parts) === $amount, 'All signed personal cents conserved');
    verify($parts[0] === (int)bcdiv(bcmul((string)$amount,'3335',0),'10007',0), 'Stable proportional base');
    verify($parts[1] === $amount-$parts[0] && $parts[2] === 0, 'Remainder only belongs to last positive-weight component');
}
denied(function () use ($allocate) { $allocate->invoke(null,100,['0','0']); }, 'No artificial equal weights for unproven component pool');
denied(function () use ($allocate) { $allocate->invoke(null,100,['-1','1']); }, 'Negative component weight rejected');
$budget = new ReflectionMethod(Salary::class,'consumeFirstCourseSourceBudget');
if (PHP_VERSION_ID < 80100) $budget->setAccessible(true);
$bounded = new Salary(new stdClass(), $never);
$budget->invoke($bounded,'candidates',10000);
verify(true, 'Exactly 10000 candidate source rows allowed');
denied(function () use ($budget,$bounded) { $budget->invoke($bounded,'candidates',1); }, '10001 candidates rejected rather than truncated');
$bounded = new Salary(new stdClass(), $never);
$budget->invoke($bounded,'history',6000);
$budget->invoke($bounded,'history',4000);
verify(true, 'History batches accumulate to exactly 10000');
denied(function () use ($budget,$bounded) { $budget->invoke($bounded,'history',1); }, 'Cumulative history overflow rejected even when each batch is bounded');
$sources = new ReflectionMethod(Salary::class,'sources');
if (PHP_VERSION_ID < 80100) $sources->setAccessible(true);
$newCustomerSource = $sources->invoke($salary,$fields)['xinkeshu'];
verify(strpos($newCustomerSource,'普通退款') !== false && strpos($newCustomerSource,'不取消新客或原人员归属') !== false,
    'New customer field source states confirmed refund and attribution retention');
verify(strpos($newCustomerSource,'作废不计') !== false, 'New customer field source retains void exclusion');
echo json_encode(['pass'=>true,'checks'=>$checks,'database_access'=>false]),PHP_EOL;
