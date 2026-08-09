#!/usr/bin/env node

import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const repo = path.resolve(__dirname, '../../..')
const toolbarPath = path.join(repo, '前端代码/shared/unified-query-vue3/src/components/UnifiedQueryToolbar.vue')
const stylesPath = path.join(repo, '前端代码/shared/unified-query-vue3/src/styles/unified-query.css')
const toolbar = fs.readFileSync(toolbarPath, 'utf8')
const styles = fs.readFileSync(stylesPath, 'utf8')

function check(message, condition) {
  assert.ok(condition, message)
  console.log(`PASS ${message}`)
}

const primaryStart = toolbar.indexOf('<div class="unified-query-toolbar__primary">')
const primaryEnd = toolbar.indexOf('\n      </div>\n      <div v-if="$slots[\'context-actions\']"', primaryStart)
const contextSlot = toolbar.indexOf('<slot name="context-actions" />')

check('保留 primary-actions 插槽兼容既有页面', toolbar.includes('<slot name="primary-actions" />'))
check('context-actions 仅在调用方提供时渲染', toolbar.includes('v-if="$slots[\'context-actions\']"'))
check('context-actions 位于 primary 滚动条外', primaryStart >= 0 && primaryEnd > primaryStart && contextSlot > primaryEnd)
check('数量范围输入仍使用 number 类型', (toolbar.match(/type="number"/g) || []).length >= 2)
check('primary 保持横向滚动并垂直裁切', /\.unified-query-toolbar__primary\s*\{[\s\S]*?overflow-x:\s*auto;[\s\S]*?overflow-y:\s*hidden;/.test(styles))
check('context-actions 是 primary 的独立同级布局区', /\.unified-query-toolbar__context-actions\s*\{[\s\S]*?display:\s*flex;/.test(styles))
check('WebKit 数字输入微调按钮已隐藏', /input\[type='number'\]::\-webkit-inner-spin-button,[\s\S]*?input\[type='number'\]::\-webkit-outer-spin-button[\s\S]*?\-webkit-appearance:\s*none;/.test(styles))
check('Firefox 数字输入微调按钮已隐藏', /input\[type='number'\]\s*\{[\s\S]*?\-moz-appearance:\s*textfield;/.test(styles))
