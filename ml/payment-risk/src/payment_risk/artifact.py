"""Portable JSON model artifact construction, validation, and scoring."""

from __future__ import annotations

import hashlib
import json
import math
import os
import re
from collections.abc import Mapping
from datetime import datetime
from pathlib import Path
from typing import Any, Final

import numpy as np

from payment_risk.contracts import (
    ARTIFACT_SCHEMA_VERSION,
    FEATURE_CONTRACT_VERSION,
    FEATURE_LABELS,
    FEATURE_NAMES,
    RANDOM_SEED,
    DatasetProvenance,
)
from payment_risk.modeling import TrainedModel
from payment_risk.splitting import ChronologicalSplit

ARTIFACT_TOP_LEVEL_KEYS: Final = frozenset(
    {
        "artifact_schema_version",
        "model_version",
        "generated_at",
        "training",
        "data_quality",
        "preprocessing",
        "model",
        "decision",
        "evaluation",
        "explanation",
    }
)
PRIVATE_DIRECTORY_MODE: Final = 0o700
PRIVATE_FILE_MODE: Final = 0o600


class ArtifactValidationError(ValueError):
    """Raised when a portable artifact violates its stable contract."""


def _canonical_json_bytes(payload: Any) -> bytes:
    canonical = json.dumps(payload, allow_nan=False, sort_keys=True, separators=(",", ":"))
    return f"{canonical}\n".encode()


def ensure_private_directory(path: Path) -> None:
    """Create missing output directories with owner-only permissions."""

    missing = []
    candidate = path
    while not candidate.exists():
        missing.append(candidate)
        candidate = candidate.parent
    for directory in reversed(missing):
        directory.mkdir(mode=PRIVATE_DIRECTORY_MODE)
    for directory in missing:
        directory.chmod(PRIVATE_DIRECTORY_MODE)
    path.chmod(PRIVATE_DIRECTORY_MODE)


def _write_private_bytes(path: Path, contents: bytes) -> None:
    descriptor = os.open(
        path,
        os.O_WRONLY | os.O_CREAT | os.O_TRUNC,
        PRIVATE_FILE_MODE,
    )
    with os.fdopen(descriptor, "wb") as handle:
        handle.write(contents)
    path.chmod(PRIVATE_FILE_MODE)


def _model_version(
    split: ChronologicalSplit,
    trained: TrainedModel,
) -> str:
    version_payload = {
        "feature_names": FEATURE_NAMES,
        "window": split.report["training"],
        "mean": trained.scaler.mean_.tolist(),
        "scale": trained.scaler.scale_.tolist(),
        "intercept": trained.classifier.intercept_.tolist(),
        "coefficients": trained.classifier.coef_.tolist(),
        "threshold": trained.threshold,
    }
    digest = hashlib.sha256(_canonical_json_bytes(version_payload)).hexdigest()[:12]
    start = split.report["training"]["start"].replace("-", "")
    end = split.report["training"]["end"].replace("-", "")
    return f"payment-risk-{start}-{end}-{digest}"


def _coefficient_sign(coefficient: float) -> str:
    if coefficient > 0:
        return "increases_overdue_risk"
    if coefficient < 0:
        return "reduces_overdue_risk"
    return "neutral"


def build_model_artifact(
    split: ChronologicalSplit,
    trained: TrainedModel,
    provenance: DatasetProvenance,
    data_quality: dict[str, Any],
    evaluation: dict[str, Any],
    *,
    generated_at: datetime,
) -> dict[str, Any]:
    """Build the PHP-consumable artifact without embedding a checksum."""

    coefficients = [float(value) for value in trained.classifier.coef_[0]]
    artifact = {
        "artifact_schema_version": ARTIFACT_SCHEMA_VERSION,
        "model_version": _model_version(split, trained),
        "generated_at": generated_at.isoformat(),
        "training": {
            "dataset_id": provenance.dataset_id,
            "source_type": provenance.source_type,
            "feature_contract_version": FEATURE_CONTRACT_VERSION,
            "feature_names": list(FEATURE_NAMES),
            "overdue_prevalence": float(np.mean(split.training.targets)),
            "seed": RANDOM_SEED,
            "window": {
                "start": split.report["training"]["start"],
                "end": split.report["training"]["end"],
            },
            "split": split.report,
        },
        "data_quality": data_quality,
        "preprocessing": {
            "type": "z_score_standardization",
            "formula": "standardized=(value-mean)/scale",
            "mean": [float(value) for value in trained.scaler.mean_],
            "scale": [float(value) for value in trained.scaler.scale_],
        },
        "model": {
            "type": "l2_logistic_regression",
            "penalty": "l2",
            "class_weight": "balanced",
            "solver": "liblinear",
            "positive_class": "overdue",
            "intercept": float(trained.classifier.intercept_[0]),
            "coefficients": coefficients,
        },
        "decision": {
            "threshold": float(trained.threshold),
            "positive_class": "overdue",
            "negative_class": "on_time",
            "risk_bands": [
                {
                    "label": "routine_review",
                    "minimum_inclusive": 0.0,
                    "maximum_exclusive": float(trained.threshold),
                },
                {
                    "label": "priority_review",
                    "minimum_inclusive": float(trained.threshold),
                    "maximum_inclusive": 1.0,
                },
            ],
        },
        "evaluation": evaluation,
        "explanation": {
            "coefficient_space": "standardized_feature_space",
            "factors": [
                {
                    "feature": feature,
                    "label": FEATURE_LABELS[feature],
                    "coefficient": coefficient,
                    "sign": _coefficient_sign(coefficient),
                }
                for feature, coefficient in zip(FEATURE_NAMES, coefficients, strict=True)
            ],
        },
    }
    validate_artifact(artifact)
    return artifact


def _finite_number(value: Any, field: str) -> float:
    if isinstance(value, bool) or not isinstance(value, int | float):
        raise ArtifactValidationError(f"{field} must be numeric")
    numeric = float(value)
    if not math.isfinite(numeric):
        raise ArtifactValidationError(f"{field} must be finite")
    return numeric


def validate_artifact(artifact: Mapping[str, Any]) -> None:
    """Validate the stable subset required for safe cross-language scoring."""

    if frozenset(artifact) != ARTIFACT_TOP_LEVEL_KEYS:
        raise ArtifactValidationError("model artifact top-level keys do not match the contract")
    if artifact["artifact_schema_version"] != ARTIFACT_SCHEMA_VERSION:
        raise ArtifactValidationError("unsupported artifact_schema_version")
    training = artifact["training"]
    if not isinstance(training, dict) or training.get("feature_names") != list(FEATURE_NAMES):
        raise ArtifactValidationError("training.feature_names must match the ordered contract")
    preprocessing = artifact["preprocessing"]
    model = artifact["model"]
    decision = artifact["decision"]
    explanation = artifact["explanation"]
    if not all(isinstance(value, dict) for value in (preprocessing, model, decision, explanation)):
        raise ArtifactValidationError("artifact sections must be JSON objects")

    mean = preprocessing.get("mean")
    scale = preprocessing.get("scale")
    coefficients = model.get("coefficients")
    if not all(isinstance(value, list) for value in (mean, scale, coefficients)):
        raise ArtifactValidationError("mean, scale, and coefficients must be arrays")
    if not len(mean) == len(scale) == len(coefficients) == len(FEATURE_NAMES):
        raise ArtifactValidationError("model arrays must align with training.feature_names")
    for index, value in enumerate(mean):
        _finite_number(value, f"preprocessing.mean[{index}]")
    for index, value in enumerate(scale):
        if _finite_number(value, f"preprocessing.scale[{index}]") <= 0:
            raise ArtifactValidationError("preprocessing scales must be positive")
    for index, value in enumerate(coefficients):
        _finite_number(value, f"model.coefficients[{index}]")
    _finite_number(model.get("intercept"), "model.intercept")
    threshold = _finite_number(decision.get("threshold"), "decision.threshold")
    if not 0 < threshold < 1:
        raise ArtifactValidationError("decision.threshold must be strictly between zero and one")
    if model.get("type") != "l2_logistic_regression" or model.get("positive_class") != "overdue":
        raise ArtifactValidationError("model type or positive class is unsupported")

    factors = explanation.get("factors")
    if not isinstance(factors, list) or len(factors) != len(FEATURE_NAMES):
        raise ArtifactValidationError("explanation factors must align with features")
    for index, (factor, feature) in enumerate(zip(factors, FEATURE_NAMES, strict=True)):
        if not isinstance(factor, dict) or factor.get("feature") != feature:
            raise ArtifactValidationError(f"explanation factor {index} is misaligned")


def score_artifact(
    artifact: Mapping[str, Any], features: Mapping[str, int | float]
) -> dict[str, Any]:
    """Score one feature vector with cross-language deterministic mathematics."""

    validate_artifact(artifact)
    if frozenset(features) != frozenset(FEATURE_NAMES):
        raise ArtifactValidationError("scoring features must exactly match the ordered contract")
    vector = np.asarray(
        [_finite_number(features[name], f"features.{name}") for name in FEATURE_NAMES],
        dtype=np.float64,
    )
    mean = np.asarray(artifact["preprocessing"]["mean"], dtype=np.float64)
    scale = np.asarray(artifact["preprocessing"]["scale"], dtype=np.float64)
    coefficients = np.asarray(artifact["model"]["coefficients"], dtype=np.float64)
    standardized = (vector - mean) / scale
    contributions = standardized * coefficients
    linear_score = float(artifact["model"]["intercept"] + np.sum(contributions))
    probability = (
        1.0 / (1.0 + math.exp(-linear_score))
        if linear_score >= 0
        else math.exp(linear_score) / (1.0 + math.exp(linear_score))
    )
    threshold = float(artifact["decision"]["threshold"])
    priority = probability >= threshold
    factor_order = sorted(
        range(len(FEATURE_NAMES)),
        key=lambda index: (-abs(contributions[index]), index),
    )[:3]
    return {
        "linear_score": linear_score,
        "probability": probability,
        "predicted_class": "overdue" if priority else "on_time",
        "advisory_band": "priority_review" if priority else "routine_review",
        "threshold": threshold,
        "top_factors": [
            {
                "feature": FEATURE_NAMES[index],
                "contribution": float(contributions[index]),
            }
            for index in factor_order
        ],
    }


def write_json(path: Path, payload: Any) -> None:
    """Write canonical, finite JSON."""

    _write_private_bytes(path, _canonical_json_bytes(payload))


def load_artifact(path: Path) -> dict[str, Any]:
    """Load and validate an artifact JSON file."""

    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as error:
        raise ArtifactValidationError("model artifact must be readable UTF-8 JSON") from error
    if not isinstance(payload, dict):
        raise ArtifactValidationError("model artifact must be a JSON object")
    validate_artifact(payload)
    return payload


def write_checksum_manifest(root: Path, paths: tuple[Path, ...]) -> Path:
    """Write external checksums for every generated evidence file."""

    lines = []
    for path in sorted(paths):
        digest = hashlib.sha256(path.read_bytes()).hexdigest()
        lines.append(f"{digest}  {path.relative_to(root).as_posix()}")
    manifest = root / "checksums.sha256"
    _write_private_bytes(manifest, ("\n".join(lines) + "\n").encode())
    return manifest


def verify_checksum_manifest(root: Path, manifest: Path) -> bool:
    """Verify every exact digest listed in a checksum manifest."""

    try:
        lines = manifest.read_text(encoding="utf-8").splitlines()
    except (OSError, UnicodeDecodeError):
        return False
    if not lines:
        return False
    for line in lines:
        parts = line.split("  ", maxsplit=1)
        if len(parts) != 2 or HASH_PATTERN.fullmatch(parts[0]) is None:
            return False
        candidate = (root / parts[1]).resolve()
        if not candidate.is_relative_to(root.resolve()) or not candidate.is_file():
            return False
        if hashlib.sha256(candidate.read_bytes()).hexdigest() != parts[0]:
            return False
    return True


HASH_PATTERN: Final = re.compile(r"[0-9a-f]{64}\Z")
