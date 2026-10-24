/** 权限树真实选择器回归：展示分组不能写入虚拟编号，不能丢弃旧勾选或影响其他端。 */
const fs=require('fs'),path=require('path'),assert=require('assert/strict');
const root=path.resolve(__dirname,'../../..');
const load=async file=>import('data:text/javascript;base64,'+fs.readFileSync(path.join(root,file)).toString('base64'));
(async()=>{
 const g=await load('前端代码/shared/storeNavigationGroups.js');
 const s=await load('前端代码/admin/src/libs/permissionTreeSelection.js');
 const tokens=JSON.parse(fs.readFileSync('/tmp/r28-browser-sessions.json'));
 const data=await(await fetch('http://127.0.0.1:18081/adminapi/setting/store-permissions',{headers:{'Authori-zation':'Bearer '+tokens.admin}})).json();assert.equal(data.status,200);
 const rows=data.data.rules;
 const tree=parent=>rows.filter(r=>r.parent_id===parent).map(r=>({...r,children:tree(r.id)}));
 const grouped=tree(0).sort((a,b)=>a.sort-b.sort);
 assert.deepEqual(grouped.map(r=>r.title),['运营管理','订单管理','库存管理','财务管理','数据中心','基础设置']);
 const saved=[301005,301020,301021,301007,301060,301062];s.applyPermissionIds(grouped,new Set(saved));
 assert.deepEqual(s.collectPermissionIds(grouped).sort((a,b)=>a-b),[...saved].sort((a,b)=>a-b));console.log('PASS regrouped store tree retains exact saved IDs');
 s.setPermissionChecks(grouped,true);const all=s.collectPermissionIds(grouped).sort((a,b)=>a-b);
 assert.deepEqual(all,rows.filter(r=>r.grantable!==false).map(r=>r.id).sort((a,b)=>a-b));assert.ok(all.every(id=>id>0));console.log('PASS all registered functions selectable; virtual group IDs never saved');
 s.setPermissionChecks(grouped,false);s.togglePermissionNode(grouped,grouped[0].id,true);const ops=s.collectPermissionIds(grouped);assert.ok(ops.includes(301001)&&ops.includes(301008)&&ops.includes(301009));assert.ok(!ops.includes(301006)&&!ops.includes(301200)&&!ops.includes(301060));console.log('PASS category toggle grants only its actual child functions');
 const other=[{id:7,children:[{id:8}]}];s.applyPermissionIds(other,new Set([8]));assert.deepEqual(s.collectPermissionIds(other),[8]);s.setPermissionChecks(other,true);assert.deepEqual(s.collectPermissionIds(other),[7,8]);console.log('PASS existing platform/mobile selection behavior unchanged');
 console.log('R28 GROUP PERMISSIONS PASS');
})().catch(e=>{console.error(e.message);process.exitCode=1});
