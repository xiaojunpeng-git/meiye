# R19 历史卡项补交生美大单报表：瑞昊与渼约008发布记录

- 授权：产品经理确认本轮固定版本测试通过，并要求 commit、部署瑞昊和渼约008、push。
- 本轮代码提交：`f4fc173f69b21e4003cde5edc30780c6ff934bb6`，仅报表服务与只读回归测试；无前端代码、数据库迁移或业务数据写入。
- 两站报表文件上线前 SHA-256 均为 `2f62662d63f6d2791bd0e02fac1413cb54e20bff60803fd93ac561d377b04fa9`。只将该提交的报表补丁应用到现有线上文件，未整文件覆盖其他已上线改动；上线后两站文件 SHA-256 均为 `9d57b9e5f99b7efe81bff8bae9e577cdf0e314f44c3abeab0293885dae0bdf1b`，PHP 7.4 语法检查通过。

## 瑞昊

- 目标：`/www/wwwroot/rh.cc3798.com/app/services/report/StoreUnifiedReportPhaseTwoServices.php`。
- 独立回滚包：`/www/backups/rh.cc3798.com/R19-f4fc173f.1ajeXl/report-before.tar.gz`，SHA-256 `ab33d00d0a3856d9a4bd3701e53bd3905e6368881230fce6ed311971ed80dd85`；`gzip -t` 通过。
- 站点专用脚本重启后 Swoole manager_count=1。生产只读查询：`BJ26101400007` 在 2026-10-14 门店 133 的报表中分别生成销售人、销售经理记录，金额各 87200 分，生美二级分类均为“卡项”，指标版本 `beauty-large-order-repayment-v6`。
- 正式 `/view_cashier_v3/` 与预览 `/preview-8080/view_cashier_v3/` 入口均 HTTP 200。两处磁盘静态资源的 index / JS / CSS SHA-256 分别均为 `d28ce1949b06847622568f31c0c3d8045e22fd1762d5fd4b1440218e9328a17e` / `266bc4c0c104def8cca1ae8d5e2382f4e400fb36ecee8d9a27cad28b5866dd7e` / `84d39724ce944a3db85dba386c5de0ad2eedf32802a21b881747e0e710fbb395`；本轮未修改前端静态资源。

## 渼约008

- 目标：`/www/wwwroot/008.cc3798.com/app/services/report/StoreUnifiedReportPhaseTwoServices.php`。
- 独立回滚包：`/www/backups/008.cc3798.com/R19-f4fc173f.8HSMZd/report-before.tar.gz`，SHA-256 `28a0352fe5b3b4ce895f5cd8f6ca8b11337312fb4ba0062d59c1fec4b4c07612`；`gzip -t` 通过。
- 仅重启 `mohe-008-swoole.service`，状态 active。对本实例门店 1 的生产只读报表查询正常返回指标版本 `beauty-large-order-repayment-v6`；所查日期无样本，不把瑞昊业务数据当作008验证结果。正式门店入口 HTTP 200，index / JS / CSS 指纹与上列一致且未修改。

## 推送与回滚

- Gitee `release/store-cashier-c14-main` 与本地历史分叉，未强推或覆盖。已将固定提交推至 `release/r19-beauty-repayment-20261014`；主发布分支仍待独立集成。
- 如需回滚，先确认目标实例文件未被后续版本覆盖，再仅恢复该实例回滚包中的报表文件并重启其独立 Swoole；不回滚数据库、不触碰其他实例或前端。
