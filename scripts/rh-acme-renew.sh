#!/usr/bin/env bash
set -euo pipefail

# RH is served by a manually managed Nginx site, not a Baota site record.
# Baota's generic renewal jobs skip it, so renew only this certificate through
# the same HTTP-01 path that was verified during the 2026-10-06 recovery.
domain=rh.cc3798.com
panel=/www/server/panel
web_root=/www/wwwroot/rh.cc3798.com/public
cert_dir="$panel/vhost/letsencrypt/$domain"
cert="$cert_dir/fullchain.pem"
key="$cert_dir/privkey.pem"
nginx=/www/server/nginx/sbin/nginx
python="$panel/pyenv/bin/python3"
backup_root=/www/backups/rh.cc3798.com/cert-renewal
renew_window=2592000

exec 9>/run/lock/ruihao_acme_renew.lock
flock -n 9 || { echo 'RH certificate renewal is already running' >&2; exit 1; }

# A healthy certificate needs the right domain, a matching key and at least
# 30 days of validity; a successful cron exit alone proves none of these.
certificate_is_healthy() {
  [[ -s "$cert" && -s "$key" ]] || return 1
  openssl x509 -in "$cert" -noout -checkhost "$domain" >/dev/null 2>&1 || return 1
  openssl x509 -in "$cert" -noout -checkend "$renew_window" >/dev/null 2>&1 || return 1
  local cert_public key_public
  cert_public="$(openssl x509 -in "$cert" -pubkey -noout | openssl pkey -pubin -outform DER | sha256sum)" || return 1
  key_public="$(openssl pkey -in "$key" -pubout -outform DER | sha256sum)" || return 1
  [[ "${cert_public%% *}" == "${key_public%% *}" ]]
}

if certificate_is_healthy; then
  echo "RH certificate is healthy and valid for more than 30 days; renewal skipped"
  exit 0
fi

[[ -d "$web_root/.well-known/acme-challenge" ]] || { echo 'RH ACME challenge path is missing' >&2; exit 1; }
[[ -x "$python" && -x "$nginx" ]] || { echo 'RH ACME or Nginx executable is missing' >&2; exit 1; }
"$nginx" -t

# The ACME client writes the live certificate path itself. Save a private,
# per-attempt rollback point before allowing it to replace either PEM file.
install -d -m 700 "$backup_root"
backup_dir="$(mktemp -d "$backup_root/renew-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXX")"
if [[ -d "$cert_dir" ]]; then
  tar -C "$panel/vhost/letsencrypt" -czf "$backup_dir/certificate-before.tar.gz" "$domain"
  chmod 600 "$backup_dir/certificate-before.tar.gz"
fi
cp -p "$panel/vhost/nginx/$domain.conf" "$backup_dir/nginx-before.conf"
chmod 600 "$backup_dir/nginx-before.conf"

restore_certificate() {
  if [[ -f "$backup_dir/certificate-before.tar.gz" ]]; then
    tar -C "$panel/vhost/letsencrypt" -xzf "$backup_dir/certificate-before.tar.gz"
    "$nginx" -t && "$nginx" -s reload
  fi
}

cd "$panel"
if ! result="$(timeout 600 "$python" -u "$panel/class/acme_v2.py" \
  --domain="$domain" --type=http --path="$web_root" 2>&1)"; then
  printf '%s\n' "$result" >&2
  restore_certificate
  exit 1
fi
printf '%s\n' "$result"
if [[ "$result" != *'证书获取成功!'* ]] || ! certificate_is_healthy || ! "$nginx" -t; then
  echo 'RH ACME returned without a valid replacement certificate; restoring previous certificate' >&2
  restore_certificate
  exit 1
fi

"$nginx" -s reload
# Resolve to this server so a proxy, DNS cache or external load balancer cannot
# make a different host's certificate look like a successful local deployment.
curl --noproxy '*' --resolve "$domain:443:127.0.0.1" --fail --silent --show-error \
  --max-time 20 --head "https://$domain/view_cashier_v3/" >/dev/null
echo "RH certificate renewed and strict local HTTPS verified; rollback: $backup_dir"
