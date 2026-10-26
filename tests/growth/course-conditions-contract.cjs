/* 课程复合闭环：随堂题、课后人工成绩、课程奖励冲正与同内容有效结果引用。 */
const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const { execFileSync } = require('node:child_process');
const base = 'http://127.0.0.1:18115';
const key = () => `course-conditions-${randomUUID()}`;
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
  async function admin(p, d) {
    const r = await raw('/adminapi/growth/' + p, d ? { ...d, idempotency_key: key() } : undefined, h);
    assert.equal(r.status, 200, p + ': ' + r.msg);
    return r.data;
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
  async function mobile(p, d, fail = false) {
    const r = await raw(
      '/api/mobile/merchant/growth/' + p,
      d ? { ...d, idempotency_key: key() } : undefined,
      mh
    );
    if (!fail) assert.ok(r.growth, p + ': ' + JSON.stringify(r));
    return fail ? r : r.growth;
  }
  function tick() {
    const r = JSON.parse(
      execFileSync(
        'docker',
        ['exec', 'mohe-growth-local-app', 'php', '/workspace/scripts/growth/process-local.php'],
        { encoding: 'utf8' }
      )
    );
    assert.deepEqual(r.failed_event_ids, []);
  }
  const inline = await admin('question/save', {
    type: 'order',
    stem: '请依次完成标准准备流程',
    options: ['核对需求', '准备用品', '清洁双手'],
    answer: [2, 0, 1],
    score: 10
  });
  const short = await admin('question/save', {
    type: 'text',
    stem: '说明禁忌核对的内容',
    score: 10,
    basis: '核对过敏、近期护理和当前状态'
  });
  const paper = await admin('paper/publish', {
    title: '课程后测验证',
    mode: 'fixed',
    question_ids: [short.id]
  });
  const exam = await admin('exam/create', {
    title: '课程课后检验-' + Date.now(),
    paper_release_id: paper.release_id,
    employee_ids: [91001],
    grader_employee_id: 91001,
    pass_score: 6,
    auto_publish: true,
    max_attempts: 2
  });
  const reward = await admin('reward/rule', {
    kind: 'points',
    title: '课程条件验证奖励',
    event_type: 'TASK_COMPLETED',
    enabled: true,
    require_passed: true,
    points: 13,
    first_only: true,
    max_awards: 1
  });
  const body = {
    title: '随堂与课后完整课程-' + Date.now(),
    chapters: [
      {
        key: 'main',
        title: '服务前准备',
        type: 'text',
        body: '清洁双手，核对需求，准备用品。',
        quizzes: [
          {
            key: 'quiz',
            question_id: inline.id,
            position: 0,
            required: true,
            wrong_policy: 'retry',
            analysis_timing: 'after_pass'
          }
        ]
      }
    ],
    course_rules: {
      post_exam_id: exam.id,
      require_exam_passed: true,
      allow_result_reference: true,
      reference_days: 30
    }
  };
  const content = await admin('content/save', body);
  const pub = await admin('content/publish', { ...body, id: content.id });
  const assignment = await admin('task/assign', {
    publication_id: pub.publication_id,
    employee_ids: [91002]
  });
  const taskId = assignment.task_ids[0];
  let task = await mobile('task?id=' + taskId);
  assert.equal(task.dependencies.length, 1);
  assert.ok(!('answer' in task.content.chapters[0].quizzes[0].question));
  const child = task.dependencies[0].id;
  assert.ok(!(await mobile('exam/start', { task_id: child }, true)).growth, '未学完不能先考');
  const session = key();
  let learned = await mobile('learn', {
    task_id: taskId,
    chapter_key: 'main',
    session_key: session,
    session_open: true,
    confirm: true,
    position: 0
  });
  assert.equal(learned.chapter_complete, false, '未答必答题不能确认章节');
  const wrong = await mobile('inline/answer', {
    task_id: taskId,
    chapter_key: 'main',
    quiz_key: 'quiz',
    answers: [0, 2, 1]
  });
  assert.equal(wrong.passed, false);
  assert.ok(!('reference_answer' in wrong));
  const correct = await mobile('inline/answer', {
    task_id: taskId,
    chapter_key: 'main',
    quiz_key: 'quiz',
    answers: [2, 0, 1]
  });
  assert.equal(correct.passed, true);
  assert.deepEqual(correct.reference_answer, [2, 0, 1]);
  learned = await mobile('learn', {
    task_id: taskId,
    chapter_key: 'main',
    session_key: session,
    confirm: true,
    position: 0
  });
  assert.equal(learned.learning_complete, true);
  assert.equal(learned.state, 'IN_PROGRESS', '学完不等于课后已通过');
  const a = await mobile('exam/start', { task_id: child });
  await mobile('exam/submit', {
    attempt_id: a.id,
    answer_session_key: a.answer_session_key,
    answers: { [short.id]: '核对过敏、近期护理和当前状态' }
  });
  assert.equal((await mobile('task?id=' + taskId)).state, 'IN_PROGRESS', '待阅卷不算完成');
  await admin('attempt/grade', {
    attempt_id: a.id,
    grades: { [short.id]: { score: 8, note: '覆盖关键要点' } }
  });
  assert.equal((await mobile('task?id=' + taskId)).state, 'COMPLETED');
  tick();
  function net(p) {
    return p.point_ledger
      .filter(
        (r) =>
          r.source_type === 'learning' && Number(r.source_id) === taskId && Number(r.rule_id) === reward.id
      )
      .reduce((n, r) => n + Number(r.delta), 0);
  }
  assert.equal(net(await mobile('profile')), 13);
  await admin('attempt/correct', {
    attempt_id: a.id,
    grades: { [short.id]: { score: 3, note: '复核依据不足' } },
    reason: '更正课后成绩'
  });
  assert.equal((await mobile('task?id=' + taskId)).state, 'IN_PROGRESS');
  tick();
  assert.equal(net(await mobile('profile')), 0);
  await admin('attempt/correct', {
    attempt_id: a.id,
    grades: { [short.id]: { score: 8, note: '完整原始依据核对通过' } },
    reason: '复核恢复成绩'
  });
  assert.equal((await mobile('task?id=' + taskId)).state, 'COMPLETED');
  tick();
  assert.equal(net(await mobile('profile')), 13);
  const second = await admin('task/assign', { publication_id: pub.publication_id, employee_ids: [91002] });
  const secondId = second.task_ids[0];
  task = await mobile('task?id=' + secondId);
  assert.ok(task.reference_candidates.some((c) => c.id === taskId));
  await mobile('result/reference', { task_id: secondId, source_task_id: taskId });
  task = await mobile('task?id=' + secondId);
  assert.equal(task.state, 'COMPLETED');
  assert.equal(task.result_type, 'reference');
  assert.equal(Number(task.reference.source_task_id), taskId);
  assert.equal(task.dependencies.length, 0);
  tick();
  assert.equal(
    (await mobile('profile')).point_ledger.filter(
      (r) => r.source_type === 'reference' && Number(r.source_id) === Number(task.result_id)
    ).length,
    0,
    '引用不重复奖励'
  );
  console.log(
    JSON.stringify(
      {
        passed: [
          '随堂题冻结统一题库',
          '作答前隐藏答案',
          '未答必答题不完成',
          '错序题不得分',
          '答对后才展示解析',
          '先学完再课后检验',
          '学完与检验状态分离',
          '待阅卷不能完成',
          '课后通过自动完成课程',
          '课程奖励只发一次',
          '成绩更正回退课程',
          '课程积分冲正',
          '恢复成绩恢复课程与净积分',
          '相同发布内容有效结果引用',
          '引用不重复生成考试和奖励'
        ],
        task_id: taskId,
        reference_task_id: secondId
      },
      null,
      2
    )
  );
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
