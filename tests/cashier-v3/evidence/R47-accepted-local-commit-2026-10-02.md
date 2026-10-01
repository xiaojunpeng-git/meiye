# R47 零本金赠金充值与已作废充值筛选——产品经理验收交接

产品经理于 2026-10-02 对本轮本地版本回复“我测试没有问题,commit”，授权按项目规则本地提交；未授权部署或 push。

基线 HEAD：`9f247529e23dd7acf43915b0dba0d4f35accdf70`。本闭环已跟踪文件补丁 SHA-256：`6bbfa67772fe0c38d8751267e3b5200dfd0bbb78bded492b1f49c0013f2c2e60`；新增合同测试另由完整文件哈希固定。

| 分组 | 文件与 SHA-256 |
| --- | --- |
| 前端源码 | `RechargeOverlay.vue` `ee74366c5c6a93d3985c5b33a7caf69f6f4cc4eb6fdf995e400889fe06d54e85`；`useRechargeCheckout.js` `2e0b9add786cad132f93e6007245d7e9eb2b4d687dd789d03db6c2154d0164e1` |
| 后端源码 | `CashierV3RechargeCheckoutModule.php` `8acd1f268c81e289add98e97f1c9d07acd8e0a22a6c7b60ffd08b5c0916c7d30`；`CashierV3RechargeModule.php` `028f5d6498b9cbf83833ffbd5522c32a3b44894174704edf68bca2d83766af0e`；`CashierV3OrderCenterRecordQueryServices.php` `fec0325dffab36b27b240356e327d75e49e64e07a9ad68c8dafdccfaea03ff20` |
| 自动化测试 | `recharge-overlay-frontend-contract.mjs` `d99a6a7ba7a282fbbb53864002614d7feff9ca5d4e397df3fa19919f9c31a026`；`recharge-checkout-contract.php` `e16f1c793a34b0305ed011e43fb085476f3252bbc76c6b562d3a25da91ec6d34`；`order-center-records-readonly.php` `f20a12f54917e135f42f8af819b1f999048e351879a8a40b8cd4725b1b65a8f3`；`recharge-normal-scope-contract.php` `f6a82383a6289da9c5dec6a9796e83089edc4b1e0081555a2d76a180dca2a859` |
| 迁移、脚本、生成物 | 无 |
| 验收证据 | `R47-zero-principal-recharge-local-selftest-2026-10-01.md`、`R47-local-recharge-sequence-2026-10-01.md`、`R47-recharge-normal-scope-selftest-2026-10-01.md`、本记录 |

本次复测：充值前端合同 38 PASS；充值后端合同 9 PASS；订单中心只读合同 38 PASS；正常范围合同 PASS；PHP 语法和 `git diff --check` PASS。真实本地数据：0＋300、银联收款 1000＋100、0＋200 后作废，订单和逆向事实均保留；默认“正常”只返回前两笔，“全部”返回三笔，“已作废”只返回第三笔，最终余额本金 1000 元、赠金 400 元。

数据架构边界：充值主单与成功的追加式生命周期操作仍是状态权威，收款/余额事实与冲销关系不改写；门店权限由后端收紧，默认正常筛选在分页与计数之前完成。无迁移、历史回填、外部真实扣款。余额事实来源字段沿用既存空值缺口，未在本闭环扩大修复；浏览器完整点击路径由本次产品经理测试确认，本地命令与数据库事实核对另见上述自测记录。

代码注释：本轮更新 `RechargeOverlay.vue`、`useRechargeCheckout.js`、`CashierV3RechargeCheckoutModule.php`、`CashierV3RechargeModule.php`、`CashierV3OrderCenterRecordQueryServices.php` 的零本金、备注、无收款和正常范围边界说明；`order-center-records-readonly.php` 的测试口径注释同步更新。
