# R49 登录失效补修：瑞昊后端部署

2026-09-27，用户明确授权“部署瑞昊，push”。

## 范围与回滚

- 固定代码提交 `8d048450`，小程序 0.15.6 上传记录提交 `9ded9efa`。
- 目标：`rh-server`，`/www/wwwroot/rh.cc3798.com`，数据库实例 `ruihao`。
- 只部署 `app/controller/ai/AiHttpActions.php`，从固定 Git 提交生成归档；未同步整个脏工作区。
- 发布前线上文件 SHA-256 与补修前提交一致：
  `afc7d6ef240a5707e8f3e0672c11060664fc12391b4949c5a7754fb249d0f822`。
- 未执行数据库升级、配置更改或业务数据写入；沿用 R49 发布登记的迁移版本
  `20260924-001-cashier-v3-checkout-reservation-service-link-v1` / `302f841c`，本次未再次查询生产迁移表。
- 备份：`/www/backups/rh.cc3798.com/20260927-r49-login-8d048450/backend-before.tar.gz`。
  SHA-256 `32c5b038d5f5735aaaaf3927f705e07638ebe8843553e71d7d81cbadeaa395dc`。
- 发布包：`/tmp/mohe-ai-r49-login-8d048450.tar.gz`，SHA-256
  `495ec1d901b2aaee60cc0c253aa83b3768f8740d2ad68b810552dd5b029c5e33`。
- 临时解包、PHP 7.4 语法检查通过后，保留原文件属主及权限、原子替换目标文件。
- 回滚：在该站点根目录恢复备份中的唯一文件，再重启相同服务；无需回滚数据库。
- 重启仅限瑞昊 Swoole 与三个魔核 AI 服务；其他实例和其他任务代码未触碰。

## 验证

- 部署前复跑：10 个手机状态/传输场景，41 项框架路由，21 项 HTTP 断言通过。
- 部署后文件 SHA-256：`147ee5d3aa3cb230d140b565a2851d9738606c6a53d3889065798fac469218ca`，与提交一致。
- Swoole `manager_count=1`；execution@1、export@1、supervisor 均 active。
- 线上 GET `/api/mobile/merchant/ai/bootstrap` 和 POST `/api/mobile/merchant/ai/runs`：
  使用专门的无效测试凭据，均返回 HTTP 401、`MERCHANT_SESSION_EXPIRED`、
  `sessionEndCause=SESSION_TIMEOUT`，保留请求标识；没有使任何真实用户会话失效。
- 平台 `/admin/setting/mohe-ai` 返回 HTTP 200。
- 本次为接口与运行服务冒烟，不宣称新增实体手机验收；0.15.6 已上传，公众平台设为体验版仍待确认。

## 推送边界

使用现有 AI 专用分支 `origin/release/mohe-ai-r46-20260927`，推送前远端为
`223dcc4acc61833318104ecee54396b1eb5b35bb`。
通过临时 Git 索引合入本次魔核 AI 补修、测试和发布记录，逐文件校验与本地固定版本一致，
不切换工作区、不建立第二套业务源码、不推送混合业务开发分支中的其他任务提交。
实际推送结果以本次命令回执和任务最终回复为准。
