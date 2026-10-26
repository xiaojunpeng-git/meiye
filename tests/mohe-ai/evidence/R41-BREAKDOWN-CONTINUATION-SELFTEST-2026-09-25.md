# 第 41 轮：经营概览转对象分组连续问答——开发与真人自测记录

## 固定版本

- 自测日期：2026-09-25（Asia/Shanghai）
- 基线 HEAD：`44f83d3e9ddf01b38e9aa339a0436b5971b8a5f3`
- 状态：本地开发与自测完成，尚未 commit、部署或 push
- 本记录及以下失败 Run 均按产品经理要求保留，不删除

### 闭环文件与 SHA-256

| 文件 | SHA-256 |
| --- | --- |
| `后端代码/app/services/ai/AiGatewayServices.php` | `c696ab45a483f1aee487a2f38339034b72ea88b246e464211635fa1bcbbedeac` |
| `后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php` | `db2071e5b488baefd8869ff6283c31d57e1bac5734c3f6822f6501ed0576b2c6` |
| `后端代码/app/services/ai/contract/AiIntentResultContract.php` | `cc594fa030374016fad2dd44b5197639556bfe5d00fb9b3e5cd21fb12862a456` |
| `tests/mohe-ai/breakdown-query.php` | `8c7471541ce26a817ebba6e0dbcd5f3649220e85034d6a11af2d7c924afd43f4` |

## 问题与修复边界

复现链路是：先问“这个月的经营情况如何”，再追问“各门店的经营详情呢”。首问能得到可信经营概览，追问却在指标绑定阶段失败，数据 Reader 从未执行。

修复保持三层边界：

1. 语言理解层明确区分“上一条具体对象的详情”和“各/所有对象的分组分析”；复数对象不能仅因出现“详情”就降为单对象下钻。
2. 协议层只接收模型已经给出的 `object_kind + analysis + breakdown` 类型，不从中文短语反推对象或指标。
3. 指标由能力注册表的 `default_breakdown_object_kinds` 唯一声明决定。门店、人员、会员共用同一编译逻辑；明确说出的注册指标、多个独立指标、条件、排除、具体对象详情均不能进入默认路径。

连续追问继承上一条可信查询的日期和授权范围，替换分析对象并清除旧分析维度。快捷路径仍通过意图合同、能力、权限、计划编译、Reader 和结果校验；它只省掉重复且不稳定的第二次指标绑定模型调用。

## 真人浏览器测试

环境：`http://127.0.0.1:18081/admin/setting/mohe-ai`，平台 admin，正常网络，真实本地瑞昊数据；通过页面输入、发送、等待完整流式回答并目视核对。

### 1. 门店维度连续追问

1. 新建对话，输入“这个月的经营情况如何”。
2. 完整回答成功，显示 10 项本期经营概览，统计时间 `2026-09-01` 至 `2026-09-25`。
3. 同一对话输入“各门店的经营详情呢”。
4. 完整回答成功：页面显示“本期有 13 家门店产生实际业绩，合计 453,801 元”，并显示门店/实际业绩两列表格；89 家零值门店被隐藏。
5. 页面用时：9 秒；服务端 client elapsed：9,851 ms。

成功 Run：

- conversation：`011fd78319fd8b3f30dfeb449e546f15`
- generation：6
- run：`282296f1c573a3e6ff747bb19dbf56111887fe3309260b57`
- status：`COMPLETED`
- 模型调用：1 次（仅 `understand_meaning`，7,912 ms）
- 查询：`unified_metric_query`，279 ms
- 结论：跳过 `bind_intent`，日期和权限继承正确，按注册表门店默认视角 `actual_performance` 执行。

### 2. 人员维度连续追问（防止只修门店）

1. 新建对话，输入“这个月的经营情况如何”。
2. 概览完整回答成功，统计时间仍为 `2026-09-01` 至 `2026-09-25`。
3. 同一对话输入“各员工的经营详情呢”。
4. 完整回答成功：页面显示“本期有 36 位人员产生销售人业绩，合计 464,899 元”，列出人员/销售人业绩两列表格，当前展示前 20 条并提示可继续缩小范围。
5. 页面用时：6 秒；服务端 client elapsed：6,334 ms。

成功 Run：

- conversation：`a60a9aeb4bac2ea79c8ed091385150e9`
- generation：2
- run：`2089d61134bb8c0c9388e5612958defc3530d39c46bed13e`
- status：`COMPLETED`
- 模型调用：1 次（仅 `understand_meaning`，4,154 ms）
- 查询：`unified_metric_query`，104 ms
- 结论：同一通用路径按注册表选择人员默认视角 `staff_sales_yeji`，没有门店专用判断。

## 保留的失败记录

这些记录用于证明复现、定位过程和修复前后差异，数据库 Run 与本文均未删除：

| Run | 状态/耗时 | 诊断意义 |
| --- | --- | --- |
| `4381deca39e3af37bddb322268488e2e1310e2787e1c908c` | FAILED / 27,477 ms | 第 41 轮原始基线，`binding_requirement_metric_mismatch`；两次模型调用，未进入查询 |
| `93dcaf6fd27d676ac47dc0893250f868695c754a3b6ed197` | FAILED / 页面 21 秒 | 首次真人复现；确认仍停在指标绑定，未误报通过 |
| `7be927a0af26b6b0d7033406218489a02c1788e46da539d1` | FAILED / 页面 23 秒 | 诊断出理解模型会重述已验证日期，促使合同改为只允许“等价日期继承” |
| `b9fe9954ba3055e9934f1c07a293747f8ef572ac08422f97` | FAILED / 页面 22 秒 | 诊断到宽泛指标载体边界 |
| `061278ad7dc692781ba29f9f75058bca313246d06c686bf4` | FAILED / 页面 19 秒 | 发现 `requirements()` 返回以 id 为键的映射；修正审计行 id 读取方式 |

## 自动化回归

以下测试在最终代码上全部通过：

- `breakdown-query.php`：51 checks
- `context-delta-gateway.php`：88 checks
- `gateway-components.php`：139 checks
- `gateway-review-regressions.php`：85 checks
- `binding-object-capability-boundary.php`：6 checks
- `intent-understanding-contract.php`：144 checks
- `semantic-binding-guard.php`：12 checks
- `object-detail-continuation.php`：31 checks
- `member-detail-continuation.php`：22 checks
- `analysis-object-resolution.php`：16 checks
- `query-context.php`：64 checks
- `registry-execution.php`：103 checks
- `gateway-integration.php`：155 checks
- `deterministic-summary-admission.php`：22 checks
- `exact-ranking-collection-admission.php`：8 checks
- 三个修改 PHP 文件均通过 `php -l`
- 修改文件均通过 `git diff --check`

新增合同用例覆盖：门店、人员、会员三个对象维度；省略冗余 `object_relation`；等价日期重述；明确注册指标不走默认值；具体对象详情不被改写为群体分组。

另发现既有 `analysis-capability-catalog.php` 单独运行时引用已不存在的 `AnalysisCapabilityCatalog`，属于本轮变更前的陈旧测试入口；本轮未修改该文件，也未将其结果冒充本轮失败。

## 代码质量与注释

- 新增的协议编译方法、关键安全分支、日期继承约束和网关快捷路径均补充了职责、输入边界及禁止事项注释。
- 没有新增门店名称、客户域名、固定指标短语或问句切片规则。
- 没有保留第二套查询实现；最终仍进入统一计划、权限和 Reader 链路。
- 本轮未创建数据库迁移、前端源码、构建产物或远程变更。
