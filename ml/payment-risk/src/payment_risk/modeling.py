"""Deterministic class-balanced logistic-regression training."""

from __future__ import annotations

from dataclasses import dataclass

import numpy as np
from numpy.typing import NDArray
from sklearn.linear_model import LogisticRegression
from sklearn.preprocessing import StandardScaler

from payment_risk.contracts import MINIMUM_VALIDATION_RECALL, RANDOM_SEED
from payment_risk.splitting import ChronologicalSplit


@dataclass(frozen=True, slots=True)
class TrainedModel:
    """Fitted preprocessing, classifier, and validation-selected decision threshold."""

    scaler: StandardScaler
    classifier: LogisticRegression
    threshold: float
    validation_probabilities: NDArray[np.float64]
    held_out_probabilities: NDArray[np.float64]


def _classification_counts(
    targets: NDArray[np.int64], probabilities: NDArray[np.float64], threshold: float
) -> tuple[int, int, int]:
    predictions = probabilities >= threshold
    positives = targets == 1
    true_positives = int(np.sum(predictions & positives))
    false_positives = int(np.sum(predictions & ~positives))
    false_negatives = int(np.sum(~predictions & positives))
    return true_positives, false_positives, false_negatives


def select_validation_threshold(
    targets: NDArray[np.int64], probabilities: NDArray[np.float64]
) -> float:
    """Maximise overdue F1 subject to recall >= 0.70, breaking ties by precision."""

    if not np.any(targets == 1):
        raise ValueError("validation targets must contain overdue observations")
    epsilon = np.finfo(np.float64).eps
    candidates = np.unique(
        np.concatenate(
            (
                np.asarray([epsilon]),
                np.clip(probabilities, epsilon, 1.0 - epsilon),
            )
        )
    )
    best_key: tuple[float, float, float] | None = None
    best_threshold = 0.0
    for candidate in candidates:
        true_positives, false_positives, false_negatives = _classification_counts(
            targets, probabilities, float(candidate)
        )
        recall = true_positives / (true_positives + false_negatives)
        if recall < MINIMUM_VALIDATION_RECALL:
            continue
        precision_denominator = true_positives + false_positives
        precision = true_positives / precision_denominator if precision_denominator else 0.0
        f1_denominator = precision + recall
        overdue_f1 = 2 * precision * recall / f1_denominator if f1_denominator else 0.0
        key = (overdue_f1, precision, float(candidate))
        if best_key is None or key > best_key:
            best_key = key
            best_threshold = float(candidate)
    return best_threshold


def train_logistic_model(split: ChronologicalSplit) -> TrainedModel:
    """Fit an L2 logistic model on training periods and tune only on validation periods."""

    scaler = StandardScaler()
    training_features = scaler.fit_transform(split.training.features)
    classifier = LogisticRegression(
        C=1.0,
        class_weight="balanced",
        max_iter=2_000,
        penalty="l2",
        random_state=RANDOM_SEED,
        solver="liblinear",
    )
    classifier.fit(training_features, split.training.targets)
    validation_probabilities = classifier.predict_proba(
        scaler.transform(split.validation.features)
    )[:, 1]
    threshold = select_validation_threshold(split.validation.targets, validation_probabilities)
    held_out_probabilities = classifier.predict_proba(scaler.transform(split.held_out.features))[
        :, 1
    ]
    return TrainedModel(
        scaler=scaler,
        classifier=classifier,
        threshold=threshold,
        validation_probabilities=validation_probabilities,
        held_out_probabilities=held_out_probabilities,
    )
