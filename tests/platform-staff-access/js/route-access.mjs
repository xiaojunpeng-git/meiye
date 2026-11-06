import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
// Execute the actual navigation guard with controllable server responses, including revoked cached grants.
const source=fs.readFileSync('前端代码/admin/src/router/index.js','utf8');
const fn=source.slice(source.indexOf('async function handleAuthenticatedRoute'),source.indexOf('// 处理公共路由'));
const meta={auth:['setting-staff-index'],refreshStaffAccess:true};
async function run({cached=[],fresh=[],failed=false,root=false}) {
 let refreshes=0,result='not-called';
 const sandbox={Vue:{prototype:{__getToken:()=> 'local-test'}},resolveRouteAccessMeta:()=>meta,
 isPlatformRootAdmin:()=>root,includeArray:(required,access)=>required.some(x=>access.includes(x)),
 store:{state:{admin:{user:{info:{level:root?0:1}}}},dispatch:async name=>{if(name==='admin/menus/getMenusNavList'){refreshes++;if(failed)throw Error('offline');return {data:{unique:fresh}};}return {};}}};
 vm.createContext(sandbox);vm.runInContext(fn+';this.guard=handleAuthenticatedRoute;',sandbox);
 await sandbox.guard({},r=>result=r,[],cached);return {result,refreshes};
}
let r=await run({cached:meta.auth});assert.equal(r.result.name,'403');assert.equal(r.refreshes,1);
r=await run({fresh:meta.auth});assert.equal(r.result,undefined);
r=await run({cached:meta.auth,failed:true});assert.equal(r.result.name,'403');
r=await run({root:true});assert.equal(r.result,undefined);
const routes=fs.readFileSync('前端代码/admin/src/router/modules/setting.js','utf8');assert.match(routes,/auth: \['setting-staff-index'\],[\s\S]{0,120}refreshStaffAccess: true/);
console.log('PASS actual personnel route: revoked cached grant denied, grant accepted, failed refresh denied, root retained');

const menuSource=fs.readFileSync('前端代码/admin/src/libs/organizationWorkspaceMenu.js','utf8').replace('export function','function');
const context={};vm.createContext(context);vm.runInContext(menuSource+';this.normalize=normalizeOrganizationWorkspaceMenu;',context);
const tree=context.normalize([{title:'组织管理',children:[{unique_auth:'setting-staff-index',title:'店员列表',path:'/admin/setting/staff/index'},{title:'供应商管理',children:[{path:'/admin/supplier/menu/list'},{path:'/admin/supplier/config'}]}]}],'/admin');
assert.equal(tree[0].children[0].title,'人员管理');assert.equal(tree[0].children[1].title,'供应商管理');assert.equal(tree[0].children[1].children.length,1);
assert.equal(context.normalize([], '/admin').length,0);
console.log('PASS canonical personnel label; no supplier masquerade; supplier settings preserved; no implicit grant');
