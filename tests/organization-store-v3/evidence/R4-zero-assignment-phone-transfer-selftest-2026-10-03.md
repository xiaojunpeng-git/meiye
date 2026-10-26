# 第4轮：无有效任职员工手机号调入本店自测

- 基线 HEAD：`0e886706494aabad03c76446be9d0a1148b41eb9`
- 闭环文件补丁 SHA-256：`4a5a91350a5479bb10ea005e5473851cd536d697c7d89ad07ec9394ecb3f7d87`
- 状态：本地开发与自测完成，待产品经理确认验收；未 commit、部署、push。

## 本轮范围

- 门店“调店”按完整手机号可查询只有员工主档、没有有效门店任职的在职员工；多条有效任职或孤立有效任职期间仍拒绝。
- 确认调入只建立或复用本店任职，并关闭历史登录和渠道权限；返回本店 staff ID，前端打开原有编辑页填写岗位和其他必填资料。本店归属由会话固定。
- 门店“新增员工”手机号命中已有有效员工主档时，提示“已经有了员工档案，请使用调店功能”。
- 产品经理确认“能进员工列表就能编辑员工”：本店员工编辑与员工列表共用操作权限，含原有“个人/门店”数据权限；新增仍需独立创建权限，委托查看模式仍不可写。
- 原本有有效外店任职的调店路径继续使用现有事务内核，不改历史经营事实。
- 岗位单选限制按本次任职生效：本店岗位保存只结束本店旧岗位，不因员工另有总部直属或其他任职岗位而拒绝；跨任职历史和有效岗位都不被本次编辑覆盖。

## 验证

- Docker 本地 PHP/MySQL 真实事务：`R51_LOCAL_TEST=1` 的 `r51-phone-transfer-integration.php` 全部通过。覆盖正常调店、HTTP 注入门店边界、幂等重放、查询后变更、后置失败回滚、无有效任职历史复用、只有主档的新建本店任职、旧权限不复活、完整详情读取及重复新增提示。夹具在 `finally` 回滚，未留下测试员工。
- 同一事务夹具另验证了总部直属岗位与本店岗位可同时有效，以及本店改岗只结束本店旧岗位、保留总部岗位；未修改尹思芳实际记录。
- 编译真实 Vue 单文件组件的 `r51-phone-transfer-dialog.cjs` 通过，含调入结果 staff ID/补全标记传递、令牌重试及切店旧响应防护。
- `r51-phone-transfer-contract.php` 和 `route-permission-contract.mjs` 通过：调店/编辑同员工列表权限，新增独立门禁；委托查看模式保持只读。
- 实际热更新页 `18091` 复验：合肥世纪中心店筛选手机号 `17755505431` 后，尹思芳行的“编辑”按钮可见，点击可打开原员工编辑弹窗；未保存任何业务数据。修复点是前端从页面权限快照读取“管理中心”，不再误读仅含细粒度代码的 operation 快照。构建输出转向 `/tmp/r4-cashier-v3-permission-check-20261003`，避免再触发源码服务监听 `dist/index.html` 后整页重载。
- PHP 语法检查 3 文件、`git diff --check` 通过；`cashier-v3` 从源码执行 `npm run build` 通过（只有既有的大包体积警告）。
- 旧调店测试的同一历史任职在一秒内反复调入可能触碰内核的秒级任职令牌；测试已跨秒验证失败注入。该既有快速往返边界未纳入本轮业务改造。

## 注释与文件归属

- 前端源码：`StaffPhoneTransferDialog.vue`、`StaffListView.vue`；后端源码：`StoreStaffPhoneTransferServices.php`、`EmployeePersonCompleteWriteServices.php`、`CashierCheckRoleMiddleware.php`、`StaffJobPositionServices.php`。
- 自动化测试：`r51-phone-transfer-integration.php`、`r51-phone-transfer-dialog.cjs`、`r51-phone-transfer-contract.php`、`route-permission-contract.mjs`；本文件为验收证据。无数据库迁移和工程脚本改动。`dist/` 是未纳入提交范围的构建产物。
- 更新的业务注释位于岗位绑定的任职级单岗位约束、两个后端服务的调入/重复建档边界、中间件员工权限口径，以及前端调入后编辑入口与列表权限判断；测试新增注释说明隔离夹具和跨秒约束。
