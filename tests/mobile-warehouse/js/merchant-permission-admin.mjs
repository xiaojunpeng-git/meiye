// 两个终端复用规则组件：检查模板编译、独立端点、路由切换和晚到响应，不模拟业务权限算法。
import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert';
import { createRequire } from 'node:module';
const require = createRequire('/app/package.json');
const compiler = require('vue-template-compiler');
const source = fs.readFileSync('/app/src/pages/setting/storePermissions/index.vue','utf8');
const template = source.match(/<template>([\s\S]*?)<\/template>\s*<script>/)[1];
const result = compiler.compile(template);
assert.deepStrictEqual(result.errors, []);console.log('PASS shared Vue2 template compiles');
const pending=[];const request=options=>new Promise((resolve,reject)=>pending.push({options,resolve,reject}));
const context={module:{exports:{}},request,URLSearchParams};vm.createContext(context);
// 使用真实原生菜单路径解析，测试刷新不会把新入口放入空header的全部菜单。
const menuHelpers=fs.readFileSync('/app/src/libs/system/index.js','utf8').replace(/^import .*;$/gm,'').replace(/export \{[^}]*\};/g,'');
vm.runInContext(menuHelpers,context);
const script=source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .*;$/gm,'').replace('export default','module.exports =');
vm.runInContext(script,context);const component=context.module.exports;
const page={...component.data(),$route:{meta:{}},$Message:{success(){},error(){}}};
for(const [name,method] of Object.entries(component.methods))page[name]=method.bind(page);
Object.defineProperty(page,'merchant',{get:()=>component.computed.merchant.call(page)});
const oldLoad=page.load();assert.equal(pending[0].options.url,'/setting/store-permissions');
page.$route.meta.merchantPermissionRules=true;component.watch.merchant.call(page);
assert.equal(pending[1].options.url,'/setting/merchant-permissions');
pending[1].resolve({data:{rules:[{id:402001,parent_id:0,title:'数据总览',feature_code:'warehouse',sort:0}],revision:7}});
await Promise.resolve();await Promise.resolve();
pending[0].resolve({data:{rules:[{id:99,parent_id:0,title:'旧门店目录'}],revision:1}});await oldLoad;
assert.equal(page.rules[0].id,402001);assert.equal(page.revision,7);console.log('PASS route switch loads merchant directory; stale store response ignored');
const save=page.save();assert.equal(pending[2].options.url,'/setting/merchant-permissions');assert.equal(pending[2].options.data.revision,7);
page.$route.meta.merchantPermissionRules=false;
pending[2].resolve({data:{rules:[{id:88}],revision:8}});await save;
assert.equal(page.rules[0].id,402001);console.log('PASS save response cannot install into different terminal');
assert.equal(page.saving,false);assert.equal(page.loading,false);
const commits=[];let refreshes=0;
page.$route={path:'/admin/setting/merchant-permissions',query:{},params:{},meta:{merchantPermissionRules:true}};
let nativeMenus=[{header:'setting',is_header:1,path:'/admin/setting',children:[{header:'setting',path:'/admin/setting/auth/list',children:[{path:'/admin/setting/merchant-permissions',header:'setting'}]}]}];
// 联调可传真实服务输出；转换仅复现项目util.makeMenu的admin路径前缀，不补造任何菜单。
if(process.env.NATIVE_MENU_FIXTURE){nativeMenus=JSON.parse(fs.readFileSync(process.env.NATIVE_MENU_FIXTURE,'utf8'));const prefix=nodes=>nodes.forEach(node=>{node.path='/admin'+node.path;if(node.children)prefix(node.children);});prefix(nativeMenus);}
page.$store={state:{admin:{menus:{menusName:nativeMenus}}},dispatch:async(action)=>{assert.equal(action,'admin/menus/getMenusNavList');refreshes++;},commit:(name,value)=>commits.push([name,value])};
await page.refreshMerchantNavigation();
assert.equal(refreshes,1);assert.equal(commits[0][0],'admin/menu/setHeaderName');assert.equal(commits[0][1],'setting');
assert(commits[2][1].some(node=>node.path==='/admin/setting/auth/list'));console.log('PASS fresh native menu selects settings permission sidebar without injection');
