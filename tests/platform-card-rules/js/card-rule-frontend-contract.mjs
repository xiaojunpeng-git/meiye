#!/usr/bin/env node

import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const formPath = path.join(root, '前端代码/admin/src/pages/product/productAdd/index.vue')
const modelPath = path.join(root, '前端代码/admin/src/pages/product/productAdd/formModel.js')
const reservationPath = path.join(root, '前端代码/admin/src/pages/product/productAdd/components/reservationSet.vue')
const listPath = path.join(root, '前端代码/admin/src/pages/product/productList/index.vue')
const detailsPath = path.join(root, '前端代码/admin/src/pages/product/components/productDetails.vue')
const selectorPath = path.join(root, '前端代码/admin/src/components/goodsAttr/index.vue')
const productBasePath = path.join(root, '前端代码/admin/src/pages/product/productAdd/components/productBaseSet.vue')
const form = fs.readFileSync(formPath, 'utf8')
const model = fs.readFileSync(modelPath, 'utf8')
const reservation = fs.readFileSync(reservationPath, 'utf8')
const list = fs.readFileSync(listPath, 'utf8')
const details = fs.readFileSync(detailsPath, 'utf8')
const selector = fs.readFileSync(selectorPath, 'utf8')
const productBase = fs.readFileSync(productBasePath, 'utf8')

const checks = [
  ['four confirmed rule names are present', ['普通卡', '任选种数卡', '任选次数卡', '时间卡'].every((name) => form.includes(name))],
  ['deprecated shared-card name is absent', !form.includes('共享卡')],
  ['time card hides perpetual validity', form.includes("formData.card_rule_type != 'time'") && form.includes('>永久有效</Radio>')],
  ['card validity uses after-opening wording', form.includes("'开卡后若干天有效'")],
  ['choice-kind and choice-count fields are conditional', form.includes("card_rule_type == 'choice_kind'") && form.includes("card_rule_type == 'choice_count'")],
  ['time card exposes per-writeoff amount', form.includes("slot=\"writeoff_amount\"") && form.includes("title: '单次核销金额'")],
  ['time-card writeoff is restricted to whole yuan', form.includes(':max="9999999999"') && form.includes(':precision="0"') && form.includes('整数核销金额')],
  ['card and project suppress generic stock input', form.includes('formData.product_type != 5 && formData.product_type != 6')],
  ['card project prices display as whole yuan', form.includes('slot="price"') && form.includes('formatWholeYuan(row.price)') && form.includes('String(Math.trunc(amount))')],
  ['only retail products expose stock synchronization', form.includes('Number(formData.product_type) === 0 && formData.applicable_type')],
  ['retail products default to single specification and hide the selector', model.includes('spec_type: 0') && form.includes('formData.product_type != 0 && formData.product_type != 4')],
  ['retail single-specification name is editable and submitted', form.includes('v-model.trim="formData.single_spec_name"') && form.includes("'single_spec_name', this.formData.single_spec_name")],
  ['project reservation defaults are store service and buy-first-book-later', model.includes('reservation_type:2') && model.includes('reservation_timing_type:3')],
  ['project reservation mode and timing controls are hidden', !reservation.includes('预约模式：') && !reservation.includes('预约时机：')],
  ['retail stock synchronization defaults off', model.includes('is_sync_stock: 0')],
  ['retail products default to store pickup and hide logistics navigation', model.includes("delivery_type: ['2']") && !form.includes("{ title: '物流设置', name: '4' }")],
  ['retail product source selection is hidden', !productBase.includes('label="商品来源："') && !productBase.includes('label="供应商："')],
  ['rule change requires confirmation when projects exist', form.includes("title: '确认切换卡项规则？'") && form.includes("if (!this.cardData.length)")],
  ['confirmed rule change clears all card settings', [
    'this.cardData = []',
    "'card_choice_limit', 0",
    "'card_shared_times', 0",
    "'write_valid', 0",
    "'days', 0",
    "'section_time', []",
  ].every((fragment) => form.includes(fragment))],
  ['new rule fields are serialized', ['card_rule_type', 'card_rule_version', 'card_choice_limit', 'card_shared_times'].every((field) => form.includes(`'${field}'`))],
  ['new rule fields have deterministic defaults', [
    "card_rule_type: 'normal'",
    'card_rule_version: 0',
    'card_choice_limit: 0',
    'card_shared_times: 0',
  ].every((fragment) => model.includes(fragment))],
  ['product list exposes card-rule filter and visible column', [
    'v-model="artFrom.card_rule_type"',
    '{ key: "card_rule_type", title: "卡项规则", minWidth: 110 }',
    '{ key: "card_rule_type", show: true }',
  ].every((fragment) => list.includes(fragment))],
  ['product list maps only confirmed rule names and leaves old blanks empty', [
    'if (Number(row.product_type) !== 5 || !row.card_rule_type) return "—"',
    '{ label: "任选次数卡", value: "choice_count" }',
  ].every((fragment) => list.includes(fragment)) && !list.includes('共享卡')],
  ['product detail renders rule-specific parameters and validity', [
    "choice_kind: '任选种数卡'",
    "choice_count: '任选次数卡'",
    "time: '时间卡'",
    'cardValidityText()',
    "title: '单次核销金额'",
    "'选中后可使用次数'",
  ].every((fragment) => details.includes(fragment))],
  ['copy mode keeps rule fields in the existing full-form copy projection', [
    'Object.keys(this.formData)',
    "this.$set(formData, 'card_rule_type'",
    "this.$set(formData, 'card_choice_limit'",
    "this.$set(formData, 'card_shared_times'",
    'writeoff_amount: Number(item.writeoff_amount || 0)',
  ].every((fragment) => form.includes(fragment))],
  ['card configuration opens the project-only selector', form.includes('title="项目列表"') && form.includes(':chooseType="97"')],
  ['project-only selector hides its type filter and locks project type', [
    '[94, 95, 96, 97].includes(Number(this.chooseType))',
    'ct === 96 || ct === 97',
    'this.formValidate.product_type = 6',
  ].every((fragment) => selector.includes(fragment))],
  ['card project selector omits the legacy pickup-only request filter', [
    'this.isCard && Number(this.chooseType) !== 97',
    'this.formValidate.is_card = 0',
  ].every((fragment) => selector.includes(fragment))],
]

let failed = 0
for (const [name, passed] of checks) {
  if (passed) console.log(`PASS ${name}`)
  else {
    failed += 1
    console.error(`FAIL ${name}`)
  }
}

console.log(`PLATFORM_CARD_RULE_FRONTEND passed=${checks.length - failed} failed=${failed}`)
if (failed > 0) process.exit(1)
