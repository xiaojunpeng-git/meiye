# R100 预约服务时长按钮补充候选

- 基线：已验收本地提交 `9c5d627e55ccd6c895771bbb4fd1bb812cf5a0e0`。
- 本次范围：预约详情的“修改服务时长”入口对有项目和无项目预约均可见；无项目时点击给出先选择项目的行内提示，不打开保存表单；商家端该按钮固定为 48px 高。后端仍要求预约项目存在才能保存，不修改时长和历史数据。
- 本次改动：`前端代码/cashier-v3/src/components/reservation/ReservationDetailOverlay.vue`、`前端代码/mobile-vue3/src/merchant/pages/reservations/index.uvue`、`后端代码/app/services/cashier/v3/reservation/CashierV3ReservationDetailQueryServices.php`、`tests/cashier-v3/php/reservation-detail-projection-contract.php`。
- 补丁 SHA-256：`7f1097f8a1dd29df0535d64be8f4fa2d73c6162ce7df6b503aa7ab291c0537e7`（相对上述基线，仅四个文件）。
- 验证：门店端 Vite 构建通过，预构建 70 项通过；PHP 7.4 语法检查通过；预约详情投影契约通过；四个文件 `git diff --check` 通过。未执行真实预约开始/结束/保存。
- 限制：本轮浏览器控制不可用，门店/商家端页面点击与实际像素高度尚未复测；商家端 H5 完整编译未在本次补充中运行。此前完整本地业务自测见 `R100-reservation-service-duration-fixed-candidate-2026-11-02.md`。
- 前端源码：两项；后端源码：一项；数据库迁移：无新增；自动化测试：一项调整；工程脚本：无；生成物：门店 `dist/`，不提交；验收证据：本文件。
