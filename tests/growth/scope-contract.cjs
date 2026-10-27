/* 菜单、发生归属、组织直属身份与开关/缺表回归，全部只操作本地隔离环境。 */
const {assert,key,raw,base,admin,mobile,fixture}=require('./local-client.cjs');
const {execFileSync}=require('node:child_process');
(async()=>{
 fixture({mode:'setup'});fixture({mode:'begin'});
 const checks=[];
 try{
  const root=await admin(),scope=await admin('growth_scope'),isolated=await admin('growth_isolated');
  const body={title:'权限真实回归-'+Date.now(),chapters:[{key:'main',title:'学习内容',type:'text',body:'仅使用虚构测试数据。'}]};
  const draft=await scope.call('content/save',body);const pub=await scope.call('content/publish',{...body,id:draft.id});
  const old=await root.call('task/assign',{publication_id:pub.publication_id,employee_ids:[91002]});const oldId=old.task_ids[0];
  assert.notEqual((await scope.call('settings',undefined,true)).status,200);
  assert.notEqual((await isolated.call('content/save',body,true)).status,200);
  assert.notEqual((await isolated.call('task?id='+oldId,undefined,true)).status,200);
  await scope.call('task?id='+oldId); checks.push('仅有菜单即能新增编辑发布，无按钮ACL；未授权菜单拒绝');
  const self=await mobile();const foreign=await mobile('emp91004');await self.call('task?id='+oldId);assert.ok(!(await foreign.call('task?id='+oldId,undefined,true)).growth);checks.push('员工本人及跨员工隔离');
  // 两位编辑者对同一后台草稿保存，最后完整保存覆盖；不要求版本号或编辑锁。
  await scope.call('content/save',{...body,id:draft.id,title:'甲保存'});await root.call('content/save',{...body,id:draft.id,title:'乙最终保存',description:'完整覆盖'});const detail=await scope.call('content?id='+draft.id);assert.equal(detail.draft.title,'乙最终保存');assert.equal(detail.draft.description,'完整覆盖');checks.push('多人草稿最后保存覆盖');
  fixture({mode:'transfer'});
  await scope.call('task?id='+oldId);assert.notEqual((await isolated.call('task?id='+oldId,undefined,true)).status,200);
  const fresh=await root.call('task/assign',{publication_id:pub.publication_id,employee_ids:[91002]});const newId=fresh.task_ids[0];
  assert.notEqual((await scope.call('task?id='+newId,undefined,true)).status,200);await isolated.call('task?id='+newId);
  const newSelf=await mobile();await newSelf.call('task?id='+oldId);await newSelf.call('task?id='+newId);
  const oldProfile=await scope.call('profile?employee_id=91002');assert.ok(oldProfile.tasks.some(t=>Number(t.id)===oldId));assert.ok(!oldProfile.tasks.some(t=>Number(t.id)===newId));checks.push('调店后旧门店保留原事实，新门店不继承旧事实，本人保留全部');
  // 组织直属员工不伪造门店，旧门店根状态在成长关闭或安装不完整时仍可使用。
  const org=await mobile('emp91003');assert.ok(org.login.merchantToken);
  const boot=await org.bootstrap();assert.ok(boot.availableActions?.includes('mobile.merchant.growth'),JSON.stringify(boot));
  fixture({mode:'set',table:'employee_mobile_auth',id:91003,data:{rules:'401001'}});
  assert.ok(!(await org.call('tasks',undefined,true)).growth);checks.push('组织直属入口真实授权可即时撤回');
  fixture({mode:'set',table:'employee_mobile_auth',id:91003,data:{rules:'401001,401030'}});
  fixture({mode:'set',table:'organization',id:91001,data:{status:0}});
  const stale=await org.bootstrap();assert.ok(!stale.availableActions?.includes('mobile.merchant.growth'),JSON.stringify(stale));checks.push('组织祖先停用后旧会话不能沿用成长身份');
  fixture({mode:'set',table:'organization',id:91001,data:{status:1}});
  const store=await mobile('emp91004');
  fixture({mode:'set',table:'growth_setting',id:1,data:{enabled:0}});
  const disabled=await store.bootstrap();assert.ok(disabled.merchantToken||disabled.availableActions,JSON.stringify(disabled));assert.ok(!disabled.availableActions?.includes('mobile.merchant.growth'));
  assert.notEqual((await root.call('content/save',body,true)).status,200);
  const tick=()=>JSON.parse(execFileSync('docker',['exec','mohe-growth-local-app','php','/workspace/scripts/growth/process-local.php'],{encoding:'utf8'}));assert.equal(tick().state,'DISABLED');checks.push('模块关闭保留旧根状态，拒绝成长写入并停止后台派发');
  fixture({mode:'set',table:'growth_setting',id:1,data:{enabled:1}});fixture({mode:'schema-hold'});
  const partial=await store.bootstrap();assert.ok(partial.merchantToken||partial.availableActions,JSON.stringify(partial));assert.ok(!partial.availableActions?.includes('mobile.merchant.growth'));assert.notEqual((await root.call('content/save',body,true)).status,200);assert.equal(tick().state,'DISABLED');checks.push('部分迁移缺表不影响旧门店登录，成长入口和worker拒绝运行');
  console.log(JSON.stringify({passed:checks,task_ids:[oldId,newId]},null,2));
 }finally{fixture({mode:'restore'});}
})().catch(e=>{console.error(e);process.exitCode=1;});
