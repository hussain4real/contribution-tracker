from __future__ import annotations

from dataclasses import replace

import numpy as np
import pytest

from payment_risk.contracts import FEATURE_NAMES
from payment_risk.evaluation import (
    _binary_metrics,
    _bootstrap_confidence_intervals,
    build_evaluation,
    write_evaluation_charts,
)
from payment_risk.features import engineer_feature_rows
from payment_risk.modeling import select_validation_threshold, train_logistic_model
from payment_risk.splitting import chronological_complete_period_split
from payment_risk.validation import load_validated_source


def _training_parts(dataset_factory):
    csv_path, provenance_path, _ = dataset_factory()
    validated = load_validated_source(csv_path, provenance_path)
    split = chronological_complete_period_split(engineer_feature_rows(validated.rows))
    trained = train_logistic_model(split)
    return validated, split, trained


def test_threshold_selection_maximises_f1_with_recall_floor_and_precision_tie_break():
    targets = np.asarray([1, 1, 0, 0], dtype=np.int64)
    probabilities = np.asarray([0.9, 0.6, 0.7, 0.1], dtype=np.float64)

    threshold = select_validation_threshold(targets, probabilities)

    assert threshold == pytest.approx(0.6)
    with pytest.raises(ValueError, match="must contain overdue"):
        select_validation_threshold(np.asarray([0, 0]), np.asarray([0.1, 0.2]))


def test_threshold_selection_breaks_equal_f1_ties_by_precision_after_recall_floor():
    targets = np.asarray(
        [1, 1, 1, 0, 0, 0, 0, 0, 1, 0, 0, 0],
        dtype=np.int64,
    )
    probabilities = np.asarray([0.9] * 8 + [0.4] * 4, dtype=np.float64)

    higher_threshold_metrics = _binary_metrics(targets, probabilities, 0.9)
    lower_threshold_metrics = _binary_metrics(targets, probabilities, 0.4)

    assert higher_threshold_metrics["overdue_f1"] == 0.5
    assert lower_threshold_metrics["overdue_f1"] == 0.5
    assert higher_threshold_metrics["recall_overdue"] == 0.75
    assert lower_threshold_metrics["recall_overdue"] == 1.0
    assert higher_threshold_metrics["precision_overdue"] == 0.375
    assert lower_threshold_metrics["precision_overdue"] == pytest.approx(1 / 3)
    assert select_validation_threshold(targets, probabilities) == pytest.approx(0.9)


def test_class_balanced_l2_model_is_deterministic_and_uses_validation_only(dataset_factory):
    _, split, first = _training_parts(dataset_factory)
    second = train_logistic_model(split)

    assert first.classifier.class_weight == "balanced"
    assert first.classifier.penalty == "l2"
    assert first.classifier.solver == "liblinear"
    assert first.scaler.mean_.shape == (len(FEATURE_NAMES),)
    assert first.scaler.scale_.shape == (len(FEATURE_NAMES),)
    assert np.allclose(first.classifier.coef_, second.classifier.coef_)
    assert np.allclose(first.validation_probabilities, second.validation_probabilities)
    assert np.allclose(first.held_out_probabilities, second.held_out_probabilities)
    assert first.threshold == second.threshold


def test_evaluation_reports_metrics_intervals_baselines_and_activation(dataset_factory):
    validated, split, trained = _training_parts(dataset_factory)

    evaluation = build_evaluation(
        split,
        trained,
        validated.provenance,
        validated.data_quality,
        bootstrap_samples=30,
    )

    assert evaluation["selection"]["minimum_overdue_recall"] == 0.70
    assert evaluation["validation"]["recall_overdue"] >= 0.70
    for section in ("validation", "held_out"):
        assert 0 <= evaluation[section]["overdue_f1"] <= 1
        assert 0 <= evaluation[section]["balanced_accuracy"] <= 1
        assert 0 <= evaluation[section]["brier_score"] <= 1
        assert evaluation[section]["bootstrap_95_ci"]["overdue_f1"]["samples"] == 30
    assert set(evaluation["baselines"]) == {
        "training_prevalence",
        "previous_period_late",
    }
    assert evaluation["activation_eligible"] is False
    assert evaluation["activation_checks"]["consented_anonymized_provenance"] is False

    real_provenance = replace(
        validated.provenance,
        source_type="consented_anonymized",
        consent_confirmed=True,
    )
    real_evaluation = build_evaluation(
        split,
        trained,
        real_provenance,
        validated.data_quality,
        bootstrap_samples=5,
    )
    assert real_evaluation["activation_checks"]["consented_anonymized_provenance"] is True
    assert real_evaluation["activation_eligible"] == all(
        real_evaluation["activation_checks"].values()
    )


def test_previous_period_late_baseline_matches_hand_calculated_predictions_and_metrics(
    dataset_factory,
):
    validated, split, trained = _training_parts(dataset_factory)
    overdue_streak_index = FEATURE_NAMES.index("overdue_streak")
    held_out_features = np.zeros((5, len(FEATURE_NAMES)), dtype=np.float64)
    held_out_features[:, overdue_streak_index] = [1.0, 1.0, 0.0, 0.0, 2.0]
    held_out_targets = np.asarray([1, 0, 1, 0, 1], dtype=np.int64)
    expected_predictions = np.asarray([1, 1, 0, 0, 1], dtype=np.int64)
    controlled_split = replace(
        split,
        held_out=replace(
            split.held_out,
            rows=split.held_out.rows[:5],
            features=held_out_features,
            targets=held_out_targets,
        ),
    )
    controlled_trained = replace(
        trained,
        held_out_probabilities=np.asarray([0.8, 0.2, 0.7, 0.3, 0.9], dtype=np.float64),
    )

    evaluation = build_evaluation(
        controlled_split,
        controlled_trained,
        validated.provenance,
        validated.data_quality,
        bootstrap_samples=1,
    )
    baseline = evaluation["baselines"]["previous_period_late"]

    np.testing.assert_array_equal(
        (held_out_features[:, overdue_streak_index] > 0).astype(np.int64),
        expected_predictions,
    )
    assert baseline["row_count"] == 5
    assert baseline["overdue_prevalence"] == pytest.approx(3 / 5)
    assert baseline["threshold"] == 0.5
    assert baseline["accuracy"] == pytest.approx(3 / 5)
    assert baseline["balanced_accuracy"] == pytest.approx(7 / 12)
    assert baseline["precision_overdue"] == pytest.approx(2 / 3)
    assert baseline["recall_overdue"] == pytest.approx(2 / 3)
    assert baseline["overdue_f1"] == pytest.approx(2 / 3)
    assert baseline["roc_auc"] == pytest.approx(7 / 12)
    assert baseline["pr_auc"] == pytest.approx(29 / 45)
    assert baseline["brier_score"] == pytest.approx(2 / 5)
    assert baseline["confusion_matrix"] == [[1, 1], [1, 2]]


def test_metrics_handle_single_class_bootstraps_without_invalid_auc():
    targets = np.asarray([0, 0, 0], dtype=np.int64)
    probabilities = np.asarray([0.1, 0.2, 0.3], dtype=np.float64)

    metrics = _binary_metrics(targets, probabilities, 0.5)
    intervals = _bootstrap_confidence_intervals(
        targets,
        probabilities,
        0.5,
        samples=3,
    )

    assert metrics["balanced_accuracy"] is None
    assert metrics["roc_auc"] is None
    assert metrics["pr_auc"] is None
    assert "roc_auc" not in intervals
    assert "pr_auc" not in intervals


def test_bootstrap_intervals_are_exactly_deterministic_across_repeated_runs():
    targets = np.asarray([0, 1, 0, 1, 0, 1, 0, 1], dtype=np.int64)
    probabilities = np.asarray([0.1, 0.8, 0.6, 0.7, 0.4, 0.3, 0.2, 0.9], dtype=np.float64)
    expected = {
        "accuracy": {"confidence": 0.95, "lower": 0.5, "upper": 1.0, "samples": 25},
        "balanced_accuracy": {
            "confidence": 0.95,
            "lower": 0.41333333333333333,
            "upper": 1.0,
            "samples": 25,
        },
        "precision_overdue": {
            "confidence": 0.95,
            "lower": 0.5,
            "upper": 1.0,
            "samples": 25,
        },
        "recall_overdue": {
            "confidence": 0.95,
            "lower": 0.23,
            "upper": 1.0,
            "samples": 25,
        },
        "overdue_f1": {
            "confidence": 0.95,
            "lower": 0.3333333333333333,
            "upper": 1.0,
            "samples": 25,
        },
        "roc_auc": {"confidence": 0.95, "lower": 0.55, "upper": 1.0, "samples": 25},
        "pr_auc": {
            "confidence": 0.95,
            "lower": 0.7214285714285714,
            "upper": 1.0,
            "samples": 25,
        },
        "brier_score": {
            "confidence": 0.95,
            "lower": 0.05825,
            "upper": 0.27699999999999997,
            "samples": 25,
        },
    }

    first = _bootstrap_confidence_intervals(targets, probabilities, 0.5, samples=25)
    second = _bootstrap_confidence_intervals(targets, probabilities, 0.5, samples=25)

    assert first == expected
    assert second == expected
    assert first == second


def test_required_charts_are_written_without_source_identifiers(dataset_factory, tmp_path):
    _, split, trained = _training_parts(dataset_factory)

    chart_paths = write_evaluation_charts(tmp_path / "charts", split, trained)

    assert {path.name for path in chart_paths} == {
        "class-distribution.png",
        "confusion-matrix.png",
        "roc-curve.png",
        "precision-recall-curve.png",
        "calibration-curve.png",
        "standardized-coefficients.png",
    }
    assert all(path.stat().st_size > 0 for path in chart_paths)
