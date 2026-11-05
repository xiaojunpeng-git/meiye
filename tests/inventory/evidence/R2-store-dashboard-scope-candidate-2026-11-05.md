# 第二轮库存首页权限范围候选（2026-11-05）

- 基线 HEAD：`0bb6b7c6b3bf9773b8056fd3060a7a71ce6c6e83`。
- 本轮补丁 SHA-256（仅下表 9 个文件相对 HEAD 的补丁）：`36e1541d2e9d28809fa962b82965bc8f3621e1d20132afbfabcaeb4136bb2bea`。
- 本地开发入口：`http://127.0.0.1:18081/admin/login`；`/view_inventory_v3/` 返回 HTTP 200。线上实例、迁移、备份和回滚：本地候选阶段不适用。

| 分类 | 文件 | 工作区 SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/inventory-vue3/src/App.vue` | `a4dbfab12fd01621e88b4b638c3e562c1a9c87564c89ddcb37ad5567e3443274` |
| 前端源码 | `前端代码/inventory-vue3/src/components/InventoryOrganizationStorePicker.vue` | `cdd57d0b89a5fdf57cf40845f2e423d677d08638c687e9956690e0bdfbebc313` |
| 前端源码 | `前端代码/inventory-vue3/src/styles.css` | `222e89246825a47755a436144c2c79dfcb7eb2cc123f6fced388ccd991140aa4` |
| 后端源码 | `后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformWarehouse.php` | `10bf028002e531725fdd7f6d413d976bf4644c91ca0029f9483723234dc49c4e` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryPlatformAccessPolicy.php` | `c2ed2b38e3006c12d079e313a0b9b40d2d5aa13c791e1e97a3ba36dd6e720c7a` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryPlatformWarehouseServices.php` | `af53d45c7b69207d4e7c9eed1b61c3b154d342150a19563d8ee3f8c935eeb938` |
| 后端源码 | `后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php` | `87d31f7899776c210c7dd288dff37b7ccfc2aabe25ce34c1e0766518520cc615` |
| 后端源码 | `后端代码/app/services/product/inventory/query/InventoryPlatformUnifiedQueryContextFactory.php` | `0008ebb4c33a24ac5d0478ffa5c0c1434efaf73b63eae758e1c5e7795d8516dd` |
| 自动化测试 | `tests/inventory/php/platform-inventory-dashboard-scope-local.php` | `3ad647e859aa1517fdc157ed843c5a79d7385a1748ab3de37e7c16067f7a1cfd` |

本轮前端增加首页组织／门店选择，受限账号默认当前授权范围，全集团账号保留总部仓并可切到门店仓。后端仅按员工可见门店与有效默认仓交集读取门店已结算批次、盘点、请货和在途调拨；组织／单店请求不能扩大权限。首页卡片下钻保留相同门店范围、待处理状态与全日期范围。首页未改变业务写入、数据表和历史事实。

已验收且需保留的既有功能：第一轮总部仓全集团权限保护、内置 admin 豁免、原库存单据页的菜单与写权限、门店端库存查询、统一组织选择器原默认行为。工作区还有其他任务未提交／暂存改动；这些改动不属于本轮 9 文件补丁，不得随本轮提交或发布。构建运行于共享工作区，后续提交或部署前仍须按双基线规则核对统一源码与目标现网，隔离其他未验收改动并重建。

自测记录：

- PHP 语法检查：本轮 5 个后端文件与测试文件均通过；本轮补丁 `git diff HEAD --check` 通过。
- Vue 3 `npm run build` 通过；本地构建入口 `dist/assets/index-11f4c4b9.js`、`index-3996c8d3.css`。这是本地生成物，不计入源码文件清单，也未部署。
- `platform-inventory-dashboard-scope-local.php`：单店、两店聚合、越权门店拒绝、HQ 拒绝、admin 全量豁免、仅首页菜单不提升库存菜单，以及调拨／请货／盘点下钻总数一致，输出 `PLATFORM_INVENTORY_DASHBOARD_SCOPE_OK`。
- `platform-organization-store-read-local.php`：五类单据原组织范围与写权限回归通过。
- `platform-stock-all-stores-local.php`：库存查询全授权门店范围及菜单隔离回归通过。
- 本地 18081 平台 `admin` 页面已加载新版库存首页；目视确认组织／门店选择器、总部仓选择器、指标卡和下方风险／待办区正常排版。浏览器自动点击未能完成，因此受限账号的实际前端交互仍列入产品经理验收清单；服务端受限范围与下钻由上述事务测试覆盖。

已知边界：首页和现有总部仓使用同一个最多 10,000 批次的数组投影；超出时接口会明确报错，不静默截断。当前候选尚未获得产品经理对以上固定文件版本的确认验收；未提交、未推送、未部署。

本轮补充／更新业务注释的文件：`InventoryPlatformAccessPolicy.php`、`InventoryPlatformWarehouseServices.php`、`InventoryPlatformWarehouse.php`、`InventoryOperationalUnifiedQueryProvider.php`、`InventoryPlatformUnifiedQueryContextFactory.php`、`App.vue`、`InventoryOrganizationStorePicker.vue`。
