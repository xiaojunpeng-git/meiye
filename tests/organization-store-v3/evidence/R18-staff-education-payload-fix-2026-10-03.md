# R18 人员权限保存触发学历校验：本地修复自测

状态：开发与自测完成，产品经理已明确授权保存王蕾的指定门店；本地保存与重新打开回显通过。代码仍待固定版本验收；未 commit、未部署、未 push。

## 原因与改动

- 编辑人员时，前端原先只要加载到学历详情，就在任何保存请求中附带 `education` 和 `education_version`。后端因此校验一个与“指定门店”调整无关的学历字段，可能阻断权限保存。
- 前端现在记录打开人员详情时的原学历；仅实际改变学历且版本已加载时才发送这两个字段。主动修改或清空学历仍携带版本，由后端保留并发保护。未读到学历详情时仍不允许覆盖原值。
- 本次只改 `前端代码/admin/src/pages/setting/staff/add.vue` 的上述提交边界，并新增一项自动化测试；没有修改后端学历规则、数据库或页面显示。

## 固定版本与自测

- 基线 HEAD：`544d013d0457c971813ffbed032290b84c17225f`。
- 前端源码 SHA-256：`cd8043d8bf628786e9a696f2e334f80f42e68506d37c43f00916296c6d76a775`。
- 自动化测试 `tests/organization-store-v3/js/staff-education-payload-contract.mjs` SHA-256：`9fafdf971fd4d2f70d9ec87460243112c4da50f4592fbafefabcafd9f107a2e6`。
- `node tests/organization-store-v3/js/staff-education-payload-contract.mjs`：4 项通过，覆盖权限单独编辑不提交学历、主动更改、主动清空及学历详情不可用。
- 平台端当前源码 `前端代码/admin/` 执行 `npm run build`：通过，仅已有资源体积与 Browserslist 提示；`git diff --check`：通过。生成物 `前端代码/admin/dist/` 不纳入源码交付。
- 产品经理确认“王蕾仅看江苏东台吾悦店、宝应金源店”后，首次在旧弹窗点击保存返回“该手机号已有员工档案，请从员工编辑页设置学历”。只读检查发现旧弹窗的实例没有新增的 `originalEducation` 初始值，热更新后仍把空学历误判为变化。
- 刷新 18081 后重新打开王蕾，核对新实例的学历原值与当前值均为空、权限模式为 `store_selected`，并重新勾选上述两家门店；点击保存后弹窗关闭。再打开王蕾，数据权限准确回显“指定门店”且仅有上述两家。成功回显截图：`R18-selected-stores-save-success-2026-10-03.png`。

代码注释同步补充于 `前端代码/admin/src/pages/setting/staff/add.vue`，明确无关权限编辑不能触发学历版本校验。
