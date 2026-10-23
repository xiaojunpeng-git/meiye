/* 手机逐章打开文档、PPT、长图和真实播放视频；覆盖完成只能由播放上报获得。 */
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const evidence = path.resolve(__dirname, '../../../任务管理/进行中/美业大学成长管理/证据');
const fixture = JSON.parse(fs.readFileSync(path.join(evidence, 'media-result.json')));
(async () => {
  const browser = await chromium.launch({
    headless: true,
    executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
  });
  let page;
  const timer = setTimeout(() => browser.close(), 120000);
  try {
    const context = await browser.newContext({
      viewport: { width: 390, height: 844 },
      isMobile: true,
      hasTouch: true
    });
    page = await context.newPage();
    page.setDefaultTimeout(20000);
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto('http://127.0.0.1:18104');
    await page.locator('input').nth(0).fill('http://127.0.0.1:18115');
    await page.locator('input').nth(1).fill('emp91002');
    await page.locator('input').nth(2).fill('GrowthLocal!2026');
    await page.getByText('账号登录', { exact: true }).click();
    await page.getByText('成长管理', { exact: true }).click();
    await page.getByText(fixture.title, { exact: true }).click();
    await page.locator('iframe.learn-pdf').waitFor();
    await page.waitForFunction(() => document.querySelector('iframe.learn-pdf').src.startsWith('blob:'));
    await page.screenshot({ path: path.join(evidence, '13-手机Word课程预览.png'), fullPage: true });
    async function confirm() {
      if (await page.getByText('本章已完成', { exact: true }).count()) return;
      await page.waitForFunction(() => {
        const b = [...document.querySelectorAll('uni-button')].find((e) =>
          e.textContent.includes('我已学习本章')
        );
        return b && !b.hasAttribute('disabled') && !b.classList.contains('uni-button-disabled');
      });
      await page.getByText('我已学习本章', { exact: true }).click();
      await page.getByText('本章已完成', { exact: true }).waitFor();
    }
    await confirm();
    await page.locator('.learn-chip').nth(1).click();
    await page.waitForFunction(() => document.querySelector('iframe.learn-pdf')?.src.startsWith('blob:'));
    await page.screenshot({ path: path.join(evidence, '14-手机PPT课程预览.png'), fullPage: true });
    await confirm();
    await page.locator('.learn-chip').nth(2).click();
    await page.locator('.learn-image img').waitFor();
    await page.waitForFunction(() => {
      const img = document.querySelector('.learn-image img');
      return img && img.complete && img.naturalHeight > 2000;
    });
    await page.screenshot({ path: path.join(evidence, '15-手机长图课程.png'), fullPage: true });
    await confirm();
    await page.locator('.learn-chip').nth(3).click();
    await page.locator('video').waitFor();
    await page.waitForFunction(() => {
      const v = document.querySelector('video');
      return v && v.readyState >= 2;
    });
    assert.equal(
      await page
        .locator('uni-button')
        .filter({ hasText: '我已学习本章' })
        .evaluate((el) => el.classList.contains('uni-button-disabled') || el.hasAttribute('disabled')),
      true
    );
    await page.locator('.uni-video-cover-play-button').click();
    await page.waitForFunction(() => {
      const v = document.querySelector('video');
      return v && !v.paused;
    });
    await page.screenshot({ path: path.join(evidence, '16-手机视频真实播放.png'), fullPage: true });
    await page.getByText('又向前迈进了一步', { exact: true }).waitFor({ timeout: 25000 });
    assert.deepEqual(errors, []);
    fs.writeFileSync(
      path.join(evidence, 'browser-media-result.json'),
      JSON.stringify(
        {
          passed: true,
          title: fixture.title,
          task_id: fixture.task_id,
          steps: [
            'Word认证PDF预览',
            'PPT认证PDF预览',
            '长图完整加载',
            '视频未完成不能手动确认',
            '点击真实播放',
            '实际观看覆盖完成整课'
          ],
          errors
        },
        null,
        2
      )
    );
    console.log('四种媒体的手机实际使用通过');
  } catch (e) {
    if (page) {
      await page
        .screenshot({ path: path.join(evidence, '媒体页面失败.png'), fullPage: true })
        .catch(() => {});
      console.error((await page.locator('body').innerText()).slice(-1200));
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
