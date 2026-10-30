# 第97轮问卷奖励按会员门店发放：本地固定候选

- 唯一源码正本：`美容源码/`；基线 HEAD `6e959c83249d1d9358a24b071649f619f63a78b3`。本轮仅本地开发；线上版本、备份和回滚点不适用。
- 业务口径：总部配置项目、实物产品、优惠券，不预选发放门店。已有归属会员按归属门店发奖；无归属会员在提交问卷时选“请选择您需要服务的门店”，同事务写入归属和答卷，奖励快照冻结该门店。近期到店不改变归属。已发布的旧固定门店奖励仍按原配置快照履约。
- 权威源：会员 `belong_store_id`、问卷答卷和奖励应发记录；实际赠送复用 010 独立赠送服务及 `gift.issued` 事实。成功重试使用原奖励幂等键，失败保持待发；不产生销售或收款事实，不修改历史奖励。
- 商品配置按总部商品及规格；发奖时以商品父 ID 与规格名称解析会员门店 SKU。只在唯一匹配时发放；缺失或歧义保持待发，避免错发。优惠券新配置只接受总部通用券。

## 文件指纹（SHA-256）

| 分组 | 文件 | 指纹 |
| --- | --- | --- |
| 前端 | `前端代码/admin/src/components/goodsAttr/index.vue` | `5e937338c98ba600afa05e043ddfd183176c90e4e44a28d5152207b2f560e03f` |
| 前端 | `前端代码/admin/src/components/memberSurveyReward/index.vue` | `4b622af22bea0f8518fea0c9eab2e7fd495a6ca4abc2bbe0768423bac0c6c89f` |
| 前端 | `前端代码/uniapp/pages/users/survey/index.vue` | `9dc9c9f25477b73da5924b74f5c9a43903d146ac2255fd142c6c3b25017a1ed7` |
| 后端 | `后端代码/app/services/user/MemberSurveyGiftServices.php` | `fe58f95d50c208a5362745a8e9db177231a95194432394c6629ab091892a9a6a` |
| 后端 | `后端代码/app/services/user/UserBelongStoreServices.php` | `82323187243dd07b4bc0f7b4792194ce5fafcb8a1d9ba866527bed188a7967cf` |
| 测试 | `tests/member-survey/php/local-business.php` | `ccada367cb9abaecd54f9e8e0fccbb8654d68f3d5e4fbe952df3dd9883617f4d` |
| 测试 | `tests/member-survey/js/browser.cjs` | `5f8dbcb23e9c694312551117907a25506b70f089cd54e5c5f20e471386f0e3e3` |

`goodsAttr/index.vue` 和 `UserBelongStoreServices.php` 含共享工作区其他任务的既有未提交改动；本轮仅属于新增问卷选品入口及一处问卷提示文案。提交前必须隔离差异，不可整文件暂存。

## 自测

- 平台生产构建通过（原组件 HTML 解析告警仍在；无构建失败）。PHP 7.4 语法检查、`git diff --check` 通过。
- 真实 Vue 组件的隔离浏览器测试 24 项通过；截图与机器结果见本目录 `browser.json`、`member-store-required.png`、`member-completed.png`。
- PHP 7.4 本地隔离库测试跑通本轮路径：总部项目/实物目录、分页、类型限制、已有归属会员发奖、首次选店后同次答卷发奖、归属门店快照、项目和产品门店 SKU 映射。临时库和测试账号已清理。为了单测本轮路径，测试副本跳过旧脚本的“总部发奖不冒认同号门店员工”断言，并在本轮断言后结束；源码测试未删该断言。完整旧脚本还依赖当前缺失的 `/scripts/member-survey/install.php`，故不得称完整回归通过。
- 本地目录只读核查：总部项目/产品 1,343 个，门店商品副本 290,763 个；总部与门店规格唯一键同值匹配数为 0，规格名称同值匹配 285,037 条；有 25 组商品内规格名称重复，代码对歧义拒绝猜配。

本轮新增/修改业务注释见选品控件的受控目录说明、奖励配置与门店解析、会员首次归属事务说明。无数据库迁移；平台 `dist` 为生成物，不作为源码。当前状态：开发与针对性自测完成，待产品经理对以上固定候选确认验收；未提交、未部署、未 push。
