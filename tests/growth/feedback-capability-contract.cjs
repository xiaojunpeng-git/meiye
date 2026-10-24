/* 错题、题目反馈、个人范围处理人及岗位能力统计的完整真实HTTP业务回归。 */
const {assert,key,admin,mobile}=require('./local-client.cjs');
(async()=>{
 const a=await admin(),m=await mobile(),teacher=await mobile('emp91004');const checks=[];
 const q=await a.call('question/save',{type:'multiple',stem:'哪些属于准备流程？',options:['核对需求','清洁双手','跳过禁忌核对'],answer:[0,1],score:10});
 const p=await a.call('paper/publish',{title:'错题重练验证',mode:'fixed',question_ids:[q.id]});
 const e=await a.call('exam/create',{title:'错题反馈验证-'+Date.now(),paper_release_id:p.release_id,employee_ids:[91002],pass_score:10,max_attempts:2,auto_publish:true,show_analysis:true});
 const first=await m.call('exam/start',{task_id:e.task_ids[0]});
 const wrong=await m.call('exam/submit',{attempt_id:first.id,answer_session_key:first.answer_session_key,answers:{[q.id]:[0]}});assert.equal(wrong.score,0);
 const feed=await m.call('question/feedback',{attempt_id:first.id,question_id:q.id,description:'请核对选项中禁忌核对的描述。'});
 const found=(await a.call('question/feedback')).rows.find(r=>Number(r.id)===feed.id);assert.ok(found);await a.call('question/feedback/resolve',{id:feed.id,resolution:'已核对原题与标准答案，无需修改原成绩。'});assert.equal((await m.call('attempt?id='+first.id)).score,0);checks.push('反馈仅引用真实答卷题目，处理不覆盖原答案与成绩');
 const invalid=await m.call('question/feedback',{attempt_id:first.id,question_id:999999,description:'无权引用的题目'},true);assert.ok(!invalid.growth);
 const practice=await m.call('exam/mistakes',{attempt_id:first.id});const t=await m.call('task?id='+practice.task_id);assert.equal(t.required,0);assert.ok(t.rules.self_practice);assert.deepEqual(t.rules.point_rules,[]);assert.deepEqual(t.rules.certificate_rules,[]); // 自主练习不套用现有奖励规则。
 const retry=await m.call('exam/start',{task_id:practice.task_id});assert.equal(retry.questions.length,1);const correct=await m.call('exam/submit',{attempt_id:retry.id,answer_session_key:retry.answer_session_key,answers:{[q.id]:[1,0]}});assert.equal(correct.score,10);assert.ok(correct.self_practice);assert.equal((await m.call('attempt?id='+first.id)).score,0);checks.push('统一题库错题重练，多选顺序无关但必须全对，原错题和奖励边界保留');
 const standard=await a.call('standard/publish',{title:'岗位能力验证标准',purpose:'evaluation',mode:'pass',items:[{key:'prepare',title:'准备规范',required:true,max_score:10}]});
 const cap=await a.call('capability/save',{position_id:91001,title:'能力统计验证-'+Date.now(),description:'按实操事实判断',standard_release_id:standard.release_id});
 const before=await a.call('report?report=capabilities&employee_id=91002');const row=before.records.find(r=>Number(r.capability_id)===cap.id);assert.ok(row);assert.equal(row.state,'NOT_EVALUATED');assert.ok(before.totals.not_evaluated_capabilities>0);await a.call('report/drill?report=capabilities&id='+row.id);checks.push('当前岗位能力未评价单列，复合来源键能精确下钻');
 // 隔离店员工只有个人范围，但明确指派可处理这一任务，仍不能读取学员其他任务。
 const act=await a.call('activity/create',{kind:'evaluation',title:'个人范围指定鉴定人-'+Date.now(),standard_release_id:standard.release_id,capability_id:cap.id,employee_ids:[91002],handler_employee_id:91004});
 const submit=await m.call('work/submit',{task_id:act.task_ids[0],body:'正式提交实操准备',asset_ids:[]});
 const pending=await teacher.call('practice');assert.ok(pending.pending.some(r=>Number(r.id)===act.task_ids[0]));await teacher.call('work?task_id='+act.task_ids[0]);
 await teacher.call('work/review',{submission_id:submit.id,note:'逐项观察达标',items:{prepare:{passed:true,note:'完整完成准备'}}});
 assert.ok(!(await teacher.call('task?id='+e.task_ids[0],undefined,true)).growth);checks.push('个人范围的指派处理人可完成实操，只能打开所指派任务');
 const after=await a.call('report?report=capabilities&employee_id=91002');const passed=after.records.find(r=>Number(r.capability_id)===cap.id);assert.equal(passed.state,'QUALIFIED');assert.ok(passed.evaluation_id);assert.equal(after.total,before.total);checks.push('能力统计读取最新正式实操结果，未评价转为已达标不重复计人项');
 console.log(JSON.stringify({passed:checks,attempt_id:first.id,capability_id:cap.id},null,2));
})().catch(e=>{console.error(e);process.exitCode=1;});
