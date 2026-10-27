/* 即时练习解析只解释当前题，不保存成绩或替代唯一交卷；正式考试始终禁止。 */
const { admin, mobile, assert } = require('./local-client.cjs');
(async () => {
  const a = await admin(), m = await mobile();
  const q = await a.call('question/save', { type: 'order', stem: '即时顺序练习-' + Date.now(), options: ['核对需求', '清洁双手', '准备用品'], answer: [1, 0, 2], score: 10, explanation: '先清洁，再核对，最后准备。' });
  const p = await a.call('paper/publish', { title: '即时解析练习卷', mode: 'fixed', question_ids: [q.id] });
  const input = { title: '即时练习-' + Date.now(), kind: 'practice', paper_release_id: p.release_id, employee_ids: [91002], auto_publish: true, pass_score: 10, required_pass: false, practice_analysis_timing: 'immediate' };
  const e = await a.call('exam/create', input), first = await m.call('exam/start', { task_id: e.task_ids[0] });
  assert.equal(first.practice_analysis_timing, 'immediate'); assert.ok(!first.questions[0].answer);
  const check = { attempt_id: first.id, answer_session_key: first.answer_session_key, question_id: q.id, answer: [0, 1, 2] };
  const feedback = await m.call('practice/check', check); assert.equal(feedback.passed, false); assert.deepEqual(feedback.answer, [1, 0, 2]);
  assert.equal((await m.call('attempt?id=' + first.id)).state, 'ANSWERING'); assert.notEqual((await m.call('task?id=' + e.task_ids[0])).state, 'COMPLETED');
  const passed = await m.call('exam/submit', { attempt_id: first.id, answer_session_key: first.answer_session_key, answers: { [q.id]: [1, 0, 2] } }); assert.equal(passed.score, 10);
  const formal = await a.call('exam/create', { ...input, title: '正式考试禁提前解析', kind: 'exam' }), exam = await m.call('exam/start', { task_id: formal.task_ids[0] });
  assert.ok(!(await m.call('practice/check', { ...check, attempt_id: exam.id, answer_session_key: exam.answer_session_key }, true)).growth);
  const late = await a.call('exam/create', { ...input, practice_analysis_timing: 'after_submit' }), lateAttempt = await m.call('exam/start', { task_id: late.task_ids[0] });
  assert.ok(!(await m.call('practice/check', { ...check, attempt_id: lateAttempt.id, answer_session_key: lateAttempt.answer_session_key }, true)).growth);
  console.log(JSON.stringify({ passed: ['明确开启的练习逐题即时解析', '即时反馈不保存正式成绩或完成任务', '最终完整交卷独立冻结评分', '正式考试和交卷后解析配置均禁止提前查看'] }, null, 2));
})().catch(e => { console.error(e); process.exitCode = 1; });
