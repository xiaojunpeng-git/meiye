/** Compile the real order query toolbar for CUA interaction; synthetic rows only, no login or business API. */
import { createRequire } from 'node:module'
import { readFileSync,writeFileSync,mkdirSync } from 'node:fs'
import { createHash } from 'node:crypto'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import http from 'node:http'
import assert from 'node:assert/strict'
const repo=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../../..')
const require=createRequire(path.join(repo,'前端代码/cashier-v3/package.json'))
const {parse,compileScript,compileStyle}=require('@vue/compiler-sfc')
const {build}=require('esbuild')
const toolbar=path.join(repo,'前端代码/cashier-v3/src/components/query/OrderCenterReportQueryToolbar.vue')
const css=[]
const entry=`import Toolbar from ${JSON.stringify(toolbar)};import {createApp,h,reactive} from 'vue';
const original={pageCode:'order_center_all',page:1,limit:20,dataScope:'normal',topFilters:[],reconciliationDrilldown:{storeId:8,methodCode:'legacy_1',from:'2026-10-28',to:'2026-10-28'}};
const state=reactive({query:original,count:0,host:'平台端'});
createApp({render:()=>h('main',[
 h('h1','对账订单日期组件核验'),h('p','合成测试数据：只验证共用组件交互，不连接业务接口。'),
 h('button',{onClick:()=>state.host=state.host==='平台端'?'门店端':'平台端'},'切换平台门店'),h('h2',state.host),
 h(Toolbar,{fields:[{key:'member_name',label:'会员姓名',type:'text',capabilities:{filter:true},filter:{enabled:true}}],pageCode:'order_center_all',executedQuery:state.query,
  onQuery:q=>{state.count++;state.query=q}}, {'primary-actions':()=>state.host==='平台端'?h('button',{class:'button'},'海沧店'):null}),
 h('output',{'aria-label':'查询次数'},String(state.count)),h('pre',{'aria-label':'已提交查询'},JSON.stringify(state.query,null,2))
])}).mount('#app');`
const result=await build({stdin:{contents:entry,resolveDir:repo,loader:'js'},bundle:true,write:false,format:'iife',platform:'browser',nodePaths:[path.join(repo,'前端代码/cashier-v3/node_modules')],plugins:[{name:'canonical-sfc',setup(builder){
 builder.onLoad({filter:/\.vue$/},args=>{
  const {descriptor,errors}=parse(readFileSync(args.path,'utf8'),{filename:args.path});assert.deepEqual(errors,[])
  const id=createHash('sha256').update(args.path).digest('hex').slice(0,10)
  const script=compileScript(descriptor,{id,inlineTemplate:true,genDefaultAs:'Component'})
  for(const style of descriptor.styles){const result=compileStyle({source:style.content,filename:args.path,id:'data-v-'+id,scoped:style.scoped});assert.deepEqual(result.errors,[]);css.push(result.code)}
  return {contents:script.content+`\nComponent.__scopeId='data-v-${id}';export default Component`,loader:'js',resolveDir:path.dirname(args.path)}
 })
}}]})
const html=`<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>第9轮对账日期核验</title><style>body{padding:20px;margin:0;background:#f5f7fa;color:#26354b;font:14px Arial}*{box-sizing:border-box}.button,button{border:1px solid #d8e0ea;border-radius:6px;padding:7px 12px;background:white;cursor:pointer}.button--primary{background:#096fdf;color:white}h1{font-size:18px}h2{font-size:15px}pre{padding:14px;background:white;white-space:pre-wrap}output{display:block;margin-top:16px}${css.join('\n')}</style><div id="app"></div><script>${result.outputFiles[0].text}</script></html>`
if(process.env.R9_EVIDENCE){mkdirSync(process.env.R9_EVIDENCE,{recursive:true});writeFileSync(path.join(process.env.R9_EVIDENCE,'component-fixture.html'),html)}
http.createServer((req,res)=>{res.writeHead(200,{'content-type':'text/html; charset=utf-8','cache-control':'no-store'});res.end(html)}).listen(0,'127.0.0.1',function(){console.log('R9_FIXTURE_URL=http://127.0.0.1:'+this.address().port)})
