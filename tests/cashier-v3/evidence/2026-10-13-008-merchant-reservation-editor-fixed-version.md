# 008 商家端预约确认编辑器：本地固定版本与自测

状态：产品经理已明确验收本固定版本，并授权仅部署 `008.cc3798.com`；本地提交和 008 发布进行中，未推送。当前浏览器复测仍为发布前旧后端。

## 问题与修复

- 008 商家端预约单 `YY2610130002` 点击“确认”时，编辑器返回“卡内项目配置不完整，请联系管理员核对。”；预约仍为待确认，点击未提交确认命令。
- 原编辑器为取预约项目调用整店收银目录。收银目录会规范化所有在售卡的卡内组成；任意无关卡的有效关联缺失项目或 SKU，都会在预约项目筛选前抛错。
- 新增仅查询本店在售项目及有效 SKU 的预约目录端口，保留商品身份、门店、审核状态、SKU 和后端数据权限校验。已购项目仍由预约权益投影读取；收银目录和卡项校验不变。没有修改商家端页面。
- 这次消除了“无关卡项阻断预约编辑器”的代码耦合；尚未定位 008 异常卡关联，也不宣称该卡已修复。发布后复测同一预约的编辑器打开路径，不提交真实确认命令或消费会员权益。

## 固定版本

- 源码正本：`美容源码/`；验收时基线 HEAD：`c01694baa8a0b1872c1663ff4bb9e41d320b5588`。准备期间其他已验收闭环继续推进 HEAD，本闭环六文件内容和补丁指纹均未变化；本闭环实际提交父版本以提交记录为准。
- 仅下列六个文件构成本闭环。逐文件 SHA-256：

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/cashier/v3/cashier/CashierV3SaleCatalogAuthority.php` | `2070fa217c715292211762f8b38fbc64c35924d75bc8ffce618e8445082cf0cf` |
| 后端源码 | `后端代码/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php` | `1b4cf935e4ca05f456b4bb9b7ee823ec923009bfb59c2c5fa8c97ef8294dfe55` |
| 后端源码 | `后端代码/app/services/cashier/v3/cashier/ThinkPhpCashierV3SaleCatalogAuthority.php` | `db55015ad4d29d75340d6c7b449fdf8c752bd6e0f07051738a593a2506c6b01d` |
| 后端源码 | `后端代码/app/services/cashier/v3/reservation/CashierV3ReservationModule.php` | `36e9e0520d31978f0f2fa17f024cd704c62ae85d5a6a55eab4b863a7e544678e` |
| 自动化测试 | `tests/cashier-v3/php/c2-sale-cart-contract.php` | `d7380b92cfbe3950969138dd3d476598b4cb2871a55af0dfd666570320334ad3` |
| 自动化测试 | `tests/cashier-v3/php/reservation-project-catalog-isolation.php` | `b0cdfe0b857a0e9f76f93d1a611314358813fc97ff7bc65ebdd6610be6b4fbbc` |

- 上述五个已跟踪文件的 `git diff --binary` 加新增测试文件的 `git diff --no-index --binary /dev/null` 补丁 SHA-256：`546cd8f26b8e09eb59842057d4c05a93b16b32aef6810202a14e96c73c947a39`。
- 前端源码、数据库迁移、工程脚本、生成物：本闭环无。验收证据：本文件。未把其他任务的大量工作区改动纳入版本。

## 自测与数据架构自检

- `reservation-project-catalog-isolation.php`：PASS；模拟整店销售目录因坏卡不可用时，预约目录仍返回本店在售项目，隐藏项目不进入结果，无门店数据权限被拒绝。
- `reservation-entitlement-project-contract.php`：PASS；已购权益继续走现有预约权益投影，来源明细契约不变。
- 008 在线发布前重现：`POST /api/mobile/merchant/reservations/editor` HTTP 200，但业务结果为 `status=failed`、`code=COMMAND_RESULT_INCOMPLETE`、消息“卡内项目配置不完整，请联系管理员核对。”；预约仍待确认。该操作只打开编辑器，没有提交确认命令。
- 六个 PHP 文件语法检查通过，闭环文件 `git diff --check` 通过。
- 既有 `c2-sale-cart-contract.php` 在宿主机独立运行因缺少 ThinkPHP `think\\facade\\Db` 初始化而失败；仓库历史证据已记录该测试本身有同一基线限制，不计本轮通过。新增测试独立运行通过。
- 权威源仍为 `store_product`、SKU 和现有权益业务表；本次仅改变预约编辑器的只读候选投影，不写业务主表，不产生预约事件、事实或 Outbox，不改变统计时点、金额、余次、冲销或历史快照。确认命令的事务、并发、幂等及权益最终复核保持原路径。
- 后端强制门店和数据权限保持；客户端不能扩大范围。查询限定本店在售已审核项目，5001 行哨兵限制与 5000 项上限沿用现有目录量级；不扫描卡内组成。无数据库迁移或回填，对账范围是修复前后正常项目候选集合及线上预约编辑器打开路径。
- 本次新增或修改的业务接口、查询边界、预约目录调用均有职责和约束注释，涉及文件为上述四个后端源码文件；测试夹具说明同步更新。

## 交付边界

产品经理已在本对话明确确认以上六文件固定版本验收，并授权仅部署 008。发布只包含本次四个后端 PHP 文件；不打包商家端 H5，不执行数据库迁移，不触及其他实例。提交、备份、回滚点、运行进程和线上复测结果另行登记；不执行 `git push`。
