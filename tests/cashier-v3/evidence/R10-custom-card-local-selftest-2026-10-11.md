# R10 定制卡三年有效期与项目状态筛选

## 范围与状态

- 授权来源：第10轮两个收银批注；仅修改定制卡默认有效期及卡内项目“销售中/全部”单选。
- 基线 HEAD：`6b8ae2c4784169f4781910477cd043ecd732a643`。固定文件及 SHA-256 见同目录 `R10-custom-card-fixed-version-2026-10-11.json`。
- 唯一源码：当前工作区 `美容源码/`；门店入口 `http://127.0.0.1:18091/view_cashier_v3/#/cashier`，未创建 worktree 或第二份业务源码。
- 状态：本次开发及专项自测完成，待产品经理确认验收。未 commit、未打8080包、未部署、未 push。
- 工作区原有其他修改及暂存内容保留，不在本闭环范围。

## 实现口径

1. 新定制卡有效期按上海当前日期增加三个自然年，闰日钳制到目标年二月末；仍可手动修改。其他赠送期限不变。
2. 搜索框右侧增加“销售中/全部”单选，默认销售中；全部增加本店仓库中项目。
3. 关键字、显示已选与状态筛选组合使用，切换不清空选择或已填次数/金额。
4. 后端单独返回 `customCardProjects`，普通销售 `items` 不扩大到仓库中项目。只有定制卡组件使用新增候选及锁定校验。
5. 继续排除其他门店、删除、未审核、非正常上下架状态、停用 SKU、历史权益承载商品及隐藏定制卡壳。

## 验证结果

- `r10-custom-card-options.mjs`：通过，执行实际 Vue 组件 setup；覆盖三自然年、上海跨日、闰日/月末、默认状态、项目类型、搜索、选中保留、清空、目录刷新和提交载荷。
- `r30-custom-card-entry-contract.mjs`：通过。
- `custom-card-configuration-contract.php`：15项通过。
- `sale-off-shelf-contract.php`：12项通过。
- `r10-custom-card-projects-local.php`：28项通过。仅 Docker 本地 `mysql/ruihao`，显式环境开关保护；克隆临时商品/SKU，验证真实目录、行锁、仓库项目配置落库、分精度金额、次数、幂等重放、冲突拒绝和非法候选拒绝。外层事务回滚后再次核对商品、SKU、定制卡配置均不存在。
- PHP 7.4 四个修改业务文件语法通过；两个修改 Vue SFC 的 script/template 编译通过；`git diff --check` 通过。
- 浏览器真实页面：业务日 2026-10-11，新卡默认 2029-10-11；当前门店销售中53项、全部452项；仓库项目“乳腺疏通”可搜索、选择，切换销售中再全部仍保留4次/398元。测试后取消选择、还原临时填写；未确认创建卡片或结账。
- 页面检查：CSS视口1624x1063、1120x800，以及当前窄浏览器尺寸；新增搜索/单选/已选操作区无相互重叠。窄屏保留既有横向工作台，不把此次改动扩大为全收银响应式重构。浏览器临时视口覆盖已清除，会员及来源恢复为原张体/A老客首护。
- 有界查询：本地门店11返回413项，5次约631-679ms；每次最多5001行哨兵，超过5000明确拒绝。EXPLAIN 使用商品 index_merge（估计1430行）与 SKU `idx_rh_attr_lookup` ref（每商品估计1行），无逐项目 SQL。
- 详细输出：`R10-custom-card-tests-2026-10-11.json`。宿主较新PHP运行旧依赖产生弃用警告，业务运行时语法验证使用Docker PHP7.4。

## 既存失败与限制

- `c2-sale-cart-contract.php` 当前42通过/2失败：SALE-CAT-07库存草稿禁用旧断言、SALE-CART-02数量重验旧断言。以 HEAD 中同三个目录类和旧测试在内存执行，结果同样42/2；未改写为通过。
- `catalog-custom-card-ui-contract.mjs` 当前及 HEAD 都失败于“优惠券须在原位置基础上左移30px”的旧断言。与本次定制卡两项无关，未擅自修改优惠券页面。
- 因此不能声称全仓回归全部通过。新增专项、上述通过的回归与真实回滚配置验证均通过；尚未进行真实收款/签发验证，不能冒充产品经理业务验收。

## 数据架构核对

- 权威源：本店商品及 SKU 权威行，不信任前端传入的门店、销售状态或价格资格。
- 事件/粒度：一次定制卡配置及其项目组件；不新增订单、库存或支付事实。
- 时点：目录只读，创建时重新锁定行检查资格；已创建配置仍使用既有不可变快照。
- 事务/并发：复用 Gateway 事务与商品/SKU权威锁；两种配置入口收敛到同一组件资格校验，不保留直售/草稿的旧组件分支。
- 幂等/金额：保留既有配置指纹、幂等键及整数分计算；重放不重复写，变更内容不能复用键。
- 冲销/历史：不改历史卡、冲销或核销策略；不修改过去的配置快照。
- 权限：候选及写入继续限制本店，仓库资格仅限定制卡组件，不放开普通直售。
- 性能：有界查询、批量分类映射，无逐项目查库；实际时延与 EXPLAIN 如上。
- 迁移/对账：无数据库结构或历史数据迁移；本地测试全量回滚并核对，未连接远程实例。

## 文件分组

前端源码（同时补充日期、状态保留、独立目录与布局约束注释）：

- `前端代码/cashier-v3/src/components/cashier/CashierGuidedBusinessPanel.vue`
- `前端代码/cashier-v3/src/views/CashierWorkbenchView.vue`
- `前端代码/cashier-v3/src/styles/base.css`

后端源码（补充候选、门店范围、最终行锁、组件用途和提交入口统一注释）：

- `后端代码/app/services/cashier/v3/cashier/CashierV3SaleCatalogAuthority.php`
- `后端代码/app/services/cashier/v3/cashier/ThinkPhpCashierV3SaleCatalogAuthority.php`
- `后端代码/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php`
- `后端代码/app/services/cashier/v3/card/CashierV3CustomCardConfigurationServices.php`

自动化测试：

- `tests/cashier-v3/js/r10-custom-card-options.mjs`
- `tests/cashier-v3/php/r10-custom-card-projects-local.php`
- `tests/cashier-v3/php/c2-sale-cart-contract.php`（适配新增接口方法）
- `tests/cashier-v3/php/custom-card-configuration-contract.php`（断言统一组件入口）

数据库迁移、工程脚本、8080构建产物：无。

验收证据：本记录、固定版本JSON、测试输出JSON，以及 `R10-custom-card-expiry-2026-10-11.png`、`R10-custom-card-expiry-desktop-2026-10-11.png`、`R10-custom-card-all-desktop-2026-10-11.png`、`R10-custom-card-all-compact-2026-10-11.png`。
