import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const currentDir = path.dirname(fileURLToPath(import.meta.url));
const repositoryRoot = path.resolve(currentDir, '../../..');
const adminRoot = path.join(repositoryRoot, '前端代码/admin/src');
const read = relativePath => fs.readFileSync(path.join(adminRoot, relativePath), 'utf8');

const disambiguation = read('libs/menuRouteDisambiguation.js');
const menus = read('store/modules/admin/modules/menus.js');
const system = read('libs/system/index.js');
const layout = read('layouts/basic-layout/index.vue');
const main = read('main.js');
const reportRouter = read('router/modules/report.js');

assert.match(disambiguation, /menu_entry/, '重复入口必须使用稳定的菜单入口标识');
assert.match(disambiguation, /counts\[key\] < 2/, '唯一菜单路径不得被改写');
assert.match(menus, /disambiguateDuplicateMenuPaths/, '菜单刷新和缓存必须启用重复路径归一化');
assert.match(system, /function isMenuPathMatch/, '顶部菜单和侧栏必须使用统一路径匹配');
assert.match(layout, /return isMenuPathMatch\(to, menuPath\)/, '侧栏入口匹配必须识别入口标识');
assert.match(main, /to\.query && to\.query\.menu_entry \? to\.fullPath : path/, '入口标识路由必须保持正确高亮');
assert.match(reportRouter, /path: 'member-management-dashboard-customer'/, '会员看板兼容入口必须注册');
assert.match(reportRouter, /member_management_dashboard_customer[\s\S]*admin-report-member-management-dashboard/, '兼容入口必须复用会员看板权限');

console.log('platform duplicate menu entry contract: PASS');
