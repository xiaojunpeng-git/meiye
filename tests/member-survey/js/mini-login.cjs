// Run the actual MP login branch: survey return links must reach phone authorization and survive login.
const fs = require('fs'), path = require('path'), vm = require('vm');
const root = path.resolve(__dirname, '../../..');
const lines = fs.readFileSync(path.join(root, '前端代码/uniapp/libs/login.js'), 'utf8').split('\n');
const enabled = [true], compiled = [];
for (const line of lines) {
  const directive = line.match(/^\s*\/\/ #(ifdef|ifndef) (.+)$/);
  if (directive) {
    const matches = directive[2].split('||').some(p => ['MP', 'MP-WEIXIN'].includes(p.trim()));
    enabled.push(enabled[enabled.length - 1] && (directive[1] === 'ifdef' ? matches : !matches));
  } else if (/^\s*\/\/ #endif/.test(line)) enabled.pop();
  else if (enabled[enabled.length - 1]) compiled.push(line);
}
const source = compiled.join('\n').replace(/import[\s\S]*?from\s+['"][^'"]+['"];?/g, '').replace(/export /g, '');
const cached = { BASIC_CONFIG: { wechat_auth_switch: false } }, navigated = [], checks = [];
const context = { store: { commit() {} }, Cache: { get: k => cached[k], set: (k, v) => { cached[k] = v; } }, Debounce: fn => fn,
  getCurrentPages: () => [{ route: 'pages/users/survey/index' }], uni: { getStorageSync() {}, navigateTo: o => navigated.push(o.url) } };
vm.createContext(context); vm.runInContext(source, context);
function check(ok, name) { if (!ok) throw Error(name); checks.push(name); }
const back = '/pages/users/survey/index?entry=' + 'a'.repeat(48);
context._toLogin(undefined, undefined, back);
check(navigated.pop() === '/pages/users/wechat_login/index', '普通授权开关关闭时问卷仍进入手机号授权页');
check(cached.login_back_url === back, '授权后返回地址完整保留任务token');
context._toLogin();
check(navigated.pop() === '/pages/users/binding_phone/index?pageType=0', '非问卷原登录分支保持不变');
context._toLogin(undefined, undefined, 'https://example.invalid/');
check(navigated.pop() === '/pages/users/binding_phone/index?pageType=0' && cached.login_back_url === 'pages/users/survey/index', '外站返回地址不能进入深链例外');
fs.writeFileSync(path.join(root, 'tests/customer-010/evidence/round12-survey-miniprogram/mini-login.json'), JSON.stringify({ passed: true, checks }, null, 2));
console.log('MINI LOGIN PASS', checks.length);
