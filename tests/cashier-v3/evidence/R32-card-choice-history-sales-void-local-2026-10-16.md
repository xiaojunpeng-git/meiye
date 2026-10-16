# R32 组合卡历史选中导致销售单作废受阻

- 范围：仅后端销售单作废门禁与定向回归；未改前端、数据库或线上数据，未执行真实作废、提交、部署或推送。
- 基线 HEAD：`8ad3393da8af84d0bdf255fe2be816ded0053797`。本轮后端文件 SHA-256：`fb4afe2ff7d27465b8eaa8a9e139c818f74bcd953ba5d91e50e24f292857eaf6`；测试文件 SHA-256：`26d1fe8e2cad0cbeb062727659d125bdd97e41d476900784ebb7f4bd647667d9`。
- 瑞昊生产只读核对：销售单 `XS26100700206` 仍为 `settled`，组合卡持卡记录 2077105，卡级次数与三组共享次数均已全数恢复；项目“东方熏蒸”两笔有效核销各 1 次，均有等量返还及 `succeeded` 服务作废。该项目保留 `selection_status=selected`、非零 `selected_at`，种数组仍保留 `selected_kind_count=1`。线上后端文件指纹与本轮修改前本地文件相同；只读事务均回滚。
- 原因：服务作废刻意保留任选项目的历史选择，但销售单作废误把非零 `selected_at` 当作未恢复的权益；项目次数、卡级及组级次数实际上已恢复。新门禁继续核对成功作废事实、有效服务与预约、卡项操作及所有次数；历史选中不再单独阻止作废。组合组随整卡撤销同步标记 `revoked`。
- 自测：`php -l` 两个改动文件通过；`sales-order-void-restored-state-contract.php`、`used-card-void-message-contract.php`、`old-card-void-contract.php`、`card-rule-entitlement-authority-contract.php` 均通过；`git diff --check` 通过。新增测试覆盖历史选中但次数全满可通过，缺组、组次数不足、项目次数不足和已撤销状态必须拦截。
- 已知限制：完整 `order-lifecycle-contract.php` 有 4 项失败，分别涉及服务作废前端上下文、订单详情终态、人员快照及退款展示；它们引用本轮未改的共享工作区文件。本轮尚未做真实销售单作废演练，不能把定向合同测试视作产品经理验收。
- 本轮补充/更新业务注释：`CashierV3SalesOrderReversalServices.php`、`sales-order-void-restored-state-contract.php`。
