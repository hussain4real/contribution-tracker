"""Model evaluation, baselines, uncertainty intervals, and charts."""

from __future__ import annotations

from pathlib import Path
from typing import Any

import numpy as np
from numpy.typing import NDArray
from sklearn.calibration import calibration_curve
from sklearn.metrics import (
    accuracy_score,
    average_precision_score,
    balanced_accuracy_score,
    brier_score_loss,
    confusion_matrix,
    f1_score,
    precision_recall_curve,
    precision_score,
    recall_score,
    roc_auc_score,
    roc_curve,
)

from payment_risk.artifact import PRIVATE_FILE_MODE, ensure_private_directory
from payment_risk.contracts import (
    ACTIVATION_POLICY_VERSION,
    BOOTSTRAP_SAMPLES,
    FEATURE_LABELS,
    FEATURE_NAMES,
    MINIMUM_VALIDATION_RECALL,
    RANDOM_SEED,
    DatasetProvenance,
)
from payment_risk.modeling import TrainedModel
from payment_risk.splitting import ChronologicalSplit


def _binary_metrics(
    targets: NDArray[np.int64], probabilities: NDArray[np.float64], threshold: float
) -> dict[str, Any]:
    predictions = (probabilities >= threshold).astype(np.int64)
    has_both_classes = len(np.unique(targets)) == 2
    return {
        "row_count": int(targets.size),
        "overdue_prevalence": float(np.mean(targets)),
        "threshold": float(threshold),
        "accuracy": float(accuracy_score(targets, predictions)),
        "balanced_accuracy": float(balanced_accuracy_score(targets, predictions))
        if has_both_classes
        else None,
        "precision_overdue": float(precision_score(targets, predictions, zero_division=0)),
        "recall_overdue": float(recall_score(targets, predictions, zero_division=0)),
        "overdue_f1": float(f1_score(targets, predictions, zero_division=0)),
        "roc_auc": float(roc_auc_score(targets, probabilities)) if has_both_classes else None,
        "pr_auc": float(average_precision_score(targets, probabilities))
        if has_both_classes
        else None,
        "brier_score": float(brier_score_loss(targets, probabilities)),
        "confusion_matrix": confusion_matrix(targets, predictions, labels=[0, 1]).tolist(),
    }


def _bootstrap_confidence_intervals(
    targets: NDArray[np.int64],
    probabilities: NDArray[np.float64],
    threshold: float,
    *,
    samples: int,
) -> dict[str, dict[str, float | int]]:
    metric_names = (
        "accuracy",
        "balanced_accuracy",
        "precision_overdue",
        "recall_overdue",
        "overdue_f1",
        "roc_auc",
        "pr_auc",
        "brier_score",
    )
    collected: dict[str, list[float]] = {name: [] for name in metric_names}
    generator = np.random.default_rng(RANDOM_SEED)
    for _ in range(samples):
        indices = generator.integers(0, targets.size, targets.size)
        metrics = _binary_metrics(targets[indices], probabilities[indices], threshold)
        for name in metric_names:
            value = metrics[name]
            if value is not None:
                collected[name].append(value)
    intervals: dict[str, dict[str, float | int]] = {}
    for name, values in collected.items():
        if not values:
            continue
        intervals[name] = {
            "confidence": 0.95,
            "lower": float(np.percentile(values, 2.5)),
            "upper": float(np.percentile(values, 97.5)),
            "samples": len(values),
        }
    return intervals


def _evaluate_partition(
    targets: NDArray[np.int64],
    probabilities: NDArray[np.float64],
    threshold: float,
    *,
    bootstrap_samples: int,
) -> dict[str, Any]:
    metrics = _binary_metrics(targets, probabilities, threshold)
    metrics["bootstrap_95_ci"] = _bootstrap_confidence_intervals(
        targets,
        probabilities,
        threshold,
        samples=bootstrap_samples,
    )
    return metrics


def build_evaluation(
    split: ChronologicalSplit,
    trained: TrainedModel,
    provenance: DatasetProvenance,
    data_quality: dict[str, Any],
    *,
    bootstrap_samples: int = BOOTSTRAP_SAMPLES,
) -> dict[str, Any]:
    """Evaluate validation/held-out periods and two deterministic baselines."""

    validation = _evaluate_partition(
        split.validation.targets,
        trained.validation_probabilities,
        trained.threshold,
        bootstrap_samples=bootstrap_samples,
    )
    held_out = _evaluate_partition(
        split.held_out.targets,
        trained.held_out_probabilities,
        trained.threshold,
        bootstrap_samples=bootstrap_samples,
    )

    training_prevalence = float(np.mean(split.training.targets))
    prevalence_probabilities = np.full_like(
        split.held_out.targets,
        training_prevalence,
        dtype=np.float64,
    )
    prevalence_baseline = _binary_metrics(
        split.held_out.targets,
        prevalence_probabilities,
        0.5,
    )
    overdue_streak_index = FEATURE_NAMES.index("overdue_streak")
    previous_period_probabilities = (split.held_out.features[:, overdue_streak_index] > 0).astype(
        np.float64
    )
    previous_period_baseline = _binary_metrics(
        split.held_out.targets,
        previous_period_probabilities,
        0.5,
    )

    baseline_f1 = max(
        prevalence_baseline["overdue_f1"],
        previous_period_baseline["overdue_f1"],
    )
    activation_checks = {
        "consented_anonymized_provenance": provenance.source_type == "consented_anonymized"
        and provenance.consent_confirmed,
        "data_quality_gate_passed": data_quality["gate_passed"],
        "validation_recall_at_least_0_70": validation["recall_overdue"]
        >= MINIMUM_VALIDATION_RECALL,
        "held_out_f1_beats_both_baselines": held_out["overdue_f1"] > baseline_f1,
        "held_out_balanced_accuracy_above_0_5": held_out["balanced_accuracy"] > 0.5,
        "held_out_brier_beats_training_prevalence": held_out["brier_score"]
        < prevalence_baseline["brier_score"],
    }
    return {
        "selection": {
            "objective": "max_overdue_f1_subject_to_recall_at_least_0_70_tie_precision",
            "validation_threshold": trained.threshold,
            "minimum_overdue_recall": MINIMUM_VALIDATION_RECALL,
        },
        "validation": validation,
        "held_out": held_out,
        "baselines": {
            "training_prevalence": prevalence_baseline,
            "previous_period_late": previous_period_baseline,
        },
        "activation_policy_version": ACTIVATION_POLICY_VERSION,
        "activation_checks": activation_checks,
        "activation_eligible": all(activation_checks.values()),
    }


def _save_figure(path: Path, pyplot: Any) -> None:
    pyplot.tight_layout()
    path.touch(mode=PRIVATE_FILE_MODE, exist_ok=True)
    path.chmod(PRIVATE_FILE_MODE)
    pyplot.savefig(path, dpi=160, bbox_inches="tight")
    path.chmod(PRIVATE_FILE_MODE)
    pyplot.close()


def write_evaluation_charts(
    output_directory: Path,
    split: ChronologicalSplit,
    trained: TrainedModel,
) -> tuple[Path, ...]:
    """Write deterministic held-out evaluation and coefficient charts."""

    import matplotlib  # noqa: PLC0415

    matplotlib.use("Agg")
    from matplotlib import pyplot  # noqa: PLC0415

    ensure_private_directory(output_directory)
    targets = split.held_out.targets
    probabilities = trained.held_out_probabilities
    predictions = (probabilities >= trained.threshold).astype(np.int64)
    paths: list[Path] = []

    class_distribution_path = output_directory / "class-distribution.png"
    partition_targets = (
        split.training.targets,
        split.validation.targets,
        split.held_out.targets,
    )
    on_time_counts = [int(np.sum(values == 0)) for values in partition_targets]
    overdue_counts = [int(np.sum(values == 1)) for values in partition_targets]
    labels = ["Training", "Validation", "Held out"]
    pyplot.figure(figsize=(6, 4))
    pyplot.bar(labels, on_time_counts, label="On time")
    pyplot.bar(labels, overdue_counts, bottom=on_time_counts, label="Overdue")
    pyplot.ylabel("Member-period observations")
    pyplot.title("Chronological split class distribution")
    pyplot.legend()
    _save_figure(class_distribution_path, pyplot)
    paths.append(class_distribution_path)

    confusion_path = output_directory / "confusion-matrix.png"
    matrix = confusion_matrix(targets, predictions, labels=[0, 1])
    pyplot.figure(figsize=(5, 4))
    pyplot.imshow(matrix, cmap="Blues")
    pyplot.title("Held-out confusion matrix")
    pyplot.xticks([0, 1], ["On time", "Overdue"])
    pyplot.yticks([0, 1], ["On time", "Overdue"])
    pyplot.xlabel("Predicted")
    pyplot.ylabel("Actual")
    for row_index in range(2):
        for column_index in range(2):
            pyplot.text(
                column_index,
                row_index,
                str(matrix[row_index, column_index]),
                ha="center",
            )
    _save_figure(confusion_path, pyplot)
    paths.append(confusion_path)

    roc_path = output_directory / "roc-curve.png"
    false_positive_rate, true_positive_rate, _ = roc_curve(targets, probabilities)
    pyplot.figure(figsize=(5, 4))
    pyplot.plot(false_positive_rate, true_positive_rate, label="Logistic model")
    pyplot.plot([0, 1], [0, 1], linestyle="--", label="Chance")
    pyplot.xlabel("False-positive rate")
    pyplot.ylabel("True-positive rate")
    pyplot.title("Held-out ROC curve")
    pyplot.legend()
    _save_figure(roc_path, pyplot)
    paths.append(roc_path)

    precision_recall_path = output_directory / "precision-recall-curve.png"
    precision, recall, _ = precision_recall_curve(targets, probabilities)
    pyplot.figure(figsize=(5, 4))
    pyplot.plot(recall, precision)
    pyplot.axhline(float(np.mean(targets)), linestyle="--", label="Prevalence")
    pyplot.xlabel("Recall")
    pyplot.ylabel("Precision")
    pyplot.title("Held-out precision-recall curve")
    pyplot.legend()
    _save_figure(precision_recall_path, pyplot)
    paths.append(precision_recall_path)

    calibration_path = output_directory / "calibration-curve.png"
    observed, predicted = calibration_curve(targets, probabilities, n_bins=10, strategy="quantile")
    pyplot.figure(figsize=(5, 4))
    pyplot.plot(predicted, observed, marker="o", label="Logistic model")
    pyplot.plot([0, 1], [0, 1], linestyle="--", label="Perfect calibration")
    pyplot.xlabel("Mean predicted probability")
    pyplot.ylabel("Observed overdue rate")
    pyplot.title("Held-out calibration")
    pyplot.legend()
    _save_figure(calibration_path, pyplot)
    paths.append(calibration_path)

    coefficient_path = output_directory / "standardized-coefficients.png"
    coefficients = trained.classifier.coef_[0]
    order = np.argsort(np.abs(coefficients))
    labels = [FEATURE_LABELS[FEATURE_NAMES[index]] for index in order]
    pyplot.figure(figsize=(9, 7))
    pyplot.barh(labels, coefficients[order])
    pyplot.axvline(0, color="black", linewidth=0.8)
    pyplot.xlabel("Standardized logistic coefficient")
    pyplot.title("Payment-risk model factors")
    _save_figure(coefficient_path, pyplot)
    paths.append(coefficient_path)

    return tuple(paths)
