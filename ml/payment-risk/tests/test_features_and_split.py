from __future__ import annotations

from dataclasses import replace
from datetime import date

import pytest
from conftest import opaque_key

from payment_risk.contracts import FEATURE_NAMES, FeatureRow
from payment_risk.features import engineer_feature_rows
from payment_risk.splitting import chronological_complete_period_split
from payment_risk.validation import DatasetValidationError, load_validated_source


def _feature_map(feature_row: FeatureRow) -> dict[str, float]:
    return dict(zip(FEATURE_NAMES, feature_row.values, strict=True))


def test_historical_features_are_computed_only_from_mature_prior_rows(dataset_factory):
    csv_path, provenance_path, _ = dataset_factory()
    validated = load_validated_source(csv_path, provenance_path)
    features = engineer_feature_rows(validated.rows)
    member_key = opaque_key("member-0")
    family_key = opaque_key("family-0")
    member_rows = [row for row in features if row.history_key == (family_key, member_key)]

    cold_start = _feature_map(member_rows[0])
    assert cold_start == {
        "expected_amount_minor": 10_000.0,
        "days_until_due": 27.0,
        "calendar_month": 1.0,
        "calendar_quarter": 1.0,
        "previous_mature_period_count": 0.0,
        "prior_3_on_time_rate": 0.0,
        "prior_6_on_time_rate": 0.0,
        "prior_lifetime_on_time_rate": 0.0,
        "prior_partial_payment_rate": 0.0,
        "mean_recorded_settlement_delay_days": 0.0,
        "median_recorded_settlement_delay_days": 0.0,
        "prior_outstanding_count": 0.0,
        "prior_outstanding_amount_minor": 0.0,
        "overdue_streak": 0.0,
    }

    february = _feature_map(member_rows[1])
    assert february["previous_mature_period_count"] == 1
    assert february["prior_partial_payment_rate"] == 1
    assert february["prior_outstanding_count"] == 1
    assert february["prior_outstanding_amount_minor"] == 5_000
    assert february["overdue_streak"] == 1
    assert february["mean_recorded_settlement_delay_days"] == 0

    march = _feature_map(member_rows[2])
    assert march["prior_3_on_time_rate"] == 0.5
    assert march["prior_6_on_time_rate"] == 0.5
    assert march["prior_lifetime_on_time_rate"] == 0.5
    assert march["prior_partial_payment_rate"] == 0.5
    assert march["mean_recorded_settlement_delay_days"] == 0
    assert march["median_recorded_settlement_delay_days"] == 0
    assert march["prior_outstanding_count"] == 0
    assert march["overdue_streak"] == 0


def test_future_outcomes_cannot_change_an_earlier_feature_vector(dataset_factory):
    csv_path, provenance_path, _ = dataset_factory()
    validated = load_validated_source(csv_path, provenance_path)
    original = engineer_feature_rows(validated.rows)
    future = validated.rows[-1]
    changed_future = replace(
        future,
        amount_paid_by_due_minor=0,
        payment_count_by_due=0,
        first_payment_recorded_at=None,
        fully_paid_recorded_at=None,
    )
    changed_rows = (*validated.rows[:-1], changed_future)

    rebuilt = engineer_feature_rows(changed_rows)

    original_by_key = {row.obligation_key: row for row in original}
    rebuilt_by_key = {row.obligation_key: row for row in rebuilt}
    for key in original_by_key.keys() - {future.obligation_key}:
        assert original_by_key[key] == rebuilt_by_key[key]
    assert (
        original_by_key[future.obligation_key].target
        != rebuilt_by_key[future.obligation_key].target
    )


def test_outstanding_amount_keeps_due_date_residual_after_recorded_post_due_payment(
    dataset_factory,
):
    csv_path, provenance_path, _ = dataset_factory()
    validated = load_validated_source(csv_path, provenance_path)
    history_key = (opaque_key("family-0"), opaque_key("member-0"))
    member_rows = [row for row in validated.rows if row.history_key == history_key]
    prior, target = member_rows[:2]
    prior_with_post_due_payment = replace(
        prior,
        amount_paid_by_due_minor=0,
        payment_count_by_due=0,
        first_payment_recorded_at=prior.obligation_created_at.replace(day=30, hour=10),
        fully_paid_recorded_at=target.obligation_created_at.replace(day=6),
    )

    engineered = engineer_feature_rows((prior_with_post_due_payment, target))
    target_features = _feature_map(engineered[1])

    assert prior_with_post_due_payment.first_payment_recorded_at < target.obligation_created_at
    assert prior_with_post_due_payment.fully_paid_recorded_at > target.obligation_created_at
    assert target_features["prior_outstanding_count"] == 1
    assert target_features["prior_outstanding_amount_minor"] == prior.expected_amount_minor


def test_settlement_recorded_exactly_at_target_cutoff_is_not_available(dataset_factory):
    csv_path, provenance_path, _ = dataset_factory()
    validated = load_validated_source(csv_path, provenance_path)
    history_key = (opaque_key("family-0"), opaque_key("member-0"))
    prior, target = [row for row in validated.rows if row.history_key == history_key][:2]
    prior_with_equal_cutoff_settlement = replace(
        prior,
        fully_paid_recorded_at=target.obligation_created_at,
    )

    engineered = engineer_feature_rows((prior_with_equal_cutoff_settlement, target))
    target_features = _feature_map(engineered[1])

    assert target_features["prior_outstanding_count"] == 1
    assert target_features["prior_outstanding_amount_minor"] == (
        prior.expected_amount_minor - prior.amount_paid_by_due_minor
    )
    assert target_features["mean_recorded_settlement_delay_days"] == 0


def test_split_is_atomic_chronological_and_exact_by_complete_period(dataset_factory):
    csv_path, provenance_path, _ = dataset_factory()
    validated = load_validated_source(csv_path, provenance_path)

    split = chronological_complete_period_split(engineer_feature_rows(validated.rows))

    assert len(split.training.periods) == 7
    assert len(split.validation.periods) == 2
    assert len(split.held_out.periods) == 3
    assert split.training.features.shape == (350, len(FEATURE_NAMES))
    assert split.validation.features.shape == (100, len(FEATURE_NAMES))
    assert split.held_out.features.shape == (150, len(FEATURE_NAMES))
    assert max(split.training.periods) < min(split.validation.periods)
    assert max(split.validation.periods) < min(split.held_out.periods)
    assert split.report["policy"] == "chronological_complete_period_60_20_20"
    assert split.report["training"]["start"] == "2024-01-01"
    assert split.report["held_out"]["end"] == "2024-12-01"


def test_split_rejects_empty_partitions_bad_feature_width_and_single_class():
    base = FeatureRow(
        history_key=("family", "member"),
        obligation_key="obligation",
        period_start=date(2024, 1, 1),
        values=(0.0,) * len(FEATURE_NAMES),
        target=0,
    )
    with pytest.raises(DatasetValidationError, match="non-empty"):
        chronological_complete_period_split((base,))

    rows = tuple(
        replace(
            base,
            obligation_key=f"obligation-{index}",
            period_start=date(2024 + (index // 12), (index % 12) + 1, 1),
            target=index % 2,
            values=(0.0,),
        )
        for index in range(12)
    )
    with pytest.raises(DatasetValidationError, match="feature matrix"):
        chronological_complete_period_split(rows)

    single_class = tuple(replace(row, values=(0.0,) * len(FEATURE_NAMES), target=0) for row in rows)
    with pytest.raises(DatasetValidationError, match="both target classes"):
        chronological_complete_period_split(single_class)


def test_feature_contract_alignment_assertion_is_defensive(dataset_factory, monkeypatch):
    csv_path, provenance_path, _ = dataset_factory()
    validated = load_validated_source(csv_path, provenance_path)
    monkeypatch.setattr("payment_risk.features.FEATURE_NAMES", (*FEATURE_NAMES, "unexpected"))

    with pytest.raises(AssertionError, match="misaligned"):
        engineer_feature_rows(validated.rows[:1])
