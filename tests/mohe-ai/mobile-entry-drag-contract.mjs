import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import vm from 'node:vm';

const require = createRequire(import.meta.url);
const esbuild = require('../../前端代码/cashier-v3/node_modules/esbuild');
const source = readFileSync(new URL('../../前端代码/mobile-vue3/src/shared/components/mohe-ai-entry.uvue', import.meta.url), 'utf8');
assert.match(source, /@touchstart="onEntryTouchStart"/);
assert.match(source, /@touchmove\.stop\.prevent="onEntryTouchMove"/);
assert.match(source, /@tap="onEntryTap"/);
const script = source.match(/<script setup lang="uts">([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '');
const expose = '\nglobalThis.entry = { restoreEntryPosition, onEntryTouchStart, onEntryTouchMove, onEntryTouchEnd, onEntryTap, position: entryPositionStyle, opened };';
const code = esbuild.transformSync(script + expose, { loader: 'ts', target: 'es2020' }).code;
const key = 'mohe-ai:mobile-entry-position:v1';
const storage = new Map();
function mount(width = 390, height = 800, safeBottom = 0) {
  const context = {
    ref: value => ({ value }), onMounted: () => {}, onUnmounted: () => {}, currentMobilePlatform: () => 'H5',
    uni: {
      getWindowInfo: () => ({ windowWidth: width, windowHeight: height, safeAreaInsets: { bottom: safeBottom } }),
      getStorageSync: name => storage.get(name), setStorageSync: (name, value) => storage.set(name, value)
    }
  };
  vm.runInNewContext(code, context);
  context.entry.restoreEntryPosition();
  return context.entry;
}
const touch = (x, y) => ({ touches: [{ clientX: x, clientY: y }] });
let entry = mount();
assert.equal(entry.position.value, ''); // The original default CSS position remains until the first drag.
entry.onEntryTouchStart(touch(330, 680));
entry.onEntryTouchMove(touch(220, 540));
entry.onEntryTouchEnd(touch(220, 540));
entry.onEntryTap();
assert.equal(entry.opened.value, false);
assert.deepEqual(JSON.parse(JSON.stringify(storage.get(key))), { x: 196, y: 508 });

entry = mount();
assert.equal(entry.position.value, 'left:196px;top:508px;right:auto;bottom:auto;');
entry.onEntryTouchStart(touch(210, 520));
entry.onEntryTouchMove(touch(213, 522)); // A 3px touch is still a tap.
entry.onEntryTouchEnd(touch(213, 522));
entry.onEntryTap();
assert.equal(entry.opened.value, true);
assert.deepEqual(JSON.parse(JSON.stringify(storage.get(key))), { x: 196, y: 508 });

entry = mount(200, 240, 20);
assert.equal(entry.position.value, 'left:132px;top:68px;right:auto;bottom:auto;');
entry.onEntryTouchStart(touch(140, 75));
entry.onEntryTouchMove(touch(-500, -500));
entry.onEntryTouchEnd(touch(-500, -500));
assert.deepEqual(JSON.parse(JSON.stringify(storage.get(key))), { x: 8, y: 8 });
console.log('Mobile AI entry drag: PASS');
