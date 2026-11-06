# DATA-R6 当前岗位与收银规则统一（开发与专项自测完成，待产品经理验收）

用户口径：修改手艺人时按当前岗位规则重新计算；与收银共用规则，不沿用旧平分金额。独立岗位各自按77元×100%=77元。

## 本轮变化
- 前端继续使用 PersonnelPerformanceOverlay 与 personnelDefaultAllocation 的唯一实现；删除历史组旧金额作为分母及旧平衡分支。
- 后端抽取 CashierV3CraftsmanAmountAllocation，收银核销内核及改单适配器共同调用，保持原核销算法不变。
- 改单入口重建当前岗位草稿；保存重新校验当前人员、岗位与比例，自动金额忽略客户端旧38.5元。
- 当前人员类型的手工费禁填和当前岗位免计同时用于页面、保存及回显；仅明确手填整元金额可以覆盖自动分配。
- 无项目消耗事实的旧记录保留首次调整冻结的项目基数，避免二次编辑将两个独立岗位合计154元错误视为项目金额。
- 原业务事实保留，通过原有冲销与追加记录记账；不批量修改历史数据。

## 已完成验证
- checkout-kernel.txt：收银核销101项断言通过。
- local-transaction.txt：真实平台动作入口、实际命令、事实落库、再次打开、幂等、事务回滚通过。旧请求金额38.5/38.5落为77/77；commission手工费归零；当前免计岗位手工费/项目数归零；缺消耗事实时基数仍为77元。
- calculator.txt / frontend.txt：独立岗位77/77、同组38/39整元尾差、明确手填20元保留、改比例恢复自动分配通过。
- 浏览器使用 browser/ 测试入口直接导入真实共享组件（合成人员，无业务网络写入）：100%各77元；改50%得到39元；改回100%恢复77；删选店长后仍各77；commission手工费禁用且0，免计岗位项目数禁用且0；确认载荷各7700分。
- Vue3构建通过（prebuild70项）：/tmp/data-r6-cashier-build，主JS index-698a1800.js，CSS index-4e8ff241.css。此为共享工作区开发构建，未作为隔离发布候选。
- PHP语法检查通过。

## 双端应用核对与范围
- 本地门店18091及平台18081已登录。平台订单嵌入页确认加载同一Vue3正本；浏览器工具无法点击跨源嵌入页时，使用页面已公开的同一iframe URL打开进行交互验证，没有另建业务页面。
- 同门店（肥西保利广场店）、同日期（2026-11-06）、正常状态：两端均3条。平台组织筛选切换到该门店后仍为3条。
- 同服务单FW110600001、背部spa68元，两端当前独立岗位均100%、各68元；店长手工费/项目数为0且禁用，美容师项目数为1。
- 两端把美容师比例改为50%均即时显示34元，改回100%均显示68元，确认后均进入同一修改原因流程；随后取消，未保存浏览器中的这张单据。实际写入、回显和幂等另由local-transaction.txt在本地真实命令内验证并回滚。
- store-current-rules.jpg、platform-current-rules.jpg为实测截图。
- 旧静态测试中的固定按钮文案、模式容器、半项格式断言已按当前真实界面及六位小数契约更新：19项通过；手工费旧提取测试补齐真实共享资格函数依赖后通过。报表生产代码未改。
- 共享文件的本轮前后版本已按开工SHA256验证；business-scoped.patch仅包含本轮业务变更，原始基线blob见baseline-blobs.json。其他任务改动不作为本轮优化提交。
- 固定开发成果见fixed-development-candidate.json。本轮未提交、未部署、未push、未连接生产实例；生产发布仍需独立核对目标现网与授权。

## 文件分组
前端：前端代码/cashier-v3/src/components/cashier/PersonnelPerformanceOverlay.vue。
后端：CashierV3ServiceRecordCraftsmanAdjustmentServices.php、ServiceCraftsmanCurrentAllocation.php、CashierV3CraftsmanAmountAllocation.php、CashierV3EntitlementCompletionKernel.php（均在后端代码/app/services/cashier/v3/）。上述业务修改补充了当前口径、权威输入及不改原事实的注释。
测试：tests/service-record-craftsman-adjustment/js/current-position.mjs；php/current-position.php、current-position-local.php、contract.php；browser/index.html、server.mjs；tests/cashier-v3/php/c2-entitlement-completion-contract.php。
数据库迁移：无。工程发布脚本：无。生成物：/tmp/data-r6-cashier-build。证据：本目录。
