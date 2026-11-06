/** Browser fixture compiles the canonical report, toolbar and calendar.
 * Synthetic API responses isolate period/display behavior from customer data;
 * requests and export snapshots are visible for manual CUA assertions.
 */
import { createRequire } from 'node:module'
import { readFileSync } from 'node:fs'
import { createHash } from 'node:crypto'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import http from 'node:http'
import assert from 'node:assert/strict'
const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const frontend = path.join(repo, '前端代码/cashier-v3')
const require = createRequire(path.join(frontend, 'package.json'))
const { parse, compileScript, compileStyle } = require('@vue/compiler-sfc'), { build } = require('esbuild')
const css = []
let api = `
const fields = [{key:'store',label:'门店',type:'text',width:180,defaultVisible:true},{key:'customers',label:'总客数',type:'decimal',width:160,defaultVisible:true},{key:'quantity',label:'项目数',type:'decimal',width:160,defaultVisible:true}];
const log=(id,value)=>document.getElementById(id).textContent=JSON.stringify(value);
export const loadBasicReportCatalog=async()=>[{code:'basic_store_summary',name:'门店统计表',available:true,time_mode:'range',queryFields:fields}];
export const loadBasicReportScope=async()=>({tree:[],allowed_store_ids:[1],authorization_mode:'all'});
let calls=0;
export async function loadBasicReport(platform,q,stores){log('requests',{count:++calls,platform,query:q,stores});await new Promise(r=>setTimeout(r,1200));return {rows:[{row_id:'sample',store:'合成门店',customers:'899.000000',quantity:'2.125000'}],pagination:{total:21,page:q.page},queryFields:fields,range:{start_date:q.start_date,end_date:q.end_date},table_layout:{summary_position:'none'},summaries:{},summary_cards:[{key:'customers',label:'总客数',value:'899.000000'},{key:'quantity',label:'项目数',value:'2.125000'},{key:'zero',label:'零值',value:'0.000000'},{key:'missing',label:'待核对',value:null}]};}
export async function downloadBasicReportXlsx(platform,q,stores){log('exports',{platform,query:q,stores});return {};}
export const saveBasicRegistration=()=>{throw Error('fixture is read-only')},saveBasicSalary=saveBasicRegistration,freezeBasicSalary=saveBasicRegistration;`
// 可选展示后端隔离库已验证的完整返回值；不是登录会话，也不能据此声称权限入口验收通过。
const verifiedResult = process.env.SUMMARY_VERIFIED_RESULT
  ? JSON.parse(readFileSync(process.env.SUMMARY_VERIFIED_RESULT, 'utf8')).result : null
if (verifiedResult) {
 api = `const result=${JSON.stringify(verifiedResult)};const fields=result.queryFields;
 const log=(id,value)=>document.getElementById(id).textContent=JSON.stringify(value);
 export const loadBasicReportCatalog=async()=>[{code:'basic_store_summary',name:'门店统计表',available:true,time_mode:'range',queryFields:fields}];
 export const loadBasicReportScope=async()=>({tree:[],allowed_store_ids:result.rows.map(r=>r.store_id),authorization_mode:'all'});
 export async function loadBasicReport(platform,q,stores){log('requests',{platform,query:q,stores});const value=structuredClone(result);
 if(q.start_date!=='2026-10-01'||q.end_date!=='2026-10-31'){value.rows=[];value.summary_cards=[];value.pagination.total=0;}
 value.range={start_date:q.start_date,end_date:q.end_date};return value;}
 export async function downloadBasicReportXlsx(platform,q,stores){log('exports',{platform,query:q,stores});return {};}
 export const saveBasicRegistration=()=>{throw Error('fixture is read-only')},saveBasicSalary=saveBasicRegistration,freezeBasicSalary=saveBasicRegistration;`
}
const modules = {
 'basicReportApi': api,
 'cashierV3Bridge': 'export const useCashierV3State=()=>({currentStore:{id:1}});',
 'reportWorkbookDownload': 'export const saveReportWorkbook=()=>{};',
 'OrganizationStoreScopePicker.vue': `import{h}from'vue';export default{render(){return h('span','合成门店范围')}};`,
 'StoreReconciliationReport.vue': `export default{render(){return null}};`
}
const entry = `import{createApp,h}from'vue';import{createRouter,createMemoryHistory}from'vue-router';import Report from ${JSON.stringify(path.join(frontend,'src/views/BasicReportsView.vue'))};const platform=new URL(location.href).searchParams.get('host')!=='store';const router=createRouter({history:createMemoryHistory(),routes:[{path:'/:report',component:Report,meta:{platformReport:platform}}]});await router.push('/basic_store_summary');createApp({render:()=>h(Report)}).use(router).mount('#app');`
const built = await build({ stdin: { contents: entry, resolveDir: repo, loader: 'js' }, bundle: true, write: false, format: 'esm', platform: 'browser', nodePaths: [path.join(frontend,'node_modules')], plugins: [{ name:'canonical-report', setup(b) {
 b.onResolve({filter:/^@\//},args=>{const key=path.basename(args.path);return modules[key]?{path:key,namespace:'fixture'}:{path:path.join(frontend,'src',args.path.slice(2))}})
 b.onLoad({filter:/.*/,namespace:'fixture'},args=>({contents:modules[args.path],loader:'js',resolveDir:frontend}))
 b.onLoad({filter:/\.css$/},args=>{css.push(readFileSync(args.path,'utf8'));return {contents:'',loader:'js'}})
 b.onLoad({filter:/\.vue$/},args=>{
  const {descriptor,errors}=parse(readFileSync(args.path,'utf8'),{filename:args.path});assert.deepEqual(errors,[])
  const id=createHash('sha256').update(args.path).digest('hex').slice(0,10)
  const compiled=compileScript(descriptor,{id,inlineTemplate:true,genDefaultAs:'Component'})
  for(const style of descriptor.styles){const output=compileStyle({source:style.content,filename:args.path,id:'data-v-'+id,scoped:style.scoped});assert.deepEqual(output.errors,[]);css.push(output.code)}
  return {contents:compiled.content+`\nComponent.__scopeId='data-v-${id}';export default Component`,loader:descriptor.scriptSetup?.lang==='ts'?'ts':'js',resolveDir:path.dirname(args.path)}
 })
 }}]})
const html=`<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>门店统计周期回归</title><style>*{box-sizing:border-box}body{margin:0;font:14px Arial;color:#24354b;background:#f4f7fa}#app{height:650px;padding:20px}.fixture{padding:10px}pre{white-space:pre-wrap}.button{padding:7px 12px;border:1px solid #d8e0ea;border-radius:6px;background:white}.button--primary{color:white;background:#096fdf}${css.join('\n')}</style><nav class="fixture">合成数据组件核验 <a href="/?host=platform">平台端</a> · <a href="/?host=store">门店端</a></nav><div id="app"></div><section class="fixture"><h3>请求快照</h3><pre id="requests"></pre><h3>导出快照</h3><pre id="exports"></pre></section><script type="module">${built.outputFiles[0].text}</script></html>`
http.createServer((req,res)=>{res.writeHead(200,{'content-type':'text/html; charset=utf-8','cache-control':'no-store'});res.end(html)}).listen(0,'127.0.0.1',function(){console.log('SUMMARY_FIXTURE_URL=http://127.0.0.1:'+this.address().port)})
