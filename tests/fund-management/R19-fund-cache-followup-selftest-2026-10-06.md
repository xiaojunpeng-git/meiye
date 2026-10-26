# R19 费用页旧缓存后续修复本地自测

- 基线 HEAD：`fb8a3060c768f44d49e36a30a63b9bc841d7c0a0`。产品经理已明确批准本次费用 iframe 加载与 HTML 缓存策略的页面变更；本文件不是对新固定版本的业务验收。
- 前端源码：`前端代码/cashier-v3/src/views/FundManagementView.vue`。仅在费用视图挂载时生成新的文档 URL，保留同源 `/view_fund_v3/` 和 `source=store`，不改变页面布局或费用业务请求。
- 注释：在 `FundManagementView.vue` 补充独立费用应用 HTML 不可复用旧缓存的约束说明；Nginx 补丁内说明精确禁缓存范围，避免误伤哈希资源。
- 工程配置补丁：`scripts/rh-fund-entry-html-cache.patch`。仅用于瑞昊 Nginx，给正式费用 HTML 与 8080 预览门店 HTML 设置 `no-cache, no-store, must-revalidate`；哈希 JS/CSS 继续沿用原静态资源规则。应用补丁前必须核对生产配置 SHA-256 `d18a535024087c9a5285eb4fcb69b08881ec5ee64f5dccb07567b70e1491452c`，独立备份配置并预演回滚；配置漂移时停止，不强制应用。
- 后端源码、数据库迁移：无。费用单、台账和统计仍由原有后端接口与权限规则读取；事实粒度、业务时点、事务、幂等、冲销、历史快照和数据权限均不改变。本次只改变静态文档缓存，不触碰业务数据。
- 自动化测试：无新增。现有收银容器构建、Nginx 补丁试打与本地浏览器真人导航核对覆盖本次窄变更。生成物：`前端代码/cashier-v3/dist/`，未纳入源码清单；当前工作区另有其他任务修改，现有构建仅用于编译验证，不可直接作为瑞昊候选包。
- 固定文件 SHA-256（两文件清单指纹 `d80d8d46c15fc5da1a3b33cc2f29e10e95fec00a398aeedb65450fc09650a895`）：

```text
e50f56087660be67f71212e69f265c8c30a1f7ed2e2776b511bfd8884a2d873e  前端代码/cashier-v3/src/views/FundManagementView.vue
1221468da472dffbcb4d3332d4759cff3aee5e58b24264b69d1890c4d8dc6fc7  scripts/rh-fund-entry-html-cache.patch
```

## 本地验证

- `npm run build`（`前端代码/cashier-v3`）：通过；因其他未提交源码改动，结果只证明本次组件可编译，不作为发布包。
- 瑞昊服务器对现有 Nginx 配置执行 `patch --dry-run -p0`：两段补丁均匹配；本地配置副本执行正向补丁后再 `patch --dry-run -R`，回滚匹配。未修改生产配置。
- 本地 `http://127.0.0.1:18091/view_cashier_v3/#/fund`：iframe 带 `source=store&entry_version=...`；离开费用页进入订单再返回，版本值变化；费用应用显示 `2026-10-01 至 2026-10-06`，当前门店 5 条，分页 `第 1 / 1 页`。
- 发布时必须从已验收的 commit 生成隔离构建，不能从当前含其他任务修改的工作区直接发布收银 `dist/`。备份正式及预览门店静态目录和瑞昊 Nginx 配置；先部署并核对资源指纹，`nginx -t` 后才重载；严格 HTTPS 核对入口 HTML 的 Cache-Control、预览路径改写、登录会话费用 iframe 和三视图只读查询。任一项失败，恢复静态目录和 Nginx 配置并重载。

当前状态：开发与自测完成，待产品经理对上述固定版本确认验收；未 commit、未部署、未 push。
