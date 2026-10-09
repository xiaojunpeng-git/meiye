# R49-00001 第4轮：门店库存查询移除设置入口

状态：按钮移除开发与专项自测完成，待产品经理确认验收；未提交、未部署、未 push。

## 范围与边界

- 授权：移除截图中门店“库存查询”的“查询设置”按钮。
- 仅门店隐藏；平台库存查询设置入口不变，查询、重置、快捷筛选和设置保存契约不变。
- 删除库存工具栏失去调用的 ref 和标题区分支，保留统计页调用；未修改共享工具栏。
- 权威事实、后端权限、金额计算、时间口径、事务、迁移、数据库均未变更。
- 本地源码基线 HEAD：`b9547566d0a35db9fdff83e8c84fa77eefbc3fa5`。
- 本地开发入口：`http://127.0.0.1:18091/view_cashier_v3/#/fund`，壳内库存查询工作区；依赖 inventory-vue3 源码。
- 本次只做本地验证和构建；远程实例、线上迁移、备份和回滚点不适用。

## 文件分组

- 前端源码：`前端代码/inventory-vue3/src/App.vue`。
- 自动化测试：`tests/inventory/js/store-menu-contract.mjs`、`tests/inventory/js/stock-settings-entry-contract.mjs`。
- 后端源码、数据库迁移、工程脚本：无。
- 生成物：`前端代码/inventory-vue3/dist/`，本地 Vite 构建，非已发布的 8080 集成包。
- 验收证据：本记录、同目录 PNG 和固定版本 JSON。
- 补充职责与范围注释：上述前端源码及两份测试文件。

## 自测

1. 专项 Vue 模板 AST 契约：10 项通过；门店隐藏、平台保留，查询回调、字段及设置保存契约保留。
2. `inventory-hide-unused-data-scope-contract.mjs`、`inventory-grouped-document-contract.mjs`、`inventory-period-controls-contract.mjs` 均通过。
3. `npm run build`（inventory-vue3）成功，1822 modules；保留原有大 chunk 警告。未新增依赖。
4. `git diff --check` 通过。
5. 实际浏览器：查询设置按钮数量 0，查询按钮 1，重置按钮 1；商品名称“POS机纸”查询得到一条记录（可用库存 11）；重置清空条件并恢复列表及合计 1822 / ¥89300.00。
6. 既有广域测试失败如实保留：store-menu-contract 19 通过 / 2 失败（共享导航字符串、总部操作绑定）；stock-list-scroll-contract 4 通过 / 1 失败（固定布局字符串）。在内存覆盖为 HEAD 源码和 HEAD 测试重跑，分别复现同样失败，未修改工作区基线来测试。

## 回归中发现的独立问题

- 输入数量范围 130 至 200 后查询，页面显示 `bccomp(): bcmath function argument is not well-formed`。已点重置恢复可用列表，不将该项标为通过。
- 数量范围相关查询和后端代码不在本次补丁内；此次未修复该独立问题，未声称已对修改前 UI 完整复现。
- 初始页面显示 86 条；执行统一查询/重置后显示 20 条，合计仍为 1822 / ¥89300.00。保留这一观察，不将分页展示判定为本次优化成果。
- 平台设置保留由模板 AST 契约验证；本次未操作平台设置弹窗。

## 固定版本

同目录 `R49-stock-settings-entry-fixed-version-2026-10-09.json` 保存三份闭环文件的 SHA-256、基线 HEAD、补丁指纹和证据指纹。验收仅对应这些文件；其他任务已有暂存与未暂存改动保持原状。
