import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import vm from 'node:vm';

// Exercise the real header method, Vue Router matching and authentication
// guard together. A granted leaf must not require its unselected ancestor.
const admin = new URL('../../../前端代码/admin/', import.meta.url);
const read = name => readFileSync(new URL(`src/${name}`, admin), 'utf8');
const require = createRequire(new URL('package.json', admin));
const Vue = require('vue');
const VueRouter = require('vue-router');
Vue.use(VueRouter);
const context = vm.createContext({
  Setting: { roterPre: '/admin' }, BasicLayout: {}, util: {}, URLSearchParams,
  Vue: { prototype: { __getToken: () => 'local-test-session' } },
});
for (const name of ['storeOperationReports', 'phaseSixReports', 'sixDimensionReports', 'phaseFourReports']) {
  vm.runInContext(read(`libs/${name}.js`).replace(/export\s*\{[\s\S]*?\};?/g, '').replace(/export /g, ''), context);
}
vm.runInContext(read('libs/system/index.js').replace(/^import .*;$/gm, '').replace(/^export \{.*\};$/gm, ''), context);
const util = read('libs/util.js');
vm.runInContext(util.slice(util.indexOf('util.makeMenu ='), util.indexOf('/**\n * 平台端免密')), context);
vm.runInContext(read('router/modules/report.js').replace(/^import .*;$/gm, '').replace('export default', 'var reportRoutes ='), context);
const router = new VueRouter({ mode: 'abstract', routes: [context.reportRoutes] });
const source = read('router/index.js');
vm.runInContext(source.slice(source.indexOf('async function handleAuthenticatedRoute('), source.indexOf('function handlePublicRoute(')), context);
const header = read('layouts/basic-layout/menu-head/index.vue');
vm.runInContext(`var headerMethods = {${header.slice(header.indexOf('    tabClick(name)'), header.indexOf('  watch:')).replace(/\s*},\s*$/, '')}}`, context);

let refreshCalls;
async function authorize(code, access, refreshed = access) {
  refreshCalls = 0;
  context.store = {
    state: { admin: { user: { info: { level: 1, account: 'employee', admin_type: 0 } } } },
    async dispatch(action) {
      if (action === 'admin/db/database') return {};
      assert.equal(action, 'admin/menus/getMenusNavList');
      refreshCalls++;
      return { data: { unique: refreshed } };
    },
  };
  const route = router.match(`/admin/report/store-operations/${code}`);
  let result = 'not-called';
  await context.handleAuthenticatedRoute(route, value => { result = value; }, [], access);
  return result && JSON.parse(JSON.stringify(result));
}

const leaf = 'partner_item_summary';
const grant = `admin-report-store-operations-${leaf}`;
const tree = { header: 'report', path: '/admin/report', children: [{
  path: '/admin/report/business-center', children: [{ path: `/admin/report/store-operations/${leaf}` }],
}] };
let destination;
context.headerMethods.tabClick.call({
  filterHeader: [tree], resolveDefaultMenuPath: context.util.resolveDefaultMenuPath,
  $router: { push: path => { destination = path; } },
}, 'report');
assert.equal(destination, `/admin/report/store-operations/${leaf}`);
assert.equal(await authorize(leaf, [grant]), undefined);
assert.equal(refreshCalls, 0);
assert.equal(await authorize(leaf, [], [grant]), undefined);
assert.equal(refreshCalls, 1);
for (const access of [[], ['admin-report'], ['admin-report-store-operations'], ['report-sale-info'], ['admin-report-store-operations-market_detail']]) {
  assert.deepEqual(await authorize(leaf, access), { name: '403' });
}
assert.deepEqual(await authorize('unregistered', [grant, 'admin-report']), { name: '403' });
for (const code of vm.runInContext('STORE_OPERATION_REPORT_CODES', context)) {
  assert.equal(await authorize(code, [`admin-report-store-operations-${code}`]), undefined);
}
for (const code of vm.runInContext('PHASE_SIX_REPORT_CODES', context)) {
  assert.equal(await authorize(code, [`admin-report-phase-six-${code}`]), undefined);
}
console.log('PASS header click, real route matching, exact leaf permissions, stale permission refresh, parent-only/sibling/unknown denial, all registered reports and phase-six legacy links');
