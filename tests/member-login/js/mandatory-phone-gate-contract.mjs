import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');
const gate = read('前端代码/uniapp/utils/memberPhoneGate.js');
const app = read('前端代码/uniapp/App.vue');
const login = read('前端代码/uniapp/pages/users/wechat_login/index.vue');

// 直接执行生产门禁判定，覆盖旧版 Vuex 把 USER_INFO 恢复为 JSON 字符串的冷启动场景。
const authorizationSource = gate.match(/export function hasMemberPhoneAuthorization\(store\) \{([\s\S]*?)\n\}/);
const createAuthorizationCheck = cache => new Function(
	'Cache',
	'LOGIN_STATUS',
	'USER_INFO',
	`return function hasMemberPhoneAuthorization(store) {${authorizationSource[1]}\n}`
)(cache, 'token', 'user');
const cacheValues = { token: 'TOKEN', user: { phone: '15900000000' } };
const authorizationCheck = createAuthorizationCheck({ get: key => cacheValues[key] });
const legacyStringStore = { getters: { isLogin: true }, state: { app: { userInfo: JSON.stringify(cacheValues.user) } } };
const unboundStore = { getters: { isLogin: true }, state: { app: { userInfo: { phone: '' } } } };

const checks = [
	['PHONE-GATE-ROUTES-01', gate.includes("'navigateBack'") && gate.includes("'switchTab'") && gate.includes('redirectToMemberLogin')],
	['PHONE-GATE-IDENTITY-02', Boolean(authorizationSource) && authorizationCheck(legacyStringStore) && !authorizationCheck(unboundStore) && gate.includes("'pages/users/wechat_login/index'")],
	['PHONE-GATE-NO-SMS-BYPASS-03', !gate.includes("'pages/users/binding_phone/index'") && !login.includes('>手机号登录</button>')],
	['PHONE-GATE-BOOTSTRAP-04', app.includes('installMemberPhoneGate(this.$store)') && app.includes('enforceMemberPhoneGate(this.$store)')],
	['PHONE-GATE-DENIAL-05', !login.includes('>取消登录</view>') && login.includes('请先授权手机号')]
];

let failed = 0;
for (const [id, passed] of checks) {
	if (passed) {
		console.log(`PASS ${id}`);
	} else {
		failed += 1;
		console.error(`FAIL ${id}`);
	}
}
process.exit(failed === 0 ? 0 : 1);
