/* 巡检整改真实HTTP：上传有效证据，整改提交不关闭，复查退回与关闭独立记录。 */
const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const base = 'http://127.0.0.1:18115';
const key = () => `inspect-qa-${randomUUID()}`;
async function raw(path, data, headers = {}) {
  return (
    await fetch(base + path, {
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
  async function mobile(path, data) {
    const r = await raw('/api/mobile/merchant/growth/' + path, data, headers);
    assert.ok(r.growth, path + ': ' + JSON.stringify(r));
    return r.growth;
  }
  async function upload(path, headers, menu) {
    const f = new FormData();
    f.append(
      'file',
      new Blob(
        [
          Buffer.from(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jC1sAAAAASUVORK5CYII=',
            'base64'
          )
        ],
        { type: 'image/png' }
      ),
      '现场证据.png'
    );
    if (menu) f.append('menu', menu);
    const r = await (await fetch(base + path, { method: 'POST', headers, body: f })).json();
    const data = r.growth || r.data;
    assert.ok(data, JSON.stringify(r));
    assert.equal(data.state, 'READY', JSON.stringify(r));
    return Number(data.id);
  }
  const proof = await upload('/adminapi/growth/asset/upload', h, 'inspection');
  const standard = await admin('standard/publish', {
    menu: 'inspection',
    title: '门店卫生巡检标准',
    purpose: 'inspection',
    mode: 'pass',
    items: [{ key: 'clean', title: '操作区域卫生', required: true, evidence_required: true, max_score: 10 }]
  });
  const inspection = await admin('inspection/create', {
    title: '门店现场巡检-' + Date.now(),
    store_id: 91001,
    executor_employee_id: 91001,
    reviewer_employee_id: 91001,
    standard_release_id: standard.release_id
  });
  const submitted = await admin('inspection/submit', {
    id: inspection.id,
    items: { clean: { passed: false, note: '台面物品摆放不规范', asset_ids: [proof] } },
    problems: [
      {
        item_key: 'clean',
        description: '整理台面并消毒后重新取证',
        responsible_employee_id: 91002,
        reviewer_employee_id: 91001,
        due_at: Math.floor(Date.now() / 1000) + 86400,
        asset_ids: [proof]
      }
    ]
  });
  assert.equal(submitted.issue_ids.length, 1);
  const issue = submitted.issue_ids[0];
  await admin('inspection/confirm', { id: inspection.id, note: '已核对现场检查记录' });
  assert.equal((await admin('issue?id=' + issue)).issue.state, 'OPEN', '巡检复核不能关闭整改');
  const newProof = await upload('/api/mobile/merchant/growth/asset/upload', headers);
  const rect = await mobile('issue/rectify', {
    issue_id: issue,
    body: '已整理并完成消毒',
    asset_ids: [newProof],
    idempotency_key: key()
  });
  assert.equal(rect.state, 'PENDING_REVIEW');
  assert.equal((await admin('issue?id=' + issue)).issue.state, 'PENDING_REVIEW');
  await admin('issue/review', { issue_id: issue, passed: false, note: '消毒证据不够完整，请补充' });
  assert.equal((await admin('issue?id=' + issue)).issue.state, 'OPEN');
  await mobile('issue/rectify', {
    issue_id: issue,
    body: '补充完整消毒过程证据',
    asset_ids: [newProof],
    idempotency_key: key()
  });
  await admin('issue/review', { issue_id: issue, passed: true, note: '复查达到要求，确认关闭' });
  const done = await admin('issue?id=' + issue);
  assert.equal(done.issue.state, 'CLOSED');
  assert.equal(done.rectifications.length, 2);
  assert.equal(done.reviews.length, 2);
  assert.equal((await admin('task?id=' + done.issue.task_id)).state, 'COMPLETED');
  const duplicate = await admin('issue/review', { issue_id: issue, passed: true, note: '重复关闭' }, true);
  assert.notEqual(duplicate.status, 200);
  const media = await fetch(
    base + `/api/mobile/merchant/growth/asset?id=${newProof}&task_id=${done.issue.task_id}`,
    { headers }
  );
  assert.equal(media.status, 200);
  assert.ok(media.headers.get('content-type').startsWith('image/'), '复查所需证据必须可读');
  console.log(
    JSON.stringify(
      {
        passed: [
          '真实门店巡检',
          '有效证据上传',
          '检查与问题分开',
          '巡检复核不关闭整改',
          '整改后待复查',
          '复查退回',
          '补交保留原整改',
          '复查通过关闭',
          '重复关闭拒绝',
          '整改任务读回完成',
          '授权证据读取'
        ],
        inspection_id: inspection.id,
        issue_id: issue
      },
      null,
      2
    )
  );
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
