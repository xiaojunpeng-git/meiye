# R43 会员赠送/预售展示本地自测

- 基线 HEAD：`462fa3e57032727c855bb5df41f74b787397d977`。
- 固定源码/测试清单指纹（下列文件 SHA-256 行按顺序拼接后再求 SHA-256）：`69bb471f57fc0852df39d3b81cd9ebd8d018e239f66e88c101fe256ddc32f16d`。
- 状态：本地开发与自测完成；待产品经理对该固定版本确认验收。未 commit、未部署、未 push、未连接或修改线上数据库。

## 范围与权威数据

- 前端源码：`前端代码/cashier-v3/src/components/member/MemberDetailOverlay.vue`、`前端代码/cashier-v3/src/layouts/CashierShell.vue`。
- 后端源码：`后端代码/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php`。
- 数据库迁移：无。自动化测试：`tests/cashier-v3/js/member-detail-v2-contract.mjs`、`tests/cashier-v3/js/member-gift-presale-query-contract.mjs`、`tests/cashier-v3/php/member-detail-v2-contract.php`、`tests/cashier-v3/php/member-gift-presale-mysql-integration.php`。
- 工程脚本：无。生成物：本地 Vite `dist/`（不作为源码或提交文件）。验收证据：本记录。
- 赠送事实继续来自 `cashier_v3_gift_fact`；迁入项目赠送只读已支付、未删除的会员赠送订单明细；商品赠送和预售只读会员可领用行。后端仍按会员、租户及门店数据权限裁剪，并按原记录剩余数量与状态投影，不反写权益、库存、订单或销售事实；同一正式商品赠送的事实与领用投影只展示一次。
- “有效”跨业务日期读取；切到“全部”后显示日期控件并按所选业务日期筛选。仅改此页签标题为“赠送/预售”，未改其他页签。

## 固定文件 SHA-256

| 文件 | SHA-256 |
|---|---|
| `前端代码/cashier-v3/src/components/member/MemberDetailOverlay.vue` | `413108ee762b1ab55046e71d69af7a2c78c4f4a19061a97a7ed7f27910eb525b` |
| `前端代码/cashier-v3/src/layouts/CashierShell.vue` | `bc838e1a8d5e7143d8914693fc7c7a1ccdf7865d8dffd495b748228014f11532` |
| `后端代码/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php` | `d95088bf09762044a9d8894e115f4aa623f628ccf7a528e1fb08c3db7769ee3d` |
| `tests/cashier-v3/js/member-detail-v2-contract.mjs` | `81c9d6f2636852d76b278e6bbfe6f6354855ecf9b5d32fb0a79e1fc4e2646d09` |
| `tests/cashier-v3/js/member-gift-presale-query-contract.mjs` | `5c163eda3cf6720e7b4c433733d762d3954c77e4d5094ceda223e92a4e229ae4` |
| `tests/cashier-v3/php/member-detail-v2-contract.php` | `0b9dc40ad099247707e2a5b159fa5135900991d34d51c4d5775929d8add8d180` |
| `tests/cashier-v3/php/member-gift-presale-mysql-integration.php` | `5a669dab4936cc08b797096b0c4e9b799784aa864c05859d84b5106aed95aedf` |

## 验证

1. `php -l` 后端服务与新增集成测试：无语法错误。
2. `node tests/cashier-v3/js/member-gift-presale-query-contract.mjs`：通过；`node tests/cashier-v3/js/member-detail-v2-contract.mjs`：通过；`php tests/cashier-v3/php/member-detail-v2-contract.php`：13/13 通过。
3. 门店端源码目录 `npm run build`：Vite 构建通过；这是本地源码构建，不是 8080 集成包。
4. 本机 `ruihao` 测试库（非瑞昊线上库）的只读会员样本：迁入项目赠送 4 条、商品赠送 10 条、预售 2 条。`member-gift-presale-mysql-integration.php` 验证历史有效记录忽略当天日期、三类记录均出现、全部历史按日期过滤、身份不重复，以及正式商品赠送事实与领用投影不重复，7/7 通过。
5. `git diff --check` 通过。工作区其他已有改动均未纳入本闭环。

## 注释与限制

- 本次在两个前端源码文件和会员详情后端服务中补充/更新了默认筛选、迁入数据只读合并、门店权限及去重的业务边界注释。
- 页面尚未取得产品经理对本固定版本的验收，也未在线上验证；本地浏览器未能完成目标会员的真实页面点击复测，不能把契约/构建测试冒充页面验收。待验收时应在 18091 会员详情进入“赠送/预售”，确认默认“有效”无日期且三类记录可见，切换“全部”出现日期区。
