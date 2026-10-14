import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

// Execute the real registries and frame; every registered route must retain its
// code/title across the Vue 2 -> Vue 3 boundary, including repeated navigation.
const root = new URL('../../../前端代码/admin/src/', import.meta.url);
const read = path => readFileSync(new URL(path, root), 'utf8');
const context = vm.createContext({ URLSearchParams, process: { env: {} }, window: { location: { origin: 'http://localhost' } } });
for (const path of ['libs/sixDimensionReports.js', 'libs/phaseFourReports.js']) {
  vm.runInContext(read(path).replace(/export\s*\{[\s\S]*?\};?/g, '').replace(/export /g, ''), context);
}
const script = read('pages/report/data/six_dimension.vue').match(/<script>([\s\S]*?)<\/script>/)[1]
  .replace(/^import .+$/gm, '').replace('export default', 'var component =');
vm.runInContext(script, context);
const reports = vm.runInContext('FRAME_REPORTS', context);
for (const report of reports) {
  const instance = { $route: { meta: { reportCode: report.code }, params: {}, query: { start_date: '2026-10-01', store_ids: ['1', '2'] } } };
  for (const [key, compute] of Object.entries(context.component.computed)) {
    Object.defineProperty(instance, key, { get: () => compute.call(instance) });
  }
  assert.equal(instance.reportCode, report.code);
  assert.equal(instance.reportTitle, report.title);
  assert.ok(instance.reportUrl.includes(`#/platform/reports/${report.code}?`));
  assert.ok(instance.reportUrl.includes('start_date=2026-10-01&store_ids=1&store_ids=2'));
  instance.$route.meta = { settingsPage: 'consumption-tiers' };
  assert.ok(instance.reportUrl.includes('#/platform/six-dimension/consumption-tiers'));
}
console.log(`PASS ${reports.length} report routes preserve iframe code/title/filters and consumption-tier settings`);
