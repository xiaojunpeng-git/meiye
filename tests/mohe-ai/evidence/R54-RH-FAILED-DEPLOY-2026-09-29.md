# R54 魔核 AI 瑞昊发布验证失败与回滚（2026-09-29）

- 固定本地提交：`dfad0f2516361700da805929b0550b4376409c57`，产品经理在本地验收后明确要求部署瑞昊并 push。本次只尝试部署该提交中的六个后端 PHP 文件；测试与证据文件不上服务器。无前端构建、数据库迁移、配置改动或其他客户实例操作。
- 目标：`rh.cc3798.com`，运行目录 `/www/wwwroot/rh.cc3798.com`，独立数据库配置为 `ruihao`。部署前最新升级登记为 `20260928-001-inventory-stock-count-draft` / `c5ae5b50`，非终态魔核 AI Run 数为 0；六个目标文件的线上指纹均等于本地提交的父版本 `3ff562f5`。
- 发布归档从固定提交 `git archive` 生成，SHA-256 `dc8097483893f66e1b866c9658eb5421ee35a77b0e5e2f6bf8d22644a6daff60`。独立备份及暂存位于 `/www/backups/rh.cc3798.com/20260929-r54-dfad0f25-deQndb/`，部署前六文件备份 `backend-before.tar.gz` 的 SHA-256 为 `dd7a025f95166e7e9712e360f5fa88ba91f3b52c8f7b3a42ba48adf40f74585d`。暂存文件经 PHP 7.4 语法检查，通过后逐文件替换；瑞昊 Swoole、AI execution/export worker 与 supervisor 成组重启，六文件线上指纹与固定提交一致。
- 线上使用已登录账号在 Chrome 新标签打开真实工作台，新会话发问“2026年消费4980的有多少个人”。页面用时 10 秒，但仍返回“已识别分析方向，但当前底层尚不能完整执行这些对象、指标与条件的组合”；没有人数结果。只读运行记录显示该次 `platform` Run 为 `FAILED` / `AI_ANALYSIS_COMBINATION_UNAVAILABLE`，发生在数据查询前。安全诊断为 `exact_registry_binding_miss:r2:o0:t2:m0:s0:x2:e0`，随后 `analysis_object_condition_metric_unavailable`；可判断模型仍产生两个指标要求，但不能仅凭这些不含原文的诊断断定第二个词是什么。
- 因目标问句线上未通过，立即从上述独立备份恢复六文件并成组重启相同服务。回滚后六文件指纹逐项恢复到部署前值；Swoole 为单 manager、端口 20800 监听，三个 AI 服务均 active，管理页 HTTP 200。备份保留以供复核，未修改业务数据。
- 发布结论：本次 R54 部署**失败并已回滚**；本地提交仍保留，`push` **未执行**。前次本地通过不能替代该线上失败样本。须针对真实的两个指标要求修复并重新自测、取得产品经理对新固定版本确认，再重新部署验证；未通过线上验证前不得推送。
