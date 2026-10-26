/* 奖励与考评闭环：冻结发放规则、成绩更正冲正/恢复、外部证书和员工异议。 */
const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const { execFileSync } = require('node:child_process');
const base = 'http://127.0.0.1:18115';
const key = () => `records-qa-${randomUUID()}`;
async function raw(path, data, h = {}) {
  return (
    await fetch(base + path, {
      method: data ? 'POST' : 'GET',
      headers: { 'Content-Type': 'application/json', ...h },
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
  const mh = {
    'X-Mobile-Contract-Version': 'mobile-merchant-v1',
    'X-Mobile-Client-Session-Id': key(),
    'X-Mobile-Request-Id': key(),
    'X-Mobile-Platform': 'WEB'
  };
  const root = await raw(
    '/api/mobile/merchant/password-login',
    { account: 'emp91002', pwd: 'GrowthLocal!2026', installationId: key(), idempotencyKey: key() },
    mh
  );
  assert.ok(root.merchantToken, JSON.stringify(root));
  Object.assign(mh, {
    Authorization: 'Bearer ' + root.merchantToken,
    'X-Mobile-Active-Context-Id': root.activeContextId,
    'X-Mobile-State-Context-Id': root.stateContextId
  });
  async function mobile(path, data, error = false) {
    const r = await raw(
      '/api/mobile/merchant/growth/' + path,
      data ? { ...data, idempotency_key: key() } : undefined,
      mh
    );
    if (!error) assert.ok(r.growth, path + JSON.stringify(r));
    return error ? r : r.growth;
  }
  function processEvents() {
    const r = JSON.parse(
      execFileSync(
        'docker',
        ['exec', 'mohe-growth-local-app', 'php', '/workspace/scripts/growth/process-local.php'],
        { encoding: 'utf8' }
      )
    );
    assert.deepEqual(r.failed_event_ids, [], JSON.stringify(r));
    return r;
  }
  const rule = await admin('reward/rule', {
    kind: 'points',
    title: '正式考试通过奖励-' + Date.now(),
    event_type: 'EXAM_PUBLISHED',
    enabled: true,
    require_passed: true,
    points: 17,
    first_only: true,
    max_awards: 1
  });
  const cert = await admin('reward/rule', {
    kind: 'certificate',
    title: '岗位学习认证-' + Date.now(),
    event_type: 'EXAM_PUBLISHED',
    enabled: true,
    require_passed: true,
    valid_days: 30,
    responsible_employee_id: 91001
  });
  const q = await admin('question/save', {
    type: 'text',
    stem: '说明服务前核对流程',
    score: 10,
    basis: '需求与禁忌均需核对'
  });
  const paper = await admin('paper/publish', { title: '奖励闭环试卷', mode: 'fixed', question_ids: [q.id] });
  const exam = await admin('exam/create', {
    title: '档案奖励闭环-' + Date.now(),
    paper_release_id: paper.release_id,
    employee_ids: [91002],
    grader_employee_id: 91001,
    pass_score: 6,
    max_attempts: 1,
    auto_publish: true
  });
  // 修改新规则不能替换已经派发的17积分快照。
  await admin('reward/rule', {
    id: rule.id,
    kind: 'points',
    title: '后续新任务规则',
    event_type: 'EXAM_PUBLISHED',
    enabled: true,
    require_passed: true,
    points: 99,
    first_only: true,
    max_awards: 1
  });
  const a = await mobile('exam/start', { task_id: exam.task_ids[0] });
  await mobile('exam/submit', {
    attempt_id: a.id,
    answer_session_key: a.answer_session_key,
    answers: { [q.id]: '询问需求并核对禁忌' }
  });
  await admin('attempt/grade', {
    attempt_id: a.id,
    grades: { [q.id]: { score: 4, note: '未完整展开要点' } }
  });
  processEvents();
  let profile = await mobile('profile');
  assert.equal(
    profile.point_ledger.filter((r) => Number(r.source_id) === a.id && Number(r.rule_id) === rule.id).length,
    0
  );
  await admin('attempt/correct', {
    attempt_id: a.id,
    grades: { [q.id]: { score: 8, note: '复核包含关键要点' } },
    reason: '依据复核更正为通过'
  });
  processEvents();
  profile = await mobile('profile');
  let ledger = profile.point_ledger.filter(
    (r) => Number(r.source_id) === a.id && Number(r.rule_id) === rule.id
  );
  assert.equal(
    ledger.reduce((n, r) => n + Number(r.delta), 0),
    17
  );
  let certificate = profile.certificates.find(
    (r) => Number(r.source_id) === a.id && Number(r.rule_id) === cert.id
  );
  assert.equal(certificate.state, 'VALID');
  const expiry = certificate.expires_at;
  processEvents();
  assert.equal(
    (await mobile('profile')).point_ledger.filter(
      (r) => Number(r.source_id) === a.id && Number(r.rule_id) === rule.id
    ).length,
    1
  );
  await admin('attempt/correct', {
    attempt_id: a.id,
    grades: { [q.id]: { score: 3, note: '复核缺失必要依据' } },
    reason: '二次核对未达到标准'
  });
  processEvents();
  profile = await mobile('profile');
  ledger = profile.point_ledger.filter((r) => Number(r.source_id) === a.id && Number(r.rule_id) === rule.id);
  assert.equal(
    ledger.reduce((n, r) => n + Number(r.delta), 0),
    0
  );
  assert.equal(profile.certificates.find((r) => Number(r.id) === Number(certificate.id)).state, 'REVOKED');
  await admin('attempt/correct', {
    attempt_id: a.id,
    grades: { [q.id]: { score: 8, note: '完整依据已核实' } },
    reason: '核对原始依据后恢复通过'
  });
  processEvents();
  profile = await mobile('profile');
  assert.equal(
    profile.point_ledger
      .filter((r) => Number(r.source_id) === a.id && Number(r.rule_id) === rule.id)
      .reduce((n, r) => n + Number(r.delta), 0),
    17
  );
  certificate = profile.certificates.find((r) => Number(r.id) === Number(certificate.id));
  assert.equal(certificate.state, 'VALID');
  assert.equal(certificate.expires_at, expiry, '成绩恢复不得偷偷续期');
  await admin('certificate/action', { id: certificate.id, action: 'revoke', reason: '独立资格复核撤销' });
  await admin('attempt/correct', {
    attempt_id: a.id,
    grades: { [q.id]: { score: 9, note: '评分补充依据' } },
    reason: '补充一项正确依据'
  });
  processEvents();
  assert.equal(
    (await mobile('profile')).certificates.find((r) => Number(r.id) === Number(certificate.id)).state,
    'REVOKED',
    '不能自动覆盖人工撤销'
  );
  const standard = await admin('standard/publish', {
    menu: 'profiles',
    title: '阶段服务考评',
    purpose: 'appraisal',
    mode: 'pass',
    items: [{ key: 'service', title: '服务准备规范', required: true, max_score: 10 }]
  });
  const appraisal = await admin('appraisal/create', {
    title: '阶段考评-' + Date.now(),
    employee_id: 91002,
    handler_employee_id: 91001,
    standard_release_id: standard.release_id,
    period_start: '2026-10-01',
    period_end: '2026-10-31'
  });
  assert.equal((await mobile('appraisal?id=' + appraisal.id)).results.length, 0);
  await admin('appraisal/confirm', {
    id: appraisal.id,
    items: { service: { passed: false, note: '还需改进操作顺序' } },
    reason: '现场观察记录',
    next_steps: '加强服务准备练习'
  });
  const o = await mobile('appraisal/object', { id: appraisal.id, body: '请核对当日操作记录' });
  await admin('appraisal/confirm', {
    id: appraisal.id,
    items: { service: { passed: true, note: '核对当日证据符合标准' } },
    reason: '依据员工异议复核更正'
  });
  await admin('objection/resolve', { objection_id: o.id, resolution: '已核对并保存更正结果，原结果保留' });
  const result = await mobile('appraisal?id=' + appraisal.id);
  assert.equal(result.results.length, 2);
  assert.equal(result.objections[0].state, 'RESOLVED');
  assert.ok(Number(result.results[0].previous_result_id) > 0);
  assert.equal((await admin('task?id=' + appraisal.task_id)).state, 'COMPLETED');
  console.log(
    JSON.stringify(
      {
        passed: [
          '派发规则冻结',
          '失败不奖励',
          '更正通过首次奖励',
          '重复消费不重复奖励',
          '更正失败追加积分冲正',
          '证书自动撤销',
          '恢复原积分与原证书有效期',
          '人工撤销不被自动恢复',
          '员工考评只读',
          '员工异议',
          '原负责人更正',
          '两份考评结果保留',
          '异议处理闭环'
        ],
        attempt_id: a.id,
        appraisal_id: appraisal.id
      },
      null,
      2
    )
  );
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
