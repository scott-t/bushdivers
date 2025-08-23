#!/usr/bin/env bash

mysql --user=root --password="$MYSQL_ROOT_PASSWORD" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS bushdiversva_phpunit;
    GRANT ALL PRIVILEGES ON \`bushdiversva_phpunit%\`.* TO '$MYSQL_USER'@'%';
EOSQL
