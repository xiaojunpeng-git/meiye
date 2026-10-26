/** Execute the real H5 entry/detail methods without a browser or customer data.
 * This is a component contract, not a claim of online business acceptance.
 */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, '前端代码/uniapp/pages/index/index.vue'), 'utf8');
const begin = source.indexOf('entryStore(num){');
const end = source.indexOf('changeLogin() {', begin);
assert(begin > 0 && end > begin, 'Canonical H5 method boundaries changed');
const methods = source.slice(begin, end);
const script = path.join(root, 'scripts/import/customer_008_store_display.php');
const rules = JSON.parse(execFileSync('php', ['-r', 'require $argv[1];echo sd008Json(sd008Rules());', script], { encoding: 'utf8' }));

async function scenario(entryRules, id, name, alone = 0) {
  const storage = new Map();
  const errors = [];
  const calls = [];
  const uni = { setStorageSync: (key, value) => storage.set(key, value) };
  const entry = async () => ({ data: { store_id: id, shop_operation_type: 2, user_entry_rules: entryRules, user_entry_name: 'user_param_store' } });
  const detail = async data => {
    calls.push(data);
    return { data: { info: { id, name, is_alone: alone } } };
  };
  const real = new Function('getEntryStore', 'getnearbyStore', 'uni', `return ({${methods}});`)(entry, detail, uni);
  const vm = {
    ...real, entryData: {}, storeInfor: { storeId: 0, storeName: '' }, belongIndex: 0,
    isBelongStore: false, isLogin: false,
    $util: { getCache: () => id, Tips: error => errors.push(error) },
    SET_NEARBY: value => storage.set('nearby', value),
  };
  vm.entryStore();
  await new Promise(resolve => setImmediate(resolve));
  return { vm, calls, errors, storage };
}

(async () => {
  const broken = await scenario(null, 108, 'Synthetic selected store');
  assert.equal(broken.vm.storeInfor.storeName, '');
  assert.equal(broken.calls.length, 0);
  assert.equal(broken.errors.length, 1, 'Original empty JSON config no longer reproduces');
  for (const [id, name] of [[108, 'Synthetic selected store'], [130, 'Synthetic nearby store']]) {
    const fixed = await scenario(rules, id, name);
    assert.deepEqual(fixed.errors, []);
    assert.equal(fixed.vm.storeInfor.storeName, name);
    assert.equal(fixed.vm.storeInfor.storeId, id);
    assert.deepEqual(fixed.calls, [{ store_id: id }]);
    assert.equal(fixed.storage.get('shop_operation_type'), 2);
    assert.equal(fixed.vm.belongIndex, 1, 'Normal store switching must remain available');
  }
  assert.equal((await scenario(rules, 108, 'Synthetic isolated store', 1)).vm.belongIndex, 0, 'Existing isolated-store restriction regressed');
  console.log('Real H5 methods: reproduce null-rule failure; fixed rules display selected/nearby name, preserve switching, isolated-store restriction and mode: PASS');
})().catch(error => { console.error(error); process.exitCode = 1; });
