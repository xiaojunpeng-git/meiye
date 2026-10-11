# R43 会员保存提示弹窗：固定版本与自测

- 基线 HEAD：`1477eb5afa11d500a17a2dce254c5224cb3a3e68`
- 范围：会员列表新增、编辑时的保存校验、手机号冲突、失败、待确认、成功提示统一用确认弹窗；关闭错误弹窗后回到出错字段。收银会员选择器保持表单内提示。
- 前端源码：`前端代码/cashier-v3/src/components/member/MemberCreatorPanel.vue` SHA-256 `a830828d86bd4775c872795fb69ff539c4845a3d648a065dbd9226c76bbc72c8`；`前端代码/cashier-v3/src/views/MemberListView.vue` SHA-256 `9009e4c94d15eec3203ea282b2a2ff50096f06608d3989aaf32c8840793341bc`。
- 自动化测试：`tests/cashier-v3/js/r43-member-save-dialog-contract.mjs` SHA-256 `8a380ef0d31a0a27e84377b7744efcc0fc3df0afde9ef33d07c0e193606a0d39`。
- 前端两文件相对 HEAD 的补丁 SHA-256：`8656ead5b63cd42989f2a071d7249bff9184716233b429868db7b09763c7afb7`。共享组件中还包含本轮此前未提交的手机号修复，不能把它误记为本次弹窗独有改动。
- 本地构建源：`前端代码/cashier-v3/`，对应 18091 热更新入口的 Vue 3 工程；`npm run build` 通过，生成 `index-07a7e335.js` 与 `index-936ce967.css`（忽略的 `dist/`，非源码、非 8080 集成包）。
- 自测：`r43-member-save-dialog-contract.mjs`、`member-edit-conflict-contract.mjs`、`git diff --check` 通过；浏览器在会员列表点击新增会员并空字段保存，确认显示“保存失败 / 请填写会员姓名”，点击确认后焦点回到标红的会员姓名字段。此浏览器验证未发送保存命令、未改业务数据。冲突及成功分支由源码契约覆盖，未对真实会员执行写入验证。
- 数据架构自检：此项只改变前端反馈呈现，不改变会员主表权威源、会员事件粒度与生效时点、事务/版本/幂等、恢复、历史快照、权限、索引、报表口径或对账路径；服务端结果原样展示，不将待确认误写为成功，既有手机号身份修复仍走统一身份写入路径。
- 注释：更新 `MemberCreatorPanel.vue` 与 `MemberListView.vue`，说明共享组件的提示分流边界、保存结果确认及错误字段焦点约束；测试中说明收银入口不受影响。
- 后端源码、数据库迁移、工程脚本：本项无。远程实例、线上迁移、备份和回滚：本项仅本地开发与构建，不适用；未部署、未 push。
- 状态：开发与自测完成，待产品经理对本固定版本确认验收；未 commit。
