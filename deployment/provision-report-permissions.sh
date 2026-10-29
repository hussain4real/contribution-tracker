#!/bin/bash
set -euo pipefail

# One-time upgrade for existing servers; also used by fresh-server setup.
# Run a reviewed copy as root BEFORE deploying this release. This deliberately
# grants no permission for the deployer to install or alter its own sudo policy.
if [[ "$EUID" -ne 0 ]]; then
    echo "Run this provisioning script as root before deployment." >&2
    exit 1
fi

POLICY=/etc/sudoers.d/familyfunds-report-permissions
TEMP_POLICY=$(mktemp /etc/sudoers.d/.familyfunds-report-permissions.XXXXXX)
trap 'rm -f "$TEMP_POLICY"' EXIT
cat > "$TEMP_POLICY" << 'EOF'
deployer ALL=(root) NOPASSWD: /usr/bin/find /var/www/contribution-tracker/storage/app/private/reports -type d -exec /usr/bin/chmod g+rx {} +
deployer ALL=(root) NOPASSWD: /usr/bin/find /var/www/contribution-tracker/storage/app/private/reports -type f -exec /usr/bin/chmod g+r {} +
EOF
chmod 0440 "$TEMP_POLICY"
visudo -cf "$TEMP_POLICY"
usermod -aG www-data deployer
mv -f "$TEMP_POLICY" "$POLICY"
echo "Report permissions provisioned. Open a fresh deployer SSH session before deploying."
