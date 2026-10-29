import { createHash } from "node:crypto";
import { readFileSync, readdirSync, lstatSync } from "node:fs";
import { dirname, join, relative, resolve } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const REQUIRED_CHECKS = [
    "production_config_cached_boot", "all_enabled_routes_and_admin_uat",
    "production_mfa_preserved_and_fresh_login", "oauth_mcp_and_webpush_compatibility",
    "durable_auth_rate_limits_after_idle", "financial_concurrency_and_unknown_outcome_handling",
    "queue_claim_ack_and_recovery", "live_mode_webhook_signature_and_duplicate_safety_without_charge",
    "all_family_scheduler_catchup_and_duplicate_fencing", "report_delivery_and_private_files_after_idle",
    "encrypted_backup_restore_retention_and_monitoring", "post_write_rollback_reconciliation",
    "20_users_warm_dashboard_under_3_seconds",
    "offsite_backup_credential_isolation",
];
const sha256 = bytes => createHash("sha256").update(bytes).digest("hex");

export function validatePreparation(config) {
    if (!config || typeof config !== "object" || Array.isArray(config)) return ["Expected a production configuration object"];
    const errors = [];
    const require = (condition, message) => { if (!condition) errors.push(message); };
    require(config.version === 1 && config.status === "prepared-disabled", "Expected a disabled production preparation manifest");
    require(config.hostname === "familyfunds.app" && config.account_id === "40344948b73fa9da3008958fdc3faf12", "Wrong production domain or account");
    const activation = config.activation ?? {};
    require(Array.isArray(activation.public_routes) && activation.public_routes.length === 0, "Preparation must not attach public routes");
    require(Array.isArray(activation.cron_triggers) && activation.cron_triggers.length === 0, "Preparation must not activate Cron");
    for (const key of ["workers_dev", "preview_urls", "queue_consumers_enabled", "outbound_delivery_enabled", "application_production_secrets_uploaded", "cutover_performed"]) {
        require(activation[key] === false, `Preparation must leave ${key} disabled`);
    }
    const resources = config.resources ?? {};
    for (const key of ["web_worker", "maintenance_worker", "container_name", "database_name", "private_bucket", "backup_bucket", "webhook_queue", "webhook_dead_letter_queue", "maintenance_queue", "maintenance_dead_letter_queue"]) {
        require(typeof resources[key] === "string" && resources[key].startsWith("familyfunds-production") && !/staging|scale|lab|proof|capacity/i.test(resources[key]), `Use a separate production ${key}`);
    }
    require(resources.container_class === "FamilyFundsManagedProductionContainer", "Use the separately verified managed production container class");
    const runtime = config.runtime ?? {};
    require(runtime.scheduling_policy === "durable_object" && runtime.preparation_stopped === true
        && runtime.instance_cap === 1 && runtime.instance_name === "familyfunds-production"
        && runtime.max_instances === undefined && runtime.preparation_max_instances === undefined,
    "Keep managed preparation stopped and enforce one instance through a fixed Durable Object name; this policy has no platform max_instances");
    require(runtime.placement_region === null && runtime.placement_policy === "provider-selected", "Do not claim a placement constraint unsupported by the managed policy");
    require(runtime.cpu_vcpu === 2 && runtime.memory_gib === 6 && runtime.disk_gb === 8, "Resource changes need a new capacity proof");
    require(runtime.idle_timeout_seconds === 120, "Keep the accepted idle shutdown policy");
    require([16, 20].includes(runtime.gateway_concurrency) && runtime.php_fpm_children === runtime.gateway_concurrency && runtime.fastcgi_keep_conn === false, "Keep matching bounded gateway/FPM pools and short FastCGI connections");
    require(runtime.automatic_financial_request_retries === 0 && runtime.stream_response_bodies === true, "Preserve financial retry and streaming controls");
    const env = runtime.environment ?? {};
    for (const [key, value] of Object.entries({APP_ENV: "production", APP_DEBUG: "false", APP_URL: "https://familyfunds.app", APP_TIMEZONE: "UTC", D1_STAGING_ENABLED: "false", FF_STARTUP_PROFILE: "0", DB_CONNECTION: "d1", QUEUE_CONNECTION: "d1", D1_PRODUCTION_ENABLED: "true", D1_PRODUCTION_DELIVERY_ENABLED: "false", D1_PRODUCTION_WEBHOOKS_ENABLED: "false", D1_DATABASE: "familyfunds-production", D1_URL: "https://familyfunds-production.d1.internal", CACHE_STORE: "d1", SESSION_DRIVER: "d1", SESSION_ENCRYPT: "true", SESSION_SECURE_COOKIE: "true", SESSION_COOKIE: "familyfunds_production_session", MAIL_MAILER: "cloudflare"})) {
        require(env[key] === value, `Invalid production environment setting ${key}`);
    }
    require(Object.keys(env).every(key => !/TOKEN|SECRET|PASSWORD|PRIVATE_KEY|APP_KEY|ACCESS_KEY/.test(key)), "Secret values belong in the protected secret store, not this manifest");
    require(Object.values(env).every(value => typeof value === "string" && !/d1-staging|scale-lab|example\.invalid/.test(value)), "Production environment must not target staging or synthetic recipients");
    const migration = config.migration ?? {};
    require(migration.mfa_policy === "preserve-and-reencrypt-on-hetzner" && migration.source_app_key_exported === false && migration.staging_sanitized_snapshot_allowed === false && migration.preserve_mfa_confirmation_timestamps === true, "Production export must preserve MFA without exporting the source key during preparation or using the sanitized staging snapshot");
    require(migration.production_key_policy === "preserve-existing-key-after-explicit-transfer-approval" && migration.existing_key_transfer_approved === false, "Existing production keys require explicit permission before transfer; Passport refresh-token compatibility must be preserved");
    require(config.integrations?.sql_bridge?.public_endpoint === false && config.integrations?.sql_bridge?.hostname === "familyfunds-production.d1.internal", "Production SQL must use the private container outbound binding");
    require(config.rollback?.source_retained === true && config.rollback?.origin_routing_change_requires_final_gate === true && config.rollback?.after_first_d1_write?.includes("reconcile"), "Post-write rollback requires reconciliation; DNS reversal alone loses writes");
    require(config.integrations?.queue_consumer_max_concurrency === 1 && config.integrations?.sql_bridge?.public_manifest_endpoint === false, "Keep bounded consumers and private database diagnostics");
    require(config.scheduling?.scope === "all eligible production families" && config.scheduling?.single_active_scheduler_required === true && config.scheduling?.durable_outbox === true, "Production scheduling requires all-family scope, a durable outbox and one active scheduler");
    require(REQUIRED_CHECKS.every(key => config.required_native_checks?.includes(key)), "A required production acceptance gate was removed");
    const offsite = config.offsite ?? {};
    require(offsite.worker === 'familyfunds-production-offsite' && offsite.enabled === false && offsite.workers_dev === false
        && offsite.preview_urls === false && Array.isArray(offsite.cron) && offsite.cron.length === 0
        && offsite.application_secrets_present === false && offsite.backup_decryption_key_present === false,
    'Offsite backups require their own disabled credential-isolated Worker and container');
    require(['FF_RCLONE_CONFIG', 'BACKUP_R2_ACCESS_KEY_ID', 'BACKUP_R2_SECRET_ACCESS_KEY'].every(name =>
        typeof offsite.secret_references?.[name] === 'string' && config.secret_references?.[name] === undefined),
    'Offsite credential references must be separate from application credentials');
    return errors;
}

function sourceInventory(root) {
    const files = [];
    const walk = directory => {
        for (const item of readdirSync(directory, {withFileTypes: true})) {
            const path = join(directory, item.name);
            if (item.isSymbolicLink()) continue;
            if (item.isDirectory()) {
                if (!item.name.startsWith(".") && !["cache", "__pycache__"].includes(item.name)) walk(path);
            } else if (/\.(php|mjs|js|py|sh|conf|vue|ts|css)$/.test(item.name) || /^Dockerfile/.test(item.name)) files.push(path);
        }
    };
    for (const directory of ["app", "bootstrap", "config", "database", "resources", "routes", "deployment/d1-proof"]) walk(join(root, directory));
    for (const file of ["composer.json", "composer.lock", "package.json", "package-lock.json", ".dockerignore"]) {
        files.push(join(root, file));
    }
    walk(join(root, "public/build"));
    // Include the compiled manifest as well as the content-addressed assets.
    files.push(join(root, "public/build/manifest.json"));
    return Object.fromEntries(files.sort().map(path => [relative(root, path), sha256(readFileSync(path))]));
}

export function reviewProduction(config, root = ROOT) {
    const errors = validatePreparation(config);
    const findings = [];
    const sourceFiles = sourceInventory(root);
    const sourceHash = sha256(JSON.stringify(sourceFiles));
    const implementations = [
        ["production-adapters", "deployment/d1-proof/D1ProductionServiceProvider.php", "callAfterResolving('cache'"],
        ["production-readiness", "deployment/d1-proof/container-health.php", "familyfunds-production.d1.internal"],
        ["production-route-boundary", "deployment/d1-proof/D1ProductionRequest.php", "final class D1ProductionRequest"],
        ["production-webhook-scope", "deployment/d1-proof/production-webhooks.mjs", "receiveProductionWebhook"],
        ["all-family-scheduling", "deployment/d1-proof/production-maintenance-worker.mjs", "productionCalendarTasks"],
        ["mfa-and-token-export", "deployment/d1-proof/D1PostgresSnapshot.php", "preserveAccounts"],
        ["durable-cache-and-rate-limits", "deployment/d1-proof/D1RuntimeCacheStore.php", "d1_runtime_cache"],
        ["idle-safe-queue-wake", "deployment/d1-proof/production-maintenance-worker.mjs", "drain"],
        ["isolated-offsite-runner", "deployment/d1-proof/production-offsite-worker.mjs", "class FamilyFundsOffsiteContainer"],
    ].map(([id, file, marker]) => {
        const lines = readFileSync(join(root, file), "utf8").split("\n");
        const line = lines.findIndex(value => value.includes(marker));
        if (line < 0) findings.push({id: `missing-${id}`, severity: "blocking", reason: `Production implementation is missing: ${id}`});
        return {id, file, line: line + 1, source_sha256: sourceFiles[file], status: line < 0 ? "missing" : "implemented; native acceptance pending"};
    });
    for (const id of REQUIRED_CHECKS) findings.push({id: `native-${id}`, severity: "blocking", reason: "This production runtime acceptance gate has not been completed: " + id});
    findings.push({id: "production-secret-transfer-pending", severity: "blocking", reason: "Current APP_KEY and Passport keys are required to preserve existing OAuth refresh tokens; exact production secret destinations require explicit approval."});
    for (const key of ["database_id", "container_namespace_id", "container_application_id"]) {
        if (!/^[a-f0-9-]{32,36}$/i.test(config.resources?.[key] ?? "")) findings.push({id: `unprovisioned-${key}`, severity: "blocking", reason: `Separate production ${key} is not provisioned and verified.`});
    }
    if (!/^sha256:[a-f0-9]{64}$/.test(config.runtime?.image ?? "") || config.runtime.image === config.runtime.accepted_staging_image_reference_only) {
        findings.push({id: "production-image-unverified", severity: "blocking", reason: "No independently built, deployed and natively accepted production image is pinned."});
    }
    return {
        checked_at: new Date().toISOString(),
        configuration_status: errors.length ? "invalid" : "prepared-disabled",
        configuration_errors: errors,
        deployment_decision: "NO-GO",
        production_changed: false,
        inspection_scope: "Local configuration and deployment review with source hashes; no production runtime deployment or native production acceptance is claimed",
        source_sha256: sourceHash,
        source_files: sourceFiles,
        implementations,
        findings,
        native_production_checks: Object.fromEntries(REQUIRED_CHECKS.map(key => [key, "not run against a production runtime"])),
        secret_transfer: "Production secrets remain on Hetzner. Obtain explicit permission for the exact production target and key handling before uploading them.",
        rollback_review: "Recovery-lab Time Travel passed previously. Reconciliation after new D1 writes is a separate unperformed production gate.",
    };
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
    const mode = process.argv[2] ?? "prepare";
    if (!["prepare", "release"].includes(mode)) throw new Error("Usage: node production-preflight.mjs [prepare|release]");
    const path = join(ROOT, "deployment/d1-proof/production-runtime.json");
    if (lstatSync(path).isSymbolicLink()) throw new Error("Production manifest must be a regular local file");
    const bytes = readFileSync(path);
    const result = {...reviewProduction(JSON.parse(bytes)), manifest_sha256: sha256(bytes)};
    console.log(JSON.stringify(result, null, 2));
    process.exitCode = result.configuration_errors.length || mode === "release" ? 2 : 0;
}
