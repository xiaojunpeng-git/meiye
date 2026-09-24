# R32 预约跨店会员与权益项目：本地固定版本和自测

## 固定版本

- 基线 HEAD：`302f841c1a7b22cc786e0f3e65429b29f5899958`
- 本闭环九个源码／测试文件补丁 SHA-256：`43612ccf8d5cb9d8bd5aa60b51c6dfe34ffd1fb451a6e149ba455a0d4513586e`
- 状态：开发与 Codex 本地自测完成，产品经理已确认验收；待本地 commit、瑞昊部署和 push。

## 业务结果

- 预约选择会员新增“本店 / 全部”范围，默认本店；仅明确选择“全部”时查询数据权限内的全集团会员。
- 预约“已购买”项目不再按发卡门店过滤。只要权益明细仍有物理剩余次数，就保留展示。
- 预约已购项目复用收银“使用权益”的欠款、有效期、卡状态及跨店读取口径；不可用项目保留在列表中并显示具体原因。
- 预约读取不创建版本、不写收银草稿、不占用或扣减权益；预约保存和后续服务流程保持原有边界。
- 已升级卡的原订单被排除后，其遗留卡实例也不再进入预约权益投影，避免空订单欠款计算导致整个项目目录失败。

## 文件 SHA-256

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/cashier/v3/cashier/CashierV3EntitlementProjectionServices.php` | `07323c04f92211a9b8afc1bfef43b9d1fa5e58ac3b3f44fc55f73646a27db0ad` |
| 后端源码 | `后端代码/app/services/cashier/v3/reservation/CashierV3ReservationModule.php` | `557e33eab4202d116e74b00694945f7c35c1741f98e25b5d1cb27f5c422704b4` |
| 后端源码 | `后端代码/app/services/cashier/v3/member/CashierV3MemberModule.php` | `afe413446cf37d03c2df89dedcf263b7dd63c79977d085dd47973cfab81342f7` |
| 前端源码 | `前端代码/cashier-v3/src/layouts/CashierShell.vue` | `dcd3d75eebcae03bc15c78468d9be360dbd314478bebcf768aa4b3439b39d9ef` |
| 前端源码 | `前端代码/cashier-v3/src/components/reservation/ReservationEditorOverlay.vue` | `5123ae4606c171548937f59fb7c67672ac1aa4b6286d5dfea81aaa17471dfb6a` |
| 自动化测试 | `tests/cashier-v3/php/reservation-entitlement-project-contract.php` | `5d03e61efbc105c22512539ad1a7424decb162d1f299b6b83797d735a70afa0b` |
| 自动化测试 | `tests/cashier-v3/php/reservation-lifecycle-contract.php` | `5cba52e3f804eee674c17001d8017c823cafd9fb4e6c9625ee73c2dc6c001388` |
| 自动化测试 | `tests/cashier-v3/js/member-selector-scope-contract.mjs` | `620cf6b4ce298b3d4e4e453348e31575196e153cdbb52329590df2b5e9b3279a` |
| 自动化测试 | `tests/cashier-v3/php/member-integration.php` | `3b7f321f6c082b1ced9fc33336835779a85b7847c61bd276aabcc37876f1633f` |

本闭环无数据库迁移、工程脚本或生成物变更。业务注释已补充在统一权益投影、预约模块和会员范围分支。

## 自测与真实数据核对

- `member-selector-scope-contract.mjs`：6 项通过。
- `reservation-entitlement-project-contract.php`：8 项通过，包含已升级原卡遗留余次回归。
- `reservation-lifecycle-contract.php`：全部通过。
- `reservation-detail-projection-contract.php`：全部通过。
- `reservation-time-window-contract.php`：全部通过。
- `reservation-lifecycle-frontend-contract.mjs`：通过。
- 门店端 `npm run build`：通过（1972 个模块完成生产构建；仅保留既有大包体提示）。
- 三个变更 PHP 文件语法检查通过；九个文件 `git diff --check` 通过。
- 真实本地 `ruihao` 数据只读核对：会员 `1089280`、项目 `246083`、权益明细 `1833450` 返回物理余次 `21`、可用次数 `0`、欠款限制次数 `21`，禁用原因是“欠款限制后暂无可用次数”。
- 本地页面真实验证：预约选择会员默认勾选“本店”；切换“全部”后可查询到跨店会员“杨盛 / 13965667292 / 芜湖湾沚店”；已购买项目显示“新背部SPA(手工) / 背部SPA / 剩余 21 次”，并禁用显示“欠款限制后暂无可用次数”。
- 本地真实写入验证：为会员“林雅平”创建预约 `YY2609240006`，预约时间 `2026-09-24 18:00–19:00`，已购项目“背部spa”一项、计划手艺人“黄文弟”。日历显示“1项 / 待服务”，详情完整回显项目、权益来源、60 分钟时长、手艺人和备注；数据库主表、项目明细及人员安排三处事实一致。
- `member-integration.php` 的新增预约范围用例已经补充；独立运行时，旧测试夹具在新增用例之前被现有 `selector:member` 权限门禁拦截，未将该套件记为通过。该阻断不影响上述真实页面、真实数据及定向合同验证结论。

## 工作区边界

工作区同时存在其他任务改动。本闭环后续若获验收，只能逐个暂存上述九个文件与本证据文件，禁止整仓暂存。
