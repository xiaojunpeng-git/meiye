# Customer care query and command adapter tests

This suite protects the independent Phase B `customer-care.v1` projection and
adapter. It does not register cashier-v3 routes or handlers.

- `js/static-contract.mjs`: source boundary, DataScope and fail-closed gates.
- `php/contract.php`: pure projection, cursor, bucket and input-mapping contract.
- `php/mysql56-integration.php`: real ThinkPHP/Phase A command and query path.
- `sql-matrix.sh`: disposable MySQL 5.6.51 and PHP 7.4 runner.
- `run-all.sh`: focused entry point.

Generated evidence and temporary databases are not source and are never kept in
this directory.
