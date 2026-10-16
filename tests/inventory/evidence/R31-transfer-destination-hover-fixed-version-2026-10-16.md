# R31 调拨草稿调入方悬停明细固定版本

- 基线 HEAD：`761a3f299c9f39dea43d54729fa69efeb3113292`
- 范围：仅在调拨草稿中悬停或聚焦“已选 N 个调入方”时展示该行已选调入方名称；点击仍打开原选择弹窗。不改调拨提交、库存或权限口径。
- 前端源码：`前端代码/inventory-vue3/src/components/InventoryTransferDraftComposer.vue`，SHA-256 `8aa60102bdc235fab8cbd4e0c13dbf35758ae1fbef8e8dbd`。同文件含前一项 R31 网关刷新修复，未覆盖该改动；本项新增注释说明名称只从该行获授权的调入方目录解析。
- 自动化测试：`tests/inventory/js/transfer-worksheet-contract.mjs`，SHA-256 `aa1452da1b74956aa306e30bacee118da4dc1085acf3321441e188b2f04991d7`。
- 后端源码、数据库迁移、工程脚本：无。构建产物 `前端代码/inventory-vue3/dist/` 为生成物，不作为源码或提交项。
- 自测：`node tests/inventory/js/transfer-worksheet-contract.mjs` 通过；`stock-transfer-form-contract.mjs` 6/6 通过；`transfer-confirmation-contract.mjs` 4/4 通过；`npm run build` 通过（仅既有大包提示）；相关文件 `git diff --check` 通过。
- 页面验证限制：18091 本地服务可达，但新开的独立预览标签要求重新登录；为避免改动用户已打开的草稿，未用其账号选择调入方，也未执行真实调拨。悬停弹层的真人路径待产品经理在固定版本上验收。
- 状态：开发与自测完成，待产品经理确认验收；未 commit、未部署、未 push。
