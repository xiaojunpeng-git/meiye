/* 完整档案分页、知识收藏/查阅、后台答疑与消息精确返回原问题。 */
const { chromium } = require('playwright');
const { admin, mobile, assert, base } = require('./local-client.cjs');
const path = require('node:path'), fs = require('node:fs');
const evidence = path.resolve(__dirname, '../../../任务管理/进行中/美业大学成长管理/证据');
(async () => {
  const a = await admin(), m = await mobile();
  const title = '页面知识查阅-' + Date.now(), question = '页面答疑-' + Date.now();
  const body = { title, kind: 'knowledge', chapters: [{ key: 'main', title: '服务沟通', type: 'text', body: '先倾听、再核对，最后复述确认。' }] };
  const draft = await a.call('content/save', body); await a.call('content/publish', { ...body, id: draft.id });
  await m.call('help/ask', { body: question, handler_employee_id: 91001 });
  const browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' });
  const timer = setTimeout(() => browser.close(), 180000), errors = [];
  try {
    const phone = await browser.newPage({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    phone.setDefaultTimeout(25000); phone.setDefaultNavigationTimeout(60000); phone.on('pageerror', e => errors.push(e.message));
    await phone.goto('http://127.0.0.1:18104', { waitUntil: 'domcontentloaded' });
    await phone.locator('input').nth(0).fill(base); await phone.locator('input').nth(1).fill('emp91002'); await phone.locator('input').nth(2).fill('GrowthLocal!2026');
    await phone.getByText('账号登录', { exact: true }).click(); await phone.getByText('成长管理', { exact: true }).click();
    await phone.getByText('我的成长档案 →', { exact: true }).click(); await phone.getByText('全部成长来源与历史 →', { exact: true }).click();
    await phone.locator('.history-card').first().waitFor(); assert.equal(await phone.locator('.history-card').count(), 20);
    const first = await phone.locator('.history-card-title').first().textContent();
    await phone.getByText('下一页', { exact: true }).click(); await phone.waitForFunction(previous => document.querySelector('.history-card-title')?.textContent !== previous, first);
    await phone.screenshot({ path: path.join(evidence, '35-完整成长历史第二页.png'), fullPage: true });
    await phone.goBack(); await phone.goBack(); await phone.getByText('随手学 · 有问有答 →', { exact: true }).click();
    await phone.locator('.knowledge-search input').fill(title); await phone.getByText('搜索', { exact: true }).click();
    await phone.getByText(title + ' →', { exact: true }).waitFor(); await phone.getByText('☆ 收藏这条知识', { exact: true }).click();
    await phone.getByText('★ 已收藏，点击取消', { exact: true }).waitFor(); await phone.getByText('我的收藏', { exact: true }).click();
    await phone.getByText(title + ' →', { exact: true }).click(); await phone.getByText('先倾听、再核对，最后复述确认。', { exact: true }).waitFor();
    await phone.screenshot({ path: path.join(evidence, '36-手机收藏知识实际阅读.png'), fullPage: true });
    const pc = await browser.newPage({ viewport: { width: 1440, height: 1000 } }); pc.setDefaultTimeout(25000); pc.setDefaultNavigationTimeout(60000);
    pc.on('pageerror', e => { if (e.message !== 'Event') errors.push(e.message); });
    await pc.goto(base + '/admin/login', { waitUntil: 'domcontentloaded' }); await pc.getByPlaceholder('请输入用户名', { exact: true }).fill('growth_admin'); await pc.getByPlaceholder('请输入密码', { exact: true }).first().fill('GrowthLocal!2026'); await pc.getByRole('button', { name: '登录', exact: true }).click(); await pc.waitForURL('**/growth/content');
    await pc.goto(base + '/admin/growth/help', { waitUntil: 'domcontentloaded' });
    const card = pc.locator('article').filter({ has: pc.getByRole('heading', { name: question, exact: true }) });
    await card.locator('textarea').fill('先询问需求与禁忌，再复述确认。'); await card.getByRole('button', { name: '回复并结束答疑', exact: true }).click();
    await card.getByText('已完成', { exact: false }).waitFor(); await pc.screenshot({ path: path.join(evidence, '37-平台人工答疑正式回复.png'), fullPage: true });
    await phone.goBack(); await phone.goBack(); await phone.getByText('消息', { exact: true }).click();
    await phone.locator('.growth-card').filter({ hasText: '你的成长问题已有回复' }).first().click();
    await phone.getByText(question, { exact: true }).waitFor(); await phone.getByText('先询问需求与禁忌，再复述确认。', { exact: true }).waitFor();
    assert.equal(await phone.locator('.knowledge-card').count(), 2);
    await phone.screenshot({ path: path.join(evidence, '38-消息精确返回原答疑.png'), fullPage: true });
    await pc.goto(base + '/admin/growth/profiles', { waitUntil: 'domcontentloaded' }); await pc.locator('.growth-toolbar select').first().selectOption('91002'); await pc.getByRole('button', { name: '查看完整成长来源', exact: true }).click();
    const modal = pc.getByRole('dialog', { name: '完整成长历史' }); await modal.locator('article').first().waitFor(); assert.equal(await modal.locator('article').count(), 20);
    await modal.getByRole('button', { name: '下一页', exact: true }).click(); await modal.getByText('第 2 /', { exact: false }).waitFor();
    await pc.screenshot({ path: path.join(evidence, '39-平台完整档案分页.png'), fullPage: true });
    assert.deepEqual(errors, []); fs.writeFileSync(path.join(evidence, 'browser-history-help-result.json'), JSON.stringify({ passed: ['手机完整历史20条分页', '知识搜索收藏查阅真实资源', '平台指定负责人答疑', '站内消息定位唯一原问题', '平台完整档案分页'], page_errors: errors }, null, 2)); console.log('历史、知识和答疑页面操作通过');
  } finally { for (const c of browser.contexts()) for (const page of c.pages()) await page.screenshot({ path: path.join(evidence, page.url().includes('18115') ? '历史平台最后状态.png' : '历史手机最后状态.png'), fullPage: true }).catch(() => {}); clearTimeout(timer); await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
