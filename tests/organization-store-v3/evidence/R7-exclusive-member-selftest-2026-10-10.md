# R7 专属会员管理自测与固定版本

## 状态与边界

- 开发与自测完成，待产品经理确认验收；未 commit、未部署、未 push。
- 唯一平台端开发源：`前端代码/admin/`，当前 18081 热更新版本；未创建 worktree、第二源码或 8080 构建包。
- 本地数据库验证使用 mohe-platform-app / mysql / ruihao，所有测试业务数据在外层事务回滚。未连接或修改远程实例。
- 基线 HEAD：`e0509aac8d30e5072fe3dd74f1aa4dccbbf7774c`。
- 固定版本：`47b9c72cb3fc54768dab810e321d9f414f231d8074852009ef5ec8342005a4e8`。
- 已跟踪文件补丁指纹：`b566a86962beff697b369da1e46b11f71016191be0a13ccef05a4b87c5f80b03`；新文件内容及删除标记另纳入完整固定版本指纹。
- 每个文件 SHA-256、删除清单及截图 SHA-256 见同名 manifest JSON。
- 原有9个暂存文件未改变，暂存区指纹仍为 `dc31b057aca12a17ee75ed7a86e023bfeba79933849b91605faa54e209c95b46`。工作区其他任务改动未暂存、提交、撤销或归入本轮。

## 完成范围

- 人员列表移除“权限编辑”，专属客户改名“专属会员”，新增“转移专属会员”。
- 查询权威来源是会员档案多人专属服务人关系；仅无有效多人记录的历史档案读取旧单人关系，不读旧销售人 salesman_id。
- 列表只返回和展示客户昵称、客户姓名、手机号、绑定时间。无专属业绩、金额或会员ID列。
- 绑定时间采用当前服务人最近加入审计；无多人审计时读取原关系创建时间或旧单人绑定时间，不以档案更新时间替代。
- 接收员工限定来源员工同门店、在职、有效任职且可作为专属服务人；支持多人选择、排除本人并去重。
- 转移覆盖来源员工全部有效专属会员，不受查看弹窗搜索、分页影响。只替换来源员工，保留其他服务人和既有接收人顺序。
- 同一事务写多人关系、旧首位兼容投影、关系变更审计与会员编辑资源版本；预览变化拒绝覆盖，重复令牌回放，中途失败全部回滚。
- 后端检查人员维护权限、当前管理员身份、统一员工门店范围及隔离门店设置，不能用旧组织授权扩大统一范围。
- 不修改收银/会员页面、共享会员档案保存服务、预约、订单、销售人、业绩、资金或员工调店业务。

## 文件分组

前端源码：
- `前端代码/admin/src/api/setting.js`
- `前端代码/admin/src/api/staff.js`
- `前端代码/admin/src/pages/setting/staff/index.vue`
- `前端代码/admin/src/pages/setting/staff/components/ExclusiveMemberTransferModal.vue`
- 删除 `前端代码/admin/src/pages/setting/staff/components/StaffFeaturePermissionModal.vue`

后端源码：
- `后端代码/app/controller/admin/v1/merchant/SystemStoreStaff.php`
- `后端代码/app/services/store/StaffExclusiveMemberServices.php`
- `后端代码/route/admin.php`

数据库迁移：无，复用现有关系、审计、幂等和资源版本表。

自动化测试：
- `tests/organization-store-v3/php/exclusive-member-transfer-local.php`
- `tests/organization-store-v3/js/exclusive-member-transfer.mjs`

工程脚本：无。

生成物：
- `R7-exclusive-member-transfer-desktop-2026-10-10.png`
- `R7-exclusive-member-transfer-mobile-2026-10-10.png`

验收证据：本文与同名 manifest JSON。截图为本地调试证据，不是构建产物或产品经理验收记录。

## 自动化结果

1. `docker exec -i -e EXCLUSIVE_TRANSFER_LOCAL_TEST=1 mohe-platform-app php < tests/organization-store-v3/php/exclusive-member-transfer-local.php`：40 PASS，最终确认夹具和业务写入全部回滚。
2. `node tests/organization-store-v3/js/exclusive-member-transfer.mjs`：3组 PASS，含四列契约、请求乱序、多人载荷、防重复提交、关闭保护、失败保留、刷新和成功回调，两个 Vue 模板编译通过。
3. `node tests/organization-store-v3/js/staff-avatar.mjs`：9 PASS。
4. `php tests/cashier-v3/php/staff-position-only-permission-contract.php`：7 PASS，0失败。宿主 PHP 输出既有 AdminException 隐式 nullable 弃用提示，与本轮无关。
5. PHP 容器 lint：新服务、控制器、路由均通过；闭环跟踪文件 `git diff --check` 通过。

数据库测试覆盖：
- 真实控制器提交一次替换四名测试会员；例子“小李、小王 → 小李、小张、小黄”、原有接收人去重、旧单人升级多人读取一致。
- 跨店、离职、不可选、本人员工及畸形ID拒绝；普通人员维护管理员仅可访问明确授权门店，隔离门店拒绝。
- 预览关系版本变化拒绝；在最后会员注入审计唯一键冲突，确认前面写入也整体回滚且不遗留成功幂等回执。
- 重放不重复审计，改变载荷复用令牌拒绝。
- 其他服务人绑定时间保留；新接收人显示实际绑定时间；用户主字段及员工任职表逐行比较不变；跨店关系不变。
- 会员资源版本递增，防止加载在先的会员编辑覆盖转移。

## 浏览器验证

- 本地人员列表的专属会员查询与转移预览可打开，候选员工可同时选择两人，取消正常。
- 查看列表为四列，无金额；来源切换不保留上一人的查询数据。
- 桌面1624×1063和窄屏390×844检查弹窗与多人选择，已保存截图并恢复视口。
- 真实浏览器未确认转移任何实际会员；写入验证仅使用可回滚的本地数据库夹具。
- 测试后关闭弹窗、恢复原空关键词列表；线上瑞昊和门店会员页未操作。

## 注释与旧代码治理

- 在 setting.js、staff/index.vue、ExclusiveMemberTransferModal.vue、SystemStoreStaff.php、StaffExclusiveMemberServices.php、route/admin.php 补充或更新职责、权限、幂等、并发、事务及关系范围注释。
- 已删除无调用的旧个人权限弹窗及两个旧前端读写API；扫描 admin/src、后端 app/route 和相关前端测试，旧组件/API符号零引用。
- 后端旧个人权限禁用保护保留，岗位权限回归确认旧写入明确拒绝；其他仍在使用的业绩查询不改。

## 已知限制与待验收

- 单次最多5000名专属会员，最多选择30名接收员工，单会员转移后最多30名服务人；超过上限整批拒绝。
- 未做多进程并发压测；已做版本冲突、幂等及事务故障回滚验证，锁顺序代码核对。
- 未执行完整平台生产构建、生产写入或线上发布，不能作为已经上线的证据。
- 待产品经理在固定版本确认四列显示、同店多人接收与替换保留口径。未获验收不提交；验收不自动授权部署或push。

## 本地数据库验证原始输出

```text
PASS 本地库存在两个独立门店
PASS 多人关系及旧单人关系可见，旧销售人/跨店/注销会员不混入
PASS 接口只包含昵称、姓名、手机号、绑定时间
PASS 客户姓名搜索
PASS 客户昵称搜索
PASS 手机号搜索
PASS 分页与计数一致
PASS 绑定日期含结束日
PASS 拒绝：格式无效
PASS 拒绝：没有有效
PASS 拒绝：人员维护
PASS 普通人员维护管理员可操作明确授权门店
PASS 拒绝：数据权限范围
PASS 拒绝：数据权限范围
PASS 畸形路径不截断为其他员工
PASS 候选人仅同店有效可选员工，不含自己
PASS 拒绝：必须是同门店
PASS 拒绝：必须是同门店
PASS 拒绝：必须是同门店
PASS 拒绝：本人
PASS 拒绝：绑定已变化
PASS 拒绝：Duplicate entry
PASS 中途保存失败整批回滚，幂等结果也不残留
PASS 真实控制器路径提交成功且一次转移全部四名会员
PASS 小李、小王替换为小李、小张、小黄
PASS 已绑定接收人去重且保留原位置
PASS 旧单人关系转为多人关系且会员档案读取一致
PASS 首位转出同步旧单人投影
PASS 其他服务人原关系、版本和绑定时间完整保留
PASS 会员档案及销售人等全部主字段不变
PASS 员工任职、权限和状态完全不变
PASS 会员资源版本推进，旧编辑请求失效
PASS 每会员一条不可覆盖前后关系审计
PASS 重复请求回放，不重复审计
PASS 拒绝：IDEMPOTENCY_TOKEN_CONFLICT
PASS 转出列表归零，接收列表读取新绑定
PASS 新接收人员显示本次实际绑定时间
PASS 已绑定接收人保留原绑定时间
PASS 跨店会员的关系不受影响
PASS 夹具和业务写入已全部回滚
```
