import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { spawnSync, execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// Local-only verification driver. It never commits, contacts a payment gateway,
// changes a retained order, or connects to a customer instance.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const hash = value => createHash('sha256').update(value).digest('hex');
const groups = {
  frontend: [
    '前端代码/admin/src/pages/setting/shop/base.vue',
    '前端代码/uniapp/api/memberCommerce.js',
    '前端代码/uniapp/components/memberCommerceOrders/index.vue',
    '前端代码/uniapp/pages/goods/member_checkout/index.vue',
    '前端代码/uniapp/pages/goods/order_confirm/index.vue',
    '前端代码/uniapp/pages/goods/order_list/index.vue',
    '前端代码/uniapp/pages/goods_details/index.vue',
    '前端代码/uniapp/pages/users/user_payment/index.vue',
    '前端代码/uniapp/pages.json'
  ],
  backend: [
    '后端代码/app/controller/admin/v1/system/MemberCommerceSettings.php',
    '后端代码/app/controller/api/v1/order/MemberCommerce.php',
    '后端代码/app/controller/api/v1/order/StoreOrder.php',
    '后端代码/app/controller/api/v1/user/UserRecharge.php',
    '后端代码/app/dao/order/StoreOrderDao.php',
    '后端代码/app/services/order/StoreOrderServices.php',
    ...['MemberCommerceServices','MemberCommerceSettingsServices','MemberCommerceScope',
      'MemberCommerceRechargeSettlement','MemberCommercePaymentServices']
      .map(name => `后端代码/app/services/member/${name}.php`),
    // Purchased projects share one issuer across member checkout and deferred store sales.
    '后端代码/app/services/cashier/v3/settlement/CashierV3PurchasedProjectEntitlementServices.php',
    '后端代码/app/services/cashier/v3/CashierV3BusinessDocumentNumberServices.php',
    '后端代码/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php',
    '后端代码/app/services/cashier/v3/fact/CashierV3SaleOnlyFactAssembler.php',
    '后端代码/app/services/cashier/v3/member/CashierV3RechargeModule.php',
    '后端代码/app/services/cashier/v3/member/CashierV3RechargeGiftIssuanceServices.php',
    '后端代码/app/services/cashier/v3/order/CashierV3SalesOrderReversalServices.php',
    '后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutPreparationServices.php',
    '后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutProjectionServices.php',
    '后端代码/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php',
    '后端代码/app/services/user/UserBalanceAtomicServices.php',
    '后端代码/mohe/services/wechat/HwcPayService.php',
    '后端代码/route/admin.php', '后端代码/route/api.php'
  ],
  migration: ['后端代码/database/upgrades/2026-10-20-会员统一结账/01-schema.sql'],
  tests: ['local-install.php','local-probe.php','local-contract.php','local-boundaries.php',
    'local-acceptance-readonly.php','local-repair-test-card-number.php','verify-local.mjs']
    .map(name => 'tests/member-checkout/' + name)
    .concat('tests/member-fuyou-payment/php/static-contract.php')
};
const evidenceDir = path.join(root, 'tests/member-checkout/evidence');
mkdirSync(evidenceDir, {recursive: true});
function run(name, command, args, options = {}, accept = () => true) {
  const result = spawnSync(command, args, {cwd: root, encoding: 'utf8', ...options});
  const output = (result.stdout || '') + (result.stderr || '');
  writeFileSync(path.join(evidenceDir, name + '.log'), output);
  if (result.status !== 0 || !accept(output)) throw new Error(`${name} failed; see evidence log`);
  process.stdout.write(`PASS ${name}\n`);
  return output;
}
for (const file of groups.backend) {
  const containerPath = '/var/www/html/' + file.slice('后端代码/'.length);
  const result = spawnSync('docker', ['exec','mohe-app','php','-l',containerPath], {cwd: root, encoding: 'utf8'});
  if (result.status !== 0) throw new Error(`${file}: ${result.stdout}${result.stderr}`);
}
process.stdout.write(`PASS PHP container lint (${groups.backend.length} files)\n`);
run('domain-boundaries','docker',['exec','-i','mohe-app','php'],
  {input: readFileSync(path.join(root,'tests/member-checkout/local-boundaries.php'))},
  output => (output.match(/^PASS /gm) || []).length === 8 && !output.includes('FAIL'));
run('fuyou-compatibility','bash',['tests/member-fuyou-payment/run-static.sh']);
run('entitlement-whole-yuan','php',['tests/cashier-v3/php/member-entitlement-whole-yuan-contract.php']);
const retained = JSON.parse(run('retained-data','docker',['exec','-i','mohe-app','php'],
  {input: readFileSync(path.join(root,'tests/member-checkout/local-acceptance-readonly.php'))}, output => output.includes('PASS: two UI purchases')));
run('diff-check','git',['diff','--check','--',...Object.values(groups).flat()]);
const manifest = {
  title: '第三轮：会员统一购买、充值与门店权益核销',
  createdAt: new Date().toISOString(),
  baselineHead: execFileSync('git',['rev-parse','HEAD'],{cwd:root,encoding:'utf8'}).trim(),
  baselineScope: 'Current canonical working tree; unrelated staged/unstaged work is present and not accepted by this verification.',
  remoteBaseline: 'Not applicable: local development and local database only.',
  stagedDiffSha256: hash(execFileSync('git',['diff','--cached','--binary'],{cwd:root})),
  entries: {member:'http://127.0.0.1:8081/pages/goods/order_list/index',
    store:'http://127.0.0.1:18091/view_cashier_v3/#/order-center',
    settings:'http://127.0.0.1:18081/admin/setting/shop/base'},
  files: Object.fromEntries(Object.entries(groups).map(([group, files]) => [group,
    files.map(file => ({path:file,sha256:hash(readFileSync(path.join(root,file)))}))])),
  preservedExistingCapabilities: ['V3 balance ledger and card issuance','store rights redemption and integer amount allocation',
    'historical/activity payment callbacks','existing unpublished worktree changes'],
  retainedAcceptanceData: retained,
  limits: ['No real Fuyou charge or refund was made; online callbacks were tested inside rollback transactions.',
    'No customer payment credentials copied or production configuration/database changed.',
    'Provider unknown attempts retain balance holds; cancellation only releases an intent never sent to provider.',
    'No mini-program or APP device payment validation; H5 local UI is the completed real acceptance path.',
    'No commit, deployment, push, review submission or mini-program upload. PM acceptance remains required.']
};
manifest.patchFingerprint = hash(JSON.stringify(manifest.files));
writeFileSync(path.join(evidenceDir,'verification.json'),JSON.stringify(manifest,null,2)+'\n');
process.stdout.write(`PATCH ${manifest.patchFingerprint}\n`);
