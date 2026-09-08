# MOHE-AI 前端交互验证

范围：平台 Vue2、门店 Vue3、商家 uni-app x 的 AI 薄入口。只新增 AI 组件及挂载，不改变原收银或报表布局。前端不计算经营指标。

## 已验证

- `node tests/mohe-ai/device-session-contract.mjs`：18 项通过（20 完整轮、账号/实例隔离、24 小时过期、迟到响应及安全下载地址）。
- `node tests/mohe-ai/browser-entry-contract.mjs`：12 项通过（bootstrap 与 create 设备标识一致、create/execute、取消、迟到完成不覆盖 CANCELLED、正文按文本展示、设备保存、能力匹配提示与 Excel 禁用）。该测试使用项目既有 jsdom；其 Shadow DOM shim 仅测交互，不代表浏览器布局验收。
- `node tests/mohe-ai/browser-transport-contract.mjs`：9 项通过。GET 的设备标识、generation、delivery token 只放 `X-Mohe-Ai-*` 请求头，不放 URL；POST 正文不交给通用请求日志器。
- `node tests/mohe-ai/browser-workflow-contract.mjs`：35 项 DOM + browserTransport HTTP 夹具检查通过，覆盖配置后启用发送、连续提问携上一轮历史、新对话空历史、切回历史、创建未回执时关闭/销毁后补取消且不执行、只读运行计数和监控状态不发起模型自检、未知告警不泄露原代码、缺字段不推断正常。不是账号真人或浏览器布局验收。
- `php tests/mohe-ai/gateway-components.php`：57 项离线组件检查；所有 cURL 调用由测试命名空间 fixture 截获，无真实 HTTP。
- `php tests/mohe-ai/gateway-integration.php`：46 项真实 SQLite 状态／配置与文件存储集成检查；模型和业务事实适配器为 fixture，覆盖汇总、趋势、排行、对比、澄清、幂等、取消、权限变更与完整指标口径。不是生产数据验收。
- 门店 Vite build 通过，1974 个模块；输出到 `/tmp/mohe-ai-cashier-build`，非 8080 集成发布包。
- 平台 webpack build 通过；输出到 `/tmp/mohe-ai-admin-build`，保留既有 `App.vue` unused variable 与大包警告，未顺带修改。
- 商家 HBuilderX uni-app x H5 build 通过；输出到 `/tmp/mohe-ai-mobile-build`。不等于 Android/iOS/小程序实机验证。
- 2026-09-08 在 Codex 浏览器操作 `tests/mohe-ai/browser-fixture.html`：悬浮入口打开、发送测试问题、校验后展示测试卡、关闭后仅剩入口。发现并修正 CSS 对 hidden 的覆盖。该夹具明确标注测试数据，不连接经营库或 SiliconFlow。

## 最终联调准备（2026-09-08）

- 基线 HEAD：`90eadab2d73afe6307052187e55ee0141ec488f1`，本地唯一源码正本开发，无提交或部署。
- Chrome 实际访问 `http://127.0.0.1:18081/admin/login` 显示账号登录；访问 `http://127.0.0.1:18091/view_cashier_v3/#/cashier` 自动转到 `#/login`。无现成登录状态，未填账号、密码、API Key，未重置密码。故实际账号下的浮窗、配置空态和真实问数仍未完成真人联调，不能用夹具替代。
- 三端卡片展示真实指标字典的业务白名单字段 `summary/include/exclude/timing/note`，不展示技术字段，不在前端补编口径。Gateway 测试新增完整口径对象检查。
- 最新本地构建输出：`/tmp/mohe-ai-cashier-final`、`/tmp/mohe-ai-admin-final`、`/tmp/mohe-ai-mobile-final`；均为临时技术验证产物，不是 8080 集成发布包。商家仅 H5，不代表原生实机验证。
- GET 请求只用绑定头，不向 URL 写入设备会话或 Run 交付凭证；未开放的 Excel 根据后端能力禁选。
- 平台入口改复用既有 `Setting.apiBaseURL` 和统一 `__getToken`/Cookie 获取，不再错误请求热更新服务器的 SPA fallback。实际热更新 bundle 的基址为 `http://127.0.0.1:18093/adminapi`；curl 请求其 `/ai/bootstrap` 得到 JSON 登录拒绝与 `Cache-Control: no-store`，携带 18081 Origin 的预检允许 AI 绑定请求头。浏览器直接打开 API 被浏览器客户端拦截，不能称浏览器已完成登录后联调。
- 能力提示按 bootstrap 使用已开放的消耗指标示例或通用提示；配置保存后刷新 Excel 可用性并在不可用时复位为只看数据。
- 上述接口修复的最新临时构建输出为 `/tmp/mohe-ai-admin-endpoint-final`、`/tmp/mohe-ai-cashier-endpoint-final`、`/tmp/mohe-ai-mobile-endpoint-final`，不替代发布包。
- 配置面板新增已有后端返回的近 24 小时完整成功、数据已出但文件未生成、技术失败、处理中计数；仅展示存在的非负整数，不相加或推断余额/模型可用性，无额外外发。对应桌面最新构建为 `/tmp/mohe-ai-admin-verified-final` 与 `/tmp/mohe-ai-cashier-verified-final`。
- 管理员运行监控只展示后端结果及八类已登记业务告警，阈值未登记或信息不完整不判断健康；阈值未登记时已有清理失败也不隐藏。最新桌面临时构建路径更新为 `/tmp/mohe-ai-admin-monitor-final`、`/tmp/mohe-ai-cashier-monitor-final`，移动无本轮变更。

## 待整合验收

- 使用真实后端 bootstrap、create、execute、status、cancel、clarify、export 和 config 接口验证三端账号归属与权限。
- 验证岗位入口配置、API 配置并发版本冲突、外发授权、真实导出、超时/容量/取消竞态。
- 验证移动原生文件打开与生命周期；浏览器下载或用户另存文件属于设备下载内容，不是服务端临时文件清理证明。
- 三端上线前按固定源码指纹重新构建；临时构建不构成发布授权。

## 实现边界

聊天正文仅设备保存，按 bootstrap 的服务端 `identity_key` 分区；每次提交最近 20 轮。创建和执行请求携带相同问题与历史，创建端只用于后端幂等摘要，正文不得入库或队列。Run 回执只由后端终态决定；断网显示未知，不能仅 Abort 或关闭面板伪造取消。

桌面共享 Shadow DOM 面板，移动使用独立 UTS/UVue 适配。设备文件可用性不授予新权限；历史记录不拿来生成 Excel。API 密钥只短暂存在配置输入框，请求完成即清空，不写本地历史。
