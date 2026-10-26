# R8 第八轮：瑞昊发布与线上核对

## 范围与提交

- 产品经理确认本地测试无问题并授权本轮 commit、部署瑞昊和 push；人员类型修改仅针对未删除人员，包含原合作方，不另做新备份。
- 本地闭环提交：`73523d06` 项目替换目标权益欠款口径；`fdbdfd56` 跨组织手艺人候选权威校验；`26a5eab3` 人员类型统一内部员工数据脚本。各提交只含其独立源码、测试与固定版本证据。
- 唯一源码正本是 `美容源码/`。本轮前端源码、构建生成物均无；后端源码 4 文件；数据库升级正本 1 文件；自动化测试 2 文件；原固定版本证据 3 文件。人员数据脚本仅在目标实例运行，未作为生产应用源码安装。
- `CashierV3OrderCenterRecordQueryServices.php` 在固定语法修复后被并行任务继续修改，本轮未提交、未部署此文件。当前本地和瑞昊运行文件均通过 PHP 7.4 语法检查，原 `T_DOUBLE_ARROW` 解析错误不再出现；未把该文件的其他退款列表改动混入本轮。
- 其他任务原暂存区补丁 SHA-256 在本轮提交前后均为 `dc31b057aca12a17ee75ed7a86e023bfeba79933849b91605faa54e209c95b46`。

## 瑞昊发布

- 仅目标实例 `rh.cc3798.com`，站点 `/www/wwwroot/rh.cc3798.com`，数据库 `ruihao`；其他客户实例未动。服务器遗留 Git HEAD 仅作历史定位，不作为发布版本。
- 发布前瑞昊三处旧服务与本地基线 `dac7aed2` 的 SHA-256 完全一致，新策略类不存在。仅将功能提交对应的四个后端文件送入临时路径，逐个校验提交哈希及生产 PHP 7.4 语法；持实例发布锁重查旧/新哈希后原子替换。
- 发布文件 SHA-256：`WriteOffOrderServices.php` `adefe5dcf857c91837bf3db7fbb7ae3922e05acfa27e42b4083c36999f601740`；`CashierV3CardRuleEntitlementAuthorityServices.php` `fae2060b143f9f4e2f213c9a10e0f720d526edc3b5367f8767a20cef6ebdc8e3`；`CashierV3ProjectReplacementDebtPolicy.php` `ba43afa98badda8b540e3b20ce7dda43c5a2be15fd2cb7effed7dd8a0dd4106d`；`CashierV3QueryEntitySelectorServices.php` `6b2978bf4a9842215a671b20a68b90bed2c73c5690b3602902a778c74f498da9`。
- 使用当前瑞昊单实例 `/etc/init.d/ruihao_swoole restart` 重载后，`manager_count=1`、PID `27633`、20800 监听正常；正式 `/store-v3/` 和预览收银入口 HTTP 200，匿名配置接口 HTTP 200。检查的最近 60 行 Swoole 日志仅见启动信息，不扩称全系统无异常。
- 新权益策略在生产环境加载，合规项目替换标记返回真，普通空标记返回假；未执行真实结账或核销。跨组织候选只读查询返回范玲丽 `employeeId=1803`、`internal/1`。

## 人员数据与回退

- 本地数据事务已更新 4 人；瑞昊脚本执行前重新核对 `ruihao` 及 3 人指纹 `43082220fb331b1f8d0a86649645bde06646a84b83f25f0af32c9f1e949f6320`，事务更新 3 人，审计请求号 `r8-personnel-internal-20261011-rh`。
- 瑞昊反查：1299 位未删除人员全为 `internal`；127 条已删除未分类档案未动。三条审计分别保留 `partner/2→internal/3`、`partner/1→internal/2`、`NULL/0→internal/1`。既有订单及业绩事实快照未回写。
- 按要求未创建新的代码或数据库备份。代码回退须先确认运行哈希仍为本轮版本，再从本地基线恢复原三文件并移除新策略类；数据回退必须按上述请求号的原值审计逐行核对、加锁更新，不能直接恢复整库或覆盖后续人员修改。本次未执行回退。

## 测试与推送

- 本地专项：项目替换欠款 9/9、跨组织候选 2/2、前端候选合同、PHP 7.4 语法检查均通过；本地人员更新后重复执行被预检指纹拒绝。既有 `r14-shared-card-debt-limit-mysql.php` 曾在一项普通购卡夹具断言失败，非本次替换路径，不能记为全套回归通过。
- 线上验证为文件字节、服务状态、匿名入口、生产类边界与只读业务查询；未执行真实收款、结账、核销或作废。
- 本地与 Gitee `origin/release/store-cashier-c14-main` 历史并行，未直接推送本地 HEAD。以远端旧 tip `c3549b51602a58644e33d34a1abb5f3d042a8214` 为父，隔离索引构造只含本轮 10 文件且 blob 与本地提交一致的集成提交 `f340c0171b44b6d9bafbd7b8b42bfd9d7fcb74f5`；快进推送并经 `ls-remote` 回读确认。未推送 GitHub、未强推、未带入其他并行改动。
