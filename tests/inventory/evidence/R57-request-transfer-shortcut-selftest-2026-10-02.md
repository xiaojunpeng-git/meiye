# R57 平台请货转调拨入口

- 基线：25c9da6f；产品经理明确要求『入口要做』。当前实现未提交，待产品经理验收。
- 范围：平台请货列表增加转调拨；进入现有调拨编辑器，供货方成为调出方，通过权威可关联请货接口带入请货方、单据及剩余商品。只读打开，不自动创建调拨、不自动发货或收货。
- 前端源码：前端代码/inventory-vue3/src/App.vue；SHA-256 7fbd7dd6dac4656b11088cddf29d9d6e53c4eebf5a125008dc9ce610f9e96dbf。
- 前端源码：前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue；SHA-256 483ca687a5f22a2ff114a4fd2537c0da18ff11168c0cf1dca19aebc11932070d。
- 后端源码：后端代码/app/services/product/inventory/InventoryStockRequestQueryServices.php；SHA-256 ce287972ab9640092cade17d19a0b11f95de5edec341c9fdfd192196028772ef。
- 业务注释：上述三文件补充入口只携带身份、受理状态/权限、权威剩余明细、一次初始化及失败不可降级为无关联调拨的边界说明。
- 数据架构：权威请货主表与既有调拨写命令不变。入口限 APPLIED/PARTIAL，须具备总部仓管理及供货主体权限；编辑器重新从现有后端接口读取授权和剩余数量。创建时仍由服务端事务、请求行关联、剩余数量检查及幂等保护。无事实写入、统计时点、历史快照或迁移变更。
- 真实数据库只读验证：铜陵中段店 QH2610020002 返回 can_transfer=true；可关联请货接口返回 target=69、3 条剩余明细。列表已取消/完成记录不提供转调拨入口。
- 页面证据：18081/admin/stock/request 今日记录 QH2610020001 操作区已显示『查看 / 转调拨 / 取消』，最新版请货日期、操作日期和操作人也已加载。
- 自测：PHP 语法、git diff --check、inventory-vue3 构建通过。无提交业务命令。
- 页面补验：重新打开同一 18081 地址，列表正常。通过真实鼠标输入点击『转调拨』，QH2610020001 自动关联，调出总部仓、调入江苏淮盛店，带入 4 行剩余数量 66/77/88/99；截图 /tmp/r57-transfer-prefill.jpg。取消返回调拨列表，没有保存调拨、发货或收货。
- 空白页排查限制：原标签页 10 连读取与刷新命令均超时；新标签页 12 正常，同一 18086 服务 HTTP 200。尚未取得原页脚本错误证据，不能将新开页面恢复表述为根因修复。本次补验没有因猜测修改嵌入加载逻辑。
- 数据库迁移、自动化测试、工程脚本：无新增；本地生成物 dist 不纳入源码；验收证据为本文件。
- 本地依赖：重启 mohe-platform-app 与 mohe-inventory-v3-src；远程实例、线上备份、回滚不适用。未 commit、未部署远程、未 push。

## 保存失败修复与真实业务验证

- 修复基线 HEAD：873333e5（其他任务推进了 HEAD；本闭环仍未提交）。
- 根因一：InventoryCrossSubjectTransferServices::normalize 使用有序 array_keys 校验 JSON 对象，拒绝前端 commonPayload + source 字段的合法顺序。改为字段集合精确匹配；必填、额外字段、类型和数量检查不放宽；明细也采用同一规则，标准化结果和幂等指纹不受输入顺序影响。
- 后端源码及业务注释：后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php，SHA-256 f029937be646717e58838e086bcc3cbde460c6d0586c088f2adb208e2da6cb1f。
- 自动化测试：tests/inventory/php/cross-transfer-key-order-contract.php，SHA-256 2e6491bdea78848d9d3a08a2af8c8b12d5970df38e654d8577b7ce023fb760c0；PHP 容器运行 CROSS_TRANSFER_KEY_ORDER_CONTRACT_OK，覆盖字段/明细换序、实际前端 spread、稳定指纹以及缺字段/额外字段/类型/零数量拒绝。PHP 语法与 git diff --check 通过。
- 根因二：本地 mysql（Docker mohe-mysql，172.18.0.2）ruihao 调拨表缺少已存在迁移的三个调拨人快照字段。升级前表 0 行，字段 0 个；备份表结构 /tmp/r57-cross-transfer-schema-before.sql；补执行正本 database/upgrades/2026-08-08-库存V3调拨人快照/02-正式升级.sql，无新迁移源码，无历史数据回填。未连接远程库。
- 真实页面验证：将关联请货切换为截图所指 QH2610020002 铜陵中段店（11/22/33 三行），点击保存后成功返回调拨列表，显示 DB2610020001、3 项、草稿。截图 /tmp/r57-transfer-saved.jpg。
- 数据库核对：调拨 id=1，request_document_id=14；三条 request_line_id=91/92/93，数量 11/22/33，quantity_scale=0；business_date=2026-10-02，操作人快照 admin，调拨人快照 admin。dispatched_at=0、received_at=0，没有发货/收货，也没有库存生效事件。
- 已知列表限制：新保存单据的业务日期/操作人在当前列表显示为“-”，但数据库均正确保存；不将此显示问题冒充保存失败，也不将其标为已修复。
- 数据架构：仍由既有事务内草稿命令写权威调拨主表/明细，权限、关联请货剩余数量和商品匹配不变；草稿不动库存。输入字段顺序不是业务粒度或并发约束，本次仅修正传输契约与本地遗漏迁移。
- 开发及上述自测完成，待产品经理确认本固定范围；未 commit、未部署瑞昊、未 push。

## 产品经理验收与发布前检查

- 2026-10-02 产品经理明确回复“我测试没有问题,commit,部署瑞昊,push”。上述四个业务源码及测试指纹核对一致，接受范围为本轮实现；偶发嵌入空白及列表空显示不冒充已修复。
- 字段顺序契约测试重跑通过，git diff --check 通过；按精确六文件清单本地提交，不包含其他任务脏改动。
- 生产只读检查：rh-server 正式/预览目录存在，Swoole manager_count=1。瑞昊 ruihao 跨主体调拨表缺 transfer_staff_id、transfer_employee_id、transfer_staff_name_snapshot、created_by_operator_name_snapshot；请货表缺 acceptance_party_id、acceptance_party_name_snapshot、created_by_operator_name_snapshot。
- 上线依赖：既有正本迁移“2026-08-08-库存V3调拨人快照”“2026-10-02-库存V3请货受理方”“2026-10-02-库存V3业务与操作信息分离”须按实例检查、独立备份、执行及验证；未执行任何生产数据库写入。本轮部署及 push 待生产迁移明确授权后继续。
