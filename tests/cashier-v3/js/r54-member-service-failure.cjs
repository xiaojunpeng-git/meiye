// Exercise the actual shell loader and Vue SFC. Transport is an isolated
// fixture; backend authority is tested separately against local MySQL.
const fs = require('fs'), path = require('path'), assert = require('assert/strict');
const { createRequire } = require('module');
const root = path.resolve(__dirname, '../../..');
const frontend = path.join(root, '前端代码/cashier-v3');
const localRequire = createRequire(path.join(frontend, 'package.json'));
const { requiredFiles, assertDependencies } = require(path.join(root, 'scripts/r54-member-service-dependency-gate.cjs'));
const source = fs.readFileSync(path.join(frontend, 'src/layouts/CashierShell.vue'), 'utf8');
const loader = source.slice(source.indexOf('async function loadMemberDetailTab('), source.indexOf('\nasync function handleMemberDetailAction('));
let checks = 0;
const check = (ok, label) => { assert.ok(ok, label); checks++; };
function harness() {
  const bindings = {
    memberDetailLoadSequence: 0, isMemberDetailLoading: { value: false }, memberDetailLoadError: { value: '' },
    isMemberDetailOpen: { value: true }, activeMemberDetailId: { value: 1 }, memberDetailFallback: { value: {} },
    requestCashierV3Action: async () => ({ result: { status: 'failed' } }),
    isSucceededResult: r => ['success', 'succeeded'].includes(r?.result?.status) && !r?.requiresRefresh,
    memberDetailId: d => d?.member?.memberId ?? d?.member?.id,
  };
  const vm = require('vm'); vm.createContext(bindings); vm.runInContext(loader, bindings);
  return bindings;
}
const success = records => ({ result: { status: 'success' }, data: { detail: { member: { memberId: 1 }, serviceRecords: records } } });
async function contracts() {
  const h = harness();
  await h.loadMemberDetailTab({ memberId: 1, tab: 'writeoff' });
  check(h.memberDetailLoadError.value !== '' && !h.isMemberDetailLoading.value, 'failed response is an error, not empty');
  h.requestCashierV3Action = async () => { throw Error('private SQL details'); };
  await h.loadMemberDetailTab({ memberId: 1, tab: 'writeoff' });
  check(!h.memberDetailLoadError.value.includes('SQL') && h.memberDetailLoadError.value !== '', 'network errors are safe, caught query failures');
  h.requestCashierV3Action = async () => success([]);
  await h.loadMemberDetailTab({ memberId: 1, tab: 'writeoff' });
  check(h.memberDetailLoadError.value === '' && h.memberDetailFallback.value.serviceRecords.length === 0, 'successful empty query is allowed');
  h.requestCashierV3Action = async () => ({ result: { status: 'success' } });
  await h.loadMemberDetailTab({ memberId: 1 });
  check(h.memberDetailLoadError.value !== '', 'missing detail is not successful empty data');
  h.requestCashierV3Action = async () => ({ result: { status: 'success' }, data: { detail: { member: { memberId: 2 } } } });
  await h.loadMemberDetailTab({ memberId: 1 });
  check(h.memberDetailLoadError.value !== '' && h.memberDetailFallback.value.member.memberId === 1, 'wrong member response cannot overwrite');
  let rejectOld;
  h.requestCashierV3Action = () => new Promise((resolve, reject) => { rejectOld = reject; });
  const old = h.loadMemberDetailTab({ memberId: 1 });
  h.requestCashierV3Action = async () => success([{ id: 'new' }]);
  await h.loadMemberDetailTab({ memberId: 1, tab: 'writeoff' });
  rejectOld(Error('late failure')); await old;
  check(h.memberDetailLoadError.value === '' && h.memberDetailFallback.value.serviceRecords[0].id === 'new', 'late error cannot replace newest success');
  h.requestCashierV3Action = () => new Promise((resolve, reject) => { rejectOld = reject; });
  const closed = h.loadMemberDetailTab({ memberId: 1 });
  h.isMemberDetailOpen.value = false; rejectOld(Error('closed')); await closed;
  check(h.memberDetailLoadError.value === '', 'closed dialog ignores late failure');
  const ref = '5fd8225287b31944894975deacb166d230f6e71f', required = requiredFiles(ref), live = { ...required };
  const helper = 'app/services/cashier/v3/card/CashierV3CardReadAmountServices.php'; delete live[helper];
  assert.throws(() => assertDependencies(required, live, {}), /CashierV3CardReadAmountServices/); checks++;
  check(assertDependencies(required, live, { ['formal/' + helper]: { after: required[helper] } }).result === 'PASS', 'explicit delivery closes missing dependency');
  assert.throws(() => assertDependencies(required, required, { ['formal/' + helper]: { after: 'wrong' } }), /mismatched/); checks++;
}
async function browser() {
  const { createServer } = localRequire('vite');
  const { chromium } = require('/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
  const evidence = path.join(root, 'tests/cashier-v3/evidence/R54-member-service'); fs.mkdirSync(evidence, { recursive: true });
  const html = `<html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div id="app"></div><script type="module">
    import {createApp,ref,h} from 'vue';
    import '/view_cashier_v3/src/styles/base.css';
    import Overlay from '/view_cashier_v3/src/components/member/MemberDetailOverlay.vue';
    const detail=ref({member:{id:1,name:'Local regression member'},serviceRecords:[{id:'stale',projectName:'Stale cached service'}]});
    const error=ref('本次查询未完成，请重试。');
    window.fixture={events:[],error,detail};
    createApp({setup(){return()=>h(Overlay,{detail:detail.value,loadError:error.value,initialTab:'writeoff',onTabChange(p){window.fixture.events.push(p);error.value='';detail.value={member:{id:1,name:'Local regression member'},serviceRecords:[{id:'fresh',projectName:'Local regression service',businessDate:'2026-10-24'}]}}})}}).mount('#app');
  </script></body></html>`;
  const server = await createServer({ root: frontend, configFile: path.join(frontend, 'vite.config.js'),
    server: { host: '127.0.0.1', port: 0, strictPort: false },
    plugins: [{ name: 'r54-fixture', configureServer(s) { s.middlewares.use(async (req, res, next) => {
      if (!req.url.endsWith('/__r54_member_service')) return next();
      res.setHeader('Content-Type', 'text/html'); res.end(await s.transformIndexHtml(req.url, html));
    }); } }] });
  let browser;
  try {
    await server.listen();
    browser = await chromium.launch({ executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
    const results = [];
    for (const viewport of [{ width: 1480, height: 1009 }, { width: 375, height: 812 }]) {
      const page = await browser.newPage({ viewport }); const errors = []; page.on('pageerror', e => errors.push(e.message));
      await page.goto('http://127.0.0.1:' + server.httpServer.address().port + '/view_cashier_v3/__r54_member_service');
      try { await page.getByRole('alert').waitFor(); }
      catch (error) { console.error('fixture browser errors', errors); throw error; }
      check(!await page.getByText('暂无可查看的服务记录').count(), 'failed query does not show empty history');
      check(!await page.getByText('Stale cached service').count(), 'failed query does not show old rows');
      await page.getByRole('button', { name: '会员记录查询周期', exact: true }).click();
      const period = page.getByRole('dialog', { name: '会员记录查询周期选择', exact: true });
      await period.getByRole('button', { name: '2026-10-01', exact: true }).first().click();
      await period.getByRole('button', { name: '2026-10-24', exact: true }).first().click();
      check(await page.evaluate(() => window.fixture.events.length) === 0, 'period draft does not execute query');
      await period.getByRole('button', { name: '确定', exact: true }).click();
      await page.screenshot({ path: path.join(evidence, 'failure-' + viewport.width + '.png') });
      await page.getByRole('button', { name: '重新查询会员详情', exact: true }).click();
      await page.getByText('Local regression service', { exact: true }).waitFor();
      const payload = await page.evaluate(() => window.fixture.events[0]);
      check(payload.tab === 'writeoff' && String(payload.memberId) === '1' && payload.dateFrom === '2026-10-01' && payload.dateTo === '2026-10-24', 'retry preserves exact member/tab/dates');
      await page.evaluate(() => { window.fixture.detail.value.serviceRecords = []; });
      await page.getByText('暂无可查看的服务记录').waitFor();
      check(await page.getByRole('alert').count() === 0, 'successful empty result no longer shows failure');
      await page.screenshot({ path: path.join(evidence, 'empty-' + viewport.width + '.png') });
      check(errors.length === 0, 'no browser runtime errors');
      results.push({ viewport, result: 'PASS', errors }); await page.close();
    }
    fs.writeFileSync(path.join(evidence, 'frontend.json'), JSON.stringify({ result: 'PASS', checks, results, transport: 'isolated fixture; actual shell function and Vue SFC' }, null, 2));
  } finally { if (browser) await browser.close(); await server.close(); }
}
(async () => { await contracts(); await browser(); console.log('R54_MEMBER_SERVICE_FRONTEND_PASS checks=' + checks); })().catch(e => { console.error(e); process.exitCode = 1; });
