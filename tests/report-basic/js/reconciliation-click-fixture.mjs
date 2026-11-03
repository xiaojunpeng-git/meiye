/** Real reconciliation SFC with a synthetic API boundary; CUA clicks the real amount button and observes its parent message. */
import {createRequire} from 'node:module'
import {readFileSync,writeFileSync,mkdirSync,existsSync} from 'node:fs'
import {fileURLToPath} from 'node:url'
import path from 'node:path'
import http from 'node:http'
import {createHash} from 'node:crypto'
import assert from 'node:assert/strict'
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../../..'),src=path.join(root,'前端代码/cashier-v3/src')
const require=createRequire(path.join(root,'前端代码/cashier-v3/package.json'))
const {build}=require('esbuild'),{parse,compileScript,compileStyle}=require('@vue/compiler-sfc'),css=[]
const report=path.join(src,'components/reports/StoreReconciliationReport.vue')
const token='00000000-0000-4000-8000-000000000028'
const entry=`import Report from ${JSON.stringify(report)};import {createApp,h,ref} from 'vue';
sessionStorage.setItem('reconciliation-navigation:${token}',JSON.stringify({returnState:{query:{pageCode:'basic_store_reconciliation',start_date:'2026-10-28',end_date:'2026-10-28',reconciliation_mode:'stores',reconciliation_store_id:0,page:1,limit:20},storeIds:[],label:'全部门店',dates:{start_date:'2026-10-28',end_date:'2026-10-28'},month:'2026-10',scrollTop:0,scrollLeft:0}}));
const navigation=ref(null),request=ref(null);window.addEventListener('r9-fixture-request',e=>request.value=e.detail);window.addEventListener('message',e=>{if(e.data?.type==='mohe:reconciliation-open-orders')navigation.value=JSON.parse(sessionStorage.getItem('reconciliation-navigation:'+e.data.token)||'null')});
createApp({render:()=>h('main',[h('h1','第9轮：真实对账组件点击回归'),h('p','合成数据；不登录、不连接业务接口、不修改业务数据。'),h(Report,{platform:true}),h('pre',{'aria-label':'最后请求'},JSON.stringify(request.value,null,2)),h('pre',{'aria-label':'已生成导航'},JSON.stringify(navigation.value,null,2))])}).mount('#app');`
const fields=[{key:'payment_name',label:'支付方式',type:'text',width:150,defaultVisible:true},{key:'total_amount',label:'合计',type:'decimal',money:true,width:150,defaultVisible:true},{key:'store_8',label:'海沧店',type:'decimal',money:true,width:150,defaultVisible:true,drilldown:{column_key:'store_8'}}]
const mock=`export async function loadBasicReport(platform,q){window.dispatchEvent(new CustomEvent('r9-fixture-request',{detail:q}));return {rows:[{row_id:'legacy_1',method_code:'legacy_1',payment_name:'银联扫码',total_amount:5998,store_8:5998}],queryFields:${JSON.stringify(fields)},pagination:{page:1,total:1,limit:20},summaries:{},store_options:[{id:8,name:'海沧店'}],receipt_fingerprint:'synthetic-verified-cell',detail_context:{store_id:8,store_name:'海沧店'},navigation_document_nos:['SYNTHETIC-SALE'],navigation_selection:{storeId:8,methodCode:'legacy_1',from:q.start_date,to:q.end_date}}}export async function loadBasicReportScope(){return {tree:[],allowed_store_ids:[8]}}export async function downloadBasicReportXlsx(){throw Error('Fixture export not used')}`
const built=await build({stdin:{contents:entry,resolveDir:root,loader:'js'},bundle:true,write:false,format:'iife',platform:'browser',nodePaths:[path.join(root,'前端代码/cashier-v3/node_modules')],plugins:[{name:'canonical-report',setup(b){
 b.onResolve({filter:/^vue-router$/},()=>({path:'route',namespace:'r9-mock'}))
 b.onResolve({filter:/^@\//},args=>{
  const mocks={'@/services/basicReportApi':'api','@/services/cashierV3Bridge':'state','@/services/reportWorkbookDownload':'download'}
  if(mocks[args.path])return {path:mocks[args.path],namespace:'r9-mock'}
  const p=path.join(src,args.path.slice(2));return {path:existsSync(p)?p:p+'.js'}
 })
 b.onLoad({filter:/.*/,namespace:'r9-mock'},args=>({loader:'js',resolveDir:root,contents:args.path==='api'?mock:args.path==='route'?`export const useRoute=()=>({query:{reconciliation_return:'${token}'}})`:args.path==='state'?`export const useCashierV3State=()=>({currentStore:{id:8}})`:'export const saveReportWorkbook=()=>{}'}))
 b.onLoad({filter:/\.vue$/},args=>{
  const {descriptor,errors}=parse(readFileSync(args.path,'utf8'),{filename:args.path});assert.deepEqual(errors,[])
  const id=createHash('sha256').update(args.path).digest('hex').slice(0,10),script=compileScript(descriptor,{id,inlineTemplate:true,genDefaultAs:'Component'})
  for(const style of descriptor.styles){const x=compileStyle({source:style.content,filename:args.path,id:'data-v-'+id,scoped:style.scoped});assert.deepEqual(x.errors,[]);css.push(x.code)}
  return {loader:'js',resolveDir:path.dirname(args.path),contents:script.content+`\nComponent.__scopeId='data-v-${id}';export default Component`}
 })
}}]})
const html=`<!doctype html><meta charset="utf-8"><title>第9轮对账点击回归</title><style>body{margin:20px;font:14px Arial;color:#26354b;background:#f5f7fa}h1{font-size:18px}button{cursor:pointer}.button{border:1px solid #d8e0ea;border-radius:6px;padding:8px 12px;background:white}pre{background:white;padding:12px;white-space:pre-wrap}.store-reconciliation{height:300px}${css.join('\n')}</style><div id="app"></div><script>${built.outputFiles[0].text}</script>`
if(process.env.R9_EVIDENCE){mkdirSync(process.env.R9_EVIDENCE,{recursive:true});writeFileSync(path.join(process.env.R9_EVIDENCE,'click-fixture.html'),html)}
http.createServer((req,res)=>{res.writeHead(200,{'content-type':'text/html; charset=utf-8','cache-control':'no-store'});res.end(html)}).listen(0,'127.0.0.1',function(){console.log('R9_CLICK_FIXTURE=http://127.0.0.1:'+this.address().port)})
