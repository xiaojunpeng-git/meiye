import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { webcrypto } from 'node:crypto';
import { mountMoheAi } from '../../前端代码/shared/mohe-ai/browser-entry.mjs';
import { DeviceSessions } from '../../前端代码/shared/mohe-ai/device-session.mjs';

const require = createRequire(import.meta.url);
const { JSDOM } = require('../../前端代码/admin/node_modules/jsdom');
const dom = new JSDOM('<!doctype html><body></body>', { url: 'https://fixture.test' });
dom.window.HTMLElement.prototype.attachShadow = function () {
  const child = dom.window.document.createElement('section'); this.appendChild(child);
  Object.defineProperty(this, 'shadowRoot', { value: child }); return child;
};
globalThis.window = dom.window; globalThis.document = dom.window.document; globalThis.crypto = webcrypto;
const flush = () => new Promise(resolve => setTimeout(resolve, 10));
const sessions = new DeviceSessions(window.localStorage, 'fixture:history-export');
const conversation = sessions.create('saved-conversation').id;
const answer = {
  summary: '符合两项条件的会员共有504人。页面仅展示前20条；完整明细另行生成 Excel。',
  auto_export_required: true,
  table: { columns: [{ key: 'label', label: '会员' }, { key: 'metric_age', label: '距最近到店天数（天）' }],
    rows: [{ label: '测试会员', metric_age: '5' }], total_rows: 504 }
};
sessions.append(conversation, '符合条件会员详情', answer.summary, answer, {
  run_id: 'saved-run', generation: 1, run_delivery_token: 'saved-proof',
  client_session_id: 'original-session', status: 'COMPLETED', feedback_available: false
});
assert.equal(sessions.load()[0].rounds[0].run_delivery_token, 'saved-proof');

const calls = []; let exportStatus = 'not_requested';
const request = async (method, path, payload) => {
  calls.push({ method, path, payload });
  if (path === '/bootstrap') return { enabled: true, can_configure: true,
    identity_key: 'fixture:history-export', async_execution: true,
    capabilities: { output_formats: ['screen', 'screen_and_xlsx'] } };
  if (path === '/runs/saved-run/export-status') return { status: exportStatus, filename: '经营数据.xlsx' };
  if (path === '/runs/saved-run/export' && method === 'POST') {
    exportStatus = 'succeeded'; return { status: exportStatus, filename: '经营数据.xlsx' };
  }
  throw new Error('Unexpected request: ' + method + ' ' + path);
};
const dispose = mountMoheAi({ request, presentation: 'workspace' }); await flush();
const root = document.querySelector('[data-mohe-ai]').shadowRoot;
root.querySelector('.entry').click(); await flush();
const history = Array.from(root.querySelectorAll('.workspace-history-open'))
  .find(button => button.textContent === '符合条件会员详情');
assert.ok(history);
history.click(); await flush();
assert.match(root.querySelector('.body').textContent, /完整明细尚未生成 Excel/);
assert.equal(calls.filter(call => call.method === 'POST').length, 0,
  'opening history must not create a file task without a customer action');
const status = calls.find(call => call.path === '/runs/saved-run/export-status');
assert.equal(status.payload.client_session_id, 'original-session');
assert.equal(status.payload.run_delivery_token, 'saved-proof');
Array.from(root.querySelectorAll('button')).find(button => button.textContent === '生成 Excel').click();
await flush();
assert.equal(calls.filter(call => call.path === '/runs/saved-run/export' && call.method === 'POST').length, 1);
assert.ok(Array.from(root.querySelectorAll('button')).some(button => button.textContent === '下载 Excel'));
history.click(); await flush();
assert.ok(Array.from(root.querySelectorAll('button')).some(button => button.textContent === '下载 Excel'),
  'reopening a generated file restores its download entry from server status');
dispose();
console.log('Browser history export restoration: PASS');
