"""End-to-end training pipeline and evidence output orchestration."""

from __future__ import annotations

from dataclasses import dataclass
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

from payment_risk.artifact import (
    build_model_artifact,
    ensure_private_directory,
    write_checksum_manifest,
    write_json,
)
from payment_risk.contracts import BOOTSTRAP_SAMPLES
from payment_risk.evaluation import build_evaluation, write_evaluation_charts
from payment_risk.features import engineer_feature_rows
from payment_risk.modeling import train_logistic_model
from payment_risk.splitting import chronological_complete_period_split
from payment_risk.validation import (
    DataQualityGateError,
    DatasetValidationError,
    load_validated_source,
)


@dataclass(frozen=True, slots=True)
class TrainingConfig:
    """Explicit, deterministic training inputs."""

    input_csv: Path
    provenance_json: Path
    output_directory: Path
    bootstrap_samples: int = BOOTSTRAP_SAMPLES
    generated_at: datetime | None = None


@dataclass(frozen=True, slots=True)
class TrainingResult:
    """Generated model and evidence paths."""

    artifact: dict[str, Any]
    model_path: Path
    evaluation_path: Path
    data_quality_path: Path
    chart_paths: tuple[Path, ...]
    checksum_path: Path


def _prepare_output_directory(path: Path) -> Path:
    resolved = path.resolve()
    repository_root = _repository_root_for(resolved)
    if repository_root is not None:
        private_output_root = repository_root / "storage" / "app" / "private" / "payment-risk"
        if not resolved.is_relative_to(private_output_root):
            raise DatasetValidationError(
                "output directory inside a repository must be under "
                "storage/app/private/payment-risk"
            )
    if resolved.exists() and any(resolved.iterdir()):
        raise DatasetValidationError("output directory must be absent or empty")
    ensure_private_directory(resolved)
    return resolved


def _repository_root_for(path: Path) -> Path | None:
    for candidate in (path, *path.parents):
        if (candidate / ".git").exists():
            return candidate
    return None


def train_model(config: TrainingConfig) -> TrainingResult:
    """Validate, train, evaluate, serialise, chart, and checksum one model."""

    if config.bootstrap_samples <= 0:
        raise ValueError("bootstrap_samples must be positive")
    try:
        validated = load_validated_source(config.input_csv, config.provenance_json)
    except DataQualityGateError as error:
        output_directory = _prepare_output_directory(config.output_directory)
        write_json(
            output_directory / "data-quality.json",
            {"data_quality": error.data_quality},
        )
        raise
    feature_rows = engineer_feature_rows(validated.rows)
    split = chronological_complete_period_split(feature_rows)
    trained = train_logistic_model(split)
    evaluation = build_evaluation(
        split,
        trained,
        validated.provenance,
        validated.data_quality,
        bootstrap_samples=config.bootstrap_samples,
    )
    generated_at = config.generated_at or datetime.now(UTC)
    if generated_at.tzinfo is None or generated_at.utcoffset() is None:
        raise ValueError("generated_at must include a timezone offset")
    artifact = build_model_artifact(
        split,
        trained,
        validated.provenance,
        validated.data_quality,
        evaluation,
        generated_at=generated_at.astimezone(UTC),
    )

    output_directory = _prepare_output_directory(config.output_directory)
    model_path = output_directory / "model.json"
    evaluation_path = output_directory / "evaluation.json"
    data_quality_path = output_directory / "data-quality.json"
    write_json(model_path, artifact)
    write_json(
        evaluation_path,
        {
            "model_version": artifact["model_version"],
            "generated_at": artifact["generated_at"],
            "evaluation": evaluation,
        },
    )
    write_json(
        data_quality_path,
        {
            "model_version": artifact["model_version"],
            "generated_at": artifact["generated_at"],
            "data_quality": validated.data_quality,
        },
    )
    chart_paths = write_evaluation_charts(output_directory / "charts", split, trained)
    checksum_path = write_checksum_manifest(
        output_directory,
        (model_path, evaluation_path, data_quality_path, *chart_paths),
    )
    return TrainingResult(
        artifact=artifact,
        model_path=model_path,
        evaluation_path=evaluation_path,
        data_quality_path=data_quality_path,
        chart_paths=chart_paths,
        checksum_path=checksum_path,
    )
