# 008 商家端旧余额结余行移除：固定验收版本

- 基线 HEAD：`533d1c5ca0147090a3a808f17f43cb7a24241793`。
- 本次两文件补丁 SHA-256：`7f2bbede935722e1cb3cf6703fc387c203ccf99375e21cac7c3aa8b4fbe8bd48`。
- 前端源码 `前端代码/mobile-vue3/src/merchant/pages/customer-detail/index.uvue`：`2d50fd40e6a01707644226b5fdcf920c2091f1b185d3a758ee3c786346fe3702`。旧站流水卡片去掉“旧站账户当时结余”整行；说明文案不再提及未展示的结余。V3 流水的本金、赠送及余额结构保留，当前余额和历史变动额的口径不变。同步更新了旧站历史展示边界注释。
- 自动化测试 `tests/mobile-vue3/js/customer-page.test.mjs`：`21d4fdbe7fb6ff169c8131004b4f2801a81e9a565a362013b7b9635ff047db33`。断言旧站结余文案不存在，保留 V3 拆分及旧站历史请求开关断言；修正同一页面测试中早已过时的共享模块导入路径断言。
- 后端源码、数据库迁移、工程脚本、生成物：无变更。验收证据为本文和本地 H5 截图 `2026-10-13-merchant-balance-row-removed-local.jpg`，截图 SHA-256：`4230125f2f7cbe9191f2396ca52523013811385fe427148bfec08c662063a916`。

## 自测

- HBuilderX Node 单项页面契约测试 `customer detail re-queries the selected member within the current merchant scope`：1/1 通过。
- `git diff --check` 通过；定向静态核对确认旧文案已删除，V3 拆分显示和 `acceptLegacyBalanceHistory: true` 仍在。
- 本地商家 H5 `127.0.0.1:18104` 实际查看 PoPo 余额异动：旧站流水仍显示时间、原门店、变动额及业务说明，结余行消失；客户页顶部当前余额仍为 ¥13,692。
- 未改远程数据库、后端或 008 线上文件；未上传小程序体验版、未推送 Git。产品经理验收通过后按项目规则仅提交本闭环，不把本次页面指令推断为新部署授权。
