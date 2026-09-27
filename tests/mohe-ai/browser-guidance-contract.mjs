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
function setup({ rounds = 3, fields = null, bad = false, unknownFirst = false, delayedClarify = false, delayedExecute = false, revisable = false } = {}) {
  const dom = new JSDOM('<!doctype html><body></body>', { url: 'https://guidance-fixture.test' });
  dom.window.HTMLElement.prototype.attachShadow = function () { const root = dom.window.document.createElement('section'); this.appendChild(root); Object.defineProperty(this, 'shadowRoot', { value: root }); return root; };
  globalThis.window = dom.window; globalThis.document = dom.window.document; globalThis.crypto = webcrypto;
  const calls = []; let state, count = 0, release, first = true;
  function guided(round) {
    const extra = revisable && round > 1 ? { revisable_steps: [{ id: 'step-1', question: '修改最初指标', fields: [metric], choices: { metric_code: 'cash_performance' } }] } : {};
    const c = step(round, rounds, fields || [metric], extra);
    if (bad) delete c.intent_revision;
    return { ...state, status: 'WAITING_CLARIFICATION', version: round + 1, clarification: c };
  }
  const request = async (method, path, payload) => {
    calls.push({ method, path, payload: payload ? JSON.parse(JSON.stringify(payload)) : null });
    if (path === '/bootstrap') return { enabled: true, identity_key: 'fixture:store:manager', window_token: 'window', guidance_schema_version: GUIDANCE_SCHEMA, max_clarification_rounds: rounds, capabilities: { metric_codes: ['cash_performance', 'consume_amount'], output_formats: ['screen'] } };
    if (path === '/runs') return state = { run_id: 'run', generation: 1, version: 1, run_delivery_token: 'delivery', status: 'RECEIVED' };
    if (path.endsWith('/execute')) {
      state = guided(1);
      if (delayedExecute) { const stale = state; state = guided(2); return new Promise(resolve => { release = () => resolve(stale); }); }
      return state;
    }
    if (path.endsWith('/cancel')) return state = { ...state, version: state.version + 1, status: 'CANCELLED' };
    if (method === 'GET') return state;
    if (path.endsWith('/clarify')) {
      if (unknownFirst && first) { first = false; throw new Error('uncertain connection'); }
      count++;
      const response = count < rounds ? guided(count + 1) : { ...state, version: state.version + 1, status: 'COMPLETED', answer: { summary: '已校验双指标', cards: [{ metric_name: '现金业绩', display_value: '123', unit: '元' }, { metric_name: '消耗业绩', display_value: '456', unit: '元' }] } };
      if (delayedClarify) return new Promise(resolve => { release = () => resolve(response); });
      return state = response;
    }
    throw new Error('unexpected fixture request');
  };
  const dispose = mountMoheAi({ request });
  const root = () => document.querySelector('[data-mohe-ai]').shadowRoot;
  const click = text => { const button = [...root().querySelectorAll('button')].find(node => node.textContent === text); assert.ok(button, text); button.click(); };
  const area = () => root().querySelector('[data-guidance=step]');
  const choose = () => { const radio = area().querySelector('input[type=radio]'); if (radio) radio.click(); else area().querySelectorAll('input[type=date]').forEach(input => { input.value = '2026-09-09'; }); };
  const start = async () => { await flush(); click('魔核 AI'); await flush(); root().querySelector('textarea').value = '需要确认条件的测试问题'; click('发送'); await flush(); };
  return { root, click, area, choose, start, calls, dispose, release: () => release() };
}
for (const rounds of [3, 4, 5]) {
  const f = setup({ rounds }); await f.start();
  eq(f.calls.find(call => call.path === '/runs').payload.guidance_schema_version, GUIDANCE_SCHEMA);
  for (let i = 1; i <= rounds; i++) {
    eq(f.area().textContent.includes(`第 ${i} 步`), true);
    eq(f.area().classList.contains('guidance-card'), true);
    eq([...f.area().querySelectorAll('.guidance-number')].map(n => n.textContent), ['1','2']);
    eq(f.area().querySelectorAll('input[type=radio]').length, 2);
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
assert.throws(() => validateClarification({ id: 'old', fields: [metric] }, GUIDANCE_SCHEMA)); checks++;
assert.throws(() => validateClarification(step(4, 3), GUIDANCE_SCHEMA)); checks++;
assert.throws(() => validateClarification(step(1, 3, [metric, ...dates]), GUIDANCE_SCHEMA)); checks++;
assert.throws(() => clarificationSubmission(step(1, 3), {}, 'id', 'unknown')); checks++;
eq(clarificationSubmission({ id: 'legacy', fields: [metric] }, { metric_code: 'cash_performance' }, 'not-sent'), { clarification_id: 'legacy', choices: { metric_code: 'cash_performance' } });
console.log(`R5 browser guidance: ${checks} checks PASS (DOM + HTTP fixtures only; no model, customer data or real browser layout)`);
