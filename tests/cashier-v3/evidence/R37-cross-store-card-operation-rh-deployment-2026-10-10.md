# R37 跨店卡操作瑞昊发布记录

- 授权与验收：产品经理确认第 37 轮本地测试无问题，并明确授权 commit、部署瑞昊、push。
- 功能提交：`c3b4c0f3`（`R37-00001 跨店卡操作与组织员工审计闭环`）。提交仅包含卡操作权限服务和对应集成测试，未夹带工作区其他任务改动。
- 业务范围：账号具有对应卡操作功能权限时，允许在当前门店操作跨店会员卡；卡停用、启用、延期、转卡、升级及项目更换等操作继续逐项校验功能权限、卡状态、持有人、历史订单和并发版本。组织员工账号缺少旧门店员工记录时，审计姓名由受信任的组织员工资料或服务端登录资料补齐，不读取客户端姓名。

## 自测与交付范围

- 后端源码：`后端代码/app/services/cashier/v3/card/CashierV3CardOperationAuthorityServices.php`。
- 自动化测试：`tests/cashier-v3/php/card-operation-integration.php`。
- 前端源码、数据库迁移、配置、工程脚本和生成物：无。
- 两份 PHP 语法检查、`git diff --check`、卡操作内核合同 `16/0`、前端桥接合同均通过。产品经理已完成本地页面测试并确认无问题。
- 本轮修改的权限、跨店策略、审计快照和测试夹具均补充或更新了业务边界注释。

## 瑞昊部署

- 目标仅为瑞昊 `rh.cc3798.com`，主机 `iZbp1fif4p5s7gj96nnec0Z`，站点 `/www/wwwroot/rh.cc3798.com`，独立数据库只读核对为 `ruihao`，MySQL `5.7.44-log`。其他客户实例未连接、未部署。
- 发布前 PHP 为 `7.4.33`，磁盘余量约 203 GB，Swoole `manager_count=1`、20800 监听正常。线上旧文件 SHA-256 为 `958bc74ecde5ba6f660f5e9e552db8b68370a64798e204f2f15b2eb061b777f8`。
- 候选仅从固定提交 `c3b4c0f3` 的 Git 归档提取，未使用脏工作区。候选 SHA-256 为 `63123b6ada4e1832c57eed0906a054391ca2d0463c96d5a0ffd8c4d082c327a7`，经服务器 PHP 7.4 语法检查后原子替换唯一目标服务文件。
- 独立备份目录：`/www/backups/rh.cc3798.com/20261010-012242-r37-c3b4c0f3/`。旧文件备份校验通过；回滚时恢复 `CashierV3CardOperationAuthorityServices.before.php` 到原路径并重启 `/etc/init.d/ruihao_swoole`，无需数据库回滚。
- 本次没有数据库迁移、生产数据写入、前端构建或静态资源切换；仅重启瑞昊 Swoole，未重启其他客户服务或 AI 服务。

## 线上核验

- 运行文件 SHA-256 与提交候选一致，属主与权限保持 `www:www`、`0644`，线上 PHP 语法检查通过。
- 瑞昊 Swoole 重启后 `manager_count=1`、PID `19740`，20800 正常监听；AI Supervisor 保持 active。
- 正式 `/view_cashier_v3/`、预览 `/preview-8080/view_cashier_v3/` 和匿名收银配置接口均返回 HTTP 200；匿名接口返回业务状态 `410000`、消息“请登录”，未绕过认证。
- 日志尾部存在本次发布前的 AI 协程历史异常；最新 Swoole 启动标记之后 `Fatal error`、`Parse error`、`Uncaught` 计数为 0，与本轮卡操作文件无关。
- 未在线上执行真实停卡、启卡或其他卡操作写入。完整业务路径依据本地自动化回归和产品经理页面验收；线上验证限于固定文件、服务运行态、认证边界和公开入口健康。
