/* 虚构体验内容通过正式HTTP入口建立，源码与技术测试共用同一业务实现。 */
const { admin, mobile, assert, base } = require('../../tests/growth/local-client.cjs');
const fs = require('node:fs'), path = require('node:path');
const { execFileSync } = require('node:child_process');
const evidence = path.resolve(__dirname, '../../../任务管理/进行中/美业大学成长管理/证据');
(async () => {
  const prepared=JSON.parse(execFileSync('docker', ['exec', 'mohe-growth-local-app', 'php', '/workspace/scripts/growth/prepare-demo.php'],{encoding:'utf8'}));assert.equal(prepared.learner,'emp400010');
  const a = await admin('growth_demo');
  const existing = await a.call('contents?keyword=' + encodeURIComponent('服务准备 · 从容开始每一次护理'));
  if (existing.rows.length) { console.log('演示内容已存在，保留当前操作状态。'); return; }
  const assets = {};
  for (const [type, filename] of [['word', '服务准备.docx'], ['ppt', '服务准备.pptx'], ['image', '服务准备长图.png'], ['video', '服务准备.mp4']]) {
    const f = new FormData(); f.append('file', new Blob([fs.readFileSync(path.join(evidence, '媒体样例', filename))]), filename);
    const r = await (await fetch(base + '/adminapi/growth/asset/upload', { method: 'POST', headers: a.headers, body: f })).json(); assert.equal(r.status, 200); assert.equal(r.data.state, 'READY'); assets[type] = r.data.id;
  }
  const points = await a.call('reward/rule', { kind: 'points', title: '认真完成一次学习', event_type: 'TASK_COMPLETED', enabled: true, points: 10, first_only: true, max_awards: 1 });
  const course = { title: '服务准备 · 从容开始每一次护理', lecturer: '苏老师', estimated_minutes: 5, cover_asset_id: assets.image, material_asset_ids: [assets.word, assets.ppt], description: '从核对需求到准备用品，把每一次服务的第一步做好。', reward_selection: { mode: 'selected', point_rule_ids: [points.id], certificate_rule_ids: [] }, chapters: [{ key: 'intro', title: '先把顾客放在心上', type: 'text', body: '先倾听，再核对\n认真询问本次护理诉求、过敏史与近期护理情况。\n复述确认\n用自己的话复述顾客需求，让双方对服务目标达成一致。\n清洁与准备\n清洁双手，归位用品，核对器具与操作环境。', required: true }, ...['word', 'ppt', 'image', 'video'].map(type => ({ key: type, title: ({ word: 'Word · 服务标准', ppt: 'PPT · 要点回顾', image: '长图 · 六步准备', video: '视频 · 留给专业一点时间' })[type], type, asset_id: assets[type], required: false }))] };
  const draft = await a.call('content/save', course), pub = await a.call('content/publish', { ...course, id: draft.id });
  const assigned = await a.call('task/assign', { publication_id: pub.publication_id, employee_ids: [400010], handler_employee_id: 400011, relative_days: 7, description: '先看短文，也可以随时打开资料复习。' });
  const questions = [];
  for (const q of [{ type: 'single', stem: '开始护理前，首先应该做什么？', options: ['核对顾客需求与禁忌', '直接开始操作', '只询问付款方式'], answer: [0] }, { type: 'multiple', stem: '哪些属于服务前的准备？', options: ['清洁双手', '核对需求', '准备用品', '省略禁忌核对'], answer: [0, 1, 2] }, { type: 'boolean', stem: '复述确认可以帮助双方明确本次服务目标。', options: ['正确', '错误'], answer: [0] }, { type: 'order', stem: '请按标准准备流程点选全部步骤', options: ['准备用品', '核对需求', '清洁双手'], answer: [2, 1, 0] }]) questions.push((await a.call('question/save', { ...q, score: 10, explanation: '把安全、沟通和准备放在服务开始之前。' })).id);
  const paper = await a.call('paper/publish', { title: '服务准备 · 四题小检验', mode: 'fixed', question_ids: questions });
  const exam = await a.call('exam/create', { title: '给专业一个小检验', reward_selection:{mode:'selected',point_rule_ids:[],certificate_rule_ids:[]}, paper_release_id: paper.release_id, employee_ids: [400010], grader_employee_id: 400011, max_attempts: 3, pass_score: 40, auto_publish: true, show_analysis: true, required_pass: true, duration_minutes: 20 });
  const standard = await a.call('standard/publish', { title: '护理服务实操标准', purpose: 'evaluation', mode: 'score', calculation: 'sum', pass_score: 80, items: [{ key: 'safety', title: '核对需求与禁忌', max_score: 40, required: true, critical: true }, { key: 'technique', title: '准备与操作规范', max_score: 60, required: true }] });
  const activityIds = {};
  for (const [kind, title] of [['assignment', '把服务准备练一遍'], ['evaluation', '请师傅看看我的实操'], ['coaching', '和苏老师的一次带教'], ['offline', '门店服务沟通小课堂']]) activityIds[kind] = await a.call('activity/create', { kind, title, description: kind === 'coaching' ? '一起练习询问、复述与准备，记录收获和下一步。' : '按任务要求完成，由苏老师给予反馈。', employee_ids: [400010], handler_employee_id: 400011, standard_release_id: kind === 'evaluation' ? standard.release_id : 0, evidence_required: kind === 'assignment', location: '春日美学体验店', event_at: Math.floor(Date.now() / 1000) + 86400 });
  const map = await a.call('map/publish', { title: '新人成长 · 第一步', sequential: true, stages: [{ title: '理解标准', activities: [{ kind: 'course', title: '服务准备学习', source_id: pub.publication_id }] }, { title: '练到手上', activities: [{ kind: 'coaching', title: '师傅带教', source_id: activityIds.coaching.id }] }] });
  const plan = await a.call('plan/publish', { title: '林晓 · 七天成长计划', map_release_id: map.release_id, employee_ids: [400010], responsible_employee_id: 400011, mentor_employee_id: 400011, due_at: Math.floor(Date.now() / 1000) + 7 * 86400 });
  const knowledge = { kind: 'knowledge', title: '顾客沟通 · 三句有温度的确认', category: '服务沟通', description: '不急着开始，先认真听懂。', chapters: [{ key: 'main', title: '有温度的确认', type: 'text', body: '“您今天最希望改善的是什么？”\n“最近有过敏或其他护理经历吗？”\n“我再确认一下，您最在意的是……对吗？”' }] };
  const k = await a.call('content/save', knowledge); await a.call('content/publish', { ...knowledge, id: k.id });
  const learner = await mobile('emp400010'); await learner.call('help/ask', { body: '遇到顾客表达不清楚时，怎样自然地进一步了解需求？', handler_employee_id: 400011 });
  const result = { title: course.title, learner: 'emp400010', mentor: 'emp400011', admin: 'growth_demo', course_id: draft.id, course_task_id: assigned.task_ids[0], exam_id: exam.id, exam_task_id: exam.task_ids[0], activities: activityIds, plan_id: plan.id, questions };
  fs.writeFileSync(path.join(evidence, 'demo-result.json'), JSON.stringify(result, null, 2)); console.log('干净体验账号与学习、练考、带教、实操、计划和知识已建立。');
})().catch(e => { console.error(e); process.exitCode = 1; });
