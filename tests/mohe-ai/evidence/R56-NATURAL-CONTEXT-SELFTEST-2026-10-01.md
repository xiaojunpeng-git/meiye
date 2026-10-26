# R56 自然语言追问与来源上下文修复：本地自测记录

- 基线 HEAD：`2656c4dd0a2fbb25fd79bcf021d689024544ecc5`。本记录对应未提交的本地工作区；产品经理验收前若下列文件变化，须重新固定版本并补验。
- 范围：仅魔核 AI 后端和永久测试；无前端源码、迁移、工程脚本或生成物。未部署瑞昊，未推送，未上传小程序。
- 本地数据边界：`ruihao` 本地 MySQL 实例 UUID 固定校验，真实网关调用使用当前 admin 权限；不改业务数据。测试仅产生 AI 会话运行记录。长驻 AI worker 在代码更新且确认无运行中任务后重启，确保实测执行新代码。

## 问题与处理

原“这个月来源 A 的会员有几个 → 上个月呢 → 明细呢”中，旧来源专用追问只识别少量固定说法，“明细呢”绕不过签名上下文合并，导致失败。删除该追问分支，详情、列出、展开等开放式追问统一交由语义理解和已验证结果引用处理；编译器仅提供 `object_kind` 占位时，允许补齐同对象的服务端签名来源及门店条件，显式冲突继续拒绝。完整的“来源 B 的会员有几个”仍走封闭的已注册来源问句入口：若上一问也是来源会员口径，保留其已签名时间和门店范围；若换话题则不继承旧来源。没有扩展为对任意短词的关键词兜底。

已扫描旧 `hasPriorSource` 参数、旧来源追问分支及对应断言引用，零遗留。修改的复杂分支已补职责与安全边界注释。

## 验证

| 场景 | 本地真实结果 |
| --- | --- |
| 2026 年 9 月来源 A 首问 → “会员详情” | 28 人详情，约 8.7 秒 |
| 同首问 → “明细呢” | 28 人详情；另在原失败的本地浏览器会话重问，约 9 秒成功展示 28 人 |
| 同首问 → “把这批会员列出来” | 28 人列表，约 12.6 秒 |
| 同首问 → “具体有哪些会员” | 28 人列表，约 12.1 秒 |
| 同首问 → “把这些会员逐个展开看看” | 28 人详情，约 9.2 秒 |
| “这个月来源 A” → “上个月呢” → “明细呢” | 0 人 → 9 月 28 人 → 同一 28 人详情；第三问约 8.8 秒 |
| 来源 A → 会员详情 → 第一位会员权益 → 换来源 B → 换时间 → 今天现金业绩 | 6 问均成功；时长约 2.5、7.7、8.6、1.4、1.6、1.4 秒；来源切换保留原时间，换话题不带入来源 |

上述来源人数仅是当前本地副本及本次权限、时间范围内的核对值，不写死在业务代码。五种详情相关表达和上下文序列均低于 25 秒；不能据此声称所有自然语言表达均已覆盖。

本地只读数据对账：来源 A 会员计数、列表和详情均为 28；与报表口径一致；`business_data_mutated=false`。`query-context.php` 67 项、`semantic-binding-guard.php` 12 项、`semantic-guidance.php` 104 项、`object-detail-continuation.php` 71 项、`context-metric-rebinding.php` 7 项通过；变更 PHP 文件语法检查及 `git diff --check` 通过。

## 固定范围与后续门禁

后端源码：

- `后端代码/app/services/ai/AiGatewayServices.php` SHA-256 `de3fc159363fe0d5173916d7bb1a1dfdd73b3dc2b18da92ff629ddafd7c0c525`
- `后端代码/app/services/ai/context/IntentContextMerger.php` SHA-256 `dd2d1f2a6a02bdd378e4a044c164777c0fffcf49c05de505ce71347b944d48a5`
- `后端代码/app/services/ai/semantic/AiFirstCourseSourceAdmission.php` SHA-256 `930a83d6c18014095624c5fb6bd4c8bad43d3506494c29ef69719df45d2b396a`

自动化测试：`tests/mohe-ai/query-context.php`、`tests/mohe-ai/r56-source-local-readonly.php`、`tests/mohe-ai/r56-source-gateway-local.php`、新增 `tests/mohe-ai/r56-natural-followup-gateway-local.php`。其余工作区已有改动不属于本闭环，不得一并暂存。产品经理尚未对本固定版本确认验收；因此未 commit，也没有部署、push 或体验版操作。
