// Isolated, anonymous, read-only production regression. No API/auth fixtures;
// only third-party SDK initialization is stubbed, not asserted as business QA.
const fs = require('fs'), path = require('path'), assert = require('assert/strict');
const {chromium} = require('/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const key = process.argv[2], base = '/private/tmp/m008-r3-release-20261024';
assert(['rh', '008', '010'].includes(key));
const origin = 'https://' + key + '.cc3798.com';
async function main() {
  const browser = await chromium.launch({channel: 'chrome', headless: true});
  try {
    const results = [];
    for (const width of [320, 375]) {
      console.log(JSON.stringify({stage: 'start', site: key, width}));
      const page = await browser.newPage({viewport: {width, height: 812}}), errors = [];
      page.setDefaultTimeout(20000);
      page.on('pageerror', e => errors.push(e.message));
      await page.route('**/*', route => {
        if (/\/jweixin-|\/jwxwork-/.test(route.request().url())) return route.fulfill({contentType: 'application/javascript', body: 'window.jWeixin=window.wx={ready:function(){},error:function(){},config:function(){}};'});
        return route.continue();
      });
      const regionResponse = await page.request.get(origin + '/api/store/list?is_select=1&region_options=1');
      const regions = await regionResponse.json();assert.equal(Number(regions.status), 200);
      await page.goto(origin + '/pages/index/index', {waitUntil: 'domcontentloaded', timeout: 60000});
      await page.locator('#target').waitFor({timeout: 60000});
      console.log(JSON.stringify({stage: 'homepage-ready', site: key, width}));
      // Remote legacy images need not finish loading to initialize navigation.
      // The visible bottom bar is the completed homepage's readiness signal.
      await page.evaluate(() => new Promise((resolve, reject) => uni.navigateTo({url: '/pages/store/list/index?type=1&isCollage=2&is_select=1', success: resolve, fail: reject})));
      const name = page.locator('.header .search .name');await name.click();
      const drawer = page.locator('.store-regions');await drawer.waitFor();
      await page.waitForFunction(() => document.querySelector('.store-regions').getBoundingClientRect().bottom <= innerHeight + 1, null, {timeout: 10000});
      console.log(JSON.stringify({stage: 'drawer-ready', site: key, width}));
      await drawer.locator('.all-option').waitFor();
      await page.screenshot({path: path.join(base, key + '-regions-' + width + '.png')});
      const p = regions.data.regions[0];
      if (p) {
        await drawer.getByRole('button', {name: p.label, exact: true}).click();
        const city = p.children[0];assert(city);
        await drawer.getByRole('button', {name: city.label, exact: true}).click();
        const expected = await (await page.request.get(origin + '/api/store/list?is_select=1&province=' + p.id + '&city=' + city.id)).json();
        const waiting = page.waitForResponse(r => {const u = new URL(r.url());return u.pathname === '/api/store/list' && u.searchParams.get('city') === String(city.id) && !u.searchParams.has('region_options');});
        await drawer.getByRole('button', {name: '全市门店', exact: true}).click();
        const actual = await (await waiting).json();assert.deepEqual(actual.data.map(s => s.id), expected.data.map(s => s.id));
        await page.waitForFunction(label => document.querySelector('.header .search .name').textContent.includes(label), city.label);
      } else await drawer.locator('.all-option').click();
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);assert.equal(overflow, false);
      await page.screenshot({path: path.join(base, key + '-online-' + width + '.png')});
      assert.deepEqual(errors, []);
      results.push({width, regionCount: regions.data.regions.length, actualAPI: true, horizontalOverflow: false, errors});
      await page.close();
    }
    const receipt = {site: key, result: 'PASS', APIDataMocked: false, readOnly: true, thirdPartySDKTested: false, results};
    fs.writeFileSync(path.join(base, key + '-browser.json'), JSON.stringify(receipt, null, 2));console.log(JSON.stringify(receipt));
  } finally {await browser.close();}
}
main().catch(e => {console.error(e);process.exitCode = 1;});
