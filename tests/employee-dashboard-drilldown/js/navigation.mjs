import fs from 'node:fs';
import vm from 'node:vm';
import { serialize, deserialize } from 'node:v8';
import assert from 'node:assert/strict';
// Execute the real view handlers: cloned postMessage payload, submitted filters, and trusted iframe boundary.
const view=fs.readFileSync('前端代码/cashier-v3/src/views/EmployeeDashboardView.vue','utf8');
const handler=view.slice(view.indexOf('function canDrill'),view.indexOf('function selectScope'));
const calls=[];const data={value:{can_view_people:true,scope:{date_range:{start:'2026-10-01',end:'2026-10-31'},store_ids:[3],role:'美容师'}}};
const context={data,loading:{value:false},URL,document:{referrer:'http://127.0.0.1:18081/admin/report/employee-dashboard'},window:{parent:{postMessage:(msg,origin)=>calls.push({msg:deserialize(serialize(msg)),origin})}}};
vm.createContext(context);vm.runInContext(handler,context);
const item={label:'高中以下',value:25,drilldown:new Proxy({kind:'education',bucket:'4',role:'美容师',store_ids:'3'}, {})};
context.openPeople(item);assert.equal(calls.length,1);assert.equal(calls[0].origin,'http://127.0.0.1:18081');assert.equal(calls[0].msg.filters.bucket,'4');assert.equal(calls[0].msg.returnFilters.role,'美容师');
data.value.can_view_people=false;context.openPeople(item);assert.equal(calls.length,1);data.value.can_view_people=true;context.loading.value=true;context.openPeople(item);assert.equal(calls.length,1);
console.log('PASS cloned payload and server-result filters; permission and loading gates');
function component(path){const s=fs.readFileSync(path,'utf8').split('<script>')[1].split('</script>')[0].replace(/^import .*;\s*$/gm,'').replace('export default','module.exports =');const c={module:{exports:{}},URL,URLSearchParams};vm.createContext(c);vm.runInContext(s,c);return c.module.exports;}
const wrapper=component('前端代码/admin/src/pages/report/data/employee_dashboard.vue');const source={};const pushed=[];const w={$refs:{dashboardFrame:{contentWindow:source}},dashboardUrl:'http://127.0.0.1:18091/view_cashier_v3/', $router:{push:x=>pushed.push(x)}};
for(const event of [{source:{},origin:'http://127.0.0.1:18091'},{source,origin:'https://evil.invalid'}])wrapper.methods.openPeople.call(w,{...event,data:calls[0].msg});assert.equal(pushed.length,0);wrapper.methods.openPeople.call(w,{source,origin:'http://127.0.0.1:18091',data:calls[0].msg});assert.equal(pushed[0].path,'/admin/setting/staff/index');console.log('PASS rejects foreign frame/origin; fixed personnel route');
const details=component('前端代码/admin/src/pages/setting/staff/EmployeeDashboardPeople.vue');const args=details.methods.params.call({submittedKeyword:'已查询',keyword:'未查询',filters:{kind:'age',bucket:'2',store_ids:'3',_employee_id:999}},2,20);assert.equal(args.keyword,'已查询');assert.equal(args.page,2);assert.equal(args._employee_id,undefined);assert.equal(args.bucket,'2');console.log('PASS pagination preserves submitted query and excludes injected identity');
