<?php
/** Disposable database transaction only: card read uses V3 quantity/state and
 * cannot mutate physical rights, cross a member boundary or retain cancelled use. */
Db::startTrans();
try {
    $ordinary = Db::name('store_order')->where('id', 5338)->find();
    unset($ordinary['id']);$ordinary['order_id']='LOCAL-CARD-USAGE-'.bin2hex(random_bytes(5));
    $ordinary['unique']=bin2hex(random_bytes(16));$ordinary['is_system_del']=0;$ordinary['store_id']=11;$ordinary['verify_code']=bin2hex(random_bytes(5));
    $oid=(int)Db::name('store_order')->insertGetId($ordinary);
    $base=Db::name('store_order_cart_info')->where('id',13967)->find();unset($base['id']);$base['oid']=$oid;$base['unique']=bin2hex(random_bytes(16));$base['write_surplus_times']=1;$base['write_times']=1;$base['write_start']=0;$base['write_end']=0;
    $id=(int)Db::name('store_order_cart_info')->insertGetId($base);
    $rows=static function()use($oid):array{return Db::name('store_order_cart_info')->where('oid',$oid)->where('cart_type',2)->select()->toArray();};
    $usage=static function()use($service,$rows):array{return $service->withCardBenefitsReservationUsage(421,$rows())[0];};
    $before=Db::name('store_order_cart_info')->where('id',$id)->find();
    if($usage()['reservation_usage']['available_quantity']!==1)throw new RuntimeException('Fresh right unavailable');
    $created=$booking->create(421,$oid,['cart_num'=>1,'cart_info_id'=>$id,'store_id'=>11,'reservation_time'=>date('Y-m-d',time()+9*86400),'reservation_start'=>'10:00','service_duration_minutes'=>30]);
    $rid=(int)$created['reservationId'];
    foreach(['PENDING_CONFIRMATION','UNSTARTED','IN_SERVICE']as$status){
        Db::name('cashier_v3_reservation')->where('id',$rid)->update(['status'=>$status]);$v=$usage();
        if($v['waiting_service']!==1||$v['reservation_usage']['occupied_quantity']!==1||$v['reservation_usage']['available_quantity']!==0||$v['reservation_usage']['disabled_reason']!=='已预约占用')throw new RuntimeException('Active V3 state missing: '.$status);
    }
    foreach(['CANCELLED','REJECTED','COMPLETED','NO_SHOW','VOIDED']as$status){
        Db::name('cashier_v3_reservation')->where('id',$rid)->update(['status'=>$status]);$v=$usage();
        if($v['waiting_service']!==0||$v['reservation_usage']['available_quantity']!==1)throw new RuntimeException('Inactive state occupied: '.$status);
    }
    Db::name('cashier_v3_reservation')->where('id',$rid)->update(['status'=>'PENDING_CONFIRMATION']);
    $cartService=app()->make(app\services\order\StoreOrderCartInfoServices::class);
    $apiRows=$cartService->getOrderCardRelatedCartInfo($oid,421);
    if($apiRows[0]['waiting_service']!==1||$apiRows[0]['reservation_usage']['available_quantity']!==0)throw new RuntimeException('Actual card API service still reads legacy occupancy');
    $denied=false;try{$cartService->getOrderCardRelatedCartInfo($oid,420);}catch(ValidateException$e){$denied=true;}if(!$denied)throw new RuntimeException('Foreign member read allowed');
    $denied=false;try{$service->withCardBenefitsReservationUsage(420,$rows());}catch(ValidateException$e){$denied=true;}if(!$denied)throw new RuntimeException('Foreign projected rows allowed');
    if(Db::name('store_order_cart_info')->where('id',$id)->find()!==$before)throw new RuntimeException('Card read mutated physical right');
    // Tenant, lifecycle and member boundaries must match the quota reader.
    foreach (['tenant','generation','member'] as $boundary) {
        if ($boundary === 'tenant') Db::name('cashier_v3_reservation_line')->where('reservation_id',$rid)->update(['tenant_id'=>'999']);
        if ($boundary === 'generation') Db::name('cashier_v3_reservation')->where('id',$rid)->update(['lifecycle_generation'=>'OLD']);
        if ($boundary === 'member') Db::name('cashier_v3_reservation')->where('id',$rid)->update(['member_id'=>420]);
        if ($usage()['waiting_service'] !== 0 || $usage()['reservation_usage']['available_quantity'] !== 1) throw new RuntimeException('Occupancy boundary crossed: '.$boundary);
        Db::name('cashier_v3_reservation_line')->where('reservation_id',$rid)->update(['tenant_id'=>'0']);
        Db::name('cashier_v3_reservation')->where('id',$rid)->update(['member_id'=>421,'lifecycle_generation'=>\app\services\cashier\v3\reservation\CashierV3ReservationLifecycleServices::GENERATION]);
    }
    Db::name('cashier_v3_reservation_line')->where('reservation_id',$rid)->update(['quantity'=>2]);
    if ($usage()['waiting_service'] !== 2) throw new RuntimeException('Counted rows instead of occupied quantity');
    Db::name('cashier_v3_reservation')->where('id',$rid)->update(['status'=>'CANCELLED']);
    Db::name('store_order_cart_info')->where('id',$id)->update(['write_end'=>time()-60]);
    if ($usage()['reservation_usage']['disabled_reason'] !== '权益已过期' || $usage()['reservation_usage']['available_quantity'] !== 0) throw new RuntimeException('Expired right became bookable');
    $plan=Db::query('EXPLAIN SELECT l.entitlement_source_detail_id, SUM(l.quantity) FROM eb_cashier_v3_reservation_line l JOIN eb_cashier_v3_reservation r ON r.id=l.reservation_id WHERE l.tenant_id=? AND l.entitlement_source_detail_id=? AND r.member_id=? AND r.lifecycle_generation=? AND r.status IN (?,?,?) GROUP BY l.entitlement_source_detail_id',['0',$id,421,\app\services\cashier\v3\reservation\CashierV3ReservationLifecycleServices::GENERATION,'PENDING_CONFIRMATION','UNSTARTED','IN_SERVICE']);
    echo json_encode(['cardOccupancy'=>'PASS','activeStates'=>3,'releasedStates'=>5,'actualBenefitsReader'=>true,'ownerDenied'=>true,'physicalRightsUnchanged'=>true,'explain'=>$plan],JSON_UNESCAPED_UNICODE),"\n";
} finally { Db::rollback(); }
