import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { webcrypto } from 'node:crypto';
import { mountMoheAi } from '../../前端代码/shared/mohe-ai/browser-entry.mjs';
import { DeviceSessions } from '../../前端代码/shared/mohe-ai/device-session.mjs';

const require = createRequire(import.meta.url);
const { JSDOM } = require('../../前端代码/admin/node_modules/jsdom');
const flush = () => new Promise(resolve => setTimeout(resolve, 12));
const legacyMenu = { question: '这次没能给出可靠结果。请选择一种方式继续。', options: [
  { value: 'retry_original', label: '按原问题再查一次' },
  { value: 'edit_original', label: '修改原问题后查询' },
  { value: 'new_conversation', label: '开启新对话' }
] };

// Exercise the mounted client and its actual outgoing envelopes. These are
// protocol/DOM tests: no model, customer data or real browser layout is used.
async function setup(responses, presentation = 'workspace', historicalFailure = false) {
  const dom = new JSDOM('<!doctype html><body></body>', { url: 'https://opt3-fixture.test' });
  dom.window.HTMLElement.prototype.attachShadow = function () {
    const root = dom.window.document.createElement('section'); this.appendChild(root);
    Object.defineProperty(this, 'shadowRoot', { value: root }); return root;
  };
  globalThis.window = dom.window; globalThis.document = dom.window.document; globalThis.crypto = webcrypto;
  const sessions = new DeviceSessions(window.localStorage, 'opt3:platform:manager');
  if (historicalFailure) {
    const savedGuidance = historicalFailure === 'timeout'
      ? { kind: 'technical_error', question: '模型响应超时，请稍后重试。', retry_original: true }
      : legacyMenu;
    sessions.create('legacy-failure');
    sessions.append('legacy-failure', '旧失败问题', savedGuidance.question,
      { summary: savedGuidance.question, terminal_status: 'FAILED', failure_guidance: savedGuidance,
        ...(historicalFailure === 'timeout' ? { recovery_origin: { conversation_id: 'legacy-failure',
          history: [], context_ref: null, created_at: Date.now() - 86400000 } } : {}) },
      null, { contextEligible: false });
  }
  const calls = []; let index = 0;
  const request = async (method, path, payload) => {
    calls.push({ method, path, payload: payload ? JSON.parse(JSON.stringify(payload)) : null });
    if (path === '/bootstrap') return { enabled: true, async_execution: true,
      identity_key: 'opt3:platform:manager', window_token: 'window', capabilities: { output_formats: ['screen'] } };
    if (path === '/runs') {
      const response = responses[index++]; assert.ok(response, 'fixture response');
      return response.accepted === false ? response
        : { run_id: 'run-' + index, generation: 1, version: 1, run_delivery_token: 'delivery', ...response };
    }
    if (path.endsWith('/delivery')) return { accepted: true };
    throw new Error('Unexpected path: ' + path);
  };
  const dispose = mountMoheAi({ request, presentation }); await flush();
  const root = document.querySelector('[data-mohe-ai]').shadowRoot;
  root.querySelector('.entry').click(); await flush();
  const send = async question => {
    root.querySelector('textarea').value = question;
    [...root.querySelectorAll('button')].find(button => button.textContent === '发送').click(); await flush();
  };
  return { root, calls, sessions, send, dispose };
}

// Typed failures retain the server's precise explanation and retry unit.
// The client never substitutes a generic business recommendation or menu.
{
  const f = await setup([{ accepted: false, reason: 'CIRCUIT_OPEN', retry_after_ms: 12001,
    message: '旧版通用提示', failure_guidance: { kind: 'technical_error', question: '模型服务暂时不可用，完整指标查询仍可使用。' } }]);
  await f.send('本次查询');
  const turn = f.root.querySelector('.conversation-turn');
  assert.match(turn.textContent, /模型服务暂时不可用，完整指标查询仍可使用。\n预计 13 秒后可重试。/);
  assert.doesNotMatch(turn.textContent, /旧版通用提示|现金业绩|CIRCUIT_OPEN/);
  assert.equal(f.root.querySelector('[data-failure-guidance]'), null);
  assert.equal(f.root.querySelector('.workspace-run-status'), null);
  assert.equal(f.root.querySelector('button[aria-label="发送问题"]').disabled, false);
  assert.equal(f.sessions.load()[0].rounds[0].context_eligible, false);
  assert.equal(f.calls.filter(call => call.path === '/runs').length, 1);
  f.dispose();
}

// During a staged update, legacy admission messages still carry the actual
// refusal. A model timeout gets one retry, never the old four-choice menu.
for (const response of [
  { accepted: false, reason: 'RATE_LIMITED', message: '您近期提问较频繁，请稍后再问。', failure_guidance: legacyMenu },
  { status: 'FAILED', reason: 'AI_MODEL_RESULT_UNKNOWN', message: legacyMenu.question, failure_guidance: legacyMenu },
  { status: 'FAILED', reason: 'AI_CAPABILITY_CHANGED' },
  { status: 'FAILED', reason: 'AI_INTENT_UNRESOLVED', message: '暂时无法确定您要查询的内容。' }
]) {
  const f = await setup([response]); await f.send('一次完整问题');
  const turn = f.root.querySelector('.conversation-turn');
  assert.doesNotMatch(turn.textContent, /请选择一种方式|现金业绩|消耗业绩|AI_/);
  assert.equal(f.root.querySelector('[data-failure-guidance]') !== null,
    response.reason === 'AI_MODEL_RESULT_UNKNOWN');
  assert.doesNotMatch(turn.textContent, /您可以这样继续|我来补充描述/);
  if (response.accepted === false) assert.match(turn.textContent, /提问较频繁/);
  if (response.reason === 'AI_MODEL_RESULT_UNKNOWN') assert.match(turn.textContent, /AI 服务暂时未返回结果/);
  if (response.reason === 'AI_CAPABILITY_CHANGED') assert.match(turn.textContent, /请刷新页面/);
  if (response.reason === 'AI_INTENT_UNRESOLVED') assert.match(turn.textContent, /暂时无法确定您要查询的内容/);
  f.dispose();
}

// Preserve confirmed out-of-scope explanations, validate optional retry
// metadata, and never turn technical responses into local recovery actions.
{
  const f = await setup([{ status: 'FAILED', reason: 'AI_INTENT_UNRESOLVED', retry_after_ms: -1,
    failure_guidance: { kind: 'capability_help', question: '当前系统无法提供这类信息。' } }]);
  await f.send('系统范围外问题');
  assert.match(f.root.querySelector('.conversation-turn').textContent, /当前系统无法提供这类信息/);
  assert.doesNotMatch(f.root.querySelector('.conversation-turn').textContent, /秒后可重试/);
  f.dispose();
}

// A failed same-topic turn is transcript, never verified business context.
// Only a server-confirmed reset prevents the next request using an older ref.
for (const topicBoundary of [undefined, 'reset']) {
  const f = await setup([
    { status: 'COMPLETED', answer: { summary: '已核对的查询结果', context_ref: 'signed-prior-result' } },
    { status: 'FAILED', reason: 'AI_MODEL_RESULT_UNKNOWN', topic_boundary: topicBoundary,
      failure_guidance: { kind: 'technical_error', question: '模型响应超时，请稍后重试。' } },
    { status: 'COMPLETED', answer: { summary: '后续查询结果' } }
  ]);
  await f.send('前序问题'); await f.send('后续输入'); await f.send('继续追问');
  const creates = f.calls.filter(call => call.path === '/runs');
  assert.equal(creates[1].payload.context_ref, 'signed-prior-result');
  assert.equal(creates[2].payload.context_ref, topicBoundary === 'reset' ? undefined : 'signed-prior-result');
  assert.deepEqual(creates[2].payload.history.map(row => row.question),
    topicBoundary === 'reset' ? ['后续输入'] : ['前序问题', '后续输入']);
  const failed = f.sessions.load()[0].rounds[1];
  assert.equal(failed.context_eligible, false);
  assert.equal(failed.topic_boundary, topicBoundary);
  assert.deepEqual(failed.presentation.recovery_origin.history.map(row => row.question), ['前序问题']);
  f.dispose();
}

// Shared-source cleanup must retain the separately approved drawer actions.
{
  const f = await setup([{ status: 'FAILED', reason: 'AI_ATTEMPT_UNRESOLVED', failure_guidance: legacyMenu }], 'drawer');
  await f.send('抽屉兼容问题');
  assert.equal(f.root.querySelectorAll('[data-failure-guidance] .guidance-number').length, 4);
  f.dispose();
}

// Reloaded platform history must not revive the retired generic action menu.
// Its original local transcript remains available as evidence, not rewritten.
{
  const f = await setup([], 'workspace', true);
  [...f.root.querySelectorAll('.workspace-history-open')].find(button => button.textContent === '旧失败问题').click();
  const turn = f.root.querySelector('.conversation-turn');
  assert.match(turn.textContent, /旧失败问题/);
  assert.doesNotMatch(turn.textContent, /请选择一种方式|按原问题再查一次/);
  assert.equal(turn.querySelector('[data-failure-guidance]'), null);
  assert.equal(f.sessions.load().find(session => session.id === 'legacy-failure').rounds[0].answer, legacyMenu.question);
  f.dispose();
}
// Historical relative-date questions cannot be silently resubmitted on a
// later business day. The same compact action restores the draft for review.
{
  const f = await setup([], 'workspace', 'timeout');
  [...f.root.querySelectorAll('.workspace-history-open')].find(button => button.textContent === '旧失败问题').click();
  const retry = f.root.querySelector('[data-failure-guidance]');
  assert.equal(retry.textContent, '核对日期后重试');
  retry.click();
  assert.equal(f.calls.filter(call => call.path === '/runs').length, 0);
  assert.equal(f.root.querySelector('textarea[aria-label="输入问题"]').value, '旧失败问题');
  f.dispose();
}
console.log('OPT3 platform failure messages / retry metadata / topic handoff / drawer compatibility: PASS');
