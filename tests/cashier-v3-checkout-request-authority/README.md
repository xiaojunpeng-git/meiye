# 收银 V3 结账请求来源权威永久测试

本目录独立验证 eventless checkout request 的生产持久化与来源权威，不激活 Gateway action。

- `run-static.sh`：SHA、源码边界、DataScoped/FOR UPDATE/CAS、只读 loader、允许来源 kind、无事件与 Outbox 写入的静态契约；有本机 PHP 时同时执行 PHP lint 和纯契约。
- `php/source-set-contract.php`：服务端可信来源的稳定排序、指纹、未知 kind、URL、重复、跨 tenant/store 拒绝。
- `php/mysql-integration.php`：真实 ThinkPHP repository 新增、无写重放、不同指纹冲突、CAS、子行/来源精确替换、来源漂移、DataScope、版本 bump、触发器失败整体回滚。
- `php/mysql-concurrency.php`：两个独立 PHP 进程并发使用同一创建幂等键，验证唯一 aggregate、一个首次写入和一个重放。
- `mysql56-matrix.sh`：MySQL 5.6.51 fresh/pre/apply/post、已有表门禁、部分 DDL、跨店坏数据，以及 PHP 7.4 集成/并发测试。

默认 `run-all.sh` 不启动 Docker。只有取得独占窗口后显式运行：

```bash
CHECKOUT_REQUEST_RUN_MYSQL56=1 bash tests/cashier-v3-checkout-request-authority/run-all.sh
```
