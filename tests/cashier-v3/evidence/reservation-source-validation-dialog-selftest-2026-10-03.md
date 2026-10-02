# 预约来源漏选弹窗自测（待产品经理验收）

- 基线 HEAD：`0a4ee922`；本次仅改门店端预约编辑器与预约前端合同测试。前端源码：`前端代码/cashier-v3/src/components/reservation/ReservationEditorOverlay.vue`；自动化测试：`tests/cashier-v3/js/reservation-lifecycle-frontend-contract.mjs`。无后端源码、数据库迁移或工程脚本改动。`dist/` 是未纳入 Git 的构建生成物，本文件为验收证据。
- 用户可见变化：创建、编辑或门店确认预约时，一级来源未选或必选二级来源未选，点击提交会在当前视口显示“预约来源未选择”弹窗及对应提示；确认后焦点回到缺失的来源下拉框。原表单校验仍阻止保存，其他必填字段校验和保存命令未改。
- 数据架构自检：权威源仍为后端预约主单；本次只改本地提交前的显示反馈，不新增业务事件、服务／销售事实、统计时点、金额、权限、历史快照、迁移或回填。后端来源校验、事务、幂等和既有数据对账路径均不变；无新增查询或性能负担。
- 注释：`ReservationEditorOverlay.vue` 在来源漏选弹窗关闭函数前补充了当前视口拦截与焦点归还的业务约束说明。
- 自动化：`node tests/cashier-v3/js/reservation-lifecycle-frontend-contract.mjs` 通过；`php tests/cashier-v3/php/reservation-entitlement-project-contract.php` 通过；`npm run build` 通过（仅有既有大 chunk 提示）；`git diff --check` 通过。
- 本地实测：18091 前端容器缓存刷新后，在未选来源的新增预约表单点击“确认预约”，出现居中 `alertdialog`，提示“请选择一级来源后再确认预约。”；点击“知道了”后弹窗关闭，焦点回到一级来源下拉框。未创建预约或发送保存命令。实测时浏览器为窄视口，弹窗文字与按钮完整可见。二级来源分支有静态合同覆盖，尚未在具有必选二级来源的实际选项上做页面复现。
- 固定文件 SHA-256：编辑器 `b72c47444562a2c23efd430f9335fc0ef0f3b47aacafad3c59ff9e0635e9a95d`；前端合同测试 `cf331cfcd3746250455f6ad0a53c36d5db3f78ef4207e7968bdc3bc8cf3c5aeb`。状态：开发与自测完成，待产品经理对以上固定版本确认验收；未 commit、未部署、未 push。
