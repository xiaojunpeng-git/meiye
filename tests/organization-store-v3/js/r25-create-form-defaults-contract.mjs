import fs from 'node:fs'
import assert from 'node:assert/strict'

const root = decodeURIComponent(new URL('../../../', import.meta.url).pathname)
const read = (path) => fs.readFileSync(`${root}/${path}`, 'utf8')

const storeStaff = read('前端代码/cashier-v3/src/views/StaffListView.vue')
const platformStaff = read('前端代码/admin/src/pages/setting/staff/add.vue')
const productDefaults = read('前端代码/admin/src/pages/product/productAdd/formModel.js')
const productForm = read('前端代码/admin/src/pages/product/productAdd/index.vue')
const inventorySwitch = read('前端代码/admin/src/pages/product/productAdd/components/inventorySet.vue')

// 新建预选值与旧资料编辑兼容值分离，避免批量改变已有员工的业绩口径。
assert.match(storeStaff, /function defaultEditorValues\(\)[\s\S]*?craftsmanPerformanceType: 'commission_labor'/)
assert.match(storeStaff, /editorStaffId\.value > 0\) replaceEditorValues\(mapDetail\(/)
assert.match(storeStaff, /detail\?\.craftsman_performance_type[\s\S]*?: 'commission'/)
assert.match(platformStaff, /function getDefaultStaffForm\(\)[\s\S]*?craftsman_performance_type: 'commission_labor'/)
assert.match(platformStaff, /craftsman_performance_type: \['commission', 'labor', 'commission_labor'\][\s\S]*?: 'commission'/)

// 新建商品关闭负库存，保存显式发送 0；编辑/复制仍回显商品原设置。
assert.match(productDefaults, /allow_negative_stock: 0/)
assert.match(inventorySwitch, /v-model="baseInfo\.allow_negative_stock"/)
assert.match(productForm, /if \(data\[key\] !== undefined\) \{\s*this\.formData\[key\] = data\[key\][\s\S]*?if \(data\.allow_negative_stock == null\) this\.formData\.allow_negative_stock = 1/)
assert.match(productForm, /this\.formData\.allow_negative_stock != null \? this\.formData\.allow_negative_stock : \(this\.\$route\.params\.id \|\| this\.\$route\.query\.copy \? 1 : 0\)/)

console.log('r25-create-form-defaults-contract: PASS')
