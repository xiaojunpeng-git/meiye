import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { webcrypto } from 'node:crypto';
import { mountMoheAi } from '../../前端代码/shared/mohe-ai/browser-entry.mjs';

const require = createRequire(import.meta.url);
const { JSDOM } = require('../../前端代码/admin/node_modules/jsdom');
const dom = new JSDOM('<!doctype html><body></body>', { url: 'https://fixture.test' });
dom.window.HTMLElement.prototype.attachShadow = function () { const root = dom.window.document.createElement('section'); this.appendChild(root); Object.defineProperty(this, 'shadowRoot', { value: root }); return root; };
globalThis.window = dom.window; globalThis.document = dom.window.document; globalThis.crypto = webcrypto;

const wait = () => new Promise(resolve => setTimeout(resolve, 12));
let releaseCreate; const calls = [];
const request = async (method, path, payload) => {
  calls.push({ method, path, payload });
  if (path === '/bootstrap') return { enabled: true, async_execution: true, can_configure: true, identity_key: 'fixture:late-close', window_token: 'w', capabilities: { metric_codes: [], output_formats: ['screen'] } };
  if (path === '/runs') return new Promise(resolve => { releaseCreate = resolve; });
  if (path.endsWith('/cancel')) return { run_id: 'late-run', generation: 1, status: 'CANCELLED' };
  throw new Error('Unexpected request: ' + method + ' ' + path);
};

const dispose = mountMoheAi({ request }); await wait();
const root = document.querySelector('[data-mohe-ai]').shadowRoot;
root.querySelector('.entry').click(); await wait();
root.querySelector('textarea').value = '关闭前仍在接纳的请求';
Array.from(root.querySelectorAll('button')).find(button => button.textContent === '发送').click(); await wait();
Array.from(root.querySelectorAll('button')).find(button => button.textContent === '关闭').click();
dispose();
releaseCreate({ run_id: 'late-run', generation: 1, version: 1, run_delivery_token: 'delivery', status: 'RECEIVED' });
await wait(); await wait();

const cancel = calls.find(call => call.path === '/runs/late-run/cancel');
assert.ok(cancel);
assert.deepEqual(cancel.payload, { client_session_id: calls.find(call => call.path === '/runs').payload.client_session_id, run_delivery_token: 'delivery', generation: 1 });
assert.equal(window.localStorage.getItem('mohe-ai:v1:fixture%3Alate-close:runtime'), null);
console.log('Browser late-admission close after page replacement: 3 checks PASS');
