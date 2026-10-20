#!/bin/sh
# What a real Midtrans callback needs on a Sail dev machine (F5-U17): the app, the queue worker and the scheduler as
# containers, and, when INBILS_TUNNEL names a cloudflared tunnel, that tunnel so Midtrans can reach this machine.
#
#   ./scripts/dev-payments.sh                              # app and workers, then how to expose the webhook
#   INBILS_TUNNEL=<name or id> ./scripts/dev-payments.sh   # the same, then the tunnel in the foreground (Ctrl-C ends it)
#
# The workers keep running when the tunnel ends. Stop them with
#   COMPOSE_PROFILES=workers ./vendor/bin/sail stop queue scheduler
set -eu

cd "$(dirname "$0")/.."

COMPOSE_PROFILES=workers ./vendor/bin/sail up -d

if [ -n "${INBILS_TUNNEL:-}" ]; then
    case "$INBILS_TUNNEL" in
        -* | *[!A-Za-z0-9._-]*)
            echo "INBILS_TUNNEL must be the name or the id of a cloudflared tunnel." >&2
            exit 2
            ;;
    esac

    if ! command -v cloudflared >/dev/null 2>&1; then
        echo "cloudflared is not installed: https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/" >&2
        exit 1
    fi

    echo "App, queue and scheduler are up. Running tunnel $INBILS_TUNNEL; open the app through its public https address."
    exec cloudflared tunnel run "$INBILS_TUNNEL"
fi

cat <<'EOF'
App, queue and scheduler are up.

Midtrans cannot call localhost. Make http://localhost reachable from the internet, then open the app through that
https address (the notification URL of an order is built from the address the app was opened on):

  quick tunnel:  cloudflared tunnel --url http://localhost     (prints an https://....trycloudflare.com address)
  named tunnel:  INBILS_TUNNEL=<name or id> ./scripts/dev-payments.sh

Pengaturan > Integrasi > Midtrans shows the URL Snap sends with every order:
  https://<public address>/webhooks/midtrans/<company id>
Midtrans calls it, the queue worker books the payment, the scheduler runs midtrans:reconcile.
EOF
