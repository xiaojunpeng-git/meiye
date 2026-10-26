import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { webcrypto } from 'node:crypto';
import { mountMoheAi } from '../../前端代码/shared/mohe-ai/browser-entry.mjs';

const require = createRequire(import.meta.url);
const { JSDOM } = require('../../前端代码/admin/node_modules/jsdom');
const dom = new JSDOM('<!doctype html><body></body>', { url: 'https://fixture.test' });
dom.window.HTMLElement.prototype.attachShadow = function () {
  const root = dom.window.document.createElement('section');
  this.appendChild(root);
  Object.defineProperty(this, 'shadowRoot', { value: root });
  return root;
};
globalThis.window = dom.window;
globalThis.document = dom.window.document;
globalThis.crypto = webcrypto;

const wait = () => new Promise(resolve => setTimeout(resolve, 12));
let created = 0;
let releaseFirstStatus;
const request = async (method, path) => {
  if (path === '/bootstrap') {
    return { enabled: true, async_execution: true, can_configure: true, identity_key: 'fixture:single-entry', window_token: 'w', capabilities: { metric_codes: [], output_formats: ['screen'] } };
  }
  if (path === '/runs') {
    created += 1;
    return { run_id: 'run-' + created, generation: 1, run_delivery_token: 'delivery-' + created, status: 'RECEIVED', progress: '已接纳' };
  }
  if (method === 'GET' && path === '/runs/run-1') return new Promise(resolve => { releaseFirstStatus = resolve; });
  if (method === 'GET' && path === '/runs/run-2') {
    return { run_id: 'run-2', generation: 1, run_delivery_token: 'delivery-2', version: 2, status: 'COMPLETED', answer: { summary: '第二次结果', cards: [] } };
  }
  if (path.endsWith('/delivery')) return { accepted: true };
  throw new Error('unexpected request ' + method + ' ' + path);
};

const first = mountMoheAi({ request });
await wait();
let root = document.querySelector('[data-mohe-ai]').shadowRoot;
root.querySelector('.entry').click();
await wait();
root.querySelector('textarea').value = '第一次问题';
Array.from(root.querySelectorAll('button')).find(button => button.textContent === '发送').click();
await new Promise(resolve => setTimeout(resolve, 1050));
assert.equal(typeof releaseFirstStatus, 'function');

// This intentionally mounts a replacement before the old poll settles. The
// entry itself owns the handoff; callers do not have to remember to dispose
// the old Vue component in an exact lifecycle order.
const second = mountMoheAi({ request });
await wait();
assert.equal(document.querySelectorAll('[data-mohe-ai]').length, 1);
root = document.querySelector('[data-mohe-ai]').shadowRoot;
root.querySelector('.entry').click();
await wait();
releaseFirstStatus({ run_id: 'run-1', generation: 1, run_delivery_token: 'delivery-1', version: 2, status: 'COMPLETED', answer: { summary: '第一次结果', cards: [] } });
await wait();
assert.match(root.textContent, /第一次问题/);
assert.match(root.textContent, /第一次结果/);

root.querySelector('textarea').value = '第二次问题';
Array.from(root.querySelectorAll('button')).find(button => button.textContent === '发送').click();
await new Promise(resolve => setTimeout(resolve, 1050));
assert.match(root.textContent, /第二次问题/);
assert.match(root.textContent, /第二次结果/);
assert.equal(root.textContent.split('第一次结果').length - 1, 1);
assert.equal(root.textContent.split('第二次结果').length - 1, 1);
second();
first();

console.log('Browser single-entry runtime handoff: 7 checks PASS');
