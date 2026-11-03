/** R44 presentation contracts: execute real component methods with isolated transport, never business DB. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../..');
const admin = path.join(root, '前端代码/admin');
const compiler = require(path.join(admin, 'node_modules/vue-template-compiler'));
const parser = require(path.join(admin, 'node_modules/@babel/parser'));
const dayjs = require(path.join(admin, 'node_modules/dayjs'));
function component(file, deps) {
  const source = fs.readFileSync(path.join(root, file), 'utf8');
  const parsed = compiler.parseComponent(source);
  assert.deepEqual(compiler.compile(parsed.template.content).errors, [], file + ' template');
  parser.parse(parsed.script.content, { sourceType: 'module' });
  const code = parsed.script.content.replace(/^import .*?;\s*$/gm, '').replace('export default', 'module.exports =');
  const context = { module: { exports: {} }, dayjs, formatBusinessMoney: value => value == null ? '—' : String(Math.round(Number(value))), mapGetters: () => ({}), toLogin() {}, ...deps };
  vm.runInNewContext(code, context, { filename: file });
  const definition = context.module.exports;
  const instance = definition.data();
  Object.entries(definition.methods).forEach(([key, fn]) => instance[key] = fn.bind(instance));
  Object.entries(definition.computed).forEach(([key, fn]) => Object.defineProperty(instance, key, { get: fn.bind(instance) }));
  return instance;
}
const json = v => JSON.parse(JSON.stringify(v));
const deferred = () => { let resolve, reject; const promise = new Promise((r, e) => { resolve = r; reject = e; }); return { promise, resolve, reject }; };
(async () => {
  const requests = [], detailRequests = [], exportRequests = [], exports = [], pending = [];
  const fullscreenDocument = { exitFullscreen() { throw new Error('page presentation must not call browser fullscreen'); } };
  const screen = component('前端代码/admin/src/pages/marketing/storeCombination/dashboard.vue', {
    document: fullscreenDocument,
    getCombinationDashboardSettings: async () => ({data:{activity_ids:[2,3]}}),
    getCombinationDashboardActivities: async () => ({data:{list:[{id:'2',title:'活动二'},{id:'3',title:'活动三'}]}}),
    getCombinationDashboard: filters => { requests.push(json(filters)); const d = deferred(); pending.push(d); return d.promise; },
    saveCombinationDashboardSettings: async values => ({data:values}),
    getCombinationDashboardDetails: async filters => { detailRequests.push(json(filters)); return {data:{list:[{id:filters.page}],count:41,summary:{net_amount:'100.12'}}}; },
    exportCombinationDashboardDetails: async filters => { exportRequests.push(json(filters)); return {data:{header:['净业绩'],filekey:['net_amount'],filename:'拼团业绩',export:[{net_amount:'100.12'}],summary:{net_amount:'100.12'},source_notes:['测试口径'],metadata:{filters},count:1}}; },
    exportExcel: (...args) => exports.push(args)
  });
  const init = screen.initialize(); await new Promise(setImmediate);
  assert.equal(screen.applied.activity_ids, '2,3'); pending.shift().resolve({data:{metrics:{total_amount:'100.12'}}}); await init;
  assert.equal(screen.metricValue('total_amount'),'100');
  const earlier = screen.loadDashboard(); screen.applied.activity_ids = '3'; const later = screen.loadDashboard();
  pending[1].resolve({data:{metrics:{total_amount:'200.80'}}}); await later;
  pending[0].resolve({data:{metrics:{total_amount:'9999'}}}); await earlier; pending.length = 0;
  assert.equal(screen.metricValue('total_amount'),'201','stale refresh cannot replace latest filter');
  screen.openDetails('member', 8, '员工甲', 'unformed'); await new Promise(setImmediate);
  const filterSnapshot = json(screen.detailFilter);
  screen.applied.activity_ids = '2'; await screen.loadDetails(2); await screen.exportDetails();
  assert.deepEqual(detailRequests[1], {...filterSnapshot,page:2,limit:20});
  assert.deepEqual(exportRequests[0],filterSnapshot,'export same exact drilldown, no page limitation');
  assert.equal(exports[0][0].export[0].net_amount,'100.12','export retains cents');
  assert.equal(exports[0][0].summary.net_amount,'100.12');
  assert.deepEqual(json(exports[0][0].metadata.filters),filterSnapshot,'workbook receives exact query metadata');
  assert.equal(screen.detailPages,3);
  screen.selectedActivities = []; const save = screen.saveSettings(); await new Promise(setImmediate);
  assert.equal(screen.applied.activity_ids,'','empty activities means all authorized'); pending.shift().resolve({data:{metrics:{}}}); await save;

  // A failed new filter must not leave clickable ranks from the previous scope.
  screen.dashboard = {metrics:{total_amount:'99'},rankings:{members:[{id:8,total_amount:'99'}]}};
  screen.loaded = true;
  screen.dateDraft = {start_date:'2026-10-01',end_date:'2026-10-02'};
  screen.query();
  assert.equal(screen.rankRows('members').length,0,'new dates immediately discard old ranks');
  pending.shift().reject(new Error('test query failure')); await new Promise(setImmediate);
  assert.equal(screen.rankRows('members').length,0,'failed new query cannot drill down stale ranks');
  assert.equal(screen.metricValue('total_amount'),'—');
  screen.dashboard = {metrics:{total_amount:'88'},rankings:{members:[{id:9}]}};
  screen.loaded = true;
  const refresh = screen.loadDashboard(); pending.shift().reject(new Error('test refresh failure')); await refresh;
  assert.equal(screen.rankRows('members')[0].id,9,'same-filter refresh retains last confirmed result');
  screen.selectedActivities = [3];
  const changedScope = screen.saveSettings(); await new Promise(setImmediate);
  assert.equal(screen.rankRows('members').length,0,'saved activity scope also discards old ranks');
  pending.shift().resolve({data:{metrics:{total_amount:'0'},rankings:{members:[]}}}); await changedScope;

  // Exercise actual page-mode methods. Native APIs must never run, including
  // supported browsers: their automatic exit events cannot undo the chosen mode.
  screen.$refs = { screen: { requestFullscreen() { throw new Error('native fullscreen must not run'); } } };
  screen.error = '';
  screen.toggleFullscreen();
  assert.equal(screen.fullscreen,true,'first click opens immersive page immediately');
  screen.toggleFullscreen();
  assert.equal(screen.fullscreen,false,'second click exits immersive page');
  screen.toggleFullscreen();
  screen.settingsOpen = screen.detailsOpen = screen.notesOpen = true;
  screen.onEscape({key:'Enter'}); assert.equal(screen.fullscreen,true,'unrelated key does not exit');
  screen.onEscape({key:'Escape'});
  assert.equal(screen.fullscreen,false,'Escape exits immersive page');
  assert(!screen.settingsOpen && !screen.detailsOpen && !screen.notesOpen,'Escape closes overlays consistently');
  assert.equal(screen.error,'','display action does not become a data error');
  screen.$refs = {};
  screen.toggleFullscreen(); assert.equal(screen.fullscreen,true,'mode does not depend on browser API or DOM capability');
  screen.exitFullscreen(); screen.exitFullscreen();
  assert.equal(screen.fullscreen,false,'cleanup exit is idempotent');

  const calls = [], memberPending=[];
  const member = component('前端代码/uniapp/pages/users/combination_promotion/index.vue', {
    getCombinationPromotionSummary: filters => { calls.push(['summary',json(filters)]); const d=deferred();memberPending.push(d);return d.promise; },
    getCombinationPromotionOrders: filters => { calls.push(['orders',json(filters)]); const d=deferred();memberPending.push(d);return d.promise; }
  });
  const first=member.load(1); member.applied.activity_id=3; member.applied.status='formed'; const second=member.load(2);
  memberPending[2].resolve({data:{metrics:{total_amount:'77.70'},activities:[{id:3,name:'活动三'}]}});
  memberPending[3].resolve({data:{list:[{id:22}],count:41}}); await second;
  memberPending[0].resolve({data:{metrics:{total_amount:'999'}}});memberPending[1].resolve({data:{list:[{id:1}],count:1}});await first;
  assert.equal(member.metrics.total_amount,'77.70');assert.equal(member.orders[0].id,22);assert.equal(member.pages,3);
  assert.equal(calls[3][1].limit,20);assert.equal(calls[3][1].activity_id,3);assert.equal(calls[3][1].status,'formed');
  assert(!('uid' in calls[3][1]),'member identity fixed by server, not submitted by client');
  assert.equal(member.activityIndex,1);
  console.log('PASS R44 frontend: template compilation, persisted settings, request race, exact drilldown/export, immersive page toggle/Escape/cleanup without native API, cents export, member identity and 20-row paging');
})().catch(error=>{console.error(error);process.exit(1)});
