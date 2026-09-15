# MOHE-AI-00004 · 可靠异步执行

本目录只交付迁移正本，不表示已经在任何客户实例执行。

- 新表只保存实例、受控消费者 ID、主机名、PID 与心跳时间；不保存问题、历史、模型输入、答案、指标或结果。
- AI 执行使用 `MOHE_PRO_AI_EXECUTION:<实例哈希>` 专属 Redis 队列；普通默认队列不得消费该队列。
- 只能通过 `php think mohe-ai:execution-worker` 启动消费者。它的实时心跳是异步开关的必要条件，环境变量中的 `true` 不能替代实际进程。源码提供两个无状态启动入口：`scripts/run-mohe-ai-execution-worker.sh` 与 `scripts/run-mohe-ai-supervisor.sh`；生产实例使用 `scripts/install-mohe-ai-systemd.sh` 安装为两个独立 systemd 服务并启用自动重启，不能用一次性 SSH 命令代替。
- 已领取的 Run 不自动重跑。监督器只在**同主机 PID 已证明不存在**时，把 Run 标记为 `AI_EXECUTION_WORKER_LOST`、将未完成尝试标为 UNKNOWN 并释放物理槽位；跨主机、无 POSIX 证明或 PID 仍存活时保持隔离，不猜测停止。
- 排队不会延长 180 秒执行预算：超过截止时间的未领取任务直接终止，监督器会摘除并删除其加密临时输入；重复浏览器确认产生的未采用输入也会立即删除。
- 上线前分别核对：目标实例、备份与回滚点、迁移结构、专属 Worker、`mohe-ai:supervise --watch`、消费者重启、断网/取消/权限变化和真实耗时分段记录。
