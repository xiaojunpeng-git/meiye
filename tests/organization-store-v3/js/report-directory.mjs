import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

// Compile the actual Vue 2 SFC and exercise its authorized-navigation boundary.
const admin = new URL('../../../前端代码/admin/', import.meta.url);
const require = createRequire(new URL('package.json', admin));
const compiler = require('vue-template-compiler');
const source = readFileSync(new URL('src/pages/report/data/report_directory.vue', admin), 'utf8');
const sfc = compiler.parseComponent(source);
assert.deepEqual(compiler.compile(sfc.template.content).errors, []);
const component = Function(sfc.script.content.replace('export default', 'return'))();
const entries = (menus, path = '/admin/report/other-reports') => component.computed.entries.call({
  $route: { path }, $store: { state: { admin: { menus: { menusName: menus } } } }
});
assert.deepEqual(entries([]), []);
assert.deepEqual(entries([{ path: '/report/other-reports' }]), []);
const menus = [{ path: '/report', children: [
  { path: '/report/other-reports', children: [
    { id: 1, path: '/report/other-reports/allowed', title: 'Allowed' },
    { id: 2, path: '/report/other-reports/detail', title: 'Hidden', auth: ['hidden'] }
  ] },
  { path: '/report/sale', children: [{ id: 3, path: '/report/report_sale', title: 'Sales' }] }
] }];
assert.deepEqual(entries(menus), [{ id: 1, path: '/admin/report/other-reports/allowed', title: 'Allowed' }]);
assert.equal(entries(menus, '/admin/report/sale')[0].id, 3);
assert.deepEqual(entries(menus, '/admin/report/missing'), []);
console.log('PASS real Vue template, authorized children only, hidden actions excluded, empty directories and no unrelated fallback');
