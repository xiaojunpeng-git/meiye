import assert from 'node:assert/strict'
import {createRequire} from 'node:module'
import path from 'node:path'
const front=path.resolve('前端代码/cashier-v3')
const require=createRequire(path.join(front,'package.json'))
const {createServer}=require('vite'),{createSSRApp,h}=require('vue'),{renderToString}=require('@vue/server-renderer')
// 渲染真实共享组件，复现平台 pageCode 被写死排除的问题；不使用替代工具栏。
const server=await createServer({root:front,ssr:{noExternal:['@mohe/unified-query-vue3','@lucide/vue']},server:{middlewareMode:true},appType:'custom',logLevel:'error'})
try{
 const {default:Toolbar}=await server.ssrLoadModule('/src/components/query/MemberReportQueryToolbar.vue')
 const fields=[{key:'created_at',label:'建档时间',type:'datetime',capabilities:{filter:true,export:true}}]
 for(const pageCode of ['member_list','platform_member_list']){
  const queryCapability={enabled:true,pageCode,commandContext:{kind:'query_preference',id:pageCode},exportCapability:{enabled:true,allowCurrentQuery:true}}
  const app=createSSRApp({render:()=>h(Toolbar,{pageCode,fields,queryCapability,executedQuery:{pageCode,page:2,limit:20,keyword:'保留关键词'}})})
  const html=await renderToString(app)
  assert.match(html,/保留关键词/);assert.match(html,/导出/);assert.match(html,/更多筛选/)
 }
 const html=await renderToString(createSSRApp({render:()=>h(Toolbar,{pageCode:'platform_member_list',fields,queryCapability:{enabled:true,pageCode:'member_list'},executedQuery:{pageCode:'platform_member_list'}})}))
 assert.doesNotMatch(html,/>导出</)
 console.log('PASS: actual Vue toolbar renders platform/store export and restores query; mismatched capability remains closed')
}finally{await server.close()}
