/* 分类题型抽题及卷内分值固定，关键题不能挤掉其他分类配额。 */
const { admin, mobile, assert } = require('./local-client.cjs');
(async () => {
  const a = await admin(), m = await mobile(), ids = [];
  for (let i = 0; i < 4; i++) ids.push((await a.call('question/save', { type: i < 2 ? 'single' : 'boolean', stem: '分类题-' + Date.now() + '-' + i, category: i < 2 ? '沟通' : '安全', options: ['正确', '错误'], answer: [0], score: 10 })).id);
  const rules = [{ type: 'single', category: '沟通', count: 1, score: 7 }, { type: 'boolean', category: '安全', count: 1, score: 13 }];
  const input = { title: '分类抽题-' + Date.now(), mode: 'random', question_ids: ids, draw_rules: rules };
  const p = await a.call('paper/publish', input);
  assert.notEqual((await a.call('paper/publish', { ...input, draw_rules: [...rules, rules[0]] }, true)).status, 200);
  assert.notEqual((await a.call('paper/publish', { ...input, draw_rules: [{ ...rules[0], count: 3 }] }, true)).status, 200);
  const examBody = { title: '分类抽题检验', paper_release_id: p.release_id, employee_ids: [91002], auto_publish: true, reward_selection:{mode:'selected',point_rule_ids:[],certificate_rule_ids:[]},pass_score: 20, key_question_ids: [ids[0]] };
  assert.notEqual((await a.call('exam/create', { ...examBody, key_question_ids: [ids[0], ids[1]] }, true)).status, 200);
  const exam = await a.call('exam/create', examBody), first = await m.call('exam/start', { task_id: exam.task_ids[0] });
  assert.deepEqual((await a.call('task?id='+exam.task_ids[0])).rules.point_rules,[]); assert.equal(first.questions.length, 2); assert.equal(first.max_score, 20); assert.ok(first.questions.some(q => q.id === ids[0])); assert.deepEqual(first.questions.map(q => q.score).sort((a,b) => a-b), [7, 13]);
  const reopened = await m.call('exam/start', { task_id: exam.task_ids[0] }); assert.deepEqual(reopened.questions, first.questions);
  const answers = Object.fromEntries(first.questions.map(q => [q.id, [0]]));
  assert.equal((await m.call('exam/submit', { attempt_id: reopened.id, answer_session_key: reopened.answer_session_key, answers })).score, 20);
  const fixed = await a.call('paper/publish', { title: '固定题序及分值', mode: 'fixed', question_ids: [ids[2], ids[0]], question_scores: { [ids[2]]: 17, [ids[0]]: 3 } });
  const fixedExam = await a.call('exam/create', { ...examBody, paper_release_id: fixed.release_id, key_question_ids: [] });
  const fixedAttempt = await m.call('exam/start', { task_id: fixedExam.task_ids[0] }); assert.deepEqual(fixedAttempt.questions.map(q => q.id), [ids[2], ids[0]]); assert.deepEqual(fixedAttempt.questions.map(q => q.score), [17, 3]);
  console.log(JSON.stringify({ passed: ['按分类与题型抽题且卷内分值独立冻结', '重叠规则和缺题明确拒绝', '关键题不能超过所属分类配额', '重进不重抽且按冻结分值计分', '固定试卷保留题序与每题分值'] }, null, 2));
})().catch(e => { console.error(e); process.exitCode = 1; });
