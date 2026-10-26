// Compile real SFCs, execute their actual range adapters, and exercise the
// shared calendar in Chromium. No report values or API results are fabricated.
const fs = require('fs'), path = require('path'), assert = require('assert/strict'), vm = require('vm');
const { createRequire } = require('module');
const root = path.resolve(__dirname, '../../..'), front = path.join(root, '前端代码/cashier-v3');
const local = createRequire(path.join(front, 'package.json'));
const { parse, compileScript, compileTemplate } = local('@vue/compiler-sfc');
const babel = local('@babel/parser');
const evidence = path.join(root, 'tests/cashier-v3/evidence/R54-query-period');
let checks = 0;
const check = (value, label) => { assert.ok(value, label); checks++; };
const read = relative => fs.readFileSync(path.join(front, 'src', relative), 'utf8');
const adapters = [
  ['components/member/MemberDetailOverlay.vue', 'tabPeriod', { tabDateFrom: { value: 'a' }, tabDateTo: { value: 'b' } }],
  ['views/BusinessDashboardView.vue', 'queryPeriod', { localScope: { value: { start: 'a', end: 'b', organization: 'unchanged' } } }],
  ['views/StoreTargetDashboardView.vue', 'customPeriod', { customStart: { value: 'a' }, customEnd: { value: 'b' } }],
  ['views/CustomerAnalyticsView.vue', 'customPeriod', { customStart: { value: 'a' }, customEnd: { value: 'b' } }],
  ['views/EmployeeDashboardView.vue', 'customPeriod', { startDate: { value: 'a' }, endDate: { value: 'b' } }],
  ['views/EngineeringManagementView.vue', 'queryPeriod', { filters: { value: { start_date: 'a', end_date: 'b', store_ids: [9], keyword: 'unchanged' } } }],
  ['views/RoutePlaceholderView.vue', 'taskQueryPeriod', { taskPlannedFrom: { value: 'a' }, taskPlannedTo: { value: 'b' } }],
  ['views/RoutePlaceholderView.vue', 'recordQueryPeriod', { recordFollowedFrom: { value: 'a' }, recordFollowedTo: { value: 'b' } }],
];
function compile(file, source) {
  const { descriptor, errors } = parse(source, { filename: file }); assert.equal(errors.length, 0);
  const script = compileScript(descriptor, { id: 'r54-period' });
  const template = compileTemplate({ source: descriptor.template.content, filename: file, id: 'r54-period', compilerOptions: { bindingMetadata: script.bindings } });
  assert.deepEqual(template.errors, []); checks++;
  return descriptor;
}
function contracts() {
  const files = new Set([...adapters.map(([file]) => file), 'views/StoreBusinessReportView.vue']);
  for (const file of files) compile(file, read(file));
  compile('UnifiedQueryDateRange.vue', fs.readFileSync(path.join(root, '前端代码/shared/unified-query-vue3/src/components/UnifiedQueryDateRange.vue'), 'utf8'));
  compile('UnifiedQuerySettingsDrawer.vue', fs.readFileSync(path.join(root, '前端代码/shared/unified-query-vue3/src/components/UnifiedQuerySettingsDrawer.vue'), 'utf8'));
  for (const [file, name, bindings] of adapters) {
    const descriptor = parse(read(file)).descriptor, source = descriptor.scriptSetup.content;
    const ast = babel.parse(source, { sourceType: 'module' });
    const declaration = ast.program.body.filter(n => n.type === 'VariableDeclaration').flatMap(n => n.declarations).find(n => n.id.name === name);
    check(Boolean(declaration), file + ' adapter exists');
    const adapter = vm.runInNewContext(source.slice(declaration.init.start, declaration.init.end), { computed: value => value, ...bindings });
    check(JSON.stringify(adapter.get()) === JSON.stringify({ min: 'a', max: 'b' }), 'initial dates preserved');
    adapter.set({ min: '2026-09-28', max: '2026-10-24' });
    check(JSON.stringify(adapter.get()) === JSON.stringify({ min: '2026-09-28', max: '2026-10-24' }), 'confirmed range preserves endpoint contract');
    if (bindings.localScope) check(bindings.localScope.value.organization === 'unchanged', 'dashboard scope unchanged');
    if (bindings.filters) check(bindings.filters.value.store_ids[0] === 9 && bindings.filters.value.keyword === 'unchanged', 'ledger scope and keyword unchanged');
    adapter.set({ min: '', max: '' });
    check(JSON.stringify(adapter.get()) === JSON.stringify({ min: '', max: '' }), 'clear maps to existing empty date fields');
  }
  for (const file of ['views/BusinessDashboardView.vue', 'views/EmployeeDashboardView.vue', 'views/CustomerAnalyticsView.vue', 'views/StoreTargetDashboardView.vue', 'views/EngineeringManagementView.vue', 'views/StoreBusinessReportView.vue', 'components/member/MemberDetailOverlay.vue']) {
    check(!/type="date"/.test(parse(read(file)).descriptor.template.content), 'no legacy query pair remains: ' + file);
  }
  const careTemplate = parse(read('views/RoutePlaceholderView.vue')).descriptor.template.content;
  check(!/v-model="(?:taskPlannedFrom|taskPlannedTo|recordFollowedFrom|recordFollowedTo)"/.test(careTemplate), 'care ranges converted');
  check(careTemplate.includes('v-model="createTaskDraft.plannedDate" type="date"'), 'task entry date untouched');
  const report = read('views/StoreBusinessReportView.vue');
  check(report.includes(':max-date="today()"') && report.includes(':min-date="isFirstPhaseReport ? LEGACY_COVERAGE_START'), 'platform date bounds retained');
}
async function browser() {
  const { createServer } = local('vite');
  const { chromium } = require('/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
  const html = `<html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div id="app"></div><script type="module">
    import {createApp,ref,h} from 'vue';
    import Picker from '/@fs/${path.join(root, '前端代码/shared/unified-query-vue3/src/components/UnifiedQueryDateRange.vue')}';
    import Settings from '/@fs/${path.join(root, '前端代码/shared/unified-query-vue3/src/components/UnifiedQuerySettingsDrawer.vue')}';
    import '/view_cashier_v3/src/styles/base.css';
    const value=ref({min:'2026-10-01',max:'2026-10-24'}),mode=ref('range'),min=ref(''),max=ref('');
    const events=[],settingsEvents=[],showSettings=ref(false); window.fixture={value,mode,min,max,events,settingsEvents,showSettings};
    const fields=[{key:'business_date',label:'业务日期',type:'date'},{key:'amount',label:'金额',type:'number'},{key:'occurred',label:'操作时间',type:'datetime'}];
    const initialSettings={filters:[{field:'business_date',operator:'between',value:['2026-10-01','2026-10-24']},{field:'amount',operator:'between',value:[1,100]},{field:'occurred',operator:'between',value:['2026-10-01T08:00','2026-10-24T18:00']}]};
    createApp({setup(){return()=>showSettings.value?h(Settings,{fields,initialSettings,onApplied:v=>settingsEvents.push(v),onClose:()=>showSettings.value=false}):h('div',{style:'width:280px;height:50px;overflow:hidden;margin:20px'},[h(Picker,{modelValue:value.value,mode:mode.value,minDate:min.value,maxDate:max.value,'onUpdate:modelValue':v=>value.value=v,onChange:v=>events.push(v)})])}}).mount('#app');
  </script></body></html>`;
  const server = await createServer({ root: front, configFile: path.join(front, 'vite.config.js'), cacheDir: '/private/tmp/mohe-r54-query-period-vite-cache', optimizeDeps: { include: ['vue', '@lucide/vue/dist/esm/icons/calculator.mjs', '@lucide/vue/dist/esm/icons/pencil-line.mjs'] }, server: { host: '127.0.0.1', port: 0, strictPort: false }, plugins: [{ name: 'period-fixture', configureServer(s) { s.middlewares.use(async (req, res, next) => {
    if (!req.url.endsWith('/__r54_period')) return next(); res.setHeader('Content-Type', 'text/html'); res.end(await s.transformIndexHtml(req.url, html));
  }); } }] });
  let browser;
  try {
    await server.listen(); browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' });
    const views = [];
    for (const viewport of [{ width: 2016, height: 1076 }, { width: 375, height: 812 }]) {
      const page = await browser.newPage({ viewport }); const errors = []; page.on('pageerror', e => errors.push(e.message));
      await page.goto('http://127.0.0.1:' + server.httpServer.address().port + '/view_cashier_v3/__r54_period');
      const trigger = page.getByRole('button', { name: '查询周期', exact: true });
      const dialog = page.getByRole('dialog', { name: '查询周期选择', exact: true });
      await trigger.click(); await dialog.waitFor();
      await dialog.getByRole('button', { name: '上一月', exact: true }).click();
      await dialog.getByRole('button', { name: '2026-09-28', exact: true }).first().click();
      if (viewport.width < 650) await dialog.getByRole('button', { name: '下一月', exact: true }).first().click();
      await dialog.getByRole('button', { name: '2026-10-24', exact: true }).first().click();
      check(await page.evaluate(() => window.fixture.events.length) === 0, 'two day clicks are draft only');
      await page.keyboard.press('Escape');
      check(await page.evaluate(() => window.fixture.value.value.min) === '2026-10-01', 'escape discards draft');
      await trigger.click(); await dialog.getByRole('button', { name: '上月', exact: true }).click();
      check(await page.evaluate(() => window.fixture.events.length) === 0, 'shortcut is draft until confirmation');
      await dialog.getByRole('button', { name: '确定', exact: true }).click();
      check(await page.evaluate(() => window.fixture.value.value.min === '2026-09-01' && window.fixture.value.value.max === '2026-09-30'), 'confirmed shortcut publishes both endpoints');
      await trigger.click(); await dialog.getByRole('button', { name: '清空', exact: true }).click();
      check(await page.evaluate(() => window.fixture.value.value.min === '' && window.fixture.value.value.max === ''), 'clear is an explicit complete empty range');
      await page.evaluate(() => { window.fixture.value.value={min:'2026-10-01',max:'2026-10-24'}; });
      await trigger.click(); await dialog.getByRole('button', { name: '上一月', exact: true }).click();
      await dialog.getByRole('button', { name: '2026-09-28', exact: true }).first().click();
      if (viewport.width < 650) await dialog.getByRole('button', { name: '下一月', exact: true }).first().click();
      await dialog.getByRole('button', { name: '2026-10-24', exact: true }).first().click();
      await dialog.getByRole('button', { name: '确定', exact: true }).click();
      check(await page.evaluate(() => window.fixture.value.value.min === '2026-09-28' && window.fixture.value.value.max === '2026-10-24'), 'cross-month range is published exactly');
      await page.evaluate(() => { window.fixture.value.value={min:'2026-10-01',max:'2026-10-24'};window.fixture.min.value='2026-10-01';window.fixture.max.value='2026-10-24'; });
      await trigger.click();
      check(await dialog.getByRole('button', { name: '2026-10-25', exact: true }).first().isDisabled(), 'future dates remain blocked');
      check(await dialog.getByRole('button', { name: '上月', exact: true }).isDisabled(), 'coverage minimum blocks older shortcut');
      await dialog.getByRole('button', { name: '本月', exact: true }).click();
      const bounds = await dialog.boundingBox();
      check(bounds.x >= 0 && bounds.y >= 0 && bounds.x + bounds.width <= viewport.width + 1 && bounds.y + bounds.height <= viewport.height + 1, 'teleported panel fits viewport, not clipped by parent');
      await page.screenshot({ path: path.join(evidence, 'period-' + viewport.width + '.png') });
      await dialog.getByRole('button', { name: '确定', exact: true }).click();
      check(await page.evaluate(() => window.fixture.value.value.max) === '2026-10-24', 'bounded current month stops at previous upper date limit');
      await page.evaluate(() => { window.fixture.mode.value='month';window.fixture.min.value='';window.fixture.max.value=''; });
      await trigger.click(); await dialog.getByRole('button', { name: '2026-10-05', exact: true }).first().click();
      await dialog.getByRole('button', { name: '确定', exact: true }).click();
      check(await page.evaluate(() => window.fixture.value.value.min === '2026-10-01' && window.fixture.value.value.max === '2026-10-31'), 'natural-month mode still selects complete month');
      await page.evaluate(() => { window.fixture.mode.value='year'; });
      await trigger.click(); await dialog.getByRole('button', { name: '本年', exact: true }).click(); await dialog.getByRole('button', { name: '确定', exact: true }).click();
      check(await page.evaluate(() => window.fixture.value.value.min === '2026-01-01' && window.fixture.value.value.max === '2026-12-31'), 'natural-year mode still selects complete year');
      await page.evaluate(() => { window.fixture.showSettings.value=true; });
      try { await page.getByRole('button', { name: '业务日期筛选周期', exact: true }).click({ timeout: 5000 }); }
      catch (error) {
        await page.screenshot({ path: path.join(evidence, 'settings-blocked-' + viewport.width + '.png') });
        console.error(await page.locator('.query-settings-rule-card').evaluateAll(nodes => nodes.map(n => ({ classes:n.className, rect:n.getBoundingClientRect().toJSON(), min:getComputedStyle(n).minHeight }))));
        throw error;
      }
      const filterPeriod = page.getByRole('dialog', { name: '业务日期筛选周期选择', exact: true });
      await filterPeriod.getByRole('button', { name: '最近7天', exact: true }).click();
      await page.screenshot({ path: path.join(evidence, 'advanced-period-' + viewport.width + '.png') });
      await filterPeriod.getByRole('button', { name: '确定', exact: true }).click();
      check(await page.evaluate(() => window.fixture.settingsEvents.length) === 0, 'advanced date range remains an unsaved draft');
      check(await page.locator('.query-settings-filter-row input[type=number]').count() === 2, 'numeric between keeps numeric editors');
      check(await page.locator('.query-settings-filter-row input[type=datetime-local]').count() === 2, 'precise datetime retains both time values');
      await page.getByRole('button', { name: '保存', exact: true }).click();
      const saved = await page.evaluate(() => window.fixture.settingsEvents[0]);
      const date = saved.filters.find(f => f.field === 'business_date');
      check(date.operator === 'between' && date.value === '2026-10-18' && date.valueTo === '2026-10-24', 'advanced filter saves exact existing between contract');
      check(errors.length === 0, 'no browser errors'); views.push({ viewport, result: 'PASS' }); await page.close();
    }
    fs.writeFileSync(path.join(evidence, 'result.json'), JSON.stringify({ result: 'PASS', checks, views, backendRequests: 0 }, null, 2));
  } finally { if (browser) await browser.close(); await server.close(); }
}
(async () => { contracts(); await browser(); console.log('R54_QUERY_PERIOD_PASS checks=' + checks); })().catch(error => { console.error(error); process.exitCode = 1; });
