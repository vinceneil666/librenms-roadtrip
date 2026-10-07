#!/bin/sh
# Install the plugin from a local checkout into a LibreNMS install (e.g. inside the librenms/librenms container),
# symlinked, so code changes are live. Run as the librenms user:
#   PLUGIN_PATH=/opt/librenms-roadtrip LIBRENMS_PATH=/opt/librenms sh dev/install-dev.sh
set -e
PLUGIN_PATH=${PLUGIN_PATH:-/opt/librenms-roadtrip}
LIBRENMS_PATH=${LIBRENMS_PATH:-/opt/librenms}
cd "$LIBRENMS_PATH"
composer config repositories.librenms-roadtrip "{\"type\": \"path\", \"url\": \"$PLUGIN_PATH\", \"options\": {\"symlink\": true}}"
php lnms plugin:add vinceneil666/librenms-roadtrip @dev
php artisan optimize:clear >/dev/null   # routes and views of the new plugin
echo "Road Trip installed - Overview -> Plugins -> Road Trip"
