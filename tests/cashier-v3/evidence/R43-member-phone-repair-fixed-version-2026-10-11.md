# R43-00001 会员手机号修正：待产品经理验收

- 基线 HEAD：`1477eb5afa11d500a17a2dce254c5224cb3a3e68`。
- 范围：门店端会员列表的编辑会员弹窗允许修正手机号；保存继续走 `update-member`，保持原 UID、权限与资源版本门禁。号码变更经统一身份服务查重、释放旧映射、绑定新映射，并随会员事件同事务落库。旧调用未提交手机号时保留原编辑行为。
- 补丁指纹（以下已跟踪的五个代码/测试文件）：`610a667f7492b3d2dadefe8f8cdc35ea0c0c081313584118606b01f88c59c8f0`；新增测试文件另列 SHA-256。

| 分组 | 文件 | SHA-256 |
|---|---|---|
| 前端源码 | `前端代码/cashier-v3/src/components/member/MemberCreatorPanel.vue` | `255b2368f3a57ed1cab1bcdd6f18eacd0ba6b4e3674f2c596054db940c2bd79c` |
| 后端源码 | `后端代码/app/services/cashier/v3/member/CashierV3MemberModule.php` | `6fabd250d68891f5af2ca840bebb7ed985f560fac2b32c7cbb6f5bf700e3a8ce` |
| 自动化测试 | `tests/cashier-v3/js/member-edit-conflict-contract.mjs` | `a23684c8e3cd3c110f93b683d3d32168b48227c7e1129b1e04ed7e8f053a3969` |
| 自动化测试夹具 | `tests/cashier-v3/lib/MemberIntegrationFixture.php` | `2d904423be23fa3fd90d93a2b9419c2f0c7bb6ab272550671d3c376484c8ec66` |
| 自动化测试 | `tests/cashier-v3/php/member-phone-repair-integration.php` | `96e7d6f6f320a5afadc8e09a33e3bd6bcb9c3e307b0e6c6e4208435538f5a547` |
| 工程脚本 | `tests/cashier-v3/run-all.sh` | `ae7b950354233b5bcf9d80030a4b24963688de28c5b2bc162729a068b9bbee65` |

## 自测

- `node tests/cashier-v3/js/member-edit-conflict-contract.mjs`、`member-list-creator-contract.mjs` 通过；PHP 三文件语法检查、`bash -n`、`git diff --check` 通过。
- Vue 3 构建通过，输出 `index-c3156afc.js` / `index-be2ac1ca.css`；生成的 `dist/` 不作为源码提交。
- 浏览器实际编辑弹窗的手机号字段显示为可输入；未保存或修改真实会员资料。
- MySQL 5.6 独立容器专项测试：`R43-MEM-01`（非法、已占用手机号拒绝）与 `R43-MEM-02`（原 UID、旧/新映射、更新事件、幂等重放）均通过，`ASSERT_FAILED=0`。证据：`tests/cashier-v3/_evidence/runs/20261011-132412-90713-15299/member-phone-repair-integration.out`。
- 完整 `run-all.sh` 未全绿，失败项包括原会员套件的“权益权威数据结构未就绪”和其他既有契约；它们未在本次范围内修复，不能声称完整回归通过。

## 数据架构自检

- 权威源：`user` 当前手机号与 `user_phone_identity` 唯一身份映射；`store_user` 仅用于门店编辑权限。
- 事件与粒度：一名会员的一次 `update-member` 成功命令对应一条 `member.updated`，手机号进入变更字段和当前值快照；真实操作时间由现有事件记录器保存。无销售、收款、业绩或报表事实变化。
- 事务、并发与幂等：沿用 V3 工作台/会员资源版本、命令回执与事务；统一身份服务对号码映射加锁并拒绝已占用或历史冲突号。失败时不提交主档、身份和事件的部分结果。
- 历史、权限与兼容：不迁移订单、卡项或历史快照，不改 UID；已有业务详情继续按原记录。后端强制校验有权编辑的会员。未携带手机号的旧调用不触发换号。
- 性能、迁移与对账：复用现有唯一摘要索引和会员主表手机号索引；无需新迁移或回填。专项测试核对主档、旧/新身份映射和事件数量。

## 交付边界

- 本轮未新增数据库迁移，未修改远程实例，未执行生产资料修正、commit、部署或 push。
- 代码注释：后端更新了换号身份边界说明，测试夹具补充了等级目录字段说明；简单表单可编辑状态没有添加逐行注释。
- 工作区另有大量既存的无关改动，全部保持原状，均不属于本次闭环文件清单。
