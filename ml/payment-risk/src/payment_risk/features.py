"""Point-in-time feature engineering from approved contribution outcomes.

The provenance contract excludes histories containing reversed allocations upstream. For an
obligation still unsettled at the target cutoff, outstanding amount remains its due-date residual;
post-due partial amounts are intentionally unavailable in the locked source schema and cannot
mutate that feature.
"""

from __future__ import annotations

from collections import defaultdict
from statistics import fmean, median

from payment_risk.contracts import FEATURE_NAMES, FeatureRow, SourceRow


def _on_time_rate(rows: list[SourceRow]) -> float:
    if not rows:
        return 0.0
    return sum(1 - row.is_overdue for row in rows) / len(rows)


def _overdue_streak(rows: list[SourceRow]) -> int:
    streak = 0
    for row in reversed(rows):
        if not row.is_overdue:
            break
        streak += 1
    return streak


def _feature_values(row: SourceRow, prior_rows: list[SourceRow]) -> tuple[float, ...]:
    mature_rows = [
        prior
        for prior in prior_rows
        if prior.period_start < row.period_start
        and prior.obligation_created_at < row.obligation_created_at
        and prior.due_date < row.obligation_created_at.date()
    ]
    mature_rows.sort(key=lambda prior: (prior.period_start, prior.obligation_key))

    partial_count = sum(
        0 < prior.amount_paid_by_due_minor < prior.expected_amount_minor for prior in mature_rows
    )
    settlement_delays = [
        (prior.fully_paid_recorded_at.date() - prior.due_date).days
        for prior in mature_rows
        if prior.fully_paid_recorded_at is not None
        and prior.fully_paid_recorded_at < row.obligation_created_at
    ]
    outstanding_rows = [
        prior
        for prior in mature_rows
        if prior.fully_paid_recorded_at is None
        or prior.fully_paid_recorded_at >= row.obligation_created_at
    ]

    values = (
        float(row.expected_amount_minor),
        float((row.due_date - row.obligation_created_at.date()).days),
        float(row.period_start.month),
        float(((row.period_start.month - 1) // 3) + 1),
        float(len(mature_rows)),
        _on_time_rate(mature_rows[-3:]),
        _on_time_rate(mature_rows[-6:]),
        _on_time_rate(mature_rows),
        partial_count / len(mature_rows) if mature_rows else 0.0,
        fmean(settlement_delays) if settlement_delays else 0.0,
        float(median(settlement_delays)) if settlement_delays else 0.0,
        float(len(outstanding_rows)),
        float(
            sum(
                prior.expected_amount_minor - prior.amount_paid_by_due_minor
                for prior in outstanding_rows
            )
        ),
        float(_overdue_streak(mature_rows)),
    )
    if len(values) != len(FEATURE_NAMES):
        raise AssertionError("feature contract and engineered values are misaligned")
    return values


def engineer_feature_rows(rows: tuple[SourceRow, ...]) -> tuple[FeatureRow, ...]:
    """Build historical features using only information recorded before each cutoff."""

    histories: defaultdict[tuple[str, str], list[SourceRow]] = defaultdict(list)
    feature_rows: list[FeatureRow] = []
    for row in sorted(
        rows,
        key=lambda item: (item.obligation_created_at, item.period_start, item.obligation_key),
    ):
        prior_rows = histories[row.history_key]
        feature_rows.append(
            FeatureRow(
                history_key=row.history_key,
                obligation_key=row.obligation_key,
                period_start=row.period_start,
                values=_feature_values(row, prior_rows),
                target=row.is_overdue,
            )
        )
        prior_rows.append(row)
    return tuple(sorted(feature_rows, key=lambda item: (item.period_start, item.obligation_key)))
