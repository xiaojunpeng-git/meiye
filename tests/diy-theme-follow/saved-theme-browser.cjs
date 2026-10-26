// Consume the real local PHP controller response in the existing member build.
// Theme color is never assigned to the page VM: App.vue must resolve the palette.
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(process.env.MEMBER_H5_TEST_ROOT || '');
const fixture = JSON.parse(fs.readFileSync(process.env.THEME_API_FIXTURE, 'utf8'));
assert.equal(fixture.data.status, 7, 'Fixture must come from the successful local wine save');
assert(fs.existsSync(path.join(root, 'index.html')), 'Explicit compiled member H5 input required');
const evidence = path.join(__dirname, 'evidence/theme-save-2026-10-22');
fs.mkdirSync(evidence, { recursive: true });
const server = http.createServer((req, res) => {
  const pathname = new URL(req.url, 'http://localhost').pathname;
  const file = pathname.startsWith('/pages/') ? path.join(root, 'index.html') : path.join(root, pathname);
  if (!file.startsWith(root + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
    res.writeHead(404).end(); return;
  }
  const types = {'.js': 'application/javascript', '.css': 'text/css', '.html': 'text/html',
    '.woff': 'font/woff', '.woff2': 'font/woff2', '.png': 'image/png'};
  res.setHeader('Content-Type', types[path.extname(file)] || 'application/octet-stream');
  fs.createReadStream(file).pipe(res);
});
(async () => {
  let browser;
  const requests = [], cases = [], warnings = [];
  try {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    browser = await chromium.launch({headless: true, channel: 'chrome'});
    const page = await browser.newPage({viewport: {width: 375, height: 812}});
    page.on('pageerror', error => warnings.push(error.message));
    await page.route('**/api/**', async route => {
      const request = route.request(), url = new URL(request.url());
      requests.push({path: url.pathname, method: request.method()});
      assert.equal(request.method(), 'GET', 'Browser verification cannot write business data');
      if (url.pathname === '/api/get_script') {
        await route.fulfill({body: '', contentType: 'application/javascript'}); return;
      }
      let response = {status: 200, data: {}, msg: 'ok'};
      if (url.pathname.includes('color_change')) response = fixture;
      if (url.pathname === '/api/navigation') response.data = {
        name: 'pageFoot', effectConfig: {tabVal: 1}, navConfig: {tabVal: 0}, navStyleConfig: {tabVal: 0}, toneConfig: {tabVal: 0},
        topConfig: {val: 0}, bottomConfig: {val: 0}, prConfig: {val: 0}, mbConfig: {val: 0},
        bgColor: {color: [{item: '#fff'}]}, bgColor2: {color: [{item: '#fff'}]},
        menuList: [{name: '服务', icon: 'icon-ic_mall', link: '/pages/activity/therapist_list/index'}],
      };
      if (url.pathname.includes('reservation/staff/list')) response.data = {list: [
        {id: 1, staff_name: '验证老师', position_label: '美容师', star_level: 4},
      ]};
      await route.fulfill({json: response});
    });
    for (const width of [375, 320]) {
      await page.setViewportSize({width, height: 812});
      await page.goto('http://127.0.0.1:' + server.address().port +
        '/pages/activity/therapist_list/index?store_id=130&position_ids=69', {waitUntil: 'domcontentloaded'});
      await page.locator('.btn-primary').waitFor({timeout: 20000});
      await page.waitForFunction(() => getComputedStyle(document.querySelector('.btn-primary')).backgroundColor === 'rgb(123, 41, 65)');
      const row = await page.locator('.btn-primary').evaluate(element => {
        const style = getComputedStyle(element), rect = element.getBoundingClientRect();
        return {theme: style.getPropertyValue('--view-theme').trim(), background: style.backgroundColor,
          backgroundImage: style.backgroundImage, left: rect.left, right: rect.right};
      });
      assert.equal(row.theme.toLowerCase(), '#7b2941');
      assert.equal(row.backgroundImage, 'none');
      assert(row.left > 0 && row.right < width, 'Booking button overflow');
      cases.push({width, ...row});
      await page.screenshot({path: path.join(evidence, 'booking-wine-' + width + '.png')});
    }
    assert(requests.some(request => request.path.includes('color_change')));
    assert.deepEqual(warnings, [], 'Fixture bootstrap errors must not hide a theme failure');
    const result = {result: 'PASS', source: 'Local MySQL save -> actual public controller response -> compiled App.vue -> booking button',
      productionConfigurationWrites: 0, cases, requests, warnings};
    fs.writeFileSync(path.join(evidence, 'browser-result.json'), JSON.stringify(result, null, 2) + '\n');
    console.log(JSON.stringify(result));
  } finally {
    if (browser) await browser.close();
    await new Promise(resolve => server.close(resolve));
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
