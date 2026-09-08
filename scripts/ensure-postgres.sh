#!/usr/bin/env bash
#
# Starts PostgreSQL if it isn't already running, so `composer run dev` and
# `composer run setup` don't fail later with a confusing connection error.
#
# Postgres is systemctl-enabled and should come up with WSL's systemd, but
# that isn't a hard guarantee. The pg_isready check comes first so the common
# case (already running) never pays for a sudo call.

set -euo pipefail

if pg_isready -q; then
    echo "PostgreSQL is already running."
    exit 0
fi

echo "PostgreSQL is not running — starting it..."
sudo service postgresql start

for _ in {1..10}; do
    if pg_isready -q; then
        echo "PostgreSQL is up."
        exit 0
    fi
    sleep 0.5
done

echo "PostgreSQL did not become ready after starting. Check 'sudo service postgresql status'." >&2
exit 1
