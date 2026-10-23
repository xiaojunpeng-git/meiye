/* 真人页面路径：四类客观题实际点选、错序失败与补考通过、成长档案和奖励设置可达。 */
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const base = 'http://127.0.0.1:18115';
const key = () => `ui-${randomUUID()}`;
const evidence = path.resolve(__dirname, '../../../任务管理/进行中/美业大学成长管理/证据');
async function raw(url, data, h = {}) {
  return (
    await fetch(base + url, {
      method: data ? 'POST' : 'GET',
      headers: { 'Content-Type': 'application/json', ...h },
      body: data ? JSON.stringify(data) : undefined
    })
  ).json();
}
(async () => {
  const l = await raw('/adminapi/login', { account: 'growth_admin', pwd: 'GrowthLocal!2026' });
  assert.equal(l.status, 200);
  const h = { 'Authori-zation': 'Bearer ' + l.data.token, 'X-Source': 'f76d38d0ee4f854f' };
  async function seed(p, d) {
    const r = await raw('/adminapi/growth/' + p, { ...d, idempotency_key: key() }, h);
    assert.equal(r.status, 200, r.msg);
    return r.data;
  }
  const title = '四类客观题页面验收-' + Date.now();
  const ids = [];
  for (const q of [
    { type: 'single', stem: '服务前首先应做什么？', options: ['确认需求', '直接操作'], answer: [0] },
    {
      type: 'multiple',
      stem: '哪些属于服务前准备？',
      options: ['核对禁忌', '清洁双手', '跳过询问'],
      answer: [0, 1]
    },
    { type: 'boolean', stem: '服务前需要确认顾客需求。', answer: [0] },
    {
      type: 'order',
      stem: '请按正确顺序完成准备流程',
      options: ['核对需求', '准备用品', '清洁双手'],
      answer: [2, 0, 1]
    }
  ])
    ids.push((await seed('question/save', { ...q, score: 10 })).id);
  const paper = await seed('paper/publish', { title, mode: 'fixed', question_ids: ids });
  await seed('exam/create', {
    title,
    paper_release_id: paper.release_id,
    employee_ids: [91002],
    max_attempts: 2,
    pass_score: 40,
    auto_publish: true,
    show_analysis: true
  });
  const browser = await chromium.launch({
    headless: true,
    executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
  });
  let phone;
  const timer = setTimeout(async () => {
    if (phone)
      await phone
        .screenshot({ path: path.join(evidence, '顺序题页面超时.png'), fullPage: true })
        .catch(() => {});
    await browser.close();
  }, 150000);
  try {
    const errors = [];
    const pc = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    pc.on('pageerror', (e) => {
      if (e.message !== 'Event') errors.push(e.message);
    });
    await pc.goto(base + '/admin/login');
    await pc.getByPlaceholder('请输入用户名', { exact: true }).fill('growth_admin');
    await pc.getByPlaceholder('请输入密码', { exact: true }).first().fill('GrowthLocal!2026');
    await pc.getByRole('button', { name: '登录', exact: true }).click();
    await pc.waitForURL('**/growth/content');
    await pc.goto(base + '/admin/growth/settings');
    await pc.getByRole('heading', { name: '积分与证书规则', exact: true }).waitFor();
    await pc.getByRole('button', { name: '新增积分规则', exact: true }).click();
    await pc.getByRole('button', { name: '保存规则', exact: true }).waitFor();
    await pc.screenshot({ path: path.join(evidence, '07-平台奖励规则.png'), fullPage: true });
    await pc.getByRole('button', { name: '取消', exact: true }).click();
    await pc.goto(base + '/admin/growth/profiles');
    await pc.locator('select').first().selectOption('91002');
    await pc.getByRole('heading', { name: /美容师小美/ }).waitFor();
    await pc.screenshot({ path: path.join(evidence, '08-平台成长档案.png'), fullPage: true });
    const ctx = await browser.newContext({
      viewport: { width: 390, height: 844 },
      isMobile: true,
      hasTouch: true
    });
    phone = await ctx.newPage();
    phone.setDefaultTimeout(25000);
    phone.on('pageerror', (e) => errors.push(e.message));
    await phone.goto('http://127.0.0.1:18104');
    await phone.locator('input').nth(0).fill(base);
    await phone.locator('input').nth(1).fill('emp91002');
    await phone.locator('input').nth(2).fill('GrowthLocal!2026');
    await phone.getByText('账号登录', { exact: true }).click();
    await phone.getByText('成长管理', { exact: true }).click();
    await phone.getByText(title, { exact: true }).click();
    await phone.getByText('开始作答', { exact: true }).click();
    await phone.locator('.exam-question').nth(3).waitFor();
    async function clickOption(q, i) {
      await phone.waitForFunction(() => !document.querySelector('uni-button.uni-button-disabled'));
      await phone.locator('.exam-question').nth(q).locator('.exam-option').nth(i).click();
    }
    async function firstThree() {
      await clickOption(0, 0);
      await clickOption(1, 0);
      await clickOption(1, 1);
      await clickOption(2, 0);
    }
    async function submit() {
      await phone.getByText('确认交卷', { exact: true }).click();
      await phone.getByText('确定', { exact: true }).click();
    }
    await firstThree();
    for (const i of [0, 2, 1]) await clickOption(3, i);
    await phone.getByText('当前顺序：核对需求 → 清洁双手 → 准备用品', { exact: true }).waitFor();
    await phone.screenshot({ path: path.join(evidence, '09-手机顺序题点选.png'), fullPage: true });
    await submit();
    await phone.getByText('这次还差一点', { exact: true }).waitFor();
    await phone.screenshot({ path: path.join(evidence, '10-考试未通过.png'), fullPage: true });
    assert.match(await phone.locator('.exam-score').innerText(), /30/);
    await phone.getByText('准备好了，再试一次', { exact: true }).click();
    await phone.getByText('确定', { exact: true }).click();
    await phone.locator('.exam-question').nth(3).waitFor();
    await firstThree();
    for (const i of [2, 0, 1]) await clickOption(3, i);
    await phone.getByText('当前顺序：清洁双手 → 核对需求 → 准备用品', { exact: true }).waitFor();
    await submit();
    await phone.getByText('通过啦！', { exact: true }).waitFor();
    await phone.screenshot({ path: path.join(evidence, '11-考试通过庆祝.png'), fullPage: true });
    assert.match(await phone.locator('.exam-score').innerText(), /40/);
    await phone.getByText('返回我的成长', { exact: true }).click();
    await phone.getByText('我的成长档案 →', { exact: true }).click();
    await phone.getByText('美容师小美的成长', { exact: true }).waitFor();
    await phone.screenshot({ path: path.join(evidence, '12-手机成长档案.png'), fullPage: true });
    assert.deepEqual(errors, []);
    fs.writeFileSync(
      path.join(evidence, 'browser-order-records-result.json'),
      JSON.stringify(
        {
          passed: true,
          title,
          steps: [
            '平台奖励配置入口',
            '平台成长档案',
            '手机单选',
            '手机多选',
            '手机判断',
            '手机顺序点选标号与顺序预览',
            '错序零分未通过',
            '新答卷补考',
            '全部正确通过庆祝',
            '手机成长档案'
          ],
          errors
        },
        null,
        2
      )
    );
    console.log('四题型及成长档案页面流程通过：' + title);
  } catch (e) {
    if (phone) {
      await phone
        .screenshot({ path: path.join(evidence, '顺序题页面失败.png'), fullPage: true })
        .catch(() => {});
      console.error((await phone.locator('body').innerText()).slice(-2000));
    }
    throw e;
  } finally {
    clearTimeout(timer);
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
