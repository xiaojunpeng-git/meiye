#!/usr/bin/env node
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const dirname = path.dirname(fileURLToPath(import.meta.url))
const repo = path.resolve(dirname, '../../..')
const view = fs.readFileSync(path.join(repo, '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue'), 'utf8')
const workspace = fs.readFileSync(path.join(repo, '后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php'), 'utf8')
const preparation = fs.readFileSync(path.join(repo, '后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutPreparationServices.php'), 'utf8')
const inventory = fs.readFileSync(path.join(repo, '后端代码/app/services/cashier/v3/settlement/CashierV3SaleInventorySettlementServices.php'), 'utf8')
const normalizer = fs.readFileSync(path.join(repo, '后端代码/app/services/cashier/v3/CashierV3RequestNormalizer.php'), 'utf8')
const cashierModule = fs.readFileSync(path.join(repo, '后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php'), 'utf8')
const catalog = fs.readFileSync(path.join(repo, '后端代码/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php'), 'utf8')

let failed = 0
function check(condition, message) {
  if (condition) console.log(`PASS: ${message}`)
  else {
    failed += 1
    console.error(`FAIL: ${message}`)
  }
}

function body(source, name) {
  const start = source.indexOf(`function ${name}`)
  if (start < 0) return ''
  const rest = source.slice(start + 1)
  const end = rest.search(/\n(?:async )?function /)
  return source.slice(start, end < 0 ? source.length : start + 1 + end)
}

const modeSwitch = body(view, 'setCartLineInventoryMode(line, mode)')
const deferred = body(view, 'persistDeferredLineServiceSettings()')
const localPreview = body(view, 'localCheckoutPreviewSnapshot()')
const finalSnapshot = body(view, 'buildCheckoutSnapshot(preview = {})')
const localDraftRecalculation = view.slice(
  view.indexOf('function recalculateLocalCashierDraft'),
  view.indexOf('function commitLocalCashierDraft')
)

check(
  /isInventoryManagedProductLine\(line\)/.test(modeSwitch)
    && /isPresale\s*=\s*mode === 'presale'/.test(modeSwitch)
    && /inventoryOutboundRequired:\s*mode === 'outbound'/.test(modeSwitch)
    && !/requestAction\(|mutateCashierDraft\(/.test(modeSwitch),
  '库存管理商品的出库、不出库、预售切换只更新前端本地状态，预售自动不出库'
)
check(
  /v-if="isInventoryManagedProductLine\(line\)"/.test(view)
    && />出库<\/button>/.test(view)
    && />不出库<\/button>/.test(view)
    && />预售<\/button>/.test(view),
  '三按钮只渲染在库存管理商品行，定制卡不进入库存流程'
)
check(
  /return true/.test(deferred)
    && !/requestAction\(|mutateCashierDraft\(/.test(deferred)
    && /recalculateLocalCashierDraft\(/.test(localPreview)
    && /localLineServiceSettings/.test(localDraftRecalculation)
    && /isPresale/.test(localDraftRecalculation)
    && /inventoryOutboundRequired/.test(localDraftRecalculation),
  '产品库存规则只在立即结账时合并到前端快照，不提前保存草稿'
)
check(
  /isInventoryManagedProductLine\(line\)/.test(localPreview)
    && /isPresale:\s*cartLinePresaleSelected\(line\)/.test(localPreview)
    && /inventoryOutboundRequired:\s*cartLineInventoryOutboundRequired\(line\)/.test(localPreview)
    && /isProjectLine\(line\)/.test(localPreview)
    && /isExperience:\s*cartLineExperienceSelected\(line\)/.test(localPreview)
    && /isInventoryManagedProductLine\(line\)/.test(finalSnapshot)
    && /next\.isPresale\s*=\s*cartLinePresaleSelected\(line\)/.test(finalSnapshot)
    && /next\.inventoryOutboundRequired\s*=\s*cartLineInventoryOutboundRequired\(line\)/.test(finalSnapshot)
    && /next\.isExperience\s*=\s*cartLineExperienceSelected\(line\)/.test(finalSnapshot),
  '体验、出库、不出库、预售均在预览和最终提交两个快照边界固定'
)
check(
  /if \(cartLinePresaleSelected\(line\)\) return false/.test(view)
    && /\$isPresale\s*=\s*!\$customCard && !empty\(\$line\['isPresale'\]\)/.test(preparation)
    && /\$customCard\s*\|\|\s*\$isPresale/.test(preparation),
  '预售快照强制不出库，旧投影不能把预售带入库存扣减'
)
check(
  /inventory_rule_product_only/.test(workspace)
    && /presale_must_not_outbound/.test(workspace)
    && /'inventory_outbound_required'\s*=>\s*\$inventoryOutboundRequired/.test(workspace),
  '后端限制库存规则仅产品可设且预售不可出库'
)
check(
  /\$hasInventoryRule\s*=/.test(cashierModule)
    && /!\$hasSalespeople\s*&&\s*!\$hasAttributions\s*&&\s*!\$hasInventoryRule/.test(cashierModule),
  '产品仅提交出库/预售规则时不强制携带销售人'
)
check(
  /inventoryOutboundRequired/.test(normalizer)
    && /isPresale/.test(normalizer),
  '请求规范化接受并标准化两个库存规则字段'
)
check(
  /inventory_outbound_required.*!== 1/.test(inventory),
  '正式结账库存服务仅为要求出库的产品生成库存扣减计划'
)
check(
  /\$isProduct\s*=\s*\(int\)\(\$current\['productType'\]/.test(catalog)
    && /!\$isProduct\s*\|\|\s*\(!\$isPresale\s*&&\s*\$requiresOutbound\)/s.test(catalog)
    && /assertInventoryAvailableForSale\(\$current, \$quantity, \$dataScope\)/.test(catalog),
  '产品不出库/预售跳过准备阶段库存门禁，默认出库仍校验库存'
)
check(
  /'isPresale'\s*=>\s*\(int\)\(\$storedLine\['is_presale'\]/.test(catalog)
    && /'inventoryOutboundRequired'\s*=>\s*\(int\)\(\$storedLine\['inventory_outbound_required'\]/.test(catalog),
  '结账源快照继承工作区产品库存规则'
)

if (failed > 0) process.exit(1)
console.log('PRODUCT_INVENTORY_OUTBOUND_DEFERRED_CONTRACT=PASS')
