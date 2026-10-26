import assert from 'node:assert/strict';
import fs from 'node:fs';

const cartSource = fs.readFileSync(
  new URL('../../后端代码/app/services/order/StoreCartServices.php', import.meta.url),
  'utf8',
);
const combinationSource = fs.readFileSync(
  new URL('../../后端代码/app/services/activity/combination/StoreCombinationServices.php', import.meta.url),
  'utf8',
);
const orderDaoSource = fs.readFileSync(
  new URL('../../后端代码/app/dao/order/StoreOrderDao.php', import.meta.url),
  'utf8',
);

// 拼团进入活动专用校验前不能再跑底层商品的跨活动限购。
assert.match(
  cartSource,
  /if \(\$isSet >= 0 && \$type !== 3\)\s*\{\s*\$this->checkLimit\(/s,
  '拼团应跳过普通商品全局限购',
);

// 当前活动的限购仍按 combinationId 查询，不能因为隔离活动而放开本活动额度。
assert.match(
  combinationSource,
  /getBuyCount\(\$uid, 3, \$combinationId\)/,
  '拼团限购应以当前活动 ID 为边界',
);
assert.match(
  combinationSource,
  /\$StoreCombinationInfo\['num'\] < \(\$userBuyCount \+ \$cartNum\)/,
  '当前活动总限购必须保留',
);

// 订单侧计数同时约束 type=3 和 activity_id，确保同商品的其他活动不被累加。
assert.match(orderDaoSource, /where\('type', \$type\)/);
assert.match(orderDaoSource, /where\('activity_id', \$activity_id\)/);

const boughtByActivity = new Map([
  [101, 1],
  [202, 0],
]);
const canBuy = (activityId, activityLimit, quantity) =>
  (boughtByActivity.get(activityId) ?? 0) + quantity <= activityLimit;

assert.equal(canBuy(202, 1, 1), true, '同商品新活动的首单应可购买');
assert.equal(canBuy(101, 1, 1), false, '同一活动达到限购后仍应拦截');

console.log('PASS combination-r68 activity limit isolation');
