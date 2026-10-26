// Read-only local H5 -> real API -> local MySQL smoke. Only external WeChat
// SDK initialization is replaced; no API data, auth or store filtering is mocked.
const fs = require('fs'), path = require('path'), http = require('http'), assert = require('assert/strict');
const {chromium} = require('/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const evidence = path.join(__dirname, 'evidence');
const get = query => new Promise((resolve, reject) => http.get('http://127.0.0.1:8080/api/store/list?' + new URLSearchParams(query), response => {
  let body = '';response.on('data', b => {body += b;});response.on('end', () => {try {resolve(JSON.parse(body));} catch (e) {reject(e);}});
}).on('error', reject));
async function run() {
  const response = await get({region_options: 1, is_select: 1});
  assert.equal(response.status, 200);
  const province = response.data.regions.find(p => p.children.length);
  assert(province, 'Local fixture must have at least one classified store');
  const city = province.children[0];
  const expected = await get({is_select: 1, province: province.id, city: city.id});
  assert(expected.data.length > 0);
  const browser = await chromium.launch({channel: 'chrome', headless: true});
  try {
    const page = await browser.newPage({viewport: {width: 375, height: 812}}), errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.route('**/*', route => {
      if (/\/jweixin-|\/jwxwork-/.test(route.request().url())) return route.fulfill({contentType: 'application/javascript', body: 'window.jWeixin=window.wx={ready:function(){},error:function(){},config:function(){}};'});
      return ['127.0.0.1', 'localhost'].includes(new URL(route.request().url()).hostname) ? route.continue() : route.abort();
    });
    await page.goto('http://127.0.0.1:8081/');
    await page.waitForURL('**/pages/index/index');await page.locator('#target').waitFor();await page.waitForLoadState('networkidle');
    await page.evaluate(() => new Promise((resolve, reject) => uni.navigateTo({url: '/pages/store/list/index?type=1&isCollage=2&is_select=1', success: resolve, fail: reject})));
    const name = page.locator('.header .search .name');
    await name.click();
    const drawer = page.locator('.store-regions');
    await drawer.getByRole('button', {name: province.label, exact: true}).waitFor();
    await page.waitForFunction(() => document.querySelector('.store-regions').getBoundingClientRect().bottom <= innerHeight + 1);
    await page.screenshot({path: path.join(evidence, 'real-local-375.png')});
    await drawer.getByRole('button', {name: province.label, exact: true}).click();
    await drawer.getByRole('button', {name: city.label, exact: true}).click();
    const result = page.waitForResponse(r => {
      const url = new URL(r.url());return url.pathname === '/api/store/list' && url.searchParams.get('city') === String(city.id) && !url.searchParams.has('region_options');
    });
    await drawer.getByRole('button', {name: '全市门店', exact: true}).click();
    const actual = await (await result).json();
    assert.deepEqual(actual.data.map(s => s.id), expected.data.map(s => s.id));
    await page.waitForFunction(label => document.querySelector('.header .search .name').textContent.includes(label), city.label);
    await page.screenshot({path: path.join(evidence, 'real-filtered-375.png')});
    assert.deepEqual(errors, []);
    const receipt = {result: 'PASS', realLocalAPI: true, APIDataMocked: false, readOnly: true, province: province.id, city: city.id, matchedStores: actual.data.length, incompleteLocalStores: response.data.incomplete_count, errors};
    fs.writeFileSync(path.join(evidence, 'real-browser-result.json'), JSON.stringify(receipt, null, 2));
    console.log(JSON.stringify(receipt));
  } finally {await browser.close();}
}
run().catch(error => {console.error(error);process.exitCode = 1;});
