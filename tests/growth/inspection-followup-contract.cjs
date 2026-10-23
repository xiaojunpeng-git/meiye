/* 重复问题按同店同标准同项聚合；补训与整改关闭保持独立，转交保留证据。 */
const { admin, mobile, assert, base, key } = require('./local-client.cjs');
const fs = require('node:fs'), path = require('node:path');
(async () => {
  const a = await admin(), learner = await mobile(), next = await mobile('emp91004');
  const form = new FormData(); form.append('menu', 'inspection'); form.append('file', new Blob([fs.readFileSync(path.resolve(__dirname, '../../../任务管理/进行中/美业大学成长管理/证据/媒体样例/服务准备长图.png'))]), '现场长图.png');
  const uploaded = await (await fetch(base + '/adminapi/growth/asset/upload', { method: 'POST', headers: a.headers, body: form })).json(); assert.equal(uploaded.status, 200); const proof = uploaded.data.id;
  const standard = await a.call('standard/publish', { menu: 'inspection', title: '反复问题检查标准-' + Date.now(), purpose: 'inspection', mode: 'pass', items: [{ key: 'clean', title: '操作区域整理', max_score: 10, required: true }] });
  const issues = [];
  for (let i = 0; i < 2; i++) {
    const inspection = await a.call('inspection/create', { title: '反复问题巡检-' + i, store_id: 91001, executor_employee_id: 91001, reviewer_employee_id: 91001, standard_release_id: standard.release_id });
    if (i === 0) { await a.call('inspection/handover', { inspection_id: inspection.id, employee_id: 91005, reason: '安排师傅现场检查' }); assert.notEqual((await a.call('inspection/submit', { id: inspection.id, items: {} }, true)).status, 200); await a.call('inspection/handover', { inspection_id: inspection.id, employee_id: 91001, reason: '改由体验管理员执行检查' }); }
    const r = await a.call('inspection/submit', { id: inspection.id, items: { clean: { passed: false, note: '待整理', asset_ids: [proof] } }, problems: [{ item_key: 'clean', description: '台面待整理', responsible_employee_id: 91002, reviewer_employee_id: 91001, due_at: Math.floor(Date.now() / 1000) + 86400, asset_ids: [proof] }] }); issues.push(r.issue_ids[0]);
  }
  const report = await a.call('report?report=issues&store_id=91001&standard_id=' + standard.id + '&item_key=clean');
  assert.equal(report.total, 2); assert.equal(report.totals.repeated_issue_groups, 1); assert.equal(Number(report.repeat_groups[0].occurrences), 2);
  const content = { title: '整理规范补训-' + Date.now(), chapters: [{ key: 'main', title: '整理规范', type: 'text', body: '归位用品，清洁台面，完成取证。' }] };
  const draft = await a.call('content/save', content), pub = await a.call('content/publish', { ...content, id: draft.id });
  const training = await a.call('issue/train', { issue_id: issues[0], kind: 'course', source_id: pub.publication_id, reason: '补足现场整理规范' });
  await learner.call('learn', { task_id: training.task_id, chapter_key: 'main', session_key: key(), session_open: true, confirm: true, position: 0 });
  assert.equal((await a.call('issue?id=' + issues[0])).issue.state, 'OPEN');
  await a.call('issue/action', { issue_id: issues[0], action: 'transfer', responsible_employee_id: 91004, reason: '由新负责人继续整改' });
  const detail = await next.call('issue?id=' + issues[0]);
  const readable = await fetch(base + '/api/mobile/merchant/growth/asset?id=' + proof + '&task_id=' + detail.issue.task_id, { headers: next.headers }); assert.equal(readable.status, 200); assert.ok((await readable.arrayBuffer()).byteLength > 500);
  const newFile=new FormData();newFile.append('file',new Blob([fs.readFileSync(path.resolve(__dirname,'../../../任务管理/进行中/美业大学成长管理/证据/媒体样例/服务准备长图.png'))]),'整改后证据.png');const ownProof=await (await fetch(base+'/api/mobile/merchant/growth/asset/upload',{method:'POST',headers:next.headers,body:newFile})).json();assert.ok(ownProof.growth);
  await next.call('issue/rectify', { issue_id: issues[0], body: '参照原证据完成整理', asset_ids: [ownProof.growth.id] });
  await a.call('issue/review', { issue_id: issues[0], passed: true, note: '独立复查确认达到标准' });
  assert.equal((await a.call('issue?id=' + issues[0])).issue.state, 'CLOSED');
  console.log(JSON.stringify({ passed: ['巡检未完成职责交接与旧执行人限制', '同店同标准同项重复问题精确分组', '补训完成不自动关闭原整改', '跨店明确交接新负责人仍可读取原证据', '新负责人提交后由原指派复查人独立关闭'] }, null, 2));
})().catch(e => { console.error(e); process.exitCode = 1; });
