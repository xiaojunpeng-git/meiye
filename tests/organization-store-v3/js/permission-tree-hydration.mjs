// Exercise the real iView Tree: opening a saved parent must not grant new leaves.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const root = new URL('../../../', import.meta.url);
const admin = new URL('前端代码/admin/', root);
const require = createRequire(new URL('package.json', admin));
const { JSDOM } = require('jsdom');
const dom = new JSDOM('<html><body></body></html>', { url: 'http://localhost/' });
global.window = dom.window;
global.document = dom.window.document;
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
const Vue = require('vue/dist/vue.common.js');
Vue.config.productionTip = false;
Vue.config.devtools = false;
Vue.component('Tree', require('view-design').Tree);

const helpers = await import('data:text/javascript;base64,' + Buffer.from(readFileSync(
  new URL('src/libs/permissionTreeSelection.js', admin), 'utf8')).toString('base64'));
const sfc = readFileSync(new URL('src/pages/store/region/workspace/components/FourChannelPermissionEditor.vue', admin), 'utf8');
const script = sfc.match(/<script>([\s\S]*?)<\/script>/)[1]
  .replace(/import\s*\{[\s\S]*?\}\s*from\s*'[^']+';/, '')
  .replace('export default', 'return');
const component = Function('markChecked', 'setChecked', 'collectIds', 'togglePermissionNode', script)(
  helpers.applyPermissionIds, helpers.setPermissionChecks, helpers.collectPermissionIds, helpers.togglePermissionNode);
Object.assign(component, Vue.compile(sfc.match(/<template>([\s\S]*?)<\/template>/)[1]));
const Editor = Vue.extend(component);
const editor = new Editor({ propsData: {
  entries: { platform: 1, store_v3: 1, mobile: 0 },
  value: { platform: [1986, 2000, 2001], store_v3: [7], mobile: [] },
  menus: {
    platform_menus: [{ id: 1986, title: 'Data', children: [
      { id: 2142, title: 'Finance', children: [{ id: 2143, title: 'Ledger' }] },
      { id: 2000, title: 'Targets', children: [{ id: 2001, title: 'Analysis' }, { id: 2531, title: 'Schemes' }] },
    ] }],
    store_v3_menus: [{ id: 7, title: 'Store' }], mobile_menus: [],
  },
} });
const tick = async () => { for (let i = 0; i < 5; i++) await Vue.nextTick(); };
editor.$mount();
document.body.appendChild(editor.$el);
editor.current = 'platform';
await tick();
const rules = () => editor.getRules().platform.slice().sort((a, b) => a - b);
const initial = [1986, 2000, 2001];
assert.deepEqual(rules(), initial);
assert.equal(editor.counts.platform, 3);
let tree = editor.$refs.tree_platform;
assert.equal(tree.checkStrictly, true);
assert.equal(tree.flatState.find(x => x.node.id === 2142).node.checked, false);
assert.equal(tree.flatState.find(x => x.node.id === 2531).node.checked, false);
assert.equal(tree.flatState.find(x => x.node.id === 1986).node.indeterminate, true);

// Search, expand, and remount must all preserve exact persisted grants.
editor.keyword = 'Finance';
await tick();
editor.expandAll(true);
await tick();
assert.deepEqual(rules(), initial);
tree = editor.$refs.tree_platform;
tree.handleCheck({ nodeKey: tree.flatState.find(x => x.node.id === 2142).node.nodeKey, checked: true });
await tick();
assert.deepEqual(rules(), [1986, 2000, 2001, 2142, 2143]);
assert.equal(editor.getRules().store_v3[0], 7);
assert.ok(!rules().includes(2531));

// Clearing a searched subtree keeps the unrelated granted target leaf.
tree = editor.$refs.tree_platform;
tree.handleCheck({ nodeKey: tree.flatState.find(x => x.node.id === 2142).node.nodeKey, checked: false });
await tick();
assert.deepEqual(rules(), [2000, 2001]);
editor.keyword = '';
await tick();
editor.selectAll(true);
await tick();
assert.deepEqual(rules(), [1986, 2000, 2001, 2142, 2143, 2531]);
editor.selectAll(false);
await tick();
assert.deepEqual(rules(), []);
editor.$destroy();
dom.window.close();
console.log('PASS real iView hydration, exact saved IDs, partial directories, search, explicit cascade, hidden grants, channel isolation, select/clear all');
