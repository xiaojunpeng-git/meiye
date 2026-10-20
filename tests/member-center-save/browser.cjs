// Use the existing canonical HBuilderX H5 server, intercepting only its read API.
// This isolated browser has no real credentials and cannot submit business writes.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require('playwright');
const api = JSON.parse(fs.readFileSync(process.env.R8_MENU_API || '/private/tmp/member-center-r8-api.json'));
const evidence = path.join(__dirname, 'evidence');
(async () => {
  const browser = await chromium.launch({headless: true, channel: process.env.R8_BROWSER_CHANNEL || 'chrome'});
  const records = [];
  try {
    for (const width of [375, 320]) {
      const context = await browser.newContext({viewport: {width, height: 812}});
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      page.on('console', message => {if (message.type() === 'error') errors.push(message.text());});
      await page.route('**/*', async route => {
        const req = route.request(), url = new URL(req.url());
        if (['res.wx.qq.com', 'open.work.weixin.qq.com'].includes(url.hostname) && req.method() === 'GET') {
          return route.continue();
        }
        if (req.method() === 'OPTIONS') {
          return route.fulfill({status: 204, headers: {'access-control-allow-origin': '*',
            'access-control-allow-headers': '*', 'access-control-allow-methods': 'GET, OPTIONS'}});
        }
        if (/\/api\/menu\/user$/.test(url.pathname)) {
          return route.fulfill({json: api, headers: {'access-control-allow-origin': '*'}});
        }
        if (!['127.0.0.1', 'localhost'].includes(url.hostname) || !['GET', 'OPTIONS'].includes(req.method())) {
          return route.abort();
        }
        return route.continue();
      });
      await page.goto('http://127.0.0.1:8081/pages/user/index');
      const service = page.locator('.service-card');
      try {
        await service.getByText('我的预约', {exact: true}).waitFor();
      } catch (error) {
        await page.screenshot({path: '/private/tmp/member-center-r8-h5-failure.png'});
        throw new Error(error.message + '\n' + JSON.stringify(errors) + '\n' + await page.locator('body').innerText());
      }
      const menuCount = api.data.diy_data.menu.list.length;
      assert.equal(await service.locator('.menu-icon').count(), menuCount);
      const layout = await service.evaluate(el => {
        const rect = el.getBoundingClientRect();
        return {width: rect.width, height: rect.height, viewport: innerWidth,
          names: [...el.querySelectorAll('uni-text')].filter(n => n.textContent.trim())
            .map(n => ({name: n.textContent.trim(), width: n.getBoundingClientRect().width}))};
      });
      assert(layout.height > 100 && layout.width <= width);
      await service.screenshot({path: path.join(evidence, `h5-services-${width}.png`)});
      await page.reload();
      await page.locator('.service-card').getByText('我的预约', {exact: true}).waitFor();
      assert.equal(await page.locator('.service-card .menu-icon').count(), menuCount);
      records.push({width, menuCount, layout, refresh: 'PASS'});
      await context.close();
    }
    fs.writeFileSync(path.join(evidence, 'browser-result.json'), JSON.stringify({status: 'PASS', records}, null, 2));
    console.log(JSON.stringify({status: 'PASS', records}));
  } finally {await browser.close();}
})().catch(error => {console.error(error); process.exitCode = 1;});
