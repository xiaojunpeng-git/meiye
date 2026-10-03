# R59 查询范围与对象继承：本地修复自测

## 问题与修复边界

此前已验收版本 `85ecdee0f71df5251846fc57c714c58c7261faeb` 首次部署瑞昊后，在线上隔离问句“这个月会员那个金额的情况”只显示“我来补充描述”，没有三个可执行推荐。该次部署已回滚，本记录不代表重新上线。只读检查该次运行的结构化理解发现，模型把 `scope` 与会员、期间、金额一同保留；推荐规划器将 `scope` 当作不支持字段，候选池因此为空。

本次仅让推荐规划器接受协议已有的 `authorized`、`unspecified`、`current_store` 范围值。前两者继续使用服务端数据权限；`current_store` 只有在服务端取得已认证、且属于授权列表的入口门店 ID 时才绑定；显式要求全部授权范围的追问不会悄悄沿用上条查询的窄范围。其他无法安全保留的条件仍不生成推荐。推荐预检不读取经营事实。

配置本地真实模型后，第一次同句问答暴露第二个缺陷：模型只保留金额、期间和范围，漏掉客户已说清的“会员”分析对象；推荐路径便套用无对象问句的门店默认值，出现三条错误的门店选项。现在理解提示要求保留清楚的对象；若模型仍漏掉已登记的对象称呼，网关只阻止默认门店推荐，不通过词语自行推断或绑定另一个对象。真正没有指定对象的问句仍可使用既有门店默认推荐。对象词表来自统一指标注册能力，不维护问句白名单。

## 固定版本与文件

基线 HEAD：`85ecdee0f71df5251846fc57c714c58c7261faeb`。七个修改文件的限定补丁 SHA-256：`54975e0db8f908677482cf9ec77b597dece23273eb629aa87e8762f2350b3bbd`。本次修改尚未提交、部署或推送；仅以下文件属于本闭环：

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/ai/execution/AiSemanticRecommendationPlanner.php` | `9eb1a6399d6d29fe9ce908158bf4a2499efd83db0d3a97ab499f3342c31c8097` |
| 后端源码 | `后端代码/app/services/ai/AiGatewayServices.php` | `1a2010b428ffa382aa55252f0a16c2d5dc67954c5b5481a01091155cd589f82d` |
| 后端源码 | `后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php` | `f0104a4b1ad0d8e5b6baf0a24aa6947c65baa9dd181cbd15907b9d68ef822e30` |
| 后端源码 | `后端代码/app/services/ai/model/SiliconFlowClient.php` | `b10c696ad71f725991c560813f9df6c6e50cbcc0d4886fb056c81652dcf4a3ae` |
| 自动化测试 | `tests/mohe-ai/recommendation-planner.php` | `5f3501753b4ce0b2f1c421db2f4db716c065a792d6413d996d72c63b64f69911` |
| 自动化测试 | `tests/mohe-ai/intent-understanding-contract.php` | `30662fc7cb2549b1db85c79857ca019d3e9aa62feb013ad8a7737eeb0dbc1dea` |
| 自动化测试 | `tests/mohe-ai/gateway-integration.php` | `1d14a12ce345331c285a5b9fe1fb1071f06a6bc8f8708fde860c799df70dbb96` |

前端源码、数据库迁移、工程脚本、生成物：无。本记录是验收证据，不是业务源码。未触碰工作区内其他任务已有的未提交修改。

## 自测结果

- `php tests/mohe-ai/gateway-integration.php`：182 项通过。复现相同问句及线上看到的 `scope=authorized` 结构，返回三个推荐；另用缺失对象载体验证不会给会员问题错推门店指标；选择前无事实查询。
- `php tests/mohe-ai/recommendation-planner.php`：127 项通过。使用真实指标注册表与编译器预检，覆盖授权范围、未指定范围、缺失或越权的当前门店、合法当前门店、未知对象及窄范围追问防扩权。
- `php tests/mohe-ai/intent-understanding-contract.php`：242 项通过。
- `php tests/mohe-ai/gateway-components.php`：141 项通过。
- `php tests/mohe-ai/context-delta-gateway.php`：102 项通过。
- `php tests/mohe-ai/semantic-guidance.php`：104 项通过。
- `php tests/mohe-ai/gateway-review-regressions.php`：85 项通过。
- `node tests/mohe-ai/browser-guidance-contract.mjs`：180 项通过。
- `node tests/mohe-ai/browser-mobile-guidance-contract.mjs`：193 项通过。
- 七个修改文件 `php -l` 通过；限定范围 `git diff --check` 通过。

产品经理明确确认两项外部处理授权后，用用户提供的 Key 经本地既有加密配置服务保存并启用，模型为 `Qwen/Qwen2.5-72B-Instruct`；配置只在本机 `ruihao` 实例，不写源码、命令参数或本记录，不复制到任何其他实例。本地真实模型与登录 admin 工作台测试：

1. 问“这个月会员那个金额的情况”，初次暴露模型遗漏对象而错推门店，已据此补修；该失败样本保留在本机对话记录。
2. 重启本地平台与 AI 消费者后，在新对话同句复测：理解载体明确 `object_kind=member`、`object_relation=analysis`、本月期间；卡片显示三个会员相关的已预检推荐加“我来补充描述”，没有门店指标。
3. 选择“会员的现金业绩（逐个明细）”后，同一 Run 完成真实只读查询，页面统计时间为 `2026-10-01` 至 `2026-10-04`，仅显示前 20 条且生成 Excel；选择后的完整回答显示用时 2 秒。本记录不保存会员姓名或经营金额。
4. 另在新对话问“业绩多少？”，仍得到三个通用门店业绩推荐加补充描述；该等待 Run 已取消，不读取事实。

本地真实问答已通过，但不冒充瑞昊线上或实体小程序验收。瑞昊仍运行回滚后的版本，未经本次固定版本验收和另行部署授权，不进行线上验证或推送。

## 架构、注释与待验收项

不改变数据源、指标、事实粒度、计算时点、金额口径、事务、数据库或权限过滤；仅调整已有推荐候选的范围保留、鉴权与对象安全边界。关键约束注释已补充或更新于 `AiSemanticRecommendationPlanner.php`、`AiGatewayServices.php` 和 `SiliconFlowClient.php`；理解契约提示同步升级为 v23。该路径没有完整替代其他旧业务分支，引用检查没有发现可随本闭环删除的平行实现。

待产品经理对以上固定版本确认验收。瑞昊线上与实体小程序验收须在另行授权部署之后完成。
