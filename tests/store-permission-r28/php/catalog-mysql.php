<?php
/** 本地真实库权限目录测试，元数据与测试岗位修改均置于回滚事务，禁止连接客户实例。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use app\services\organization\StorePermissionCatalogServices as Catalog;
use app\services\organization\JobPositionPolicyServices as Positions;
use app\services\cashier\v3\permission\CashierV3PermissionPolicyRegistry as Policy;
use app\services\cashier\v3\CashierV3DataScopeContext as Scope;
use app\services\cashier\v3\manifest\CashierV3ActionManifest as Manifest;
use think\facade\Db;
$c=config('database.connections.mysql');
if ($c['hostname']!=='mysql'||$c['database']!=='ruihao') throw new RuntimeException('LOCAL_DATABASE_REQUIRED');
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString());exit(1);});
function check($ok,$message) { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
function rejects($fn,$message) { try{$fn();}catch(Throwable $e){check(true,$message);return;}throw new RuntimeException($message.' accepted'); }
$catalog=new Catalog();
$before=$catalog->snapshot();
$beforeChannels=Db::name('job_position_channel_rule')->whereIn('channel',['platform','mobile'])->order('id')->select()->toArray();
Db::startTrans();
try {
    check(count($before['rules'])===count(Catalog::definitions())+count(Catalog::navigationGroups())+count(Catalog::navigationItems()),'complete stable code registry');
    // 旧目录补充子入口仅修改展示配置：缺项补齐、重复不覆盖编辑、岗位原行保持一致。
    $allChannels=Db::name('job_position_channel_rule')->order('id')->select()->toArray();
    $withoutNavigation=array_values(array_filter($before['rules'],static function($r){return empty($r['navigation_key']);}));
    Db::name(Catalog::TABLE)->where('id',1)->update(['rules_json'=>json_encode($withoutNavigation,JSON_UNESCAPED_UNICODE)]);
    $catalog->initializeNavigationItems();$withNavigation=$catalog->snapshot();
    check(count($withNavigation['rules'])===count($before['rules']),'navigation upgrade fills registered submenus');
    $catalog->initializeNavigationItems();check($catalog->snapshot()===$withNavigation,'navigation upgrade repeat preserves metadata and revision');
    check(Db::name('job_position_channel_rule')->order('id')->select()->toArray()===$allChannels,'navigation upgrade leaves every job channel row unchanged');
    // 恢复当前测试基线，后续保存仍对本次读到的修订进行并发检查。
    Db::name(Catalog::TABLE)->where('id',1)->update(['rules_json'=>json_encode($before['rules'],JSON_UNESCAPED_UNICODE),'revision'=>$before['revision']]);
    check(Positions::storeV3FeaturesFromRuleIds([301005])===['cashier.v3.member'],'member no longer grants care or fund');
    check(Positions::storeV3FeaturesFromRuleIds([301007])===['cashier.v3.management_center'],'management container never regrants unchecked children');
    foreach ([301008=>'cashier.v3.care',301009=>'cashier.v3.fund',301200=>'cashier.v3.data',301201=>'cashier.v3.targets'] as $id=>$f) check(Positions::storeV3FeaturesFromRuleIds([$id])===[$f],'independent '.$f);
    $staff=Positions::storeV3FeaturesFromRuleIds([301040]);
    check(in_array('cashier.v3.staff.create',$staff)&&in_array('cashier.v3.management.staff',$staff)&&!in_array('cashier.v3.management.rooms',$staff),'leaf inherits only business ancestors');
    $rows=$before['rules'];foreach($rows as &$row) if($row['id']===301009){$row['title']='测试费用';$row['sort']=7;$row['enabled']=0;}unset($row);
    $saved=$catalog->save(['revision'=>$before['revision'],'rules'=>$rows],1);
    check($saved['revision']===$before['revision']+1,'atomic metadata revision increments');
    check($catalog->snapshot()===$saved,'saved names sort and state survive reread');
    check(Positions::storeV3FeaturesFromRuleIds([301009])===[],'disabled fund denied on server despite persisted grant');
    check(Positions::storeV3FeaturesFromRuleIds([301005])===['cashier.v3.member'],'disable fund does not affect member');
    rejects(function()use($catalog,$before){$catalog->save($before,1);},'stale concurrent editor rejected');
    $audit=Db::name('store_v3_permission_audit')->where('action','UPDATE_CATALOG')->order('id desc')->find();
    check(json_decode($audit['after_json'],true)===$rows,'metadata audit carries exact before and after');
    $rows=$saved['rules'];foreach($rows as &$row) if($row['id']===301009)$row['enabled']=1;unset($row);
    $saved=$catalog->save(['revision'=>$saved['revision'],'rules'=>$rows],1);
    check(Positions::storeV3FeaturesFromRuleIds([301009])===['cashier.v3.fund'],'reenable restores original explicit grant');
    // 大类关闭必须由服务端裁剪所有显示后代，不依赖前端隐藏或改变岗位已存编号。
    $groupRows=$saved['rules']; foreach($groupRows as &$r)if($r['id']===-2804)$r['enabled']=0;unset($r);
    $disabled=$catalog->save(['revision'=>$saved['revision'],'rules'=>$groupRows],1);
    check(Positions::storeV3FeaturesFromRuleIds([301200,301201])===[],'disabled persisted data group denies report and target capabilities on server');
    check(in_array('cashier.v3.member',Positions::storeV3FeaturesFromRuleIds([301005])),'disabling data group preserves operation grants');
    $saved=$catalog->save(['revision'=>$disabled['revision'],'rules'=>$saved['rules']],1);
    $ungrouped=$saved['rules'];foreach($ungrouped as &$r)if($r['id']===301009)$r['parent_id']=0;unset($r);
    rejects(function()use($catalog,$saved,$ungrouped){$catalog->save(['revision'=>$saved['revision'],'rules'=>$ungrouped],1);},'orphan business entry rejected instead of silently disappearing');
    foreach (['cycle','duplicate','missing','fake','fraction'] as $case) {
        $bad=$saved['rules'];
        if($case==='cycle')$bad[0]['parent_id']=$bad[0]['id'];
        if($case==='duplicate')$bad[]=$bad[0];
        if($case==='missing')array_pop($bad);
        if($case==='fake')$bad[0]['id']=999999;
        if($case==='fraction')$bad[0]['sort']=0.5;
        rejects(function()use($catalog,$saved,$bad){$catalog->save(['revision'=>$saved['revision'],'rules'=>$bad],1);},'invalid '.$case.' rejected');
    }
    $scope=new Scope(1,1,1,'0','1',[1],'stores',[],false,'','test',['cashier.v3.member'],[]);
    $policy=new Policy();
    rejects(function()use($policy,$scope){$policy->assertAllowed('feature:cashier.v3.management.staff',$scope,[],'query-staff');},'member cannot call staff API');
    rejects(function()use($policy,$scope){$policy->assertAllowed('feature:cashier.v3.targets',$scope,[],'query-store-target-dashboard');},'member cannot call target API');
    check(Manifest::requireAction('query-staff')['permission']==='cashier.v3.management.staff','staff manifest bound to separate capability');
    check(Manifest::requireAction('query-store-target-dashboard')['permission']==='cashier.v3.targets','target manifest bound to separate capability');
    check(Db::name('job_position_channel_rule')->whereIn('channel',['platform','mobile'])->order('id')->select()->toArray()===$beforeChannels,'platform and mobile job grants unchanged');
    $source=json_decode(file_get_contents('/tmp/r28-permission-local-before.json'),true);
    $map=array_column(Catalog::definitions(),'id','feature_code');
    foreach($source['job_position_channel_rule'] as $row){
        if($row['channel']!=='store_v3')continue;
        $old=Positions::legacyStoreV3FeaturesFromRuleIds(array_filter(array_map('intval',explode(',',$row['rules']))));
        $new=Positions::storeV3FeaturesFromRuleIds(array_filter(array_map('intval',explode(',',(string)Db::name('job_position_channel_rule')->where('id',$row['id'])->value('rules')))));
        check(!array_diff($old,$new),'migration retains old effective permissions position '.$row['position_id']);
        if(in_array('cashier.v3.member',$old))check(in_array('cashier.v3.care',$new)&&in_array('cashier.v3.fund',$new),'old member grant retains care and fund');
        if(in_array('cashier.v3.management_center',$old))check(in_array('cashier.v3.targets',$new)&&in_array('cashier.v3.data',$new)&&in_array('cashier.v3.management.rooms',$new),'old management retains existing workspaces');
    }
    // 实际任职组合也必须保持旧有效操作，防止逐岗位迁移意外扩大多岗位员工授权。
    $sourceMap=[];foreach($source['job_position_channel_rule'] as $r)if($r['channel']==='store_v3'&&$r['status'])$sourceMap[$r['position_id']]=array_filter(array_map('intval',explode(',',$r['rules'])));
    $groups=[];
    foreach(Db::name('staff_job_position')->alias('j')->join('position p','p.id=j.position_id')->where('j.status',1)->where('j.is_del',0)->where('j.end_time',0)->where('p.status',1)->where('p.use_store',1)->field('j.staff_id,j.employee_id,j.position_id')->select()->toArray() as $j){
        $key=$j['staff_id'].':'.$j['employee_id'];$groups[$key]=array_merge($groups[$key]??[],$sourceMap[$j['position_id']]??[]);
    }
    $legacyCodes=array_values(Positions::storeV3FeatureMap(true));
    foreach($groups as $key=>$oldIds){
        $old=Positions::legacyStoreV3FeaturesFromRuleIds($oldIds);$newIds=[];
        // 按真实岗位逐个展开，不能把全部旧规则先合成一个伪岗位。
        foreach(Db::name('staff_job_position')->where('staff_id',(int)explode(':',$key)[0])->where('employee_id',(int)explode(':',$key)[1])->where('status',1)->where('is_del',0)->where('end_time',0)->column('position_id') as $pid)$newIds=array_merge($newIds,Catalog::migrateLegacyIds($sourceMap[$pid]??[]));
        $new=array_intersect(Catalog::explicitFeatures($newIds),$legacyCodes);
        check(!array_diff($old,$new)&&!array_diff($new,$old),'multi-position effective grant preserved '.$key);
    }
    // 在真实库外层回滚事务中恢复升级前规则，完整执行首次升级及重复升级。
    Db::name(Catalog::TABLE)->where('id',1)->delete();
    foreach($source['job_position_channel_rule'] as $row)if($row['channel']==='store_v3')Db::name('job_position_channel_rule')->where('id',$row['id'])->update($row);
    Db::name('database_upgrade_log')->where('upgrade_key','20261023-r28-store-permission-catalog')->delete();
    $sql='/var/www/html/database/upgrades/2026-10-23-门店端独立权限规则/02-正式升级.sql';
    $catalog->initializeLegacyCatalog($sql);
    Db::name('store_v3_permission_audit')->where('action','INITIALIZE_ORDER_NAVIGATION')->delete();
    $catalog->initializeNavigationGroups();
    $catalog->initializeOrderNavigation();
    $initialized=$catalog->snapshot();check($initialized['revision']===3,'fresh migration initializes complete catalog');
    foreach($source['job_position_channel_rule'] as $row)if($row['channel']==='store_v3'){
        $migrated=Db::name('job_position_channel_rule')->where('id',$row['id'])->find();
        check($migrated['rules']===implode(',',Catalog::migrateLegacyIds(array_filter(array_map('intval',explode(',',$row['rules'])))))&&(int)$migrated['version']===(int)$row['version']+1,'fresh migration expands exact rules and advances revision position '.$row['position_id']);
    }
    $count=Db::name('store_v3_permission_audit')->count();
    $catalog->initializeLegacyCatalog($sql);
    $catalog->initializeNavigationGroups();
    $catalog->initializeOrderNavigation();
    check($catalog->snapshot()===$initialized&&Db::name('store_v3_permission_audit')->count()===$count,'repeat migration does not regrant or duplicate audits');
    check(Db::name('job_position_channel_rule')->whereIn('channel',['platform','mobile'])->order('id')->select()->toArray()===$beforeChannels,'fresh migration never writes platform/mobile grants');
} finally { Db::rollback(); }
check($catalog->snapshot()===$before,'tests rolled back metadata and audit writes');
echo "R28 PASS\n";
