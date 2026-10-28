/** 中文错误投影不能改变结构化code/data，避免影响原有业务分支。 */
import assert from 'node:assert/strict'
import { createInventoryApi } from '../../../前端代码/inventory-vue3/src/services/inventoryApi.js'
for (const [message, expected] of [['inventory_new_internal_error','库存服务拒绝了本次请求。'],['请先选择仓库','请先选择仓库'],['inventory_manual_inbound_dates_required','请为每个入库商品填写生产日期和到期日。']]) {
 const api = createInventoryApi({windowRef:{localStorage:{getItem:()=>''}},fetchImpl:async()=>({ok:true,status:200,text:async()=>JSON.stringify({status:400,msg:message,data:{code:'stable_error_code',unchanged:7}})})})
 await assert.rejects(api.dashboard(), e => e.message === expected && e.code === 'stable_error_code' && e.data.unchanged === 7)
}
console.log('PASS 中文已知错误/未知错误兜底/原code及data保留')
