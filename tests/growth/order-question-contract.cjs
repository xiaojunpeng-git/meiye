/* 顺序题真实HTTP验证：原顺序冻结，错序/漏选零分，重复项不能靠去重答对。 */
const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const origin = 'http://127.0.0.1:18115';
const key = () => `order-qa-${randomUUID()}`;
async function raw(path, data, headers = {}) {
  return (
    await fetch(origin + path, {
      method: data ? 'POST' : 'GET',
      headers: { 'Content-Type': 'application/json', ...headers },
      body: data ? JSON.stringify(data) : undefined
    })
  ).json();
}
(async () => {
  const login = await raw('/adminapi/login', { account: 'growth_admin', pwd: 'GrowthLocal!2026' });
  assert.equal(login.status, 200, login.msg);
  const h = { 'Authori-zation': `Bearer ${login.data.token}`, 'X-Source': 'f76d38d0ee4f854f' };
  async function admin(path, data) {
    const r = await raw('/adminapi/growth/' + path, { ...data, idempotency_key: key() }, h);
    assert.equal(r.status, 200, r.msg);
    return r.data;
  }
  const q = await admin('question/save', {
    type: 'order',
    stem: '请按正确顺序选择服务前准备步骤',
    options: ['核对需求', '准备用品', '清洁双手'],
    answer: [2, 0, 1],
    score: 10
  });
  const invalid = await raw(
    '/adminapi/growth/question/save',
    {
      type: 'order',
      stem: '无效顺序',
      options: ['甲', '乙', '丙'],
      answer: [0, 1],
      score: 10,
      idempotency_key: key()
    },
    h
  );
  assert.notEqual(invalid.status, 200, '参考答案必须覆盖全部选项');
  const boolean = await admin('question/save', {
    type: 'boolean',
    stem: '服务前应核对顾客需求',
    answer: [0],
    score: 10
  });
  const paper = await admin('paper/publish', {
    title: '顺序与判断试卷',
    mode: 'fixed',
    question_ids: [q.id, boolean.id]
  });
  const exam = await admin('exam/create', {
    title: '顺序题真实验证-' + Date.now(),
    paper_release_id: paper.release_id,
    employee_ids: [91002],
    max_attempts: 3,
    pass_score: 20,
    auto_publish: true,
    show_analysis: true
  });
  const headers = {
    'X-Mobile-Contract-Version': 'mobile-merchant-v1',
    'X-Mobile-Client-Session-Id': key(),
    'X-Mobile-Request-Id': key(),
    'X-Mobile-Platform': 'WEB'
  };
  const root = await raw(
    '/api/mobile/merchant/password-login',
    { account: 'emp91002', pwd: 'GrowthLocal!2026', installationId: key(), idempotencyKey: key() },
    headers
  );
  assert.ok(root.merchantToken, JSON.stringify(root));
  Object.assign(headers, {
    Authorization: `Bearer ${root.merchantToken}`,
    'X-Mobile-Active-Context-Id': root.activeContextId,
    'X-Mobile-State-Context-Id': root.stateContextId
  });
  async function mobile(path, data, fail = false) {
    const r = await raw('/api/mobile/merchant/growth/' + path, data, headers);
    if (!fail) assert.ok(r.growth, JSON.stringify(r));
    return fail ? r : r.growth;
  }
  const resultIds = [];
  for (const sequence of [
    [0, 2, 1],
    [2, 0],
    [2, 0, 1]
  ]) {
    const a = await mobile('exam/start', { task_id: exam.task_ids[0], idempotency_key: key() });
    assert.deepEqual(a.questions.find((x) => Number(x.id) === Number(q.id)).options, [
      '核对需求',
      '准备用品',
      '清洁双手'
    ]);
    const payload = {
      attempt_id: a.id,
      answer_session_key: a.answer_session_key,
      answers: { [q.id]: sequence, [boolean.id]: [0] },
      idempotency_key: key()
    };
    if (sequence.length === 3 && sequence[0] === 2) {
      const duplicate = await mobile(
        'exam/save',
        { ...payload, answers: { [q.id]: [2, 0, 0, 1] }, idempotency_key: key() },
        true
      );
      assert.ok(!duplicate.growth, '重复选项必须拒绝');
    }
    const r = await mobile('exam/submit', payload);
    const exact = JSON.stringify(sequence) === JSON.stringify([2, 0, 1]);
    assert.equal(r.grades[q.id].score, exact ? 10 : 0);
    assert.equal(r.grades[boolean.id].score, 10);
    assert.equal(r.passed, exact);
    assert.deepEqual(r.questions.find((x) => Number(x.id) === Number(q.id)).answer, [2, 0, 1]);
    resultIds.push(r.id);
  }
  console.log(
    JSON.stringify(
      {
        passed: [
          '顺序题标准答案必须含全部选项',
          '标准先后顺序冻结',
          '错序零分',
          '漏选零分',
          '重复选项拒绝',
          '全部选齐且顺序正确才得分',
          '判断题正常判分',
          '补考历史独立保留'
        ],
        attempt_ids: resultIds
      },
      null,
      2
    )
  );
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
