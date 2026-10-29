// 验证加载失败、重试和过期请求不会把既有岗位权限覆盖为空；不连接业务数据库。
const fs = require('fs'), vm = require('vm'), assert = require('assert');
const source = fs.readFileSync('前端代码/admin/src/pages/store/region/workspace/components/JobPositionFormModal.vue', 'utf8');
let mode = 'fail';
const menus = {platform_menus:[{id:1}],store_v3_menus:[{id:33022}],mobile_menus:[{id:2}]};
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/import[\s\S]*?;/g, '').replace('export default', 'module.exports =');
const context = {module:{exports:{}},FourChannelPermissionEditor:{},getJobPositionMenus:()=> mode==='fail'?Promise.reject({msg:'目录不完整'}):Promise.resolve({data:mode==='empty'?{}:menus}),getJobPositionDetail:()=>Promise.resolve({data:{position:{id:1,name:'测试岗位',use_store:1},store_v3_rules_ids:[33022]}})};
vm.runInNewContext(script,context);
const component = context.module.exports;
const state = Object.assign(component.data(), {positionId:1,submitting:false,$refs:{},$emit:()=>{throw Error('加载失败不允许提交')}});
Object.entries(component.methods).forEach(([name,fn])=>state[name]=fn.bind(state));
const tick=()=>new Promise(resolve=>setImmediate(resolve));
(async()=>{
 state.open();assert(state.loading);state.submit();await tick();assert.equal(state.loadError,'目录不完整');assert(!state.loading);state.submit();
 mode='empty';state.open();await tick();assert.equal(state.loadError,'权限目录不完整，请重新加载');
 mode='ok';state.open();await tick();assert(!state.loading);assert.equal(state.loadError,'');assert.equal(state.ruleIds.store_v3[0],33022);assert.equal(state.menus.store_v3_menus[0].id,33022);
 state.menus={sentinel:true};await state.loadMenus(state.loadSequence-1);assert(state.menus.sentinel);
 console.log('PASS: failure/save guard, incomplete response, retry, existing grants, stale request');
})().catch(e=>{console.error(e);process.exit(1)});
