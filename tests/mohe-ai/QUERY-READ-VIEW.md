# 共享指标查询与一致读对接

实现文件位于 `后端代码/app/services/query/metric/`，不是 AI 私有事实或报表公式。

## 调用

`MetricReadViewStore($absoluteInstancePrivateDirectory, $instanceSigningKey)` 创建该实例独立存储。签名密钥由后端可信配置提供，不取前端/API payload，不使用 SiliconFlow Key。

`MetricReadViewServices($store, $authorize)` 使用默认真实 MySQL InnoDB REPEATABLE READ 只读事务。`create($authenticatedPrincipal, $query)` 建立新的共享短期读视图；`replay($authenticatedPrincipal, $sameQuery, $readConsistencyRef)` 只重放原查询，不读取最新金额。

`$authorize($authenticatedPrincipal)` 必须每次重建当前对应报表权限，返回且仅返回：

- `instance_id`、`subject_ref`、`terminal`、`tenant_id`、`permission_version`
- `report_capability_code`：当前唯一支持 `group_management_dashboard`
- `scope_provider_code`：可信报表范围适配器代码
- `scope_mode`：`stores/all/agent_limited/platform_admin`；`self_participant` 等个人范围拒绝，不降级全店
- `store_ids`：合法正整数数组；门店端必须单店

查询严格字段：`query_shape`（summary/comparison/trend/ranking）、`metric_codes`、`start_date`、`end_date`、`compare_range`（null 或 start/end）、`store_ids`（空数组为当前权限范围）、`business_filters`（当前仅允许空数组）。排行另传 `ranking:{direction:top/bottom/top_and_bottom,limit:1..20}`，其他形态为空。未支持业务条件不得删除后查询。

输出 `results`：汇总/对比每个指标与周期一项 `amount_cents`；趋势为日期/分整数行；排行为 top/bottom 门店 ID、`store_name`、分整数行。店名来自同一只读事务内授权范围的 `system_store.name`，随金额一起签名冻结；重放不会拼接改名后的新名称。名称缺失停止，不让模型编店名。所有金额均为分整数。

## 能力与真实边界

- `metricCapabilities()` 按指标返回实现能力；`MetricQueryCatalog::fromSharedQuery($dictionaryLookup)` 为显式新版目录，旧 `fromDictionary` 保留基础未就绪语义。
- 消耗 `consume_amount` 规范映射既有集团 `consumption_performance`，读取同一项目级完成服务事实、有效状态、冲销符号、业务日及作废排除，规范实现版本 `consumption-completed-service-facts-v1`。
- 原现金集团源只含销售分摊，充值和充值欠款补收没有 saleFacts。2026-09-08 产品确认后，共享 reader 补入两类互斥付款事实并复用到报表与 AI，采用 `group-management-cash-recharge-v2`；详细验证与未完成门禁见 CASH-RECHARGE-VERIFICATION.md。混合请求仍不得静默删掉任一指标。
- 实际业绩口径冲突保持关闭。
- 实现能力不授予岗位入口/报表权限，不证明某客户实例已升级、历史覆盖完整或压测通过。实例启用仍检查相应门禁。
- 视图保存的是共享查询的不可变结果投影，仅允许精确原查询重放；不是可供任意后续下钻的全事实快照，不伪造事实提交水位。由真实事务保持本批所有指标和周期的一致性。
- view 不保存聊天内容；24小时到期读取拒绝，`MetricReadViewStore::cleanup()` 物理清理。调度注册由整合层完成。
- 签名覆盖查询、当前权限绑定、来源版本、全部结果；重放重新验权，变更即拒绝，不能以裁剪后的新数字替代旧卡片。

## 自测

`php tests/mohe-ai/query-read-view.php`：离线纯合同测试，生成的临时文件退出清理。

`bash tests/mohe-ai/run-query-mysql.sh`：全新 Docker MySQL 5.7 临时容器，仅绑定随机 localhost 端口，随机测试凭证仅进程环境，不读取 `.env`，不使用现有客户库。通过类映射载入实际 Think ORM，不启动应用。退出删除自身容器与卷。

真实测试覆盖：成功/失败与作废、负向消耗、门店隔离、原报表委托对账、跨周期、全范围排行、日趋势零补齐、并发提交下的重复读、禁止读事务写入、禁止嵌套业务事务、非 InnoDB 拒绝、旧视图不随新提交漂移、撤权、个人范围拒绝、TTL清理。此测试是隔离业务夹具，不冒充真实客户全量历史对账或产品经理验收。

2026-09-08 当前执行结果：离线查询合同 54 项、指标目录合同 36 项、真实 MySQL 5.7.44 夹具 50 项通过；集团报表委托静态回归通过。真实测试另覆盖排行名称随原金额冻结、改名后重放不漂移、40ms SQL 执行预算产生 MySQL 3024 中断（断言小于1秒）、异常后恢复既有 SESSION 时限、取消检查阻止查询且不遗留事务。宿主 PHP 8.5.8，未冒充生产 PHP 版本测试；一次 amd64 模拟容器启动 exit139 已清理，重建后整套通过。

## 共享导出适配

- `MetricReadViewExportRegistrar` 单独登记 `metric_read_view_export`，不会借用会员或普通业务页面。固定列清单为 `fields()` 返回键顺序；`scope_dimensions.metric_read_ref` 必须是唯一原读视图引用。
- `MetricReadViewExportProvider($views,$resolveReadContext)` 的可信 resolver 收 `(context,ref)`，返回 `principal/query`，据此重新授权并重放原视图。不得把浏览器历史、前端字段或模型输出当作可信 resolver。
- 只接受共享 compiler 生成且持久化后验证通过的原计划；禁止额外筛选、分组、自定义指标、额外合计和非默认排序。`query` 原结果范围固定，拒绝 `page`。金额使用分整数的精确十进制文本转换，不经浮点；Writer 保留原精度。
- Task 的 `createAi(context,payload,binding)` 仅可信服务器调用，必须先安装来源合同并注入 `setAiFence(binding,phase,action)`；该函数必须在真实 Run 锁下验证当前归属、代际、取消和阶段，再恰执行一次 action。不能用恒 true 闭包作为上线实现。
- Task `executablePlan(task)` 验证双层 query 范围后还原原共享计划；完成、续租、状态、下载、失败和取消均走阶段 fence。完成不延长 root/source expiry；AI 不自动重领失租任务。
- `cleanupAiExpired(limit,deleteFiles)` 覆盖全部 AI 状态，清空业务载荷及用户归属字段后删除相关文件与行。文件删除失败明确返回 retry，残余清理索引不冒充匿名记录或“24小时已清理成功”。Worker 必须继续重试和告警。
- 真实夹具验证普通旧 schema 兼容、部分迁移拒绝、双层范围、Run fence、取消竞争、5种状态清理、删除失败、REPORT/AI 两向扫描隔离与指定任务越分区不改变状态。共享 compiler→JSON 持久化→Provider 原视图投影实测通过。
- 实际 Task `createAi` 与 `claim` 在隔离 MySQL 通过：权限策略、注册目录、计划编译及 INSERT/claim CAS 使用真实代码；自定义字段、偏好和引用注册使用受控空夹具，不冒充客户完整配置验收。
- 本文件测试不执行正式迁移、不启用客户 Excel 开关；完整 HTTP Gateway 与实际队列消费者的上线联调仍是独立门禁。

## 导出运行态端到端增量自测

2026-09-08 最新 `bash tests/mohe-ai/run-query-mysql.sh`：**62 项 PASS，真实 MySQL 5.7.44**。该命令包含 `query-export-runtime-mysql.php`，不另需客户账号、模型 Key 或现有业务库。

新增链路使用真实 MySQL 只读事务/冻结读视图、SQLite Run 存储及 fence、共享注册/编译/Task/Worker/Writer、xlsx 回读校验及下载复验。实测首问排队进入 WAITING_EXPORT 并释放父执行槽、文件完成后发布 COMPLETED、下载获取合法文件描述、重复 Job 不二次发布、权限变更拒绝旧文件、关闭取消后迟到 Job 不写文件/不发布、两个真实文件槽均忙时保留原已校验证据并发布 PARTIAL_SUCCEEDED。

隔离替身仅为可信账号解析、队列投递回调（捕获后显式执行消费）以及空自定义字段/偏好/引用元数据；不冒充真实后台身份重建、Redis 队列投递或客户历史对账。生成文件和密钥在临时目录内，退出完整清理；没有聊天落库，未运行正式迁移、启用开关或部署。
