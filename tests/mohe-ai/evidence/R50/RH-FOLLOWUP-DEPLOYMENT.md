# R50 补充发布与双端线上测试

## 版本及授权

- 产品经理确认本轮固定版本验收及部署瑞昊后，提交 `9d90d5c8228b47617126e0706ce5f2c1de87bfb2`（R50 魔核AI完整日期查询与双端确认续查闭环）。
- 固定版本、自测和注释说明见 `CLARIFICATION-FOLLOWUP.md`；本记录不改写前次 `RH-DEPLOYMENT.md`。
- 本次没有 push，没有上传小程序体验版，没有修改生产数据库、客户配置或其他客户实例。

## 发布边界与回滚

- 目标：`rh.cc3798.com`，目录 `/www/wwwroot/rh.cc3798.com`，数据库标识 `ruihao`。
- 后端仅发布 AiGatewayServices、AiDateRangeGuidancePlanner、AiRegisteredPlanCompiler、AiRunStore、MetricGroupedProjection、MetricQueryDatePolicy、MetricReadViewServices 共 7 个文件。PHP 7.4 语法检查及发布后指纹比对通过。
- 平台构建源为当前 18081 对应 `前端代码/admin/`，复用共享魔核 AI 组件；生产构建通过。增量发布 `app.72567e27.js`、gzip 和 `system.html`；旧哈希资源保留。
- 后端归档 SHA-256：`a6d8bfa336c96e18da7ce770be7f6b93235ce06d606e914d5aceedfb3b5c7983`。
- 备份：`/www/backups/rh.cc3798.com/20260927-r50-followup-9d90d5c8/`，包含 `backend-before.tar.gz`、`admin-before.tar.gz`。
- 回滚恢复上述两个备份中的对应文件，重启瑞昊 Swoole 与 3 个魔核 AI 服务；没有数据库回滚步骤。
- 部署前无正在执行的非确认态任务。部署后 Swoole 与 execution/export/supervisor 服务正常，页面和新 JS HTTP 200。

## 线上实际页面测试

测试于 2026-09-27 执行；小程序使用微信开发者工具现有编译版本、连接瑞昊正式服务，没有切到本地后端。以下不是实体手机验收。

| 问题 | 电脑端线上 | 小程序线上 | 核对结果 |
| --- | --- | --- | --- |
| 2026年3月到今天，最高营业额是哪个门店 | 1 秒 | 5 秒 | 均为 2026-03-01 至 2026-09-27；港汇店现金业绩 49,080 元；没有历史接入边界确认或提示 |
| 2026年3月1日到3月31日，营业额最高的是哪个门店 | 2 秒 | 4 秒 | 均完整保留 3 月区间，提示暂无符合条件的现金业绩记录；无虚构零值第一名 |

截图：`desktop-online-full-requested-range.png`、`desktop-online-empty-requested-range.png`、`mini-online-full-requested-range.png`、`mini-online-empty-requested-range.png`。

额外检查：电脑端超过 366 天的查询显示编号单选确认卡，等待选择时不显示思考计时；用户选定区间后才继续，不静默裁剪。选择 2025-09-27 至 2026-09-27 后 1 秒返回对应区间结果，无残留继续查询状态。截图 `desktop-online-clarification-card.png`、`desktop-online-clarification-result.png`。

## 测试环境限制

- 原有浏览器页入口未响应；重新打开同一线上地址后可正常进入并完成测试，未修改业务代码绕过。
- 小程序两条核心回归已完成；继续输入额外超长区间时，电脑控制连接出现窗口不可用/剪贴板超时，重新绑定后仍不能可靠输入，因此不声称本次线上小程序超长区间续查已通过。此前本地确认卡、等待超过 3 分钟、过期终态回归记录保留在 `CLARIFICATION-FOLLOWUP.md`。
- 本轮截图、测试会话及历史记录均保留。实体手机验证及体验版上传不在本次完成声明内。

## 产品经理验收确认（2026-09-28）

- 产品经理在上述发布与测试结果交付后明确回复：“我测试没有问题了”。本轮已部署版本 `9d90d5c8228b47617126e0706ce5f2c1de87bfb2` 验收通过。
- 用户未逐项列出设备和测试步骤，因此不将此确认改写为 Codex 已完成额外小程序超长区间或实体手机测试；前述技术测试限制按历史事实保留。
- 本次仅归档该发布记录及 6 张线上测试截图，无新增业务代码、数据库迁移或构建产物。其他任务的工作区改动保持原状。
- 本次确认不扩展为 push 或上传体验版授权。
