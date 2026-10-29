"""Command-line interface for validation, training, scoring, and verification."""

from __future__ import annotations

import argparse
import json
import sys
from collections.abc import Sequence
from pathlib import Path
from typing import Any

from payment_risk.artifact import (
    ArtifactValidationError,
    load_artifact,
    score_artifact,
    verify_checksum_manifest,
)
from payment_risk.features import engineer_feature_rows
from payment_risk.pipeline import TrainingConfig, train_model
from payment_risk.splitting import chronological_complete_period_split
from payment_risk.validation import (
    DataQualityGateError,
    DatasetValidationError,
    load_validated_source,
)


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="payment-risk")
    subparsers = parser.add_subparsers(dest="command", required=True)

    validate = subparsers.add_parser("validate", help="validate a private source export")
    validate.add_argument("--input-csv", required=True, type=Path)
    validate.add_argument("--provenance", required=True, type=Path)

    train = subparsers.add_parser("train", help="train and evaluate a portable model")
    train.add_argument("--input-csv", required=True, type=Path)
    train.add_argument("--provenance", required=True, type=Path)
    train.add_argument("--output-dir", required=True, type=Path)

    score = subparsers.add_parser("score", help="score a JSON feature vector")
    score.add_argument("--model", required=True, type=Path)
    score.add_argument("--features-json", required=True, type=Path)

    verify = subparsers.add_parser("verify", help="verify an output checksum manifest")
    verify.add_argument("--output-dir", required=True, type=Path)
    return parser


def _read_feature_json(path: Path) -> dict[str, int | float]:
    try:
        payload: Any = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as error:
        raise ArtifactValidationError("features must be readable UTF-8 JSON") from error
    if not isinstance(payload, dict):
        raise ArtifactValidationError("features must be a JSON object")
    return payload


def _run(arguments: argparse.Namespace) -> dict[str, Any]:
    if arguments.command == "validate":
        validated = load_validated_source(arguments.input_csv, arguments.provenance)
        split = chronological_complete_period_split(engineer_feature_rows(validated.rows))
        return {"data_quality": validated.data_quality, "split": split.report}
    if arguments.command == "train":
        result = train_model(
            TrainingConfig(
                input_csv=arguments.input_csv,
                provenance_json=arguments.provenance,
                output_directory=arguments.output_dir,
            )
        )
        return {
            "model": str(result.model_path),
            "activation_eligible": result.artifact["evaluation"]["activation_eligible"],
            "checksums": str(result.checksum_path),
        }
    if arguments.command == "score":
        return score_artifact(
            load_artifact(arguments.model),
            _read_feature_json(arguments.features_json),
        )
    output_directory = arguments.output_dir.resolve()
    manifest = output_directory / "checksums.sha256"
    if not verify_checksum_manifest(output_directory, manifest):
        raise ArtifactValidationError("checksum verification failed")
    return {"verified": True, "manifest": str(manifest)}


def main(argv: Sequence[str] | None = None) -> int:
    """Run the CLI and return a process exit code."""

    try:
        result = _run(_parser().parse_args(argv))
    except (ArtifactValidationError, DatasetValidationError, ValueError) as error:
        payload: dict[str, Any] = {"error": str(error)}
        if isinstance(error, DataQualityGateError):
            payload["data_quality"] = error.data_quality
        print(json.dumps(payload, sort_keys=True), file=sys.stderr)
        return 2
    print(json.dumps(result, allow_nan=False, sort_keys=True))
    return 0
