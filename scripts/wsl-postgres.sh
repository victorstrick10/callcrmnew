#!/usr/bin/env bash
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

if ! command -v psql >/dev/null 2>&1; then
  apt-get update -qq
  apt-get install -y -qq postgresql postgresql-contrib
fi

PGVER="$(ls /etc/postgresql | sort -V | tail -1)"
CONF="/etc/postgresql/${PGVER}/main/postgresql.conf"
HBA="/etc/postgresql/${PGVER}/main/pg_hba.conf"
# 5432 is often occupied on the Windows host (WSL1 shares the network stack).
PORT="${PG_PORT:-5433}"

echo "PGVER=${PGVER} PORT=${PORT}"

sed -i "s/^#\\?port = .*/port = ${PORT}/" "$CONF"
sed -i "s/^#\\?listen_addresses.*/listen_addresses = '*'/" "$CONF"

sed -i 's/^local\s\+all\s\+postgres\s\+peer/local   all             postgres                                trust/' "$HBA"
sed -i 's/^local\s\+all\s\+all\s\+peer/local   all             all                                     md5/' "$HBA"
grep -q '0.0.0.0/0 md5' "$HBA" || echo 'host all all 0.0.0.0/0 md5' >> "$HBA"
grep -q '::/0 md5' "$HBA" || echo 'host all all ::/0 md5' >> "$HBA"

pg_ctlcluster "$PGVER" main restart 2>/dev/null || service postgresql restart
sleep 2

su - postgres -c "psql -p ${PORT} -c 'SELECT version();'"

su - postgres -c "psql -p ${PORT} -tAc \"SELECT 1 FROM pg_roles WHERE rolname='call_crm'\"" | grep -q 1 \
  || su - postgres -c "psql -p ${PORT} -c \"CREATE USER call_crm WITH PASSWORD 'call_crm' CREATEDB;\""

su - postgres -c "psql -p ${PORT} -tAc \"SELECT 1 FROM pg_database WHERE datname='call_crm'\"" | grep -q 1 \
  || su - postgres -c "psql -p ${PORT} -c \"CREATE DATABASE call_crm OWNER call_crm;\""

su - postgres -c "psql -p ${PORT} -c \"GRANT ALL PRIVILEGES ON DATABASE call_crm TO call_crm;\""
su - postgres -c "psql -p ${PORT} -d call_crm -c \"GRANT ALL ON SCHEMA public TO call_crm;\"" 2>/dev/null || true

echo "WSL Postgres ready: db=call_crm user=call_crm host=127.0.0.1 port=${PORT}"
