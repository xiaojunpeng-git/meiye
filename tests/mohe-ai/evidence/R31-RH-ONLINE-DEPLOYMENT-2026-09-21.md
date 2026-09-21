# R31 瑞昊指标分层与能力保全线上部署验收

- 部署时间：2026-09-21 08:11—08:22（Asia/Shanghai）
- 目标实例：瑞昊 `rh.cc3798.com`
- 部署源码提交：`1dd92c9e1e53b857059e6ca9c3e2c7871fe4ca48`
- 数据库迁移：无
- 配置变更：无
- 其他客户实例：未变更

## 发布边界

本地工作区存在其他任务的未提交修改，因此本次从固定提交导出只读发布副本并重新构建平台端，只同步以下源码拥有的发布单元：

1. `app/services/ai/`
2. `app/services/query/metric/`
3. `public/view_admin/`
4. `public/system.html`

未同步本地 `.env`、运行目录、上传文件、数据库升级文件或其他任务的工作区修改。

## 备份与回滚

- 备份目录：`/www/backup/mohe-rh/20260921_081158-r31-metric-layering/`
- 后端备份：`backend-before.tar.gz`
  - SHA-256：`69408b6955307ae33c49f6933f0e213930ea1fc4a2a4c6c75bb47c2d845104ca`
- 平台端备份：`admin-before.tar.gz`
  - SHA-256：`ddfc46edba126b6ba63bcb9124bee828e0b9a96bc22f2cb89fea4c86fe4a6a2d`
- 回滚方式：恢复以上两份备份，清理应用缓存，并成组重启瑞昊 Swoole、AI execution worker 与 AI supervisor。

## 发布一致性

固定提交发布副本与瑞昊运行目录的整树 SHA-256 指纹一致：

- AI 命名空间：`d6d165bdbb21c2839f583fa88811f73d4a98ec39e234eda38850aaa18a6f7ee3`
- 指标注册命名空间：`f17f170355378734fc7dff46d64ed155c62bf6757e4b7cd9c8c33993a44f6c5d`
- 平台端 `view_admin`：`5d973807c35f27991b5fc044f38b0a94e3892562b479232563da384d8c38e159`
- 平台端 `system.html`：`9182fcdc386e8dbe9cde579c0d6246e22c8585de137b6b9f8315d7c7fd705edb`

服务器中原有的以下旧指标链路文件已删除，并由统一注册表链路替代：

- `MetricQueryCatalog.php`
- `MetricQueryReadinessGate.php`
- `MetricReportCapabilityRegistry.php`

## 运行状态

- 瑞昊 Swoole manager：1 个。
- `mohe-ai-execution-worker@1.service`：active。
- `mohe-ai-supervisor.service`：active。
- `https://rh.cc3798.com/admin/setting/mohe-ai`：HTTP 200。

## 线上真人测试

在瑞昊线上同一个新对话中连续执行 10 次提问：

1. 本月项目、卡项、产品多对象默认销售额排行：完成。
2. 追问切换为销量：三类对象共同切换指标并完成。
3. 追问前三名：继承对象、指标、期间并完成。
4. 追问改成昨天：只替换日期并完成。
5. 切换到本月客户实际收款销售额阈值计数：完成。
6. 追问明细：继承客户条件并返回明细；本证据不保存名单正文。
7. 切换到今天经营概览：没有继承客户条件，完整返回已登记概览指标。
8. 追问昨天：继承概览指标集合，只替换日期。
9. 切换到本月人员项目数第一名：按人员维度项目数完成排行。
10. 追问前三名：稳定继承人员、项目数与本月期间并完成。

验收期间没有出现指标选择、人员范围选择、上下文串话、旧链路错误或技术失败。线上真人测试通过。
