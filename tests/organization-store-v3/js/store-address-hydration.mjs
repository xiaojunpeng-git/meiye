import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

// 执行组件真实异步回显方法，验证级联树、用户选址优先及门店切换隔离。
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const source = fs.readFileSync(path.join(root, '前端代码/admin/src/pages/store/addStore/index.vue'), 'utf8')
const extract = (start, end, name, args = [], values = []) => {
  const first = source.indexOf(start)
  const last = source.indexOf(end, first)
  assert.ok(first >= 0 && last > first)
  return new Function(...args, `return ({${source.slice(first, last)}}).${name}`)(...values)
}
const calls = []
const levels = { 1: [{ value: 2, label: '扬州市', children: [] }],
  2: [{ value: 3, label: '邗江区', children: [] }], 3: [{ value: 4, label: '竹西街道' }] }
let request = async ({ pid }) => { calls.push(pid); return { data: levels[pid] } }
const hydrate = extract('async hydrateAddressOptions(', 'loadData(item, callback)', 'hydrateAddressOptions',
  ['cityApi'], [(data) => request(data)])
const roots = [{ value: 1, label: '江苏省', children: [] }]
const errors = []
const state = { addressHydrationVersion: 0, addressRootsPromise: Promise.resolve(roots), addresData: roots,
  formItem: { address: '江苏省扬州市邗江区竹西街道', detailed_address: '某路1号', addressSelect: [] },
  $Message: { error: (msg) => errors.push(msg) } }
await hydrate.call(state, ['1', '2', '3', '4'])
assert.deepEqual(state.formItem.addressSelect, [1, 2, 3, 4])
assert.equal(state.addresData[0].children[0].children[0].children[0].label, '竹西街道')
assert.deepEqual(calls, [1, 2, 3])
assert.deepEqual(roots[0].children, [])
assert.equal(state.formItem.detailed_address, '某路1号')
console.log('PASS 已选路径逐级加载，统一数字 ID，不污染根字典或地址文字')

let finish
request = () => new Promise(resolve => { finish = resolve })
const pending = hydrate.call(state, [1, 2])
await Promise.resolve()
const changed = extract('addchack(e,selectedData)', 'cityInfo(data)', 'addchack')
changed.call(state, [9], [{ label: '用户新选地区' }])
finish({ data: levels[1] })
await pending
assert.deepEqual(state.formItem.addressSelect, [9])
assert.equal(state.formItem.address, '用户新选地区')
console.log('PASS 用户改选后，旧自动回显结果失效')

const old = hydrate.call(state, [1, 2])
await Promise.resolve()
state.addressHydrationVersion++
state.formItem.addressSelect = []
finish({ data: levels[1] })
await old
assert.deepEqual(state.formItem.addressSelect, [])
console.log('PASS 切换门店或新建使旧路径失效')

request = async () => { throw { msg: '测试网络失败' } }
const original = JSON.parse(JSON.stringify(state.formItem))
await hydrate.call(state, [1, 2])
assert.deepEqual(state.formItem, original)
assert.deepEqual(errors, ['测试网络失败'])
console.log('PASS 网络失败不清空地址、不触发保存')

await hydrate.call(state, [999])
assert.deepEqual(state.formItem, original)
await hydrate.call(state, [])
assert.deepEqual(state.formItem.addressSelect, [])
assert.equal(state.formItem.address, original.address)
console.log('PASS 未知 ID 或空地址不猜测，不覆盖原文')

const details = new Map()
const getInfo = extract('getInfo (id)', '// 选择图片', 'getInfo', ['storeGetInfoApi'],
  [id => new Promise(resolve => details.set(id, resolve))])
const applied = []
state.loadedRouteStoreId = '179'
state.applyStoreInfo = (info, id) => applied.push(id)
getInfo.call(state, '179')
state.loadedRouteStoreId = '177'
getInfo.call(state, '177')
details.get('177')({ data: { info: {} } })
await Promise.resolve()
details.get('179')({ data: { info: {} } })
await Promise.resolve()
assert.deepEqual(applied, ['177'])
console.log('PASS 旧门店详情晚到不覆盖新门店')

const listSource = fs.readFileSync(path.join(root, '前端代码/admin/src/pages/store/storeList/index.vue'), 'utf8')
const listMethod = listSource.slice(listSource.indexOf('    storeFullAddress(row)'), listSource.indexOf('\n    },', listSource.indexOf('    storeFullAddress(row)')) + 7)
const fullAddress = new Function(`return ({${listMethod}}).storeFullAddress`)()
assert.equal(fullAddress({ address: '江苏省扬州市', detailed_address: '某路1号' }), '江苏省扬州市某路1号')
assert.equal(fullAddress({ address: '江苏省扬州市某路1号', detailed_address: '' }), '江苏省扬州市某路1号')
assert.equal(fullAddress({ address: '江苏省扬州市某路1号', detailed_address: '某路1号' }), '江苏省扬州市某路1号')
assert.equal(fullAddress({ address: '', detailed_address: '某路1号' }), '某路1号')
assert.equal(fullAddress({}), '')
console.log('PASS 列表保持完整地址，兼容旧数据且不重复门牌')
