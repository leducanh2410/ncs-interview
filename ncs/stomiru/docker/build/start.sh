#!/bin/sh
set -e

# Khởi chạy supervisord để quản lý Nginx & PHP-FPM
exec /usr/bin/supervisord -c /etc/supervisord.conf
