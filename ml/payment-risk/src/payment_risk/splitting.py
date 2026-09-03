"""Chronological, complete-period dataset splitting."""

from __future__ import annotations

from dataclasses import dataclass
from datetime import date
from typing import Any

import numpy as np
from numpy.typing import NDArray

from payment_risk.contracts import FEATURE_NAMES, FeatureRow
from payment_risk.validation import DatasetValidationError


@dataclass(frozen=True, slots=True)
class MatrixPartition:
    """One chronological model partition."""

    rows: tuple[FeatureRow, ...]
    features: NDArray[np.float64]
    targets: NDArray[np.int64]
    periods: tuple[date, ...]


@dataclass(frozen=True, slots=True)
class ChronologicalSplit:
    """Atomic 60/20/20 split over complete periods."""

    training: MatrixPartition
    validation: MatrixPartition
    held_out: MatrixPartition
    report: dict[str, Any]


def _partition(rows: tuple[FeatureRow, ...], periods: tuple[date, ...]) -> MatrixPartition:
    selected = tuple(row for row in rows if row.period_start in periods)
    features = np.asarray([row.values for row in selected], dtype=np.float64)
    targets = np.asarray([row.target for row in selected], dtype=np.int64)
    if features.ndim != 2 or features.shape[1] != len(FEATURE_NAMES):
        raise DatasetValidationError("engineered feature matrix violates the feature contract")
    if set(targets.tolist()) != {0, 1}:
        raise DatasetValidationError("every chronological split must contain both target classes")
    return MatrixPartition(rows=selected, features=features, targets=targets, periods=periods)


def chronological_complete_period_split(rows: tuple[FeatureRow, ...]) -> ChronologicalSplit:
    """Keep every period intact while assigning periods in chronological order."""

    periods = tuple(sorted({row.period_start for row in rows}))
    period_count = len(periods)
    training_count = int(period_count * 0.60)
    validation_count = int(period_count * 0.20)
    held_out_count = period_count - training_count - validation_count
    if min(training_count, validation_count, held_out_count) < 1:
        raise DatasetValidationError("60/20/20 split requires non-empty chronological partitions")

    training_periods = periods[:training_count]
    validation_periods = periods[training_count : training_count + validation_count]
    held_out_periods = periods[training_count + validation_count :]
    training = _partition(rows, training_periods)
    validation = _partition(rows, validation_periods)
    held_out = _partition(rows, held_out_periods)
    report = {
        "policy": "chronological_complete_period_60_20_20",
        "training": {
            "period_count": len(training_periods),
            "row_count": len(training.rows),
            "start": training_periods[0].isoformat(),
            "end": training_periods[-1].isoformat(),
        },
        "validation": {
            "period_count": len(validation_periods),
            "row_count": len(validation.rows),
            "start": validation_periods[0].isoformat(),
            "end": validation_periods[-1].isoformat(),
        },
        "held_out": {
            "period_count": len(held_out_periods),
            "row_count": len(held_out.rows),
            "start": held_out_periods[0].isoformat(),
            "end": held_out_periods[-1].isoformat(),
        },
    }
    return ChronologicalSplit(
        training=training,
        validation=validation,
        held_out=held_out,
        report=report,
    )
