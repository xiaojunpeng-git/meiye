<?php
declare(strict_types=1);
namespace app\services\product\inventory;
use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;
final class InventoryStockCountQueryServices {
    public function list(int $storeId, int $operatorId, string $keyword, int $page=1, int $limit=20): array {
        $staff=Db::name('system_store_staff')->where('id',$operatorId)->where('store_id',$storeId)->where('status',1)->where('is_del',0)->find(); if(!$staff)throw new \RuntimeException('inventory_stock_count_query_scope_denied');
        $location=Db::name('inventory_location')->where('tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)->where('store_id',$storeId)->where('location_type','STORE')->where('is_default',1)->where('location_status','ACTIVE')->find(); if(!$location)throw new \RuntimeException('inventory_stock_count_query_location_missing');
        $query=Db::name('inventory_stock_count_document')->alias('d')->leftJoin('inventory_stock_count_line l','l.document_id=d.id')->where('d.tenant_id',CashierV3ScopeResolver::TENANT_SCOPE_ID)->where('d.location_id',(int)$location['id']); $keyword=trim($keyword); if($keyword!=='')$query->whereLike('d.count_no|d.remark','%'.$keyword.'%');
        $count=(clone $query)->group('d.id')->count(); $rows=$query->field('d.id,d.count_no order_sn,d.document_status status_name,d.business_date,d.recorded_at add_time,d.operator_id admin_id,COUNT(l.id) detail_count,SUM(ABS(l.difference_quantity_units)) difference_quantity')->group('d.id')->order('d.id desc')->page(max(1,$page),max(1,min(100,$limit)))->select()->toArray();
        return ['count'=>$count,'list'=>$rows];
    }
}
