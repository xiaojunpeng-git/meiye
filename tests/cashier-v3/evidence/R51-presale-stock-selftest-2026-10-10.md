# R51 第6轮：预售结账扣库修复

> 后续口径更新：本记录保留首次纠正时的审计展示事实；最新固定版本和验收入口见 `R51-presale-normal-flow-selftest-2026-10-10.md`。已精确纠正的预售误扣及纠正不再进入正常流水、计数或出入库统计，底层审计不删除。

## 版本与边界

- 固定版本：`R51-presale-stock-20261010`；基线 HEAD `5d7d5cb0d5a960396add7f0cbaa0a53a08bed789`。
- 用户明确要求“一起修复”。只修改本机唯一源码；只纠正本地 Docker `mohe-cashier-app → mysql/ruihao` 的 `XS26100900006`、门店126、原流水21719。没有连接或修改瑞昊生产库存，没有 commit、部署或 push。
- 本轮前两项预售标签、库存流水业务化展示保持有效，分别见同目录 `R51-product-presale-label-selftest-2026-10-09.md` 和 `tests/inventory/evidence/R51-movement-business-display-selftest-2026-10-09.md`。本记录补充后续扣库与本地纠正，不改写此前30盒的历史截图。
- 当前工作区有其他任务的未提交/已暂存改动，未覆盖或暂存。本项没有前端修改、数据库结构迁移和新构建产物。
- 状态：开发与自测完成，待产品经理确认固定版本验收；报表页面/导出全链路未另做人工验收，成本服务真实数据库读取已验证。

## 原因与修复

库存计划第一轮按正式销售行 `inventory_outbound_required` 正确排除预售，但第二轮遍历全部销售行并只检查SKU是否已锁。相同SKU的普通购买行让预售行再次入选。现保存第一轮合格出库行，第二轮只遍历该集合；卡项、项目、无需出库行不能因共享SKU重新入选。未新增前端去重或修改正式销售快照。

本地历史单普通3盒、预售3盒原先均扣库。修复追加一条 `cashier_presale_stock_repair`，完整镜像冲销原流水21719，原批次6066/库存6551退回3盒、库存30→33；原订单、明细、付款和结账库存回执保持不变。纠正于2026-10-10实际发生，保留系统操作者与原因，不倒填发生时间。

作废必须保留原回执完整性检查，并仅跳过经完整身份、批次、数量、成本及正式预售标记验证的纠正。异常冲销、重复冲销仍拒绝。后续作废只再退普通3盒，最终36盒，不能重复退预售3盒。

商品成本读取仍保留原结账回执，另读取权限范围内关联原扣库事实的纠正，按原销售业务日抵消误扣成本。本单普通成本56400分保持、预售成本56400分→0；收款/收入不变。没有修改报表布局、列、金额展示或导出规则。

## 文件分组与注释

- 前端源码：本项无修改。
- 后端源码：`CashierV3SaleInventorySettlementServices.php`（出库集合保留）、新增 `CashierV3PresaleStockRepairServices.php`（精确纠正、幂等、对账/CAS）、`CashierV3SalesOrderInventoryReversalServices.php`（作废防重复退库）、`InventoryMovementDisplayServices.php`（纠正类型及原单号中文投影）、`ProductManagementDashboardServices.php`（已纠正预售误扣成本抵消）。以上业务边界均补充说明注释。
- 数据库迁移：无；复用现有批次流水及操作者/备注字段。
- 自动化测试：`tests/cashier-v3-sale-inventory/php/mysql-integration.php`、新增 `php/presale-repair-local-integration.php`、`tests/inventory/php/movement-business-display.php`。
- 工程脚本：`tests/cashier-v3-sale-inventory/mysql56-matrix.sh`；新增 `scripts/cashier-v3/repair-local-presale-stock.php`（仅本地、单一订单白名单，默认回滚预览，显式apply且要求新证据文件）。
- 生成物：无新业务构建包；修复前后数据库快照JSON属于证据，不是源码。
- 验收证据：本文件、`R51-presale-stock-repair-local-2026-10-10.json`、同名PNG。运行时原始快照另保留于后端 `runtime/`。

## 自测结果

1. 修复前新混合单回归复现 `sale_inventory_shortage_denied`：普通3＋预售30被合并扣库，库存只有5。旧runner因ThinkPHP异常退出码0误报PASS；现强制检查完整断言结束标记。
2. MySQL5.6.51/PHP7.4隔离矩阵最终 `passed=14 failed=0`。覆盖普通＋预售、预售＋普通、纯预售（仓库停用仍不依赖仓库）、FEFO/成本游标、幂等重放、作废、重复作废拒绝、计划冲突、失败回滚、缺货、零库存、SKU身份冲突及非库存商品。
3. 旧隔离夹具缺少当前共享流水writer需要的操作者/备注字段，补齐测试库字段；启动等待改为最终TCP监听，避免临时初始化服务退出造成连接拒绝。未修改业务环境结构。
4. 本地真实订单纠正前演练：11项通过、全部回滚，包含账实不符拒绝。正式执行仅追加一条纠正、恢复3盒；再次预览为重放0恢复/3已恢复，库存33不变。
5. 最终本地回滚集成：16项PASS；覆盖原订单/明细/回执和原流水逐值不变、精确关联及审计、成本抵消与原销售日、成本权限/租户/门店隔离、畸形纠正拒绝作废、纠正后作废只退普通、整轮回滚与最终数据库不变。初次纠正对账拒绝场景已在正式apply前通过；apply后重跑明确SKIP该初次场景，不伪称再次执行。
6. 库存明细真实服务：库存33、三条关联本单流水（两条原扣库＋一条纠正），成本隐藏与跨租户/门店/来源拒绝通过。来源查询EXPLAIN为订单唯一索引const1/明细索引ref2。
7. `run-static.sh` 16项合同通过；本机新版PHP有既有可空参数弃用警告，相关PHP7.4实际业务测试通过。订单预售标签、库存流水真实Vue模板渲染回归通过；相关PHP语法与 `git diff --check` 通过。
8. 浏览器本地库存明细实测：总可用33盒、对账正常、纠正业务类型中文、业务单号XS26100900006、预售第2行、冲销流水21719。截图保留完整页面。

## 数据架构自检

- 权威源/粒度：正式销售行决定是否出库；库存/批次余额与已结算批次流水一致。纠正粒度为一条错误原扣库分配，不按商品名称或整单数量猜测。
- 事件与时间：新结账沿用原事务、出库事件/Outbox；历史维护不重跑结账、不新增销售/收款/业绩，纠正本身以关联批次事实记录真实执行日期和时间。
- 事务/并发/幂等：原单/回执锁与生命周期操作串行，库存/批次稳定顺序锁、版本CAS；原流水ID确定纠正事实唯一键，重放不重复退库。
- 冲销/快照：不覆盖原历史；后续作废只恢复尚未恢复的分配；历史名称从正式销售行快照解析。
- 权限：维护入口无HTTP路由，脚本强制本地容器/主机/数据库/门店/订单白名单；查询、成本按原后端tenant/store/成本权限范围读取，不能从客户端扩权。
- 性能/兼容：不新增迁移；共享SKU锁顺序不变；纠正只查单一订单，报表纠正只查本次已筛选回执对应的订单，不扫全库后做客户端过滤。
- 对账：纠正前锁定后检查库存与批次流水净额；纠正后净额33；后续作废演练净额36。保留快照证据；不以删除原事实回滚，后续若需业务再调整须新增审计事实。本地测试探针全部事务回滚。
- 旧代码治理：第二轮全销售行路径被合格出库集合替代；成本读取原逐分配直接返回循环被按稳定明细键汇总并抵消的唯一路径替代，无第二份扣库实现。前两项已删除的前端类型猜测字典未恢复。

## 固定指纹

以下路径均相对唯一源码根目录。五个已跟踪修改文件（扣库/作废/成本服务及矩阵脚本、矩阵PHP）HEAD补丁SHA-256：`9ccca947d01ae6a1d5b822f78f62f15b5cd5aa524fa9f76882fe7f58d62510d4`。其余新增/同轮文件用逐文件指纹固定；后续验收如变化须补验受影响范围。

| 文件 | SHA-256 |
|---|---|
| 后端代码/app/services/cashier/v3/settlement/CashierV3SaleInventorySettlementServices.php | af47d70e8bae0a60d3bf57af5e858d3ba9698e180f7f3260ad81347278a9ea31 |
| 后端代码/app/services/cashier/v3/settlement/CashierV3PresaleStockRepairServices.php | 351699f4fe8e738a301b8fb6b38fe6720680d5a348014dfd705dafffab8d2176 |
| 后端代码/app/services/cashier/v3/order/CashierV3SalesOrderInventoryReversalServices.php | 250c1ff68dbe6114158753ffa81d8d427f02143110b2e7ba9bec0798250304b5 |
| 后端代码/app/services/product/inventory/InventoryMovementDisplayServices.php | 289710c6f859bd08aab7ad719b42ecc37c78d47b29da8e585d33f84fa2343daa |
| 后端代码/app/services/report/ProductManagementDashboardServices.php | 1e23a10cb24abc473d1d1d6b78d6faf61dc877946860899ed627544daa11ef0b |
| tests/cashier-v3-sale-inventory/mysql56-matrix.sh | 3efba7c871596c752a78575c517b99b39df392a1cb901fb3b3cbe6862c06ac54 |
| tests/cashier-v3-sale-inventory/php/mysql-integration.php | de33d7214a34f3350881f1e7a98599d723eeefc194ae0e1b64124733e136bc5a |
| tests/cashier-v3-sale-inventory/php/presale-repair-local-integration.php | 4626df782f92f5915b1ff40a5ff71f3cb5dd0a18899ae48ddb2b18e5157c6f3c |
| tests/inventory/php/movement-business-display.php | ce5f066dc652459bb8233fe3819a340d97d1934116e2d2ed1100e4cbecaf0a3a |
| scripts/cashier-v3/repair-local-presale-stock.php | 70267d48fc28abda332b27924d4459344582308a8cb113dd8a8a5e5155883094 |
| tests/cashier-v3/evidence/R51-presale-stock-repair-local-2026-10-10.json | 4e54b559d43a2d034bb01c951575bd2809d3b18609742b99cab8944f3ba144d7 |
| tests/cashier-v3/evidence/R51-presale-stock-repair-local-2026-10-10.png | 1716a70b690472cd2250493e3805de152b82a109cb22137af1ed34f1fb54dbd9 |

## 验收建议

本地重新开一单同商品普通购买＋预售，核对只有普通行生成扣库；纯预售不扣库存，实际领用时再扣。最新库存详情验收应按后续记录：33盒、对账正常，仅普通购买出库和原盘盈入库可见，不再展示预售误扣及其纠正。产品经理确认前不提交，不部署，不push；生产历史同类数据未扫描或回填。
