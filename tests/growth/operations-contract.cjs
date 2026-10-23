/* 站内恢复：首次任职/改名/复职区分、入职去重、超时仅用服务器保存答案、知识收藏和人工答疑。 */
const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const { execFileSync } = require('node:child_process');
const base = 'http://127.0.0.1:18115';
const key = () => `ops-${randomUUID()}`;
async function raw(p, d, h = {}) {
  return (
    await fetch(base + p, {
      method: d ? 'POST' : 'GET',
      headers: { 'Content-Type': 'application/json', ...h },
      body: d ? JSON.stringify(d) : undefined
    })
  ).json();
}
(async () => {
  const l = await raw('/adminapi/login', { account: 'growth_admin', pwd: 'GrowthLocal!2026' });
  assert.equal(l.status, 200);
  const h = { 'Authori-zation': 'Bearer ' + l.data.token, 'X-Source': 'f76d38d0ee4f854f' };
  async function admin(p, d, fail = false) {
    const r = await raw('/adminapi/growth/' + p, d ? { ...d, idempotency_key: key() } : undefined, h);
    if (!fail) assert.equal(r.status, 200, p + ': ' + r.msg);
    return fail ? r : r.data;
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
  async function mobile(p, d) {
    const r = await raw(
      '/api/mobile/merchant/growth/' + p,
      d ? { ...d, idempotency_key: key() } : undefined,
      mh
    );
    assert.ok(r.growth, p + ': ' + JSON.stringify(r));
    return r.growth;
  }
  function fixture(d) {
    return JSON.parse(
      execFileSync(
        'docker',
        ['exec', '-i', 'mohe-growth-local-app', 'php', '/workspace/tests/growth/operations-fixtures.php'],
        { input: JSON.stringify(d), encoding: 'utf8' }
      )
    );
  }
  function tick() {
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
  const content = {
    title: '知识与入职流程测试-' + Date.now(),
    kind: 'knowledge',
    chapters: [{ key: 'text', title: '服务准备', type: 'text', body: '先核对需求与禁忌，再完成服务准备。' }]
  };
  const draft = await admin('content/save', content);
  const pub = await admin('content/publish', { ...content, id: draft.id });
  const knowledge = await mobile('knowledge?keyword=' + encodeURIComponent(content.title));
  assert.equal(knowledge.total, 1);
  await mobile('knowledge/favorite', { id: draft.id, favorite: true });
  assert.ok((await mobile('knowledge?favorite=1')).rows.some((x) => Number(x.id) === draft.id));
  const opened = await mobile('knowledge/open', { id: draft.id });
  assert.equal((await mobile('knowledge/open', { id: draft.id })).task_id, opened.task_id);
  await mobile('learn', {
    task_id: opened.task_id,
    chapter_key: 'text',
    position: 0,
    confirm: true,
    session_key: key(),
    session_open: true
  });
  const question = await mobile('help/ask', {
    body: '请说明禁忌核对的注意事项',
    handler_employee_id: 91001,
    task_id: opened.task_id
  });
  await admin('help/reply', { id: question.id, body: '先询问过敏与近期护理情况，再按服务标准确认。' });
  const answer = (await mobile('help')).rows.find((x) => Number(x.id) === question.id);
  assert.equal(answer.state, 'ANSWERED');
  assert.equal(answer.replies.length, 1);
  await admin('help/reply', { id: question.id, body: '转交带教师傅补充专业说明', transfer_to: 91005 });
  const unauthorized = await admin('help/reply', { id: question.id, body: '原负责人重复回复' }, true);
  assert.notEqual(unauthorized.status, 200);
  const promoted = await admin('help/promote', {
    question_id: question.id,
    title: '禁忌核对答疑整理',
    body: '先询问过敏与近期护理情况。'
  });
  assert.equal((await admin('content?id=' + promoted.id)).state, 'DRAFT');
  const map = await admin('map/publish', {
    title: '新人入职测试地图',
    sequential: true,
    stages: [
      {
        title: '入职基础',
        activities: [{ kind: 'course', source_id: pub.publication_id, title: '服务准备', required: true }]
      }
    ]
  });
  const rule = await admin('onboarding/save', {
    title: '首次任职自动培养测试',
    enabled: true,
    store_id: 91001,
    position_id: 91001,
    map_release_id: map.release_id,
    relative_days: 10,
    responsible_employee_id: 91001
  });
  const before = (await admin('onboarding')).dispatches.filter((x) => Number(x.rule_id) === rule.id).length;
  assert.equal(before, 0, '老员工不补发');
  const hire = fixture({ mode: 'hire' });
  tick();
  let dispatch = (await admin('onboarding')).dispatches.filter((x) => Number(x.rule_id) === rule.id);
  assert.equal(dispatch.length, 1);
  assert.equal(dispatch[0].state, 'DONE', JSON.stringify(dispatch));
  const plan = dispatch[0].plan_id;
  tick();
  assert.equal((await admin('onboarding')).dispatches.filter((x) => Number(x.rule_id) === rule.id).length, 1);
  fixture({ mode: 'rename', employee_id: hire.employee_id });
  tick();
  fixture({ mode: 'resume', job_id: hire.job_id });
  tick();
  dispatch = (await admin('onboarding')).dispatches.filter((x) => Number(x.rule_id) === rule.id);
  assert.equal(dispatch.length, 1);
  assert.equal(dispatch[0].plan_id, plan);
  const q = await admin('question/save', {
    type: 'single',
    stem: '服务前需要核对需求吗？',
    options: ['需要', '不需要'],
    answer: [0],
    score: 10
  });
  const paper = await admin('paper/publish', {
    title: '定时器验证试卷',
    mode: 'fixed',
    question_ids: [q.id]
  });
  const exam = await admin('exam/create', {
    title: '定时器测试-' + Date.now(),
    paper_release_id: paper.release_id,
    employee_ids: [91002],
    max_attempts: 1,
    pass_score: 6,
    duration_minutes: 1,
    auto_publish: true
  });
  const a = await mobile('exam/start', { task_id: exam.task_ids[0] });
  await mobile('exam/save', {
    attempt_id: a.id,
    answer_session_key: a.answer_session_key,
    answers: { [q.id]: [0] }
  });
  fixture({ mode: 'timeout', attempt_id: a.id });
  assert.ok(tick().expired_attempts >= 1);
  const result = await mobile('attempt?id=' + a.id);
  assert.equal(result.state, 'PUBLISHED');
  assert.equal(result.score, 10);
  assert.equal(result.passed, true);
  assert.equal(tick().expired_attempts, 0);
  const counts = fixture({ mode: 'message_count', task_id: exam.task_ids[0] });
  tick();
  assert.equal(
    fixture({ mode: 'message_count', task_id: exam.task_ids[0] }).count,
    counts.count,
    '重复消费不能重复发消息'
  );
  console.log(
    JSON.stringify(
      {
        passed: [
          '知识搜索',
          '本人收藏',
          '同一知识查阅任务去重',
          '知识阅读完成',
          '人工答疑回复',
          '转交后原负责人不能处理',
          '问答整理为未发布草稿',
          '规则不补发老员工',
          '首次任职自动派发',
          '入职重复消费去重',
          '改名不派发',
          '复职不冒充入职',
          '后台超时冻结保存答案',
          '超时结果只生成一次',
          '站内消息不重复'
        ],
        rule_id: rule.id,
        new_employee_id: hire.employee_id,
        attempt_id: a.id
      },
      null,
      2
    )
  );
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
