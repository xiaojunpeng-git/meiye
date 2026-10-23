/* 等级按有效独立流水变化；提醒同窗口去重，结束任务停止催办。 */
const { admin, mobile, assert, key } = require('./local-client.cjs');
const { execFileSync } = require('node:child_process');
const tick = () => JSON.parse(execFileSync('docker', ['exec', 'mohe-growth-local-app', 'php', '/workspace/scripts/growth/process-local.php'], { encoding: 'utf8' }));
(async () => {
  const a = await admin(), m = await mobile('emp91003'), original = await a.call('settings');
  const rules = await a.call('rewards'), levels = rules.levels.filter(r => Number(r.enabled)).map(({ title, threshold }) => ({ title, threshold: Number(threshold) }));
  let delta = 0;
  try {
    const before = await m.call('profile'), balance = Number(before.point_balance);
    await a.call('reward/levels', { levels: [{ title: '测试基础等级', threshold: 0 }, { title: '测试进阶等级', threshold: Math.max(0, balance) + 10 }] });
    const awarded = await a.call('points/adjust', { employee_id: 91003, delta: 20, reason: '独立等级边界验证', basis: '本地测试' }); delta = 20;
    assert.equal((await m.call('profile')).level.title, '测试进阶等级');
    await a.call('points/adjust', { employee_id: 91003, delta: -20, reason: '恢复等级测试积分', basis: '与原测试增分对应' }); delta = 0;
    const returned = await m.call('profile'); assert.equal(returned.point_balance, balance); assert.equal(returned.level.title, '测试基础等级');
    assert.ok(returned.level_history.some(r => r.level_title === '测试进阶等级'));
    assert.notEqual((await a.call('reward/levels', { levels: [{ title: '重复1', threshold: 0 }, { title: '重复2', threshold: 0 }] }, true)).status, 200);
    const templates = JSON.parse(original.templates_json || '{}');
    await a.call('settings', { enabled: true, business_link_enabled: !!Number(original.business_link_enabled), templates: { ...templates, reminders: { ...(templates.reminders || {}), due: true, lead_hours: 24, frequency_hours: 24, recipient: 'executor' } } });
    const body = { title: '到期提醒边界-' + Date.now(), reward_selection: { mode: 'selected', point_rule_ids: [], certificate_rule_ids: [] }, chapters: [{ key: 'main', title: '基础说明', type: 'text', body: '测试提醒不重复催办。' }] };
    const draft = await a.call('content/save', body), pub = await a.call('content/publish', { ...body, id: draft.id });
    const assigned = await a.call('task/assign', { publication_id: pub.publication_id, employee_ids: [91003], due_at: Math.floor(Date.now() / 1000) + 3600 });
    const task = assigned.task_ids[0];
    function count() { return JSON.parse(execFileSync('docker', ['exec', '-i', 'mohe-growth-local-app', 'php', '/workspace/tests/growth/operations-fixtures.php'], { input: JSON.stringify({ mode: 'message_count', task_id: task }), encoding: 'utf8' })).count; }
    tick(); const sent = count(); tick(); assert.equal(count(), sent);
    await m.call('learn', { task_id: task, chapter_key: 'main', session_key: key(), session_open: true, position: 0, confirm: true });
    tick(); const finished = count(); tick(); assert.equal(count(), finished);
    console.log(JSON.stringify({ passed: ['独立流水升降级并保留历史', '恢复积分后余额准确', '重复等级门槛拒绝', '到期提醒同窗口去重', '完成后不再催办'] }, null, 2));
  } finally {
    if (delta) await a.call('points/adjust', { employee_id: 91003, delta: -delta, reason: '失败测试恢复', basis: '恢复隔离测试余额' });
    await a.call('reward/levels', { levels });
    await a.call('settings', { enabled: !!Number(original.enabled), business_link_enabled: !!Number(original.business_link_enabled), templates: JSON.parse(original.templates_json || '{}') });
  }
})().catch(e => { console.error(e); process.exitCode = 1; });
