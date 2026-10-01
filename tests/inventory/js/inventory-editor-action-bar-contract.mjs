import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'

const root = path.resolve(process.cwd())
const file = path.join(root, '前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue')
const source = fs.readFileSync(file, 'utf8')

const checks = [
  ['六类商品明细编辑页共用顶部操作栏', source.includes("const lineEditorKeys = ['inbound', 'outbound', 'count', 'request', 'transfer', 'usage']")],
  ['取消和提交组紧跟批量删除之后', source.indexOf('class="line-submit-actions"') > source.indexOf('@click="removeSelectedRows"')],
  ['盘点保存草稿与完成盘点也在商品操作栏', source.includes('@click="saveCountDraft"') && source.includes('@click="submitCount"') && source.indexOf('@click="saveCountDraft"') > source.indexOf('class="line-submit-actions"')],
  ['顶部标题栏仅保留返回列表入口', !source.match(/inventory-editor__header-actions[\s\S]{0,900}@click="(?:saveCountDraft|submitCount)"/)],
  ['底部栏仅服务详情、导入和无商品栏的仓库创建', source.includes('v-if="isDetail || isImport || pageKey === \'warehouse\'" class="inventory-modal__footer"')],
  ['移动后继续复用各业务原提交函数', ['submitInbound()', 'submitOutbound()', 'submitRequest()', 'submitTransfer()', 'submitUsage()', 'submitWarehouse()'].every((call) => source.includes(`return ${call}`))],
]

let failed = 0
for (const [name, passed] of checks) {
  console.log(`${passed ? 'PASS' : 'FAIL'} ${name}`)
  if (!passed) failed += 1
}

if (failed) {
  console.error(`inventory editor action bar contract failed: ${failed}/${checks.length}`)
  process.exit(1)
}

console.log(`inventory editor action bar contract passed: ${checks.length}/${checks.length}`)
