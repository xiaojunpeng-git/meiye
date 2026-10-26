# R36 项目替换整元金额

基线：2d3adce02923922931493c444f2ebed20ea8c3a0。开发与自动化自测完成；后续升级恢复修复一并交付后，产品经理明确回复“commit”，确认本轮提交。未部署。

## 口径及边界

- 新替换权益金额取整到元，按整元均摊、末次领取剩余整元尾差。例如 1000 元 / 3 次为 333、333、334。
- 历史分级权益仍按原冻结口径计算本次实际扣除额；不足一元不转入目标，写入来源操作行的 `replacementAmountAllocation` 审计。最后一次来源余次、余金额全部清零。例如 256.60 元扣清、转入 256 元、取整扣减 0.60 元。
- 不批量回填历史记录，不改原购买金额；来源行 `amount_cents` 为实际扣除，目标行为实际转入，其差额与审计取整扣减一致。
- 金额整数展示与上述实际金额规则分离；次数不取整。

## 文件分组及注释

- 前端：EntitlementSelectorOverlay.vue，仅金额显示使用统一格式化，保留缺失值与次数精度，已注明不得回写展示值。
- 后端：CardOperationKernel / CardOperationAuthorityServices，统一纯函数分摊、锁内复核、快照审计；EntitlementActualAmountAllocator，冻结新版本整元规则；EntitlementProjectionServices / DirectSnapshotEntitlementSettlementServices / MemberDetailQueryServices，独立权益金额与分精度判定分离，防止重新套用整卡共享池。相关职责与约束已注释。
- 测试：card-operation-kernel-contract.php、card-operation-integration.php、r36-entitlement-amount-display.mjs。
- 数据库迁移、工程脚本、构建生成物：无。
- MemberDetailQueryServices 与 DirectSnapshotEntitlementSettlementServices 有此前其他未提交改动，本轮只修改独立金额判定，不将其他改动算入本轮。

## 数据架构自检

来源为锁定的权益明细及冻结金额口径。沿用一次替换操作及来源/目标行粒度、原事务、幂等、资源版本、业务时间与操作时间；差额在同一事务中记录，失败整体回滚。历史销售与经营事实不覆盖，既有权限、事件、冲销边界不扩张。不新增查询或索引，不新增跨实例访问。

## 自测

- 卡操作集成 28 项、卡规则集成 11 项通过；包含新目标 50 元 / 3 次按 16、16、18 分摊，以及重放不重复扣次。
- 内核合同通过：整元末次尾差、历史 256.60 → 256 + 0.60 的源/目标行及审计验证。
- 前端金额显示测试、原卡操作前端合同、Vue 编译、diff 空白检查通过。
- 本轮使用隔离 MySQL 5.6 测试库，没有新增真实会员替换交易。已重启本地服务加载代码。
- 运行日志：/tmp/r36-whole-yuan-final.log。

## 核心指纹

- Kernel：98a111a77881292e7b609a08ba5b599fe2756f32c11038f7633941ce4a1230b5
- Authority：cc4f7d447ae81fca2654e729b69348aa389985792b6ad68b0d08eb59cd3931a6
- Allocator：59e2b3fad048ac8ecfa82bc020d144e0616277192304823a316b1c7147e096a3
