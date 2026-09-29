#!/bin/bash
set -e

# 1. Ensure MariaDB server is running
if ! /etc/init.d/mariadb status > /dev/null 2>&1; then
    echo "Starting MariaDB service..."
    /etc/init.d/mariadb start || true
fi

# 2. Ensure database exists and schema is loaded
mariadb -e "CREATE DATABASE IF NOT EXISTS otp_marketplace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null || true
if ! mariadb -e "USE otp_marketplace; DESCRIBE users;" > /dev/null 2>&1; then
    echo "Initializing database schema..."
    mariadb otp_marketplace < schema.sql 2>/dev/null || true
fi

# 3. Start PHP Server on port 3000
echo "Starting NumVault PHP Application Server on port 3000..."
exec php -S 0.0.0.0:3000 router.php
