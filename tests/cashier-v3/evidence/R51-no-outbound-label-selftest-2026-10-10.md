# R51 第6轮：消费订单不出库标签

## 固定版本

- 版本 `R51-no-outbound-label-20261010`，当前基线 HEAD `4a8fd8ca4122037ae4eef5ea432c6424ff78062d`。共享工作区HEAD已由其他任务推进，本次未提交、暂存或覆盖其他改动。
- 用户逐项授权：消费订单商品名称右侧也显示“不出库”标签；无其他页面布局、按钮或收银操作修改。
- 规则：普通商品正式销售行 `inventory_outbound_required=0` 显示“不出库”；预售仍显示“预售”，不重复显示其隐含的不出库；正常出库不加标签。非商品、缺失/非法快照和未关联正式V3明细的旧记录不推断标签。
- 开发与自测完成，待产品经理确认验收；未部署、未push，未做历史数据写入。

## 文件清单与注释

- 前端源码：`前端代码/cashier-v3/src/views/OrderCenterView.vue`，使用现有标签样式，在预售标签后添加互斥“不出库”标签；注释说明预售优先及禁止从流水反推。
- 后端源码：`后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php`，两处正式销售行读取补齐字段；正式及关联V3兼容映射返回只读 `isNoOutbound`；注释说明明确零值、历史缺失和正式快照边界。
- 数据库迁移：无。
- 自动化测试：扩展现有 `tests/cashier-v3/php/order-center-product-presale-read.php` 与 `tests/cashier-v3/js/order-center-product-presale-render.mjs`，不创建平行标签实现。
- 工程脚本：无。
- 生成物：本地 `前端代码/cashier-v3/dist/`，仅验证构建，不是8080集成发布包，不作为源码。
- 验收证据：本文件及 `R51-no-outbound-label-local-2026-10-10.png`。

## 自测与构建来源

1. PHP7.4映射回归通过：整数/字符串零和一、缺失、null、false、空串、非法值；正式及关联兼容路径；项目/卡项/权益排除；旧购物车不推断。
2. 本地Docker `mysql/ruihao` 只读事务真实查询两个现有订单，各重复读取两次。`XS26100900006` 两行一预售；`XS26101000001` 三行分别不出库、预售、正常出库。每行标签均与正式快照一致，数量及金额不变，应收均1128元。测试要求本地Docker及明确数据库，未新建订单或写库存。
3. 真实Vue列表模板SSR通过：名称右侧“不出库”、预售优先、不重复标签、三条同名兄弟行独立；严格布尔与非商品排除；体验标签回归通过。
4. 浏览器点击查询并整页刷新后，`XS26101000001` 第1行“不出库”×1、第2行“预售”×2、第3行无标签×3，金额及现金收款1128元保持。截图完整页面已保存。
5. `npm run build` 成功，1994模块；既有大chunk提示保留。来源为当前18091使用的唯一 `前端代码/cashier-v3/`，Vite共享库存/查询包沿用当前源码，未启动或切换开发入口。纯本地构建，客户线上迁移、备份、回滚点不适用，不连接远程实例。
6. PHP语法与 `git diff --check` 通过。此次只读标签变更不重跑结账，不将其冒充库存写入路径验收；同轮库存修复证据继续单独保留。

## 数据架构自检

- 权威源和粒度：结账冻结的正式销售明细一行一标记，与当前商品配置、库存余额、领用结果及名称无关。
- 事件、统计时点、事务、并发、幂等：只读字段投影，不产生业务事件、事实、Outbox或新写事务，不改变既有结账/库存/收款生命周期或统计口径。
- 冲销与历史：不修改原销售快照或纠正事实，后续作废/领用不反推覆盖原结账选择；缺失历史保持无标签。
- 权限与性能：沿用原列表/详情的后端租户、门店及人员范围；只在既有字段选择与逐行映射中增加一列，无新增查询、全表扫描或客户端扩权。
- 迁移、对账与治理：无迁移/回填；逐行对照数据库标记及稳定行ID，金额数量不变。复用原标签分支和测试，未增加第二份页面/读模型。

## 指纹

两个业务源码相对本基线的组合补丁SHA-256：`931826fbe9e7733cc85a4dad3ce99dd44e722b593d1e3f02aa0b5993084c70a6`（包含同轮此前预售标签改动）。如下指纹共同固定最新标签验收版本，前项同文件旧指纹为历史记录，不再代表最新文件。

| 文件 | SHA-256 |
|---|---|
| 前端代码/cashier-v3/src/views/OrderCenterView.vue | db2305da29d9740cfc99fc43032779e7e1b71cc75cbe17389cb0b507544cbfea |
| 后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php | 2f59b6c973b6a0ffed0f7c56508c4a21774d511d6c48040fd2cd3991e885ffe7 |
| tests/cashier-v3/php/order-center-product-presale-read.php | 71da67a80b9cc52a61dc8dd57d8e88602a911f474193b28be818eb7711058040 |
| tests/cashier-v3/js/order-center-product-presale-render.mjs | 18eb2f915b2b5e0db0f43bd45acea7dbe5ad6d0cf3f98b12e4720b2f8e059582 |
| 前端代码/cashier-v3/dist/index.html | de6c8b31eda8a1d461830020e702651597aed6903d7a3deb9fb68b293115f137 |
| 前端代码/cashier-v3/dist/assets/index-2d1e958f.js | b8b0caffa80d3fb5c7f9a132eac95bce3570585b4940df5e08ac81ccc572e1d3 |
| 前端代码/cashier-v3/dist/assets/index-253ffe62.css | 253ffe62b817e528aba56c83b26950f0dafcba85fc61bd2f5f8219752e7e0d06 |

验收入口：[本地消费订单](http://127.0.0.1:18091/view_cashier_v3/#/order-center)，业务日期2026-10-10、霍山文峰店、订单XS26101000001。

## 追加：商品类型文字改为产品

- 用户明确授权名称前的类型文字“商品”改为“产品”。固定版本 `R51-product-type-wording-20261010`，基线HEAD仍为 `4a8fd8ca4122037ae4eef5ea432c6424ff78062d`。
- 前端源码仅改上述 `OrderCenterView.vue` 的类型文字投影，并注释说明保留接口 `itemType=商品` 给原业务判断。表头“商品”、项目/卡项、商品名称、预售/不出库标签与其他页面不改；后端、迁移、工程脚本无追加修改。
- 自动化测试仅扩展上述真实模板渲染测试：普通/预售/不出库均显示“产品”，不显示旧类型文字；项目/卡项维持原名，原类型数据未改，预售及不出库回归通过。`git diff --check`通过。
- 浏览器热更新真实验证本地XS26101000001三行均显示“购买 产品”，不出库/预售标签及金额数量不变。证据 `R51-product-type-wording-local-2026-10-10.png`。本次纯文字修改未另做整包构建；上节dist指纹仅属于追加前的本地构建，不能作为最新发布包。
- 数据架构：仅显示别名，不更改正式快照、事件/事实/统计时间、权限、事务/幂等/冲销或库存；没有新增查询或数据写入，无迁移和历史回填。不另建页面、重复类型映射或源码副本。
- 最新前端SHA-256 `25960399f5ca9bdf023d9a542494c21db6d4e6fba76dc7d9fa83f48fccb59884`；最新JS测试SHA-256 `79db6a4236f0ec24f74ba231b284091df2fc1317d524ef1fbb3040ea5a42d740`；截图SHA-256 `c0f89d3d8f58ef55abbc639165876b6fdec47a1f1fa94280bf1dd8da7ee4ff6c`。前端相对基线补丁SHA-256 `25fbf4d055d02b4ad9175080fc68f6d475bdf4f41d0c74ff1e6b084ceb2dfcba`，包含同轮之前已授权标签改动。
- 状态：开发与自测完成，待产品经理确认验收；未提交、未部署、未push。
