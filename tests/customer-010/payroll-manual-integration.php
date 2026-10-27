<?php
/** 注入 payroll-local.php 的隔离夹具事务；不连接远程，不写客户业务库。 */
$salaryManual=new \app\services\report\BasicSalaryManualServices();
$salaryContext=['visible_store_ids'=>[901],'basic_scope_mode'=>'stores','basic_actor_key'=>'platform:1','salary_edit'=>true,'salary_freeze'=>true];
$salaryBody=['month'=>'2026-10','store_id'=>901,'employee_id'=>99101,'row_key'=>'salary:2026-10:901:99101',
 'values'=>['canbu'=>'100','beizhu'=>'两项保存测试'],'expected_version'=>0,'idempotency_key'=>'salary-test-save-0001'];
$salaryDenied=function($fn,$label){try{$fn();}catch(InvalidArgumentException $e){qp(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);};
$salarySnapshot=Db::name('cashier_v3_performance_fact')->order('id')->select()->toArray();
$salaryLegacy=Db::name('salary_info')->order('id')->select()->toArray();
$salarySaved=$salaryManual->save($salaryContext,$salaryBody);
qp($salarySaved['version']===1,'First manual save creates version one');
qp($salaryManual->save($salaryContext,$salaryBody)['replayed']===true,'Identical request replays original receipt');
qp(Db::name('basic_salary_manual_audit')->count()===1,'Replay creates no extra audit');
$salaryDenied(function()use($salaryManual,$salaryContext,$salaryBody){$salaryManual->save($salaryContext,array_replace($salaryBody,['idempotency_key'=>'salary-test-stale-0001']));},'Stale first-save version rejected');
$salaryRead=(new BasicSalaryReportServices())->read('basic_salary_realtime',[901],$input);
qp($salaryRead['rows'][0]['canbu']===100.0 && $salaryRead['rows'][0]['beizhu']==='两项保存测试','Fresh salary query restores both values');
qp($salaryRead['rows'][0]['manual_version']===1,'Read version matches saved content');
$salarySaved=$salaryManual->save($salaryContext,array_replace($salaryBody,['values'=>['canbu'=>'','beizhu'=>''],'expected_version'=>1,'idempotency_key'=>'salary-test-clear-0001']));
qp($salarySaved['version']===2 && $salarySaved['values']['canbu']==='','Explicit empty string persists');
$salaryRead=(new BasicSalaryReportServices())->read('basic_salary_realtime',[901],$input);
qp($salaryRead['rows'][0]['canbu']===0.0 && $salaryRead['rows'][0]['manual_values']['canbu']==='' && $salaryRead['rows'][0]['beizhu']==='','Cleared manual input is not replaced by defaults');
$salaryDenied(function()use($salaryManual,$salaryContext,$salaryBody){$salaryManual->save($salaryContext,array_replace($salaryBody,['values'=>['work_get'=>'999'],'idempotency_key'=>'salary-test-fact-0001']));},'Calculated amounts cannot be written');
$salaryDenied(function()use($salaryManual,$salaryContext,$salaryBody){$salaryManual->save($salaryContext,array_replace($salaryBody,['values'=>['canbu'=>'1.25'],'idempotency_key'=>'salary-test-cent-0001']));},'New fractional manual amounts rejected');
$salaryDenied(function()use($salaryManual,$salaryContext,$salaryBody){$salaryManual->save($salaryContext,array_replace($salaryBody,['store_id'=>902,'row_key'=>'salary:2026-10:902:99101','idempotency_key'=>'salary-test-scope-0001']));},'Cross-store save rejected');
$salaryDenied(function()use($salaryManual,$salaryContext,$salaryBody){$salaryManual->save(array_replace($salaryContext,['salary_edit'=>false]),$salaryBody);},'Read access cannot manufacture edit capability');
$salaryFreeze=['month'=>'2026-10','frozen'=>true,'expected_version'=>0,'idempotency_key'=>'salary-test-freeze-0001'];
qp($salaryManual->save($salaryContext,$salaryFreeze,true)['frozen']===true,'Platform freezes month');
qp((int)Db::name('salary_freeze')->where('date',strtotime('2026-10-01 Asia/Shanghai'))->value('is_freeze')===1,'Legacy store endpoint sees the same frozen marker');
$salaryStore=array_replace($salaryContext,['basic_actor_key'=>'store:2','salary_freeze'=>false]);
$salaryDenied(function()use($salaryManual,$salaryStore,$salaryBody){$salaryManual->save($salaryStore,array_replace($salaryBody,['expected_version'=>2,'idempotency_key'=>'salary-test-locked-0001']));},'Frozen month rejects direct store write');
$salaryDenied(function()use($salaryManual,$salaryStore,$salaryFreeze){$salaryManual->save($salaryStore,$salaryFreeze,true);},'Store cannot change global freeze');
$salarySaved=$salaryManual->save($salaryContext,array_replace($salaryBody,['expected_version'=>2,'values'=>['canbu'=>'200'],'idempotency_key'=>'salary-test-platform-0001']));
qp($salarySaved['version']===3,'Platform remains editable during freeze');
$salaryDuringFreeze=(new BasicSalaryReportServices())->read('basic_salary_realtime',[901],$input);
qp($salaryDuringFreeze['rows'][0]['canbu']===200.0 && $salaryDuringFreeze['salary_state']['frozen'],'Frozen month still freshly computes current inputs');
$salaryManual->save($salaryContext,array_replace($salaryFreeze,['frozen'=>false,'expected_version'=>1,'idempotency_key'=>'salary-test-unfreeze-0001']),true);
qp($salaryManual->save($salaryStore,array_replace($salaryBody,['expected_version'=>3,'values'=>['canbu'=>'300'],'idempotency_key'=>'salary-test-store-0001']))['version']===4,'Unfreeze restores authorized store edits');
qp(\app\services\report\BasicSalaryManualServices::read('2026-10',902,99101)['version']===0,'Same employee in another store has independent record');
qp(Db::name('cashier_v3_performance_fact')->order('id')->select()->toArray()===$salarySnapshot,'Manual edits and freeze never change business facts');
qp(Db::name('salary_info')->order('id')->select()->toArray()===$salaryLegacy,'Legacy salary JSON preserved');

// 旧平台冻结入口仍在用：新读入口必须识别它的状态，不分裂成两套冻结。
Db::name('salary_freeze')->where('date',strtotime('2026-10-01 Asia/Shanghai'))->update(['is_freeze'=>1]);
qp(\app\services\report\BasicSalaryManualServices::state('2026-10')['frozen'],'Legacy freeze remains authoritative in new report');
$salaryDenied(function()use($salaryManual,$salaryStore,$salaryBody){$salaryManual->save($salaryStore,array_replace($salaryBody,['expected_version'=>4,'idempotency_key'=>'salary-test-oldlock-0001']));},'Legacy freeze also blocks new store save');
Db::name('salary_freeze')->where('date',strtotime('2026-10-01 Asia/Shanghai'))->update(['is_freeze'=>2]);
qp(!\app\services\report\BasicSalaryManualServices::state('2026-10')['frozen'],'Legacy cancellation is visible to new report');
