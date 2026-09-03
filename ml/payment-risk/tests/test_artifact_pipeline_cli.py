from __future__ import annotations

import copy
import json
import stat
from datetime import UTC, datetime
from pathlib import Path
from types import SimpleNamespace

import pytest

from payment_risk.artifact import (
    ARTIFACT_TOP_LEVEL_KEYS,
    ArtifactValidationError,
    _coefficient_sign,
    load_artifact,
    score_artifact,
    validate_artifact,
    verify_checksum_manifest,
    write_checksum_manifest,
)
from payment_risk.cli import _read_feature_json, _run, main
from payment_risk.contracts import FEATURE_NAMES
from payment_risk.pipeline import (
    TrainingConfig,
    TrainingResult,
    _prepare_output_directory,
    train_model,
)
from payment_risk.validation import DataQualityGateError, DatasetValidationError

FIXTURE_PATH = Path(__file__).parent / "fixtures" / "golden-scoring.json"


def _golden() -> dict:
    return json.loads(FIXTURE_PATH.read_text(encoding="utf-8"))


def test_golden_fixture_has_cross_language_stable_scoring_math():
    fixture = _golden()

    result = score_artifact(fixture["artifact"], fixture["feature_vector"])

    expected = fixture["expected"]
    tolerance = fixture["absolute_tolerance"]
    assert result["linear_score"] == pytest.approx(expected["linear_score"], abs=tolerance)
    assert result["probability"] == pytest.approx(expected["probability"], abs=tolerance)
    assert result["predicted_class"] == expected["predicted_class"]
    assert result["advisory_band"] == expected["advisory_band"]
    assert result["threshold"] == expected["threshold"]
    assert [factor["feature"] for factor in result["top_factors"]] == expected[
        "top_factor_features"
    ]

    positive = copy.deepcopy(fixture["artifact"])
    positive["model"]["intercept"] = 5
    assert score_artifact(positive, fixture["feature_vector"])["advisory_band"] == "priority_review"


def test_model_artifact_validation_is_strict_and_finite():
    fixture = _golden()
    artifact = fixture["artifact"]
    validate_artifact(artifact)
    assert frozenset(artifact) == ARTIFACT_TOP_LEVEL_KEYS
    assert _coefficient_sign(1) == "increases_overdue_risk"
    assert _coefficient_sign(-1) == "reduces_overdue_risk"
    assert _coefficient_sign(0) == "neutral"

    mutations = [
        (lambda value: value.update({"extra": True}), "top-level"),
        (lambda value: value.update({"artifact_schema_version": "v0"}), "unsupported"),
        (lambda value: value.update({"training": []}), "feature_names"),
        (lambda value: value["training"].update({"feature_names": []}), "feature_names"),
        (lambda value: value.update({"preprocessing": []}), "sections"),
        (lambda value: value["preprocessing"].update({"mean": {}}), "must be arrays"),
        (lambda value: value["preprocessing"].update({"mean": []}), "must align"),
        (lambda value: value["preprocessing"]["mean"].__setitem__(0, float("nan")), "finite"),
        (lambda value: value["preprocessing"]["scale"].__setitem__(0, 0), "positive"),
        (lambda value: value["model"]["coefficients"].__setitem__(0, float("inf")), "finite"),
        (lambda value: value["model"].update({"intercept": True}), "numeric"),
        (lambda value: value["decision"].update({"threshold": 0}), "strictly between"),
        (lambda value: value["model"].update({"type": "tree"}), "unsupported"),
        (lambda value: value["explanation"].update({"factors": []}), "must align"),
        (
            lambda value: value["explanation"]["factors"][0].update({"feature": "wrong"}),
            "misaligned",
        ),
    ]
    for mutate, message in mutations:
        changed = copy.deepcopy(artifact)
        mutate(changed)
        with pytest.raises(ArtifactValidationError, match=message):
            validate_artifact(changed)


def test_scoring_rejects_missing_extra_and_nonfinite_features():
    fixture = _golden()
    features = fixture["feature_vector"]
    with pytest.raises(ArtifactValidationError, match="exactly match"):
        score_artifact(fixture["artifact"], {name: features[name] for name in FEATURE_NAMES[:-1]})
    invalid = dict(features)
    invalid[FEATURE_NAMES[0]] = float("nan")
    with pytest.raises(ArtifactValidationError, match="finite"):
        score_artifact(fixture["artifact"], invalid)


def test_scoring_breaks_equal_factor_magnitudes_by_feature_order():
    fixture = _golden()
    artifact = copy.deepcopy(fixture["artifact"])
    artifact["preprocessing"]["mean"] = [0.0] * len(FEATURE_NAMES)
    artifact["preprocessing"]["scale"] = [1.0] * len(FEATURE_NAMES)
    artifact["model"]["coefficients"] = [0.0] * len(FEATURE_NAMES)
    features = dict.fromkeys(FEATURE_NAMES, 0.0)

    result = score_artifact(artifact, features)

    assert [factor["feature"] for factor in result["top_factors"]] == list(FEATURE_NAMES[:3])


def test_artifact_round_trip_and_invalid_json_errors(tmp_path):
    fixture = _golden()
    model_path = tmp_path / "model.json"
    model_path.write_text(json.dumps(fixture["artifact"]), encoding="utf-8")
    assert load_artifact(model_path) == fixture["artifact"]

    model_path.write_text("not-json", encoding="utf-8")
    with pytest.raises(ArtifactValidationError, match="readable UTF-8 JSON"):
        load_artifact(model_path)
    model_path.write_text("[]", encoding="utf-8")
    with pytest.raises(ArtifactValidationError, match="JSON object"):
        load_artifact(model_path)


def test_pipeline_writes_portable_evidence_without_copying_source_csv(dataset_factory, tmp_path):
    csv_path, provenance_path, _ = dataset_factory()
    output = tmp_path / "evidence"

    result = train_model(
        TrainingConfig(
            input_csv=csv_path,
            provenance_json=provenance_path,
            output_directory=output,
            bootstrap_samples=10,
            generated_at=datetime(2026, 8, 10, tzinfo=UTC),
        )
    )

    assert result.model_path.name == "model.json"
    assert result.evaluation_path.name == "evaluation.json"
    assert result.data_quality_path.name == "data-quality.json"
    assert result.checksum_path.name == "checksums.sha256"
    assert len(result.chart_paths) == 6
    assert not list(output.rglob("*.csv"))
    assert verify_checksum_manifest(output, result.checksum_path)
    artifact = load_artifact(result.model_path)
    assert artifact["training"]["feature_names"] == list(FEATURE_NAMES)
    assert 0 <= artifact["training"]["overdue_prevalence"] <= 1
    assert artifact["evaluation"]["activation_eligible"] is False
    assert artifact["evaluation"]["held_out"]["overdue_f1"] >= 0
    assert artifact["evaluation"]["baselines"]["training_prevalence"]["brier_score"] >= 0
    assert json.loads(result.evaluation_path.read_text())["evaluation"] == artifact["evaluation"]
    assert json.loads(result.data_quality_path.read_text())["data_quality"]["gate_passed"]
    assert stat.S_IMODE(output.stat().st_mode) == 0o700
    assert stat.S_IMODE((output / "charts").stat().st_mode) == 0o700
    for generated_file in (
        result.model_path,
        result.evaluation_path,
        result.data_quality_path,
        result.checksum_path,
        *result.chart_paths,
    ):
        assert stat.S_IMODE(generated_file.stat().st_mode) == 0o600


def test_output_directory_is_private_and_repository_local_paths_are_restricted(tmp_path):
    repository = tmp_path / "repository"
    (repository / ".git").mkdir(parents=True)
    forbidden = repository / "ml" / "payment-risk" / "evidence"

    with pytest.raises(DatasetValidationError, match="storage/app/private/payment-risk"):
        _prepare_output_directory(forbidden)

    assert not forbidden.exists()

    private_root = repository / "storage" / "app" / "private" / "payment-risk"
    allowed = private_root / "run-1"
    assert _prepare_output_directory(allowed) == allowed.resolve()
    for private_directory in (
        repository / "storage",
        repository / "storage" / "app",
        repository / "storage" / "app" / "private",
        private_root,
        allowed,
    ):
        assert stat.S_IMODE(private_directory.stat().st_mode) == 0o700

    external_parent = tmp_path / "offline"
    external = external_parent / "evidence"
    assert _prepare_output_directory(external) == external.resolve()
    assert stat.S_IMODE(external_parent.stat().st_mode) == 0o700
    assert stat.S_IMODE(external.stat().st_mode) == 0o700


def test_failed_quality_gate_preserves_only_private_aggregate_evidence_and_cli_report(
    dataset_factory,
    tmp_path,
    capsys,
):
    csv_path, provenance_path, _ = dataset_factory(histories=49)
    direct_output = tmp_path / "failed-direct"

    with pytest.raises(DataQualityGateError, match="minimum data-quality") as caught:
        train_model(
            TrainingConfig(
                csv_path,
                provenance_path,
                direct_output,
                bootstrap_samples=1,
            )
        )

    report = caught.value.data_quality
    assert report["valid_row_count"] == 588
    assert report["distinct_member_count"] == 49
    assert report["gate_passed"] is False
    assert report["gates"]["minimum_distinct_members"] is False
    assert [path.name for path in direct_output.iterdir()] == ["data-quality.json"]
    quality_path = direct_output / "data-quality.json"
    assert json.loads(quality_path.read_text(encoding="utf-8")) == {"data_quality": report}
    assert stat.S_IMODE(direct_output.stat().st_mode) == 0o700
    assert stat.S_IMODE(quality_path.stat().st_mode) == 0o600

    assert (
        main(
            [
                "validate",
                "--input-csv",
                str(csv_path),
                "--provenance",
                str(provenance_path),
            ]
        )
        == 2
    )
    validate_error = json.loads(capsys.readouterr().err)
    assert validate_error["data_quality"] == report

    cli_output = tmp_path / "failed-cli"
    assert (
        main(
            [
                "train",
                "--input-csv",
                str(csv_path),
                "--provenance",
                str(provenance_path),
                "--output-dir",
                str(cli_output),
            ]
        )
        == 2
    )
    train_error = json.loads(capsys.readouterr().err)
    assert train_error["data_quality"] == report
    assert [path.name for path in cli_output.iterdir()] == ["data-quality.json"]


def test_pipeline_refuses_invalid_runtime_or_destructive_output(dataset_factory, tmp_path):
    csv_path, provenance_path, _ = dataset_factory()
    with pytest.raises(ValueError, match="bootstrap_samples"):
        train_model(
            TrainingConfig(csv_path, provenance_path, tmp_path / "bad", bootstrap_samples=0)
        )
    with pytest.raises(ValueError, match="timezone offset"):
        train_model(
            TrainingConfig(
                csv_path,
                provenance_path,
                tmp_path / "bad-time",
                bootstrap_samples=1,
                generated_at=datetime(2026, 8, 10),
            )
        )

    nonempty = tmp_path / "nonempty"
    nonempty.mkdir()
    (nonempty / "keep.txt").write_text("keep", encoding="utf-8")
    with pytest.raises(DatasetValidationError, match="absent or empty"):
        train_model(
            TrainingConfig(
                csv_path,
                provenance_path,
                nonempty,
                bootstrap_samples=1,
            )
        )


def test_checksum_manifest_rejects_tampering_malformed_and_traversal(tmp_path):
    evidence = tmp_path / "evidence"
    evidence.mkdir()
    first = evidence / "a.json"
    first.write_text("{}", encoding="utf-8")
    manifest = write_checksum_manifest(evidence, (first,))
    assert verify_checksum_manifest(evidence, manifest)

    first.write_text("tampered", encoding="utf-8")
    assert not verify_checksum_manifest(evidence, manifest)
    manifest.write_text("", encoding="utf-8")
    assert not verify_checksum_manifest(evidence, manifest)
    manifest.write_text("malformed\n", encoding="utf-8")
    assert not verify_checksum_manifest(evidence, manifest)
    manifest.write_text(f"{'0' * 64}  ../outside\n", encoding="utf-8")
    assert not verify_checksum_manifest(evidence, manifest)
    assert not verify_checksum_manifest(evidence, evidence)


def test_cli_dispatches_validate_score_train_verify_and_errors(
    dataset_factory, tmp_path, monkeypatch, capsys
):
    csv_path, provenance_path, _ = dataset_factory()
    assert (
        main(
            [
                "validate",
                "--input-csv",
                str(csv_path),
                "--provenance",
                str(provenance_path),
            ]
        )
        == 0
    )
    assert json.loads(capsys.readouterr().out)["data_quality"]["gate_passed"]

    fixture = _golden()
    model_path = tmp_path / "golden-model.json"
    features_path = tmp_path / "features.json"
    model_path.write_text(json.dumps(fixture["artifact"]), encoding="utf-8")
    features_path.write_text(json.dumps(fixture["feature_vector"]), encoding="utf-8")
    assert main(["score", "--model", str(model_path), "--features-json", str(features_path)]) == 0
    assert json.loads(capsys.readouterr().out)["advisory_band"] == "routine_review"

    output = tmp_path / "verified"
    output.mkdir()
    evidence = output / "evidence.json"
    evidence.write_text("{}", encoding="utf-8")
    write_checksum_manifest(output, (evidence,))
    assert main(["verify", "--output-dir", str(output)]) == 0
    assert json.loads(capsys.readouterr().out)["verified"] is True

    fake_result = TrainingResult(
        artifact={"evaluation": {"activation_eligible": False}},
        model_path=tmp_path / "model.json",
        evaluation_path=tmp_path / "evaluation.json",
        data_quality_path=tmp_path / "quality.json",
        chart_paths=(),
        checksum_path=tmp_path / "checksums.sha256",
    )
    monkeypatch.setattr("payment_risk.cli.train_model", lambda _config: fake_result)
    response = _run(
        SimpleNamespace(
            command="train",
            input_csv=csv_path,
            provenance=provenance_path,
            output_dir=tmp_path / "unused",
        )
    )
    assert response["activation_eligible"] is False

    assert main(["verify", "--output-dir", str(tmp_path / "missing")]) == 2
    assert "checksum verification failed" in capsys.readouterr().err


def test_cli_feature_json_errors_and_value_error_handling(tmp_path, monkeypatch, capsys):
    bad = tmp_path / "bad.json"
    bad.write_text("not-json", encoding="utf-8")
    with pytest.raises(ArtifactValidationError, match="readable UTF-8 JSON"):
        _read_feature_json(bad)
    bad.write_text("[]", encoding="utf-8")
    with pytest.raises(ArtifactValidationError, match="JSON object"):
        _read_feature_json(bad)

    monkeypatch.setattr(
        "payment_risk.cli._run", lambda _arguments: (_ for _ in ()).throw(ValueError("bad"))
    )
    assert main(["verify", "--output-dir", str(tmp_path)]) == 2
    assert json.loads(capsys.readouterr().err)["error"] == "bad"
