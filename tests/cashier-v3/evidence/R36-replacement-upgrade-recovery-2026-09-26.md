# R36 替换后升级自动恢复

基线 2d3adce02923922931493c444f2ebed20ea8c3a0；叠加本轮整元金额修改。交付自测结果后，产品经理明确回复“commit”，确认本轮提交；未部署。

## 原因及修改

本地 17:00:49 的 submit-checkout 拒绝日志明确指向明细 2423360：project_source_version_missing。替换创建目标权益后未登记技术资源信息，而打开选择器仅同步卡级信息。

- 后端 CardOperationAuthorityServices：新替换同事务登记目标；已打开完整结账快照遇到旧操作遗漏时，仅在来源锁定且确认为操作生成权益的前提下补建缺失项，不覆盖已有版本。新增注释说明权限、锁与恢复边界。
- 后端 EntitlementProjectionServices：重开选择器同步既有操作生成项目，兼容停用卡查询，不产生业务交易。
- 后端 CardOperationResourceDiscovery / AuthorityServices：异常兜底使用业务表述，不再要求用户处理“版本”。
- 测试 card-operation-integration.php：新增创建即登记、旧目标重开恢复、已打开结账锁内恢复及已有版本不重置验证。
- 前端、迁移、工程脚本、生成物：本子项无新增；此前整元展示修改保持。

## 自测与架构边界

MySQL 5.6 隔离卡操作集成 31/31、规则集成 11/11；原前端卡操作合同和整元金额测试通过。日志 /tmp/r36-upgrade-recovery-verified.log，最终 CARD_OPERATION_FOCUSED_MYSQL56=PASS。已重启本地后端。未代用户再次真实收款，不将隔离测试冒充真人结账完成。

权威源、事实粒度、业务时间、权限和幂等保持不变；新权益登记与权益创建处于同一事务，恢复只补技术登记，不改金额或次数、不生成销售/收款事实。原有快照金额、次数及并发检查保留，不以零版本跳过检查；不批量改历史数据、不连接远程实例。

## 当前文件 SHA-256

- Authority：bb18532cea4c5dcc591d0b386f1a7fed9e1cf95839d07aadd4a29f876dc1f410
- Discovery：a9d379b24b3016bef1f8f706f122a265833449187cc15063e9df19a010a535af
- Projection：3eb361f987f4dc331c5f2cd3716157779795e50580610aa4b2bf6c4742a62e23
- Integration：69bf36cc973fa71a2f89731b6a273f4a4c6b79fe40505b3206e8a675c9901da1
