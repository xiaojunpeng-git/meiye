# R21 库存详情已耗尽批次流水修复自测（2026-10-03）

## 固定版本与文件边界

- 基线 HEAD：`92458a94ccb6c71ec9ab0a314cd9c6e950d5f206`；以下四个已修改文件的补丁 SHA-256：`0daca607a1b7c89b3aa743dfa47579626c600dff8761a7721c43ea5b3fa09674`。
- 后端源码：`后端代码/app/services/product/inventory/InventoryStoreReadModelServices.php`，SHA-256 `90ae64f8fab50826c58822971fb7284dd7a208a0a32b7544f4f162eb45c5d9dc`。
- 自动化测试：`tests/inventory/php/product-detail-batch-columns-contract.php`，SHA-256 `ce5a43401ef1557abbba603c1c7f973a07ada93f27f0539aeb547618c4a4a228`；`tests/inventory/php/inventory-batch-reconciliation-contract.php`，SHA-256 `8dda7510eda7b4d9b8e49df38c896bdbc9310471ddbfa1c4ec4a9864dde76728`；`tests/inventory/php/read-model-projection-contract.php`，SHA-256 `f716fa9ae4b71b45d06a35c205946c9f0985d621097b721e175b2c80ef732b9f`。
- 前端源码、数据库迁移、工程脚本、生成物：无。本文件为验收证据。工作区其他任务的脏文件未修改或暂存。

## 原因与数据架构自检

- 权威源与粒度：库存余额按已结算批次事实聚合；流水以 `inventory_batch_movement_fact` 的单条批次事实为最小粒度。`PSLY2610030002` 跨两批次，因此同一出库单号合法对应两条事实。
- 原接口先从当前正余额批次生成批次 ID，再以这些 ID 限定流水。批号 `1122` 余额归零后被排除，导致其入库流水、`PSLY2610030001` 全部出库流水以及 `PSLY2610030002` 的一部分流水被隐藏；实际事实和库存扣减没有丢失。
- 修复后，当前批次库存继续只显示正余额并与库存主表对账；流水改按后端授权的商品 ID、租户和仓库范围查询最近 200 条已结算事实，包含已耗尽批次。批号、生产日期和到期日仍取事实关联的原批次；单据号仍取原业务单号映射。
- 本次纯查询，不写业务事件或库存，不改变四类时间、金额、事务、并发、幂等、作废/冲销规则、来源快照或聚合口径。后端同时约束事实仓库与库存所属仓库，客户端无法扩大门店权限；保留既有倒序 200 条上限，不引入无界扫描。
- 对账方式：页面总可用库存和当前批次余额仍为 999，状态“正常”；历史流水可见两张领用出库单及跨批次明细。没有改动本地或远程数据库。

## 验证

- PHP 语法：`InventoryStoreReadModelServices.php` 通过。
- `product-detail-batch-columns-contract.php`：5 项通过，新增覆盖已耗尽批次历史和双侧仓库权限约束。
- `inventory-batch-reconciliation-contract.php`：8 项通过；允许库存事实使用内连接取权威库存行。
- `read-model-projection-contract.php`：10 项通过；同步原本过期的“操作时间”测试断言至现有页面文字“操作日期”，未改页面文案。
- `document-number-contract.php`：6 项通过；`git diff --check` 通过。
- 本地门店端合肥世纪中心店“活性玻尿酸胶原液”库存详情：当前批次仅批号 `1003`、剩余 `999`；流水共显示 5 条，含 `PSLY2610030002` 批号 `1003` 与 `1122` 各 1、`PSLY2610030001` 批号 `1122` 的 1，以及两个批次的原入库事实。

开发与本地自测完成，待产品经理确认本固定版本验收；未提交、未部署、未推送。

## 验收更新

2026-10-03，产品经理查看本地修复结果后明确表示“我测试没有问题，先commit”。本节记录提交授权；上文“待产品经理确认”保留为当时的自测交接状态。此确认不授权部署或推送。
