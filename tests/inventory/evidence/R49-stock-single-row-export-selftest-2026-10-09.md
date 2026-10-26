# R49-00001 第4轮库存查询 V4：导出并入筛选行

状态：开发与自测完成，待产品经理确认验收；未 commit、部署或 push。

## 范围

门店库存查询将导出移到重置后面，移除原首行（含收起/展开入口），筛选字段固定可见。桌面顺序为商品名称、库存数量、查询、重置、导出；自定义上下界仍在数量模式后。窄屏允许自然换行，查询/重置/导出整组相邻。平台与其他接入页不启用单行模式。继承旧收起偏好时仍显示字段；元数据无字段时回退原首行，避免操作不可用。

## 文件分组

- 本项前端源码：前端代码/inventory-vue3/src/App.vue；前端代码/shared/unified-query-vue3/src/components/UnifiedQueryToolbar.vue。补充了单行限定、旧偏好回退及导出权限/执行路径不变的职责注释。
- 本项自动化测试：tests/unified-query/js/query-actions-after-fields-contract.mjs（兼容新增导出但保留原查询/重置断言）；single-row-query-export-contract.mjs（新增默认关闭、24种状态组合、首行显隐、导出顺序/权限/执行路径、门店限定）。
- 本项后端源码、数据库迁移、工程脚本：无。整轮仍包含 V3 的数量查询后端修复，范围由 V4 清单累计固定。
- 生成物：inventory-vue3/dist/ 本地构建，1824 modules，index-8e714df6.css / index-289d8275.js；不是8080集成打包。
- 验收证据：本记录、R49-stock-single-row-export-2026-10-09.png、R49-stock-single-row-export-browser-2026-10-09.json、V4固定版本。保留V1-V3历史证据，当前验收以V4为准。

## 自测

1. single-row-query-export-contract 通过；query-actions-after-fields-contract 17项通过；stock-settings-entry-contract 13项通过；stock-quantity-presets-contract 19项通过。
2. inventory-vue3 npm run build 通过，仅原有大chunk提示；git diff --check通过。
3. 真实浏览器：查询/重置/导出各一个，原首行DOM数量0；导出可以打开“导出查询结果”抽屉，显示当前查询2条及原字段、范围设置，随后取消关闭，未创建导出任务。
4. 自定义130-200实际返回两条库存，合计330；桌面1624×1063三按钮同排且从左到右顺序正确；窄屏768×900三按钮相邻、无输入遮挡、文字完整、处于视口内；临时视口已恢复。

## 数据与边界

本项仅移动原入口，使用同一导出抽屉/直接导出回调与canExport权限，不另建查询或导出计算。权威库存源、事实粒度、事件时间、事务、幂等、冲销、历史快照、金额精度、后端权限及性能口径均沿用V3。未写业务数据库、无迁移、无生产连接，远程实例/备份/回滚不适用；构建源与本地热更新源一致。

V3已记录的既有分页/条数展示、广域旧测试及未下载导出文件限制继续保留，详见R49-stock-quantity-presets-selftest-2026-10-09.md。当前自测不能冒充产品经理验收。未暂存或覆盖其他任务改动。
