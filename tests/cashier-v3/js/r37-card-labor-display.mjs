/** R37补漏：执行真实Vue2列计算，覆盖所有卡规则及组合，显隐不得修改卡内金额数据。 */
import assert from 'node:assert/strict'
import { readFileSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
const root=fileURLToPath(new URL('../../../',import.meta.url))
const admin=process.env.R37_ADMIN_ROOT || root+'前端代码/admin'
const require=createRequire(admin+'/package.json')
const {parseComponent}=require('vue-template-compiler')
const {parse}=require('@babel/parser')
const source=parseComponent(readFileSync(admin+'/src/pages/product/productAdd/index.vue','utf8')).script.content
const ast=parse(source,{sourceType:'module',plugins:['objectRestSpread','optionalChaining']})
const options=ast.program.body.find(n=>n.type==='ExportDefaultDeclaration').declaration
function method(group,name){const n=options.properties.find(p=>p.key?.name===group).value.properties.find(p=>p.key?.name===name);return new Function('return ({'+source.slice(n.start,n.end)+'}).'+name)()}
const columns=method('computed','cardRuleColumns'),combinations=method('methods','combinationColumns'),cases=[]
for(const rule of ['normal','choice_kind','choice_count','time','combination'])for(const visible of [true,false]){
 const context={formData:{card_rule_type:rule},cardLaborFeeVisible:visible,cardData:[{unique:'keep',labor_fee:25,price:353,write_times:3}]}
 const initial=JSON.stringify(context.cardData)
 const result=columns.call(context)
 assert.equal(result.some(c=>c.slot==='labor_fee'),visible)
 assert.ok(result.some(c=>c.slot==='craftsman_default'))
 assert.equal(JSON.stringify(context.cardData),initial)
 context.cardRuleColumns=result
 for(const combinationRule of ['choice_kind','choice_count']){
  const grouped=combinations.call(context,combinationRule)
  assert.equal(grouped.some(c=>c.slot==='labor_fee'),visible)
  assert.ok(!grouped.some(c=>c.type==='selection'))
 }
 cases.push({rule,visible,result:'PASS'})
}
writeFileSync(process.env.R37_EVIDENCE_OUTPUT || root+'tests/cashier-v3/evidence/R37-card-labor-followup/verification.json',JSON.stringify({result:'PASS',cases,cardLaborFeePreserved:25,combinationColumns:'PASS'},null,2))
console.log('R37 card columns: 5 rules × 2 modes; grouped rules and saved fee preservation PASS')
