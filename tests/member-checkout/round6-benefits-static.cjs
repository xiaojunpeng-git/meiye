const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '../..');
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');
const checkout = read('前端代码/uniapp/pages/goods/member_checkout/index.vue');
const recharge = read('前端代码/uniapp/pages/users/user_payment/index.vue');
const benefits = read('后端代码/app/services/member/MemberCommerceBenefitsServices.php');
const routes = read('后端代码/route/api.php');

// Member purchase exposes existing benefits through one checkout; recharge
// keeps its established amount-selection page and never accepts these assets.
assert.match(checkout, /@click="openCoupons"/);
assert.match(checkout, /<baseDrawer[^>]+couponVisible/);
assert.match(checkout, /积分抵扣/);
assert.match(checkout, /coupon_id:\s*this\.kind === 'purchase'/);
assert.match(checkout, /use_integral:\s*this\.kind === 'purchase'/);
assert.doesNotMatch(recharge, /openCoupons|coupon_id|use_integral|积分抵扣/);

// Coupon consumption remains in cashier V3, while points retain a product-line
// allocation for a future store view without adding a cashier UI entry now.
assert.match(benefits, /couponUserIds/);
assert.match(benefits, /integralAllocations/);
assert.match(benefits, /salesOrderLineId/);
assert.match(benefits, /cashier_v3_sales_order_line/);
assert.match(benefits, /\$ratio === '' \? 0 : self::moneyCents\(\$ratio\)/);
assert.match(routes, /member\/commerce\/(detail|pay|cancel)\/:id/);
assert.match(routes, /\[A-Za-z0-9-\]\+/);

process.stdout.write('PASS round6 purchase coupon and integral static contract\n');
