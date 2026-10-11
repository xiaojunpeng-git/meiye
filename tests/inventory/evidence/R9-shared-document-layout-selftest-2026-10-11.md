# R9 库存单据共用布局自测

## 状态与固定版本

开发及本次新增测试完成，待产品经理确认验收。未 commit、部署或 push，未连接或修改远程实例。基线 HEAD：`2ce93d0fb8a80c5262ce75afa31b35f93762d62c`。

固定版本指纹：`7fc2e14a729193749f85c64fe36c4f02e9d44c3da737be8107b4e22d55f9edce`。逐文件 SHA-256、补丁指纹、构建产物和测试输出见同目录 `R9-shared-document-layout-fixed-version-2026-10-11.json`。

## 根因与范围

平台桥接页和门店嵌入组件本来均使用 `前端代码/inventory-vue3/`。差异来自 `App.vue` 将分组明细表限定为 store 模式，平台落入旧平铺表分支，而不是缺少第二份源码。本次删除这五页已被替代的平铺动作分支，让入库、出库、请货、调拨、院装领用共用现有分组表格、商品列映射和有限并发详情读取。保留门店已确认的表格呈现；平台“转调拨”及后端 can_* 能力继续按原授权显示。

平台仍保留必要的仓库上下文筛选，门店保留原有状态筛选及查询设置入口；本次不宣称两端外壳和全部筛选功能完全相同，也未修改这些既有业务差异。查询设置入口现有行为不属于本次修复。

展开明细时同时修正平台选择门店仓仍读取总部详情的问题：复用平台统一查询上下文校验页面、门店和仓库，门店出库复用原事实投影。成本在后端脱敏，不借用门店会话，不增加权限。总部新建、作废及库存结算命令未修改；平台读取门店单据不推导总部作废能力。数据库迁移：无。

## 文件分组与注释

- 前端源码：`前端代码/inventory-vue3/src/App.vue`、`groupedDocumentRows.js`、`documentDetailReader.js`。
- 后端源码：`InventoryPlatformDocumentReadScope.php`、`InventoryPlatformHqInboundServices.php`、`InventoryPlatformHqOutboundServices.php`、`InventoryMovementQueryServices.php`，以及对应两个平台总部入/出库控制器。完整路径见固定清单。
- 自动化测试：`shared-document-layout-contract.mjs`、`shared-document-layout-render.mjs`、`platform-document-detail-scope-contract.php`。
- 数据库迁移、工程脚本：无。
- 生成物：库存模块本地 `dist/`，不作为可编辑源码，不是 8080 集成发布包。
- 验收证据：本记录、固定版本 JSON、旧测试基线比较 JSON、平台与门店入库 PNG。
- 上述修改的业务源码均补充或更新共用布局、身份隔离、读取范围、成本或写能力边界注释。未改动其他任务已暂存或未提交的文件。

## 自测结果

- 新增三项测试 PASS：双端五页 API 选择及拒绝后不降级、真实 Vue 模板渲染一致性及加载/失败/空明细、平台门店详情范围与成本及总部能力回归。
- 六个后端文件 PHP 语法检查 PASS；`git diff --check` PASS。
- 相关原有 PHP 测试：手工单冲销、单据生命周期路由、平台统一查询、入库出库追溯 PASS。
- 原有 `manual-inbound-detail-contract.php` 一条基于旧字符串的成本权限断言失败。该测试读取的六个源码输入与 HEAD 完全相同，非本次引入，未掩盖失败。
- 原有 40 项 JS 测试：32 PASS，8 项修改前后均失败，新增失败 0。具体项目及输出见 `R9-shared-document-layout-regression-2026-10-11.json`，不将旧失败记为通过。
- `docker exec mohe-inventory-v3-src npm run build` PASS；保留既有 ExcelJS 大 chunk 警告。构建源对应当前 18086 共享模块、18081 平台桥接和 18091 门店组件依赖，未使用旧发布产物作为开发源。

## 实际页面核验

平台 `18081/admin/inbound/manage`：入库单 `RK2610110001` 显示同一商品表头、信息带及两条商品明细，查看详情可打开。当前账号仓库选择仅有总部仓，未变更账号权限以强行验证门店仓；门店仓的后台读取边界以自动化执行测试覆盖，仍需有对应范围的实际账号业务验收。

门店 `18091/view_cashier_v3/#/cashier` 通过库存导航进入入库，本月筛选后 `RK2610010193` 显示原信息带、两条商品明细及已作废状态，与产品经理提供截图一致。未新建、作废、发货、收货或写入任何业务单据。五页真实模板的结构、按钮能力和加载状态由编译渲染测试覆盖，不冒充其余四页均已做真实业务操作验收。

本地 Docker 文件变化没有完整触发 Vite 缓存刷新，因此仅重启本机库存和门店源码开发容器，随后浏览器复查。浏览器临时视口已恢复。远程客户、线上迁移版本及远程备份/回滚点：本次无远程操作，不适用。
