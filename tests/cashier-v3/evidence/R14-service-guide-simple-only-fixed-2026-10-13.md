# R14 纯权益导购更正简易模式固定版本

- 基线 HEAD：`fe9ba5d012d3df3e626a19f2c7d1615e630e0404`。本次两处前端改动的 `git diff --binary` SHA-256：`38e0fa015576f9896ec815c40f01648fac10db571d9263d198918c88c8ea20eb`。
- 本文件补充 [R14 导购与轮次更正本地自测](R14-service-guide-correction-local-2026-10-13.md)；仅纯权益消费订单的导购更正弹窗固定为简易选择并隐藏分配模式切换。普通销售订单及收银分配入口不变。没有改动导购事实、轮次或资金口径。
- 本地浏览器：江苏金湖桓裕店订单中心打开 `FW101300009`，重开后显示简易选择、查询导购及第 1～3 轮控件，页面中不存在分配模式切换；同页普通销售订单仍进入完整分配并保留切换。此次仅打开和取消弹窗，没有提交任何业务更正。截图 [R14-service-guide-simple-only-2026-10-13.png](R14-service-guide-simple-only-2026-10-13.png)，SHA-256 `8273392d32c5b462a1975bcb33dce9f1678baeaa5c749fc07c9eaf0f6293a112`。
- 验证：`npm run build` 通过（Vite 2001 个模块）；`node tests/cashier-v3/js/personnel-performance-overlay-contract.mjs` 通过；两处前端源码 `git diff --check` 通过。原 R14 业务更正自测与服务端回归证据见上述文档。
- 注释：`PersonnelPerformanceOverlay.vue` 新增固定简易模式的入口边界说明；`OrderCenterView.vue` 原有纯权益服务事实、更正审计注释保持有效。
- 文件分类：前端源码为下列清单的前六项（含本次修改的人员分配组件）；后端源码为其余六项；数据库迁移、自动化测试源码、工程脚本均无本次新增；生成物仅本地 `dist/`；验收证据为本文件与截图。
- 阶段：本地开发和自测完成，待产品经理确认此固定版本；未 commit、未部署、未 push。

## 固定版本源码 SHA-256

```text
f7630e310e2fb0cf770123913be9ad9b6ab37a530fc3eee9f1ad4be1038aa2d5  前端代码/cashier-v3/src/components/cashier/PersonnelPerformanceOverlay.vue
6c65cfedcb9dfc9ee46a261e4029e4e4c26a36c91aadf05268aebe3c1c29e08c  前端代码/cashier-v3/src/views/OrderCenterView.vue
e9f9a358e72b5db5bcf9c1418443db111903914e5fd95832d69a03d177d3f3a7  前端代码/cashier-v3/src/components/order/SalesOrderDetailOverlay.vue
2c0ca4f139b67622af2e042b5dd2495fa7961d05c5fc3cc15dd5b9fbfb14c028  前端代码/cashier-v3/src/components/order/BusinessRecordDetailOverlay.vue
18e2f14b5df0f705d6a7c8f20ef182bb4e7569226a989f79d432242475b8bac3  前端代码/cashier-v3/src/services/cashierV3ActionManifest.js
8f8338aee27eecca14f04d367efb9dac3a813091404c931d24efec27faaf7f03  前端代码/cashier-v3/src/services/cashierV3Bridge.js
7f11a2927a76c4d52d85ead0791a17135b2dbb75c5b940b37ed0d77b7a5981f3  后端代码/app/services/cashier/v3/order/CashierV3ServiceGuideCorrectionServices.php
d802e5c767fbeff3437396d52ce08500566c2821a87a93203b331e1ea6b87f54  后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleModule.php
ab3611a0a479fa5bbdf69019e0824c87bd9c52cc8dd842295001a334a948ff4a  后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php
963db3b4b380cf02ff8b22a41b2c5a7c84e4217894f6059842244574d43e91dc  后端代码/app/services/cashier/v3/report/CashierV3GuideRoundFactServices.php
6e2f9ba4b40cf592937616cc5e6bd2921dad0243fe091e9c7bfc3707dd86962d  后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php
304a039aff4b302b24390b5b86ebb72541bdd5a4345bad27492814ef5ab5d6c6  后端代码/app/services/cashier/v3/manifest/CashierV3C5MemberOrderModule.php
```
