# R30 瑞昊默认排行与运行版本一致性部署证据

- 部署时间：2026-09-21 00:26（Asia/Shanghai）
- 目标实例：瑞昊 `rh.cc3798.com`
- 部署源码提交：`8ae28b58249c11ea9fd4e7f63921088a51a5435f`
- 数据库迁移：无
- 其他客户实例：未变更

## 部署边界

当前本地工作区存在其他任务的未提交修改，因此未从工作区直接发布。先从固定提交导出只读发布副本，再精准同步本闭环的五个生产 PHP 文件，没有夹带预演中发现的其他已提交或未提交差异。

## 备份与回滚

- 部署前回滚目录：`/www/backup/mohe-rh/20260921_002603-r30-rank-runtime/`
- 回滚包：`backend-before.tar.gz`
- SHA-256：`efad78ebf3ba71e4cb5d61191f96ce93cc3f7aad085e1e5d2a990ec4b83bcddb`
- 回滚范围与发布范围一致：AI 网关、意图合同、能力引导、模型客户端及指标注册表。

## 运行时验证

- 已清理瑞昊应用缓存。
- 已成组重启 Swoole、`mohe-ai-execution-worker@1.service` 与 `mohe-ai-supervisor.service`。
- Swoole manager 数量为 1；AI Worker 和 Supervisor 均为 active。
- 线上五个目标文件 SHA-256 与固定提交导出副本逐一一致。
- `https://rh.cc3798.com/admin/setting/mohe-ai` 返回 HTTP 200。

## 真人验收

在瑞昊线上新对话输入“哪个员工业绩最高”，系统直接完成查询并回答：

> 本期间没有符合当前筛选条件的销售人业绩数据。统计时间：2026-09-21 至 2026-09-21。人员范围：本期有业绩归属的人员。

该问题没有再进入“人员范围”或“选择指标”澄清，符合默认专业首答的验收预期。

## 自动化回归

- 8 组回归共 550 项检查通过。
- `gateway-integration.php`：113 项通过。
- `intent-understanding-contract.php`：127 项通过。
- 发布脚本 Bash 语法、相关 PHP 语法及 `git diff --check` 通过。
