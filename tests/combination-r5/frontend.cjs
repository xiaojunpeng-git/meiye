// 真实Vue2组件的查询/导出/竞态及Excel内容验证；无浏览器登录、支付或真实退款。
const fs=require('fs'),path=require('path'),vm=require('vm'),assert=require('assert');
const root=path.resolve(__dirname,'../..'),base=path.join(root,'前端代码/admin');
const Vue=require(path.join(base,'node_modules/vue/dist/vue.common.js'));
const compiler=require(path.join(base,'node_modules/vue-template-compiler'));
const XLSX=require(path.join(base,'node_modules/xlsx-js-style'));
const requests=[],exportedReports=[],checks=[];
function check(ok,name){assert(ok,name);checks.push(name);}
function source(file){return compiler.parseComponent(fs.readFileSync(path.join(base,file),'utf8'));}
const s=source('src/pages/marketing/storeCombination/ActivityFlow.vue');
const context={component:null,OrganizationStoreScopePicker:{},ReportFieldFilters:{},formatBusinessMoney:v=>String(v),exportBusinessReport:data=>exportedReports.push(data),
  guideRequired:()=>checks.push('missing refund reason uses shared focused guidance'),clearRequiredGuidance:()=>{},
  combinationActivityFlowApi:(id,params)=>new Promise((resolve,reject)=>requests.push({id,params,resolve,reject})),combinationActivityFlowRefundApi:()=>{throw Error('No financial call allowed');}};
vm.createContext(context);vm.runInContext(s.script.content.replace(/^import[\s\S]*?;\s*$/gm,'').replace('export default','component='),context);
const component=context.component;component.created=undefined;const app=new Vue({...component,propsData:{activityId:4}});app.$Message={error:()=>{},info:()=>{}};
const columns=[{key:'member_name',title:'会员',type:'text',operators:['equal'],width:130},{key:'paid_amount',title:'付款金额',type:'amount',operators:['equal','between'],width:100}];
const response={list:[{order_id_key:30,member_name:'示例会员',paid_amount:'1.00'}],count:25,page:1,summary:{paid_amount:'2500.00'},table_layout:{fixed:true}};
(async()=>{
  check(compiler.compile(s.template.content).errors.length===0,'flow template compiles');
  for(const f of ['src/pages/marketing/storeCombination/statistics.vue','src/components/query/ReportFieldFilters.vue'])check(!compiler.compile(source(f).template.content).errors.length,'shared filter and original tabs compile: '+f);
  const stats=source('src/pages/marketing/storeCombination/statistics.vue');const statsContext={component:null,cardsData:{},ActivityFlow:{},Setting:{roterPre:'/admin'},mapState:()=>({})};vm.createContext(statsContext);vm.runInContext(stats.script.content.replace(/^import[\s\S]*?;\s*$/gm,'').replace('export default','component='),statsContext);statsContext.component.created=undefined;const statsApp=new Vue(statsContext.component);let calls=0;statsApp.getList=()=>calls++;statsApp.currentTab='flow';statsApp.onClickTab();check(statsApp.type==='flow'&&calls===0,'new flow tab does not invoke old order or participant query');statsApp.currentTab='0';statsApp.onClickTab();check(statsApp.type===0&&calls===1,'returning to participant tab restores numeric type and original columns');statsApp.currentTab='1';statsApp.onClickTab();check(statsApp.type===1&&calls===2,'activity order tab retains original query path');
  app.metadata=columns;app.draft.filters=[{fieldKey:'member_name',operator:'equal',value:'已查询会员'}];app.search();const initial=requests.pop();initial.resolve({data:response});await new Promise(setImmediate);
  check(app.displayRows[0].is_summary&&app.displayRows[0].paid_amount==='2500.00'&&app.displayRows[1].paid_amount==='1.00','first row uses full backend total rather than visible row sum');
  app.draft.filters[0].value='尚未查询的输入';const paging=app.load(2);const second=requests.pop();check(JSON.parse(second.params.filters)[0].value==='已查询会员'&&second.params.page===2,'paging freezes submitted filters instead of current draft');second.resolve({data:{...response,page:2}});await paging;
  const exporting=app.exportRows();const exp=requests.pop();check(exp.params.view==='export'&&JSON.parse(exp.params.filters)[0].value==='已查询会员','export uses same committed filters');const payload={header:['会员','付款金额'],filekey:['member_name','paid_amount'],filename:'活动流水',export:[{member_name:'合计金额',paid_amount:'2500.00'}],sources:{付款金额:'原付款金额'}};exp.resolve({data:payload});await exporting;check(exportedReports[0]===payload,'export passes backend complete result without client arithmetic');
  const old=app.load(1),newer=app.load(2);const fresh=requests.pop(),stale=requests.pop();fresh.resolve({data:{...response,page:2,summary:{paid_amount:'300.00'}}});await newer;stale.resolve({data:response});await old;check(app.page===2&&app.summary.paid_amount==='300.00','late older request cannot overwrite current result');
  const fail=app.load(1);requests.pop().reject({msg:'模拟失败'});await fail;check(app.summary===null&&app.displayRows.length===0&&app.error==='模拟失败','error clears stale money instead of displaying misleading aggregate');
  const empty=app.load(1);requests.pop().resolve({data:{...response,list:[],count:0,summary:{paid_amount:'0.00'}}});await empty;check(app.displayRows.length===1&&app.totalPages===1&&app.displayRows[0].paid_amount==='0.00','empty query retains zero summary and minimum one page');
  await app.submitRefund();check(!app.refundBusy,'empty reason does not call financial API');
  const delayed=app.load(1);const pending=requests.pop();app.$destroy();pending.resolve({data:response});await delayed;check(app.total===0,'destroyed tab ignores pending responses');
  const exportSource=fs.readFileSync(path.join(base,'src/utils/exportBusinessReport.js'),'utf8');const exportContext={XLSX,saveAs:()=>{},Blob:require('buffer').Blob,createBusinessReportWorkbook:null};vm.createContext(exportContext);vm.runInContext(exportSource.replace(/^import .*?;\s*$/gm,'').replace('export function createBusinessReportWorkbook','function createBusinessReportWorkbook').replace('export default function','function'),exportContext);
  const report={header:['会员','手机','付款金额','原支付交易号'],filekey:['member_name','phone','paid_amount','transaction'],filename:'活动流水',sources:{手机:'原订单手机'},export:[{member_name:'合计金额',paid_amount:'123.45'},{member_name:'示例',phone:'01340000000',paid_amount:'123.45',transaction:'4500000572202610318714726789'}]};
  const workbook=exportContext.createBusinessReportWorkbook(report),bytes=XLSX.write(workbook,{bookType:'xlsx',type:'buffer'}),read=XLSX.read(bytes,{type:'buffer'});const rows=XLSX.utils.sheet_to_json(read.Sheets['活动流水'],{header:1});
  check(rows[1][0]==='合计金额'&&rows[1][2]==='123.45','export keeps backend first-row summary with cents precision');check(rows[2][1]==='01340000000'&&rows[2][3]==='4500000572202610318714726789','phone leading zero and long transaction ID remain exact text');check(read.SheetNames.includes('列名取值来源'),'workbook includes business field sources');
  console.log(JSON.stringify({result:'PASS',count:checks.length,checks},null,2));
})().catch(e=>{console.error(e);process.exitCode=1;});
