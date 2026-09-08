import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const esbuild = require('../../前端代码/cashier-v3/node_modules/esbuild');
const source = fs.readFileSync(new URL('../../前端代码/mobile-vue3/src/shared/components/mohe-ai-entry.uvue', import.meta.url), 'utf8');
// Execute the production UVue controller after TS syntax lowering. This checks
// its actual state functions, not a copied implementation, but is NOT a native
// UTS compiler, picker, H5 layout or device lifecycle acceptance test.
const script = source.match(/<script setup lang="uts">([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '');
const expose = '\nglobalThis.controller = {openPanel,newConversation,submit,accept,confirmChoices,cancelRun,closePanel,selectDate,chooseValue,editGuidance,resetGuidanceChoices,currentGuidanceFields,validGuidance,load,refs:{question,progress,clarification,choices,guidanceBusy,guidanceUnknown,revisingId,displayed,opened},state:()=>({run,pending,conversation,storageKey}),unmount:()=>{}};';
const code = esbuild.transformSync(script + expose, { loader: 'ts', target: 'es2020' }).code;
const schema = 'mohe-clarification-v2'; let checks = 0;
const clean = value => JSON.parse(JSON.stringify(value));
const eq = (actual, expected) => { assert.deepEqual(clean(actual), expected); checks++; };
const metric = { key: 'metric_code', label: '业绩指标', type: 'select', options: [{ label: '现金业绩', value: 'cash_performance' }, { label: '消耗业绩', value: 'consume_amount' }] };
const fieldsDate = [{ key: 'start_date', label: '开始日期', type: 'date' }, { key: 'end_date', label: '结束日期', type: 'date' }];
function setup(max = 3) {
  const calls = [], mounted = [], unmounted = [], storage = new Map(); let now = 1788912000000;
  class FixtureDate extends Date { static now() { return now; } }
  const context = { ref: value => ({ value }), onMounted: fn => mounted.push(fn), onUnmounted: fn => unmounted.push(fn), Date: FixtureDate,
    setTimeout: () => 1, clearTimeout: () => {}, setInterval: () => 1, clearInterval: () => {},
    uni: { getStorageSync: key => storage.get(key), setStorageSync: (key, value) => storage.set(key, clean(value)), showActionSheet: () => {} },
    mobileAiRequest: (method, path, body, done) => calls.push({ method, path, body: clean(body), done, answered: false }), downloadMobileAi: () => {} };
  vm.runInNewContext(code, context); mounted.forEach(fn => fn()); const api = context.controller;
  const answer = (path, data, error = null) => { const call = calls.find(item => !item.answered && item.path === path); assert.ok(call, path); call.answered = true; call.done(error || { ok: true, data: clean(data) }); return call; };
  answer('/bootstrap', { enabled: true, identity_key: 'fixture:merchant:manager', window_token: 'window', guidance_schema_version: schema, max_clarification_rounds: max, capabilities: { metric_codes: ['cash_performance','consume_amount'], output_formats: ['screen'] } });
  api.openPanel();
  let state = { run_id: 'mobile-run', generation: 1, version: 1, run_delivery_token: 'delivery', status: 'RECEIVED' };
  const step = (round, fields = [metric], extra = {}) => state = { ...state, version: round + 1, status: 'WAITING_CLARIFICATION', clarification: { id: 'step-' + round, schema_version: schema, step_revision: 1, intent_revision: round, round_no: round, max_clarification_rounds: max, question: '确认条件', fields, confirmed_summary: [{ label: '指标', value: '现金业绩、消耗业绩' }], revisable_steps: [], ...extra } };
  const start = (fields = [metric]) => { api.refs.question.value = '移动引导测试'; api.submit(); answer('/runs', state); answer('/runs/mobile-run/execute', step(1, fields)); };
  return { api, answer, calls, storage, step, start, finish: (contextRef = null) => ({ ...state, version: state.version + 1, status: 'COMPLETED', answer: { summary: '已校验', ...(contextRef ? {context_ref:contextRef} : {}), cards: [{ metric_name: '现金业绩', display_value: '123', unit: '元' }, { metric_name: '消耗业绩', display_value: '456', unit: '元' }] } }), expire: () => { now += 86400000; }, unmount: () => unmounted.forEach(fn => fn()) };
}
for (const max of [3,4,5]) {
  const f = setup(max); f.start(); eq(f.calls.find(call => call.path === '/runs').body.guidance_schema_version, schema);
  for (let round = 1; round <= max; round++) {
    eq(f.api.refs.clarification.value.round_no, round); eq(f.api.refs.choices.value, {}); eq(f.api.refs.displayed.value.length, 0);
    f.api.chooseValue(metric, metric.options[0]); f.api.confirmChoices(); f.api.confirmChoices();
    const pending = f.calls.filter(call => !call.answered && call.path.endsWith('/clarify')); eq(pending.length, 1);
    eq([pending[0].body.step_revision, pending[0].body.intent_revision, pending[0].body.choices], [1, round, { metric_code: 'cash_performance' }]);
    f.answer('/runs/mobile-run/clarify', round === max ? f.finish() : f.step(round + 1));
  }
  eq(f.api.refs.clarification.value, null); eq(f.api.refs.displayed.value[0].cards.length, 2);
  eq(f.calls.filter(call => call.path === '/runs').length, 1); eq(f.calls.filter(call => call.path.endsWith('/execute')).length, 1);
  eq(f.api.load()[0].rounds.length, 1); f.unmount();
}
{
  const f = setup(); f.start(fieldsDate); f.api.confirmChoices(); eq(f.calls.filter(call => call.path.endsWith('/clarify')).length, 0);
  f.api.selectDate('start_date', { detail: { value: '2026-09-09' } }); f.api.selectDate('end_date', { detail: { value: '2026-09-09' } }); f.api.confirmChoices();
  eq(f.calls.at(-1).body.choices, { start_date: '2026-09-09', end_date: '2026-09-09' }); f.unmount();
}
{
  const f = setup(); f.start(); f.api.chooseValue(metric, metric.options[0]); f.api.confirmChoices(); const original = f.calls.at(-1).body;
  f.answer('/runs/mobile-run/clarify', null, { ok: false, responseKnown: false }); eq(f.api.refs.guidanceUnknown.value, true);
  f.api.chooseValue(metric, metric.options[1]); f.api.confirmChoices(); eq(f.calls.at(-1).body, clean(original));
  f.answer('/runs/mobile-run/clarify', f.step(2)); eq(f.api.refs.guidanceUnknown.value, false); eq(f.api.refs.choices.value, {}); f.unmount();
}
{
  const f = setup(); f.start(); f.api.chooseValue(metric, metric.options[0]); f.api.confirmChoices();
  const old = { id: 'step-1', question: '最初指标', fields: [metric], choices: { metric_code: 'cash_performance' } };
  f.answer('/runs/mobile-run/clarify', f.step(2, [metric], { revisable_steps: [old] })); f.api.editGuidance(old); eq(f.api.refs.choices.value, { metric_code: 'cash_performance' });
  f.api.chooseValue(metric, metric.options[1]); f.api.confirmChoices(); eq([f.calls.at(-1).body.clarification_id, f.calls.at(-1).body.revise_clarification_id, f.calls.at(-1).body.intent_revision], ['step-2', 'step-1', 2]);
  eq(f.calls.at(-1).body.choices, { metric_code: 'consume_amount' }); f.unmount();
}
{
  const f = setup(); f.start(); const newer = f.step(2); f.api.accept(newer); f.api.accept({ ...newer, version: 2, clarification: { ...newer.clarification, id: 'old', round_no: 1 } });
  eq(f.api.refs.clarification.value.id, 'step-2'); f.api.closePanel(); f.answer('/runs/mobile-run/cancel', { ...newer, version: 4, status: 'CANCELLED' });
  f.api.accept({ ...newer, version: 5, status: 'COMPLETED', answer: { summary: '迟到', cards: [] } }); eq(f.api.refs.displayed.value.length, 0); eq(f.api.refs.opened.value, false); f.unmount();
}
{
  const f = setup(); f.start(); eq(f.api.validGuidance({ id: 'legacy', fields: [metric] }), false);
  const bad = f.step(2); delete bad.clarification.intent_revision; f.api.accept(bad); eq(f.api.refs.clarification.value, null);
  const key = f.api.state().storageKey; const records = f.storage.get(key); records[0].rounds = Array.from({ length: 25 }, (_, i) => ({ question: 'q' + i, answer: 'a' + i, created_at: records[0].created_at }));
  records[0].rounds.push({ question: 'future', answer: 'future', created_at: records[0].created_at + 86400001 }); f.storage.set(key, records); eq(f.api.load()[0].rounds.length, 25);
  f.expire(); eq(f.api.load().length, 0); f.unmount();
}
{
  const f = setup(); f.start(); f.api.accept(f.finish());
  const key = f.api.state().storageKey; const records = f.storage.get(key);
  records[0].rounds = Array.from({ length: 25 }, (_, i) => ({ question: 'q' + i, answer: 'a' + i, created_at: records[0].created_at })); f.storage.set(key, records);
  f.api.refs.question.value = '新的明确问题'; f.api.submit(); const created = f.calls.filter(call => call.path === '/runs').at(-1);
  eq(created.body.history.length, 20); eq(created.body.history[0], { question: 'q5', answer: 'a5' });
  eq(Object.keys(created.body.history[0]), ['question', 'answer']); f.unmount();
}
for (const marker of ['mode="date"', 'currentGuidanceFields()', '修改已确认条件', '第 {{ clarification.round_no }} 步', 'client_submission_id', 'revise_clarification_id']) { eq(source.includes(marker), true); }
{
  const f = setup(); f.start(); f.api.accept(f.finish('fixture-context-a'));
  const key = f.api.state().storageKey; const records = f.storage.get(key);
  eq(records[0].rounds[0].presentation.context_ref,'fixture-context-a');
  f.api.refs.question.value = '那上个月呢'; f.api.submit();
  const second = f.calls.filter(call => call.path === '/runs').at(-1);
  eq(second.body.context_ref,'fixture-context-a'); eq(Object.keys(second.body.history[0]),['question','answer']);
  records[0].rounds[0].presentation.context_ref = 'fixture-changed-after-create'; f.storage.set(key,records);
  f.answer('/runs',{run_id:'mobile-next',generation:1,version:1,run_delivery_token:'next-delivery',status:'RECEIVED'});
  eq(f.calls.at(-1).body.context_ref,'fixture-context-a'); // same frozen reference in execute
  f.answer('/runs/mobile-next/execute',{run_id:'mobile-next',generation:1,version:2,status:'COMPLETED',answer:{summary:'第二个答案',context_ref:'fixture-context-b',cards:[]}});
  f.api.newConversation(); f.api.refs.question.value = '今天现金业绩多少'; f.api.submit();
  const third = f.calls.filter(call => call.path === '/runs').at(-1);
  eq(Object.prototype.hasOwnProperty.call(third.body,'context_ref'),false); eq(third.body.history,[]); f.unmount();
}
console.log(`R5 mobile production-controller: ${checks} checks PASS (TS-lowered controller + callback fixtures; not H5/native UI acceptance)`);
