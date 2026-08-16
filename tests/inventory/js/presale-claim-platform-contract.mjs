#!/usr/bin/env node

import fs from 'node:fs'
import path from 'node:path'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const adminRoot = path.join(root, '前端代码/admin')
const requireFromAdmin = createRequire(path.join(adminRoot, 'package.json'))
const { parseComponent } = requireFromAdmin('vue-template-compiler')

const pagePath = path.join(adminRoot, 'src/pages/stockManage/presaleClaim/list.vue')
const apiPath = path.join(adminRoot, 'src/api/stockManage.js')
const routePath = path.join(adminRoot, 'src/router/modules/stockManage.js')
const cashierRoot = path.join(root, '前端代码/cashier-v3')
const storeViewPath = path.join(cashierRoot, 'src/views/PresaleClaimView.vue')
const storeApiPath = path.join(cashierRoot, 'src/services/presaleClaimApi.js')
const cashierRoutePath = path.join(cashierRoot, 'src/router/index.js')
const inventoryModalPath = path.join(root, '前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue')
const servicePath = path.join(root, '后端代码/app/services/cashier/v3/presale/CashierV3PresaleClaimServices.php')
const storeControllerPath = path.join(root, '后端代码/app/controller/store/product/inventory/PresaleClaim.php')
const platformControllerPath = path.join(root, '后端代码/app/controller/admin/v1/product/inventory/PresaleClaim.php')
const migrationPath = path.join(root, '后端代码/database/upgrades/2026-08-16-收银V3预售领用出库/02-正式升级.sql')

const page = fs.readFileSync(pagePath, 'utf8')
const api = fs.readFileSync(apiPath, 'utf8')
const route = fs.readFileSync(routePath, 'utf8')
const storeView = fs.readFileSync(storeViewPath, 'utf8')
const storeApi = fs.readFileSync(storeApiPath, 'utf8')
const cashierRoute = fs.readFileSync(cashierRoutePath, 'utf8')
const inventoryModal = fs.readFileSync(inventoryModalPath, 'utf8')
const service = fs.readFileSync(servicePath, 'utf8')
const storeController = fs.readFileSync(storeControllerPath, 'utf8')
const platformController = fs.readFileSync(platformControllerPath, 'utf8')
const migration = fs.readFileSync(migrationPath, 'utf8')
const parsed = parseComponent(page, { pad: 'line' })

let failed = 0
function check(name, passed) {
  console.log(`${passed ? 'PASS' : 'FAIL'} ${name}`)
  if (!passed) failed += 1
}

check('platform page is a valid Vue SFC', !parsed.errors?.length && Boolean(parsed.template) && Boolean(parsed.script))
check('organization filter uses the shared organization picker in org-only tree mode',
  page.includes('<OrganizationResourceSelector')
  && page.includes('resource="organization"')
  && page.includes(':tree-mode="true"')
  && page.includes('selection-mode="org_only"')
  && page.includes('v-model="filters.organization_id"'))
check('page supports repeated claim, detail, and void actions',
  page.includes('openClaim(row)') && page.includes('openDetail(row)') && page.includes('openVoid(row)')
  && page.includes('idempotency_key: idempotencyKey'))
check('platform API module targets all four presale claim routes',
  api.includes("'/product/inventory/v3/presale-claims'")
  && api.includes('presale-claims/claim')
  && api.includes('presale-claims/${encodeURIComponent(String(claimId || \'\'))}/void'))
check('stock route reuses the existing inventory menu authorization',
  route.includes("name: 'presaleClaimManage'")
  && route.includes("auth: ['admin-stock-manage']"))
check('backend claim and void require existing platform warehouse-manage capability',
  service.includes("assertFeature($access, 'inventory.location.manage'")
  && service.includes("'当前岗位未配置“平台仓库管理”权限。'"))
check('migration keeps API grants separate and reuses the existing stock menu grant',
  migration.includes("'inventory-v3-platform-batch-view'")
  && migration.includes("'inventory-v3-platform-warehouse-manage'")
  && migration.includes("'/admin/stock/presale-claim'")
  && migration.includes("'admin-stock-manage'"))
check('store page supports the same repeated claim and void lifecycle',
  storeView.includes('createStorePresaleClaim')
  && storeView.includes('voidStorePresaleClaim')
  && storeView.includes('领用成功，已生成预售领用出库单。'))
check('store transport uses the session token and never shared cookies',
  storeApi.includes('readStoreV3SessionToken')
  && storeApi.includes("credentials: 'omit'")
  && storeApi.includes("'/storeapi/product/inventory/v3/presale-claims'"))
check('store page labels post-refund presale lines as closed and keeps them unavailable',
  storeView.includes("CLOSED_AFTER_SALE_REVERSAL: '已关闭'")
  && storeView.includes("row.can_claim"))
check('store defaults to all available presale lines and only shows sales dates for other statuses',
  storeView.includes("const status = ref('AVAILABLE')")
  && storeView.includes("status.value !== 'AVAILABLE'")
  && storeView.includes('start_date: salesStartDate.value, end_date: salesEndDate.value'))
check('presale list filters sales dates on the server under the same access scope',
  service.includes("$this->optionalDate((string)($criteria['start_date'] ?? ''), 'presale_claim_sales_date_invalid')")
  && service.includes("$query->where('business_date', '>=', $startDate)")
  && service.includes("$query->where('business_date', '<=', $endDate)"))
check('store and platform preserve named presale list query parameters',
  storeController.includes("'status' => $this->request->get('status', '')")
  && storeController.includes("'start_date' => $this->request->get('start_date', '')")
  && platformController.includes("'status' => $this->request->get('status', '')")
  && platformController.includes("'organization_id' => $this->request->get('organization_id', 0)"))
check('insufficient stock keeps the claim form and opens a blocking alert',
  storeApi.includes('error.code = String(payload?.data?.code || payload?.code || \'\')')
  && storeView.includes("error?.code || '') === 'presale_claim_stock_insufficient'")
  && storeView.includes('role="alertdialog"')
  && storeView.includes('库存不足'))
check('store route is guarded by the existing inventory outbound feature',
  cashierRoute.includes("'cashier-v3-presale-claim': 'cashier.v3.inventory.outbound'")
  && cashierRoute.includes("path: 'presale-claim'"))
check('presale claim creates an outbound fact before deducting stock in the same transaction',
  service.indexOf("'sourceType' => 'presale_claim_outbound'") >= 0
  && service.indexOf("'sourceType' => 'presale_claim_outbound'") < service.indexOf('$this->decreaseBalances($stock, $allocations, $units, $now);'))
check('inventory detail labels presale claim movements as outbound documents',
  inventoryModal.includes("presale_claim_outbound: '预售领用出库'")
  && inventoryModal.includes("presale_claim_void: '预售领用作废退库'"))

process.exit(failed === 0 ? 0 : 1)
