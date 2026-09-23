# 第 35 轮魔核 AI 瑞昊发布与线上验收

## 授权、版本和边界

- 产品经理已确认第 35 轮本地验收，并明确要求将本轮内容 commit、部署瑞昊、线上验证后 push。
- 本轮此前的提速闭环提交为 `e5837178ba17df6c87750ffdd9423282322da872`；跨期对比补充闭环提交为 `d0d9c4f1`。实际发布文件均从 `d0d9c4f1` 的 Git 归档提取，没有从脏工作区同步业务代码。
- 目标仅为 `rh.cc3798.com`、数据库 `ruihao`。未发布平台、门店或小程序前端；未触碰其他客户实例。服务器目录只保留运行结果，不作为源码或提交来源。

## 发布前核对与回滚点

- 瑞昊服务器、站点路径、独立数据库和 AI Worker 均与实例治理登记一致。发布前 `AiGatewayServices.php` SHA-256 为 `2598755a5b5b0b16d6b5ec3919b21500863dd1616c90b744a9e49ce843512fd5`，与 R34 线上记录相符；本轮 R35 后端未在瑞昊运行。
- 发布前会员 144745 行；`idx_user_real_name`、`idx_user_nickname` 均不存在。目标为 MySQL 5.7.44/InnoDB，站点所在磁盘发布前有约 227 GB 可用。
- 独立备份目录：`/www/backups/rh.cc3798.com/20260923-r35-czpyzKpZ/`。
  - 6 个被覆盖的后端文件：`backend-before.tar.gz`，SHA-256 `e1a3ce8cac590bc53505f51656a5e5fe463f630016ab12b27e5827e3ac1c0de3`。
  - 完整 `ruihao` 数据库：`ruihao-before.sql.gz`，SHA-256 `03c5850bbc8ed1ece89debca8a5fcdaaff33dae1d91e07de3edee6371c716da5`；`gzip -t` 通过。
- 代码异常时先恢复这 6 个精确文件并重启 Swoole、AI Worker、Supervisor；索引本身不改变会员数据，必要时按迁移清单单独回滚。若数据库出现非预期变更，再使用独立数据库备份恢复，不能用其他客户库替代。

## 迁移与代码发布

- 执行已提交的 `2026-09-23-魔核AI会员名称检索索引/02-正式升级.sql`，SHA-256 `2ee41b84696e38ac3ad5fa633de120e8f82706507e7f3916843d35257c1fa8f7`。后检：两个单列 BTREE 索引均存在，会员行数仍为 144745；`eb_database_upgrade_log` 登记键 `20260923-001-mohe-ai-member-name-index`，代码版本 `d0d9c4f1`。
- 仅同步以下后端文件；远端 PHP 语法检查与源文件 SHA-256 均通过：

| 文件 | 线上 SHA-256 |
| --- | --- |
| `app/services/ai/AiGatewayServices.php` | `867596a046c5155933a8e2e96f004f0b047a374a4852a22ac1d878b9a57b2ded` |
| `app/services/ai/contract/AiIntentUnderstandingContract.php` | `5593577fa1d5ebb6970cf9a933e995e543d65c43d0da277b91fe5f0718e613d4` |
| `app/services/ai/execution/AiRegisteredPlanCompiler.php` | `9e4d632c8b0ed6e6d4b34f7e67d925f63d68a32b72850c9e977974c1d0850e81` |
| `app/services/ai/presentation/AiAnswerRenderer.php` | `6ff305b7c9c05f6dbeb89b850941bd81ce9dfce8e2192aedaa737858e798a1fd` |
| `app/services/query/metric/MetricReadViewServices.php` | `e5a7c64fa290e3bf4abec1f8755c5c3499272204f45f3ebec29b75ade1046095` |
| `app/services/query/metric/MemberAnalysisObjectServices.php` | `023e602b0dfc7c67c17056df5cbcd2d171bd7fad6a58b38f7ba3232d2a427262` |

- Swoole 重启后 `manager_count=1`；`mohe-ai-execution-worker@1.service` 和 `mohe-ai-supervisor.service` 均为 `active`。

## 瑞昊同一真实工作台验收

1. 新对话 `9月17日现金业绩对比9月16日`：页面用时 2 秒，两个期间均为 0 元，增减 0 元、变化率“持平”，无错误的除零增长率。
2. 同一对话 `9月20日现金业绩和销售数量对比9月19日`：页面用时 2 秒；现金业绩 34,460/28,360 元、增减 +6,100 元、变化率 +21.5%；销售数量 11/8 件、增减 +3 件、变化率 +37.5%，未跨单位混算。
3. 同一对话 `9月20日经营情况对比9月19日`：页面用时 2 秒；10 项注册概览指标均展示本期、对比期、增减和变化率。示例：消耗业绩 10,839/11,988 元、-1,149 元、-9.6%；完成服务项目数量 110/127 项、-17 项、-13.4%。
4. 新对话 `这个月经营情况如何`：页面用时 19 秒，完整返回现金、退款、实际、消耗、销售额、实际收款销售额、销售数量、完成服务项目数量、余额扣款及充值本金和 `2026-09-01 至 2026-09-23` 期间；未退化为只答单一指标。

上述数值是瑞昊当前登录账号权限范围内的线上页面显示，未进行逐笔账务对账；浏览器对话记录保留，未删除。完整上月的事实覆盖不足，系统仍应要求显式确认截短范围，不能把它宣传为完整环比。
