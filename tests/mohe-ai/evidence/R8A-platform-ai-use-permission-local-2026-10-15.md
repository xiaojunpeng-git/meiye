# R8A 魔核 AI 平台使用权限：本地固定版本与自测

- 固定基线 HEAD：`b94bf7d375f63649cd04d19a6de490061594e392`
- 补丁 SHA-256：`8a15e9055edddc3602fa2adf8f6643e1973b2b14c44d6616f6a0bec6b6a81083`（已跟踪文件的 `git diff --binary`，按下表顺序附上新增文件路径和原始内容）
- 固定清单 SHA-256：`28be3b30f4790f2d8d338b3cc710daba413a30a695c9466316716d1ea430418a`
- 状态：开发与本地自测完成，待产品经理对本固定版本确认验收；未提交、未部署瑞昊、未 push。

## 范围与边界

权限规则的“设置”节点新增 **魔核 AI 使用** 功能规则（`mohe-ai-entry`）。平台普通账号须有有效岗位规则且仍具备自身数据范围才能问数；平台超级管理员沿用现有功能权限口径。撤销岗位、停用规则、停用岗位或失去数据范围时，服务端拒绝后续问答及导出续跑，启动信息不再返回指标和门店能力目录。模型配置、指标管理和评价查看继续仅限既有根管理员 `admin`。门店端、商家端权限未改。

迁移仅登记平台端功能规则，不登记可点击的 AI 管理菜单。为了上线切换不打断已有平台用户，首次迁移将该规则补进当时有效的平台和区域代理岗位；门店岗位和停用岗位不补。后续岗位可单独取消，重复运行迁移不会恢复已取消的授权。规则只决定能否使用 AI，所有指标查询仍由原服务端数据范围裁决。

## 文件清单与 SHA-256

| 分类 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/ai/AiGatewayServices.php` | `7b3b2c408722deaa06664e204bbae0c608e316cb24ac56ac9ef6e312294e51c4` |
| 后端源码 | `后端代码/app/services/ai/execution/AiPlatformPrincipalResolver.php` | `021e1008613d55e9b9f73fae620b4c5ad630afd03a5b16aebd4f9feeaad2aaf7` |
| 后端源码 | `后端代码/app/services/ai/execution/AiPlatformUsePermission.php` | `92c182f5404b217d064fff37407094c67bb6492ab87911ef42043c87524ebef3` |
| 后端源码 | `后端代码/app/services/system/SystemMenusServices.php` | `93a339abf94d8cc5967621254cba006767ad9c8ebaf915952f5d7bf6d07d74c6` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-15-魔核AI平台使用权限/02-正式升级.sql` | `baeb40d918475e1871aa33d5b293f428d95fc7eb1565b3b1a0c3338a25061c51` |
| 自动化测试 | `tests/mohe-ai/principal-resolvers.php` | `3bdf851d014a1473a47fd9bdb9655770f85f8e5f54447df083b9831edc54b94c` |
| 自动化测试 | `tests/mohe-ai/management-gateway.php` | `2a19b2143c48eb97e9352738893282663f22ab9dea89623a0836d52464309705` |

前端源码：无。工程脚本：无。生成物：无。验收证据：本文件；不计入上述代码补丁指纹。

## 自测记录

1. `php tests/mohe-ai/principal-resolvers.php`：57 项通过，覆盖授权、撤权、隐藏及删除规则、停用岗位、空岗位、后台任务续跑拒绝和原数据范围。
2. `php tests/mohe-ai/management-gateway.php`：37 项通过，含无使用权时启动信息不暴露能力目录。
3. `php tests/mohe-ai/gateway-integration.php`：183 项通过；`php tests/mohe-ai/management-menu.php`：22 项通过，配置管理仍限根管理员。
4. 变更 PHP 文件全部通过 `php -l`，目标文件 `git diff --check` 无空白错误。
5. 本机 Docker MySQL 5.7 临时隔离库执行迁移：首次添加规则于设置父级，平台及区域代理有效岗位保留使用，门店和停用岗位不获授权；人工撤销一个测试岗位后再次执行迁移，未恢复其授权。临时测试库已删除。
6. 本机 `mohe-mysql` 的 `ruihao` 测试库已执行迁移；通过本地平台应用自身读取，规则 ID 2299、关键词搜索命中 1 条、岗位授权树包含该规则；A18156013263 `can_use=true`、`can_configure=false`。`18081` 权限规则页面实际搜索“魔核 AI 使用”显示规则和 `mohe-ai-entry`，接口路径为空。测试库与 `mohe-platform-app` 均位于本机 Docker 网络 `172.18.0.2`，未连接线上数据库。

## 发布前检查

瑞昊线上尚无该规则。部署须先独立备份目标实例的菜单和岗位授权并核对设置父级、迁移版本、当前 commit 和回滚点，再先执行迁移、后发布已验收的后端代码；分别复核权限规则列表、岗位授予与撤销、普通账号问数、配置管理拒绝及数据范围。此次固定版本不含其他客户实例、平台前端构建或小程序上传。

新增和更新的业务注释位于 `AiPlatformUsePermission.php`、`AiPlatformPrincipalResolver.php`、`AiGatewayServices.php`、`SystemMenusServices.php`；说明岗位使用权、数据范围、启动信息和关键词搜索的边界。引用扫描确认新权限类仅由平台主体解析器调用；旧 `mohe-ai-entry` 无运行时使用分支，本闭环以它作为唯一规则标识，没有保留并行实现。
