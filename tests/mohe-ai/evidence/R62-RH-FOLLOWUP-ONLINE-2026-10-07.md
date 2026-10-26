# R62 瑞昊上下文周几追问线上验证

- 目标实例：仅 `rh.cc3798.com`，独立数据库 `ruihao`；未改库、配置、前端或其他客户实例。
- 已验收源码提交：`41c6674b33526fbea0e244a65573c6b7dbf32d85`；瑞昊本次只替换 `app/services/ai/contract/AiIntentUnderstandingContract.php`，运行 SHA-256 `9cd6bdf0c7fd32fbc7115606f2a4980bf92992b27786298b32bd92902416ff03`。
- 替换前 SHA-256 `03aafe6d0a25bdefe39481ea3e5ee49c376748fe4a69f857b2ffe87aacb06d47`；可回滚单文件备份在 `/www/backups/rh.cc3798.com/r62-41c6674b.0P53tU2o/AiIntentUnderstandingContract.php`。更早 R62 全组备份另存于 `/www/backups/rh.cc3798.com/r62-5e9e301d.9sH4by65/predeploy-ai-files.tar.gz`。
- 部署前运行中 AI 任务数为 0；远端 PHP 7.4 lint 通过。替换后重启瑞昊 Swoole、AI 执行、导出、监督和权益索引进程。验后上述 4 个工作进程 active，Swoole manager_count=1，运行中 AI 任务数 0。
- 线上工作台同会话第 1 组：“上个季度营业额总的是多少” 21 秒，现金业绩 30,068 元，日期 2026-07-01 至 2026-09-30；追问“上周营业额最高是周几，最低是周几” 28 秒，最高周五 2026-10-02（325,846 元），最低周一、周二并列 0 元，日期 2026-09-28 至 2026-10-04。
- 线上工作台同会话第 2 组：首问 24 秒、同值同日期；追问 26 秒、同样的最高与并列最低结果及日期。两组均未出现模型理解契约失败。
- 结论：本轮功能线上验证通过；追问仍超过 25 秒（26、28 秒），延迟波动作为后续性能关注项，不能声称达成稳定 25 秒内。
