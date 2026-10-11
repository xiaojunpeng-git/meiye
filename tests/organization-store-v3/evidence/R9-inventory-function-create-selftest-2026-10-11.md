# R9 库存既有功能权限与新建单据自测

日期：2026-10-11。状态：开发与自测完成，待产品经理对新固定版本确认验收。

## 问题与边界

此前只衔接库存菜单读取，并以新版仓库管理权限判断新建，遗漏了账号已经拥有的添加/编辑单据功能权限，造成新建被错误禁用。只读核对本地账号 A18156013263 的有效岗位规则，确认已有以下功能节点：

| 功能 | 既有授权代码 |
| --- | --- |
| 调拨单编辑 | admin-stock-transfer-add |
| 请货单编辑 | admin-stock-request-add |
| 添加入库单 | admin-inbound-manage-add |
| 添加出库单 | admin-outbound-manage-add |
| 添加盘点单 | admin-inventory-count-add |

本次将对应菜单与功能节点共同映射到精确 HTTP 路由、领域创建校验和前端 can_create，不要求重新配置岗位。没有修改真实账号、岗位、授权或组织/门店数据，没有把某一项功能扩大为全部库存管理权限。

兼容范围是对应单据新建、必要的商品/人员/供货方查询、盘点草稿保存和恢复。发货、收货、撤销、冲销、导入、删除草稿、创建仓库等仍执行原有独立权限校验，不由添加节点推导。已有新版原生权限路径保留。

## 自测结果

- r9-platform-transfer-menu-access.php：206 PASS，包含原只读矩阵、五项独立创建授权、跨功能拒绝、撤权即时生效、辅助查询、盘点草稿真实保存并回读版本1。受本地 MySQL hostname 保护的合成数据外层事务已回滚，回滚断言通过。
- platform-operational-query-contract.php：12 PASS。
- cross-subject-transfer-contract.php：13 PASS。商品目录本次增加精确创建功能鉴权后，更新相关静态断言，保留总部/门店动态 type 与 relation_id 隔离检查；未改变商品数据过滤口径。旧记录中的静态失败属于历史结果，新版本已通过。
- 平台登录 JS 回归 PASS；PHP 回归 26 PASS（含合成数据回滚）。
- 本次11个后端文件 PHP7.4 lint、库存 App.vue SFC 脚本及模板编译、git diff --check 均通过。

## 浏览器真实路径

使用当前本地真实账号和实际 API 响应，未拦截响应，未修改授权。入口为 127.0.0.1:18081，库存 Vue3 热更新服务为18086。

| 页面 | 验证 | 截图 |
| --- | --- | --- |
| 调拨 | 新建跨门店调拨打开；选择商品实际加载573规格、48页 | R9-function-transfer-create-2026-10-11.png |
| 入库 | 添加入库单打开；导入仍按原权限禁用 | R9-function-inbound-create-2026-10-11.png |
| 出库 | 添加出库单打开；导入仍按原权限禁用 | R9-function-outbound-create-2026-10-11.png |
| 盘点 | 添加盘点单打开；空必填项不能完成盘点 | R9-function-count-create-2026-10-11.png |
| 请货 | 新建请货单打开；总部仓、请货人、受理部门正常加载 | R9-function-request-create-2026-10-11.png |

浏览器没有提交真实新单，也未改变库存。当前账号没有门店数据范围，未为了测试补发门店范围，不宣称跨门店真实提交/发货/收货已验收。按钮恢复可用不代表必填项或数据范围校验取消。

## 数据与源码

权威授权来自当前有效岗位规则，每次兼容判断按对应功能核对；未知路由拒绝。租户、组织、门店、总部仓及商品所属范围不扩大。库存业务事件、事实粒度、金额、原事务、并发版本、幂等、冲销和历史快照逻辑均未改变，无迁移。

当前基线 HEAD：4a00cf487274dcf88460ac091567a7527ba04679；R9 最初基线 dac7aed256a477d375a77726fe71c67c6b5c9e79。此前其他任务提交不涉及本轮清单，未创建 R9 commit。新固定版本清单合并此前登录、空工作区和菜单读取成果，历史清单不改写。

本次前端源码：前端代码/inventory-vue3/src/App.vue。

本次后端源码及注释更新：InventoryPlatformAccessPolicy.php、InventoryHqLocationServices.php、InventoryCrossSubjectTransferServices.php、InventoryPlatformHqInboundServices.php、InventoryPlatformHqOutboundServices.php、InventoryStockCountServices.php、InventoryStockCountDraftServices.php、InventoryStockRequestServices.php、InventoryPlatformHqCatalogServices.php、InventoryPlatformWarehouse.php、SystemRoleServices.php。注释说明精确功能映射、默认原生权限及禁止扩大数据范围的边界。

自动化测试：tests/inventory/php/r9-platform-transfer-menu-access.php、tests/inventory/php/cross-subject-transfer-contract.php。

数据库迁移、工程脚本、8080构建生成物：无。验收证据为本文件、上述五张截图及新固定版本 JSON。只重启本地库存热更新容器，未连接远程、未部署、未 push。其余脏工作区与暂存区保持原状。
