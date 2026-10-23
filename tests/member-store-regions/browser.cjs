// Actual HBuilderX H5 bundle. HTTP fixtures isolate UI states; real MySQL scope
// and controller execution are separately covered by regions.php.
const fs = require('fs'), path = require('path'), assert = require('assert/strict');
const {chromium} = require('/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const {regions} = require('./contract.cjs');
const evidence = path.join(__dirname, 'evidence');
fs.mkdirSync(evidence, {recursive: true});
async function run() {
  const browser = await chromium.launch({channel: 'chrome', headless: true});
  const cases = [];
  try {
    for (const width of [320, 375]) {
      const page = await browser.newPage({viewport: {width, height: 812}});
      const errors = [], requests = [];
      let fail = false, empty = false;
      page.on('pageerror', e => errors.push(e.message));
      await page.route('**/api/store/list*', async route => {
        const query = Object.fromEntries(new URL(route.request().url()).searchParams);
        requests.push(query);
        if (query.region_options) {
          if (fail) return route.fulfill({status: 200, contentType: 'application/json', body: JSON.stringify({status: 400, msg: '测试失败'})});
          return route.fulfill({json: {status: 200, data: {regions: empty ? [] : regions, incomplete_count: empty ? 0 : 2}}});
        }
        const id = Number(query.province) === 6 ? 130 : 129;
        return route.fulfill({json: {status: 200, data: [{id, name: id === 130 ? '杭州门店' : '广州门店', address: '示例地区', detailed_address: '示例路1号', day_time: '10:00 - 20:00'}]}});
      });
      // No remote domains are needed by this frontend regression.
      await page.route('**/*', route => {
        if (/\/jweixin-|\/jwxwork-/.test(route.request().url())) return route.fulfill({contentType: 'application/javascript', body: 'window.jWeixin=window.wx={ready:function(){},error:function(){},config:function(){}};'});
        const host = new URL(route.request().url()).hostname;
        return ['127.0.0.1', 'localhost'].includes(host) ? route.fallback() : route.abort();
      });
      await page.goto('http://127.0.0.1:8081/');
      await page.waitForFunction(() => window.uni && uni.navigateTo);
      await page.waitForURL('**/pages/index/index');
      await page.locator('#target').waitFor();
      await page.waitForLoadState('networkidle');
      await page.evaluate(() => new Promise((resolve, reject) => uni.navigateTo({url: '/pages/store/list/index?type=1&isCollage=2&is_select=1&storeId=130', success: resolve, fail: reject})));
      const name = page.locator('.header .search .name');
      try {await name.waitFor({timeout: 10000});} catch (e) {
        console.error({url: page.url(), body: (await page.locator('body').innerText()).slice(0,1500), errors});
        await page.screenshot({path: path.join(evidence, 'failure.png')});throw e;
      }
      await name.click();
      const drawer = page.locator('.store-regions');
      const settleDrawer = () => page.waitForFunction(() => {
        const el = document.querySelector('.store-regions');
        const box = el && el.getBoundingClientRect();
        return box && box.height > 100 && box.bottom <= innerHeight + 1 && box.top >= 0;
      });
      try {await drawer.getByRole('button', {name: '广东省', exact: true}).waitFor({timeout: 10000});} catch (e) {
        console.error({stage: 'regions', body: (await page.locator('body').innerText()).slice(-1600), requests, errors});
        await page.screenshot({path: path.join(evidence, 'failure.png')});throw e;
      }
      await settleDrawer();
      await page.screenshot({path: path.join(evidence, `regions-${width}.png`)});
      await drawer.getByRole('button', {name: '广东省', exact: true}).click();
      await drawer.getByRole('button', {name: '全省门店', exact: true}).waitFor();
      await drawer.getByRole('button', {name: '广州市', exact: true}).click();
      await drawer.getByRole('button', {name: '全市门店', exact: true}).waitFor();
      await page.screenshot({path: path.join(evidence, `city-${width}.png`)});
      await drawer.getByRole('button', {name: '全市门店', exact: true}).click();
      await page.waitForFunction(() => document.querySelector('.header .search .name').textContent.includes('广州市'));
      assert(requests.some(q => q.province === '1' && q.city === '2' && q.area === '0'));
      await name.click();await drawer.getByRole('button', {name: '海珠区', exact: true}).click();
      await page.waitForFunction(() => document.querySelector('.header .search .name').textContent.includes('海珠区'));
      await name.click();await drawer.getByRole('button', {name: '全部地区', exact: true}).first().click();
      await drawer.locator('.all-option').click();
      await page.waitForFunction(() => document.querySelector('.header .search .name').textContent.includes('全部'));
      fail = true;await name.click();await drawer.getByText('地区加载失败', {exact: true}).waitFor();
      await page.screenshot({path: path.join(evidence, `retry-${width}.png`)});
      fail = false;await drawer.getByRole('button', {name: '重试', exact: true}).click();
      await drawer.getByRole('button', {name: '广东省', exact: true}).waitFor();
      await drawer.getByRole('button', {name: '关闭', exact: true}).click();
      empty = true;await name.click();await drawer.getByText('暂无可筛选地区', {exact: true}).waitFor();
      await settleDrawer();
      const bounds = await drawer.evaluate(el => {
        const rect = el.getBoundingClientRect();
        return {left: rect.left, right: rect.right, bottom: rect.bottom, width: innerWidth,
          overflowing: [...el.querySelectorAll('button')].filter(b => b.scrollWidth > b.clientWidth + 1).map(b => b.textContent)};
      });
      assert(bounds.left >= -1 && bounds.right <= width + 1 && bounds.bottom <= 813);
      assert.deepEqual(bounds.overflowing, []);
      assert(requests.filter(q => q.region_options).every(q => q.is_select === '1' && q.province === '0' && q.city === '0'));
      assert.deepEqual(errors, []);
      cases.push({width, result: 'PASS', bounds, requests, errors});
      await page.close();
    }
    fs.writeFileSync(path.join(evidence, 'browser-result.json'), JSON.stringify({result: 'PASS', actualCompiledH5: true, httpBoundaryFixtures: true, cases}, null, 2));
    console.log('PASS actual HBuilderX H5 320/375: regions, whole city, district, clear, failure/retry, empty, bounds.');
  } finally {await browser.close();}
}
run().catch(error => {console.error(error);process.exitCode = 1;});
