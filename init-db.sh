#!/bin/bash
set -e

echo "Importing database schema..."
mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" < /docker-entrypoint-initdb.d/barangay_poblacion_south.sql
echo "Database schema imported successfully!"
