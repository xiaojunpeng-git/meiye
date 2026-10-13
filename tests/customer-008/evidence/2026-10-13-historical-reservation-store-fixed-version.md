# 008 历史权益预约门店修复：固定版本交接

状态：本地开发及隔离库自测完成，待产品经理确认验收；未提交、未部署、未推送。仅计划更新 `008.cc3798.com` 的会员 H5 与同实例 PHP 服务，不改数据库，不触及 007、012、瑞昊等其他实例。

## 现场与根因

- 用户截图对应会员 421 的订单 5338、权益明细 13967，预约页的“预约门店”为空。
- 该技术承载订单的 `store_id=0` 是迁移时有意保留的无归店事实；权益归属在 `eb_migration_entitlement_scope`，归属渼约组织 3。直接补旧门店名会误写业务归属。
- 原会员页只在普通跨店开关开启时提供选店，普通门店列表对该项目返回空；原 V3 预约写接口还过滤 `is_system_del=1`，所以只改显示也无法预约。

## 修复范围

- 对经过迁移范围表及本人权益校验的无归店历史权益，会员页展示“请选择门店”和渼约营业门店选择器；选择门店后清除原时段与老师，按选中门店重新载入老师。没有营业门店时给出明确提示。
- 服务端从历史权益归属组织的有效下级节点取营业门店；提交时复核本人、权益剩余、门店营业状态和组织祖先状态，拒绝歌妮及停业门店。历史权益逐个项目预约，避免跨来源增项混入。
- V3 写入前锁定权益明细，计入已有待确认、待服务、服务中的预约量，避免同一剩余次数重复预约；相同请求沿用原幂等结果。原普通订单预约路径保留。
- HBuilderX 传统 Vue2 编译链显式加载项目已有 Babel 可选链转换插件，保证此次 H5 源码可生成完整包。

## 固定版本

- 源码基线 HEAD：`c8c8d2a2e7fc99021fc45e2400f2302df0cfb24f`。
- 六个代码／测试文件的逐文件 SHA-256：`2026-10-13-historical-reservation-store-files.sha256`；该清单 SHA-256：`0a75081b19dcd9a5208fdb1547ad533af4302e02c1110394d02a5750c86b28aa`。
- 对上述四个已跟踪文件的 HEAD diff 加两个新文件的 no-index diff 计算补丁 SHA-256：`8950fb0f9674d1a14074a094b4a6a5dc2f85c47aab3536dd573beb1bd5c61d8f`。
- H5 构建目录 `/tmp/m008-historical-reservation-final/`，502 个文件；部署包 `/tmp/m008-historical-reservation-h5-fixed.tar.gz` SHA-256 `84b099286cefd303a8613f4d615f508b95b36fdec82af7ad1ba37a084c64a8d4`。

## 自测证据

- PHP 四个文件语法检查、`git diff --check`、H5 入口及预约页产物 `node --check` 均通过。HBuilderX 5.26 生产 H5 编译生成预约页模块和完整静态目录。构建日志在首次 `DONE Build complete` 后还有一个热重载阶段，对不相关类字段语法给出解析提示，再次输出 `DONE Build complete`；产物存在且目标模块已解析。本包部署前仍应核对实际文件与入口依赖。
- MySQL 5.7 隔离库 `m008-rights-isolate/mohe_008`：订单 5338 的预约读取返回唯一渼约营业门店 11；歌妮门店 22、错误会员、停业门店、停用组织均被拒绝。隔离库添加与现有本地瑞昊库相同的 V3 预约／业务事件／outbox 表结构，未复制其业务数据；缺少的旧预约及核销空表仅为补齐隔离测试读取。
- `tests/customer-008/php/historical-reservation-store-isolated.php` 使用 2026-10-16 10:00 时段真实走到 V3 预约写入，外层事务回滚；同一请求重放得到相同预约 ID，同一剩余 1 次换时段重复预约被拒绝，权益剩余仍为 1。隔离库运行命令：`docker exec -i -e M008_RIGHTS_TEST_BACKEND=/var/www/html -e M008_RIGHTS_TEST_HOST=m008-rights-isolate -e M008_RIGHTS_TEST_PORT=3306 mohe-platform-app php < tests/customer-008/php/historical-reservation-store-isolated.php`。
- 未在 008 正式库创建预约，也未改变用户的余额、积分或权益。真实浏览器选店与提交只能在获准发布后于 008 验证；正式会员的预约提交会产生真实业务记录，验证时使用专用测试权益或只读检查，不消费截图中的用户权益。

## 交付边界

- 前端源码：`前端代码/uniapp/pages/activity/reservation/index.vue`、`前端代码/uniapp/vue.config.js`。
- 后端源码：`后端代码/app/services/cashier/v3/reservation/ImportedEntitlementReservationStoreServices.php`、`MemberV3ReservationServices.php`、`后端代码/app/services/order/StoreReservationOrderServices.php`。
- 自动化测试：`tests/customer-008/php/historical-reservation-store-isolated.php`。
- 数据库迁移、工程脚本：无。生成物：本地 H5 构建目录及 tar 包，不入 Git。验收证据：本说明及逐文件校验清单。
- 提交前按精确文件清单暂存，保留工作区其他任务的脏文件。部署仅在产品经理验收和授权 008 发布后进行；先核对当前 008 版本、迁移、静态文件回滚点与 PHP 回滚点，再逐项发布、线上核验。
