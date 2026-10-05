#!/bin/bash
set -euo pipefail

# Read-only preflight, run from the incoming revision before changing the checkout.
fail() {
    echo "Report permissions require provisioning before deployment. Have an administrator run a reviewed copy of deployment/provision-report-permissions.sh as root, then reconnect as deployer and retry." >&2
    exit 1
}

# Check this session, not just the account database: supplementary groups are
# inherited at login, including by the scheduler's backup process.
[[ " $(id -nG) " == *" www-data "* ]] || fail
REPORTS_DIR=/var/www/contribution-tracker/storage/app/private/reports
sudo -n -l /usr/bin/find "$REPORTS_DIR" -type d -exec /usr/bin/chmod g+rx {} + >/dev/null 2>&1 || fail
sudo -n -l /usr/bin/find "$REPORTS_DIR" -type f -exec /usr/bin/chmod g+r {} + >/dev/null 2>&1 || fail
