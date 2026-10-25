<?php
/** Local MySQL transaction fixtures only: prove same-master persistence, audit,
 * idempotency and platform/store concurrency without changing a real store.
 */
require '/var/www/html/vendor/autoload.php';(new \think\App('/var/www/html/'))->initialize();
set_exception_handler(static function(Throwable $e){file_put_contents('php://stderr',$e->getMessage().PHP_EOL);exit(1);});
use think\facade\Db;
use think\exception\ValidateException;
use app\services\store\StoreProfileSettingsServices;
$cfg=config('database.connections.mysql');if(!in_array($cfg['hostname'],['mysql','mohe-mysql'],true)||$cfg['database']!=='ruihao')throw new RuntimeException('Local only');
$checks=0;function r59check($value,$label){global $checks;if(!$value)throw new RuntimeException($label);$checks++;}
$service=new StoreProfileSettingsServices();$store=2147000059;
if(Db::name('system_store')->where('id',$store)->count())throw new RuntimeException('Fixture collision');
Db::startTrans();try{
 $row=Db::name('system_store')->where('is_del',0)->order('id')->find();if(!$row)throw new RuntimeException('Store fixture source missing');
 $row['id']=$store;$row['name']='R59测试门店';$row['phone']='010-12345678';$row['image']='/uploads/r59.png';$row['background_image']='/uploads/r59-head.png';$row['introduction']='R59';$row['detailed_address']='R59测试地址1号';$row['is_show']=1;$row['is_del']=0;$row['longitude']='117.227200';$row['latitude']='31.820600';$row['day_time']='10:00:00 - 21:00:00';$row['day_start']='10:00:00';$row['day_end']='21:00:00';
 // Use a valid path from the canonical city dictionary, not invented name IDs.
 $ids=[];$parent=0;for($i=0;$i<3;$i++){$city=Db::name('city_area')->where('parent_id',$parent)->order('id')->find();if(!$city)break;$ids[]=(int)$city['id'];$parent=(int)$city['id'];}
 if(count($ids)<2)throw new RuntimeException('City fixture missing');foreach(['province','city','area','street']as $i=>$key)$row[$key]=$ids[$i]??0;
 Db::name('system_store')->insert($row);$before=$service->read($store);$input=$before['profile'];unset($input['address']);
 $input['name']='R59修改名称';$input['phone']='010-87654321';$token='R59_'.bin2hex(random_bytes(12));
 $saved=$service->save($store,['id'=>59,'name'=>'R59测试'],$input,$before['expected_version'],$token);
 r59check($saved['profile']['name']==='R59修改名称'&&$saved['profile']['phone']==='010-87654321','Two fields saved');
 r59check($service->read($store)===$saved,'Reload preserves database values');
 r59check(Db::name('system_store')->where('id',$store)->value('name')==='R59修改名称','Platform master reads same name');
 r59check($saved['expected_version']!==$before['expected_version'],'Fingerprint changes');
 r59check($service->save($store,['id'=>59],$input,$before['expected_version'],$token)===$saved,'Idempotent first result');
 r59check(Db::name('cashier_v3_store_profile_audit')->where('store_id',$store)->count()===1,'One audit only');
 $bad=$input;$bad['name']='冲突修改';try{$service->save($store,[],$bad,$before['expected_version'],$token);throw new RuntimeException('Mismatched replay accepted');}catch(ValidateException $e){$checks++;}
 try{$service->save($store,[],$input,$before['expected_version'],$token.'x');throw new RuntimeException('Stale store form accepted');}catch(ValidateException $e){$checks++;}
 $platform=app()->make(\app\services\store\SystemStoreServices::class);
 try{$platform->saveStore($store,['expected_store_profile_version'=>$before['expected_version']]);throw new RuntimeException('Stale platform form accepted');}catch(\mohe\exceptions\AdminException $e){r59check(strpos($e->getMessage(),'其他人修改')!==false,'Platform stale form rejected');}
 $current=$service->read($store);Db::name('system_store')->where('id',$store)->update(['name'=>'R59平台修改']);
 try{$service->save($store,[],$input,$current['expected_version'],$token.'y');throw new RuntimeException('Platform edit overwritten');}catch(ValidateException $e){$checks++;}
 foreach([['space_num'=>1.1],['space_num'=>-1],['is_show'=>2],['organization_id'=>1],['store_id'=>1],['delivery_type'=>[1]],['phone'=>str_repeat('1',26)],['day_time'=>['25:00','26:00']],['latitude'=>91],['address_ids'=>array_reverse($ids)]]as $change){try{$service->normalize(array_replace($input,$change));throw new RuntimeException('Invalid field accepted '.json_encode($change));}catch(ValidateException $e){$checks++;}}
 $clear=$input;$clear['introduction']='';$current=$service->read($store);$after=$service->save($store,[],$clear,$current['expected_version'],$token.'z');r59check($after['profile']['introduction']==='','Explicit empty introduction survives');
 $raw=Db::name('system_store')->where('id',$store)->find();foreach(['type','region_id','delivery_type','store_rate_type','store_cashier_order_rate','product_status']as $field)r59check((string)$raw[$field]===(string)$row[$field],'Unrelated settings preserved '.$field);
 try{$service->read($store-1);throw new RuntimeException('Missing store accepted');}catch(ValidateException $e){$checks++;}
 r59check(Db::name('organization_store')->where('store_id',$store)->count()===0,'Organization never changed');
}finally{Db::rollback();}
echo 'R59_STORE_PROFILE_PASS checks='.$checks.PHP_EOL;
