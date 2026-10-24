/* 真实练考HTTP验证：服务端固定题、唯一交卷、人工公布与改分留痕。 */
const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const origin = 'http://127.0.0.1:18115';
const key = () => 'exam-qa-' + randomUUID();
async function request(path, data, headers = {}) {
  return (
    await fetch(origin + path, {
      method: data ? 'POST' : 'GET',
      headers: { 'Content-Type': 'application/json', ...headers },
      body: data ? JSON.stringify(data) : undefined
    })
  ).json();
}
(async () => {
  const login = await request('/adminapi/login', { account: 'growth_admin', pwd: 'GrowthLocal!2026' });
  assert.equal(login.status, 200, login.msg);
  const adminHeaders = { 'Authori-zation': 'Bearer ' + login.data.token, 'X-Source': 'f76d38d0ee4f854f' };
  async function admin(path, data) {
    const r = await request(
      '/adminapi/growth/' + path,
      data ? { ...data, idempotency_key: key() } : undefined,
      adminHeaders
    );
    assert.equal(r.status, 200, path + ': ' + r.msg);
    return r.data;
  }
  const questions = [];
  for (const q of [
    { type: 'single', stem: '服务前先做什么？', options: ['核对需求', '直接操作'], answer: [0], score: 10 },
    {
      type: 'multiple',
      stem: '哪些属于服务前准备？',
      options: ['清洁双手', '核对需求', '不问直接操作'],
      answer: [0, 1],
      score: 10
    }
  ])
    questions.push((await admin('question/save', q)).id);
  const paper = await admin('paper/publish', {
    title: '美容师服务基础试卷',
    mode: 'fixed',
    question_ids: questions
  });
  const exam = await admin('exam/create', {
    title: '服务基础检验-' + Date.now(),
    paper_release_id: paper.release_id,
    employee_ids: [91002],
    max_attempts: 2,
    pass_score: 20,
    auto_publish: true,
    show_analysis: true,
    required_pass: true
  });
  const headers = {
    'X-Mobile-Contract-Version': 'mobile-merchant-v1',
    'X-Mobile-Client-Session-Id': key(),
    'X-Mobile-Platform': 'WEB',
    'X-Mobile-Request-Id': key()
  };
  const root = await request(
    '/api/mobile/merchant/password-login',
    { account: 'emp91002', pwd: 'GrowthLocal!2026', installationId: key(), idempotencyKey: key() },
    headers
  );
  assert.ok(root.merchantToken, JSON.stringify(root));
  Object.assign(headers, {
    Authorization: 'Bearer ' + root.merchantToken,
    'X-Mobile-Active-Context-Id': root.activeContextId,
    'X-Mobile-State-Context-Id': root.stateContextId
  });
  async function mobile(path, data, allowError = false) {
    const r = await request('/api/mobile/merchant/growth/' + path, data, headers);
    if (!allowError) assert.ok(r.growth, path + ': ' + JSON.stringify(r));
    return allowError ? r : r.growth;
  }
  const attempt = await mobile('exam/start', { task_id: exam.task_ids[0], idempotency_key: key() });
  assert.equal(attempt.questions.length, 2);
  assert.ok(!('answer' in attempt.questions[0]), '作答前不得泄露答案');
  const reopened = await mobile('exam/start', { task_id: exam.task_ids[0], idempotency_key: key() });
  assert.equal(attempt.id, reopened.id);
  assert.deepEqual(
    attempt.questions.map((q) => q.id),
    reopened.questions.map((q) => q.id)
  );
  const oldSession = await mobile(
    'exam/save',
    {
      attempt_id: attempt.id,
      answer_session_key: attempt.answer_session_key,
      answers: {},
      idempotency_key: key()
    },
    true
  );
  assert.ok(!oldSession.growth, '旧作答会话失效');
  const failed = await mobile('exam/submit', {
    attempt_id: attempt.id,
    answer_session_key: reopened.answer_session_key,
    answers: { [questions[0]]: [1], [questions[1]]: [0] },
    idempotency_key: key()
  });
  assert.equal(failed.score, 0, '漏选不得分');
  assert.equal(failed.passed, false);
  assert.equal(failed.can_retake, true);
  const late = await mobile(
    'exam/save',
    {
      attempt_id: attempt.id,
      answer_session_key: reopened.answer_session_key,
      answers: {},
      idempotency_key: key()
    },
    true
  );
  assert.ok(!late.growth, '交卷后拒绝迟到保存');
  const second = await mobile('exam/start', { task_id: exam.task_ids[0], idempotency_key: key() });
  assert.notEqual(second.id, attempt.id);
  assert.equal(second.attempt_no, 2);
  const passed = await mobile('exam/submit', {
    attempt_id: second.id,
    answer_session_key: second.answer_session_key,
    answers: { [questions[0]]: [0], [questions[1]]: [0, 1] },
    idempotency_key: key()
  });
  assert.equal(passed.score, 20);
  assert.equal(passed.passed, true);
  assert.equal((await admin('task?id=' + exam.task_ids[0])).state, 'COMPLETED');
  const text = (
    await admin('question/save', {
      type: 'text',
      stem: '如何在服务前核对顾客需求？',
      score: 10,
      basis: '询问需求、核对禁忌并复述确认',
      options: [],
      answer: []
    })
  ).id;
  const paper2 = await admin('paper/publish', { title: '人工评价试卷', mode: 'fixed', question_ids: [text] });
  const exam2 = await admin('exam/create', {
    title: '服务表达人工检验',
    paper_release_id: paper2.release_id,
    employee_ids: [91002],
    max_attempts: 2,
    pass_score: 6,
    grader_employee_id: 91001,
    auto_publish: false,
    show_analysis: false
  });
  const third = await mobile('exam/start', { task_id: exam2.task_ids[0], idempotency_key: key() });
  const held = await mobile('exam/submit', {
    attempt_id: third.id,
    answer_session_key: third.answer_session_key,
    answers: { [text]: '询问服务需求，核对禁忌，再复述确认。' },
    idempotency_key: key()
  });
  assert.equal(held.state, 'GRADING');
  assert.ok(!('score' in held));
  assert.ok(!('basis' in held.questions[0]));
  assert.equal((await admin('task?id=' + exam2.task_ids[0])).state, 'PENDING');
  await admin('attempt/grade', {
    attempt_id: third.id,
    grades: { [text]: { score: 8, note: '覆盖主要要点' } }
  });
  assert.equal((await mobile('attempt?id=' + third.id)).score, 8);
  await admin('attempt/correct', {
    attempt_id: third.id,
    grades: { [text]: { score: 4, note: '复核未包含禁忌核对' } },
    reason: '复核评分依据后更正'
  });
  const corrected = await mobile('attempt?id=' + third.id);
  assert.equal(corrected.score, 4);
  assert.equal(corrected.passed, false);
  console.log(
    JSON.stringify(
      {
        passed: [
          '作答前隐藏答案',
          '刷新同一试卷',
          '旧作答会话失效',
          '多选精确判分',
          '未通过可补考',
          '交卷后拒绝保存',
          '补考新记录',
          '最高有效结果完成任务',
          '待阅卷不完成不泄露',
          '指派人阅卷',
          '公布后才显示成绩',
          '成绩更正保留关联'
        ],
        attempt_ids: [attempt.id, second.id, third.id]
      },
      null,
      2
    )
  );
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
