# 收银 V3 会员余额权威永久测试

不启动 Docker 的静态与 PHP 纯合同门禁：

```bash
CHECKOUT_BALANCE_SKIP_MYSQL56=1 bash tests/cashier-v3-member-balance/run-all.sh
```

完整 MySQL 5.6.51 迁移、触发器、真实原子流水、DataScope、幂等、并发和外层事务回滚矩阵：

```bash
bash tests/cashier-v3-member-balance/run-all.sh
```

完整矩阵会启动临时 Docker 容器；开发阶段未获得运行窗口时只执行静态门禁。
