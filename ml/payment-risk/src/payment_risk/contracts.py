"""Stable source, feature, and artifact contracts.

The source provenance attests that member histories containing any financial reversal were
excluded upstream because the locked CSV has no reversal event columns.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import date, datetime
from typing import Final

ARTIFACT_SCHEMA_VERSION: Final = "payment-risk-logistic-v1"
PROVENANCE_SCHEMA_VERSION: Final = "payment-risk-provenance-v1"
FEATURE_CONTRACT_VERSION: Final = "payment-risk-features-v1"
ACTIVATION_POLICY_VERSION: Final = "payment-risk-activation-v1"
RECORDED_AT_POLICY: Final = "system_known_by_cutoff_no_reversed_allocations"

CSV_COLUMNS: Final = (
    "family_key",
    "member_key",
    "obligation_key",
    "period_start",
    "obligation_created_at",
    "due_date",
    "expected_amount_minor",
    "amount_paid_by_due_minor",
    "first_payment_recorded_at",
    "fully_paid_recorded_at",
    "payment_count_by_due",
    "is_backfilled",
)

PROVENANCE_KEYS: Final = frozenset(
    {
        "provenance_schema_version",
        "dataset_id",
        "source_type",
        "generated_at",
        "consent_confirmed",
        "pii_removed",
        "point_in_time_correct",
        "recorded_at_policy",
        "csv_sha256",
    }
)

FEATURE_NAMES: Final = (
    "expected_amount_minor",
    "days_until_due",
    "calendar_month",
    "calendar_quarter",
    "previous_mature_period_count",
    "prior_3_on_time_rate",
    "prior_6_on_time_rate",
    "prior_lifetime_on_time_rate",
    "prior_partial_payment_rate",
    "mean_recorded_settlement_delay_days",
    "median_recorded_settlement_delay_days",
    "prior_outstanding_count",
    "prior_outstanding_amount_minor",
    "overdue_streak",
)

FEATURE_LABELS: Final = {
    "expected_amount_minor": "Expected contribution amount",
    "days_until_due": "Days available before the due date",
    "calendar_month": "Contribution calendar month",
    "calendar_quarter": "Contribution calendar quarter",
    "previous_mature_period_count": "Previous mature contribution periods",
    "prior_3_on_time_rate": "On-time rate across the latest 3 mature periods",
    "prior_6_on_time_rate": "On-time rate across the latest 6 mature periods",
    "prior_lifetime_on_time_rate": "Lifetime on-time contribution rate",
    "prior_partial_payment_rate": "Previous partial-payment rate",
    "mean_recorded_settlement_delay_days": "Mean recorded settlement delay",
    "median_recorded_settlement_delay_days": "Median recorded settlement delay",
    "prior_outstanding_count": "Previous contributions still outstanding",
    "prior_outstanding_amount_minor": "Previous amount still outstanding",
    "overdue_streak": "Consecutive overdue mature periods",
}

MINIMUM_ROWS: Final = 500
MINIMUM_HISTORIES: Final = 50
MINIMUM_PERIODS: Final = 12
MINIMUM_CLASS_ROWS: Final = 100
MINIMUM_DAYS_TO_DUE: Final = 7
MINIMUM_VALIDATION_RECALL: Final = 0.70
BOOTSTRAP_SAMPLES: Final = 1_000
RANDOM_SEED: Final = 41_729


@dataclass(frozen=True, slots=True)
class SourceRow:
    """A validated contribution outcome row from the approved CSV export."""

    family_key: str
    member_key: str
    obligation_key: str
    period_start: date
    obligation_created_at: datetime
    due_date: date
    expected_amount_minor: int
    amount_paid_by_due_minor: int
    first_payment_recorded_at: datetime | None
    fully_paid_recorded_at: datetime | None
    payment_count_by_due: int
    is_backfilled: bool

    @property
    def history_key(self) -> tuple[str, str]:
        """Return the tenant-scoped member-history key."""

        return self.family_key, self.member_key

    @property
    def is_overdue(self) -> int:
        """Derive the target at the end of the contribution due date."""

        return int(self.amount_paid_by_due_minor < self.expected_amount_minor)


@dataclass(frozen=True, slots=True)
class FeatureRow:
    """A model-ready row with metadata kept separate from numeric features."""

    history_key: tuple[str, str]
    obligation_key: str
    period_start: date
    values: tuple[float, ...]
    target: int


@dataclass(frozen=True, slots=True)
class DatasetProvenance:
    """Validated provenance for an external source CSV."""

    dataset_id: str
    source_type: str
    generated_at: datetime
    consent_confirmed: bool
    csv_sha256: str
