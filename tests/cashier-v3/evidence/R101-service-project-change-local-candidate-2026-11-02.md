# R101 服务中修改服务项目：本地候选

- 基线 HEAD：`7cdfbf429ed28f4ceb70a70256e53954615a8188`。唯一源码正本：`美容源码/` 当前工作区。共享工作区相关文件存在其他任务的暂存及未暂存修改；本轮尚未隔离提交。线上目标：不适用。
- 范围：商家端、门店端预约详情在 `IN_SERVICE` 时提供“修改服务项目”；保存后同步预约最终项目行和当前服务单行。结束服务沿既有链路从最终预约项目行核销已购权益。独立服务时长、实际开始时间和倒计时不随项目修改。其他状态不显示入口。
- 权威源：`cashier_v3_reservation_line` 为最终选择；`cashier_v3_service_order_line` 同步当前服务项目；旧服务行转为 `RELEASED` 留痕。预约操作和 `reservation.service_projects_changed` 事件保存修改前后项目；权益扣减及经营事实只在结束服务事务产生。
- 权限与并发：两端共用预约操作策略，保留身份、租户、门店、预约状态、单据版本、服务单版本和命令幂等校验；不新增岗位权限。
- 迁移：无。历史数据：不改。发布：未授权，不部署、不 push、不执行线上结束服务。

## 自测

- 门店 Vue 3 预构建 70 项与 Vite 构建通过；商家 uni-app x H5 构建通过，输出位于 `/private/tmp/r101-merchant-h5-20261102`，未发布。
- PHP 相关文件语法检查通过；`reservation-lifecycle-frontend-contract.mjs` 通过。
- PHP 7.4 本地事务测试 `r101-reservation-project-link-mysql.php` 通过：新预约项目行关联新 ACTIVE 服务行，旧行 RELEASED；结束服务事务对最终预约行生成 1 条已购权益核销事实；时长及实际开始时间不变；测试末尾回滚并核对原行恢复。
- PHP 7.4 动作定义检查通过：仅 `IN_SERVICE` 返回新按钮，且位于时长按钮左侧。
- `r100-reservation-duration-mysql.php` 本地事务回归通过，7 项。旧 `reservation-lifecycle-contract.php` 在“预约新建和编辑不依赖工作台或预约资源版本”静态断言处失败；该断言对本轮新增命令无覆盖，本次未将其算作通过。
- 浏览器控制接口 `browser.user is unavailable`，因此未完成两端页面点击复测；结束服务仅在本地事务内执行并回滚，未执行线上核销。现阶段不得称为完整业务验收。

## 文件指纹（SHA-256）

```text
2c7e79870c9ee1af3645924abbc76d2702f58a6c345a75365f56989996849f82  前端代码/cashier-v3/src/components/reservation/ReservationDetailOverlay.vue
feefee8f0bf768195887b274340b5058871f529f45c43f506bec4e46f38682af  前端代码/cashier-v3/src/views/ReservationListView.vue
232cc76fa89d3e1669f250c0abd23a1f82b7db4789d01197d53ef88400c95912  前端代码/cashier-v3/src/services/cashierV3ActionManifest.js
87dd6b5cf66581ab1601dd52c4b3c006c3e33a94dcb962f887709180a544a244  前端代码/mobile-vue3/src/merchant/pages/reservations/index.uvue
12127949442078d7c69923c3b78feef5876ac5aef95fffcea0f1dd9ffb8d0bb1  前端代码/mobile-vue3/src/shared/api/mobile-reservation-client.uts
fcbe658b8b14730e180e4a2f32adb2f12ca09f714535b21e993b50df9decd6fb  后端代码/app/services/cashier/v3/reservation/CashierV3ReservationModule.php
52ce67cbbe95b86d2bf3f4bef9d62aef59255f7435e488587fd80c9c150e159d  后端代码/app/services/cashier/v3/reservation/CashierV3ReservationLifecycleServices.php
41d27257d1f64aa7500de145c973ecb5af145cb7b329fd46209362f08cd640b3  后端代码/app/services/cashier/v3/reservation/CashierV3ReservationDetailQueryServices.php
79974f8e051200b688bba4b529e82af1e1002edcf4618d5b7a7d2922931c589a  后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php
8e7f307df5d3dbe2cf2ba76d0ba9a05d219f705a7b9fbb78fe18c8f95e5a739c  后端代码/app/services/cashier/v3/manifest/CashierV3C3ServiceModule.php
f74d71df8922283a46fcedf32a0476a4c680072642d5d1e29a5d037ad2836061  后端代码/app/services/cashier/v3/registry/CashierV3PermissionGuard.php
60160de62c34c7650aa8ac6d38310acb485c0175a939c168acc76900d5e57ab4  后端代码/app/services/mobile/reservation/MobileReservationServices.php
81fb2fb84e29a115bd50d825df6ca6fc59afb59cab6aa62d180d2002bd859b8f  后端代码/app/controller/mobile/merchant/Reservation.php
32363cb28eae10db936a1b7ff809bbf27a3b0479f2d3fa512074c893b2830574  后端代码/route/api-mobile.php
d8d883f847731c72c5332b396d6bbfe1b0a9f7ade8e01d42e11cd1c4a70804a7  tests/cashier-v3/php/r101-reservation-project-link-mysql.php
```

注释已同步补充或更新于预约模块、服务生命周期、商家路由及两端页面；生成物不纳入源码提交。

## 验收与隔离提交

- 产品经理已确认上述固定源码文件指纹对应的业务候选验收通过，仅授权本地提交。
- 提交基线保留已验收的第100轮预约时长提交 `bf737d150` 与后续订单中心、小票提交 `bda82da5d`；两条历史的 Git 合并树为 `33737191c0472feb992c8d162c7694f8ed7c7a2a`，无冲突。
- 本轮只从固定候选提取上述业务文件及本专项测试、证据进入独立 Git 索引；共享工作区的其他暂存与未暂存改动不进入提交。浏览器点击复测限制仍如上文所述。
