/* 实操与带教真实HTTP：关键项不能绕过，补交不覆盖，负责人逐人确认活动。 */
const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const origin = 'http://127.0.0.1:18115';
const key = () => `work-qa-${randomUUID()}`;
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
  async function admin(path, data, error = false) {
    const r = await raw(
      '/adminapi/growth/' + path,
      data ? { ...data, idempotency_key: key() } : undefined,
      h
    );
    if (!error) assert.equal(r.status, 200, path + ': ' + r.msg);
    return error ? r : r.data;
  }
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
  async function mobile(path, data, error = false) {
    const r = await raw('/api/mobile/merchant/growth/' + path, data, headers);
    if (!error) assert.ok(r.growth, JSON.stringify(r));
    return error ? r : r.growth;
  }
  const standard = await admin('standard/publish', {
    title: '护理安全实操标准',
    purpose: 'evaluation',
    mode: 'score',
    calculation: 'sum',
    pass_score: 60,
    items: [
      { key: 'safety', title: '安全与禁忌核对', max_score: 40, required: true, critical: true },
      { key: 'technique', title: '护理操作规范', max_score: 60, required: true }
    ]
  });
  const activity = await admin('activity/create', {
    kind: 'evaluation',
    title: '护理实操鉴定-' + Date.now(),
    description: '提交实操过程后由师傅鉴定',
    standard_release_id: standard.release_id,
    employee_ids: [91002],
    handler_employee_id: 91001,
    max_resubmissions: 2
  });
  const task = activity.task_ids[0];
  const first = await mobile('work/submit', {
    task_id: task,
    body: '已完成第一轮护理操作练习',
    asset_ids: [],
    idempotency_key: key()
  });
  assert.equal(first.state, 'PENDING');
  const invalid = await admin(
    'work/review',
    {
      submission_id: first.id,
      note: '不能把关键项设为不适用',
      items: {
        safety: { not_applicable: true, reason: '不想评价' },
        technique: { score: 60, note: '操作完成' }
      }
    },
    true
  );
  assert.notEqual(invalid.status, 200);
  await admin('work/review', {
    submission_id: first.id,
    note: '安全项未达到满分，安排补练',
    items: { safety: { score: 30, note: '禁忌核对遗漏' }, technique: { score: 60, note: '操作规范' } }
  });
  assert.equal((await admin('task?id=' + task)).state, 'RETURNED', '即使90分，关键项失败仍需补练');
  const second = await mobile('work/submit', {
    task_id: task,
    body: '补练后完整核对禁忌并确认需求',
    asset_ids: [],
    idempotency_key: key()
  });
  assert.notEqual(second.id, first.id);
  await admin('work/review', {
    submission_id: second.id,
    note: '复鉴达标',
    items: { safety: { score: 40, note: '安全要点完整' }, technique: { score: 60, note: '操作达标' } }
  });
  const done = await admin('task?id=' + task);
  assert.equal(done.state, 'COMPLETED');
  assert.equal(done.conclusion, 'QUALIFIED');
  const history = await mobile('work?task_id=' + task);
  assert.equal(history.submissions.length, 2);
  assert.equal(history.evaluations.length, 2);
  assert.equal(history.evaluations[1].conclusion, 'NEED_PRACTICE');
  const work = await admin('activity/create', {
    kind: 'assignment',
    title: '护理练习打卡',
    employee_ids: [91002],
    handler_employee_id: 91001,
    required_submissions: 2,
    max_resubmissions: 1
  });
  for (let i = 0; i < 2; i++) {
    const s = await mobile('work/submit', {
      task_id: work.task_ids[0],
      body: '第' + (i + 1) + '次练习记录',
      asset_ids: [],
      idempotency_key: key()
    });
    await admin('work/review', { submission_id: s.id, note: '本次记录有效', accepted: true });
    assert.equal((await admin('task?id=' + work.task_ids[0])).state, i === 0 ? 'IN_PROGRESS' : 'COMPLETED');
  }
  const coaching = await admin('activity/create', {
    kind: 'coaching',
    title: '服务表达带教',
    employee_ids: [91002],
    handler_employee_id: 91001
  });
  const denied = await mobile(
    'activity/confirm',
    { task_id: coaching.task_ids[0], body: '学员伪造带教', idempotency_key: key() },
    true
  );
  assert.ok(!denied.growth);
  await admin('activity/confirm', {
    task_id: coaching.task_ids[0],
    body: '现场指导需求确认与复述',
    next_steps: '下次练习完整服务流程'
  });
  assert.equal((await admin('task?id=' + coaching.task_ids[0])).state, 'COMPLETED');
  const offline = await admin('activity/create', {
    kind: 'offline',
    title: '门店服务培训',
    employee_ids: [91002],
    handler_employee_id: 91001,
    location: '测试培训室'
  });
  await admin('activity/confirm', { task_id: offline.task_ids[0], body: '尚未到场', attended: false });
  assert.equal((await admin('task?id=' + offline.task_ids[0])).state, 'IN_PROGRESS');
  await admin('activity/confirm', {
    task_id: offline.task_ids[0],
    body: '后续补训已实际到场',
    attended: true
  });
  assert.equal((await admin('task?id=' + offline.task_ids[0])).state, 'COMPLETED');
  console.log(
    JSON.stringify(
      {
        passed: [
          '关键项不允许不适用',
          '高总分不能绕过关键项',
          '补交与复鉴独立保留',
          '待点评不能完成',
          '按要求次数完成打卡',
          '禁止学员伪造师傅确认',
          '带教实名记录',
          '未到场不完成活动',
          '逐人到场确认'
        ],
        task_ids: [task, work.task_ids[0], coaching.task_ids[0], offline.task_ids[0]]
      },
      null,
      2
    )
  );
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
