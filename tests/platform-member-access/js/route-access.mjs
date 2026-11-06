import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
// Execute the actual navigation guard with controllable server responses, including revoked cached grants.
const source=fs.readFileSync('前端代码/admin/src/router/index.js','utf8');
const fn=source.slice(source.indexOf('async function handleAuthenticatedRoute'),source.indexOf('// 处理公共路由'));
const meta={auth:['admin-user-user-index'],refreshMemberAccess:true};
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
const routes=fs.readFileSync('前端代码/admin/src/router/modules/user.js','utf8');assert.match(routes,/auth: \['admin-user-user-index'\],[\s\S]{0,120}refreshMemberAccess: true/);
console.log('PASS actual member route: revoked cached grant denied, grant accepted, failed refresh denied, root retained');
