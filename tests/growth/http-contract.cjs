/* MYDX真实HTTP与数据库闭环：本地隔离数据，正式结果不能凭页面状态伪造。 */
const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const origin = process.env.GROWTH_ORIGIN || 'http://127.0.0.1:18115';
if (!/^http:\/\/(127\.0\.0\.1|localhost):18115$/.test(origin)) throw new Error('只允许隔离本地环境');
const key = () => `qa-${randomUUID()}`;
async function raw(path, body, headers = {}) {
  const r = await fetch(origin + path, {
    method: body ? 'POST' : 'GET',
    headers: { 'Content-Type': 'application/json', ...headers },
    body: body ? JSON.stringify(body) : undefined
  });
  return r.json();
}
async function main() {
  const login = await raw('/adminapi/login', { account: 'growth_admin', pwd: 'GrowthLocal!2026' });
  assert.equal(login.status, 200, login.msg);
  const auth = { 'Authori-zation': `Bearer ${login.data.token}`, 'X-Source': 'f76d38d0ee4f854f' };
  async function admin(path, body) {
    const r = await raw('/adminapi/growth/' + path, body, auth);
    assert.equal(r.status, 200, `${path}: ${r.msg}`);
    return r.data;
  }
  const suffix = Date.now();
  const content = {
    title: `HTTP闭环课程-${suffix}`,
    kind: 'course',
    category: '本地验证',
    description: '快照与阅读验证',
    chapters: [
      {
        key: 'basic',
        title: '服务准备',
        type: 'text',
        body: '清洁双手，核对顾客需求，准备服务用品。',
        required: true,
        completion: 'confirm'
      }
    ]
  };
  const created = await admin('content/save', { ...content, idempotency_key: key() });
  const id = Number(created.id);
  const saveA = { ...content, id, title: '草稿A-' + suffix, idempotency_key: key() };
  const saveB = { ...content, id, title: '草稿B-' + suffix, idempotency_key: key() };
  await admin('content/save', saveA);
  await admin('content/save', saveB);
  await admin('content/save', saveA);
  assert.equal((await admin('content?id=' + id)).draft.title, saveB.title, '旧幂等重试不能覆盖较新草稿');
  const published = await admin('content/publish', { ...content, id, idempotency_key: key() });
  await admin('content/save', {
    ...content,
    id,
    chapters: [{ ...content.chapters[0], body: '后续草稿内容' }],
    idempotency_key: key()
  });
  const assignment = {
    publication_id: published.publication_id,
    employee_ids: [91002, 91003],
    title: content.title,
    idempotency_key: key()
  };
  const assigned = await admin('task/assign', assignment);
  assert.equal(assigned.assigned_count, 2);
  assert.deepEqual((await admin('task/assign', assignment)).task_ids, assigned.task_ids);
  async function merchant(employee) {
    const client = key();
    const headers = {
      'X-Mobile-Contract-Version': 'mobile-merchant-v1',
      'X-Mobile-Client-Session-Id': client,
      'X-Mobile-Platform': 'WEB',
      'X-Mobile-Request-Id': key()
    };
    const root = await raw(
      '/api/mobile/merchant/password-login',
      { account: `emp${employee}`, pwd: 'GrowthLocal!2026', installationId: key(), idempotencyKey: key() },
      headers
    );
    assert.ok(root.merchantToken, `员工${employee}登录失败: ${JSON.stringify(root)}`);
    const session = {
      ...headers,
      Authorization: `Bearer ${root.merchantToken}`,
      'X-Mobile-Active-Context-Id': root.activeContextId,
      'X-Mobile-State-Context-Id': root.stateContextId
    };
    return {
      root,
      raw: (path, body) => raw('/api/mobile/merchant/growth/' + path, body, session),
      async call(path, body) {
        const r = await this.raw(path, body);
        assert.ok(r.growth, `${path}: ${JSON.stringify(r)}`);
        return r.growth;
      }
    };
  }
  const student = await merchant(91002);
  const orgStudent = await merchant(91003);
  assert.equal(Number(orgStudent.root.activeContext.storeId), 0, '组织员工不能借用门店');
  const studentTasks = (await student.call('tasks')).rows;
  const task = studentTasks.find((t) => t.title === content.title);
  assert.ok(task);
  const detail = await student.call('task?id=' + task.id);
  assert.equal(detail.content.chapters[0].body, content.chapters[0].body, '发布后修改草稿不得替换历史任务');
  const orgTask = (await orgStudent.call('tasks')).rows.find((t) => t.title === content.title);
  assert.ok(orgTask);
  const forbidden = await student.raw('task?id=' + orgTask.id);
  assert.ok(!forbidden.growth, '不能查看其他员工任务');
  const learn = {
    task_id: task.id,
    chapter_key: 'basic',
    session_key: key(),
    position: 0,
    confirm: true,
    idempotency_key: key()
  };
  const result = await student.call('learn', learn);
  assert.equal(result.state, 'COMPLETED');
  assert.equal((await student.call('learn', learn)).state, 'COMPLETED');
  assert.equal((await admin('task?id=' + task.id)).state, 'COMPLETED', '管理端须读回正式完成');
  const again = await student.raw('learn', { ...learn, idempotency_key: key() });
  assert.ok(!again.growth, '换标识也不得覆盖完成结果');
  await orgStudent.call('learn', {
    ...learn,
    task_id: orgTask.id,
    session_key: key(),
    idempotency_key: key()
  });
  console.log(
    JSON.stringify(
      {
        passed: [
          '平台真实登录',
          '草稿最后保存覆盖',
          '旧请求重试不覆盖',
          '发布完整快照',
          '固定名单与分配去重',
          '门店员工学习',
          '无店员工学习',
          '禁止跨员工读取',
          '阅读确认与正式完成',
          '重复正式提交不生效',
          '管理端结果读回'
        ],
        course_id: id,
        task_ids: assigned.task_ids
      },
      null,
      2
    )
  );
}
main().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
