# R51 第 6 轮：库存流水业务化展示

状态：本次三个展示项开发与自测完成，待产品经理确认验收；未 commit、部署或 push。另有预售扣库疑点，仅记录，未修正历史数据。

## 范围与固定版本

- 单据号展示真实业务单号；来源明细展示发生时的中文商品名称；业务类型展示实际业务来源，收银流水不再兜底成“库存调整”。
- 基线 HEAD：`5d7d5cb0d5a960396add7f0cbaa0a53a08bed789`。同轮已有订单中心预售标签成果保持不变，见 `tests/cashier-v3/evidence/R51-product-presale-label-selftest-2026-10-09.md`。
- 既有三个已跟踪后端/合同测试文件的精确 `git diff --binary HEAD` 指纹：`b16458d3f405fb09493ddf5a6ebfebf8c43ecc4eab3f3bf41f137c0f5ef20e47`。
- UI 精确补丁指纹：`a7205196c8aa79985f7d15861aa9adfb8e4ee0c9668a76eb5174abec6d8cd1ef`。生成方法：对该 Vue 文件的 `git diff --binary HEAD` 保留头和包含 `movementTypeName` 或“流水标签由后端”的两个 hunk，排除其他任务的调拨草稿改动。后续提交必须逐 hunk 隔离，禁止将该文件整份暂存。
- UI 开工前工作区 SHA-256：`25a8efe59f48b45959b0316b9d91e86793424c5a63f76e2c2a9a5ce3b9c32d25`。在内存逆转本次两个 UI 改动后再次计算，指纹完全一致，证明原有在途修改未被覆盖；未创建源码备份副本。

| 分组 | 文件（相对美容源码） | SHA-256 |
| --- | --- | --- |
| 前端源码 | 前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue | c48e48c2dd1db8fb17482d57e8f377be56ea60f623417bb2d7475446d485606a |
| 后端源码 | 后端代码/app/services/product/inventory/InventoryMovementDisplayServices.php | 51a81aeb442aaa932e58e8a4325161152da2274992320a42bdc73157b68bf4b3 |
| 后端源码 | 后端代码/app/services/product/inventory/InventoryStoreReadModelServices.php | 3acfd781026c9d2c43f28d7f1c53966b3d45e2774679367905378e8e7aadbb94 |
| 后端源码 | 后端代码/app/services/product/inventory/InventoryPlatformHqReadModelServices.php | bdcc1167e04ff54fd95c29696ce1da0cdfcf5ad3479878ac2c1b36045ddb3db6 |
| 自动化测试 | tests/inventory/php/inventory-batch-reconciliation-contract.php | 437ea9231c3bbcc37e57881ac34ac1bec75893a247201490b61486ba794c62bd |
| 自动化测试 | tests/inventory/php/movement-business-display.php | 33805a502469a7b9b0d76f0ba271fa04fddbbbb3f627addeb1e4870123092512 |
| 自动化测试 | tests/inventory/js/movement-business-display-render.mjs | 4be6631a90880a6426d696d6670806675a250a831d5957e830a9ab2ed5a08cf4 |
| 验收证据 | tests/inventory/evidence/R51-movement-business-display-2026-10-09.png | 5388b608216c982c63eb889069fe8699da5d8c838a6a2e496134c02f5d08272c |

数据库迁移、工程脚本：无。生成物不纳入源码清单。上述四个业务源码文件补齐职责/快照/权限边界注释。引用扫描确认 `movementTypeName` 仅供该库存详情使用，服务端投影已替代其全部类型，删除原客户端字典并更新对应合同断言；其余仍使用的库存读取路径未删除。

## 数据架构与实例自检

1. 权威源/粒度：每条已结算批次移动事实保留不变；中文展示来自对应正式销售行、作废操作、领用单、登记业务单号和批次发生时商品快照，不读当前商品名称来改写历史。
2. 时间/生效：保留事实业务日、发生/结算/记录时间和成功状态；本次只读投影，不产生业务事件或 Outbox。
3. 事务/并发/幂等：不新增命令或扣减，不修改事实/单据/余额。实数验证在只读事务内完成并回滚；零库存既有集成夹具在隔离测试库内生成并全部回滚。
4. 冲销/恢复：保留 `source_id`、`source_detail_id`、`reversal_of` 技术关系；销售作废展示作废业务单号，名称仍取原销售行；领用作废沿原领用单号追溯。缺失来源显示历史业务单据/明细，不伪造新编号。
5. 权限：先由原服务裁剪仓库、门店、商品和成本，再对最多 200 条结果按租户/门店分组查精确来源 ID。销售头/行还须同时匹配租户、门店、订单和行；不因显示中文扩大权限。
6. 查询/性能：门店/总部共用标签投影，避免客户端各猜一套。新增来源查找按类型每组至多三次批量查询，无 N+1；本地销售来源 EXPLAIN 为订单头 `uk_order_id/const/1`、明细 `uk_order_checkout_line/ref/2`，无需迁移索引。
7. 金额/数量/报表：不新增金额字段，不修改原有格式化、分值、数量、汇总或导出列；只替换三项展示值，表列数与顺序不变。
8. 实例：仅本地 `mysql/ruihao` 与隔离测试库验证，不连接远程；客户目标、生产迁移/备份/回滚不适用。恢复仅撤销本次精确补丁，不触及其他脏文件和暂存项。

## 自测与限制

- PHP 投影及本地实数读回通过：`XS26100900006` 两条流水均显示“收银结账”，分别为第 1 行商品名称与第 2 行商品名称（预售）；盘点单 `PD2610010081` 保持，来源明细也显示批次商品快照。
- 成本隐藏、跨租户、跨门店、错误来源订单 ID、旧来源缺失、销售作废/领用作废、技术关联保留及原始事实完全不变回归通过。总可用库存仍为 30 盒。
- 实际 Vue 流水模板编译渲染通过，技术 ID 不出现在可见表格，14 列/空状态/冲销提示保持。门店浏览器关闭后重新打开详情，三项显示正确；截图按标注视口保存，临时视口已恢复。总部真实登录页面未另行实测，复用服务通过源码和模板检查。
- `inventory-batch-reconciliation-contract.php` 8 项、`product-detail-batch-columns-contract.php` 5 项、`zero-stock-product-detail-integration.php` 18 项通过；后者确认无夹具残留。三份后端 PHP 语法、预售标签模板回归、`git diff --check` 通过。
- 已知旧测试：`inventory-v3-c3-contract.mjs` 在第 26 行旧调拨列精确正则失败，同一断言对 HEAD 文件也失败，与本次流水展示无关；未为通过旧断言扩改调拨功能。
- 门店源码 `npm run build` 通过（1994 modules，既有大 chunk 警告）；源为当前 18091 对应工作区及库存共享依赖，不是发布包，也不是 8080 集成打包，包含原有其他工作区改动，不可直接拿来发布。
- 本地构建指纹：HTML `cc57012d34d8e71f7ca00f4feccfcc3a09cfc8f7ff69dab7c8acee0fe61e10a9`；`index-2eb9ec45.js` `59552fa52c7c702e50af7324634607d617aba346f060ccce8b91b7a22f8b0db6`；`index-cd1bfaf6.css` `cd1bfaf6d089fab7ede0b1cd06c0daf078160b40891e77d757d3a7c402374e4c`。

## 单独记录的库存疑点

本地样本第 2 行已保存 `is_presale=1`、`inventory_outbound_required=0`，但有对应批次出库流水 `21719`，数量 3；第 1 行普通购买出库为 `21718`。这与预售无需立即出库的规则存在疑点，需要单独排查结账库存计划。当前仅核实并提示，不根据展示需求静默改历史事实、补库存或声称扣库问题已修复；不扩大本次三个展示项的验收范围。
