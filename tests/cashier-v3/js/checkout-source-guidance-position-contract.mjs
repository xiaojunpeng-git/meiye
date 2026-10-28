#!/usr/bin/env node
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const dirname = path.dirname(fileURLToPath(import.meta.url))
const repo = path.resolve(dirname, '../../..')
const styles = fs.readFileSync(path.join(repo, '前端代码/cashier-v3/src/styles/base.css'), 'utf8')

// 来源必填提示必须锚定来源按钮；否则绝对定位会落到工作台底部，用户会误以为收款按钮失效。
const sourceButtonRules = [...styles.matchAll(/\.cashier-workflow-context-source\s*\{([\s\S]*?)\}/g)]
  .map((match) => match[1])
const requiredRule = styles.match(/\.cashier-workflow-context-source--required\s*\{([\s\S]*?)\}/)?.[1] || ''

if (!sourceButtonRules.some((rule) => /position:\s*relative;/.test(rule) && /overflow:\s*visible;/.test(rule))) {
  throw new Error('来源按钮必须作为必填箭头的可见定位容器')
}
if (!/z-index:\s*20;/.test(requiredRule)) {
  throw new Error('来源必填状态必须显示在收银工作台上方')
}

console.log('CHECKOUT_SOURCE_GUIDANCE_POSITION_CONTRACT=PASS')
