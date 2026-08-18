#!/usr/bin/env bash
# Isolated MySQL 5.6 focused gate for direct V3 card operations.
# This runner is intentionally separate from the shared run-all.sh.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
TEST_DIR="$ROOT_DIR/tests/cashier-v3"
BACKEND_DIR="$ROOT_DIR/后端代码"
UPGRADE_DIR="$BACKEND_DIR/database/upgrades"
BASE_UPGRADE="$UPGRADE_DIR/2026-07-27-收银V3命令与幂等底座"
EVENT_UPGRADE="$UPGRADE_DIR/2026-07-28-收银V3统一事件与Outbox"
C5_UPGRADE="$UPGRADE_DIR/2026-07-28-C5会员建档一致性"
ENTITLEMENT_UPGRADE="$UPGRADE_DIR/2026-07-28-收银V3权益购物车草稿"
CHECKOUT_SETTLEMENT_UPGRADE="$UPGRADE_DIR/2026-07-29-收银V3结账请求与收款明细"
CHECKOUT_SOURCE_UPGRADE="$UPGRADE_DIR/2026-07-29-收银V3结账请求来源权威"
SALE_CART_UPGRADE="$UPGRADE_DIR/2026-07-29-收银V3销售购物车权威行"
CHECKOUT_FACT_UPGRADE="$UPGRADE_DIR/2026-07-29-收银V3统一结账事实底座"
DEBT_REPAYMENT_UPGRADE="$UPGRADE_DIR/2026-08-01-收银V3欠款还款权威"
CHECKOUT_RESOURCE_PLAN_UPGRADE="$UPGRADE_DIR/2026-07-29-收银V3结账资源预锁计划"
SALES_ORDER_UPGRADE="$UPGRADE_DIR/2026-07-29-收银V3正式销售订单权威"
PAYMENT_COLLECTION_UPGRADE="$UPGRADE_DIR/2026-07-29-收银V3正式收款权威"
ENTITLEMENT_COMPLETION_UPGRADE="$UPGRADE_DIR/2026-07-29-收银V3权益完成权威写入"
HANG_ORDER_AUTHORITY_UPGRADE="$UPGRADE_DIR/2026-07-29-收银V3正式挂单权威"
HANG_RESUME_CHECKOUT_UPGRADE="$UPGRADE_DIR/2026-07-30-收银V3普通商品挂单恢复结账"
SALESPERSON_DRAFT_UPGRADE="$UPGRADE_DIR/2026-07-31-收银V3销售人分配草稿"
CARD_UPGRADE="$UPGRADE_DIR/2026-07-30-收银V3卡操作权威"
CARD_OPERATION_SETTLEMENT_UPGRADE="$UPGRADE_DIR/2026-08-11-收银V3卡操作权益余额结算"
CARD_ISSUANCE_UPGRADE="$UPGRADE_DIR/2026-07-30-收银V3卡项权益签发"
ISSUED_CARD_RULE_UPGRADE="$UPGRADE_DIR/2026-08-03-收银V3新卡项规则签发状态"
RECHARGE_DEBT_UPGRADE="$UPGRADE_DIR/2026-08-02-收银V3充值欠款权威"
RECHARGE_GIFT_UPGRADE="$UPGRADE_DIR/2026-08-02-收银V3充值赠送权威"
DOCUMENT_NUMBER_UPGRADE="$UPGRADE_DIR/2026-08-03-收银V3业务单号统一"
BUSINESS_SOURCE_UPGRADE="$UPGRADE_DIR/2026-08-06-收银V3来源与记账配置"
REPORT_CHANNEL_UPGRADE="$UPGRADE_DIR/2026-08-10-报表渠道事实快照"
CUSTOMER_LIFECYCLE_UPGRADE="$UPGRADE_DIR/2026-08-10-顾客生命周期与首次疗程归因"
MORE_ACTIONS_UPGRADE="$UPGRADE_DIR/2026-08-05-收银V3更多操作权威"
RESUMED_HANG_REFERENCE_UPGRADE="$UPGRADE_DIR/2026-08-10-收银V3提单结账内部引用"
LINE_COUPON_UPGRADE="$UPGRADE_DIR/2026-08-11-收银V3销售行优惠券快照"
MYSQL_IMAGE="${CARD_OPERATION_MYSQL_IMAGE:-docker.m.daocloud.io/library/mysql:5.6.51}"
PHP_DOCKERFILE="$TEST_DIR/docker/php74-runtime.Dockerfile"
PHP_PLATFORM="${CARD_OPERATION_PHP_PLATFORM:-}"
PHP_IMAGE="${CARD_OPERATION_PHP_IMAGE:-}"
PHP_MEMORY="${CARD_OPERATION_PHP_MEMORY:-384m}"
NETWORK="card-operation-net-$$"
MYSQL_CONTAINER="card-operation-mysql56-$$"
PHP_CONTAINER="card-operation-php74-$$"
# Docker Desktop cannot always mount macOS per-user TMPDIR paths (for example
# /var/folders/...), leaving /test-env empty.  Keep this disposable directory
# directly below the checked-out source root, which is already a shared mount.
WORK_DIR="$(mktemp -d "$ROOT_DIR/.card-operation.XXXXXX")"
TEMP_ENV="$WORK_DIR/card-operation.env"
DB_NAME="cashier_v3_card_operation"

cleanup() {
  local rc=$?
  if [[ "$rc" -ne 0 && "${CARD_OPERATION_DEBUG_MYSQL_LOGS:-0}" == "1" ]]; then
    docker logs "$MYSQL_CONTAINER" >&2 || true
  fi
  docker rm -fv "$PHP_CONTAINER" "$MYSQL_CONTAINER" >/dev/null 2>&1 || true
  docker network rm "$NETWORK" >/dev/null 2>&1 || true
  rm -rf "$WORK_DIR"
}
trap cleanup EXIT INT TERM

if [[ "$PHP_PLATFORM" == "" ]]; then
  case "$(docker version --format '{{.Server.Arch}}')" in
    amd64|x86_64) PHP_PLATFORM='linux/amd64' ;;
    arm64|aarch64) PHP_PLATFORM='linux/arm64' ;;
    *) echo 'CARD_OPERATION_UNSUPPORTED_DOCKER_ARCH' >&2; exit 1 ;;
  esac
fi
if [[ "$PHP_IMAGE" == "" ]]; then
  PHP_IMAGE_SHA="$(shasum -a 256 "$PHP_DOCKERFILE" | awk '{print substr($1,1,12)}')"
  PHP_IMAGE="cashier-v3-card-operation-php74:${PHP_IMAGE_SHA}-${PHP_PLATFORM#linux/}"
  if ! docker image inspect "$PHP_IMAGE" >/dev/null 2>&1; then
    docker build --platform "$PHP_PLATFORM" -f "$PHP_DOCKERFILE" \
      -t "$PHP_IMAGE" "$TEST_DIR/docker"
  fi
fi

(cd "$CARD_UPGRADE" && shasum -a 256 -c SHA256SUMS.txt)
(cd "$CARD_ISSUANCE_UPGRADE" && shasum -a 256 -c SHA256SUMS.txt)
(cd "$ISSUED_CARD_RULE_UPGRADE" && shasum -a 256 -c SHA256SUMS.txt)
(cd "$CHECKOUT_SETTLEMENT_UPGRADE" && shasum -a 256 -c SHA256SUMS.txt)
(cd "$CHECKOUT_SOURCE_UPGRADE" && shasum -a 256 -c SHA256SUMS.txt)
(cd "$SALE_CART_UPGRADE" && shasum -a 256 -c SHA256SUMS.txt)
(cd "$CHECKOUT_FACT_UPGRADE" && shasum -a 256 -c SHA256SUMS.txt)
(cd "$DEBT_REPAYMENT_UPGRADE" && shasum -a 256 -c SHA256SUMS.txt)
(cd "$CHECKOUT_RESOURCE_PLAN_UPGRADE" && shasum -a 256 -c SHA256SUMS.txt)
(cd "$SALES_ORDER_UPGRADE" && shasum -a 256 -c SHA256SUMS.txt)
(cd "$PAYMENT_COLLECTION_UPGRADE" && shasum -a 256 -c SHA256SUMS.txt)
(cd "$ENTITLEMENT_COMPLETION_UPGRADE" && shasum -a 256 -c SHA256SUMS.txt)
(cd "$HANG_ORDER_AUTHORITY_UPGRADE" && shasum -a 256 -c SHA256SUMS.txt)
(cd "$HANG_RESUME_CHECKOUT_UPGRADE" && shasum -a 256 -c SHA256SUMS.txt)
(cd "$SALESPERSON_DRAFT_UPGRADE" && shasum -a 256 -c SHA256SUMS.txt)
(cd "$RECHARGE_DEBT_UPGRADE" && shasum -a 256 -c SHA256SUMS.txt)
(cd "$RECHARGE_GIFT_UPGRADE" && shasum -a 256 -c SHA256SUMS.txt)
(cd "$DOCUMENT_NUMBER_UPGRADE" && shasum -a 256 -c SHA256SUMS.txt)
(cd "$BUSINESS_SOURCE_UPGRADE" && shasum -a 256 -c SHA256SUMS.txt)
(cd "$CUSTOMER_LIFECYCLE_UPGRADE" && shasum -a 256 -c SHA256SUMS.txt)

printf '%s\n' \
  'APP_DEBUG = true' \
  "HOSTNAME = $MYSQL_CONTAINER" \
  "DATABASE = $DB_NAME" \
  'USERNAME = root' \
  'PASSWORD = ' \
  'HOSTPORT = 3306' \
  'DRIVER = file' \
  'CACHE_DRIVER = file' > "$TEMP_ENV"

docker network create "$NETWORK" >/dev/null
docker run -d --name "$MYSQL_CONTAINER" --network "$NETWORK" --platform linux/amd64 \
  --cpus 1 --memory 1g -e MYSQL_ALLOW_EMPTY_PASSWORD=yes "$MYSQL_IMAGE" \
  --character-set-server=utf8mb4 --collation-server=utf8mb4_general_ci \
  --sql-mode=STRICT_ALL_TABLES --innodb-large-prefix=0 --innodb-file-format=Antelope >/dev/null

for _ in $(seq 1 90); do
  # mysqladmin without -h can succeed against the temporary bootstrap
  # server's Unix socket before the final TCP listener is available.
  docker exec "$MYSQL_CONTAINER" mysql -h "$MYSQL_CONTAINER" -uroot -e 'SELECT 1' >/dev/null 2>&1 && break
  sleep 1
done
docker exec "$MYSQL_CONTAINER" mysql -h "$MYSQL_CONTAINER" -uroot -e 'SELECT 1' >/dev/null
MYSQL_VERSION="$(docker exec "$MYSQL_CONTAINER" mysql -uroot -Nse 'SELECT VERSION()')"
[[ "$MYSQL_VERSION" == '5.6.51' ]]
echo "MYSQL_VERSION=$MYSQL_VERSION"

mysql_file() {
  local file="$1"
  docker exec -i "$MYSQL_CONTAINER" mysql -uroot --database="$DB_NAME" < "$file"
}

mysql_expect() {
  local file="$1" expected="$2" output
  if ! output="$(mysql_file "$file")"; then
    printf 'MYSQL_EXECUTION_FAILED: %s\n%s\n' "$file" "$output" >&2
    return 1
  fi
  if ! grep -q "$expected" <<< "$output"; then
    printf 'MYSQL_EXPECTATION_FAILED: %s expected %s\n%s\n' "$file" "$expected" "$output" >&2
    return 1
  fi
}

register_upgrade() {
  local key="$1" title="$2" file="$3"
  local checksum
  checksum="$(shasum -a 256 "$file" | awk '{print $1}')"
  docker exec "$MYSQL_CONTAINER" mysql -uroot --database="$DB_NAME" -e "
    INSERT INTO eb_database_upgrade_log
      (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note)
    VALUES
      ('$key','$title','02-正式升级.sql','$checksum','card-operation-focused',NOW(),'codex-test','isolated dependency');"
}

start_php_env() {
docker run -d --name "$PHP_CONTAINER" --network "$NETWORK" --platform "$PHP_PLATFORM" \
    --cpus 1 --memory "$PHP_MEMORY" \
    -e DB_HOST="$MYSQL_CONTAINER" -e DB_PORT=3306 -e DB_DATABASE="$DB_NAME" \
    -e HOSTNAME="$MYSQL_CONTAINER" -e HOSTPORT=3306 -e DATABASE="$DB_NAME" \
    -e USERNAME=root -e PASSWORD= \
    -e DB_USERNAME=root -e DB_PASSWORD= -e CACHE_DRIVER=file -e DRIVER=file \
    -v "$BACKEND_DIR:/source:ro" -v "$ROOT_DIR/tests:/tests:ro" \
    -v "$WORK_DIR:/test-env:ro" --tmpfs /var/www/html:rw,size=384m \
    --entrypoint sh "$PHP_IMAGE" -lc "
      set -e
      tar -C /source --exclude='./.env' --exclude='./.env.*' \\
        --exclude='./runtime' --exclude='./public' --exclude='./route/api-mobile.php' \\
        -cf - . | tar -C /var/www/html -xf -
      mkdir -p /var/www/html/runtime /var/www/html/public/uploads
      cp /test-env/card-operation.env /var/www/html/.env
      touch /var/www/html/.card-operation-ready
      exec tail -f /dev/null
    "
}

run_php() {
  local command="$1"
  docker exec "$PHP_CONTAINER" sh -lc "
    set -e
    cd /var/www/html
    $command
  "
}

docker exec "$MYSQL_CONTAINER" mysql -uroot -e \
  "CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
mysql_file "$UPGRADE_DIR/0000-升级登记表初始化.sql"
mysql_file "$BASE_UPGRADE/02-正式升级.sql"
register_upgrade '20260727-001-cashier-v3-command-idem' 'card operation focused dependency' "$BASE_UPGRADE/02-正式升级.sql"

start_php_env
for _ in $(seq 1 90); do
  docker exec "$PHP_CONTAINER" test -f /var/www/html/.card-operation-ready >/dev/null 2>&1 && break
  sleep 1
done
docker exec "$PHP_CONTAINER" test -f /var/www/html/.card-operation-ready
run_php 'php /tests/cashier-v3/php/member-prepare-schema.php | grep -q C5_LEGACY_SCHEMA_READY=1'

mysql_expect "$ENTITLEMENT_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
mysql_expect "$ENTITLEMENT_UPGRADE/02-正式升级.sql" 'APPLY_OK'
mysql_expect "$ENTITLEMENT_UPGRADE/03-升级后验证.sql" 'VERIFY_OK'
register_upgrade '20260728-005-cashier-v3-entitlement-draft' 'card operation focused dependency' "$ENTITLEMENT_UPGRADE/02-正式升级.sql"
# The entitlement migration creates the workspace tables; run the fixture
# schema extender once more so compatibility columns are added after creation.
run_php 'php /tests/cashier-v3/php/member-prepare-schema.php | grep -q C5_LEGACY_SCHEMA_READY=1'

mysql_expect "$EVENT_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
mysql_expect "$EVENT_UPGRADE/02-正式升级.sql" 'APPLY_OK'
mysql_expect "$EVENT_UPGRADE/03-升级后验证.sql" 'VERIFY_OK'
register_upgrade '20260728-003-cashier-v3-event-outbox' 'card operation focused dependency' "$EVENT_UPGRADE/02-正式升级.sql"

mysql_expect "$C5_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
mysql_expect "$C5_UPGRADE/02-正式升级.sql" 'APPLY_OK'
mysql_expect "$C5_UPGRADE/03-升级后验证.sql" 'VERIFY_OK'

# Checkout facts depend on the complete request, sale-line and payment-draft
# schema.  Install that real migration before attempting the fact migration.
echo 'CARD_OPERATION_PHASE=checkout-settlement'
mysql_expect "$CHECKOUT_SETTLEMENT_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
mysql_expect "$CHECKOUT_SETTLEMENT_UPGRADE/02-正式升级.sql" 'APPLY_OK'
mysql_expect "$CHECKOUT_SETTLEMENT_UPGRADE/03-升级后验证.sql" 'POSTCHECK_OK'
register_upgrade '20260729-004-cashier-v3-checkout-settlement' 'card operation focused dependency' "$CHECKOUT_SETTLEMENT_UPGRADE/02-正式升级.sql"

echo 'CARD_OPERATION_PHASE=checkout-source-authority'
mysql_expect "$CHECKOUT_SOURCE_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
mysql_expect "$CHECKOUT_SOURCE_UPGRADE/02-正式升级.sql" 'APPLY_OK'
mysql_expect "$CHECKOUT_SOURCE_UPGRADE/03-升级后验证.sql" 'POSTCHECK_OK'
register_upgrade '20260729-006-cashier-v3-checkout-source-authority' 'card operation focused dependency' "$CHECKOUT_SOURCE_UPGRADE/02-正式升级.sql"

# The card-operation production path records lifecycle context.  Install the
# real fact, service-fact, source-config, channel and lifecycle migrations in
# dependency order so the isolated MySQL gate validates that actual contract.
echo 'CARD_OPERATION_PHASE=checkout-facts'
mysql_expect "$CHECKOUT_FACT_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
mysql_file "$CHECKOUT_FACT_UPGRADE/02-正式升级.sql"
mysql_expect "$CHECKOUT_FACT_UPGRADE/03-升级后验证.sql" 'VERIFY_OK'
register_upgrade '20260729-007-cashier-v3-checkout-facts-v1' 'card operation focused dependency' "$CHECKOUT_FACT_UPGRADE/02-正式升级.sql"

echo 'CARD_OPERATION_PHASE=debt-repayment-authority'
mysql_expect "$DEBT_REPAYMENT_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
mysql_expect "$DEBT_REPAYMENT_UPGRADE/02-正式升级.sql" 'APPLY_OK'
mysql_expect "$DEBT_REPAYMENT_UPGRADE/03-升级后验证.sql" 'POSTCHECK_OK'
register_upgrade '20260801-003-cashier-v3-debt-repayment-authority' 'card operation focused dependency' "$DEBT_REPAYMENT_UPGRADE/02-正式升级.sql"

echo 'CARD_OPERATION_PHASE=checkout-resource-plan'
mysql_expect "$CHECKOUT_RESOURCE_PLAN_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
mysql_expect "$CHECKOUT_RESOURCE_PLAN_UPGRADE/02-正式升级.sql" 'APPLY_OK'
mysql_expect "$CHECKOUT_RESOURCE_PLAN_UPGRADE/03-升级后验证.sql" 'POSTCHECK_OK'
register_upgrade '20260729-010-cashier-v3-checkout-resource-plan' 'card operation focused dependency' "$CHECKOUT_RESOURCE_PLAN_UPGRADE/02-正式升级.sql"

echo 'CARD_OPERATION_PHASE=sales-order-authority'
mysql_expect "$SALES_ORDER_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
mysql_expect "$SALES_ORDER_UPGRADE/02-正式升级.sql" 'APPLY_OK'
mysql_expect "$SALES_ORDER_UPGRADE/03-升级后验证.sql" 'POSTCHECK_OK'
register_upgrade '20260729-011-cashier-v3-sales-order-authority-v1' 'card operation focused dependency' "$SALES_ORDER_UPGRADE/02-正式升级.sql"

echo 'CARD_OPERATION_PHASE=payment-collection-authority'
mysql_expect "$PAYMENT_COLLECTION_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
mysql_expect "$PAYMENT_COLLECTION_UPGRADE/02-正式升级.sql" 'APPLY_OK'
mysql_expect "$PAYMENT_COLLECTION_UPGRADE/03-升级后验证.sql" 'POSTCHECK_OK'
register_upgrade '20260729-012-cashier-v3-payment-collection-authority-v1' 'card operation focused dependency' "$PAYMENT_COLLECTION_UPGRADE/02-正式升级.sql"

echo 'CARD_OPERATION_PHASE=entitlement-completion'
mysql_expect "$ENTITLEMENT_COMPLETION_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
mysql_file "$ENTITLEMENT_COMPLETION_UPGRADE/02-正式升级.sql"
mysql_expect "$ENTITLEMENT_COMPLETION_UPGRADE/03-升级后验证.sql" 'POSTCHECK_OK'
register_upgrade '20260729-014-cashier-v3-entitlement-completion-persistence-v1' 'card operation focused dependency' "$ENTITLEMENT_COMPLETION_UPGRADE/02-正式升级.sql"

echo 'CARD_OPERATION_PHASE=more-actions-authority'
mysql_expect "$MORE_ACTIONS_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
mysql_expect "$MORE_ACTIONS_UPGRADE/02-正式升级.sql" 'APPLY_OK'
mysql_expect "$MORE_ACTIONS_UPGRADE/03-升级后验证.sql" 'POSTCHECK_OK'
register_upgrade '20260805-001-cashier-v3-more-actions-authority' 'card operation focused dependency' "$MORE_ACTIONS_UPGRADE/02-正式升级.sql"

echo 'CARD_OPERATION_PHASE=business-source-config'
mysql_file "$BUSINESS_SOURCE_UPGRADE/01-升级前检查.sql"
mysql_expect "$BUSINESS_SOURCE_UPGRADE/02-正式升级.sql" 'APPLY_OK'
mysql_file "$BUSINESS_SOURCE_UPGRADE/03-升级后验证.sql"
register_upgrade '20260806-002-cashier-v3-business-source-accounting-config' 'card operation focused dependency' "$BUSINESS_SOURCE_UPGRADE/02-正式升级.sql"

echo 'CARD_OPERATION_PHASE=report-channel-fact-snapshot'
mysql_expect "$REPORT_CHANNEL_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
mysql_file "$REPORT_CHANNEL_UPGRADE/02-正式升级.sql"
mysql_expect "$REPORT_CHANNEL_UPGRADE/03-升级后验证.sql" 'POSTCHECK_OK'
register_upgrade '20260810-001-report-channel-fact-snapshot' 'card operation focused dependency' "$REPORT_CHANNEL_UPGRADE/02-正式升级.sql"

echo 'CARD_OPERATION_PHASE=customer-lifecycle'
echo 'CARD_OPERATION_PHASE=customer-lifecycle-precheck'
mysql_expect "$CUSTOMER_LIFECYCLE_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
echo 'CARD_OPERATION_PHASE=customer-lifecycle-apply'
mysql_file "$CUSTOMER_LIFECYCLE_UPGRADE/02-正式升级.sql"
echo 'CARD_OPERATION_PHASE=customer-lifecycle-postcheck'
mysql_expect "$CUSTOMER_LIFECYCLE_UPGRADE/03-升级后验证.sql" 'POSTCHECK_OK'
register_upgrade '20260810-002-customer-lifecycle-first-course-attribution' 'card operation focused dependency' "$CUSTOMER_LIFECYCLE_UPGRADE/02-正式升级.sql"

echo 'CARD_OPERATION_PHASE=sale-cart-authority'
mysql_expect "$SALE_CART_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
mysql_expect "$SALE_CART_UPGRADE/02-正式升级.sql" 'APPLY_OK'
mysql_expect "$SALE_CART_UPGRADE/03-升级后验证.sql" 'POSTCHECK_OK'
register_upgrade '20260729-008-cashier-v3-sale-cart-authority' 'card operation focused dependency' "$SALE_CART_UPGRADE/02-正式升级.sql"

# The workspace schema now freezes salesperson assignments.  Card-operation
# commands reuse the same cashier readiness gate, so this is a real runner
# dependency rather than a test-only column shortcut.
echo 'CARD_OPERATION_PHASE=salesperson-workspace-draft'
mysql_expect "$SALESPERSON_DRAFT_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
mysql_expect "$SALESPERSON_DRAFT_UPGRADE/02-正式升级.sql" 'APPLY_OK'
mysql_expect "$SALESPERSON_DRAFT_UPGRADE/03-升级后验证.sql" 'POSTCHECK_OK'
register_upgrade '20260731-006-cashier-v3-salesperson-workspace-draft' 'card operation focused dependency' "$SALESPERSON_DRAFT_UPGRADE/02-正式升级.sql"

# Coupon fields are part of a frozen sales line and must be present before a
# card-upgrade command creates its protected workspace line.
echo 'CARD_OPERATION_PHASE=line-coupon-snapshot'
mysql_expect "$LINE_COUPON_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
mysql_expect "$LINE_COUPON_UPGRADE/02-正式升级.sql" 'APPLY_OK'
mysql_expect "$LINE_COUPON_UPGRADE/03-升级后验证.sql" 'POSTCHECK_OK'
register_upgrade '20260811-001-cashier-v3-line-coupon-snapshot' 'card operation focused dependency' "$LINE_COUPON_UPGRADE/02-正式升级.sql"

# A restored hang binds the live workspace first.  Use the two existing
# production migrations rather than adding a test-only workspace column.
echo 'CARD_OPERATION_PHASE=hang-order-authority'
mysql_expect "$HANG_ORDER_AUTHORITY_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
mysql_expect "$HANG_ORDER_AUTHORITY_UPGRADE/02-正式升级.sql" 'APPLY_OK'
mysql_expect "$HANG_ORDER_AUTHORITY_UPGRADE/03-升级后验证.sql" 'POSTCHECK_OK'
register_upgrade '20260729-017-cashier-v3-hang-order-authority-v1' 'card operation focused dependency' "$HANG_ORDER_AUTHORITY_UPGRADE/02-正式升级.sql"

echo 'CARD_OPERATION_PHASE=hang-resume-checkout'
mysql_expect "$HANG_RESUME_CHECKOUT_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
mysql_expect "$HANG_RESUME_CHECKOUT_UPGRADE/02-正式升级.sql" 'APPLY_OK'
mysql_expect "$HANG_RESUME_CHECKOUT_UPGRADE/03-升级后验证.sql" 'POSTCHECK_OK'
register_upgrade '20260730-022-cashier-v3-sale-hang-resume-checkout-v1' 'card operation focused dependency' "$HANG_RESUME_CHECKOUT_UPGRADE/02-正式升级.sql"

echo 'CARD_OPERATION_PHASE=resumed-hang-checkout-reference'
mysql_expect "$RESUMED_HANG_REFERENCE_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
mysql_expect "$RESUMED_HANG_REFERENCE_UPGRADE/02-正式升级.sql" 'APPLY_OK'
mysql_expect "$RESUMED_HANG_REFERENCE_UPGRADE/03-升级后验证.sql" 'POSTCHECK_OK'
register_upgrade '20260810-002-cashier-v3-resumed-hang-checkout-reference' 'card operation focused dependency' "$RESUMED_HANG_REFERENCE_UPGRADE/02-正式升级.sql"

# Card-operation audit rows receive the same immutable, date-scoped business
# number as other cashier facts.  The two prerequisite upgrades are installed
# only in this disposable MySQL 5.6 database, in their production order.
echo 'CARD_OPERATION_PHASE=recharge-debt-authority'
mysql_expect "$RECHARGE_DEBT_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
mysql_expect "$RECHARGE_DEBT_UPGRADE/02-正式升级.sql" 'APPLY_OK'
mysql_expect "$RECHARGE_DEBT_UPGRADE/03-升级后验证.sql" 'POSTCHECK_OK'
register_upgrade '20260802-001-cashier-v3-recharge-debt-authority' 'card operation focused dependency' "$RECHARGE_DEBT_UPGRADE/02-正式升级.sql"

echo 'CARD_OPERATION_PHASE=recharge-gift-authority'
echo 'CARD_OPERATION_PHASE=recharge-gift-authority-precheck'
mysql_expect "$RECHARGE_GIFT_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
echo 'CARD_OPERATION_PHASE=recharge-gift-authority-apply'
mysql_expect "$RECHARGE_GIFT_UPGRADE/02-正式升级.sql" 'APPLY_OK'
echo 'CARD_OPERATION_PHASE=recharge-gift-authority-postcheck'
mysql_expect "$RECHARGE_GIFT_UPGRADE/03-升级后验证.sql" 'POSTCHECK_OK'
echo 'CARD_OPERATION_PHASE=recharge-gift-authority-register'
register_upgrade '20260802-002-cashier-v3-recharge-gift-authority' 'card operation focused dependency' "$RECHARGE_GIFT_UPGRADE/02-正式升级.sql"

echo 'CARD_OPERATION_PHASE=business-document-numbers'
mysql_expect "$DOCUMENT_NUMBER_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
mysql_expect "$DOCUMENT_NUMBER_UPGRADE/02-正式升级.sql" 'APPLY_OK'
mysql_expect "$DOCUMENT_NUMBER_UPGRADE/03-升级后验证.sql" 'POSTCHECK_OK'
register_upgrade '20260803-005-cashier-v3-business-document-numbers' 'card operation focused dependency' "$DOCUMENT_NUMBER_UPGRADE/02-正式升级.sql"

echo 'CARD_OPERATION_PHASE=card-operation-authority'
echo 'CARD_OPERATION_PHASE=card-operation-authority-precheck'
mysql_expect "$CARD_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
echo 'CARD_OPERATION_PHASE=card-operation-authority-apply'
mysql_expect "$CARD_UPGRADE/02-正式升级.sql" 'APPLY_OK'
echo 'CARD_OPERATION_PHASE=card-operation-authority-postcheck'
mysql_expect "$CARD_UPGRADE/03-升级后验证.sql" 'POSTCHECK_OK'
echo 'CARD_OPERATION_PHASE=card-operation-authority-register'
register_upgrade '20260730-021-cashier-v3-card-operation-authority-v1' 'card operation authority' "$CARD_UPGRADE/02-正式升级.sql"

# Upgrade/project settlement writes the authoritative positive entitlement
# credit for card and project upgrades. The integration fixture requires this
# table before it can exercise the checkout-owned settlement boundary.
echo 'CARD_OPERATION_PHASE=card-operation-settlement'
mysql_expect "$CARD_OPERATION_SETTLEMENT_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
mysql_expect "$CARD_OPERATION_SETTLEMENT_UPGRADE/02-正式升级.sql" 'APPLY_OK'
mysql_expect "$CARD_OPERATION_SETTLEMENT_UPGRADE/03-升级后验证.sql" '1'
register_upgrade '20260811-001-cashier-v3-card-operation-entitlement-credit-v1' 'card operation settlement authority' "$CARD_OPERATION_SETTLEMENT_UPGRADE/02-正式升级.sql"

# The regression below covers a direct project replacement on a newly issued
# four-rule card. Install its real prerequisites in the same order as a
# production instance; legacy-card coverage remains in the fixture too.
echo 'CARD_OPERATION_PHASE=card-issuance'
mysql_expect "$CARD_ISSUANCE_UPGRADE/02-正式升级.sql" 'APPLY_OK'
register_upgrade '20260730-023-cashier-v3-card-purchase-issuance-v1' 'card operation rule-state dependency' "$CARD_ISSUANCE_UPGRADE/02-正式升级.sql"

echo 'CARD_OPERATION_PHASE=issued-card-rule-state'
mysql_expect "$ISSUED_CARD_RULE_UPGRADE/01-升级前检查.sql" 'PRECHECK_OK'
mysql_expect "$ISSUED_CARD_RULE_UPGRADE/02-正式升级.sql" 'APPLY_OK'
mysql_expect "$ISSUED_CARD_RULE_UPGRADE/03-升级后验证.sql" 'POSTCHECK_OK'
register_upgrade '20260803-005-cashier-v3-issued-card-rule-state-v1' 'card operation rule-state dependency' "$ISSUED_CARD_RULE_UPGRADE/02-正式升级.sql"

echo 'CARD_OPERATION_PHASE=php-contract-and-integration'
run_php '
  php -l /tests/cashier-v3/php/card-operation-integration.php
  php /tests/cashier-v3/php/card-operation-kernel-contract.php
'
if ! card_operation_integration="$(run_php 'php /tests/cashier-v3/php/card-operation-integration.php')"; then
  printf '%s\n' "$card_operation_integration" >&2
  exit 1
fi
printf '%s\n' "$card_operation_integration"
echo 'CARD_OPERATION_PHASE=current-document-number-postcheck'
# The card-operation migration's own postcheck ran immediately after it was
# applied above.  Its historical CO+hash format must not be re-run after the
# later business-document upgrade has issued current CK date/sequence numbers.
invalid_operation_numbers="$(docker exec "$MYSQL_CONTAINER" mysql -uroot --database="$DB_NAME" -Nse "
  SELECT COUNT(*)
  FROM eb_cashier_v3_card_operation
  WHERE operation_no NOT REGEXP '^CK[0-9]{11}$';")"
test "$invalid_operation_numbers" = '0'

echo 'CARD_OPERATION_FOCUSED_MYSQL56=PASS'
