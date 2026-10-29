import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import test from "node:test";
import { reviewProduction, validatePreparation } from "./production-preflight.mjs";

const manifest = JSON.parse(readFileSync(new URL("./production-runtime.json", import.meta.url), "utf8"));
const change = mutate => { const config = structuredClone(manifest); mutate(config); return config; };

test("preparation is valid while the actual deployment decision remains NO-GO", () => {
    assert.deepEqual(validatePreparation(manifest), []);
    const result = reviewProduction(manifest);
    assert.equal(result.configuration_status, "prepared-disabled");
    assert.equal(result.deployment_decision, "NO-GO");
    assert.equal(result.production_changed, false);
    assert.equal(result.implementations.length, 9);
    assert.ok(result.implementations.every(item => item.status === "implemented; native acceptance pending"));
    for (const id of ["production-image-unverified", "production-secret-transfer-pending", "native-post_write_rollback_reconciliation"]) {
        assert.ok(result.findings.some(finding => finding.id === id), id);
    }
    assert.ok(result.native_production_checks.production_mfa_preserved_and_fresh_login.includes("not run"));
});

test("the deployment gate exits unsuccessfully even though preparation is valid", () => {
    const result = spawnSync(process.execPath, [fileURLToPath(new URL("./production-preflight.mjs", import.meta.url)), "release"], {encoding: "utf8", maxBuffer: 2_000_000});
    assert.equal(result.status, 2, result.stderr);
    assert.equal(JSON.parse(result.stdout).deployment_decision, "NO-GO");
});

test("preparation cannot expose a route, schedule, queue, sending path or preview", () => {
    for (const key of ["workers_dev", "preview_urls", "queue_consumers_enabled", "outbound_delivery_enabled", "application_production_secrets_uploaded", "cutover_performed"]) {
        assert.ok(validatePreparation(change(config => { config.activation[key] = true; })).length > 0, key);
    }
    assert.ok(validatePreparation(change(config => { config.activation.public_routes = ["familyfunds.app/*"]; })).length > 0);
    assert.ok(validatePreparation(change(config => { config.activation.cron_triggers = ["* * * * *"]; })).length > 0);
});

test("staging resources and the accepted staging image cannot stand in for production", () => {
    assert.ok(validatePreparation(change(config => { config.resources.database_name = "familyfunds-d1-web-staging"; })).length > 0);
    assert.ok(validatePreparation(change(config => { config.resources.private_bucket = "familyfunds-d1-staging-private"; })).length > 0);
    const result = reviewProduction(change(config => { config.runtime.image = config.runtime.accepted_staging_image_reference_only; }));
    assert.ok(result.findings.some(finding => finding.id === "production-image-unverified"));
});

test("no plaintext credential value or staging environment can enter runtime variables", () => {
    assert.ok(validatePreparation(change(config => { config.runtime.environment.APP_KEY = "fixture-only-key"; })).length > 0);
    assert.ok(validatePreparation(change(config => { config.runtime.environment.APP_ENV = "d1-staging"; })).length > 0);
    assert.ok(validatePreparation(change(config => { config.runtime.environment.APP_DEBUG = "true"; })).length > 0);
});

test("production MFA and OAuth compatibility cannot be discarded or existing keys transferred without permission", () => {
    assert.ok(validatePreparation(change(config => { config.migration.staging_sanitized_snapshot_allowed = true; })).length > 0);
    assert.ok(validatePreparation(change(config => { config.migration.source_app_key_exported = true; })).length > 0);
    assert.ok(validatePreparation(change(config => { config.migration.production_key_policy = "rotate-and-break-refresh-tokens"; })).length > 0);
    assert.ok(validatePreparation(change(config => { config.migration.existing_key_transfer_approved = true; })).length > 0);
    assert.ok(validatePreparation(change(config => { config.migration.mfa_policy = "independent-staging-enrollment"; })).length > 0);
});

test("financial retries, persistent FastCGI pools and lost release gates are refused", () => {
    assert.ok(validatePreparation(change(config => { config.runtime.automatic_financial_request_retries = 1; })).length > 0);
    assert.ok(validatePreparation(change(config => { config.runtime.fastcgi_keep_conn = true; })).length > 0);
    assert.ok(validatePreparation(change(config => { config.required_native_checks.pop(); })).length > 0);
});

test("review evidence binds current runtime source and compiled assets without private files", () => {
    const result = reviewProduction(manifest);
    assert.match(result.source_sha256, /^[a-f0-9]{64}$/);
    assert.ok(result.source_files["composer.lock"]);
    assert.ok(result.source_files["deployment/d1-proof/container-worker.mjs"]);
    assert.ok(result.source_files["public/build/manifest.json"]);
    assert.ok(Object.keys(result.source_files).some(path => /^public\/build\/.*\.js$/.test(path)));
    assert.ok(Object.keys(result.source_files).every(path => !path.startsWith("storage/") && !path.includes(".env")));
    for (const finding of result.findings.filter(finding => finding.file)) {
        assert.equal(finding.source_sha256, result.source_files[finding.file]);
        assert.ok(finding.line > 0);
    }
});

test("malformed JSON configuration shapes fail validation", () => {
    assert.ok(validatePreparation(null).length > 0);
    assert.ok(validatePreparation([]).length > 0);
    assert.ok(validatePreparation({}).length > 0);
});

test('managed preparation rejects unsupported platform caps and placement promises', () => {
    for (const mutate of [
        config => { config.runtime.max_instances = 1; },
        config => { config.runtime.placement_region = 'WEUR'; },
        config => { config.runtime.instance_cap = 2; },
        config => { config.runtime.instance_name = 'arbitrary-instance'; },
        config => { config.resources.container_class = 'FamilyFundsProductionContainer'; },
    ]) assert.ok(validatePreparation(change(mutate)).length > 0);
    assert.ok(reviewProduction(manifest).findings.some(finding => finding.id === 'native-offsite_backup_credential_isolation'));
    assert.ok(validatePreparation(change(config => {config.secret_references.FF_RCLONE_CONFIG = 'forbidden';})).length > 0);
});
