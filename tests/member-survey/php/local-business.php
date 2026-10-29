<?php
declare(strict_types=1);
/** 合成会员/任务在独立临时本地数据库执行真实服务，结束销毁；禁止远程实例和原库写入。 */
$root=getenv('BACKEND_ROOT')?:getcwd();require$root.'/vendor/autoload.php';
spl_autoload_register(static function(string$c)use($root):void{if(strpos($c,'app\\')!==0)return;$p=$root.'/app/'.str_replace('\\','/',substr($c,4)).'.php';if(is_file($p))require$p;},true,true);
$app=new think\App($root.'/');if($env=getenv('LOCAL_TEST_ENV'))$app->env->load($env);$app->initialize();
use think\facade\Db;
use app\services\user\MemberSurveyServices as Survey;
use app\services\user\MemberSurveyGiftServices as Gift;
if(!in_array(config('database.connections.mysql.hostname'),['mysql','mohe-mysql'],true))throw new RuntimeException('local DB required');
$account=(string)config('database.connections.mysql.username');if(!preg_match('/^r12_test_[a-f0-9]{12}$/D',$account))throw new RuntimeException('restricted test login required');
$original=Db::query('SELECT DATABASE() db')[0]['db'];if(!preg_match('/^[A-Za-z0-9_]+$/D',$original))throw new RuntimeException('invalid db');
$fixtureDatabase='r12_survey_'.bin2hex(random_bytes(5));$checks=[];$schema=[];
function check12(bool$ok,string$label):void{global$checks;if(!$ok)throw new RuntimeException($label);$checks[]=$label;}
function reject12(callable$call,string$label):void{try{$call();}catch(think\exception\ValidateException$e){check12(true,$label);return;}throw new RuntimeException('expected reject: '.$label);}
function seed12(string$table,array$input):void{global$schema;if(!isset($schema[$table]))$schema[$table]=Db::query('SHOW COLUMNS FROM `eb_'.$table.'`');$row=[];foreach($schema[$table]as$c){if(strpos($c['Extra'],'auto_increment')!==false||strpos($c['Extra'],'GENERATED')!==false||$c['Default']!==null||$c['Null']==='YES')continue;$row[$c['Field']]=preg_match('/int|decimal|float|double/',$c['Type'])?0:(strpos($c['Type'],'date')!==false?'2026-10-29':'');}Db::name($table)->insert(array_replace($row,$input));}
$grants=json_encode(Db::query('SHOW GRANTS FOR CURRENT_USER'));check12(strpos($grants,'`ruihao`')===false&&strpos($grants,'ALL PRIVILEGES ON *.*')===false,'测试账号无原开发库写删权限');
Db::execute('CREATE DATABASE `'.$fixtureDatabase.'` CHARACTER SET utf8mb4');
try{
 $tables=['store_reservation_order','inventory_location','user','system_store','system_admin','system_menus','system_role','organization','organization_store','store_coupon_issue','store_coupon_user','store_coupon_issue_user','store_product','store_product_attr_value','cashier_v3_direct_gift_authority','cashier_v3_direct_gift_item','cashier_v3_recharge_gift_coupon_issue_mapping','cashier_v3_gift_fact','cashier_v3_business_event','cashier_v3_outbox','store_order','store_order_cart_info','user_card_holder','cashier_v3_sale_fact','cashier_v3_payment_fact','cashier_v3_performance_fact','cashier_v3_document_sequence','cashier_v3_member_asset_change','cashier_v3_claim_registration','cashier_v3_claim_item'];
 foreach(['cashier_v3','store','user','inventory']as$prefix)foreach(Db::query("SHOW TABLES LIKE 'eb_".$prefix."_%'")as$row)$tables[]=substr(array_values($row)[0],3);
 $tables=array_unique($tables);
 foreach($tables as$t){if(Db::query("SHOW TABLES LIKE 'eb_".$t."'"))Db::execute('CREATE TABLE `'.$fixtureDatabase.'`.`eb_'.$t.'` LIKE `'.$original.'`.`eb_'.$t.'`');}
 Db::execute('USE `'.$fixtureDatabase.'`');$pdo=Db::connect()->getPdo();$sql=file_get_contents($root.'/database/upgrades/2026-10-29-会员调查/01-schema.sql');$pdo->exec($sql);$pdo->exec($sql);
 seed12('user',['uid'=>101,'nickname'=>'合成扫码会员','phone'=>'13800000101','belong_store_id'=>11,'status'=>1,'delete_time'=>null]);seed12('user',['uid'=>102,'nickname'=>'提醒会员','phone'=>'13800000102','belong_store_id'=>11,'status'=>1,'delete_time'=>null]);
 seed12('system_store',['id'=>11,'name'=>'合成门店','is_del'=>0,'is_show'=>1]);seed12('system_admin',['id'=>7,'account'=>'test-survey','status'=>1,'is_del'=>0]);
 seed12('organization',['id'=>21,'pid'=>0,'name'=>'合成组织','is_del'=>0]);seed12('organization_store',['store_id'=>11,'org_id'=>21]);
 seed12('store_coupon_issue',['id'=>31,'relation_id'=>11,'status'=>1,'is_del'=>0,'title'=>'合成奖励券','is_permanent'=>1,'is_give_subscribe'=>0,'is_full_give'=>0,'total_count'=>100,'remain_count'=>100]);
 $policy=new app\services\user\MemberSurveyAccessPolicy();
 $policy->assertPlatform(['id'=>7,'level'=>0,'admin_type'=>0],[]);check12(true,'总部超级管理员管理调查');
 reject12(fn()=>$policy->assertPlatform([],[]),'匿名管理拒绝');
 reject12(fn()=>$policy->assertPlatform(['id'=>7,'level'=>1,'admin_type'=>0],[]),'无菜单授权拒绝');
 reject12(fn()=>$policy->assertPlatform(['id'=>7,'level'=>0,'admin_type'=>1],[['unique_auth'=>'admin-user-survey']]),'门店会话不能管理总部调查');
 $policy->assertPlatform(['id'=>7,'level'=>1,'admin_type'=>0],[['unique_auth'=>'admin-user-survey']]);check12(true,'总部菜单授权可管理');
 $survey=new Survey();$gift=new Gift();$questions=[['type'=>'single','title'=>'选择题','required'=>true,'options'=>['甲','乙']],['type'=>'multiple','title'=>'多选题','required'=>true,'options'=>['一','二']],['type'=>'text','title'=>'建议','required'=>false,'options'=>[]]];
 $qid=$survey->saveQuestionnaire(['title'=>'合成问卷','questions'=>$questions],7);
 $id=$survey->save(['title'=>'公开扫码任务','questionnaire_id'=>$qid],7);
 // 必须走保存后的非空列表回读；空库查询不能覆盖逐任务统计SQL。
 $savedList=$survey->listTasks(1,20);$savedRow=array_column($savedList['list'],null,'id')[$id]??null;
 check12($savedList['count']===1&&$savedRow!==null&&$savedRow['title']==='公开扫码任务'&&(int)$savedRow['answer_count']===0&&(int)$savedRow['total_answer_count']===0,'创建成功后非空列表可回读草稿及零答卷');
 $savedAgain=(new Survey())->listTasks(1,20);check12((int)$savedAgain['list'][0]['id']===$id,'刷新服务后仍能读取已保存任务');
 $survey->publish($id);$task=$survey->readTask($id);
 check12(Db::name('member_survey_target')->count()===0,'零提醒名单可发布');
 // 微信网络边界替换为合成PNG；实际scene编解码与环境参数仍使用真实业务代码。
 class SurveyCodeFixture extends app\services\user\MemberSurveyMiniProgramCodeServices {protected function appId():string{return 'wx0000000000000000';}protected function fetchCode(string $scene,string $page,string $environment):string {if(strlen($scene)!==32||$page!=='pages/users/survey/index'||$environment!=='trial')throw new RuntimeException('invalid mini code request');return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aNaIAAAAASUVORK5CYII=');}}
 $app->bind(app\services\user\MemberSurveyMiniProgramCodeServices::class,SurveyCodeFixture::class);
 think\facade\Config::set(['mini_env_version'=>'trial'],'member_survey');
 $link=$survey->qrEntry($id);check12($link['kind']==='miniprogram'&&strlen($link['scene'])===32&&bin2hex(base64_decode(strtr($link['scene'],'-_','+/')))===$task['entry_token']&&strpos($link['image'],'data:image/png;base64,')===0,'小程序码scene无损定位任务并固定体验环境');
 // 微信可能返回JPEG，也可能返回JSON错误；响应不能被误认图片或把上游凭据带给客户端。
 $jpegCode=new class extends SurveyCodeFixture {protected function fetchCode(string $scene,string $page,string $environment):string{return "\xff\xd8\xffsynthetic-jpeg";}};
 check12(strpos($jpegCode->make($task['entry_token'])['image'],'data:image/jpeg;base64,')===0,'微信JPEG小程序码按正确格式返回');
 $badCode=new class extends SurveyCodeFixture {protected function fetchCode(string $scene,string $page,string $environment):string{return '{"errcode":40013}';}};
 reject12(fn()=>$badCode->make($task['entry_token']),'微信JSON错误不能伪装二维码');
 reject12(fn()=>$jpegCode->make('bad'),'生成小程序码拒绝异常token');
 $failedCode=new class extends SurveyCodeFixture {protected function fetchCode(string $scene,string $page,string $environment):string{throw new RuntimeException('synthetic-private-detail');}};
 try{$failedCode->make($task['entry_token']);throw new RuntimeException('expected code failure');}catch(think\exception\ValidateException $error){check12(strpos($error->getMessage(),'synthetic-private-detail')===false,'小程序码上游异常不泄露原始详情');}
 $public=$survey->entry($task['entry_token']);check12($public['id']===$id&&!isset($public['filters'])&&!isset($public['creator_id']),'公开入口只返回填写所需字段');
 reject12(fn()=>$survey->entry('invalid'),'错误二维码拒绝');reject12(fn()=>$survey->submit(101,$id,[],1),'后端必填保护');
 $answer=['1'=>'1','2'=>['1','2'],'3'=>'合成建议'];$result=$survey->submit(101,$id,$answer,1);
 check12($result['status']==='none'&&Db::name('member_survey_answer')->count()===1,'名单外会员可提交');
 $survey->submit(101,$id,$answer,1);check12(Db::name('member_survey_answer')->count()===1,'同轮重复提交不重复答卷');
 check12($survey->memberDetail(101,$id)['completed']===true,'已提交返回完成状态');
 $answeredRow=array_column($survey->listTasks(1,20)['list'],null,'id')[$id];
 check12((int)$answeredRow['target_count']===0&&(int)$answeredRow['answer_count']===1&&(int)$answeredRow['total_answer_count']===1,'扫码答卷计入当前轮及累计答卷且不扩大提醒名单');
 check12($survey->results($id)['target_count']===0&&(float)$survey->results($id)['answer_rate']===0.0,'公开参与不膨胀提醒名单完成率');
 check12($survey->pending(101)===null,'扫码参与者不加入提醒队列');
 $survey->resend($id);reject12(fn()=>$survey->submit(101,$id,$answer,1),'旧轮次表单不能提交新一轮');$survey->submit(101,$id,$answer,2);
 check12(Db::name('member_survey_answer')->count()===2,'新轮次保留旧答卷');
 $roundRow=array_column($survey->listTasks(1,20)['list'],null,'id')[$id];
 check12((int)$roundRow['answer_count']===1&&(int)$roundRow['total_answer_count']===2,'非空列表区分当前轮答卷和跨轮累计答卷');
 $survey->saveQuestionnaire(['title'=>'新版问卷','questions'=>[['type'=>'text','title'=>'新题','required'=>false]]],7,$qid);
 check12(count($survey->entry($task['entry_token'])['questions'])===3,'编辑问卷不改变已发布题目');
 reject12(fn()=>$survey->save(['title'=>'覆盖发布','questionnaire_id'=>$qid],7,$id),'已发布任务不可覆盖');
 $survey->stop($id);reject12(fn()=>$survey->entry($task['entry_token']),'停止后二维码不可答');
 // 奖励失败不能丢失答卷；修复依赖后只履约原应发记录。
 $id2=$survey->save(['title'=>'奖励任务','questionnaire_id'=>$qid],7);$gift->saveConfig($id2,['store_id'=>11,'reward_each_round'=>false,'coupon'=>[['id'=>31,'num'=>1,'write_days'=>30]],'product'=>[]]);$survey->publish($id2);
 Db::name('system_admin')->where('id',7)->update(['status'=>0]);$reward=$survey->submit(101,$id2,['1'=>'已填写'],1);
 check12($reward['status']==='pending'&&Db::name('member_survey_answer')->where('task_id',$id2)->count()===1,'发奖失败答卷和待发保留');
 check12($gift->memberStatus($id2,102)['status']==='none','其他会员不能查询或领取奖励');
 Db::name('system_admin')->where('id',7)->update(['status'=>1]);$reward=$gift->memberStatus($id2,101,true);
 if($reward['status']!=='issued')throw new RuntimeException('reward issue failed: '.Db::name('member_survey_reward')->where('task_id',$id2)->value('last_error'));
 check12(Db::name('store_coupon_user')->count()===1&&Db::name('cashier_v3_gift_fact')->count()===1,'奖励使用010赠送权威和优惠券权益');
 $gift->memberStatus($id2,101,true);check12(Db::name('store_coupon_user')->count()===1,'重复重试不重复发券');
 $survey->resend($id2);$survey->submit(101,$id2,['1'=>'第二轮'],2);check12(Db::name('store_coupon_user')->count()===1,'仅一次奖励跨轮幂等');
 check12(Db::name('cashier_v3_sale_fact')->count()===0&&Db::name('cashier_v3_payment_fact')->count()===0,'奖励不伪造销售或收款');
 check12(Db::name('cashier_v3_business_event')->count()===1&&Db::name('cashier_v3_outbox')->count()===1,'奖励事件和outbox同事务');

 // 项目与实物也走原赠送投影，实物仅登记待领取，不提前扣库存。
 seed12('store_product',['id'=>41,'type'=>1,'relation_id'=>11,'product_type'=>6,'store_name'=>'合成护理项目','is_del'=>0,'is_verify'=>1,'is_show'=>1]);
 seed12('store_product_attr_value',['id'=>51,'product_id'=>41,'type'=>0,'unique'=>'survey-project-sku','suk'=>'默认','write_times'=>1]);
 seed12('store_product',['id'=>42,'type'=>1,'relation_id'=>11,'product_type'=>0,'store_name'=>'合成实物','is_del'=>0,'is_verify'=>1,'is_show'=>0,'is_inventory'=>0]);
 seed12('store_product_attr_value',['id'=>52,'product_id'=>42,'type'=>0,'unique'=>'survey-product-sku','suk'=>'默认','stock'=>0]);
 seed12('store_product',['id'=>43,'type'=>0,'relation_id'=>11,'product_type'=>0,'store_name'=>'不可赠的非实物','is_del'=>0,'is_verify'=>1,'is_show'=>0]);
 // 共享选品控件仍从所选发放门店的门店商品/规格取值，仓库筛选不会把其他门店混进来。
 $saleCatalog=$gift->catalog(11,['page'=>1,'limit'=>20,'type'=>1]);
 check12($saleCatalog['count']===1&&(int)$saleCatalog['list'][0]['id']===41&&(int)$saleCatalog['list'][0]['attrValue'][0]['id']===51,'问卷商品选择器销售中目录按门店和规格返回');
 $warehouseCatalog=$gift->catalog(11,['page'=>1,'limit'=>20,'type'=>2,'product_type'=>'0']);
 check12($warehouseCatalog['count']===1&&(int)$warehouseCatalog['list'][0]['id']===42&&(int)$warehouseCatalog['list'][0]['attrValue'][0]['id']===52,'问卷商品选择器仓库中实物可检索');
 reject12(fn()=>$gift->catalog(11,['product_type'=>'5']),'问卷商品选择器拒绝整卡赠送');
 for($n=0;$n<21;$n++){seed12('store_product',['id'=>1000+$n,'type'=>1,'relation_id'=>11,'product_type'=>6,'store_name'=>'合成分页项目'.$n,'is_del'=>0,'is_verify'=>1,'is_show'=>1]);seed12('store_product_attr_value',['id'=>1100+$n,'product_id'=>1000+$n,'type'=>0,'unique'=>'survey-page-sku-'.$n,'suk'=>'默认','write_times'=>1]);}
 $catalogFirst=$gift->catalog(11,['page'=>1,'limit'=>20,'type'=>1]);$catalogLast=$gift->catalog(11,['page'=>2,'limit'=>20,'type'=>1]);
 check12($catalogFirst['count']===22&&count($catalogFirst['list'])===20&&$catalogLast['count']===22&&count($catalogLast['list'])===2,'问卷选品目录超过20项时后端分页和总数一致');
 check12($gift->catalog(11,['page'=>1,'limit'=>20,'type'=>1,'store_name'=>'合成护理项目'])['count']===1,'问卷选品搜索后总数回到完整筛选集');
 seed12('inventory_location',['id'=>61,'tenant_id'=>'0','store_id'=>11,'organization_id'=>'21','location_type'=>'STORE','is_default'=>1,'location_status'=>'ACTIVE','location_name'=>'合成门店仓','organization_path'=>'/21/']);
 $id3=$survey->save(['title'=>'项目实物奖励','questionnaire_id'=>$qid],7);
 $config=['store_id'=>11,'reward_each_round'=>true,'product'=>[['id'=>51,'num'=>2,'write_days'=>30],['id'=>52,'num'=>1,'write_days'=>30]],'coupon'=>[]];
 $gift->saveConfig($id3,$config);$survey->publish($id3);$got=$survey->submit(102,$id3,['1'=>'项目实物'],1);
 if($got['status']!=='issued')throw new RuntimeException('project reward failed: '.Db::name('member_survey_reward')->where('task_id',$id3)->value('last_error'));
 check12(Db::name('user_card_holder')->where('uid',102)->value('write_surplus_times')==2,'项目奖励形成010可核销权益');
 check12(Db::name('store_order')->where('uid',102)->where('staff_id','<>',0)->count()===0,'总部发奖不冒认同号门店员工');
 check12(Db::name('store_product_attr_value')->where('id',52)->value('stock')==0,'实物发奖不提前扣库存');
 check12(Db::name('cashier_v3_direct_gift_item')->where('gift_kind','product')->count()===1,'实物奖励进入独立赠送明细');
 $survey->resend($id3);$survey->submit(102,$id3,['1'=>'再次奖励'],2);
 check12(Db::name('user_card_holder')->where('uid',102)->count()===2,'每轮奖励选项允许新一轮再次赠送');
 reject12(fn()=>$gift->saveConfig($id3,$config),'发布后不能修改奖励');
 $id4=$survey->save(['title'=>'失效奖品验证','questionnaire_id'=>$qid],7);$config['product'][0]['num']=1.5;reject12(fn()=>$gift->saveConfig($id4,$config),'奖励数量不接受小数');
 $config['product'][0]['num']=1;Db::name('store_product')->where('id',42)->update(['is_verify'=>0]);reject12(fn()=>$gift->saveConfig($id4,$config),'失效实物不能绕过010赠送资格');
 check12(count($survey->listQuestionnaires(1,1)['list'])===1,'问卷管理后端分页');

 // 两个独立PHP进程真实并发，同一个答卷唯一键和任务锁必须共同阻止重复发奖。

 // 普通门店赠送上下文不带管理员类型，原staff_id投影行为保持不变。
 Db::transaction(function():void{
  $scope=new app\services\cashier\v3\CashierV3OperatorScope(11,99,'21','0');
  $context=new app\services\cashier\v3\CashierV3DataScopeContext(99,99,11,'0','21',[11],'stores',[],false,'','survey-staff-regression',[],['id'=>99,'staff_name'=>'合成门店员工']);
  $recorder=new app\services\cashier\v3\event\CashierV3BusinessEventRecorder();$contract=app\services\cashier\v3\manifest\CashierV3ActionManifest::eventContractFor('submit-direct-gift');$execution=$recorder->newExecution('submit-direct-gift','SURVEY-STAFF-REGRESSION',$scope,$context,'survey-regression');
  $result=(new app\services\cashier\v3\member\CashierV3DirectGiftIssuanceServices())->issueInTx(['handlingStoreId'=>11,'reason'=>'普通门店赠送回归','items'=>[['kind'=>'project','catalogItemId'=>51,'quantity'=>1,'validityEnd'=>date('Y-m-d',time()+86400)]]],Db::name('user')->where('uid',101)->find(),$scope,$context,'SURVEY-STAFF-REGRESSION',$recorder,$execution,$contract);
  $recorder->assertRequiredPersistedInTx($execution,$contract);check12(Db::name('store_order')->where('uid',101)->where('staff_id',99)->count()===1,'原门店赠送staff_id行为保留');
 });

 $stockPolicy=app\services\product\product\ProductStockEligibility::class;
 foreach([[0,1,true],[0,0,false],[4,0,false],[5,0,false],[6,0,false]]as[$type,$inventory,$expected])check12($stockPolicy::appliesTo(['product_type'=>$type,'is_inventory'=>$inventory])===$expected,'已上线库存资格保留：'.$type.':'.$inventory);
 seed12('store_card_related',['id'=>71,'card_product_id'=>99,'product_id'=>41,'product_attr_unique'=>(string)Db::name('store_product_attr_value')->where('id',51)->value('unique'),'status'=>0,'write_times'=>4]);
 $related=app()->make(app\services\product\product\StoreCardRelatedServices::class)->getCardRelatedProductForEdit(99);
 check12(count($related)===1&&(int)$related[0]['status']===0&&(int)$related[0]['write_times']===4,'已上线卡项编辑保留停用关联和次数');
 $parallelId=$survey->save(['title'=>'并发答卷','questionnaire_id'=>$qid],7);
 $gift->saveConfig($parallelId,['store_id'=>11,'reward_each_round'=>true,'product'=>[],'coupon'=>[['id'=>31,'num'=>1,'write_days'=>30]]]);$survey->publish($parallelId);
 $beforeCoupons=Db::name('store_coupon_user')->count();$processes=[];
 for($i=0;$i<2;$i++){$pipes=[];$process=proc_open([PHP_BINARY,'/tests/member-survey/php/concurrent-worker.php',$fixtureDatabase,(string)$parallelId],[1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('worker unavailable');$processes[]=[$process,$pipes];}
 foreach($processes as[$process,$pipes]){$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$decoded=json_decode(trim($output),true);if($exit!==0||empty($decoded['worker_passed']))throw new RuntimeException('concurrent worker failed: '.$output.$error);}
 check12(Db::name('member_survey_answer')->where('task_id',$parallelId)->count()===1,'两个独立连接并发只保存一份答卷');
 check12(Db::name('store_coupon_user')->count()===$beforeCoupons+1,'两个独立连接并发只发一份奖励');
 // 新模块菜单安装可重放，不自动扩大任何既有岗位。
 seed12('system_menus',['id'=>501,'unique_auth'=>'admin-user','type'=>1,'is_del'=>0,'path'=>'0','params'=>'[]','methods'=>'']);
 $menus=file_get_contents($root.'/database/upgrades/2026-10-29-会员调查/02-menu.sql');$pdo->exec($menus);$pdo->exec($menus);
 check12(Db::name('system_menus')->where('unique_auth','admin-user-survey')->count()===1&&Db::name('system_role')->count()===0,'菜单安装幂等且不扩权');
 putenv('R12_SURVEY_INSTALL=1');require '/scripts/member-survey/install.php';$installed=installMemberSurveyMetadata($root,$fixtureDatabase);check12(!empty($installed['passed'])&&$installed['existing_roles_changed']===0,'受控安装器可幂等重放且不扩权');
 $denied=false;try{installMemberSurveyMetadata($root,'not_this_database');}catch(RuntimeException$error){$denied=true;}check12($denied,'安装器目标实例不符时拒绝写入');putenv('R12_EXPECTED_DATABASE='.$fixtureDatabase);
 $expired=$survey->save(['title'=>'过期测试','questionnaire_id'=>$qid],7);$survey->publish($expired);$token=Db::name('member_survey_task')->where('id',$expired)->value('entry_token');Db::name('member_survey_task')->where('id',$expired)->update(['deadline'=>time()-1]);reject12(fn()=>$survey->entry($token),'已过期任务拒绝扫码答题');
 $analysis=$survey->questionnaireAnalysis($qid);check12(count($analysis['groups'])>=1,'问卷分析可读取多任务快照');
 $export=$survey->taskExportData($id2);check12(count($export['details']['rows']??[])===2,'导出保留所有轮次答卷');
 // 新提交门禁：真实会员主表为准，拒绝路径不能留下答卷、扫码关系、归属历史或权益。
 seed12('user',['uid'=>103,'nickname'=>'待选店会员','phone'=>'13800000103','belong_store_id'=>0,'status'=>1,'delete_time'=>null]);
 seed12('user',['uid'=>104,'nickname'=>'未授权手机会员','phone'=>'','belong_store_id'=>11,'status'=>1,'delete_time'=>null]);
 seed12('user',['uid'=>105,'nickname'=>'回滚会员','phone'=>'13800000105','belong_store_id'=>0,'status'=>1,'delete_time'=>null]);
 seed12('system_store',['id'=>12,'name'=>'另一门店','is_del'=>0,'is_show'=>1]);seed12('system_store',['id'=>13,'name'=>'隐藏门店','is_del'=>0,'is_show'=>0]);
 $gated=$survey->save(['title'=>'手机与门店门禁','questionnaire_id'=>$qid],7);$survey->publish($gated);
 $required=$survey->memberDetail(103,$gated)['requirements'];check12(!$required['phone_required']&&$required['store_required']&&array_column($required['stores'],'id')==[11,12],'已授权无归属仅返回启用可选门店');
 reject12(fn()=>$survey->submit(104,$gated,['1'=>'答案'],1,11),'未授权手机号即使伪造门店也不能提交');
 reject12(fn()=>$survey->submit(103,$gated,['1'=>'答案'],1),'无所属门店必须显式选店');
 reject12(fn()=>$survey->submit(103,$gated,['1'=>'答案'],1,13),'隐藏门店不能作为首次归属');
 reject12(fn()=>$survey->submit(103,$gated,['1'=>'答案'],1,999),'不存在门店不能作为首次归属');
 check12(Db::name('member_survey_answer')->where('task_id',$gated)->count()===0&&Db::name('member_survey_target')->where('task_id',$gated)->count()===0,'门禁拒绝不残留答卷或扫码参与关系');
 Db::execute("CREATE TRIGGER survey_fixture_fail_answer BEFORE INSERT ON eb_member_survey_answer FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture answer failure'");
 $failed=false;try{$survey->submit(105,$gated,['1'=>'答案'],1,11);}catch(Throwable$e){$failed=true;}finally{Db::execute('DROP TRIGGER survey_fixture_fail_answer');}
 check12($failed&&(int)Db::name('user')->where('uid',105)->value('belong_store_id')===0&&Db::name('user_belong_store')->where('uid',105)->count()===0&&Db::name('cashier_v3_report_member_store_assignment_period')->where('member_id',105)->count()===0,'答卷失败同步回滚归属主表历史和统计事实');
 $survey->submit(103,$gated,['1'=>'答案'],1,11);
 check12((int)Db::name('user')->where('uid',103)->value('belong_store_id')===11&&Db::name('user_belong_store')->where('uid',103)->where('type','survey_choice')->count()===1&&Db::name('cashier_v3_report_member_store_assignment_period')->where('member_id',103)->count()===1,'首次选择门店与答卷同时保存并保留归属历史事实');
 $survey->submit(103,$gated,['1'=>'答案'],1,12);check12((int)Db::name('user')->where('uid',103)->value('belong_store_id')===11&&Db::name('user_belong_store')->where('uid',103)->count()===1,'重复提交不重复绑店也不覆盖已有归属');
 $owned=$survey->memberDetail(101,$gated)['requirements'];check12(!$owned['phone_required']&&!$owned['store_required']&&$owned['stores']===[],'已有归属会员不再要求选择门店');
 $survey->submit(101,$gated,['1'=>'答案'],1,12);check12((int)Db::name('user')->where('uid',101)->value('belong_store_id')===11,'已有归属拒绝通过问卷提交改绑到其他门店');
 $out=['passed'=>true,'checks'=>$checks,'isolated_database_destroyed_on_exit'=>true];echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
 if($path=getenv('R12_RESULT'))file_put_contents($path,json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}finally{
 // 最终销毁只允许本脚本随机创建的专属前缀；任何变量/连接被外部代码改变都不得退化成原库清理。
 if(!preg_match('/^r12_survey_[a-f0-9]{10}$/D',$fixtureDatabase)||$fixtureDatabase===$original)throw new RuntimeException('fixture cleanup target rejected');
 Db::execute('DROP DATABASE IF EXISTS `'.$fixtureDatabase.'`');
}
