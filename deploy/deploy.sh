#!/bin/sh
# 部署：主機自己從 GitHub 拉。用法（root）：sh /opt/esign.ccra.tw/deploy/deploy.sh [--force]
set -eu
REPO=/opt/esign.ccra.tw
WEB=/var/www/esign.ccra.tw
cd "$REPO"
git fetch -q origin main
[ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] && [ "${1:-}" != "--force" ] && exit 0
git reset -q --hard origin/main
mkdir -p "$WEB"
install -d -m 770 -o www-data -g www-data /var/lib/esign /var/lib/esign/records /var/lib/esign/outbox /var/lib/esign/outbox-sent
rsync -a --delete --exclude 'tests/' --exclude '.git/' --exclude 'deploy/' --exclude 'docs/' "$REPO/" "$WEB/"
chown -R root:www-data "$WEB"
install -m 644 -o root -g root "$REPO/deploy/esign.cron" /etc/cron.d/esign
touch /var/log/esign-digest.log && chown www-data:www-data /var/log/esign-digest.log
if ! cmp -s "$REPO/deploy/esign-fpm.conf" /etc/php/8.5/fpm/pool.d/esign.conf; then
    install -m 644 -o root -g root "$REPO/deploy/esign-fpm.conf" /etc/php/8.5/fpm/pool.d/esign.conf
    php-fpm8.5 -t && systemctl reload php8.5-fpm
fi
if ! cmp -s "$REPO/deploy/esign.nginx" /etc/nginx/sites-available/esign.ccra.tw; then
    install -m 644 -o root -g root "$REPO/deploy/esign.nginx" /etc/nginx/sites-available/esign.ccra.tw
    ln -sf /etc/nginx/sites-available/esign.ccra.tw /etc/nginx/sites-enabled/esign.ccra.tw
    nginx -t && systemctl reload nginx
fi
logger -t esign-deploy "deployed $(git rev-parse --short HEAD)"
echo "deployed $(git rev-parse --short HEAD)"
