# 收银 V3 统一结账事实底座永久测试

静态与纯合同测试：

```bash
CHECKOUT_FACT_SKIP_MYSQL56=1 bash tests/cashier-v3-checkout-facts/run-all.sh
```

完整 MySQL 5.6.51 迁移、部分创表恢复、事务仓储、幂等重放、不可变冲突、数据权限和冲销集成测试：

```bash
bash tests/cashier-v3-checkout-facts/run-all.sh
```

完整套件会启动临时 Docker 容器；静态套件不会启动 Docker。本任务交付阶段只运行静态套件。
