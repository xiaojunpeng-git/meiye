// Execute the actual MP request error branch and login handler with inert UI/storage
// adapters. A server revocation must clear member state and enter the login route.
const fs = require('fs'), vm = require('vm'), assert = require('assert');
const root = '前端代码/uniapp/';
function mp(source) {
  let enabled = [true], output = [];
  for (const line of source.split('\n')) {
    const directive = line.match(/\/\/\s*#(ifdef|ifndef|endif)\s*(.*)/);
    if (directive) {
      if (directive[1] === 'endif') enabled.pop();
      else {
        const matches = directive[2].trim().split(/\s*\|\|\s*/).some(x => x === 'MP' || x === 'MP-WEIXIN');
        enabled.push(enabled[enabled.length - 1] && (directive[1] === 'ifdef' ? matches : !matches));
      }
    } else if (enabled[enabled.length - 1]) output.push(line);
  }
  return output.join('\n').replace(/import\s+[\s\S]*?\s+from\s+['"][^'"]+['"];?/g, '').replace(/export\s+(const|function)\s+/g, '$1 ').replace(/export\s+default\s+\w+;?/g, '').replace(/export\s*\{[^}]+\};?/g, '');
}
const actions = [], ctx = {
  HTTP_REQUEST_URL: 'https://example.invalid', HEADER: {}, TOKENNAME: 'Authori-zation',
  Debounce: fn => fn, MERCHANT_STAFF_TOKEN: 'merchant',
  store: {state: {app: {token: 'synthetic-local'}}, commit: name => actions.push(name)},
  Cache: {set() {}, get: () => ({wechat_auth_switch: true})},
  getCurrentPages: () => [{route: 'pages/user/index'}],
  uni: {getStorageSync() {}, navigateTo: x => actions.push(x.url),
    request: x => x.success({data: {status: 410001, msg: '手机号已变更，请重新授权当前手机号登录'}})},
};
vm.createContext(ctx);
vm.runInContext(mp(fs.readFileSync(root + 'libs/login.js', 'utf8')) + '\nthis.toLogin = toLogin;', ctx);
vm.runInContext(mp(fs.readFileSync(root + 'utils/request.js', 'utf8')) + '\nthis.baseRequest = baseRequest;', ctx);
(async () => {
  await ctx.baseRequest('user', 'GET', {}, {}).catch(() => {});
  assert(actions.includes('LOGOUT'));
  assert(actions.includes('/pages/users/wechat_login/index'));
  console.log('PASS actual MP request 410001 -> actual toLogin -> LOGOUT and authorization page');
})().catch(e => {console.error(e); process.exitCode = 1;});
