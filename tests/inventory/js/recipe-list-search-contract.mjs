import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const app = read('前端代码/inventory-vue3/src/App.vue')
const controller = read('后端代码/app/controller/admin/v1/product/inventory/StoreProjectConsumableRecipe.php')
const service = read('后端代码/app/services/product/inventory/StoreProjectConsumableRecipeServices.php')
const model = read('后端代码/app/model/product/inventory/StoreProjectConsumableRecipe.php')

assert.match(app, /v-model="recipeKeyword"[\s\S]*placeholder="搜索项目名称"[\s\S]*@keyup\.enter="loadCurrentList"/, '项目配方提供项目名称模糊搜索')
assert.match(app, /query\.keyword = recipeKeyword\.value\.trim\(\)/, '项目配方查询携带当前项目关键词')
assert.match(app, /recipeKeyword = ''/, '项目配方重置会清空项目关键词')
assert.match(app, /filter-search[\s\S]*recipeKeyword[\s\S]*primary-button/, '项目搜索框位于查询按钮左侧')
assert.match(app, /function openRecipeEditor\(row = null\)[\s\S]*selectedDetail\.value = row[\s\S]*editor\.value = row \? 'recipe-edit' : 'recipe'/, '新增配方不复用列表首行，只有明确传行才进入编辑')
assert.match(app, /active === 'recipe' \? openRecipeEditor\(\) : openEditor\(\)/, '页面新增按钮始终打开空白配方')
assert.match(app, /openRecipeEditor\(sourceRows\[index\]\)/, '列表编辑会传入当前行配方')
assert.match(controller, /\['keyword', ''\]/, '项目配方列表接口接收关键词')
assert.match(service, /store_name', 'like'/, '项目配方服务按项目名称模糊匹配')
assert.match(service, /project_product_ids/, '项目名称匹配结果会约束配方项目')
assert.match(model, /searchProjectProductIdsAttr[\s\S]*whereIn\('project_product_id'/, '配方查询按匹配项目集合过滤')

console.log('INVENTORY_RECIPE_LIST_SEARCH_CONTRACT_RESULT PASS')
