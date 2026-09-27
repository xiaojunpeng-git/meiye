# R49 补修：登录失效后停止等待

## 范围与状态

2026-09-27，本地基线 `ad350a508258f0c89b5e3bb4cad860caa4b5f1c1`。
已完成实现与针对性自动回归。产品经理随后明确授权“修复的部分你自测通过后就可以”，
本次按该授权提交并上传小程序；瑞昊后端不在本次上传操作中变更。
本次没有改变商家登录有效期、权限规则、数据库、模型或经营指标口径。
不触碰其他任务正在修改的商家认证服务、manifest 或收银代码。

原问题证据继续保留在本目录 `mini-session-expired.png`、`mini-online-initial-wait.png`
及 `RH-DEPLOYMENT.md`，没有删除测试会话或证据。

## 原因与修复

- AI HTTP 入口把共享移动端的登录异常归类成 `AI_REQUEST_FAILED`。现仅对明确的
  `MobileApiException` 复用统一响应，保留 HTTP 401、`MERCHANT_SESSION_EXPIRED`、
  请求标识及会话失效原因。普通内部异常仍隐藏敏感细节。
- 手机端提交被拒绝后只重置提交按钮，没有停止计时及清理等待气泡。现同时停止等待，
  恢复用户输入，显示失败原因；断网仍保存原请求标识，重试不另开重复任务。
- 登录在轮询、恢复、确认或取消过程中失效时，停止观察并提示：
  “登录已失效，请退出魔核 AI，重新登录商家端后再试。”
  已接纳任务的恢复记录仍按原账号隔离保留，不擅自宣布取消，不改变身份或权限。
- 所有修改的业务源码均补充了职责与边界注释。替换原错误分支，没有保留平行实现；
  本次没有需要删除的独立旧模块。

## 用例与执行结果

命令均从 `美容源码/` 执行，不连接生产库、不使真实用户登录失效。

| 用例 | 预期 | 自动回归 |
|---|---|---|
| 提交时登录过期 | 401 正确分类；未调用数据/模型；停止计时，恢复输入 | 通过 |
| 本地登录信息缺失或上下文无效 | 明确未发送，不当作网络未知结果 | 通过 |
| 普通提交失败 | 显示原因，停止等待，允许重新输入 | 通过 |
| 服务端拒绝接纳 | 停止等待，保留输入 | 通过 |
| 网络无响应后再发送 | 保留同一请求标识，恢复等待气泡 | 通过 |
| 已接纳任务轮询中登录失效 | 停止轮询和计时；保留恢复记录 | 通过 |
| 旧页面延迟响应 | 不覆盖新页面状态 | 通过 |
| 内部未知异常 | 不泄露 SQL、提示词等异常详情 | 通过 |

- `node tests/mohe-ai/mobile-login-wait-contract.mjs`：10 个场景通过；使用 HBuilderX
  随附 TypeScript 编译器解析两个完整 UTS 脚本，并执行源码函数的状态测试。
- `php tests/mohe-ai/http-actions-contract.php`：41 项真实框架路由匹配、21 项 HTTP 断言通过。
- `php -l 后端代码/app/controller/ai/AiHttpActions.php`：通过。
- `git diff --check`：通过。

限制如实保留：旧 `mobile-ai-entry-transport-contract.php` 在胶囊布局断言失败，
仍要求已被历史版本移除的 `capsuleBottom + 8`；HEAD 基线同样不含该实现。
本次没有为通过旧测试改回已确认的布局，也不宣称全套旧测试通过。
独立调用 UTS/Rollup 完整编译尝试缺少 HBuilderX `preUVueJs` 上下文，未完成；
上述 TypeScript 解析与状态测试不等于实体手机验收。
后续上传前补验：HBuilderX 5.26 原生 CLI `launch mp-weixin --compile true`
于 17:27:04 完整编译成功，耗时 5485ms；保留既有 span 选择器警告。
以唯一 mobile-vue3 源码生成开发构建，上传阶段压缩，不宣称发行模式构建。
未修改用户 manifest，只为派生 project.config 恢复既有瑞昊 AppID 并开启压缩。

## 发布后的人工验收项（尚未执行）

1. 在受控测试环境确认失效登录下提问立即显示重新登录提示，等待计时不再增长。
2. 正常重新登录后提问、追问、生成 Excel 不受影响。
3. 对处理中登录失效场景确认不再后台无限轮询；重新进入后按原账号恢复任务。
4. 发布需同时更新 AI 后端文件与小程序体验版；仅部署 PHP 不会更新手机输入区状态逻辑。

## 固定文件清单

前端源码：`mobile-ai-client.uts`、`mohe-ai-entry.uvue`（路径见下）。
后端源码：`AiHttpActions.php`。自动化测试：两个契约测试。
数据库迁移、工程脚本及新增生成物：无。验收证据：本文件；其余历史证据不变。
仅本地验证，客户实例备份与线上迁移核对不适用，未改变任何线上实例。

SHA-256：

```text
147ee5d3aa3cb230d140b565a2851d9738606c6a53d3889065798fac469218ca  后端代码/app/controller/ai/AiHttpActions.php
1301f15ac1b2719697d6d1281a29157f5261b0defa956fdbd9d1fb2c5e86d84f  前端代码/mobile-vue3/src/shared/api/mobile-ai-client.uts
26a985e05cfb852bba9bbc6a7de4862a5169084fee9356a5c199d42f4b19b50d  前端代码/mobile-vue3/src/shared/components/mohe-ai-entry.uvue
8d489d9d53d51e1bc7865d517fe7b05b219ab89dd66890dee32d98ac5eed7de6  tests/mohe-ai/http-actions-contract.php
53707cb98856acdda265dcb5c32dca1fa1e93dd32c3f7c20e65edb5f3f51472c  tests/mohe-ai/mobile-login-wait-contract.mjs
```

上述四个已跟踪源码/测试文件的 `git diff` 补丁 SHA-256：
`5dfda55d1a0cc426dc907070d4959d35371a31ea3ddf7d03ef9eef64e36630bf`。
新增状态测试单独以上述文件指纹固定。
