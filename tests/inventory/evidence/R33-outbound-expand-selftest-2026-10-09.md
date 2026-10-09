# R33 门店出库订单式分组明细自测

- 基线 HEAD：`b07fcf4f549b7c52edcc197a2e92901a53350bca`。仅本地开发；未提交、部署或推送。
- 前端源码：`前端代码/inventory-vue3/src/App.vue`（SHA-256 `60febfc2d51f713cfd6501eb09ab21dcda922aea7d42e00fdffab4c5405c665b`）、`前端代码/inventory-vue3/src/styles.css`（SHA-256 `63b719ff2e8818125328d4884092a731b51683f1405a8b2727afb9de2c3e5ba7`）。后端源码、数据库迁移、自动化测试、工程脚本：无。
- 源码补丁指纹（以上两文件的 `git diff` SHA-256）：`ed667d01a5c61bc4e9f839f62290f84fcdb89d1a124dc48c9799cc0ece71a054`。
- 本地生成物：`inventory-vue3/dist/`（不作为源码提交）。本文件是验收证据，不属于业务源码。
- `npm run build` 通过；`node tests/inventory/js/inventory-period-controls-contract.mjs` 通过；`git diff --check` 通过。
- 本地门店页面实测：查询区已无折叠开关；查询 2026-10-01 至 2026-10-31 得到 6 张单据。参照消费订单列表，整张表共用一组商品明细表头，每张单据先显示一条浅色、单行的“出库单号：值  出库日期：值 ...”信息带，其下直接显示相应商品/批次明细；没有第二块结果区或重复明细表头。6 张单据依次对应 2、1、1、1、1、1 条权威明细，首张单据和商品成本均显示整数元 `¥188`。重新查询后明细重新读取；切换查询会废弃旧响应，单张读取失败独立提示。
- 既有契约测试 `inventory-api-contract.mjs`、`document-lifecycle-frontend-contract.mjs` 分别在未改动的 `cancelCrossTransfer`、`reverseCrossTransfer` API 名称处失败；与本轮出库展开无关，未计为通过。
- 本轮业务注释更新在 `App.vue`（查询范围、响应失效、分组表结构、金额展示边界）和 `styles.css`（订单式分组布局边界）。
- 固定版本待产品经理确认验收；未获验收前不 commit，部署瑞昊和 push 需另行授权。
