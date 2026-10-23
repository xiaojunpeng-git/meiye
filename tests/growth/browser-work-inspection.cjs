/* 通过页面创建活动、员工提交、师傅点评，以及真实上传整改证据并复查关闭。 */
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { randomUUID } = require('node:crypto');
const evidence = path.resolve(__dirname, '../../../任务管理/进行中/美业大学成长管理/证据');
const base = 'http://127.0.0.1:18115';
(async () => {
  const l = await (
    await fetch(base + '/adminapi/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ account: 'growth_admin', pwd: 'GrowthLocal!2026' })
    })
  ).json();
  const h = {
    'Authori-zation': 'Bearer ' + l.data.token,
    'X-Source': 'f76d38d0ee4f854f',
    'Content-Type': 'application/json'
  };
  async function seed(p, d) {
    const r = await (
      await fetch(base + '/adminapi/growth/' + p, {
        method: d ? 'POST' : 'GET',
        headers: h,
        body: d ? JSON.stringify({ ...d, idempotency_key: randomUUID() }) : undefined
      })
    ).json();
    assert.equal(r.status, 200, r.msg);
    return r.data;
  }
  const standard = await seed('standard/publish', {
    menu: 'inspection',
    title: '页面巡检标准-' + Date.now(),
    purpose: 'inspection',
    mode: 'pass',
    items: [{ key: 'clean', title: '操作区卫生', required: true, evidence_required: true, max_score: 10 }]
  });
  const browser = await chromium.launch({
    headless: true,
    executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
  });
  let pc, phone;
  const timer = setTimeout(() => browser.close(), 210000);
  try {
    const errors = [];
    pc = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    pc.setDefaultTimeout(20000);
    pc.on('pageerror', (e) => {
      if (e.message !== 'Event') errors.push(e.message);
    });
    await pc.goto(base + '/admin/login');
    await pc.getByPlaceholder('请输入用户名', { exact: true }).fill('growth_admin');
    await pc.getByPlaceholder('请输入密码', { exact: true }).first().fill('GrowthLocal!2026');
    await pc.getByRole('button', { name: '登录', exact: true }).click();
    await pc.waitForURL('**/growth/content');
    await pc.goto(base + '/admin/growth/practice');
    await pc.getByRole('button', { name: '＋ 安排活动', exact: true }).click();
    const title = '页面作业与点评-' + Date.now();
    let dlg = pc.getByRole('dialog');
    await dlg.locator('input:not([type=checkbox])').first().fill(title);
    await dlg.locator('textarea').first().fill('记录服务准备练习内容，由师傅实际点评确认。');
    await dlg.locator('.growth-persons label').filter({ hasText: '美容师小美' }).click();
    await dlg.locator('select').nth(1).selectOption('91001');
    await dlg.getByRole('button', { name: '确认分配 1 人', exact: true }).click();
    await pc.getByRole('row').filter({ hasText: title }).waitFor();
    await pc.screenshot({ path: path.join(evidence, '17-平台真实安排作业.png'), fullPage: true });
    const mobile = await browser.newContext({
      viewport: { width: 390, height: 844 },
      isMobile: true,
      hasTouch: true
    });
    phone = await mobile.newPage();
    phone.setDefaultTimeout(20000);
    phone.on('pageerror', (e) => errors.push(e.message));
    await phone.goto('http://127.0.0.1:18104');
    await phone.locator('input').nth(0).fill(base);
    await phone.locator('input').nth(1).fill('emp91002');
    await phone.locator('input').nth(2).fill('GrowthLocal!2026');
    await phone.getByText('账号登录', { exact: true }).click();
    await phone.getByText('成长管理', { exact: true }).click();
    await phone.getByText(title, { exact: true }).click();
    await phone.locator('textarea').first().fill('今天练习了服务前需求与禁忌核对，复述确认后再准备材料。');
    await phone.getByText('确认提交', { exact: true }).click();
    await phone.getByText('确定', { exact: true }).click();
    await phone.getByText('第 1 次提交 · 待点评', { exact: true }).waitFor();
    await phone.screenshot({ path: path.join(evidence, '18-手机正式提交作业.png'), fullPage: true });
    await pc.reload();
    await pc
      .getByRole('row')
      .filter({ hasText: title })
      .getByRole('button', { name: '查看 / 处理', exact: true })
      .click();
    dlg = pc.getByRole('dialog');
    await dlg.locator('textarea').first().fill('能够完整描述需求核对与确认过程，本次练习达标。');
    await dlg.getByRole('button', { name: '确认本次处理', exact: true }).click();
    await pc.getByText('已确认', { exact: true }).waitFor();
    await phone.getByText('返回成长', { exact: true }).click();
    await phone.getByText('成长记录', { exact: true }).click();
    await phone.getByText(title, { exact: true }).click();
    await phone.getByText('✦ 本次成长已记录', { exact: true }).waitFor();
    await phone.screenshot({ path: path.join(evidence, '19-手机读回师傅点评.png'), fullPage: true });
    await pc.goto(base + '/admin/growth/inspection');
    await pc.getByRole('button', { name: '＋ 安排巡检', exact: true }).click();
    dlg = pc.getByRole('dialog');
    const inspectionTitle = '页面门店巡检-' + Date.now();
    await dlg.locator('input').first().fill(inspectionTitle);
    await dlg.locator('select').nth(0).selectOption('91001');
    await dlg.locator('select').nth(1).selectOption(String(standard.release_id));
    await dlg.locator('select').nth(2).selectOption('91001');
    await dlg.locator('select').nth(3).selectOption('91001');
    await dlg.getByRole('button', { name: '确认安排', exact: true }).click();
    await pc.getByRole('button', { name: '巡检任务', exact: true }).click();
    await pc
      .getByRole('row')
      .filter({ hasText: inspectionTitle })
      .getByRole('button', { name: '查看 / 检查', exact: true })
      .click();
    dlg = pc.getByRole('dialog');
    await dlg.locator('select').first().selectOption({ label: '未通过' });
    await dlg.locator('textarea').first().fill('现场台面物品摆放不规范，需整理后重新取证。');
    await dlg
      .locator('input[type=file]')
      .first()
      .setInputFiles(path.join(evidence, '媒体样例/服务准备长图.png'));
    await dlg.getByText('服务准备长图.png', { exact: true }).waitFor();
    await dlg.getByText('操作区卫生 需要安排整改', { exact: true }).click();
    const issue = '页面整改要求-' + Date.now();
    await dlg.locator('textarea').nth(1).fill(issue);
    await dlg.locator('select').nth(1).selectOption('91002');
    await dlg.locator('select').nth(2).selectOption('91001');
    await dlg
      .locator('input[type=datetime-local]')
      .fill(new Date(Date.now() + 86400000).toISOString().slice(0, 16));
    await dlg.getByRole('button', { name: '确认提交巡检', exact: true }).click();
    await pc.getByRole('button', { name: '整改问题', exact: true }).click();
    await pc.getByRole('row').filter({ hasText: issue }).waitFor();
    await phone.getByText('返回成长', { exact: true }).click();
    await phone.getByText('刷新 ↻', { exact: true }).click();
    await phone.getByText('我的任务', { exact: true }).click();
    await phone.getByText('整改：操作区卫生', { exact: true }).first().click();
    await phone.locator('textarea').first().fill('已整理台面并消毒，附上本次重新取证的照片。');
    const choose = phone.waitForEvent('filechooser');
    await phone.getByText('＋ 新的整改证据（0）', { exact: true }).click();
    await (await choose).setFiles(path.join(evidence, '媒体样例/服务准备长图.png'));
    await phone.getByText('＋ 新的整改证据（1）', { exact: true }).waitFor();
    await phone.getByText('提交，等待复查', { exact: true }).click();
    await phone.getByText('查看整改证据 ↗', { exact: true }).waitFor();
    await phone.screenshot({ path: path.join(evidence, '20-手机整改后待复查.png'), fullPage: true });
    await pc.reload();
    await pc
      .getByRole('row')
      .filter({ hasText: issue })
      .getByRole('button', { name: '查看 / 处理', exact: true })
      .click();
    dlg = pc.getByRole('dialog');
    await dlg.locator('textarea').first().fill('已复查现场与新证据，符合卫生要求，确认关闭。');
    await dlg.getByRole('button', { name: '确认复查结果', exact: true }).click();
    await pc.getByRole('row').filter({ hasText: issue }).getByText('已关闭', { exact: true }).waitFor();
    await pc.screenshot({ path: path.join(evidence, '21-平台复查关闭问题.png'), fullPage: true });
    assert.deepEqual(errors, []);
    fs.writeFileSync(
      path.join(evidence, 'browser-work-inspection-result.json'),
      JSON.stringify(
        {
          passed: true,
          title,
          inspectionTitle,
          issue,
          steps: [
            '页面安排作业',
            '美容师页面正式提交',
            '指派负责人页面点评',
            '员工页面读回完成',
            '页面安排巡检',
            '逐项检查与现场证据上传',
            '安排责任人与复查人',
            '手机新证据整改提交',
            '整改后仍待复查',
            '平台真实复查关闭'
          ],
          errors
        },
        null,
        2
      )
    );
    console.log('作业点评与巡检整改的页面操作通过');
  } catch (e) {
    if (pc)
      await pc
        .screenshot({ path: path.join(evidence, '作业巡检平台失败.png'), fullPage: true })
        .catch(() => {});
    if (phone) {
      await phone
        .screenshot({ path: path.join(evidence, '作业巡检手机失败.png'), fullPage: true })
        .catch(() => {});
      console.error((await phone.locator('body').innerText()).slice(-1200));
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
