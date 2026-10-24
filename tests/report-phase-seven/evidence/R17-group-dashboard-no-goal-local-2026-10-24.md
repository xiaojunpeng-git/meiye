# 第 17 轮：集团看板无目标展示，本地待验收

- 源码正本：`美容源码/`；基线 HEAD：`df65e60a4d916f0f021ad00576314061eaeab1bf`。
- 本轮范围：无月目标或全年无目标时，经营卡和组织排行继续展示授权范围的实际业绩；未设置的目标、剩余目标及完成率显示 `-`。已有目标的分母和分子仍只覆盖对应门店与月份。
- 其他任务未验收改动：共享工作区存在大量独立改动；本轮不提交、不打包发布，也不把它们列入本轮成果。目标管理服务文件另有其他任务改动，本轮没有将其列入变更清单。
- 受影响既有功能：已配置目标的达成率、同范围实际业绩、无目标门店权限范围、组织排行、目标明细抽屉。无远程实例操作；线上版本、备份及回滚记录均不适用。

## 固定文件

| 类型 | 路径 | SHA-256 |
|---|---|---|
| 前端源码 | `前端代码/cashier-v3/src/views/GroupManagementDashboardView.vue` | `61107d49a7a68da5342c5bfd8e1419afa9fa99071ce5f0eb9f38e7cc8242c800` |
| 后端源码 | `后端代码/app/services/report/GroupManagementDashboardServices.php` | `f82865d4037725e656ac2737378e9b1aeea462679598b1b1673ce5abcf24c308` |
| 后端源码 | `后端代码/app/services/target/TargetSchemeReportServices.php` | `91c6470c1695eeeff988b659f28ab52ef946284d8bd0e48aeb633bc686d8e167` |
| 自动化测试 | `tests/report-phase-seven/php/group-dashboard-unconfigured-goal-local.php` | `9cdf90370996e6c6f35b8a7c59f6797d9656e3039d43271362ff57dfd6ec6388` |

三个业务文件相对 HEAD 的补丁 SHA-256：`8566f86754249c89d3247a3037dfcb7e2a193e73dc4b7689afc5b4056b3a1d86`。本轮无数据库迁移、工程脚本；`dist/` 为忽略的构建生成物，不是源码。

## 自测

- PHP 7.4 两个受影响后端服务语法检查通过；`git diff --check` 通过。
- 本地瑞昊数据库只读事务：无月目标仍有六张经营卡和门店排行，目标与达成率为空；全年无目标时月/年目标与达成率均为空；模拟非零实际业绩保持原值、不产生伪造完成率；已有月目标仍按统一事实及目标分母计算。`group dashboard unconfigured goal: PASS`。
- `group-dashboard-stability-contract.php` 通过。
- 门店端 `npm run build` 通过，包含 70 项结账快照预检查。构建入口仍为 `前端代码/cashier-v3/`，对应本地热更新 `18091`；这不是 8080 集成包或部署。
- 本地 `18081` 平台页与 `18091` 门店入口均返回 HTTP 200。HTTP 状态仅用于确认服务可达。
- 历史 `group-dashboard-optimization-local.php` 当前停在其销售类型列表顺序断言 `Sale-type card order or population is invalid`，因此不能将它记为通过；本轮专用无目标与已有目标只读回归独立通过。

## 状态

开发与自测完成，待产品经理对上述固定文件确认验收；尚未 commit、push 或部署。
