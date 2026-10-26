import assert from 'node:assert/strict'
import {
  readRecentCashierMemberIds,
  recentCashierMemberKey,
  rememberRecentCashierMember
} from '../../../前端代码/cashier-v3/src/services/recentCashierMembers.js'

const entries = new Map()
const storage = {
  getItem: (key) => entries.get(key) ?? null,
  setItem: (key, value) => entries.set(key, value)
}
const key = recentCashierMemberKey('cashier-a', 17)
assert.ok(key)
assert.notEqual(key, recentCashierMemberKey('cashier-b', 17))
assert.notEqual(key, recentCashierMemberKey('cashier-a', 18))
assert.equal(recentCashierMemberKey('', 17), '')
assert.equal(recentCashierMemberKey('cashier-a', 0), '')

for (let id = 1; id <= 11; id += 1) rememberRecentCashierMember(storage, key, id)
assert.deepEqual(readRecentCashierMemberIds(storage, key),
  ['11', '10', '9', '8', '7', '6', '5', '4', '3', '2'])
rememberRecentCashierMember(storage, key, 4)
assert.deepEqual(readRecentCashierMemberIds(storage, key),
  ['4', '11', '10', '9', '8', '7', '6', '5', '3', '2'])
rememberRecentCashierMember(storage, key, 0)
assert.equal(readRecentCashierMemberIds(storage, key).length, 10)

entries.set(key, '{broken')
assert.deepEqual(readRecentCashierMemberIds(storage, key), [])
entries.set(key, JSON.stringify(['2', '2', 'bad', '3']))
assert.deepEqual(readRecentCashierMemberIds(storage, key), ['2', '3'])
assert.deepEqual(readRecentCashierMemberIds(storage, recentCashierMemberKey('cashier-b', 17)), [])
console.log('recent cashier member history: pass')
