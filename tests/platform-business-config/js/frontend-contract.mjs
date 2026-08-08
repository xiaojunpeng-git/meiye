import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const currentDir = path.dirname(fileURLToPath(import.meta.url));
const repositoryRoot = path.resolve(currentDir, '../../..');
const adminRoot = path.join(repositoryRoot, '前端代码/admin/src');

const read = relativePath => fs.readFileSync(path.join(adminRoot, relativePath), 'utf8');

const settingRouter = read('router/modules/setting.js');
const productRouter = read('router/modules/product.js');
const api = read('api/productBusinessConfig.js');
const sourcePage = read('pages/product/businessConfig/source.vue');
const accountingPage = read('pages/product/businessConfig/accounting.vue');
const menuCache = read('store/modules/admin/modules/menus.js');
const menuState = read('store/modules/admin/modules/menu.js');
const menuNormalization = read('libs/productBusinessConfigMenu.js');

assert.match(settingRouter, /path: 'shop\/business-source'/, '商品设置路由应注册来源设置');
assert.match(settingRouter, /auth: \['setting-shop-business-source'\]/, '来源设置路由必须匹配页面菜单权限');
assert.match(settingRouter, /path: 'shop\/accounting'/, '商品设置路由应注册记账设置');
assert.match(settingRouter, /auth: \['setting-shop-accounting'\]/, '记账设置路由必须匹配页面菜单权限');
assert.doesNotMatch(productRouter, /business_source_setting|accounting_setting/, '来源和记账设置不得错误挂在商品资料路由下');
assert.match(menuNormalization, /来源设置: 'setting\/shop\/business-source'/, '来源设置旧菜单必须归一到正式页面路由');
assert.match(menuNormalization, /记账设置: 'setting\/shop\/accounting'/, '记账设置旧菜单必须归一到正式页面路由');
assert.match(menuNormalization, /normalized\.path = `\$\{prefix\}\/\$\{route\}`/, '菜单路径必须使用当前终端路由前缀');
assert.match(menuCache, /normalizeProductBusinessConfigMenu/, '菜单缓存和刷新数据都必须执行来源、记账入口归一化');
assert.match(menuState, /normalizeProductBusinessConfigMenu/, '动态侧栏也必须执行来源、记账入口归一化');

assert.match(api, /url: 'product\/business-config\/sources'[\s\S]*method: 'get'/, '来源设置应读取正式列表接口');
assert.match(api, /url: 'product\/business-config\/sources'[\s\S]*method: 'post'/, '新增来源应使用正式写接口');
assert.match(api, /sources\/\$\{id\}`,[\s\S]*method: 'put'/, '编辑和停用来源应使用 PUT 写接口');
assert.match(api, /accounting-methods'[\s\S]*method: 'get'/, '记账设置应读取固定方式目录');
assert.match(api, /accounting-methods\/\$\{code\}`,[\s\S]*method: 'put'/, '记账方式应按稳定代码更新');
assert.match(api, /accounting-methods\/restore-defaults'[\s\S]*method: 'post'/, '恢复默认名称应使用显式命令接口');
assert.match(api, /createBusinessConfigIdempotencyKey/, '平台配置的每次写请求都应生成唯一幂等键');

assert.match(sourcePage, /availableParents\(\)[\s\S]*item\.parentId === 0/, '上级来源选项只能来自一级来源');
assert.match(sourcePage, /normalizeSourceTree/, '来源列表应兼容权威接口的树形或平铺返回');
assert.match(sourcePage, /row\.level === 1[\s\S]*新增二级来源/, '只有一级来源可以新增二级来源');
assert.match(sourcePage, /:disabled="Boolean\(editingId\)"/, '已建立来源不得在前端变更层级或上级');
assert.match(sourcePage, /canRequireSecondary/, '至少存在启用的二级来源后才能配置二级必选');
assert.match(sourcePage, /parentId: this\.form\.parentId/, '来源保存必须提交权威上级 ID');
assert.match(sourcePage, /requireSecondary/, '来源配置必须支持二级来源必选规则');
assert.match(sourcePage, /version: numberValue\(item\.version/, '来源读取必须保留服务端资源版本');
assert.match(sourcePage, /expectedVersion: row\.version/, '来源状态更新必须提交预期版本');
assert.match(sourcePage, /payload\.expectedVersion = this\.form\.version/, '来源编辑必须提交预期版本');
assert.match(sourcePage, /SOURCE-CREATE/, '来源新增必须提交独立幂等键');
assert.doesNotMatch(sourcePage, />删除</, '来源设置不得提供物理删除入口');
assert.doesNotMatch(sourcePage, /method:\s*['"]delete['"]/i, '来源设置不得调用删除接口');

assert.match(accountingPage, /<Input :value="form\.code" disabled/, '记账代码必须只读');
assert.match(accountingPage, /displayName: this\.form\.displayName/, '记账方式只提交可配置显示名称');
assert.match(accountingPage, /version: numberValue\(item\.version/, '记账方式读取必须保留服务端资源版本');
assert.match(accountingPage, /expectedVersion: this\.form\.version/, '记账方式更新必须提交预期版本');
assert.match(accountingPage, /ACCOUNTING-RESTORE/, '恢复默认名称必须提交独立幂等键');
assert.match(accountingPage, /item\.code !== 'old_card_entry'/, '七种记账收款必须排除旧卡录入');
assert.match(accountingPage, /item\.code === 'old_card_entry'/, '旧卡录入必须在独立区域展示');
assert.match(accountingPage, /现金业绩 0，不可组合收款/, '旧卡录入必须明确展示非收款业务边界');
assert.match(accountingPage, /启用状态和排序保持不变/, '恢复默认名称不得重置状态或排序');
assert.doesNotMatch(accountingPage, /code: this\.form\.code[\s\S]*displayName/, '稳定代码不得出现在更新请求体中');

console.log('platform business config frontend contract: PASS');
