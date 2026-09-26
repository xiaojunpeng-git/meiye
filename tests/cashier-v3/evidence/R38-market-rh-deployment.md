# R38 市场两表瑞昊发布

- 产品经理明确授权Commit、瑞昊部署、push；业务提交da5b0498。只部署StoreUnifiedReportPhaseTwoServices.php，取自固定Git提交，无前端重构/构建、无迁移、无业务写入或其他客户变更。
- 提交前源码/测试指纹与验收记录一致。线上数据库只读核实ruihao，迁移保持20260924-001-cashier-v3-checkout-reservation-service-link-v1 / 302f841c。
- 独立回滚文件：/www/backups/rh.cc3798.com/20260927-r38-market-da5b0498/before.php，SHA256 b4ae1db16f8816172eaefd60fedb9cc47743430276ab92272d6d922a5d64c5b0。恢复该文件至app/services/report/StoreUnifiedReportPhaseTwoServices.php并重启瑞昊Swoole可回滚，无数据回滚需要。
- 远端PHP7.4语法检查通过，原子替换后SHA256 d1cf319666b340ad1efdd80c159e064275ebcd6d4f01c9597f66e6805f111412。瑞昊Swoole重启后manager_count=1、PID23072、端口20800。未改变AI服务或配置。
- 线上真实只读报表验证：门店132、2026-09-26，会员李玉凤1025156显示1行、visits=1、amount=0、来源A老客首护。来源17汇总为6人次，与市场明细及导出完整结果人次合计一致。没有执行收银、作废、补充值或其他生产业务写入。
- 原工作区其他脏文件保留。仅将本批业务及记录等价重放到既有release/r38-20260927；线上验证通过后推送origin/github，具体远端指纹以工具回执为准。
