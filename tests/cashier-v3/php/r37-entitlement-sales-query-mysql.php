<?php
/** R37 本地只读对账：服务按结账分组、混合单去重、分页和金额保持一致。 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\order\CashierV3SalesOrderQueryServices;
use think\facade\Db;
$app = new \think\App($backend . '/'); $app->initialize();
$sf = Db::name('cashier_v3_entitlement_service_fact')->where('service_status','completed')->order('id','desc')->find();
if (!$sf) throw new RuntimeException('missing service fixture');
$tenant = (string)$sf['tenant_id']; $store = (int)$sf['store_id']; $date = (string)$sf['business_date'];
$scope = new CashierV3DataScopeContext(71,701,$store,$tenant,'organization:test',[$store],CashierV3DataScopeContext::MODE_ALL,[],true,'test-super','permission-v2',['cashier.v3.order_center','cashier.v3.order.service_detail'],[]);
$operator = new CashierV3OperatorScope($store,71,'organization:test',$tenant);
$reader = new CashierV3SalesOrderQueryServices();
$payload = ['page'=>1,'pageSize'=>100,'dateFrom'=>$date,'dateTo'=>$date,'status'=>'','dataScope'=>'all'];
$full = $reader->querySalesOrders($payload,$operator,$scope);
$ids=[]; $pure=0;
foreach ($full['records'] as $record) {
    if (isset($ids[$record['id']])) throw new RuntimeException('duplicate group');
    $ids[$record['id']]=true;
    if (empty($record['entitlementOnly'])) continue;
    $pure++;
    // 来源必须来自这次结账的冻结选择，验证列表和详情都不再丢失；只读不回填。
    $selectedSource = Db::name('cashier_v3_checkout_business_source_selection')
        ->where('tenant_id', $tenant)->where('store_id', $store)->where('checkout_kind', 'sale')
        ->where('checkout_request_id', substr($record['id'], strlen('service:')))->find();
    if ($selectedSource && $selectedSource['primary_source_name_snapshot'] !== '') {
        $expectedSource = $selectedSource['secondary_source_name_snapshot'] ?: $selectedSource['primary_source_name_snapshot'];
        if ($record['source'] !== $expectedSource) throw new RuntimeException('pure entitlement source missing in list');
        $sourceDetail = $reader->salesOrderDetail(['orderId'=>$record['id']], $operator, $scope);
        if (($sourceDetail['source'] ?? '') !== $expectedSource) throw new RuntimeException('pure entitlement source missing in detail');
    }
    if ($record['availableActions'] !== [] || $record['lifecycleOrderId'] !== '' || (float)$record['actualReceivedAmount'] !== 0.0) throw new RuntimeException('service group leaked sales authority');
    foreach ($record['items'] as $item) {
        if ($item['businessTag'] !== '权益' || $item['payableAmount'] !== null || $item['serviceFactId'] <= 0) throw new RuntimeException('invalid entitlement item');
        foreach ($item['craftsmenListAllocations'] as $person) if (!is_bool($person['isPointCustomer'])) throw new RuntimeException('missing point/round readback');
    }
}
if (!$pure) throw new RuntimeException('pure service missing');
$paged=[]; $cursor='';
for ($page=1;$page<100;$page++) {
    $result=$reader->querySalesOrders(array_merge($payload,['page'=>$page,'pageSize'=>2,'queryCursor'=>$cursor]),$operator,$scope);
    foreach ($result['records'] as $row) $paged[]=$row['id'];
    $cursor=$result['paginationCursor']['next'] ?? '';
    if (!$cursor) break;
}
if ($paged !== array_keys($ids)) throw new RuntimeException('pagination skips or duplicates documents');
$normal=$reader->querySalesOrders(array_merge($payload,['status'=>'normal']),$operator,$scope);
foreach($normal['records'] as $row) if($row['orderStatus']==='已作废') throw new RuntimeException('void leaked into normal');
$denied=$reader->querySalesOrders(array_merge($payload,['storeIds'=>[$store+100000]]),$operator,$scope);
if($denied['records']!==[]) throw new RuntimeException('store scope broadened');
// 同组非首条服务号也应精确检索到整组，不能只查询组首编号。
$byNumber=$reader->querySalesOrders(array_merge($payload,['keyword'=>$sf['service_record_no']]),$operator,$scope);
if(count($byNumber['records'])!==1) throw new RuntimeException('service number search failed');
echo 'R37 read-only query PASS; groups=' . count($ids) . '; pure=' . $pure . '; pagination=PASS' . PHP_EOL;
// 输出实际查询计划供验收评估；仅 EXPLAIN，不改写业务记录。
$criteriaMethod = new ReflectionMethod($reader, 'listCriteria'); $criteriaMethod->setAccessible(true);
$queryMethod = new ReflectionMethod($reader, 'authorityOrderBaseQuery'); $queryMethod->setAccessible(true);
$criteria = $criteriaMethod->invoke($reader, $payload, $operator, $scope);
$sql = $queryMethod->invoke($reader, $criteria)->limit(20)->buildSql(false);
echo json_encode(Db::query('EXPLAIN ' . $sql), JSON_UNESCAPED_UNICODE) . PHP_EOL;
