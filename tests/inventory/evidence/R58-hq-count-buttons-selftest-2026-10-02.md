# R58 · 第15轮总部盘点按钮恢复（2026-10-02）

## 范围与状态

用户授权：“按钮都不要屏蔽”，针对 18081 总部盘点编辑器。恢复导出盘点资料、导入盘点、库存清零、加载全部商品、保存草稿；选择商品、批量填写、批量删除、取消、完成盘点继续保留。合理的空数据/处理中禁用及服务端权限不取消。不修改其他收银页面。

代码实现、隔离自测与页面按钮/加载冒烟通过，待产品经理验收。最初 18086 Vite 缓存的 Vue 模板仍含旧总部屏蔽判断，curl 读取编译 render 证实了缓存。只读核对用户原 tab 14 已回到列表，不再有未保存编辑表单；因此重启仅本地 mohe-inventory-v3-src。独立 tab 16 刷新后，全套按钮真实显示，加载全部商品成功读取 560 个有效规格。未提交该测试表单，测试标签已关闭。

未进行本轮 commit、部署、push 或远程数据库操作，未改变真实盘点库存。客户实例、线上备份及线上回滚点均不适用。本地依赖沿用 canonical inventory-vue3、admin 18081、inventory 18086 和 mohe-platform-app；未创建 worktree 或第二套源码。

## 实现边界

- 总部草稿复用既有 inventory_stock_count_draft 表，保存、续编、列表、删除都按真实总部仓、平台创建人、版本隔离。
- 总部与门店共用确认草稿辅助方法，确认核对快照并幂等结算；草稿保存、导入、清零不直接改变库存。
- 总部确认返回 invalid_lines，沿用现有具体问题行提示及高亮。
- 新增权限登记迁移 20261002-002，仅登记三个 API、复用平台仓库管理权限，不增角色授权、不改变库存表。
- 所有新增与修改业务边界均补充注释（上列前后端源码及迁移）；无无调用平行业务分支，旧门店确认逻辑已提取复用。

## 技术验证

1. npm run build 成功：index-5c2f1b68.js / index-c171b63e.css / exceljs.min-a0d233a6.js；依赖包体积警告保留，不是构建失败。仅本地派生 dist，不是 8080 集成包或已部署产物。
2. 6 个后端文件 PHP 语法检查通过；git diff --check 通过。
3. JS count-worksheet / count-submission / count-draft-list-visibility / count-date-ui 四个合同测试通过。
4. headquarters-count-draft-integration.php：14 项 PASS，HQ_COUNT_DRAFT_RESULT failed=0。
   运行：docker exec -i -e BACKEND_ROOT=/var/www/html mohe-platform-app php < tests/inventory/php/headquarters-count-draft-integration.php
   白名单隔离数据库 inventory_manual_inbound_test_20260730：API 迁移两次无重复、保存不产生库存/事实、详情恢复、跨创建人/仓库拒绝、列表隔离、并发版本保护、替换快照拒绝、完成盘点/重试仅入账一次、已确认禁止删除、未确认草稿删除、门店组织身份与确认回归。所有业务夹具、菜单登记及库存事件事务回滚。
5. 隔离库的精简表结构缺少后台账号表与 R57 操作快照字段，测试脚本在数据库白名单检查后补齐必要测试结构。最初尝试旧 stock-count-draft-integration.php 因夹具缺 staff_name 失败；未当作通过。共享门店确认路径已由新隔离测试实际覆盖，不能声称完整旧脚本通过。
6. 独立浏览器测试 tab 16 真实显示全套按钮；空明细时导出/清零/保存/完成保留禁用，加载 560 行后启用。未导入用户文件、未点击清零确认、未提交真实盘点。Excel 导入导出依赖的工作簿合同测试通过，不冒充实际浏览器上传下载通过。
7. 本机 mohe-platform-app 默认连接 hostname=mysql/database=ruihao/port=3306（本地 Docker 数据库，非远程生产）。三个 API 原登记为空；已执行本地菜单登记，新增菜单 ID 2280、2281、2282，并仅清理 getAllRoles(2,1) 的平台 API 菜单缓存。回滚定位为这三个菜单 ID，未修改任何角色授权、草稿、库存或正式单据。未认证 HTTP 草稿详情返回“请登录”，不是公开接口。已登记迁移执行说明供后续获授权的上线使用。

## 固定准备版本与文件边界

当前共享 HEAD：e0169655b98ff6d5048a885747ab31511369cfb0（其他任务正常提交，非本轮提交）。本轮业务文件 SHA-256 如下；验收前若变化需重新固定和补验。

前端源码 3 个、后端源码 6 个、自动化测试 2 个、迁移 4 个；工程脚本无新增。生成物 dist 不纳入业务源码清单，证据为本记录。

```text
8fdb95972252134a00367e2c08b8d08c561bb00f3a4f34f957aec040d0185346  前端代码/inventory-vue3/src/App.vue
2213606ce53376bacff814dd0162197c9e9bd9445a5a5725ed0c8bc53414f22c  前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue
dda11bba539e825b42b7f90e70628f942e1f2c3ac1ad6f2af3e1ddfafe74da31  前端代码/inventory-vue3/src/services/inventoryApi.js
c7cfe54446bbc06c9296f432521c31d6c7b3788d29319d09e3dc68b926a87b4d  后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformHqStockCount.php
e5c4ecc3efded75a50d1603fb7f23976dbbdf7cf562b497fade4c4655ba9f107  后端代码/app/services/product/inventory/InventoryStockCountDraftServices.php
a16401164fd85c4b63bc85f23a876676aa25b835eed868c495ff89560acd2c6f  后端代码/app/services/product/inventory/InventoryStockCountServices.php
c00d068f741b68085b41e2c3cd6038a1f1c2946d24f7858a711290bed9956496  后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php
120714a9617581e00d006b4e20c04e99f31892bd81735b0c540fee9ff846fe75  后端代码/app/services/product/inventory/query/InventoryPlatformUnifiedQueryContextFactory.php
db6f59c2bff59ecca4796be17b2f02a24ebbb2ee7df08e1c58da484fac5de3fc  后端代码/route/admin.php
b2f40d92c9b54ff7420494ce3952ccba8fd9f5763ee8c48fca6b2d0488c6ff9f  tests/inventory/php/headquarters-count-draft-integration.php
abdbdb4e4e890af82e927c611c252e6017177b4b45720d596658f6fd71882fb2  tests/inventory/js/count-draft-list-visibility-contract.mjs
140ed1951475da20c0757ba03f584fae974b656375a69790372b261f542ad65a  后端代码/database/upgrades/2026-10-02-总部盘点草稿入口/00-升级清单.md
7f775412241a3639cbc33a5744a31ab36a361b3cec4fbf4d4e2f48af0bbe0cf5  后端代码/database/upgrades/2026-10-02-总部盘点草稿入口/01-升级前检查.sql
da2263a9da1a7faebfa3c7b09b6c81a8ff392aec10092e0e1988e74f48d4203d  后端代码/database/upgrades/2026-10-02-总部盘点草稿入口/02-正式升级.sql
fb29dc8bfc3ceb57d1b56b12b6fe139531c186d423afbfa5ed2c95bb813c61c1  后端代码/database/upgrades/2026-10-02-总部盘点草稿入口/03-升级后验证.sql
```

## 验收交接

固定业务源码指纹如上，截图 R58-hq-count-buttons-2026-10-02.png。18081 已可重新进入盘点编辑器检查完整按钮。草稿服务的真实事务与门店回归已覆盖；未在业务浏览器中保存测试草稿或确认盘点，不冒充该真人流程验收。待产品经理确认；本记录不代表产品经理已验收，未 commit、部署或 push。
