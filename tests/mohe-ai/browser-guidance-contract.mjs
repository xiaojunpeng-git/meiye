import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { webcrypto } from 'node:crypto';
import { mountMoheAi } from '../../前端代码/shared/mohe-ai/browser-entry.mjs';
import { GUIDANCE_SCHEMA, validateClarification, clarificationSubmission } from '../../前端代码/shared/mohe-ai/clarification-state.mjs';
const require = createRequire(import.meta.url);
const { JSDOM } = require('../../前端代码/admin/node_modules/jsdom');
const flush = () => new Promise(resolve => setTimeout(resolve, 12));
let checks = 0;
const eq = (actual, expected) => { assert.deepEqual(actual, expected); checks++; };
const metric = { key: 'metric_code', label: '业绩指标', type: 'select', options: [{ label: '现金业绩', value: 'cash_performance' }, { label: '消耗业绩', value: 'consume_amount' }] };
const dates = [{ key: 'start_date', label: '开始日期', type: 'date', min: '2026-08-10', max: '2026-09-30' }, { key: 'end_date', label: '结束日期', type: 'date', min: '2026-08-10', max: '2026-09-30' }];
function step(round, max, fields = [metric], extra = {}) {
  return { id: 'step-' + round, schema_version: GUIDANCE_SCHEMA, step_revision: 1, intent_revision: round, round_no: round,
    max_clarification_rounds: max, question: '请选择第 ' + round + ' 项条件', fields,
    confirmed_summary: [{ label: '范围', value: '本店' }, { label: '期间', value: '今天' }], revisable_steps: [], ...extra };
}
function setup({ rounds = 3, fields = null, bad = false, unknownFirst = false, delayedClarify = false, delayedExecute = false, revisable = false, partial = false, failureCard = false, failureReason = 'AI_INTENT_PARTIAL', disabled = false, createError = null, presentation = 'drawer', expireAfterDraft = false, lateExpiry = false } = {}) {
  const dom = new JSDOM('<!doctype html><body></body>', { url: 'https://guidance-fixture.test' });
  dom.window.HTMLElement.prototype.attachShadow = function () { const root = dom.window.document.createElement('section'); this.appendChild(root); Object.defineProperty(this, 'shadowRoot', { value: root }); return root; };
  globalThis.window = dom.window; globalThis.document = dom.window.document; globalThis.crypto = webcrypto;
  const calls = []; let state, count = 0, release, first = true, restarted = false;
  function guided(round) {
    const extra = revisable && round > 1 ? { revisable_steps: [{ id: 'step-1', question: '修改最初指标', fields: [metric], choices: { metric_code: 'cash_performance' } }] } : {};
    const c = step(round, rounds, fields || [metric], extra);
    if (bad) delete c.intent_revision;
    return { ...state, status: 'WAITING_CLARIFICATION', version: round + 1, clarification: c };
  }
  const request = async (method, path, payload) => {
    calls.push({ method, path, payload: payload ? JSON.parse(JSON.stringify(payload)) : null });
    if (path === '/bootstrap') return { enabled: !disabled, can_configure: true, disabled_reason: disabled ? 'AI_NOT_CONFIGURED' : '', identity_key: 'fixture:store:manager', window_token: 'window', guidance_schema_version: GUIDANCE_SCHEMA, max_clarification_rounds: rounds, capabilities: { metric_codes: ['cash_performance', 'consume_amount'], output_formats: ['screen'] } };
    if (path === '/runs') { restarted = !!payload.clarification_restart; if (createError) throw Object.assign(new Error(createError), { responseKnown: true }); return state = { run_id: restarted ? 'restart-run' : 'run', generation: 1, version: 1, run_delivery_token: 'delivery', status: 'RECEIVED' }; }
    if (path.endsWith('/execute')) {
      if (restarted) return state = { ...state, version: state.version + 1, status: 'COMPLETED', answer: { summary: '恢复查询完成' } };
      if (partial) return state = { ...state, version: state.version + 1, status: 'FAILED', reason: failureReason, message: failureCard ? '这次没能给出可靠结果。请选择一种方式继续。' : '请补充查询意思。',
        ...(failureCard ? { failure_guidance: { options: [
          {value:'retry_original',label:'按原问题再查一次'}, {value:'edit_original',label:'修改原问题后查询'},
          {value:'new_conversation',label:'开启新对话'}], supplement_label:'我来补充描述' } } : {}) };
      state = guided(1);
      if (expireAfterDraft) state = { ...state, server_time: 1000, clarification_deadline_at: 1030 };
      if (delayedExecute) { const stale = state; state = guided(2); return new Promise(resolve => { release = () => resolve(stale); }); }
      return state;
    }
    if (path.endsWith('/cancel')) return state = { ...state, version: state.version + 1, status: 'CANCELLED' };
    const expired = () => ({ ...state, status: 'FAILED', reason: 'CLARIFICATION_EXPIRED', version: state.version + 1,
      failure_guidance: { kind: 'clarification_expired', question: '补充等待已超时，尚未执行查询。已确认条件已保留，可继续原查询。' } });
    if (method === 'GET') return state = expireAfterDraft && !restarted ? expired() : state;
    if (path.endsWith('/clarify')) {
      if (lateExpiry) return state = expired();
      if (unknownFirst && first) { first = false; throw new Error('uncertain connection'); }
      count++;
      const response = count < rounds ? guided(count + 1) : { ...state, version: state.version + 1, status: 'COMPLETED', answer: { summary: '已校验双指标', cards: [{ metric_name: '现金业绩', display_value: '123', unit: '元' }, { metric_name: '消耗业绩', display_value: '456', unit: '元' }] } };
      if (delayedClarify) return new Promise(resolve => { release = () => resolve(response); });
      return state = response;
    }
    throw new Error('unexpected fixture request');
  };
  let dispose = mountMoheAi({ request, presentation });
  const root = () => document.querySelector('[data-mohe-ai]').shadowRoot;
  const click = text => { const button = [...root().querySelectorAll('button')].find(node => node.textContent === text); assert.ok(button, text); button.click(); };
  const area = () => root().querySelector('[data-guidance=step]');
  const choose = () => { const radio = area().querySelector('input[type=radio]'); if (radio) radio.click(); else area().querySelectorAll('input[type=date]').forEach(input => { input.value = '2026-09-09'; }); };
  const start = async () => { await flush(); click('魔核 AI'); await flush(); root().querySelector('textarea').value = '需要确认条件的测试问题'; click('发送'); await flush(); };
  return { root, click, area, choose, start, calls, dispose: () => dispose(), remount: async () => { dispose(); dispose = mountMoheAi({ request, presentation }); await flush(); click('魔核 AI'); await flush(); }, release: () => release() };
}
// A disabled local service and a configuration change after bootstrap are
// technical boundaries, not failed natural-language understanding.
{
  const f = setup({ disabled: true }); await flush(); f.click('魔核 AI'); await flush();
  f.root().querySelector('textarea').value = '今天现金业绩是多少'; f.click('发送'); await flush();
  eq(f.root().textContent.includes('启用状态和外部处理授权'), true);
  eq(f.root().textContent.includes('AI_NOT_CONFIGURED'), false);
  eq(f.calls.filter(call => call.path === '/runs').length, 0); f.dispose();
}
{
  const f = setup({ createError: 'AI_NOT_CONFIGURED' }); await f.start();
  eq(f.root().textContent.includes('启用状态和外部处理授权'), true);
  eq(f.root().textContent.includes('AI_NOT_CONFIGURED'), false);
  eq(f.root().querySelector('[data-failure-guidance]') !== null, true);
  eq(f.root().textContent.includes('需要确认条件的测试问题'), true); f.dispose();
}
for (const reason of ['AI_INTENT_UNRESOLVED','AI_CAPABILITY_CHANGED']) {
  const f = setup({ partial: true, failureReason: reason }); await f.start();
  eq(f.root().textContent.includes('今天的现金业绩'), true);
  eq(f.root().textContent.includes('需要确认条件的测试问题'), true);
  eq(f.root().querySelector('[data-failure-guidance]'), null);
  eq(f.calls.filter(call => call.path === '/runs').length, 1);
  f.dispose();
}
{
  const f = setup({ partial: true, failureCard: true, presentation: 'workspace' }); await f.start();
  eq(f.root().querySelector('[data-failure-guidance]'), null);
  eq(f.root().textContent.includes('请稍后重试'), true);
  eq(f.root().textContent.includes('检查条件'), false);
  f.dispose();
}
// Expiry is distinct from a semantic failure. The server's deadline prompts
// one status read; a draft survives the terminal transition and reload. One
// explicit click creates one fresh admission with opaque source + choices.
for (const deadlineDriven of [true, false]) {
  const f = setup({ presentation: 'workspace', expireAfterDraft: deadlineDriven, lateExpiry: !deadlineDriven });
  await f.start(); f.choose();
  f.area().querySelector('input[type=radio]').dispatchEvent(new window.Event('change', { bubbles: true }));
  if (deadlineDriven) { await f.remount(); await new Promise(resolve => setTimeout(resolve, 170)); }
  else { f.click('确认并继续'); await flush(); }
  eq(f.root().textContent.includes('补充等待已超时'), true);
  eq(f.root().textContent.includes('检查条件'), false);
  eq(f.root().querySelector('[data-failure-guidance]'), null);
  eq(f.area().querySelector('input[type=radio]').checked, true);
  await f.remount(); f.click('需要确认条件的测试问题'); await flush();
  eq(f.area().querySelector('input[type=radio]').checked, true);
  f.click('继续原查询'); await flush(); await flush();
  const starts = f.calls.filter(call => call.path === '/runs');
  eq(starts.length, 2);
  eq(starts[1].payload.question, '需要确认条件的测试问题');
  eq(starts[1].payload.clarification_restart.run_id, 'run');
  eq(starts[1].payload.clarification_restart.choices, { metric_code: 'cash_performance' });
  eq(f.root().textContent.includes('恢复查询完成'), true);
  f.dispose();
}
// A transport outage has no accepted semantic result. It must not ask the
// customer to change business conditions or create an irrelevant choice card.
{
  const f = setup({ partial: true, failureCard: true, failureReason: 'AI_MODEL_RESULT_UNKNOWN', presentation: 'workspace' }); await f.start();
  eq(f.root().textContent.includes('AI 服务暂时未返回结果，请稍后重试。'), true);
  eq(f.root().textContent.includes('检查条件'), false);
  eq(f.root().querySelector('[data-failure-guidance]'), null);
  eq(f.calls.filter(call => call.path === '/runs').length, 1);
  f.dispose();
}
for (const rounds of [3, 4, 5]) {
  const f = setup({ rounds }); await f.start();
  eq(f.calls.find(call => call.path === '/runs').payload.guidance_schema_version, GUIDANCE_SCHEMA);
  for (let i = 1; i <= rounds; i++) {
    eq(f.area().textContent.includes(`第 ${i} 步`), true);
    eq(f.area().classList.contains('guidance-card'), true);
    eq([...f.area().querySelectorAll('.guidance-number')].map(n => n.textContent), ['1','2','3']);
    eq(f.area().querySelectorAll('input[type=radio]').length, 3);
    eq(f.area().querySelectorAll('input:checked').length, 0);
    eq(f.root().querySelectorAll('.value').length, 0);
    f.choose(); f.click('确认并继续'); await flush();
    const submitted = f.calls.filter(call => call.path.endsWith('/clarify')).at(-1).payload;
    eq([submitted.schema_version, submitted.step_revision, submitted.intent_revision], [GUIDANCE_SCHEMA, 1, i]);
    eq(submitted.choices, { metric_code: 'cash_performance' });
    eq(typeof submitted.client_submission_id, 'string');
  }
  eq(f.calls.filter(call => call.path === '/runs').length, 1);
  eq(f.calls.filter(call => call.path.endsWith('/execute')).length, 1);
  eq(f.area(), null); eq(f.root().querySelectorAll('.value').length, 2);
  eq(f.root().textContent.includes('已接纳选择，正在继续查询。'), false);
  const local = JSON.parse(window.localStorage.getItem('mohe-ai:v1:fixture%3Astore%3Amanager'));
  eq(local[0].rounds.length, 1); eq(JSON.stringify(local).includes('client_submission_id'), false); f.dispose();
}
// An underspecified card keeps true metric alternatives, while its action
// option cancels the paused Run and returns the original wording to edit.
{
  const actionField = { ...metric, options: [
    ...metric.options.map(option => ({ ...option, recommended: true })),
    { value: '__edit_original__', label: '修改原问题后查询', recommended: true, action: 'edit_original' },
  ] };
  const f = setup({ fields: [actionField] }); await f.start();
  eq([...f.area().querySelectorAll('.guidance-number')].map(node => node.textContent), ['1','2','3','4']);
  f.area().querySelector('input[value=__edit_original__]').click(); f.click('确认并继续'); await flush();
  eq(f.calls.filter(call => call.path.endsWith('/cancel')).length, 1);
  eq(f.calls.filter(call => call.path.endsWith('/clarify')).length, 0);
  eq(f.root().querySelector('textarea[aria-label="输入问题"]').value, '需要确认条件的测试问题');
  f.dispose();
}
{
  const f = setup({ fields: [{ ...metric, options: [...metric.options.map(option => ({ ...option, recommended: true })),
    { label: '退款业绩', value: 'refund_performance', recommended: true }, { label: '销售人业绩', value: 'staff_sales' }] }] });
  await f.start();
  eq([...f.area().querySelectorAll('.guidance-number')].map(n => n.textContent), ['1','2','3','4']);
  eq(f.area().querySelectorAll('select').length, 0);
  const supplement = f.area().querySelector('input[value=__supplement__]');
  supplement.click(); const detail = f.area().querySelector('textarea[aria-label="补充描述"]');
  eq(detail.hidden, false); detail.value = '我指的是本月门店退款金额';
  f.click('确认并继续'); await flush();
  eq(f.calls.filter(call => call.path.endsWith('/clarify')).length, 1);
  eq(f.calls.filter(call => call.path.endsWith('/cancel')).length, 0);
  const submitted = f.calls.filter(call => call.path === '/runs');
  eq(submitted.length, 1);
  eq(f.calls.find(call => call.path.endsWith('/clarify')).payload.choices,
    {metric_code:'__supplement__',supplement_text:'我指的是本月门店退款金额'});
  f.dispose();
}
{
  const f = setup({ fields: [{ ...metric, options: [...metric.options,
    { label: '退款业绩', value: 'refund_performance' }, { label: '销售人业绩', value: 'staff_sales' }] }] });
  await f.start();
  eq(f.area().querySelectorAll('select').length, 1);
  eq([...f.area().querySelectorAll('option')].some(option => option.value === '__supplement__'), true);
  f.dispose();
}
{
  const f = setup({ fields: dates }); await f.start();
  eq(f.area().querySelectorAll('input[type=date]').length, 2); eq(f.area().querySelectorAll('input[type=radio]').length, 0);
  f.click('确认并继续'); await flush(); eq(f.calls.filter(call => call.path.endsWith('/clarify')).length, 0);
  eq(f.area().textContent.includes('有效日期'), true); f.choose(); f.click('确认并继续'); await flush();
  eq(f.calls.filter(call => call.path.endsWith('/clarify'))[0].payload.choices, { start_date: '2026-09-09', end_date: '2026-09-09' }); f.dispose();
}
{
  const f = setup({ unknownFirst: true }); await f.start(); f.choose(); f.click('确认并继续'); await flush();
  eq([...f.area().querySelectorAll('input')].every(input => input.disabled), true);
  f.click('重试确认'); await flush(); const sent = f.calls.filter(call => call.path.endsWith('/clarify'));
  eq(sent.length, 2); eq(sent[0].payload, sent[1].payload); eq(f.area().textContent.includes('第 2 步'), true); f.dispose();
}
for (const action of ['停止', '关闭']) {
  const f = setup({ delayedClarify: true }); await f.start(); f.choose();
  const confirm = [...f.area().querySelectorAll('button')].find(button => button.textContent === '确认并继续'); confirm.onclick(); confirm.onclick(); await flush();
  eq(f.calls.filter(call => call.path.endsWith('/clarify')).length, 1); f.click(action); await flush();
  f.release(); await flush(); eq(f.area(), null); eq(f.root().querySelectorAll('.value').length, 0); eq(f.calls.filter(call => call.path === '/runs').length, 1); f.dispose();
}
{
  const f = setup({ revisable: true }); await f.start(); f.choose(); f.click('确认并继续'); await flush(); f.click('修改最初指标');
  eq(f.area().querySelector('input:checked').value, 'cash_performance'); f.area().querySelector('input[value=consume_amount]').click(); f.click('确认并继续'); await flush();
  const submitted = f.calls.filter(call => call.path.endsWith('/clarify')).at(-1).payload;
  eq([submitted.clarification_id, submitted.revise_clarification_id, submitted.intent_revision], ['step-2', 'step-1', 2]);
  eq(submitted.choices, { metric_code: 'consume_amount' }); eq(f.area().textContent.includes('第 3 步'), true); f.dispose();
}
{
  const f = setup({ delayedExecute: true }); await f.start(); await new Promise(resolve => setTimeout(resolve, 1100));
  eq(f.area().textContent.includes('第 2 步'), true); f.release(); await flush();
  eq(f.area().textContent.includes('第 2 步'), true); eq(f.root().querySelectorAll('[data-guidance=step]').length, 1); f.dispose();
}
{
  const f = setup({ bad: true }); await f.start(); eq(f.area(), null); eq(f.root().textContent.includes('引导信息不完整'), true); f.dispose();
}
{
  const f = setup({ partial: true }); await f.start();
  const detail = f.root().querySelector('textarea[aria-label="补充描述"]');
  eq(detail, null);
  eq(f.calls.filter(call => call.path === '/runs').length, 1);
  f.dispose();
}
{
  const f = setup({ partial: true, failureCard: true }); await f.start();
  const card = f.root().querySelector('[data-failure-guidance]');
  eq(card !== null, true);
  eq([...card.querySelectorAll('.guidance-number')].map(item => item.textContent), ['1','2','3','4']);
  eq(f.root().textContent.includes('请直接重试'), false);
  card.querySelector('button').click(); card.querySelector('button').click(); await flush();
  const creates = f.calls.filter(call => call.path === '/runs');
  eq(creates.length, 2);
  eq(creates[1].payload.question, creates[0].payload.question);
  eq(creates[1].payload.history, creates[0].payload.history);
  f.dispose();
}
assert.throws(() => validateClarification({ id: 'old', fields: [metric] }, GUIDANCE_SCHEMA)); checks++;
assert.throws(() => validateClarification(step(4, 3), GUIDANCE_SCHEMA)); checks++;
assert.throws(() => validateClarification(step(1, 3, [metric, ...dates]), GUIDANCE_SCHEMA)); checks++;
assert.throws(() => clarificationSubmission(step(1, 3), {}, 'id', 'unknown')); checks++;
eq(clarificationSubmission({ id: 'legacy', fields: [metric] }, { metric_code: 'cash_performance' }, 'not-sent'), { clarification_id: 'legacy', choices: { metric_code: 'cash_performance' } });
console.log(`R5 browser guidance: ${checks} checks PASS (DOM + HTTP fixtures only; no model, customer data or real browser layout)`);
