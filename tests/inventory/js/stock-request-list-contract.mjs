import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(here, '../../..')
const pagePath = path.join(root, '前端代码/admin/src/pages/stockManage/stockRequestManage/list.vue')
const source = fs.readFileSync(pagePath, 'utf8')

let passed = 0
let failed = 0
function check(name, condition) {
  if (condition) {
    passed += 1
    console.log(`PASS ${name}`)
  } else {
    failed += 1
    console.log(`FAIL ${name}`)
  }
}

const filterStart = source.indexOf('<FormItem label="供货方：">')
const filterEnd = source.indexOf('</FormItem>', filterStart)
const supplyFilter = source.slice(filterStart, filterEnd)
const statusStart = source.indexOf('<FormItem label="状态：">')
const statusEnd = source.indexOf('</FormItem>', statusStart)
const statusFilter = source.slice(statusStart, statusEnd)

check('request status filter exposes only an explicit 全部 option',
  statusFilter.includes('<Option value="">全部</Option>')
    && !statusFilter.includes('草稿')
    && !statusFilter.includes('已申请')
    && !statusFilter.includes('部分调拨'))
check('request supply filter contains stores but no HQ option',
  supplyFilter.includes("'s' + item.id")
    && !supplyFilter.includes('总部仓'))
check('request list removes the obsolete operation note',
  !source.includes('确认申请不会变动库存；确认调拨后才会改双方库存'))
check('request creation form remains the only place that offers HQ supply',
  source.includes('<form-modal')
    && !source.includes('请货表单不支持总部仓'))

console.log(`INVENTORY_STOCK_REQUEST_LIST_RESULT passed=${passed} failed=${failed}`)
process.exit(failed === 0 ? 0 : 1)
