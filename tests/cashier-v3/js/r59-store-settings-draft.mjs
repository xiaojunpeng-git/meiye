/** Exercise the page's actual DTO adoption with Vue reactive saved state. This
 * protects cancel/reload from DataCloneError and saved/draft aliasing regressions.
 */
import assert from 'node:assert/strict'
import fs from 'node:fs'
import { createRequire } from 'node:module'
const require = createRequire(new URL('../../../前端代码/cashier-v3/package.json', import.meta.url))
const { reactive, ref } = require('vue')
const file = new URL('../../../前端代码/cashier-v3/src/views/StoreSettingsView.vue', import.meta.url)
const source = fs.readFileSync(file, 'utf8')
const adoption = source.match(/function adopt\(data\) \{[\s\S]*?\n\}/)?.[0]
assert.ok(adoption)
const form = reactive({}), loaded = ref(null), navigation = ref(null), state = reactive({stateContextId:'isolated-context'})
const adopt = new Function('loaded','navigation','state','form','hydrateRegions', `let submission; ${adoption}; return adopt`)(loaded,navigation,state,form,()=>{})
const original = {store_id:1,profile:{name:'saved',introduction:'can-clear',address_ids:[1,2,3]}}
adopt(original); form.name='draft'; form.address_ids.push(4)
assert.equal(loaded.value.profile.name,'saved'); assert.deepEqual([...loaded.value.profile.address_ids],[1,2,3])
adopt(loaded.value); assert.equal(form.name,'saved'); assert.deepEqual([...form.address_ids],[1,2,3]); assert.equal(navigation.value.contextId,'isolated-context')
form.introduction=''; assert.equal(loaded.value.profile.introduction,'can-clear')
assert.equal(original.profile.name,'saved')
console.log('R59_STORE_SETTINGS_DRAFT_PASS reactive cancel, deep draft isolation, empty draft clear, context metadata')
