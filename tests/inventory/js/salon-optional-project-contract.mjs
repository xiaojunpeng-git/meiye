import assert from 'node:assert/strict'
import fs from 'node:fs'

const modal = fs.readFileSync(new URL('../../../前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue', import.meta.url), 'utf8')
const list = fs.readFileSync(new URL('../../../前端代码/inventory-vue3/src/App.vue', import.meta.url), 'utf8')
const service = fs.readFileSync(new URL('../../../后端代码/app/services/product/inventory/InventorySalonUsageServices.php', import.meta.url), 'utf8')
const schema = fs.readFileSync(new URL('../../../后端代码/database/upgrades/2026-07-30-库存V3院装事实/02-正式升级.sql', import.meta.url), 'utf8')

// 只放宽项目关联：耗材明细仍必填；前后端都必须接受项目 0 并显示未关联状态。
assert.match(modal, /lineEditorKeys\.includes\(props\.pageKey\) && !selectedRows\.value\.length/, '院装仍须至少选择一条耗材')
assert.doesNotMatch(modal, /pageKey === 'usage' && \(!Number\(usageProjectId\.value\)/, '未选项目不能禁用领用按钮')
assert.match(modal, /if \(props\.pageKey !== 'usage' \|\| !selectedRows\.value\.length\) return/, '未选项目可以提交领用')
assert.match(modal, /<span class="field-label">核销项目<\/span>/, '项目字段不标必填')
assert.match(modal, /不关联核销项目/, '空选项明确是不关联项目')
assert.match(list, /text\(row\.project_name_snapshot, '未关联项目'\)/, '无项目的单据在列表可识别')
assert.match(service, /\$input\['project_id'\] < 0/, '服务端仅拒绝负项目 ID')
assert.match(service, /if \(\$command\['projectId'\] > 0\)/, '已选项目仍由服务端校验归属')
assert.match(service, /private function voidAtScope/, '未关联项目的领用与其他领用一样支持作废退库')
assert.doesNotMatch(modal, /returnSalonUsage|start-usage-return/, '不再提供新的院装退回操作')
assert.match(schema, /project_id.*NOT NULL DEFAULT '0'/, '现有单据表已支持未关联项目，无需改表')

console.log('SALON_OPTIONAL_PROJECT_CONTRACT_OK')
