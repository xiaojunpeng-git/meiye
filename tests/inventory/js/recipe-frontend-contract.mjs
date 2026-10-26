import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const app = read('前端代码/inventory-vue3/src/App.vue')
const modal = read('前端代码/inventory-vue3/src/components/InventoryRecipeModal.vue')
const selector = read('前端代码/inventory-vue3/src/components/InventoryRecipeProductSelector.vue')
const api = read('前端代码/inventory-vue3/src/services/inventoryApi.js')
const service = read('后端代码/app/services/product/inventory/StoreProjectConsumableRecipeServices.php')
const detailDao = read('后端代码/app/dao/product/inventory/StoreProjectConsumableRecipeDetailDao.php')

let failed = 0
function check(name, condition) {
  console.log(`${condition ? 'PASS' : 'FAIL'} ${name}`)
  if (!condition) failed += 1
}

check('recipe remains platform-only and exposes the confirmed list actions',
  app.includes("key: 'recipe', label: '项目配方'")
    && app.includes('platformOnly: true')
    && app.includes('openRecipeEditor')
    && app.includes('toggleRecipeStatus')
    && app.includes('deleteRecipe'))
check('recipe editor selects one project and multiple consumables without exposing id inputs',
  modal.includes('InventoryRecipeProductSelector')
    && modal.includes('projectSelectorVisible')
    && modal.includes('consumableSelectorVisible')
    && selector.includes("props.kind === 'project'")
    && selector.includes('selection.value = new Map([[key, row]])')
    && !modal.includes('输入项目ID'))
check('recipe writes the authoritative project sku and consumable sku facts',
  api.includes('/recipe/save/')
    && modal.includes('project_product_id: form.value.project_product_id')
    && modal.includes('project_unique: form.value.project_unique')
    && modal.includes('consumable_product_id: item.consumable_product_id')
    && modal.includes('consumable_unique: item.consumable_unique'))
check('new recipe quantities are positive and limited to two decimals on both sides',
  modal.includes("/^\\d+(\\.\\d{1,2})?$/")
    && modal.includes('step="0.01"')
    && service.includes("'/^\\d+(\\.\\d{1,2})?$/'")
    && service.includes('耗材单次用量最多保留两位小数'))
check('recipe detail is isolated by recipe id and editing cannot rebind the project',
  detailDao.includes("where('recipe_id', $recipeId)")
    && !detailDao.includes("search(['recipe_id' => $recipeId])")
    && service.includes('编辑配方时不能更换项目规格'))
check('recipe copy explains historical snapshots and destructive actions require confirmation',
  modal.includes('后来修改配方不会改变历史核销记录')
    && app.includes('历史记录不受影响')
    && app.includes('删除后不能恢复'))

console.log(`INVENTORY_RECIPE_FRONTEND_CONTRACT_RESULT failed=${failed}`)
process.exit(failed ? 1 : 0)
