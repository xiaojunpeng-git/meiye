# D3-010 执行前准备（待固定候选验收）

- 数据候选 SHA-256 `013f187ab9480815e204177abfcd527f8869e1e5effa6c44009fd3b5bd35dc61`；执行脚本 SHA-256 `32706b49b8b7fd3b50c35aab4f6ca41266579484a25f830dc7c4cdf8f4753b7e`。候选的所有新金额字段均为整元对应的整数分值，逐字段校验通过。
- 私有备份 `/www/backups/010/d3-service-680-20261106-013f187a/affected-tables.sql.gz`，100,263,202 字节，SHA-256 `252a7368bffcd74d326dced6e284d2dc848eca827d80ecf1e09665926927193c`。采用 `--single-transaction` 与 `pipefail` 完整导出，`gzip -t` 和 `SHA256SUMS` 校验通过。
- 备份包含九张表：`eb_cashier_v3_performance_fact`、`eb_cashier_v3_entitlement_service_fact`、`eb_cashier_v3_entitlement_writeoff_fact`、`eb_cashier_v3_business_event`、`eb_store_order_writeoff`、`eb_store_order`、`eb_store_order_cart_info`、`eb_user_card_holder`、`eb_staff_yeji`。后五张为只读原始业务证据；恢复只向前四张新增事实。
- 候选及执行脚本镜像位于 `/www/backups/010/d3-service-680-candidate-20261106/`。执行前须再次校验镜像指纹和来源，提供上述备份目录与已验收文件 SHA。当前还没有 D3 生产写入或本地提交。
- 事务内任何检查失败均回滚。完整备份用于灾难恢复证据，不得在营业后直接用整表恢复覆盖新的正常交易；如提交后需要回退，应先核实候选精确事实 ID 及后续引用，按专门批准的回退计划执行。
- 本地隔离库 `codex_d3_010_680` 保留相关源数据夹具。实际执行脚本的本地事务演练已回滚；另测当前剩余次数改变仍保持原值、原金额改变被拒绝、候选 SHA 错误以非零退出码拒绝。
- 产品经理已确认历史总部 22 条、12,401 元不转入现有门店，并已明确验收上述固定文件与执行脚本对应的 680 条完整候选。生产执行结果另记。
