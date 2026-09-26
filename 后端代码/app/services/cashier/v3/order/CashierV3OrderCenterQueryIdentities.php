<?php
namespace app\services\cashier\v3\order;

use think\facade\Db;

/**
 * 为已经过 DataScope 裁剪的记录补只读筛选身份。批量按来源主键关联，禁止按名称猜人，
 * 不更新业务记录、不重算金额，也不改变原详情和人员调整所使用的数据结构。
 */
final class CashierV3OrderCenterQueryIdentities
{
    public function attach(string $type, array $rows, string $tenant): array
    {
        if (!$rows) return [];
        $sources = [
            'recharge:' => ['user_recharge','id','store_id','staff_id'],
            'debt:' => ['store_debt','id','store_id',null],
            'refund:' => ['cashier_v3_order_lifecycle_operation','operation_id','store_id','operator_id'],
            'service:' => ['cashier_v3_entitlement_service_fact','id','store_id','operator_id'],
            'v3-sales-supplement:' => ['cashier_v3_debt_repayment','repayment_id','store_id','operator_id'],
            'v3-supplement:' => ['cashier_v3_recharge_debt_repayment','repayment_id','store_id','operator_id'],
            'legacy-supplement:' => ['store_debt_repay','id','pay_store_id','staff_id'],
            'v3-gift:' => ['cashier_v3_gift_fact','gift_fact_id','store_id','operator_id'],
            'v3-direct-gift:' => ['cashier_v3_gift_fact','gift_fact_id','store_id','operator_id'],
            'card-operation:' => ['cashier_v3_card_operation','id','store_id','operator_id'],
            'legacy-replacement:' => ['card_project_replacement','id','store_id','staff_id'],
            'legacy-card-upgrade:' => ['store_order','id','store_id','staff_id'],
        ];
        $metadata = [];
        foreach ($sources as $prefix => [$table,$key,$store,$operator]) {
            if ($type === 'sales') continue;
            $ids = [];
            foreach ($rows as $row) if (strpos((string)$row['id'], $prefix) === 0) $ids[] = substr($row['id'], strlen($prefix));
            if (!$ids) continue;
            $query = Db::name($table)->whereIn($key, $ids);
            if (strpos($table, 'cashier_v3_') === 0) $query->where('tenant_id', $tenant);
            foreach ($query->field($key . ' AS source_key,' . $store . ' AS store_id,' . ($operator ?: '0') . ' AS operator_id')->select()->toArray() as $record) {
                $metadata[$prefix . $record['source_key']] = $record;
            }
        }
        if ($type === 'sales') {
            $ids = array_column($rows, 'id');
            foreach (Db::name('cashier_v3_sales_order')->where('tenant_id', $tenant)->whereIn('order_id',$ids)
                ->field('order_id AS source_key,store_id,operator_id,supplement_enabled')->select()->toArray() as $record) $metadata[$record['source_key']]=$record;
            $requests = [];
            foreach ($ids as $id) if (strpos($id,'service:')===0) $requests[]=substr($id,8);
            if ($requests) foreach (Db::name('cashier_v3_entitlement_service_fact')->where('tenant_id',$tenant)->whereIn('checkout_request_id',$requests)
                ->field('checkout_request_id AS source_key,store_id,operator_id')->select()->toArray() as $record) $metadata['service:'.$record['source_key']]=$record;
        }
        if ($type === 'gift') {
            $ids=[];
            foreach ($rows as $row) if (strpos($row['id'],'gift:')===0) $ids[]=substr($row['id'],5);
            if ($ids) foreach(Db::name('store_order_cart_info')->alias('ci')->join('store_order o','o.id=ci.oid')->whereIn('ci.id',$ids)
                ->field('ci.id AS source_key,o.store_id,o.staff_id AS operator_id')->select()->toArray() as $record) $metadata['gift:'.$record['source_key']]=$record;
        }
        $employeeIds=[];
        $staffByEmployee=[];
        $salesIds = $this->salesIds($type,$rows,$tenant);
        // 订单未冻结手机号；此联系字段按会员 ID 读取当前电话，不能反向替换历史姓名快照。
        $phones = $type === 'sales' ? Db::name('user')->whereIn('uid', array_column($rows,'memberId'))->column('phone','uid') : [];
        foreach ($rows as &$row) {
            $meta=$metadata[$row['id']]??[];
            if ($type === 'sales') {
                $row['phone'] = (string)($phones[$row['memberId'] ?? 0] ?? '');
                $row['supplement'] = !empty($meta['supplement_enabled']) ? '补单' : '正常办理';
            }
            $row['store_query_ids'] = $this->ids([$meta['store_id'] ?? $row['storeId'] ?? 0]);
            $row['operator_query_ids'] = $this->ids([$meta['operator_id'] ?? 0]);
            $row['cashier_query_ids'] = $row['operator_query_ids'];
            $row['void_operator_query_ids'] = [];
            $people=[];
            foreach ($row['items'] ?? [] as $item) foreach ($item['salespeople'] ?? [] as $person) $people[]=$person['employeeId']??0;
            $row['salesperson_query_ids']=$this->ids(array_merge($people,$salesIds[$row['id']]??[]));
            $row['craftsman_query_ids']=$this->ids(array_column($row['craftsmenListAllocations']??[], 'employeeId'));
            $row['sales_manager_query_ids']=[];
            $row['guide_query_ids']=[];
            // 消费单的人员位于各商品/权益明细；服务记录仍沿用记录级人员，不能只读主单漏掉手艺人。
            foreach ($row['items'] ?? [] as $item) {
                foreach (['craftsman'=>'craftsmenListAllocations','sales_manager'=>'salesManagers','guide'=>'guides'] as $key=>$source) {
                    $people=$item[$source]??($key==='craftsman'?($item['craftsmen']??[]):[]);
                    $row[$key.'_query_ids']=array_merge($row[$key.'_query_ids'],array_column($people,'employeeId'));
                }
            }
            foreach (['salesperson','craftsman','sales_manager','guide'] as $key) $employeeIds=array_merge($employeeIds,$row[$key.'_query_ids']);
        }
        unset($row);
        // 选择器 ID 是门店任职 ID，事实 ID 是 employee ID；显式关系转换，不靠两者数字巧合。
        $employeeIds=$this->ids($employeeIds);
        if ($employeeIds) foreach(Db::name('system_store_staff')->whereIn('employee_id',$employeeIds)->field('id,employee_id')->select()->toArray() as $staff) {
            $staffByEmployee[(string)$staff['employee_id']][]=(string)$staff['id'];
        }
        foreach($rows as &$row) foreach(['salesperson_query_ids','craftsman_query_ids','sales_manager_query_ids','guide_query_ids'] as $field) {
            $ids=[];
            foreach($row[$field] as $employee) $ids=array_merge($ids,$staffByEmployee[$employee]??[]);
            $row[$field]=$this->ids($ids);
        }
        unset($row);
        if ($type==='service') {
            $ids=array_column($rows,'serviceFactId');
            $voids=Db::name('cashier_v3_service_record_void_operation')->where('tenant_id',$tenant)->whereIn('service_fact_id',$ids)->where('status','succeeded')->column('operator_id','service_fact_id');
            foreach($rows as &$row) $row['void_operator_query_ids']=$this->ids([$voids[$row['serviceFactId']]??0]);
            unset($row);
        }
        return $rows;
    }

    /** 充值/补交名单与原展示所用的事实类型、冲销口径一致。 */
    private function salesIds(string $type, array $rows, string $tenant): array
    {
        if (!in_array($type,['recharge','supplement'],true)) return [];
        $sources=$type==='recharge' ? ['recharge:'=>['recharge','RCH:']] : [
            'v3-sales-supplement:'=>['debt_repayment',''], 'v3-supplement:'=>['recharge_debt_repayment','']];
        $result=[];
        foreach($sources as $prefix=>[$document,$factPrefix]) {
            $ids=[];$keys=[];
            foreach($rows as $row) if(strpos($row['id'],$prefix)===0){$id=$factPrefix.substr($row['id'],strlen($prefix));$ids[]=$id;$keys[$id]=$row['id'];}
            if(!$ids)continue;
            $query=Db::name('cashier_v3_performance_fact')->alias('pf')->where('pf.tenant_id',$tenant)
                ->where('pf.source_document_type',$document)->whereIn('pf.order_id',$ids)
                ->where('pf.performance_type','sales_performance_allocated')->where('pf.fact_direction','forward')->where('pf.status','effective');
            if($type==='supplement')$query->whereNotExists(function($q){$q->name('cashier_v3_performance_fact')->alias('rev')->whereRaw('rev.tenant_id=pf.tenant_id AND rev.reversal_of=pf.fact_id')->where('rev.fact_direction','reversal');});
            foreach($query->field('pf.order_id,pf.employee_id')->select()->toArray() as $fact) $result[$keys[$fact['order_id']]][]=(string)$fact['employee_id'];
        }
        return $result;
    }

    private function ids(array $ids): array
    {
        return array_values(array_unique(array_map('strval',array_filter($ids,static fn($id)=>(int)$id>0))));
    }
}
