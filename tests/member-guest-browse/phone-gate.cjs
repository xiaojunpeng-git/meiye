// Use the production route guard with a guest session; no network or member data is modified.
const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, '前端代码/uniapp/utils/memberPhoneGate.js'), 'utf8')
  .replace(/^import .*;\r?\n/gm, '').replace(/export /g, '');
let pages = [];
const redirects = [];
const interceptors = {};
const context = {
  LOGIN_STATUS: 'token', USER_INFO: 'user', Cache: { get: () => '' },
  getMemberSessionRecovery: () => null,
  getCurrentPages: () => pages,
  uni: {
    addInterceptor: (method, handler) => { interceptors[method] = handler; },
    reLaunch: ({ url, complete }) => { redirects.push(url); if (complete) complete(); }
  }
};
vm.createContext(context);
vm.runInContext(source, context);
const guest = { getters: { isLogin: false }, state: { app: { userInfo: {} } } };
context.installMemberPhoneGate(guest);

// A normal WeChat launch uses the first configured page, before the home tab exists.
const pagesConfig = fs.readFileSync(path.join(root, '前端代码/uniapp/pages.json'), 'utf8');
const launchRoute = pagesConfig.match(/"pages"\s*:\s*\[[\s\S]*?"path"\s*:\s*"([^"]+)"/)[1];
assert(context.enforceMemberPhoneGate(guest, launchRoute), 'fresh guest can open the configured launch page');
assert(interceptors.switchTab.invoke({ url: '/pages/index/index' }), 'launch page can continue to home');

for (const route of ['pages/index/index', 'pages/goods_cate/goods_cate', 'pages/store_list/index', 'pages/store/home/index', 'pages/goods_details/index']) {
  assert(context.enforceMemberPhoneGate(guest, route), `guest landing: ${route}`);
  assert(interceptors.navigateTo.invoke({ url: `/${route}?id=1` }), `guest navigation: ${route}`);
}
assert.strictEqual(redirects.length, 0, 'public browsing cannot force login');

pages = [{ route: 'pages/index/index' }, { route: 'pages/goods_details/index' }];
assert(interceptors.navigateBack.invoke({ delta: 1 }), 'returning to public home stays available');
assert.strictEqual(interceptors.switchTab.invoke({ url: '/pages/user/index' }), false, 'personal center requires phone');
assert.deepStrictEqual(redirects, ['/pages/users/wechat_login/index']);

pages = [];
assert.strictEqual(context.enforceMemberPhoneGate(guest, 'pages/order_addcart/order_addcart'), false, 'protected deep link requires phone');
assert.strictEqual(redirects.length, 2);

const login = fs.readFileSync(path.join(root, '前端代码/uniapp/pages/users/wechat_login/index.vue'), 'utf8');
assert(login.includes('先浏览首页') && login.includes("uni.switchTab({ url: '/pages/index/index' })"), 'guest can leave login for public home');
console.log('PASS guest home, catalogue, store, product, back navigation and protected routes');
