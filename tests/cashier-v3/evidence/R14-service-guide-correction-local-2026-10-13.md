# R14 纯权益订单导购与轮次更正本地自测

- 基线 HEAD：`fe9ba5d012d3df3e626a19f2c7d1615e630e0404`。源码文件 SHA-256 清单指纹：`69017c5394161c659e44cc93278d5343000ed60ced6bf83086b995e43fe21d0d`。仅在本地 `18091` / 江苏金湖桓裕店测试；未提交、未部署、未推送。
- 范围：纯权益消费订单的导购列可修改人员及第 1～3 轮或无轮次；旧值为空可补录。只允许本门店已完成且未作废的服务行。服务端从服务事实及完成回执读取目标，以整个结账请求的有效导购事实 ID 作并发旧值校验；同单轮次一致，跨业务日轮次冲突拒绝。旧事实转 `reversed`，新事实和业务事件生效，导购及轮次分别写更正审计；不改原核销、权益余额或收款。
- 真人路径：本地测试单 `FW101300009` 从朱延芳第1轮同时改为王小薇第2轮，再只改回第1轮，最后只把导购改回朱延芳。刷新列表仍显示朱延芳第1轮；订单详情“变更记录”显示三次操作、四条字段审计。只读数据库核对为三条 `reversed`、一条 `effective`，有效值为朱延芳第1轮，另有三条更正业务事件。
- 空值补录：另建无导购的纯权益测试单 `FW101300010`，订单中心导购列从“—”可打开编辑并补录王小薇第1轮。列表刷新显示新值；数据库审计分别记录 `— → 王小薇` 和 `无轮次 → 第1轮`。该额外测试单随后通过正常作废流程撤销，作废状态 `succeeded`、`restored_quantity=1`；测试痕迹保留以便追溯。补录后、作废前页面截图：`R14-service-guide-correction-browser-2026-10-13.png`，SHA-256 `5418faf75bcfc135ce7f35b4263c04cc698303066beba81e4dbe7131452eaea7`。桌面尺寸复核、作废后状态截图：`R14-service-guide-correction-desktop-2026-10-13.png`，SHA-256 `cd2498be5241bce99925d6781d78944a83d60feebd1633cc9977e216d511ea21`；浏览器尺寸与筛选均已恢复。
- 自动化：新增服务 `php -l` 通过；`guide-round-fact-contract.php` 全通过；`order-center-service-snapshot-read-contract.php` 通过；`c2-checkout-settlement-contract.php` 96/96；收银端 `npm run build` 通过；所涉已跟踪文件 `git diff --check` 通过。PHP 测试有既存 nullable 类型弃用提示，不影响断言结果。
- 注释：新增服务类、旧值与跨行轮次校验、无导购回执补录、权限/事件/审计边界；更新导购历史读取与纯权益列表映射注释，说明权威关联键及日期快照约束。
- 文件分类：前端源码 `OrderCenterView.vue`、两处订单详情组件、前端 action manifest/bridge；后端源码 `CashierV3ServiceGuideCorrectionServices.php`、订单生命周期模块、销售订单查询、导购轮次事实服务、后端 action manifest/C5 模块；数据库迁移无；自动化测试源码无；工程脚本无；生成物仅本地未纳入清单的 `dist/`；验收证据为本文件和上述截图。
- 已知相邻问题：既有“纯权益订单业务日期更正”路径未同步旧导购事实的 `business_date`；本次导购更正写入的新有效事实取当前服务日期，但未在本轮改动既有日期更正链或历史数据。若要支持日期更正后仍按导购事实日期筛选报表，应单独修复并回归该路径。
- 阶段：开发与定向自测完成，待产品经理对本固定版本确认验收；不得据此 commit 或发布。

## 固定版本文件 SHA-256

```text
d22756332221512b187523d38fdbd9e59332fd15f823f045896d3126edfeada6  前端代码/cashier-v3/src/views/OrderCenterView.vue
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
