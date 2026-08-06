import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const source = fs.readFileSync(
  path.join(root, '前端代码/cashier-v3/src/views/StoreLoginView.vue'),
  'utf8'
)
const asset = path.join(root, '前端代码/cashier-v3/src/assets/login-beauty-quiet.png')

const assertions = [
  ['confirmed dynamic title is present', source.includes('你亲手雕琢细腻美好，我留存全部暖心操作')],
  ['support footer is present', source.includes('厦门魔核方舟科技有限公司提供支持')],
  ['beauty visual asset is wired', source.includes("url('@/assets/login-beauty-quiet.png')") && fs.existsSync(asset)],
  ['responsive layout prevents horizontal overflow', source.includes('overflow-x: hidden') && source.includes(':global(html:has(.store-login))') && source.includes(':global(body:has(.store-login))') && source.includes('@media (max-width: 460px)')],
  ['reduced motion keeps the title readable', source.includes('@media (prefers-reduced-motion: reduce)')],
  ['login behavior remains wired', source.includes('@submit.prevent="submit"') && source.includes('autocomplete="username"') && source.includes('autocomplete="current-password"')]
]

let failed = 0
for (const [name, passed] of assertions) {
  console.log(`${passed ? 'PASS' : 'FAIL'} ${name}`)
  if (!passed) failed += 1
}

console.log(`\n${assertions.length - failed} passed, ${failed} failed`)
process.exit(failed === 0 ? 0 : 1)
