# R60 OpenLux 本地切换自测（待产品经理验收）

- 基线 HEAD：`d87926d1067e31cc0a26eb9858f0519fad6abfa5`；八个源码/测试文件补丁 SHA-256：`1da46d96e4886f6da56caaa728fd2bbe77b5ef09893ad3163bd834ffc1c633f7`。本次仅修改本地实例；未 commit、未部署瑞昊、未 push。
- 本地实例核对：MySQL 主机 `mysql`、库 `ruihao`；切换后公开配置显示 `provider=openlux`、`enabled=true`、模型 `qwen3-next-80b-a3b-instruct`。API Key 只通过标准输入保存到本实例加密配置，未写入源码、命令参数或本记录。本地 `.env`（Git 忽略）只保存供应商代号。
- OpenLux 官方兼容接口：`POST https://api.openlux.ai/v1/chat/completions`。服务端固定目标，不接受管理员提供的 URL；旧供应商密钥及授权在切换后不沿用。设置页已显示实际供应商名称。
- 配置的结构化响应探针：通过，返回有效 JSON 与用量信息。
- 真实语义问题：“上个月消费有500元以上的会员有多少人”。本地授权实例返回 `COMPLETED`，实际调用模型并查询真实事实数据；服务端完整答案 5,543 ms，模型 3,550 ms、统一查询 185 ms；未修改业务记录。另用“今天现金业绩是多少”核对快速路径，`COMPLETED`，1,227 ms；该问题未调用模型，不能单独作为供应商联通证据。
- 离线回归：`config-recovery.php` 8 项通过，`gateway-components.php` 144 项通过，`config-contract.php` 129 项通过，`runtime-contract.php` 105 项通过，`gateway-review-regressions.php` 85 项通过；变更文件 PHP 语法与 `git diff --check` 通过。平台端 18081 重新编译后设置页显示 OpenLux、正确模型及已开启的授权。
- 额外运行的 `gateway-integration.php` 在 Q001 “现金业绩为323元”断言处失败；该夹具使用注入模型，失败处不经过本次供应商 HTTP 入口。本次未将该旧范围断言算作通过，也未修改其业务口径。
- 瑞昊线上只读预检（2026-10-07）：目标站点 `/www/wwwroot/rh.cc3798.com/`，独立库 `ruihao`，服务端历史 Git 标识 `3254ecd` 仅作定位，`AiGatewayServices.php` 等五个目标文件指纹仍为旧版；线上模型为 `Qwen/Qwen2.5-72B-Instruct`、配置版本 7，尚无 `MODEL_PROVIDER` 设置。AI 活动任务 0，最近迁移键 `20261006-ruihao-mixed-sale-project-service-repair`；独立备份根目录可用。线上未改动。正式部署前仍须对确切目标文件与本实例配置另做独立备份和回滚清单。

变更文件 SHA-256（源码、测试，均未提交）：

```text
1b92afb85e95ddf83f4724f2d5a18d8ef264b75e18a1bba4ebd87880768183c9  后端代码/config/mohe_ai.php
675c36b081a37670c721af807867820b48866622cccc9ff6990718c417073003  后端代码/app/services/ai/config/AiConfigStore.php
2eb1dff747832b392a506113db91f54c17a99202aec83dd18f2a8e2433668302  后端代码/app/services/ai/execution/AiRuntimeFactory.php
655c7cfe982daa3a7804c4aa0c04167253f330fbe70740d272b6a0248ae5efc5  后端代码/app/services/ai/model/SiliconFlowClient.php
6e2477ec19c8c1e12597ce9ecd6675a94db38ffc039c44306fb058d9661e63ee  后端代码/app/services/ai/AiGatewayServices.php
cc5087187f5df64039d3c74faa445e72947c94d602bc5dda5c6cf555b0d8e1ce  前端代码/admin/src/pages/setting/moheAi/index.vue
b1d8d9b684a3bde689180d49b6dc7297e7e258737e3f1cac3e05bf0c81dd9f8b  tests/mohe-ai/config-recovery.php
5cc6ca2c20618792bb9a0c30b1d95e87c0275c18aaa1867cc8ed816c860e8ded  tests/mohe-ai/gateway-components.php
```

本次代码职责注释更新：`mohe_ai.php`、`AiConfigStore.php`、`AiRuntimeFactory.php`、`SiliconFlowClient.php`、`index.vue`；测试注释更新于两份配置与传输夹具。已扫描全部模型客户端构造调用并接入同一服务端供应商选择，未保留第二套平行请求路径。
