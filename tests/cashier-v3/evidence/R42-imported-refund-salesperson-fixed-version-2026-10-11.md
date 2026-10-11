# R42 迁入旧卡退款销售人改派：固定本地验收版本

- 基线 HEAD：`dac7aed256a477d375a77726fe71c67c6b5c9e79`。
- 状态：开发与自测完成，待产品经理确认验收；未 commit、未部署、未 push，生产站点未改变。
- 范围：订单中心退款记录中的迁入旧卡退款，开放与普通退款相同的销售人修改入口；日期更正、作废入口范围不变。退款金额、支付事实和原销售事实均不改写。
- 权威口径：迁入旧卡退款操作行是退款金额与门店归属来源；人员改派的最后一条 revision 是当前快照；每次改派冲销上一版员工负向事实并追加新版，工资明细只读有效分配。没有可信原销售明细时不回填原销售人或臆造项目分类。
- 前端源码：`前端代码/cashier-v3/src/views/OrderCenterView.vue`、`前端代码/cashier-v3/src/services/cashierV3ActionManifest.js`、`前端代码/cashier-v3/src/services/cashierV3Bridge.js`。
- 后端源码：`后端代码/app/services/cashier/v3/CashierV3CommandGatewayServices.php`、`后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php`、`后端代码/app/services/cashier/v3/manifest/CashierV3C5MemberOrderModule.php`、`后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php`、`后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleModule.php`、`后端代码/app/services/cashier/v3/order/CashierV3RefundSalespersonServices.php`、`后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php`。
- 数据库迁移：无。自动化测试：`tests/cashier-v3/js/r32-refund-date-contract.mjs`、`tests/cashier-v3/js/r42-imported-refund-salesperson-contract.mjs`、`tests/cashier-v3/php/r42-imported-refund-salesperson-rollback.php`。工程脚本：无。生成物：`前端代码/cashier-v3/dist/`，仅本地忽略产物，非 8080 集成包。验收证据：本文件。
- 注释更新：`OrderCenterView.vue`、`CashierV3OrderCenterRecordQueryServices.php`、`CashierV3RefundSalespersonServices.php`、`StoreUnifiedReportPhaseSixServices.php` 及两份 R42/R32 测试，说明旧卡只开放人员、快照/事务/事实边界；其余注册表条目沿用现有模式。

## 自测

- 本地源码为 `18091` 热更新容器 `/app` 挂载源；`npm run build` 通过，Vite 输出 JS `index-3b230785.js`、CSS `index-6be0670a.css`。仅测试构建，未生成或发布 8080 包。
- PHP 相关 8 文件 `php -l` 通过；网关文件仍有原有 nullable 类型弃用提示，无语法错误。`CashierV3ActionManifest::selfCheck()` 返回 `[]`。
- `node tests/cashier-v3/js/r32-refund-date-contract.mjs` 和 `node tests/cashier-v3/js/r42-imported-refund-salesperson-contract.mjs` 通过；`git diff --check` 通过。
- 在本机 `mohe-cashier-app` / 本地 `ruihao` 数据库，对既有 `OLO-80F50383CDBECC9CBBFAC9D843CB28139A8AF783` 运行 `docker exec -i mohe-cashier-app php < tests/cashier-v3/php/r42-imported-refund-salesperson-rollback.php` 通过。覆盖权限拒绝、命令类型隔离、两次改派、重复请求幂等、旧版本拒绝、列表最新快照、工资明细只计一次 -500 元、退款金额及原销售事实不变；外层事务回滚后 revision/业绩事实恢复原数量。
- 原 R32 PHP 回归因其任意选择的本地门店账号现无 `staff_adjust` 权限而拒绝；旧 D3 工资退款分类回归的本地退款 fixture 现已不存在（operation/adjustment/业绩事实均为 0）。两条旧脚本未计为通过，本次新增事务测试直接覆盖受影响的旧卡工资读取路径。

## 固定文件 SHA-256

```text
312fc4f318c9f9d515e7efd69d0a74d3942d6a2f2b85efbd3ab634f5bc741c9b  前端代码/cashier-v3/src/views/OrderCenterView.vue
f003ac4106d933c0f71a9a067fb7d50c0f75b6a9ff3902712286a00dd998f652  前端代码/cashier-v3/src/services/cashierV3ActionManifest.js
3543cdc4bfec9b6f04692524022dd0f107b4e7d67aa30c02c8f51fa307a3e81e  前端代码/cashier-v3/src/services/cashierV3Bridge.js
80e3d3518276d386f8b8b1d6240b7de5c07e165f81579961d3304f24edc4f09c  后端代码/app/services/cashier/v3/CashierV3CommandGatewayServices.php
3603df487410d3abae95484cef8bb13fdef99f97414dd4964eb0a0c59a187aed  后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php
93c42c69905cb74a9d03e129ba878775e73d4b80a03b440501349aed631d81be  后端代码/app/services/cashier/v3/manifest/CashierV3C5MemberOrderModule.php
4ebcc72a5f3f4003737aec515c08f9ca9a6ddea4ff329f3dd0da6fda9cb9b36f  后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php
1803aa25f2d9fe21b641ed2a9e1a7b951760175f43a1d4afe5439ed07395380d  后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleModule.php
c766c805f3616f4523e4e91b33481733649f9b838b07c91a343c2d757a9e0815  后端代码/app/services/cashier/v3/order/CashierV3RefundSalespersonServices.php
17ef6302b472942310f7c6505ee3d8b726588bf54b1db0b30f5eea966df8482c  后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php
3c34e933a8dcb2dd12e71942e4e0568b868b7dd265e71fa935997fba745608f7  tests/cashier-v3/js/r32-refund-date-contract.mjs
3518813fe9ebc3427d30209274463dc62eed2f3053c0a630c5bf62b4085fc123  tests/cashier-v3/js/r42-imported-refund-salesperson-contract.mjs
bd31a8c2819a8b51a22fcef239652c789dd7fac74730ec7b0962176f7ec7c85b  tests/cashier-v3/php/r42-imported-refund-salesperson-rollback.php
```

跟踪文件的当前补丁指纹（`git diff`，上述跟踪范围，不含两份新增测试）为 `fe8bf26f53fc381de4b985217904a889e9ea0f5cc1fd58371987a611c1e9a3e0`。同工作区另有其他任务的已暂存和未暂存改动；本任务未触碰暂存区，原已暂存补丁指纹保持 `dc31b057aca12a17ee75ed7a86e023bfeba79933849b91605faa54e209c95b46`。将来仅在产品经理对以上文件 SHA 明确验收后，按本闭环逐文件核对并暂存，不得夹带其他改动。
