<?php
declare(strict_types=1);

// Local mirror only, with a database-enforced read-only transaction. Never dispatch a refund.
$backend = getenv('CASHIER_BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\order\CashierV3RefundSalespersonServices;
use think\facade\Db;

$app = new think\App($backend . '/');
$app->initialize();
if (!is_file('/.dockerenv') || config('database.connections.mysql.hostname') !== 'mysql') {
    throw new RuntimeException('Only the local Docker mirror is allowed');
}
Db::execute('SET SESSION TRANSACTION READ ONLY');
Db::startTrans();
try {
    $service = new CashierV3RefundSalespersonServices();
    $normalize = new ReflectionMethod($service, 'normalize');
    $normalize->setAccessible(true);
    $rows = Db::name('cashier_v3_performance_fact')->where('performance_type','sales_performance_allocated')
        ->where('fact_direction','forward')->where('status','effective')->where('allocation_weight_numerator','>',100)
        ->order('id desc')->limit(200)->select()->toArray();
    // Include independent-position orders, not only the most recent ordinary-personnel rows.
    $independentRows = Db::name('cashier_v3_performance_fact')->alias('f')
        ->join('system_store_staff s','s.employee_id=f.employee_id AND s.store_id=f.store_id')
        ->join('staff_job_position j','j.staff_id=s.id')
        ->join('position p','p.id=j.position_id')
        ->where('j.status',1)->where('j.is_del',0)->where('j.end_time',0)
        ->where('p.status',1)->where('p.performance_independent',1)
        ->where('s.status',1)->where('s.is_del',0)->where('s.cashier_salesperson_enabled',1)
        ->where('f.performance_type','sales_performance_allocated')->where('f.fact_direction','forward')
        ->where('f.status','effective')->order('f.id desc')->limit(30)->field('f.*')->select()->toArray();
    $rows = array_merge($independentRows, $rows);
    $seen = []; $results = []; $cardChecks = 0; $validated = 0;
    foreach ($rows as $fact) {
        $key = $fact['order_id'] . '|' . $fact['source_line_id'];
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $line = Db::name('cashier_v3_sales_order_line')->where('tenant_id',$fact['tenant_id'])
            ->where('order_id',$fact['order_id'])->where('order_line_id',$fact['source_line_id'])->find();
        if (!$line || (int)$line['member_id'] <= 0) continue;
        $staff = Db::name('system_store_staff')->alias('s')->join('organization_store os','os.store_id=s.store_id')
            ->where('s.store_id',(int)$fact['store_id'])->where('s.status',1)->where('s.is_del',0)
            ->where('s.level',0)->field('s.*,os.org_id')->find();
        if (!$staff) continue;
        $store = (int)$fact['store_id']; $tenant = (string)$fact['tenant_id'];
        $operator = new CashierV3OperatorScope($store,(int)$staff['id'],(string)$staff['org_id'],$tenant);
        $scope = new CashierV3DataScopeContext((int)$staff['id'],(int)$staff['id'],$store,$tenant,
            (string)$staff['org_id'],[$store],CashierV3DataScopeContext::MODE_STORES,[],false,
            '', 'r90-readonly', ['cashier.v3.order_center','cashier.v3.order.refund'], $staff);
        $original = Db::name('cashier_v3_performance_fact')->where('tenant_id',$tenant)
            ->where('order_id',$fact['order_id'])->order('id')->select()->toArray();
        $entry = $service->selection(['mode'=>'document','memberId'=>(int)$line['member_id'],
            'lineId'=>$line['order_line_id']],$operator,$scope);
        $people = $entry['currentSalespeople'];
        if (!$people) continue;
        $groups = []; $localPositive = true;
        foreach ($people as $person) {
            $weight = $person['allocationWeight'];
            if (!is_int($weight) || $weight < 0 || $weight > 100) throw new RuntimeException('Invalid percentage');
            $groups[$person['allocationGroupKey']] = ($groups[$person['allocationGroupKey']] ?? 0) + $weight;
            if ($weight === 0 || ($person['personnelSource'] ?? '') === 'other') $localPositive = false;
        }
        foreach ($groups as $total) if (!in_array($total,[0,100],true)) throw new RuntimeException('Group total drift');
        if ($localPositive) { $normalize->invoke($service,$people,$operator); $validated++; }
        $receipt = Db::name('cashier_v3_card_purchase_receipt')->where('tenant_id',$tenant)
            ->where('sales_order_id',$fact['order_id'])->where('sales_order_line_id',$line['order_line_id'])
            ->where('status','completed')->find();
        if ($receipt) {
            $card = $service->selection(['mode'=>'card','memberId'=>(int)$line['member_id'],
                'holderId'=>(int)$receipt['card_holder_id']],$operator,$scope);
            if ($card['currentSalespeople'] !== $people) throw new RuntimeException('Card/document defaults differ');
            $cardChecks++;
        }
        $after = Db::name('cashier_v3_performance_fact')->where('tenant_id',$tenant)
            ->where('order_id',$fact['order_id'])->order('id')->select()->toArray();
        if ($original !== $after) throw new RuntimeException('Historical facts changed');
        $results[] = ['store'=>$store,'groups'=>$groups,'weights'=>array_column($people,'allocationWeight')];
        if (count($results) >= 10) break;
    }
    if (!$results || !$validated) throw new RuntimeException('No usable local fixtures');
    echo json_encode(['status'=>'PASS','documentChecks'=>count($results),'cardChecks'=>$cardChecks,
        'existingValidatorChecks'=>$validated,'historicalFactsUnchanged'=>true,'refundsExecuted'=>0,
        'results'=>$results],JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),PHP_EOL;
} finally { Db::rollback(); }
