# R14 仓库设置入口移除自测

范围：仅移除平台库存管理中的仓库设置导航入口。仓库维护路由、后端权限与接口、业务单据的仓库选择和库存数据保持不变；未操作远程实例。

## 验证

- 平台侧栏与顶部库存标签均无“仓库设置”；库存首页、请货管理、入库管理、调拨管理、库存盘点五个本地页面均无该按钮。
- 调拨页仍显示调出方、调入方；盘点页仍显示库存仓筛选，已有测试调拨单可见。
- 授权平台账号直达 `/admin/stock/warehouse` 仍能打开仓库列表和“新建仓库”；隐藏入口未删除功能。
- `inventory-vue3` 的 `npm run build` 通过；`admin` 的 `npm run build` 通过（旧 `stockManage.js` 缩进 ESLint 警告仍在，本次未修改该文件）。
- `node tests/inventory/js/hq-multi-warehouse-contract.mjs`：14/14 通过。
- 迁移脚本中的仓库设置菜单 `is_show=0`，保留权限记录供授权与直达路由使用；本地已应用的菜单由前端统一过滤。

页面证据：`R14-warehouse-entry-removed-transfer-2026-10-13.jpg`。

新增/更新业务注释：`前端代码/admin/src/store/modules/admin/modules/menu.js` 中说明导航隐藏与仓库权限保留的边界。

状态：本地开发及自测完成，待产品经理对更新后的固定版本确认验收；未 commit、未部署、未 push。
