import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const formPath = path.join(root, '前端代码/admin/src/pages/stockManage/stockTransferManage/add.vue')
const servicePath = path.join(root, '后端代码/app/services/store/SystemStoreStaffServices.php')
const source = fs.readFileSync(formPath, 'utf8')
const service = fs.readFileSync(servicePath, 'utf8')
let passed = 0
let failed = 0
function check(name, condition) {
  if (condition) passed += 1
  else failed += 1
  console.log(`${condition ? 'PASS' : 'FAIL'} ${name}`)
}

const selectorCount = (source.match(/resource="store"/g) || []).length
check('source and destination both use the shared store-only selector',
  selectorCount >= 2
    && (source.match(/selection-mode="store_only"/g) || []).length >= 2
    && source.includes('picker-mode="modal"'))
check('platform transfer defaults to headquarters and still supports store source mode',
  source.includes("fromMode: 'hq'")
    && source.includes('<Option value="hq">总部仓</Option>')
    && source.includes('<Option value="store">门店</Option>')
    && source.includes('onFromStorePick'))
check('current account tenure is preferred when loading transfer staff',
  source.includes('current.employee_id')
    && source.includes('item.employee_id')
    && source.includes('matchedEmployee')
    && source.includes('keepStaffId || staffId'))
check('linked request rows preserve authoritative detail ids and remaining quantities',
  source.includes('request_detail_id: d.id')
    && source.includes('remain_qty: remain')
    && source.includes('max_qty: remain')
    && source.includes('request_detail_id: row.request_detail_id'))
check('transfer form does not expose client-side cost or amount columns',
  !source.includes('实际单位成本') && !source.includes('调拨金额'))
check('staff select API returns employee identity for safe default matching',
  service.includes("getSelectList($where, 'id,employee_id,staff_name')")
    && service.includes("'employee_id' => (int)($menu['employee_id'] ?? 0)"))

console.log(`INVENTORY_STOCK_TRANSFER_FORM_RESULT passed=${passed} failed=${failed}`)
process.exit(failed === 0 ? 0 : 1)
