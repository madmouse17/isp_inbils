#!/bin/sh
# Runs the FreeRADIUS lab e2e (tests/e2e/radius-provisioning.spec.ts) against ~/labs/freeradius.
# The served app gets RADIUS_* from the lab's own .env through the process env (scripts/e2e-server-env.mjs
# forwards them only while E2E_RADIUS_LAB=1), so the local .env.e2e needs no RADIUS lines. Values are never printed.
# The lab runs under the root dockerd, hence --context default (the shell default may be Docker Desktop).
set -eu

lab_dir="$HOME/labs/freeradius"
lab_value() { grep "^$1=" "$lab_dir/.env" | cut -d= -f2-; }

# Light reset of rows THIS spec owns only (user e2e-lab@demo, the demo-realm NAS at 172.30.77.20): a leftover NAS
# row makes the next run report "Not written" (the writer never overwrites a row it did not create). Other lab
# users, sessions and NAS rows are left alone. A failed reset stops the run.
lab_user='e2e-lab@demo'
docker --context default compose -f "$lab_dir/compose.yaml" exec -T radius-db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot radius -e "
    delete from nas where nasname = \"172.30.77.20\" and shortname = \"demo\";
    delete from radcheck where username = \"'"$lab_user"'\";
    delete from radreply where username = \"'"$lab_user"'\";
    delete from radusergroup where username = \"'"$lab_user"'\";
    delete from radacct where username = \"'"$lab_user"'\";
    delete from radpostauth where username = \"'"$lab_user"'\";"'
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
