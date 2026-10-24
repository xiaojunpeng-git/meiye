/* 实际页面保存与恢复顺序答案；故意丢弃已落库的交卷响应，验证重试仍只有一份正式答卷。 */
const { chromium } = require('playwright');
const { admin, assert, base } = require('./local-client.cjs');
const fs = require('node:fs'), path = require('node:path');
const evidence = path.resolve(__dirname, '../../../任务管理/进行中/美业大学成长管理/证据');
(async () => {
  const a = await admin(), title = '页面保存与断线恢复-' + Date.now();
  const q = await a.call('question/save', { type: 'order', stem: '按服务顺序选择全部步骤', options: ['准备材料', '确认需求', '清洁双手'], answer: [1, 2, 0], score: 10 });
  const paper = await a.call('paper/publish', { title, mode: 'fixed', question_ids: [q.id] });
  const exam = await a.call('exam/create', { title, paper_release_id: paper.release_id, employee_ids: [91002], pass_score: 10, max_attempts: 2, auto_publish: true });
  const browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' });
  const timer = setTimeout(() => browser.close(), 150000);
  let phone;
  try {
    phone = await browser.newPage({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true }); phone.setDefaultTimeout(25000);
    await phone.goto('http://127.0.0.1:18104', { waitUntil: 'domcontentloaded' });
    await phone.locator('input').nth(0).fill(base); await phone.locator('input').nth(1).fill('emp91002'); await phone.locator('input').nth(2).fill('GrowthLocal!2026');
    await phone.getByText('账号登录', { exact: true }).click(); await phone.getByText('成长管理', { exact: true }).click(); await phone.getByText(title, { exact: true }).click();
    await phone.getByText('开始作答', { exact: true }).click();
    for (const i of [1, 2]) await phone.locator('.exam-option').nth(i).click();
    await phone.getByText('保存，稍后继续', { exact: true }).click(); await phone.getByText(/已答 1 \/ 1 · 已保存/).waitFor();
    await phone.reload({ waitUntil: 'domcontentloaded' });
    await phone.getByText('当前顺序：确认需求 → 清洁双手', { exact: true }).waitFor();
    await phone.locator('.exam-option').nth(0).click();
    await phone.getByText('当前顺序：确认需求 → 清洁双手 → 准备材料', { exact: true }).waitFor();
    // 请求真实到达本地服务并成功落库后才截断响应；不伪造成功载荷。
    const requests = []; let dropped = false;
    await phone.route('**/api/mobile/merchant/growth/exam/submit', async route => {
      requests.push(route.request().postDataJSON());
      if (!dropped) { dropped = true; const response = await route.fetch(); const result = await response.json(); assert.ok(result.growth, JSON.stringify(result)); await route.abort('connectionfailed'); }
      else await route.continue();
    });
    await phone.getByText('确认交卷', { exact: true }).click(); await phone.getByText('确定', { exact: true }).click();
    await phone.getByText('重试确认', { exact: true }).waitFor();
    await phone.screenshot({ path: path.join(evidence, '46-交卷响应中断保留重试.png'), fullPage: true });
    await phone.getByText('重试确认', { exact: true }).click(); await phone.getByText('通过啦！', { exact: true }).waitFor();
    assert.equal(requests.length, 2); assert.deepEqual(requests[0], requests[1]);
    const task = await a.call('task?id=' + exam.task_ids[0]);
    assert.equal(task.attempts.length, 1); assert.equal(task.state, 'COMPLETED'); assert.equal(Number((await a.call('attempt?id=' + task.attempts[0].id)).score), 10);
    await phone.reload({ waitUntil: 'domcontentloaded' }); await phone.getByText('通过啦！', { exact: true }).waitFor();
    await phone.screenshot({ path: path.join(evidence, '47-重试后唯一答卷刷新恢复.png'), fullPage: true });
    fs.writeFileSync(path.join(evidence, 'browser-answer-recovery-result.json'), JSON.stringify({ passed: true, task_id: task.id, attempt_id: task.attempts[0].id, steps: ['顺序题部分答案保存', '刷新恢复同一答卷与点选顺序', '补齐答案交卷', '真实交卷成功后响应中断', '原操作重试返回同一结果', '唯一正式答卷与刷新读回'] }, null, 2));
    console.log('答案保存、刷新恢复与交卷响应中断重试通过');
  } catch (e) { if (phone) await phone.screenshot({ path: path.join(evidence, '答题恢复失败.png'), fullPage: true }).catch(() => {}); throw e; }
  finally { clearTimeout(timer); await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
