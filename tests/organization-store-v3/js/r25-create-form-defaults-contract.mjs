import fs from 'node:fs'
import assert from 'node:assert/strict'

const root = decodeURIComponent(new URL('../../../', import.meta.url).pathname)
const read = (path) => fs.readFileSync(`${root}/${path}`, 'utf8')

const storeStaff = read('前端代码/cashier-v3/src/views/StaffListView.vue')
const platformStaff = read('前端代码/admin/src/pages/setting/staff/add.vue')
const productDefaults = read('前端代码/admin/src/pages/product/productAdd/formModel.js')
const productForm = read('前端代码/admin/src/pages/product/productAdd/index.vue')
const inventorySwitch = read('前端代码/admin/src/pages/product/productAdd/components/inventorySet.vue')

// 人员编辑不再暴露旧类型；新增与编辑统一提交双项，历史员工事实不批量改写。
assert.doesNotMatch(storeStaff, /craftsmanPerformanceType:/)
assert.match(storeStaff, /editorStaffId\.value > 0\) replaceEditorValues\(mapDetail\(/)
assert.match(platformStaff, /function getDefaultStaffForm\(\)[\s\S]*?craftsman_performance_type: 'commission_labor'/)
assert.doesNotMatch(platformStaff, /手艺人服务业绩类型/)

// 新建商品关闭负库存，保存显式发送 0；编辑/复制仍回显商品原设置。
assert.match(productDefaults, /allow_negative_stock: 0/)
assert.match(inventorySwitch, /v-model="baseInfo\.allow_negative_stock"/)
assert.match(productForm, /if \(data\[key\] !== undefined\) \{\s*this\.formData\[key\] = data\[key\][\s\S]*?if \(data\.allow_negative_stock == null\) this\.formData\.allow_negative_stock = 1/)
assert.match(productForm, /this\.formData\.allow_negative_stock != null \? this\.formData\.allow_negative_stock : \(this\.\$route\.params\.id \|\| this\.\$route\.query\.copy \? 1 : 0\)/)

console.log('r25-create-form-defaults-contract: PASS')
