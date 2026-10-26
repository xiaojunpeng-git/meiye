# R41 门店端切换门店自测与验收版本（2026-09-29）

## 固定范围

- 基线 HEAD：`79cd177956b56d56298bb44917fc3867647b42de`。本闭环只改下列门店端文件；工作区其他已有改动不属于本轮，未提交、未部署、未 push。
- 前端源码：`前端代码/cashier-v3/src/layouts/CashierShell.vue`、`src/services/storeV3LoginApi.js`、`src/styles/base.css`、`src/views/StoreLoginView.vue`。
- 后端源码：`后端代码/app/controller/cashier/v3/StoreLogin.php`、`app/services/cashier/v3/CashierV3StoreLoginServices.php`、`route/cashier-v3.php`。
- 自动化测试：`tests/cashier-v3/js/store-switch-contract.mjs`、`store-login-presentation-contract.mjs`、`tests/organization-store-v3/js/single-store-tenure-contract.mjs`、`store-v3-login-entry-contract.mjs`。
- 数据库迁移与工程脚本：无。生成物：本地 `cashier-v3/dist/`，不作为源码。验收证据：本文件。
- 已跟踪闭环文件补丁 SHA-256：`c171bba6891290260f24990a7a540f4164342dae5f2aa4b69ff1d55cf33cec13`；新测试文件 SHA-256：`e2070ce95f862f6a30374ee36b63769f0ca16987bba8dcf2cb3f243ad7641282`。各文件 SHA-256 如下；验收期间任何文件变化均须重新固定并补验受影响范围。

| 文件（均相对 `美容源码/`） | SHA-256 |
| --- | --- |
| `tests/cashier-v3/js/store-switch-contract.mjs` | `e2070ce95f862f6a30374ee36b63769f0ca16987bba8dcf2cb3f243ad7641282` |
| `tests/cashier-v3/js/store-login-presentation-contract.mjs` | `1dfe7f3ea407d811ef3c54be7219cb128cf8088175ec9d64857b06ef849df0c7` |
| `tests/organization-store-v3/js/single-store-tenure-contract.mjs` | `6f23781a78dec8167e1b812b062c14e33f77d5146e5a1a21e139b2ec4d7d65a0` |
| `tests/organization-store-v3/js/store-v3-login-entry-contract.mjs` | `23ff6252996438697e39d1e48eb2f9f103c0db1c770fb9598a4514072d0994c2` |
| `前端代码/cashier-v3/src/layouts/CashierShell.vue` | `310e462e70a9e85554f41ba4fda7903afc0c84618d72469c6a734738207d5fd6` |
| `前端代码/cashier-v3/src/services/storeV3LoginApi.js` | `fd806adab506b0f13ea649cfa3d09833df0c465dfd8e36845f517ef80d3c6648` |
| `前端代码/cashier-v3/src/styles/base.css` | `34a3893d33339e5c7f25e090359e8c202dc69f0d0843092a27f661bd4d55e8ee` |
| `前端代码/cashier-v3/src/views/StoreLoginView.vue` | `017e6cbe68d56ee4cd1d2d08a66ba646ac1b57d252fa09210d8187a8193b4af0` |
| `后端代码/app/controller/cashier/v3/StoreLogin.php` | `d931b5581b7f022363c077283d0a8b15b8c228f895e9f3c1818250ed359b8d05` |
| `后端代码/app/services/cashier/v3/CashierV3StoreLoginServices.php` | `7457150a5debaa3797f5e52c282ac6f36c9501761d0c62ef2437daa4ecf5700a` |
| `后端代码/route/cashier-v3.php` | `1cd542f35f3be0cfd17de0c137cef31555604d28692da2b7c6e65e48a678bbe8` |

## 方案与数据架构自检

- 权威身份与范围：服务端 `CashierV3StoreLoginServices` 复用登录时的 `organizationLoginCandidates`，逐次重算员工门店端入口、组织数据权限与门店状态。唯一有效任职门店账号不进入组织切店候选。客户端只显示候选，不凭传入 `target_store_id` 扩权；目标与当前会话门店都须属于服务端候选集。
- 切换事件是重新签发绑定目标门店的会话，不改员工任职，也不改订单、库存、收款、会员等业务主表或统一事实。事实粒度、四类时间、金额、冲销、Outbox、历史快照、报表口径和索引均不变；新会话仍由原有服务端门店强制中间件限定数据范围。
- 新令牌初始化完整工作台成功后，页面撤销旧令牌并清空旧门店的库存挂载令牌、会员／服务操作现场和收银来源。失败恢复旧令牌与旧工作台；恢复也失败则退回登录页，不继续展示不一致的门店投影。旧会话撤销失败不扩大权限，其有效期仍由原会话机制控制。
- 切店只是会话写入，不产生经营事实；无业务事务／并发扣减／幂等命令／报表对账变更。候选查询沿现有索引与授权解析路径，没有新增跨门店客户端查询；本地集成检查验证目标门店会话绑定，测试产生的会话行已回滚并撤销令牌。

## 已执行验证

- `npm run build`：Vite 1983 模块构建成功；包体积警告为既有状态。
- PHP 语法：上述服务、控制器和路由三文件均通过 `php -l`。
- JS 合同：新切店权限与会话合同 7/7，登录呈现 10/10，组织选店入口 16/16，单门店任职合同 16/16；`git diff --check` 通过。
- 本地数据库只读候选：已有多门店组织账号返回 102 个授权候选、1 个组织树根；对无权目标与当前店目标均拒绝。授权目标在本地事务中成功签发目标店令牌，测试令牌撤销、事务回滚后组织会话行数仍为 2。
- 本地 HTTP：未登录访问新候选接口命中登录拦截，返回“请登录”；未绕过认证。原有浏览器登录的是单门店账号，左上角仍显示静态门店标识，浏览器控制台无错误。
- 本地浏览器黑盒：使用产品经理提供的多店测试账号，在独立临时标签登录，先从登录组织树进入“肥西水晶城”，左上角出现切店按钮；点击后复用组织／门店双栏弹窗，选择“合肥滨湖店”成功。目标店盘点列表当天 0 条；从库存页切回“肥西水晶城”后，旧库存工作区关闭，重新打开盘点列表为 1 条，单号 `PD2609290001`，证明两店查询未串用同一列表。刷新后左上角仍显示当前店与切店按钮；最后退出测试账号并关闭临时标签，原用户标签未改动。只进行了登录、切店和只读查询，没有新增或修改业务单据。
- 预览源核对：首次新标签仍见 Vite 旧缓存的登录文案；只重启本地 `mohe-cashier-v3-src` 开发容器后，18091 模块响应显示 R41 新文案，再执行上述黑盒测试。没有操作远程实例、8080 集成包或其他本地服务。

## 待产品经理验收

多店账号的本地弹窗、切换、返回、刷新及盘点列表隔离已实际操作验证。产品经理仍需对该固定版本明确确认验收；本轮状态为“开发与自测完成，待产品经理确认验收”，不据此创建 commit 或部署。
