# R63 · 无法回答时的易懂引导（本地开发与自测）

- 基线 HEAD：`1dc47d85edc89179ae898354ef213c312524b620`。本记录对应未提交工作区，产品经理尚未对固定版本确认验收；未部署瑞昊、未 push、未上传小程序。
- 改动范围：可理解但暂时失败的任务保留三项安全操作和“我来补充描述”；完全无关或尚无经营查询含义的提问直接说明魔核 AI 能查什么，不弹选项卡。重试沿用失败提问当时的前序历史和引用，不把失败当数据结果；网络结果未确认时保留请求编号，不创建第二个查询。清除了后端旧的逐错误码用户文案分支。
- 本次反馈的根因：本地获授权会员昵称“天天”碰巧跨在“今天天气”中的日期与天气词之间。旧脱敏把原句改成了“今会员 [local_condition_1]气如何”，模型因此误判为会员经营问题。现在复用日历解析器识别的完整日期片段，先保护公共日期再脱敏较短昵称；若会员姓名与整个日期片段完全同名，则仍按私有身份脱敏。日期单独出现也不再被算作没有前文的经营查询含义。这是脱敏边界和语义结构修复，不靠“天气”关键词拦截。
- 代码注释补充：`后端代码/app/services/ai/AiGatewayServices.php`、`后端代码/app/services/ai/semantic/AiSemanticIntentParser.php`、`后端代码/app/services/ai/contract/AiIntentResultContract.php`、`前端代码/shared/mohe-ai/browser-entry.mjs`、`前端代码/mobile-vue3/src/shared/components/mohe-ai-entry.uvue` 的脱敏、意图、失败投影、已知入队拒绝、跨天重问和上下文隔离边界。
- 固定源码/测试补丁指纹（仅下列已跟踪的十个文件）：`74acc6a7916593d37122e8df6b184841447d0146a0e7632572b9f8af217282a1`；新增的 R63 定向测试和本证据单独列出，不与工作区其他任务改动混合。

## 固定版本文件 SHA-256

| 分类 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/ai/AiGatewayServices.php` | `aeb0faa1d60e67c9a31799065372d919a980375b68d64f9fecffa0c88698b8bd` |
| 后端源码 | `后端代码/app/services/ai/semantic/AiSemanticIntentParser.php` | `67dde9652616ac357334c0ee75e8adaf094d7f1224be5a6c4694fb65e6222157` |
| 后端源码 | `后端代码/app/services/ai/contract/AiIntentResultContract.php` | `98b1a6d7d5bde4567a76dedf1aeb47f7a20c56b652c0855598fc5627042dc6a8` |
| 前端源码 | `前端代码/shared/mohe-ai/browser-entry.mjs` | `8fb39c2ed4384c54c23d8da910e1abda584dfad3ccc55eb120d0d12197e8070c` |
| 前端源码 | `前端代码/mobile-vue3/src/shared/components/mohe-ai-entry.uvue` | `de87d4da2eb52f37777f0552b4023826054a1e5d6c264416683fa4c8c20a751f` |
| 自动化测试 | `tests/mohe-ai/browser-guidance-contract.mjs` | `ac44cb0bcd3bbb51b998d2277bcda4e3b142cdd124fc052d3c66675b9f14a432` |
| 自动化测试 | `tests/mohe-ai/browser-mobile-guidance-contract.mjs` | `ee57cbd8077eaea224ad17f2f90f3e8ca4dcac6706122555a7ff703445ea7774` |
| 自动化测试 | `tests/mohe-ai/browser-entry-contract.mjs` | `f94375617e241075d91f8ffe4d89bde197cc96f6d9376738f282a4b94e9f9782` |
| 自动化测试 | `tests/mohe-ai/gateway-integration.php` | `870b7d35185adb150f1e8f79169d8ed7071c330c0eefb877da176bee988e4e91` |
| 自动化测试 | `tests/mohe-ai/intent-understanding-contract.php` | `3f20318400b006d91ad5c1092816c3dc75323912d3db99fa4d88eb2891bd9fa1` |
| 自动化测试（新增） | `tests/mohe-ai/r63-failure-guidance.php` | `c28667c8ae5f8a22a854d54eb430ecfa846718e708675cca5460f97ad5a95f53` |

数据库迁移和工程脚本：本轮没有修改。生成物：本地管理端热更新包和小程序编译产物，仅供自测，不入 Git 提交清单。

## 已通过

1. `php -l` 检查网关和日历解析器；`php tests/mohe-ai/r63-failure-guidance.php`：失败卡、纯帮助、公共日期不被会员昵称误伤、正常提及的会员昵称与同名私有身份仍脱敏，全部通过。
2. `node tests/mohe-ai/browser-guidance-contract.mjs`：195 项通过；失败卡四项、完全无关无卡、重复点击不重复入队、原问题及历史保持。
3. `node tests/mohe-ai/browser-mobile-guidance-contract.mjs`：214 项通过；手机端完全无关无卡、已知入队拒绝、旧服务端失败投影、重试保留原问题及历史。
4. `node tests/mohe-ai/browser-entry-contract.mjs`：通过；包括页面重载后的失败任务和入队拒绝引导。
5. HBuilderX 5.26 `launch mp-weixin --compile true`：20 页及魔核 AI 组件编译成功；生成的 WXML/JS 含四选项和补充描述。仅本地编译，**不等于小程序真机验收**。存在原有的 `span` 选择器警告。
6. 本地管理端 `18081` 真实页面：首次重问“今天天气如何”落入错误的“部分理解”卡。只读核对本地加密意图载体后证实安全投影已被会员昵称误改；修复并重新加载本地消费者后，同一句约 4 秒直接显示“我主要帮您查询门店经营数据……”，没有任何选项卡。紧接同会话提问“今天现金业绩是多少”，约 1 秒正常返回当日现金业绩和统计日期，未继承天气问句；再问“明天天气如何”，约 6 秒返回同类能力说明，也没有误用上一问经营结果或弹卡。这里仅证明本地问答链路，不代表瑞昊线上或真机已更新。
7. `php tests/mohe-ai/intent-understanding-contract.php` 250 项、`php tests/mohe-ai/semantic-guidance.php` 104 项、`php tests/mohe-ai/r50-calendar-range.php` 37 项、`php tests/mohe-ai/skill-semantic-projection.php` 21 项、`php tests/mohe-ai/analysis-object-resolution.php` 16 项通过；`git diff --check` 通过。

## 未冒充通过的检查

- `php tests/mohe-ai/gateway-integration.php` 已经过本轮失败引导断言，但随后停在既有 `Q001 gross includes every registered cash source` 金额断言；本轮没有改取数口径，此项未记为通过。
- `npm run check` 的全工程测试有 22 项失败，主要是既有商家端契约/接口文件缺失及其他工程断言；本轮定向魔核 AI 组件测试和小程序编译通过，不把全工程检查记为通过。
- 本地“今天现金业绩是多少”返回 0 元，仅是当前本地实例当日读数；没有拿它代替瑞昊线上经营数据对账。
- 2026-10-08 微信开发者工具模拟器：打开正确的 `mobile-vue3` 编译项目，以产品经理提供的测试账号登录商家端，页面显示“已连接瑞昊正式服务”，进入魔核 AI 后实际提问“今天天气如何”。模拟器约 27 秒后仍显示“我已理解部分条件，请选择您想查询的内容，或补充描述”的确认卡，仅有“我来补充描述”；因此**小程序连接瑞昊的这条真实路径未通过**。已取消该待确认查询。随后同会话问“今天现金业绩是多少”，4 秒返回“现金业绩为0元。统计时间：2026-10-08 至 2026-10-08。”正常经营查询通路可用，但该读数未经独立金额对账。小程序前端为本轮本地编译版本，瑞昊后端尚未部署本轮脱敏与语义修复；该结果不得写成小程序端已验收通过，也不以本地电脑端通过替代线上双端一致性验证。测试凭据未写入本记录。
