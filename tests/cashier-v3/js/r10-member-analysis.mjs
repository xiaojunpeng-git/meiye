import assert from 'node:assert/strict'
import {createRequire} from 'node:module'
import path from 'node:path'
const root=path.resolve('前端代码/cashier-v3'), require=createRequire(path.join(root,'package.json'))
const {createServer}=require('vite')
// 加载真实业务契约，验证草稿与已执行查询隔离、零值及清空；不使用镜像实现。
const server=await createServer({root,server:{middlewareMode:true},appType:'custom',logLevel:'error',ssr:{noExternal:['@mohe/unified-query-vue3','@lucide/vue']}})
try {
 const {memberAnalysisFields:fields,memberAnalysisDraft:draft,buildMemberAnalysis:build}=await server.ssrLoadModule('/src/services/memberAnalysisFilters.js')
 const {buildMemberReportQuery}=await server.ssrLoadModule('/src/services/memberReportFilters.js')
 assert.equal(fields.length,9);assert.equal(fields.find(f=>f.key==='cashPerformance').label,'现金业绩')
 const original={pageCode:'platform_member_list',page:3,memberAnalysis:{cashPerformance:{min:'100',max:'200'}}}
 const edited=draft(original.memberAnalysis);edited.cashPerformance.min='150'
 assert.equal(original.memberAnalysis.cashPerformance.min,'100')
 assert.deepEqual(build(draft()),{})
 edited.visitCount={min:'0',max:'0'};assert.deepEqual(build(edited).visitCount,{min:'0',max:'0'})
 edited.visitPeriod={min:'2026-10-01',max:''};assert.throws(()=>build(edited),/完整/)
 edited.visitPeriod={min:'2026-10-01',max:'2026-10-31'}
 edited.remainingTimes={min:'0.5',max:''};assert.throws(()=>build(edited),/整数/)
 for(const pageCode of ['member_list','platform_member_list']) {
  const q=buildMemberReportQuery({...original,pageCode},{filters:[]},'检索词','normal',20,false,'',pageCode)
  assert.equal(q.page,1);assert.deepEqual(q.memberAnalysis,original.memberAnalysis)
 }
 const {createSSRApp,h}=require('vue'),{renderToString}=require('@vue/server-renderer')
 const {default:Toolbar}=await server.ssrLoadModule('/src/components/query/MemberReportQueryToolbar.vue')
 for(const pageCode of ['member_list','platform_member_list']) {
  const html=await renderToString(createSSRApp({render:()=>h(Toolbar,{pageCode,executedQuery:{pageCode},queryCapability:{enabled:true,pageCode}})}))
  assert.ok(html.indexOf('分析筛选')<html.indexOf('更多筛选'))
 }
 console.log('PASS: nine shared analysis fields, cash label, zero/integer/range validation, draft isolation, page reset, both toolbars')
} finally { await server.close() }
