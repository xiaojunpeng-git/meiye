# R33 库存无效数据范围控件移除自测

- 基线 HEAD：`67e25e56d5a3e6960c36b13f77aea41663956c23`。本次只在唯一源码正本做本地修改、构建与验证；目标客户、线上迁移、实例备份和回滚不适用。未提交、部署或推送。
- 前端源码：`前端代码/inventory-vue3/src/App.vue` SHA-256 `3989eb576004e79dded75c9b35b78c87ac783632b21fe30d93482f3a4976db1c`；`src/components/InventoryOperationalUnifiedQueryToolbar.vue` SHA-256 `7dc157f5969c6cc6e6dd57a10b4b837a64e446bd1f9a690063d93c4598fa6564`。两文件 Git 补丁 SHA-256 `2bc1a971d66c9f80796fa03324c3ad732d21d5fac10dc90acd86397b584e9ee0`。
- 自动化测试：`tests/inventory/js/inventory-hide-unused-data-scope-contract.mjs` SHA-256 `9380309fdea627d380f822e0f19180a3111bd6b528cfe725b7371a2b6377dffd`。后端源码、数据库迁移、工程脚本：无。生成物：`前端代码/inventory-vue3/dist/` 与 `前端代码/cashier-v3/dist/`，不作为开发正本。本文件是验收证据。
- `node tests/inventory/js/inventory-hide-unused-data-scope-contract.mjs`、`inventory-grouped-document-contract.mjs`、`inventory-period-controls-contract.mjs`、`git diff --check` 均通过。独立库存包与门店端集成包的 `npm run build` 均通过；构建输出分别为 `index-2fd2a44e.js`/`index-93258331.css` 和 `index-9424148c.js`/`index-11d1c833.css`，仅用于核对本地源码构建入口，不代表已打包平台或门店 8080 发布包。
- 本地 `18091` 热更新黑盒：入库、出库、盘点、请货、调拨、院装、导入、库存查询、库存统计逐页确认“正常数据 / 全部数据”控件不再显示；入库仍显示 1 张单据及 4 条明细，状态筛选保留。未操作库存写入按钮。
- 数据架构自检：库存单据及批次事实权威源不变，业务事件、事实粒度、业务时间、事务、幂等、作废冲销、历史快照、统计与金额均未改。查询仍由后端注入租户、门店、仓库权限，显式状态筛选保持原口径；移除的通用 `dataScope` 在库存 Provider 原先未参与数据过滤，请求固定发送 `normal` 仅维持统一查询契约。无新索引、迁移或历史数据回填，不改变其他客户实例。注释更新在 `App.vue`，说明权限与无效范围控件的边界。
- 固定版本待产品经理确认验收；commit、部署和 push 均未获本次请求授权。
