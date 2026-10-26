# R20 门店页面顶部栏与查询新增入口本地自测

- 基线 HEAD：`8080f24f30ed4164cf9d07f39f06022aa4e3decf`。本闭环按用户 11 条页面标注移除九个指定路由的外壳标题栏，会员列表独立按钮行也移除；“新增预约”“新增会员”分别放到各自查询栏“重置”右侧。
- 前端源码：`前端代码/cashier-v3/src/layouts/CashierShell.vue`、`src/styles/base.css`、`src/views/ReservationListView.vue`、`src/views/MemberListView.vue`。外壳使用显式路由白名单，其他路由的标题栏保留；移除已无调用的顶部新增事件分支。会员新增仍受原列表 `cashier.v3.member.create` 权限控制，预约新增仍走原编辑器准备命令。
- 注释：`CashierShell.vue` 新增路由白名单注释，说明业务页已有操作区以及固定行高释放边界；本轮其余修改为直接可读的模板和样式。
- 后端源码、数据库迁移、工程脚本：无。自动化测试：`tests/cashier-v3/js/headerless-query-actions-contract.mjs`、`member-list-creator-contract.mjs`。生成物：`前端代码/cashier-v3/dist/`，仅本地构建自测；未作为发布包。验收证据：本文件。
- `CashierShell.vue` 在本轮开始前已有一处他人未提交的专属服务人多选改动（`selectMemberCreatorServicePerson`）；本轮未修改该处。以下整文件哈希包含该现存改动，后续提交必须只纳入本轮授权的补丁。

## 固定文件清单

```text
ad786e20c4c852802d8f4c1cf54e19b816b8c86f47e8f5a1008ef59e60c50b0e  前端代码/cashier-v3/src/layouts/CashierShell.vue
7b549cbfc68bed267e8dc4bc30b4e98a08adf0561373847b591ba77db5ee6599  前端代码/cashier-v3/src/styles/base.css
da708f6627796346354464be380e1b5c8aebb8493979d0fc86f1af1675124d15  前端代码/cashier-v3/src/views/MemberListView.vue
d365631d82a48301eb24fe87d5058f52c6ebb1443d41757bda0e11503b6e3a26  前端代码/cashier-v3/src/views/ReservationListView.vue
9a8a9937fff9fa42fdecd781638d24612c4a9dc8ff74eab8123e16cba94c6af9  tests/cashier-v3/js/member-list-creator-contract.mjs
ea0d1ebbb07d9f83e285d284fb933bcc0c535e99b839c1329faf3795b48cb4be  tests/cashier-v3/js/headerless-query-actions-contract.mjs
```

六文件清单指纹：`bd52c62ab731e65cb6dc73c84f5207c3c599f20d8fa01d4dff1cba06c0e238e8`。

## 验证结果

- `npm run build`（`前端代码/cashier-v3`）通过；新回归契约、会员建档契约、预约生命周期契约、收银会员选择契约（12 项）、门店报表契约（44 项）和目标看板契约（10 项）通过；`git diff --check` 通过。构建存在原有大包体提醒，无编译错误。
- 本地浏览器逐路由核对：费用、挂单、房间、预约、会员、客情、订单、门店运营、目标均无外壳标题栏，内容从顶部开始；未在白名单的员工页仍有标题栏。
- 预约查询栏按钮顺序为“查询、重置、新增预约”，点击后打开“新增预约”弹窗；会员查询栏中“重置”后紧跟“新增会员”，点击后打开“新增会员”建档弹窗；均未保存业务数据。会员独立按钮行已消失。
- 页面与弹窗在本地门店的桌面尺寸进行检查，按钮同一水平线且没有遮挡。没有触及权威业务事实、资金、报表计算、权限接口或生产数据。

当前状态：开发与自测完成，待产品经理对本固定版本确认验收；未 commit、未部署、未 push。
