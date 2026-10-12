#!/bin/sh
# Runs the FreeRADIUS lab e2e (tests/e2e/radius-provisioning.spec.ts) against ~/labs/freeradius.
# The served app gets RADIUS_* from the lab's own .env through the process env (scripts/e2e-server-env.mjs
# forwards them only while E2E_RADIUS_LAB=1), so the local .env.e2e needs no RADIUS lines. Values are never printed.
# The lab runs under the root dockerd, hence --context default (the shell default may be Docker Desktop).
set -eu

lab_dir="$HOME/labs/freeradius"
lab_value() { grep "^$1=" "$lab_dir/.env" | cut -d= -f2-; }

# Light reset: the spec leaves its NAS row, account rows and sessions behind, and a leftover NAS row makes the
# next run report "Not written" (the writer never overwrites a row it did not create).
docker --context default compose -f "$lab_dir/compose.yaml" exec -T radius-db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" radius -e "
    delete from nas where nasname = \"172.30.77.20\";
    delete from radcheck where username like \"e2e-lab@%\";
    delete from radreply where username like \"e2e-lab@%\";
    delete from radusergroup where username like \"e2e-lab@%\";
    delete from radacct; delete from radpostauth;
    update radusergroup set groupname = \"10M\" where username = \"lab-ok\";"' 2>&1 | grep -v 'Using a password' || true
docker --context default compose -f "$lab_dir/compose.yaml" restart nas-sim >/dev/null

E2E_RADIUS_LAB=1 \
RADIUS_ENABLED=true \
RADIUS_DB_HOST=127.0.0.1 \
RADIUS_DB_PORT=13306 \
RADIUS_DB_DATABASE="$(lab_value RADIUS_DB_NAME)" \
RADIUS_DB_USERNAME="$(lab_value RADIUS_DB_USER)" \
RADIUS_DB_PASSWORD="$(lab_value RADIUS_DB_PASSWORD)" \
exec flock /tmp/inbils-e2e.lock sh -c \
    'php tests/e2e/setup.php && npm run test:e2e -- tests/e2e/radius-provisioning.spec.ts --project=chromium --workers=1 "$@"' sh "$@"
