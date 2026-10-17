import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'

const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const admin = path.resolve(process.env.ADMIN_ROOT || path.join(repo, '前端代码/admin'))
const requireAdmin = createRequire(path.join(admin, 'package.json'))
const { parseComponent, compile } = requireAdmin('vue-template-compiler')
const read = relative => fs.readFileSync(path.join(admin, relative), 'utf8')

const base = parseComponent(read('src/pages/setting/shop/base.vue'))
const hidden = parseComponent(read('src/pages/setting/setSystem/index.vue'))
const route = read('src/router/modules/setting.js')
const controller = fs.readFileSync(path.join(repo, '后端代码/app/controller/admin/v1/system/DealPeopleThresholdPolicy.php'), 'utf8')

// The live menu enters shop/base; the versioned threshold must live there, not only on a hidden route.
assert.match(route, /path: 'shop\/base',[\s\S]*?name: `\$\{pre\}shop_base`/)
assert.match(base.template.content, /aria-label="成交人数门槛"/)
assert.match(base.script.content, /getDealPeopleThreshold\(\)/)
assert.match(base.script.content, /updateDealPeopleThreshold\(value\)/)
assert.match(base.script.content, /!this\.dealPeopleLoaded/)
assert.doesNotMatch(hidden.template.content, /成交人数门槛/)
assert.match(controller, /\['setting-shop-base', 'setting-system-config'\]/)
for (const [name, component] of [['shop/base', base], ['setSystem', hidden]]) {
  assert.deepEqual(compile(component.template.content).errors, [], `${name} template must compile`)
}

console.log('PASS deal-people threshold is reachable from the visible system settings menu')
