# 第 6 轮 I：会员多条件详情与历史 Excel 入口，本地固定验收版

- 基线 HEAD：`221c9fc80d15e4c2a46a4fbdb53c68ef297a17b0`。
- 固定文件内容指纹：`0519d66018957ffb5c46e2bc3da4f822a64da87bcf452c9ac66f11b1cb4aa710`，按下列五个文件的路径、零字节、内容、零字节顺序计算 SHA-256。
- 业务范围：多条件会员名单每位会员占一行，已请求的条件指标各占一列并标明单位及独立时间范围；页面最多预览 20 位会员，完整已验证名单进入同一个回答的 Excel。重新打开历史会话时，通过原 Run 的交付凭证恢复对应 Excel 任务状态；未申请任务时显示“生成 Excel”，已完成时显示“下载 Excel”。历史打开不自行创建新任务。

## 问题与修复

1. 原 `condition_list` 把一位会员的每项条件拆成一行，504 人、2 条条件的页面 20 行只展示约 10 人，且表头为“会员／指标／数值／单位”。现按授权对象汇成一行，列名来自已注册指标及其期间，不由浏览器另算业务数值；数目上限按会员行计算。
2. 原实时回答可启动独立 Excel，但历史会话只恢复答案文本和表格，没有恢复文件任务。现从原回答的 `run_id`、`generation`、`client_session_id` 和交付令牌查询服务器状态，不从本地答案文本推定下载权限。交付令牌与本地会话一起按原 24 小时期限保存；无凭证的旧历史答复明确提示重新提问。
3. 原已约定的前 N 人多指标完整文件选项保留；导出可见表与页面共用后端渲染口径，精确值审计表仍保留原始分精度记录。

## 文件分组

- 前端源码：`前端代码/shared/mohe-ai/browser-entry.mjs`（`4e84e98126a9228c038d7216757ca7fa91c8d53a39b36129b2fb4f812a9c29d6`）；`前端代码/shared/mohe-ai/device-session.mjs`（`55aa5711b382c3981db2ffbb155d5dc9cae75f935c8859b6c7082080ece6f56d`）。
- 后端源码：`后端代码/app/services/ai/presentation/AiAnswerRenderer.php`（`6a06ec1678a27de20c9f3cfe516bc04ccd470696ccac5281621e2a81bcb89945`）。
- 自动化测试：`tests/mohe-ai/condition-set.php`（`a01f3592fd8c6043a2cb1dcf36eb26a88540a5bd15818d196dfebba8fbef43a6`）；`tests/mohe-ai/browser-history-export-contract.mjs`（`647a81f3c303d6ae055969bf457b910793e9cd2da663b63395d152634b79c677`）。
- 数据库迁移、工程脚本、生成物：无。本文件为验收证据，不是业务源码。

## 自测

- `php tests/mohe-ai/condition-set.php`：170 项通过；合成 504 人时，页面 20 位、可见工作表 504 位、精确值审计表 1008 条指标记录。
- `node tests/mohe-ai/browser-history-export-contract.mjs`：通过；历史打开只查状态、不自动创建任务，使用原会话及令牌，手动生成后能恢复下载入口。
- `node tests/mohe-ai/browser-entry-contract.mjs`、`node tests/mohe-ai/browser-independent-export-contract.mjs`：通过。
- `php tests/mohe-ai/gateway-integration.php`：183 项通过；`php tests/mohe-ai/export-runtime-contract.php`：路由、状态、签名和真实 XLSX 合同均通过。
- 两个前端文件 `node --check` 通过；后端文件在本机与本地 PHP 7.4 容器内 `php -l` 通过；目标文件 `git diff --check` 通过。
- 本地 18081 工作台重新打开用户标注的历史会话：旧回答仍保留原始逐指标快照，已成功恢复“Excel 已生成／下载 Excel”入口并点击下载按钮。内置浏览器未提供可核验的落盘文件，故不声称浏览器文件保存已验证。
- 本地 AI 执行容器重载源码后，在同一工作台实问“5天没有到店且余额在500以上的会员详情”：17 秒答出 504 人；“会员筛选依据”表为“会员／距最近到店天数（天）／会员账户余额（元）”，恰好预览 20 位会员而非 10 位。该回答的独立 Excel 随后显示“Excel 已生成／下载 Excel”。另有既有的 504 人“会员权益汇总”表，两张表各预览 20 位；本次没有改变权益明细口径。

## 边界与状态

- 历史问答是不可变快照；旧答案的原表格不会回写成新列式呈现。新问答已经在本地真人页面复核；物理文件是否在内置浏览器落盘仍未验证。504 人的完整行数由后端合同及导出投影验证。
- 本次未连接远程实例、未修改生产数据、未部署、未 push。工作区有大量其他任务变更及既有暂存项；本闭环提交时只能逐文件隔离暂存以上目标，不得夹带。
- 本次修改的渲染分支、历史交付凭证和文件恢复边界均补充或更新职责注释；无被完整替代且经零引用确认可删的旧业务入口。
- 开发与自测完成，待产品经理确认此固定版本验收；确认前不提交。
