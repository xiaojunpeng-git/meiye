import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

// Execute the real login/session and menu helpers with isolated browser/store adapters.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const read = name => fs.readFileSync(path.join(root, '前端代码/admin/src', name), 'utf8');
const storage = new Map([['unique_auth', 'old-admin-permission']]);
const cookies = new Map();
const util = { cookies: { set: (key, value) => cookies.set(key, value) } };
const context = vm.createContext({
  Setting: { roterPre: '/admin' }, util, URLSearchParams,
  cloneDeep: data => JSON.parse(JSON.stringify(data)),
  localStorage: { setItem: (key, value) => storage.set(key, String(value)) }
});
const system = read('libs/system/index.js').replace(/^import .*;$/gm, '').replace(/^export \{.*\};$/gm, '');
vm.runInContext(system, context);
const menuHelpers = read('libs/util.js');
vm.runInContext(menuHelpers.slice(menuHelpers.indexOf('util.makeMenu ='), menuHelpers.indexOf('/**\n * 平台端免密')), context);
vm.runInContext(read('libs/moheAiManagementMenu.js').replace(/^export /gm, ''), context);
vm.runInContext(read('libs/platformLogin.js').replace(/^import .*;$/gm, '').replace('export async function', 'async function'), context);
const plain = data => JSON.parse(JSON.stringify(data));

async function login(menus, access, redirect = '/admin/home/') {
  const state = new Map();
  let destination;
  const db = { set: () => ({ write() {} }) };
  const instance = {
    $store: {
      commit: (key, data) => state.set(key, plain(data)),
      dispatch: async (key, data) => {
        if (key === 'admin/db/database') return db;
        state.set(key, plain(data === undefined ? null : data));
      }
    },
    $route: { query: { redirect } },
    $router: { replace: data => { destination = data.path; } }
  };
  await context.finishPlatformLogin(instance, {
    menus, unique_auth: access, token: 'synthetic-local-session', expires_time: 9999999999,
    user_info: { id: 9, account: 'synthetic', admin_type: 0 },
    logo: '', logo_square: '', version: '', newOrderAudioLink: ''
  });
  return { state, destination };
}

for (const access of [[], ['inventory-access']]) {
  const result = await login([], access);
  assert.equal(result.destination, '/admin/workspace');
  for (const key of ['admin/menus/getmenusNav', 'admin/menu/setHeader', 'admin/menu/setSider', 'admin/menu/setOpenNames']) {
    assert.deepEqual(result.state.get(key), []);
  }
  assert.equal(storage.get('unique_auth'), access.join(','));
  assert.deepEqual(result.state.get('admin/user/set').access, access);
  assert.equal(cookies.get('token'), 'synthetic-local-session');
  assert.equal(result.state.get('admin/menu/setMoheAiVerifiedToken'), null);
}
assert.equal(util.resolveDefaultMenuPath([]), '/admin/workspace');
const menu = [{ path: '/stock', header: 'product', children: [{ path: '/stock/transfer', title: '调拨管理' }] }];
const normal = await login(menu, ['transfer'], '');
assert.equal(normal.destination, '/admin/stock/transfer');
assert.equal(normal.state.get('admin/menu/setSider')[0].path, '/admin/stock/transfer');
assert.equal((await login([{ path: '/stock', header: 'product' }], [], '/admin/stock/transfer')).destination, '/admin/stock/transfer');
// Empty arrays overwrite the previous account rather than inheriting its navigation.
assert.equal((await login([], [])).state.get('admin/menu/setHeader').length, 0);
assert.equal(storage.get('unique_auth'), '');
// The freshly authenticated response may show the special entry immediately;
// switching to an account whose response omits it must clear the marker.
const aiMenu = [{ path: '/setting', header: 'setting', children: [{ path: '/setting/mohe-ai/feedback', unique_auth: 'setting-mohe-ai' }] }];
assert.equal((await login(aiMenu, ['setting-mohe-ai'])).state.get('admin/menu/setMoheAiVerifiedToken'), 'synthetic-local-session');
assert.equal((await login([], [])).state.get('admin/menu/setMoheAiVerifiedToken'), null);

const loginPage = read('pages/account/login/index.vue');
assert.equal((loginPage.match(/finishPlatformLogin\(this, res.data\)/g) || []).length, 3);
assert.ok(!loginPage.includes('unique_auth.length'));
assert.ok(!loginPage.includes('filterMenuSider[0]'));
const routes = read('router/routes.js');
assert.ok(routes.includes("name: 'platform_workspace'") && routes.includes("meta: { auth: true, title: '' }"));
const guard = read('router/index.js');
vm.runInContext(guard.slice(guard.indexOf('function handlePublicRoute('), guard.indexOf('router.afterEach')), Object.assign(context, {
  Vue: { prototype: { __getToken: () => 'synthetic-local-session' } },
  store: { state: { admin: { menus: { menusName: [] } } }, dispatch() { throw new Error('Session must not be cleared'); } }
}));
let publicDecision = 'not-called';
context.handlePublicRoute({ path: '/admin/403' }, value => { publicDecision = value; });
assert.equal(publicDecision, undefined);
assert.equal(context.includeArray(['business-read'], []), false);
assert.equal(context.includeArray(true, []), true);
const menusStore = read('store/modules/admin/modules/menus.js');
assert.ok(menusStore.includes("if (Array.isArray(res.data.unique))"));
assert.ok(menusStore.includes("localStorage.setItem('unique_auth', access)"));
assert.ok(menusStore.includes("await dispatch('admin/user/set'"));
assert.ok(guard.includes("const refreshed = await store.dispatch('admin/menus/getMenusNavList')"));
assert.ok(guard.includes('isPermission = includeArray(meta.auth, freshAccess)'));
console.log('PASS empty menus + empty permissions, granted permissions + empty menus, current job-permission refresh, normal navigation, stale-state clearing, 403 session retention, three login methods');
