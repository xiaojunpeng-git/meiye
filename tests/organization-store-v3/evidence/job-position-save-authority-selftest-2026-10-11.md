# JP-00001 岗位保存额外超管限制修复

- 固定版本：JP-20261011-save-authority-v1；开工 HEAD `2bb1eb7dc6bc0b1b3a11342422918bd7a9f3b996`，固定清单 HEAD `ea8e205421b99c27df13b5fce6f95090c63eb2ef`（期间其他任务推进 HEAD，本次 4 个业务文件补丁不变）。
- 产品需求：岗位策略弹窗“保存岗位”提示无权，去掉该处额外限制。
- 状态：本地开发与自测完成，待产品经理对本固定版本确认验收。未 commit、部署或 push。

## 修复范围

独立岗位页及组织工作台的岗位表单保存不再使用包含超管判断的通用 `can_write`。后台岗位保存不再额外要求 `level=0`，改为验证有效平台账号已拥有现有岗位策略功能。总写开关、后台登录和 HTTP 功能鉴权、请求令牌、结构锁、事务、幂等、名称及权限内容校验保留。

没有向真实账号增加权限，没有修改岗位发布、状态专用命令或其他组织操作的超管限制，没有改页面布局或收银页面。岗位编辑表单本身已有启用状态字段，按原有完整保存语义保留；本次没有放宽状态专用接口。

## 自测

- `node tests/organization-store-v3/js/job-position-save-authority.mjs`：两个真实页面保存方法执行通过；非超管进入请求、总写开关关闭和重复点击保护通过。
- `node tests/organization-store-v3/js/job-position-status-toggle.mjs`：状态专用保存、不重存权限、错误恢复及只读保护通过。
- `node tests/organization-store-v3/js/job-position-policy-contract.mjs`：6 项既有合同通过。
- `docker exec -i -e JOB_POSITION_LOCAL_TEST=1 mohe-platform-app php < tests/organization-store-v3/php/job-position-save-local.php`：15 项真实本地数据库/控制器测试通过。合成非超管账号通过已有岗位策略授权创建、编辑、回读；相同令牌回放、异载荷冲突、缺令牌、空名称、撤销角色、停用账号、缺身份拒绝及其他状态命令仍受原边界保护。
- 两个后端文件 PHP lint 通过，4 个业务源码文件 `git diff --check` 通过；本地管理员 Vue 热更新编译完成。
- 浏览器打开原岗位编辑弹窗，名称及功能权限正常显示，“保存岗位”可用；没有点击保存真实岗位。截图仅证明页面呈现，不冒充完整浏览器写入验收。浏览器历史里存在早于本次验证的两条 `Event` 错误；本次未新增观察到的控制台错误。
- 所有合成账号、角色、岗位、规则与幂等写入均在外层事务结束时回滚；测试没有签发登录令牌。

## 数据架构自检

权威源沿用 `position`、`job_position_channel_rule`、有效平台账号及其角色菜单；既有人员投影继续由原保存服务更新。这里只调整一个保存命令的授权判定，不产生订单、金额或经营统计事实，不改变统计时点、历史快照、冲销或报表口径。岗位版本递增、组织结构锁、事务和严格请求幂等继续使用原路径；授权在执行和幂等回放前核对，撤销后不得重放写入。未增加迁移、索引、客户分支或第二套源码。

## 文件分类与注释

- 前端源码与边界注释：`前端代码/admin/src/pages/store/region/job-positions/index.vue`、`前端代码/admin/src/pages/store/region/workspace/index.vue`。
- 后端源码与职责/授权注释：`后端代码/app/services/organization/JobPositionPolicyServices.php`、`后端代码/app/services/organization/OrganizationWorkspaceWriteGate.php`。
- 自动化测试：`tests/organization-store-v3/js/job-position-save-authority.mjs`、`tests/organization-store-v3/php/job-position-save-local.php`。
- 数据库迁移、工程脚本、构建发布包：无。
- 验收证据：本文、同目录截图 `job-position-save-authority-2026-10-11.png` 和固定版本清单 `job-position-save-authority-fixed-2026-10-11.json`。

## 环境边界

唯一源码为当前 `美容源码/`；仅本机 `mohe-platform-app` 的 Docker 测试库（主机名 mysql，数据库 ruihao，显式测试开关）及 18081 热更新验证。未连接任何远程实例；客户部署备份/迁移版本不适用。恢复只需撤回本次 4 个源码文件的定向补丁，不能覆盖其他并行工作区改动。确认验收前不提交；其他客户与生产实例保持原状。
