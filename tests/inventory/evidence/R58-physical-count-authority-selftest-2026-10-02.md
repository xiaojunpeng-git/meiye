# R58 / 第15轮：实盘数量作为最终库存

状态：开发与自测完成，待产品经理固定版本验收；未提交、未部署、未 push。

产品经理已确认：导入不因账面变化拦截；完成时以实盘数为最终库存，包括盘点期间发生入出库的情形。后端仍在事务内按当前权威库存生成盘盈/盘亏事实，不修改历史事件。权限、商品身份、批次追溯、草稿版本与幂等保护保持。

导入忽略文件账面与盈亏参考列，校验实盘数量并使用当前目录账面展示。非空实盘全部提交，等于旧账面也不遗漏。盘盈是否需要批次资料由后端当前库存判定，不能用旧快照预先拦截。已完成旧草稿命令只保留严格原指纹重放兼容。

## 文件分组

- 前端：InventoryBusinessModal.vue、utils/countSubmission.js。
- 后端：InventoryStockCountServices.php。
- 测试：count-submission-contract.mjs、count-physical-authority-contract.mjs、headquarters-count-draft-integration.php、stock-count-integration.php。
- 数据库迁移 / 工程脚本：本项无新增（同轮总部按钮闭环的迁移另有记录）。
- 生成物：inventory-vue3/dist，仅本地构建，不是 8080 发布包。
- 证据：本记录；无线上验证。

业务注释已同步以上前端与后端文件，说明快照不可用于筛除实盘、事务内调整及历史命令重放边界。

## 自测

- count-submission-contract：PASS，非空、零数、旧账面等值提交；非法数量保留校验。
- count-physical-authority-contract：PASS，导入无账面变化门禁、按当前账面计算差异、版本更新保护仍在。
- count-draft-list-visibility-contract：PASS，同轮按钮/草稿接线无回归。
- headquarters-count-draft-integration：15 PASS，failed=0，白名单隔离库 inventory_manual_inbound_test_20260730，所有业务夹具/事件事务回滚。包含实际库存2、旧账面与实盘均1，最终库存1/盘亏1/重复提交不重复入账，以及门店组织身份草稿确认与重放。
- stock-count-integration.php 已更新陈旧快照测试预期，本次未单独执行该历史有持久夹具测试，不声称通过。
- PHP 语法与 git diff --check：PASS。
- Vite 构建：PASS，1821 modules，存在既有大 chunk 提示；构建后仅调整草稿说明文案，不影响已测业务逻辑。

## 固定验收指纹

基线 HEAD：dece39a5f6cfe659839345ec14cf744d61fff764。同轮未提交总部按钮改动仍在相同文件内，详见 R58-hq-count-buttons-selftest-2026-10-02.md；不夹带其他任务改动。

SHA-256（路径相对于美容源码）：

```
471f713e1375ba5c0b68dcb87649a7a54d49cc0bd2987b7c19023296058be437 前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue
45fd2b66d4895a00068bc9068fd4f45f690a9fd6a6f0a9f3d16b7a2e3ec076ca 前端代码/inventory-vue3/src/utils/countSubmission.js
dd8c615781bcc799f0589e64cad03ccec8eb7be3ca49d594ba791c3cff523d2c 后端代码/app/services/product/inventory/InventoryStockCountServices.php
185d38c1ecd2261558b11b6d65d05c1a959e3f656759e7a3bd005c48bad778f7 tests/inventory/js/count-submission-contract.mjs
b9b3abfa96aac3626572d9dd304d8705ea4f9178e122470c03b8df755fb9b850 tests/inventory/js/count-physical-authority-contract.mjs
38b3c1798e3897fd7428d533d011689a56375301a3625662c95f50d3357bc4b7 tests/inventory/php/headquarters-count-draft-integration.php
5322806efeea0dbbffa5ffa526273ede56e82217acc0185847c4959d06127eb3 tests/inventory/php/stock-count-integration.php
```
