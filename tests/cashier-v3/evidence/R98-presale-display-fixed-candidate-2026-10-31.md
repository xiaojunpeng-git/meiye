# R98 售前显示开关固定候选（2026-10-31）

- 开发起点 HEAD：`fcaf4f58a3126dfcce4b3ec74d55c8f4981d4044`；固定时 HEAD：`f48015f4ddfdda135cb032f9117d8df9481ba349`。同一共享正本有其他任务并行提交和大量已暂存、未暂存改动；本轮不得整文件暂存或顺带提交。
- 候选文件清单及各自 SHA-256 如下。清单摘要 SHA-256：`f55d8db3e57d966f07bb730bdd1c9caccab80158f64a47141f4f1c7b5217a84c`。以上指纹对应当前工作树内容，提交时仍须仅隔离本轮增量并重新核对。
- 线上目标实例、现网版本、远程备份：不适用（仅本地开发及本地瑞昊数据库配置定义）。

```text
377edb73b5e9c55b53553bc19fc0831a3638e321c5c1690c20bfe6c84d5b31b5  前端代码/cashier-v3/src/components/cashier/PersonnelPerformanceOverlay.vue
27885b4025322721fa884d2a0834e97b504c10f0803107e691826886ac0efcb8  前端代码/cashier-v3/src/components/member/RechargeOverlay.vue
5a148c10c3debe6c1513408586a8117f310c6035df176a27addc9af6cae297a5  前端代码/cashier-v3/src/components/member/MemberDebtOverlay.vue
69c667db015f7441f7c476168776b52cb114fab24227eea16d2cbf1b222a5333  前端代码/cashier-v3/src/layouts/CashierShell.vue
75256b495532b339ded9ed01cb8ba05e5204c4808484f6431eb33f19eed48114  前端代码/cashier-v3/src/views/CashierWorkbenchView.vue
2150b53159495a384b492b3fb607c5facd9bd0afdb067b3f04f6b6cc7e142b7d  前端代码/cashier-v3/src/views/OrderCenterView.vue
1cef2afc3622849a24aae39d1730ca4a1063da3ce2ac2e18da09232ce8a9180b  前端代码/cashier-v3/src/services/cashierV3Bridge.js
9ddf012c5d2d5235e7e874b9046d98b7f9542784195d03de87189461cbdcb188  前端代码/cashier-v3/src/styles/base.css
d9478f2e97f75c5047d90c421e3186502d74bd11676fb3b74d1ab82d82275003  后端代码/app/services/system/config/SystemConfigServices.php
798d952c466528bc4dc03050971935243317eebb888fb54f547b8c647fef7049  后端代码/app/services/cashier/v3/projection/CashierV3RootDomainAssembler.php
77c257384c5cdc20b49dff3f136ee3a0b8ca12ec8c5420567070e796a71dc470  后端代码/app/services/cashier/v3/projection/CashierV3RootStateContract.php
aee70892d5033fd7cba0cbc52952b1032ce0376ddceb811e97c9bee4fe1bcd9b  后端代码/database/upgrades/20261031_r98_cashier_presale_display_switch.sql
3c47e6668a75f8b6e742ef544b7ca63087bc7dc2076f553880bc59cab645919e  tests/cashier-v3/js/r98-presale-display.mjs
```

## 本轮新增/修复

- 平台系统设置“门店设置”中，在“销售经理/导购”上方新增“售前”开关，默认开启；配置迁移幂等且不覆盖既有值。
- 根投影及前端桥接下发 `preSaleEnabled`；缺省开启。收银、订单中心业务分配弹窗关闭时隐藏简易选择“售前”和完整分配“是否售前”列，表格列数同步调整；充值与欠款补交的复用弹窗也保持一致。
- 售前标记属于原销售人快照。关闭仅影响展示和新的勾选操作；已选人员、比例、金额及历史售前标记不清空、不改算法。

## 已验收既有功能与隔离

- 保留现有手艺人“点客”、销售人分配、手工费模式、订单中心退款等已上线行为；R37 劳动业绩显隐回归通过。
- 相关文件已有其他任务的暂存和未暂存改动，本轮未将其称为本轮成果。无远程部署、无 push、无真实结账、无历史业务数据修改。

## 自测

- `node tests/cashier-v3/js/r98-presale-display.mjs`：简易/完整两模式开关两态真实 Vue 渲染、确认快照一致、历史售前标记保留，以及收银和订单中心模板编译，通过。
- `node tests/cashier-v3/js/r37-labor-fee-display.mjs`：既有分配/手工费回归通过。
- `npm run build`（`前端代码/cashier-v3`）：预构建权益快照 70 项和 Vite 构建通过；主 JS `index-4cf43251.js`，CSS `index-4d34dae1.css`。
- PHP 7.4 三处后端文件语法通过；本地瑞昊数据库迁移连续执行两次仅 1 条配置定义，默认值 1。配置表单顺序及 0/1 读取经事务检查，测试值已回滚，本地最终仍为 1。
- `git diff --check` 通过。浏览器自动化入口本次返回 `browser.user is unavailable`，因此未声称完成浏览器登录态人工点击验收；组件实际渲染和本地配置读写已覆盖关键显隐与数据保留。

## 交付分类

- 前端源码：清单前 8 项。
- 后端源码：清单第 9 至 11 项。
- 数据库迁移：清单第 12 项，仅本地瑞昊已执行。
- 自动化测试：清单第 13 项。
- 工程脚本：无。生成物：`前端代码/cashier-v3/dist/`，不纳入源码提交。
- 本轮注释已补充于配置、根投影、桥接、业务分配组件及两层复用弹窗，并说明显隐与历史快照边界。

状态：开发与自测完成，待产品经理对以上固定候选确认验收；未提交、未部署。
