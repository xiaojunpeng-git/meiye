#!/usr/bin/env bash
set -euo pipefail

TEST_ROOT="$(cd "$(dirname "$0")" && pwd)"
php "$TEST_ROOT/php/canonical-identity-contract.php"
php "$TEST_ROOT/php/identity-writer-scanner-contract.php"
php "$TEST_ROOT/php/mobile-session-contract.php"
php "$TEST_ROOT/php/mobile-personal-target-contract.php"
bash "$TEST_ROOT/mysql56-canonical-identity.sh"
bash "$TEST_ROOT/mysql56-mobile-session.sh"
bash "$TEST_ROOT/mysql56-personal-monthly-target.sh"
