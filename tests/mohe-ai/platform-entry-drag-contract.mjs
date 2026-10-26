import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { webcrypto } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { mountMoheAi } from '../../前端代码/shared/mohe-ai/browser-entry.mjs';

const require = createRequire(import.meta.url);
const { JSDOM } = require('../../前端代码/admin/node_modules/jsdom');
const dom = new JSDOM('<!doctype html><body></body>', { url: 'https://fixture.test' });
const platformEntry = readFileSync(new URL('../../前端代码/admin/src/components/MoheAiEntry.vue', import.meta.url), 'utf8');
const cashierEntry = readFileSync(new URL('../../前端代码/cashier-v3/src/components/MoheAiEntry.vue', import.meta.url), 'utf8');
assert.match(platformEntry, /draggableEntry: true/);
assert.doesNotMatch(cashierEntry, /draggableEntry: true/);
// The installed jsdom has no Shadow DOM or layout. Supply only the browser
// boundaries needed to verify drag versus click and persisted positioning.
dom.window.HTMLElement.prototype.attachShadow = function () {
  const root = dom.window.document.createElement('section');
  this.appendChild(root);
  Object.defineProperty(this, 'shadowRoot', { value: root });
  return root;
};
globalThis.window = dom.window;
globalThis.document = dom.window.document;
globalThis.crypto = webcrypto;
Object.defineProperty(window, 'innerWidth', { configurable: true, value: 400 });
Object.defineProperty(window, 'innerHeight', { configurable: true, value: 300 });

const request = async (_method, path) => {
  if (path === '/bootstrap') return { enabled: true, async_execution: true, identity_key: 'drag-fixture', capabilities: { metric_codes: [], output_formats: ['screen'] } };
  throw new Error(`Unexpected request: ${path}`);
};
const nextTurn = () => new Promise(resolve => setTimeout(resolve, 10));
const pointer = (target, type, x, y) => {
  const event = new window.Event(type, { bubbles: true, cancelable: true });
  Object.defineProperties(event, { pointerId: { value: 1 }, button: { value: 0 }, isPrimary: { value: true }, clientX: { value: x }, clientY: { value: y } });
  target.dispatchEvent(event);
};
const mount = async () => {
  const dispose = mountMoheAi({ request, presentation: 'workspace', entryIconUrl: '/icon.gif', draggableEntry: true });
  await nextTurn();
  const root = document.querySelector('[data-mohe-ai]').shadowRoot;
  const entry = root.querySelector('.entry');
  let capturedPointer = null;
  entry.setPointerCapture = id => { capturedPointer = id; };
  entry.hasPointerCapture = id => capturedPointer === id;
  entry.releasePointerCapture = id => { if (capturedPointer === id) capturedPointer = null; };
  entry.getBoundingClientRect = () => ({ left: parseFloat(entry.style.left) || 314, top: parseFloat(entry.style.top) || 212, width: 58, height: 58 });
  return { dispose, root, entry, capturedPointer: () => capturedPointer };
};

let instance = await mount();
assert.equal(instance.entry.classList.contains('entry--draggable'), true);
assert.equal(instance.entry.querySelector('img').draggable, false);

// A real drag moves the launcher without opening the AI workspace.
pointer(instance.entry, 'pointerdown', 330, 230);
assert.equal(instance.capturedPointer(), 1);
pointer(window, 'pointermove', 150, 100);
pointer(window, 'pointerup', 150, 100);
assert.equal(instance.capturedPointer(), null);
instance.entry.click();
assert.equal(instance.root.querySelector('[role=dialog]'), null);
assert.deepEqual(JSON.parse(window.localStorage.getItem('mohe-ai:platform-entry-position:v1')), { x: 134, y: 82 });
instance.dispose();

// The same browser restores the chosen position. A short click still opens.
instance = await mount();
assert.equal(instance.entry.style.left, '134px');
assert.equal(instance.entry.style.top, '82px');
instance.entry.click();
await nextTurn();
assert.ok(instance.root.querySelector('[role=dialog]'));

// Resizing clamps both coordinates to keep the full icon reachable.
Object.defineProperty(window, 'innerWidth', { configurable: true, value: 100 });
Object.defineProperty(window, 'innerHeight', { configurable: true, value: 90 });
window.dispatchEvent(new window.Event('resize'));
assert.deepEqual(JSON.parse(window.localStorage.getItem('mohe-ai:platform-entry-position:v1')), { x: 34, y: 24 });
instance.dispose();
console.log('Platform AI entry drag: PASS');
