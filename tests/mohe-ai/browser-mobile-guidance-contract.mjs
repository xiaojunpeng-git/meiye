import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const esbuild = require('../../前端代码/cashier-v3/node_modules/esbuild');
const source = fs.readFileSync(new URL('../../前端代码/mobile-vue3/src/shared/components/mohe-ai-entry.uvue', import.meta.url), 'utf8');
// Neither terminal displays a default hint; retain an accessible input name.
const browserEntry = fs.readFileSync(new URL('../../前端代码/shared/mohe-ai/browser-entry.mjs', import.meta.url), 'utf8');
assert.ok(!source.includes('questionHint'));
assert.ok(!source.match(/<textarea[^>]*placeholder/));
assert.ok(source.includes('aria-label="输入问题"'));
assert.ok(!browserEntry.includes('input.placeholder'));
assert.ok(browserEntry.includes("input.setAttribute('aria-label', '输入问题')"));
// Approved tagline is shared visible brand copy, independent from the empty composer.
assert.ok(source.includes('<text class="ai-brand-tagline">够智能、够准确、够便捷</text>'));
assert.ok(browserEntry.includes("el('small', '够智能、够准确、够便捷', 'workspace-brand-tagline')"));
// Execute the production UVue controller after TS syntax lowering. This checks
// its actual state functions, not a copied implementation, but is NOT a native
// UTS compiler, picker, H5 layout or device lifecycle acceptance test.
const script = source.match(/<script setup lang="uts">([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '');
const expose = '\nglobalThis.controller = {onComposerLineChange,onComposerInput,resetComposerSize,answerPresentation,presentationGroups,openPanel,newConversation,submit,accept,confirmChoices,cancelRun,closePanel,selectDate,chooseValue,editGuidance,resetGuidanceChoices,currentGuidanceFields,validGuidance,load,resumeActive,elapsedLabel,toggleComposerOptions,toggleExcel,createConversationFromMenu,confirmClearConversation,openHistory,selectConversation,refs:{composerInputHeight,composerExpanded,question,progress,clarification,choices,guidanceBusy,guidanceUnknown,revisingId,displayed,opened,busy,activeQuestion,activeElapsedSeconds,activeRunState,messageAnchor,composerOptionsOpen,historyOpen,recentConversations,wantExcel,excelAvailable},state:()=>({run,pending,conversation,storageKey,runtimeKey}),unmount:()=>{}};';
const code = esbuild.transformSync(script + expose, { loader: 'ts', target: 'es2020' }).code;
const schema = 'mohe-clarification-v2'; let checks = 0;
const clean = value => JSON.parse(JSON.stringify(value));
const eq = (actual, expected) => { assert.deepEqual(clean(actual), expected); checks++; };
const metric = { key: 'metric_code', label: '业绩指标', type: 'select', options: [{ label: '现金业绩', value: 'cash_performance' }, { label: '消耗业绩', value: 'consume_amount' }] };
const fieldsDate = [{ key: 'start_date', label: '开始日期', type: 'date' }, { key: 'end_date', label: '结束日期', type: 'date' }];
function setup(max = 3, asyncExecution = true, deferBootstrap = false, sharedStorage = null, initialOpen = true) {
  const calls = [], modals = [], mounted = [], unmounted = [], storage = sharedStorage || new Map(); let now = 1788912000000;
  class FixtureDate extends Date { static now() { return now; } }
  let scheduled = 0; const intervals = new Map(); let intervalId = 0;
  const context = { ref: value => ({ value }), nextTick: fn => fn(), onMounted: fn => mounted.push(fn), onUnmounted: fn => unmounted.push(fn), currentMobilePlatform: () => 'H5', Date: FixtureDate,
    setTimeout: () => ++scheduled, clearTimeout: () => {}, setInterval: fn => { intervals.set(++intervalId,fn); return intervalId; }, clearInterval: key => intervals.delete(key),
    uni: { getStorageSync: key => storage.get(key), setStorageSync: (key, value) => storage.set(key, clean(value)), removeStorageSync: key => storage.delete(key), showActionSheet: () => {}, showModal: options => modals.push(options) },
    mobileAiRequest: (method, path, body, done) => calls.push({ method, path, body: clean(body), done, answered: false }), downloadMobileAi: () => {} };
  vm.runInNewContext(code, context); mounted.forEach(fn => fn()); const api = context.controller;
  const answer = (path, data, error = null) => { const call = calls.find(item => !item.answered && item.path === path); assert.ok(call, path); call.answered = true; call.done(error || { ok: true, data: clean(data) }); return call; };
  const bootstrap = { enabled: true, async_execution: asyncExecution, identity_key: 'fixture:merchant:manager', window_token: 'window', guidance_schema_version: schema, max_clarification_rounds: max, capabilities: { metric_codes: ['cash_performance','consume_amount'], output_formats: ['screen'] } };
  if (!deferBootstrap) answer('/bootstrap', bootstrap);
  if (initialOpen) api.openPanel();
  let state = { run_id: 'mobile-run', generation: 1, version: 1, run_delivery_token: 'delivery', status: 'RECEIVED' };
  const step = (round, fields = [metric], extra = {}) => state = { ...state, version: round + 1, status: 'WAITING_CLARIFICATION', clarification: { id: 'step-' + round, schema_version: schema, step_revision: 1, intent_revision: round, round_no: round, max_clarification_rounds: max, question: '确认条件', fields, confirmed_summary: [{ label: '指标', value: '现金业绩、消耗业绩' }], revisable_steps: [], ...extra } };
  const start = (fields = [metric]) => { api.refs.question.value = '移动引导测试'; api.submit(); answer('/runs', state); api.accept(step(1, fields)); };
  return { api, answer, bootstrap, calls, modals, storage, advance: ms => { now += ms; intervals.forEach(fn => fn()); }, scheduled: () => scheduled, step, start, finish: (contextRef = null) => ({ ...state, version: state.version + 1, status: 'COMPLETED', answer: { summary: '已校验', ...(contextRef ? {context_ref:contextRef} : {}), cards: [{ metric_name: '现金业绩', display_value: '123', unit: '元' }, { metric_name: '消耗业绩', display_value: '456', unit: '元' }] } }), expire: () => { now += 86400000; }, unmount: () => unmounted.forEach(fn => fn()) };
}
// R50：等待不计处理耗时；每次人工确认开启新处理段，原会话恢复仍展示确认卡。
{
  const f = setup(); f.start(); const frozen = f.api.refs.activeElapsedSeconds.value;
  f.advance(240000);
  eq([f.api.refs.activeElapsedSeconds.value,f.api.refs.activeRunState.value,f.api.refs.messageAnchor.value], [frozen,'请确认下方问题','ai-guidance-current']);
  f.api.chooseValue(metric,metric.options[0]); f.api.confirmChoices(); f.advance(2000);
  eq([f.api.refs.activeElapsedSeconds.value,f.api.refs.activeRunState.value],[2,'正在思考']);
  f.answer('/runs/mobile-run/clarify',f.step(2)); f.advance(300000);
  eq(f.api.refs.activeElapsedSeconds.value,2);
  assert.ok(source.indexOf('id="ai-guidance-current"') < source.indexOf('class="ai-reading-runway"'));
  f.unmount();
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
  eq(f.storage.get(f.api.state().runtimeKey).resolved_run.run_id, 'mobile-run');
  eq(f.calls.filter(call => call.path === '/runs').length, 1); eq(f.calls.filter(call => call.path.endsWith('/execute')).length, 0);
  eq(f.api.load()[0].rounds.length, 1); f.unmount();
}
{
  const f = setup(); f.api.refs.question.value = '等待态阅读锚点'; f.api.submit();
  // The customer sees a complete local turn immediately, before network
  // admission or a final answer has had a chance to enlarge the transcript.
  eq([f.api.refs.activeQuestion.value, f.api.refs.activeElapsedSeconds.value, f.api.refs.activeRunState.value], ['等待态阅读锚点', 0, '正在思考']);
  eq(f.api.refs.messageAnchor.value.startsWith('ai-active-'), true);
  f.answer('/runs', { run_id: 'wait-run', generation: 1, version: 1, run_delivery_token: 'wait-delivery', status: 'RECEIVED' });
  f.api.accept({ run_id: 'wait-run', generation: 1, version: 2, status: 'COMPLETED', answer: { summary: '首答已到达', cards: [] } });
  eq([f.api.refs.activeQuestion.value, f.api.refs.activeElapsedSeconds.value, f.api.refs.displayed.value[0].answer], ['', 0, '首答已到达']);
  eq([f.api.refs.displayed.value[0].elapsed_seconds, f.api.elapsedLabel(0), f.api.elapsedLabel(65)], [0, '', '用时 1 分钟 5 秒']); f.unmount();
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
  const f = setup(); f.start(); f.api.chooseValue(metric, metric.options[0]); f.api.confirmChoices();
  // Async acknowledgement still projects the old clarification until the
  // queued worker claims the choice. The client keeps the Run active and does
  // not offer that same choice again while it observes the result.
  f.answer('/runs/mobile-run/clarify', { ...f.step(1), version: 3 });
  eq(f.api.refs.clarification.value, null); eq(f.api.refs.guidanceBusy.value, false); eq(f.api.refs.busy.value, true); f.unmount();
}
{
  const f = setup(); f.start(); f.api.chooseValue(metric, metric.options[0]); f.api.confirmChoices();
  // The worker may accept transport-wise but reject an incomplete/invalid
  // choice after full semantic validation.  That must reopen this exact step,
  // not leave the local queued marker suppressing the controls forever.
  f.answer('/runs/mobile-run/clarify', { ...f.step(1), version: 3 });
  f.api.accept({ ...f.step(1), version: 4, clarification_rejected: true });
  eq(f.api.refs.clarification.value.id, 'step-1'); eq(f.api.refs.progress.value, '所选条件格式不完整，请检查后重新确认。'); f.unmount();
}
{
  const f = setup(); f.start(); f.api.chooseValue(metric, metric.options[0]); f.api.confirmChoices();
  f.answer('/runs/mobile-run/clarify', { ...f.step(1), version: 3 });
  const record = f.storage.get(f.api.state().runtimeKey);
  eq(record.queued_clarification_id, 'step-1');
  const before = f.scheduled(); f.api.resumeActive(record); f.answer('/runs/mobile-run', record.run);
  eq(f.scheduled() > before, true); eq(f.api.refs.clarification.value, null); f.unmount();
}
{
  const f = setup(); f.start(); f.api.chooseValue(metric, metric.options[0]); f.api.confirmChoices();
  const original = clean(f.calls.at(-1).body);
  // The application can disappear after the request has left the device but
  // before its result is known. The next session must retry exactly that
  // request, never recreate it with another submission id or changed choices.
  f.answer('/runs/mobile-run/clarify', null, { ok: false, responseKnown: false });
  const record = f.storage.get(f.api.state().runtimeKey);
  eq(record.guidance_submission.client_submission_id, original.client_submission_id);
  f.api.refs.clarification.value = null; f.api.resumeActive(record); f.answer('/runs/mobile-run', record.run);
  const retry = f.calls.filter(call => !call.answered && call.path.endsWith('/clarify')).at(-1);
  eq(retry.body, original); f.unmount();
}
{
  const f = setup(); f.start(); f.api.chooseValue(metric, metric.options[0]); f.api.confirmChoices();
  f.answer('/runs/mobile-run/clarify', null, { ok: false, responseKnown: false });
  const record = f.storage.get(f.api.state().runtimeKey);
  f.api.refs.clarification.value = null; f.api.resumeActive(record);
  f.answer('/runs/mobile-run', { ...record.run, version: record.run.version + 1, clarification_rejected: true });
  eq(f.calls.filter(call => !call.answered && call.path.endsWith('/clarify')).length, 0);
  eq(f.api.refs.clarification.value.id, 'step-1'); f.unmount();
}
{
  const f = setup(); f.start(); const record = f.storage.get(f.api.state().runtimeKey);
  f.api.refs.clarification.value = null; f.api.resumeActive(record); f.answer('/runs/mobile-run', record.run);
  eq(f.api.refs.clarification.value.id, 'step-1'); f.unmount();
}
{
  const f = setup(); f.start();
  // Merchant pages each mount the entry. A route change must leave this Run
  // recoverable instead of silently acting as if the customer pressed Stop.
  f.unmount();
  eq(f.calls.filter(call => call.path.endsWith('/cancel')).length, 0);
  eq(f.storage.get(f.api.state().runtimeKey).run.run_id, 'mobile-run');
}
{
  const f = setup(); f.start(); const newer = f.step(2); f.api.accept(newer); f.api.accept({ ...newer, version: 2, clarification: { ...newer.clarification, id: 'old', round_no: 1 } });
  eq(f.api.refs.clarification.value.id, 'step-2'); f.api.closePanel(); f.answer('/runs/mobile-run/cancel', { ...newer, version: 4, status: 'CANCELLED' });
  f.api.accept({ ...newer, version: 5, status: 'COMPLETED', answer: { summary: '迟到', cards: [] } });
  // Stopping keeps the customer's question and its terminal status visible,
  // while the late successful projection still has no right to replace it.
  eq(f.api.refs.displayed.value.map(item => ({ question: item.question, answer: item.answer, context_eligible: item.context_eligible })), [{ question: '移动引导测试', answer: '已取消', context_eligible: false }]);
  eq(f.api.refs.opened.value, false); f.unmount();
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
for (const marker of ['mode="date"', 'currentGuidanceFields()', '修改已确认条件', '第 {{ clarification.round_no }} 步', 'client_submission_id', 'revise_clarification_id', 'class="ai-reading-runway"', '已处理 {{ activeElapsedSeconds }} 秒', 'elapsedLabel(item.elapsed_seconds)', 'function completedElapsedSeconds()', 'Native scroll-view clamps a newest short turn']) { eq(source.includes(marker), true); }
{
  const f = setup(); f.start(); f.api.accept(f.finish('fixture-context-a'));
  const key = f.api.state().storageKey; const records = f.storage.get(key);
  eq(records[0].rounds[0].presentation.context_ref,'fixture-context-a');
  f.api.refs.question.value = '那上个月呢'; f.api.submit();
  const second = f.calls.filter(call => call.path === '/runs').at(-1);
  eq(second.body.context_ref,'fixture-context-a'); eq(Object.keys(second.body.history[0]),['question','answer']);
  records[0].rounds[0].presentation.context_ref = 'fixture-changed-after-create'; f.storage.set(key,records);
  f.answer('/runs',{run_id:'mobile-next',generation:1,version:1,run_delivery_token:'next-delivery',status:'RECEIVED'});
  eq(f.calls.filter(call => call.path.endsWith('/execute')).length,0);
  f.api.accept({run_id:'mobile-next',generation:1,version:2,status:'COMPLETED',answer:{summary:'第二个答案',context_ref:'fixture-context-b',cards:[]}});
  f.api.newConversation(); f.api.refs.question.value = '今天现金业绩多少'; f.api.submit();
  const third = f.calls.filter(call => call.path === '/runs').at(-1);
  eq(Object.prototype.hasOwnProperty.call(third.body,'context_ref'),false); eq(third.body.history,[]); f.unmount();
}
{
  const f = setup(); const record = { created_at: 1788912000000, session_id: 'resume-session', conversation_id: 'resume-conversation', close_requested: false,
    pending: { question: '恢复中的问题' }, run: { run_id: 'resume-run', generation: 1, version: 4, run_delivery_token: 'resume-delivery', status: 'WORKFLOW_EXECUTING', progress: '正在核对数据' } };
  f.api.resumeActive(record); f.answer('/runs/resume-run', record.run);
  eq(f.api.refs.busy.value, true); eq(f.api.refs.progress.value, '正在核对数据'); eq(f.api.state().run.run_id, 'resume-run');
  f.api.resumeActive(record); f.answer('/runs/resume-run', null, { ok: false, responseKnown: false });
  eq(f.api.refs.busy.value, true); eq(f.api.state().run.run_id, 'resume-run'); eq(f.api.refs.progress.value, '连接中断，正在重新确认任务状态。'); f.unmount();
}
{
  const f = setup(3, false); const record = { created_at: 1788912000000, session_id: 'resume-session', conversation_id: 'resume-conversation', close_requested: false,
    pending: { question: '兼容模式恢复', history: [], output_format: 'screen' }, run: { run_id: 'resume-run', generation: 1, version: 4, run_delivery_token: 'resume-delivery', status: 'RECEIVED', progress: '已接纳' } };
  f.api.resumeActive(record); f.answer('/runs/resume-run', record.run);
  const execute = f.calls.find(call => !call.answered && call.path === '/runs/resume-run/execute');
  eq([execute.body.question, execute.body.history, execute.body.output_format], ['兼容模式恢复', [], 'screen']);
  f.answer('/runs/resume-run/execute', { ...record.run, version: 5, status: 'COMPLETED', answer: { summary: '已完成', cards: [] } }); f.unmount();
}
{
  const f = setup(); const record = { created_at: 1788912000000, session_id: 'resume-session', conversation_id: 'resume-conversation', close_requested: false,
    pending: { question: '已过期任务', history: [], output_format: 'screen' }, run: { run_id: 'resume-run', generation: 1, version: 4, run_delivery_token: 'resume-delivery', status: 'WORKFLOW_EXECUTING', progress: '正在处理' } };
  f.api.resumeActive(record); f.answer('/runs/resume-run', null, { ok: false, responseKnown: true, reason: 'AI_RUN_EXPIRED' });
  eq(f.api.state().run, null); eq(f.api.refs.busy.value, false); eq(f.api.refs.progress.value, '上一次任务已失效，请重新提问。'); f.unmount();
}
{
  const f = setup(3, true, true);
  const identity = 'fixture:merchant:manager', key = 'mohe-ai:v1:' + encodeURIComponent(identity), runtime = 'mohe-ai:v1:runtime:' + encodeURIComponent(identity);
  const record = { created_at: 1788912000000, session_id: 'old-session', conversation_id: 'old-conversation', close_requested: false,
    pending: { question: '旧页面迟到初始化', history: [], output_format: 'screen' }, run: { run_id: 'old-run', generation: 1, version: 1, run_delivery_token: 'old-delivery', status: 'RECEIVED' } };
  f.storage.set(key, [{ id: 'old-conversation', created_at: 1788912000000, rounds: [] }]); f.storage.set(runtime, record);
  f.unmount(); f.answer('/bootstrap', f.bootstrap);
  eq(f.calls.filter(call => call.path === '/runs/old-run').length, 0);
}
{
  const f = setup(); f.api.refs.question.value = '关闭前仍在接纳的请求'; f.api.submit(); const runtime = f.api.state().runtimeKey;
  // Closing before the create acknowledgement is an explicit cancellation,
  // even if native component recycling prevents the old entry from rendering
  // that acknowledgement. It must cancel the admitted server Run, not leave a
  // permanent local "running" record.
  f.api.closePanel(); f.unmount();
  f.answer('/runs', { run_id: 'late-mobile-run', generation: 1, version: 1, run_delivery_token: 'late-delivery', status: 'RECEIVED' });
  const cancel = f.calls.find(call => !call.answered && call.path === '/runs/late-mobile-run/cancel');
  eq([cancel.body.client_session_id, cancel.body.generation, cancel.body.run_delivery_token], [f.api.state().pending.client_session_id, 1, 'late-delivery']);
  f.answer('/runs/late-mobile-run/cancel', { run_id: 'late-mobile-run', generation: 1, version: 2, status: 'CANCELLED' });
  eq(f.storage.has(runtime), false);
}
{
  const f = setup(); f.api.refs.question.value = '同一任务的新页面已到下一步'; f.api.submit(); const runtime = f.api.state().runtimeKey;
  const pending = clean(f.api.state().pending);
  // The replacement entry may have already received a newer projection before
  // the retired entry's create acknowledgement arrives. Version 1 must not
  // erase the later guidance step.
  f.storage.set(runtime, { created_at: 1788912000000, session_id: pending.client_session_id, conversation_id: pending.conversation_id, close_requested: false, queued_clarification_id: '', guidance_submission: null, pending, run: { run_id: 'same-mobile-run', generation: 1, version: 4, run_delivery_token: 'newer-delivery', status: 'WAITING_CLARIFICATION', clarification: { id: 'newer-step' } } });
  f.unmount(); f.answer('/runs', { run_id: 'same-mobile-run', generation: 1, version: 1, run_delivery_token: 'older-delivery', status: 'RECEIVED' });
  const record = f.storage.get(runtime); eq([record.run.status, record.run.version, record.run.clarification.id], ['WAITING_CLARIFICATION', 4, 'newer-step']);
}
{
  const f = setup(); f.api.refs.question.value = '旧页面不得覆盖新任务'; f.api.submit(); const runtime = f.api.state().runtimeKey;
  // A different task can become active while the retiring component still has
  // an old pending request. Its unmount must leave the replacement untouched.
  f.storage.set(runtime, { created_at: 1788912000000, session_id: 'new-session', conversation_id: 'new-conversation', close_requested: false, queued_clarification_id: '', guidance_submission: null, pending: { client_request_id: 'new-request' }, run: { run_id: 'new-mobile-run', generation: 2, version: 3, status: 'UNDERSTANDING' } });
  f.unmount(); f.answer('/runs', { run_id: 'old-mobile-run', generation: 1, version: 1, run_delivery_token: 'old-delivery', status: 'RECEIVED' });
  eq(f.storage.get(runtime).run.run_id, 'new-mobile-run');
}
{
  const f = setup(); f.start(); const runtime = f.api.state().runtimeKey;
  // The replacement page can submit its choice before the retiring page's
  // unmount hook runs. The server still projects the same Run version here,
  // so version ordering alone cannot protect the newer local queued marker.
  const record = clean(f.storage.get(runtime));
  record.owner_id = 'replacement-entry'; record.queued_clarification_id = 'step-1';
  record.guidance_submission = { clarification_id: 'step-1', client_submission_id: 'replacement-choice', choices: { metric_code: 'cash_performance' }, schema_version: schema, intent_revision: 1, step_revision: 1 };
  f.storage.set(runtime, record); f.unmount();
  const retained = f.storage.get(runtime);
  eq([retained.owner_id, retained.queued_clarification_id, retained.guidance_submission.client_submission_id], ['replacement-entry', 'step-1', 'replacement-choice']);
}
{
  const shared = new Map(), old = setup(3, true, false, shared); old.start();
  const runtime = old.api.state().runtimeKey, current = clean(old.api.state().run);
  const replacement = setup(3, true, true, shared, false);
  replacement.answer('/bootstrap', replacement.bootstrap);
  while (replacement.calls.some(call => !call.answered && call.path === '/bootstrap')) replacement.answer('/bootstrap', replacement.bootstrap);
  const restore = replacement.calls.find(call => !call.answered && call.path === '/runs/mobile-run');
  assert.ok(restore, JSON.stringify(replacement.calls.map(call => ({ path: call.path, answered: call.answered }))));
  restore.answered = true; restore.done({ ok: true, data: clean(current) });
  const owner = shared.get(runtime).owner_id;
  // The old page still has a callback in flight after the replacement claims
  // this exact Run. It may update its own UI, but cannot reclaim device state.
  old.api.accept({ ...current, version: current.version + 1, progress: '旧页面的迟到回调' });
  const retained = shared.get(runtime);
  eq([retained.owner_id, retained.run.version], [owner, current.version]); old.unmount(); replacement.unmount();
}
{
  const shared = new Map(), old = setup(3, true, false, shared); old.api.refs.question.value = '接纳中的任务'; old.api.submit();
  const runtime = old.api.state().runtimeKey;
  const replacement = setup(3, true, true, shared, false);
  replacement.answer('/bootstrap', replacement.bootstrap);
  while (replacement.calls.some(call => !call.answered && call.path === '/bootstrap')) replacement.answer('/bootstrap', replacement.bootstrap);
  const owner = shared.get(runtime).owner_id;
  // The original create reply may still reach the retiring page after the
  // replacement retries the same idempotent request. It cannot retake storage.
  old.answer('/runs', { run_id: 'pending-run', generation: 1, version: 1, run_delivery_token: 'pending-delivery', status: 'RECEIVED' });
  eq(shared.get(runtime).owner_id, owner); old.unmount(); replacement.unmount();
}
{
  const shared = new Map(), old = setup(3, true, false, shared); old.start();
  const runtime = old.api.state().runtimeKey, current = clean(old.api.state().run);
  const replacement = setup(3, true, true, shared, false);
  replacement.answer('/bootstrap', replacement.bootstrap);
  while (replacement.calls.some(call => !call.answered && call.path === '/bootstrap')) replacement.answer('/bootstrap', replacement.bootstrap);
  const owner = shared.get(runtime).owner_id;
  const restore = replacement.calls.find(call => !call.answered && call.path === '/runs/mobile-run'); assert.ok(restore); restore.answered = true;
  restore.done({ ok: true, data: { ...current, version: current.version + 2, status: 'COMPLETED', answer: { summary: '新页面完成', cards: [] } } });
  replacement.api.newConversation(); replacement.api.closePanel();
  old.api.accept({ ...current, version: current.version + 1, status: 'WORKFLOW_EXECUTING' });
  const retained = shared.get(runtime);
  eq([retained != null && retained.resolved_run != null, retained.resolved_run.run_id, retained.owner_id == owner], [true, 'mobile-run', true]); old.unmount(); replacement.unmount();
}
{
  const shared = new Map(), first = setup(3, true, false, shared), second = setup(3, true, false, shared);
  first.api.refs.question.value = '先前页面的问题'; first.api.submit();
  const accepted = { run_id: 'foreign-active-run', generation: 1, version: 1, run_delivery_token: 'foreign-delivery', status: 'RECEIVED' };
  first.answer('/runs', accepted);
  second.api.refs.question.value = '稍后继续问的问题'; second.api.submit();
  eq([second.calls.filter(call => call.path === '/runs').length, second.api.refs.question.value, second.api.refs.busy.value], [0, '稍后继续问的问题', true]);
  second.answer('/bootstrap', second.bootstrap);
  const restored = second.calls.find(call => !call.answered && call.path === '/runs/foreign-active-run'); assert.ok(restored);
  restored.answered = true; restored.done({ ok: true, data: { ...accepted, version: 2, status: 'COMPLETED', answer: { summary: '先前答案', cards: [] } } });
  second.api.submit(); eq(second.calls.filter(call => call.path === '/runs').length, 1);
  eq(second.calls.filter(call => call.path === '/runs').at(-1).body.question, '稍后继续问的问题'); first.unmount(); second.unmount();
}
{
  const shared = new Map(), first = setup(3, true, false, shared), second = setup(3, true, false, shared);
  first.api.refs.question.value = '正在处理'; first.api.submit();
  second.api.refs.question.value = '等待恢复后再问'; second.api.submit();
  second.answer('/bootstrap', null, { ok: false, responseKnown: false, message: '连接中断' });
  eq([second.api.refs.busy.value, second.api.refs.question.value, second.calls.filter(call => call.path === '/runs').length], [false, '等待恢复后再问', 0]);
  first.unmount(); second.unmount();
}
{
  const f = setup(); f.start(); f.api.chooseValue(metric, metric.options[0]); const runtime = f.api.state().runtimeKey;
  const record = clean(f.storage.get(runtime)); record.owner_id = 'replacement-entry'; f.storage.set(runtime, record);
  f.api.confirmChoices(); eq(f.calls.filter(call => call.path.endsWith('/clarify')).length, 0); f.unmount();
}
{
  const shared = new Map(), old = setup(3, true, false, shared); old.api.refs.question.value = '切页后完成'; old.api.submit();
  const runtime = old.api.state().runtimeKey; old.unmount();
  old.answer('/runs', { run_id: 'late-completed-run', generation: 1, version: 2, run_delivery_token: 'late-completed-delivery', status: 'COMPLETED', answer: { summary: '迟到结果', cards: [] } });
  const replacement = setup(3, true, true, shared, false);
  replacement.answer('/bootstrap', replacement.bootstrap);
  while (replacement.calls.some(call => !call.answered && call.path === '/bootstrap')) replacement.answer('/bootstrap', replacement.bootstrap);
  const retained = shared.get(runtime);
  eq([retained != null && retained.resolved_run != null, retained.resolved_run.run_id, replacement.api.refs.displayed.value.at(-1).answer], [true, 'late-completed-run', '迟到结果']); replacement.unmount();
}
{
  const f = setup(); f.api.openPanel(); f.api.closePanel(); f.api.openPanel(); f.api.refs.question.value = '关闭后重新提问'; f.api.submit();
  f.answer('/runs', { run_id: 'reopened-run', generation: 1, version: 1, run_delivery_token: 'reopened-delivery', status: 'RECEIVED' });
  eq(f.calls.filter(call => call.path === '/runs/reopened-run/cancel').length, 0); eq(f.api.state().run.run_id, 'reopened-run'); f.unmount();
}
{
  const f = setup(); f.api.refs.question.value = '切页前已完成的请求'; f.api.submit(); const runtime = f.api.state().runtimeKey;
  // Navigation alone is not a cancel. A late terminal acknowledgement is
  // retained and rendered by the replacement entry instead of being lost.
  f.unmount();
  f.answer('/runs', { run_id: 'late-terminal-run', generation: 1, version: 2, run_delivery_token: 'late-terminal-delivery', status: 'COMPLETED', answer: { summary: '切页后仍可看到', cards: [] } });
  const record = f.storage.get(runtime); eq([record.run.run_id, record.run.status, record.close_requested], ['late-terminal-run', 'COMPLETED', false]);
  f.api.resumeActive(record); eq(f.api.refs.displayed.value.at(-1).answer, '切页后仍可看到'); eq([f.storage.has(runtime), f.storage.get(runtime).resolved_run.run_id], [true, 'late-terminal-run']);
}
{
  const f = setup(); f.start(); const key = f.api.state().storageKey, records = f.storage.get(key);
  records[0].rounds.push({ question: '移动引导测试', answer: '已校验', run_id: 'mobile-run', generation: 1, created_at: records[0].created_at }); f.storage.set(key, records);
  f.api.accept(f.finish()); eq(f.api.load()[0].rounds.length, 1); eq(f.api.refs.displayed.value.length, 1); f.unmount();
}
// R47: execute real sizing handlers, including linechange arriving before v-model.
{
  const f = setup(); const r = f.api.refs;
  const line = n => f.api.onComposerLineChange({detail:{lineCount:n}});
  line(2); eq([r.composerInputHeight.value,r.composerExpanded.value], [66,true]);
  line(3); eq(r.composerInputHeight.value,94);
  line(20); eq(r.composerInputHeight.value,144);
  line(1); eq([r.composerInputHeight.value,r.composerExpanded.value],[38,false]);
  line(NaN); line(0); eq(r.composerInputHeight.value,38);
  line(4); f.api.onComposerInput({detail:{value:''}});
  eq([r.composerInputHeight.value,r.composerExpanded.value],[38,false]);
  line(3); r.question.value='测试长文本'; f.api.submit();
  eq([r.composerInputHeight.value,r.composerExpanded.value],[38,false]);
  assert.match(source, /:fixed="platformUsesNativeCanvas" :auto-height="false"/);
  assert.match(source, /height: composerInputHeight/);
  assert.match(source, /ai-composer--expanded\{padding:12px 54px 56px/);
  f.unmount();
}
console.log(`R5/R47 mobile production-controller: ${checks} checks PASS (TS-lowered controller + callback fixtures; not H5/native UI acceptance)`);

// R45: desktop and mobile consume the same nested presentation; no query or metric recomputation.
{
  const f = setup(); const facts = [{label:'现金业绩',value:'11,059',unit:'元',section:'收款'}, {label:'退款业绩',value:'0',unit:'元',section:'收款'}, {label:'服务项目数',value:'137',unit:'项',section:'服务'}];
  const p = {version:1,headline:'今日经营概览',period_label:'测试日期',facts,notes:[]};
  const before = f.calls.length;
  eq(f.api.answerPresentation({presentation:p}), p);
  eq(f.api.answerPresentation(p), p);
  eq(f.api.answerPresentation({summary:'查询失败'}), null);
  eq(f.api.presentationGroups(facts), [{section:'收款',facts:facts.slice(0,2)}, {section:'服务',facts:facts.slice(2)}]);
  eq(f.calls.length,before);
  assert.ok(!source.includes('@tap="showHistory"'));
  // 单行导航移除新建操作行，但保留底部加号及关闭面板原有行为。
  assert.ok(!source.includes('class="ai-workspace-actions"'));
  assert.match(source, /class="ai-back-button"[^>]*@tap="closePanel"/);
  assert.match(source, /paddingLeft: nativeCapsuleSpace/);
  assert.match(source, /class="ai-composer-more"/);
  f.unmount();
  console.log('R45 nested presentation, grouped facts and no additional requests: PASS');
}

// R52: the four actions stay in one vertical menu. Local session changes must
// not issue a new query, delete another conversation, or cross an active Run.
{
  for (const label of ['生成Execl','新增对话','清空对话','历史对话']) assert.match(source, new RegExp('class="ai-composer-option"[^>]*>' + label));
  assert.match(source, /\.ai-composer-options__actions\{display:flex;flex-direction:column/);
  const f = setup(); const r = f.api.refs;
  r.excelAvailable.value = true; f.api.toggleComposerOptions(); eq(r.composerOptionsOpen.value,true);
  f.api.toggleExcel(); eq(r.wantExcel.value,true); eq(f.calls.filter(call => call.path == '/runs').length,0);
  const original = f.api.state().conversation;
  f.start(); f.api.createConversationFromMenu(); eq(f.api.state().conversation,original);
  f.api.accept(f.finish()); eq(f.api.load().find(s => s.id == original).rounds.length,1);
  f.api.createConversationFromMenu(); const second = f.api.state().conversation;
  eq([second == original,r.displayed.value.length,r.composerOptionsOpen.value],[false,0,false]);
  f.api.toggleComposerOptions(); f.api.openHistory();
  eq(r.recentConversations.value.some(s => s.id == original),true);
  f.api.selectConversation(original); eq([f.api.state().conversation,r.displayed.value.length,r.historyOpen.value],[original,1,false]);
  f.api.toggleComposerOptions(); f.api.confirmClearConversation();
  eq([f.modals.length,f.api.load().some(s => s.id == original)],[1,true]);
  f.modals[0].success({confirm:false}); eq(f.api.load().some(s => s.id == original),true);
  f.api.confirmClearConversation(); f.modals[1].success({confirm:true});
  eq([f.api.load().some(s => s.id == original),f.api.load().some(s => s.id == second),r.displayed.value.length],[false,true,0]);
  eq(f.calls.filter(call => call.path == '/runs').length,1);
  f.unmount();
  console.log('R52 one-column composer menu, history isolation and clear confirmation: PASS');
}
