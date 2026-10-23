/* 随堂、课后检验、成长统计和复盘的实际浏览器页面操作。 */
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
  const q = await seed('question/save', {
    type: 'order',
    stem: '标准准备流程的正确先后是？',
    options: ['核对需求', '准备用品', '清洁双手'],
    answer: [2, 0, 1],
    score: 10
  });
  const paper = await seed('paper/publish', {
    title: '页面课后检验试卷',
    mode: 'fixed',
    question_ids: [q.id]
  });
  const examTitle = '页面课后检验-' + Date.now();
  const exam = await seed('exam/create', {
    title: examTitle,
    paper_release_id: paper.release_id,
    employee_ids: [91001],
    pass_score: 10,
    max_attempts: 1,
    auto_publish: true,
    show_analysis: true
  });
  const title = '页面随堂与课后课程-' + Date.now();
  const body = {
    title,
    chapters: [
      {
        key: 'main',
        title: '标准准备流程',
        type: 'text',
        body: '清洁双手，核对需求，再准备用品。',
        quizzes: [
          {
            key: 'prepare',
            question_id: q.id,
            position: 0,
            required: true,
            wrong_policy: 'retry',
            analysis_timing: 'after_answer'
          }
        ]
      }
    ],
    course_rules: { post_exam_id: exam.id, require_exam_passed: true }
  };
  const c = await seed('content/save', body);
  const pub = await seed('content/publish', { ...body, id: c.id });
  const t = await seed('task/assign', { publication_id: pub.publication_id, employee_ids: [91002] });
  const browser = await chromium.launch({
    headless: true,
    executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
  });
  let phone, pc;
  const timer = setTimeout(() => browser.close(), 180000);
  try {
    const errors = [];
    const ctx = await browser.newContext({
      viewport: { width: 390, height: 844 },
      isMobile: true,
      hasTouch: true
    });
    phone = await ctx.newPage();
    phone.setDefaultTimeout(20000);
    phone.on('pageerror', (e) => errors.push(e.message));
    await phone.goto('http://127.0.0.1:18104');
    await phone.locator('input').nth(0).fill(base);
    await phone.locator('input').nth(1).fill('emp91002');
    await phone.locator('input').nth(2).fill('GrowthLocal!2026');
    await phone.getByText('账号登录', { exact: true }).click();
    await phone.getByText('成长管理', { exact: true }).click();
    await phone.getByText(title, { exact: true }).click();
    await phone.getByText('随堂练习', { exact: true }).waitFor();
    for (const i of [2, 0, 1]) await phone.locator('.learn-quiz-option').nth(i).click();
    await phone.waitForFunction(() => {
      const e = [...document.querySelectorAll('uni-button')].find((e) =>
        e.textContent.includes('确认本题答案')
      );
      return e && !e.classList.contains('uni-button-disabled') && !e.hasAttribute('disabled');
    });
    await phone.getByText('确认本题答案', { exact: true }).click();
    await phone.getByText('随堂练习 · 已完成', { exact: true }).waitFor();
    await phone.screenshot({ path: path.join(evidence, '22-手机随堂顺序题完成.png'), fullPage: true });
    await phone.getByText('我已学习本章', { exact: true }).click();
    await phone.getByText('章节已学完，还差最后一步', { exact: true }).waitFor();
    await phone.getByText(examTitle + ' →', { exact: true }).click();
    await phone.getByText('开始作答', { exact: true }).click();
    await phone.locator('.exam-option').nth(2).click();
    await phone.locator('.exam-option').nth(0).click();
    await phone.locator('.exam-option').nth(1).click();
    await phone.getByText('确认交卷', { exact: true }).click();
    await phone.getByText('确定', { exact: true }).click();
    await phone.getByText('通过啦！', { exact: true }).waitFor();
    await phone.getByText('返回我的成长', { exact: true }).click();
    await phone.getByText('又向前迈进了一步', { exact: true }).waitFor();
    await phone.screenshot({ path: path.join(evidence, '23-课后通过自动完成课程.png'), fullPage: true });
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
    await pc.goto(base + '/admin/growth/reports');
    await pc.getByRole('button', { name: '导出当前查询', exact: true }).waitFor();
    await pc.getByRole('row').filter({ hasText: title }).first().waitFor();
    await pc.screenshot({ path: path.join(evidence, '24-平台成长统计与固定合计.png'), fullPage: true });
    await pc
      .getByRole('row')
      .filter({ hasText: title })
      .first()
      .getByRole('button', { name: title, exact: true })
      .click();
    await pc.getByRole('heading', { name: '来源明细', exact: true }).waitFor();
    await pc.getByRole('button', { name: '关闭', exact: true }).click();
    const download = pc.waitForEvent('download');
    await pc.getByRole('button', { name: '导出当前查询', exact: true }).click();
    const file = await download;
    await file.saveAs(path.join(evidence, '页面实际导出.csv'));
    await pc.goto(base + '/admin/growth/overview');
    await pc.getByRole('heading', { name: '成长工作台', exact: true }).waitFor();
    await pc.getByText('任务完成率', { exact: true }).waitFor();
    await pc.screenshot({ path: path.join(evidence, '25-平台成长工作台.png'), fullPage: true });
    await pc.getByRole('button', { name: '经营观察与复盘', exact: true }).click();
    await pc.getByRole('button', { name: '新建复盘', exact: true }).click();
    const dlg = pc.locator('.growth-modal');
    await dlg
      .locator('input')
      .first()
      .fill('页面服务准备培养复盘-' + Date.now());
    await dlg.locator('select').nth(1).selectOption('91002');
    await dlg.locator('textarea').nth(0).fill('本地隔离数据，用于验证复盘记录的保存与刷新读回。');
    await dlg.locator('textarea').nth(1).fill('学习已通过，后续仍需结合实际服务机会观察。');
    await dlg.locator('textarea').nth(2).fill('由带教师傅安排下一次服务表达练习。');
    await dlg.getByRole('button', { name: '保存复盘', exact: true }).click();
    await pc.getByText('复盘已保存，观测指标与文字分别留存', { exact: true }).waitFor();
    await pc.reload();
    await pc
      .getByText(/下一步：由带教师傅安排下一次服务表达练习/)
      .first()
      .waitFor();
    await pc.screenshot({ path: path.join(evidence, '26-平台复盘刷新后读回.png'), fullPage: true });
    assert.deepEqual(errors, []);
    fs.writeFileSync(
      path.join(evidence, 'browser-course-report-result.json'),
      JSON.stringify(
        {
          passed: true,
          title,
          task_id: t.task_ids[0],
          steps: [
            '手机随堂顺序题',
            '学完后提示课后要求',
            '进入真实课后检验',
            '通过后返回课程自动刷新',
            '课程正式完成',
            '平台成长统计',
            '精确来源明细',
            '实际下载CSV',
            '管理工作台',
            '复盘填写保存与刷新读回'
          ],
          errors
        },
        null,
        2
      )
    );
    console.log('随堂课后、统计导出及复盘页面操作通过');
  } catch (e) {
    if (phone)
      await phone
        .screenshot({ path: path.join(evidence, '随堂统计手机失败.png'), fullPage: true })
        .catch(() => {});
    if (pc) {
      await pc
        .screenshot({ path: path.join(evidence, '随堂统计平台失败.png'), fullPage: true })
        .catch(() => {});
      console.error((await pc.locator('body').innerText()).slice(-1200));
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
