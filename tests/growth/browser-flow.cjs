/* 浏览器逐项真实操作：Vue2发布分配、Vue3美容师登录学习；不通过注入接口伪造页面成功。 */
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const evidence = path.resolve(__dirname, '../../../任务管理/进行中/美业大学成长管理/证据');
fs.mkdirSync(evidence, { recursive: true });
(async () => {
  const browser = await chromium.launch({
    headless: true,
    executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
  });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();
  page.setDefaultTimeout(20000);
  const failTimer = setTimeout(async () => {
    await page.screenshot({ path: path.join(evidence, '浏览器超时.png'), fullPage: true }).catch(() => {});
    await browser.close();
    process.exitCode = 1;
  }, 120000);
  const errors = [];
  page.on('pageerror', (e) => {
    if (e.message !== 'Event') errors.push(e.message);
  });
  await page.goto('http://127.0.0.1:18115/admin/login', { waitUntil: 'domcontentloaded' });
  await page.getByPlaceholder('请输入用户名', { exact: true }).fill('growth_admin');
  await page.getByPlaceholder('请输入密码', { exact: true }).first().fill('GrowthLocal!2026');
  await page.getByRole('button', { name: '登录', exact: true }).click();
  await page.waitForURL('**/growth/content');
  const title = '真人路径体验课程-' + Date.now();
  await page.getByRole('button', { name: '＋ 新建内容' }).click();
  await page.locator('#growth-title').fill(title);
  await page.locator('#growth-description').fill('通过真实页面完成发布、分配、学习和结果读回。');
  await page.locator('#chapter-title-0').fill('服务前的准备');
  await page
    .locator('#chapter-body-0')
    .fill('第一步：询问并确认顾客需求。\n第二步：核对禁忌与注意事项。\n第三步：清洁双手并准备用品。');
  await page.screenshot({ path: path.join(evidence, '01-平台课程编辑.png'), fullPage: true });
  await page.getByRole('button', { name: '预览确认并发布', exact: true }).click();
  await page.getByRole('button', { name: '确认本次内容并发布', exact: true }).click();
  const row = page.getByRole('row').filter({ hasText: title });
  await row.getByText('已发布', { exact: true }).waitFor();
  await row.getByRole('button', { name: '分配学习', exact: true }).click();
  await page.getByRole('dialog').waitFor();
  await page.getByRole('dialog').locator('label').filter({hasText:/^美容师小美$/}).click();
  await page.getByRole('dialog').locator('label').filter({hasText:/^组织员工小雅$/}).click();
  await page.getByRole('button', { name: '确认分配 2 人', exact: true }).click();
  await page.getByText('已向 2 名员工分配学习任务', { exact: true }).waitFor();
  await page.screenshot({ path: path.join(evidence, '02-平台任务分配.png'), fullPage: true });
  const mobileContext = await browser.newContext({
    viewport: { width: 390, height: 844 },
    isMobile: true,
    hasTouch: true
  });
  const phone = await mobileContext.newPage();
  phone.setDefaultTimeout(20000);
  phone.on('response', async (r) => {
    if (r.url().includes('/api/mobile/merchant/growth/learn')) console.log('LEARN_RESPONSE', await r.text());
  });
  phone.on('pageerror', (e) => errors.push(e.message));
  await phone.goto('http://127.0.0.1:18104', { waitUntil: 'domcontentloaded' });
  await phone.locator('input').nth(0).fill('http://127.0.0.1:18115');
  await phone.locator('input').nth(1).fill('emp91002');
  await phone.locator('input').nth(2).fill('GrowthLocal!2026');
  await phone.getByText('账号登录', { exact: true }).click();
  await phone.getByText('成长管理', { exact: true }).waitFor({ timeout: 30000 });
  await phone.getByText('成长管理', { exact: true }).click();
  await phone.getByText(title, { exact: true }).waitFor();
  await phone.screenshot({ path: path.join(evidence, '03-手机成长首页.png'), fullPage: true });
  await phone.getByText(title, { exact: true }).click();
  await phone.getByText('我已学习本章', { exact: true }).waitFor();
  await phone.screenshot({ path: path.join(evidence, '04-手机课程学习.png'), fullPage: true });
  await phone.waitForFunction(() => {
    const b = [...document.querySelectorAll('uni-button')].find((e) =>
      e.textContent.includes('我已学习本章')
    );
    return b && !b.hasAttribute('disabled') && !b.classList.contains('uni-button-disabled');
  });
  await phone.getByText('我已学习本章', { exact: true }).click();
  await phone.getByText('又向前迈进了一步', { exact: true }).waitFor();
  await phone.screenshot({ path: path.join(evidence, '05-手机学习完成.png'), fullPage: true });
  await page.getByRole('button', { name: '查询', exact: true }).click();
  const completed = page.getByRole('row').filter({ hasText: title }).filter({ hasText: '美容师小美' });
  await completed.getByText('已完成', { exact: true }).waitFor();
  await page.screenshot({ path: path.join(evidence, '06-平台完成结果读回.png'), fullPage: true });
  assert.deepEqual(errors, [], '页面运行异常');
  fs.writeFileSync(
    path.join(evidence, 'browser-course-result.json'),
    JSON.stringify(
      {
        passed: true,
        title,
        steps: [
          '平台登录',
          '新建课程',
          '填写章节',
          '发布',
          '勾选两类员工分配',
          '美容师登录',
          '从工作台进入成长',
          '阅读正文',
          '确认学习',
          '管理端读回已完成'
        ],
        errors
      },
      null,
      2
    )
  );
  console.log('浏览器课程流程通过：' + title);
  clearTimeout(failTimer);
  await browser.close();
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
