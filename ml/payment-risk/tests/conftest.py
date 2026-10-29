from __future__ import annotations

import csv
import hashlib
import json
from datetime import UTC, date, datetime, timedelta
from pathlib import Path

import pytest

from payment_risk.contracts import CSV_COLUMNS, PROVENANCE_SCHEMA_VERSION, RECORDED_AT_POLICY


def opaque_key(value: str) -> str:
    return hashlib.sha256(value.encode()).hexdigest()


def month_start(start: date, offset: int) -> date:
    month_index = (start.year * 12) + start.month - 1 + offset
    return date(month_index // 12, (month_index % 12) + 1, 1)


def write_csv(
    path: Path, rows: list[dict[str, str]], headers: tuple[str, ...] = CSV_COLUMNS
) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=headers, extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)


def write_provenance(
    path: Path,
    csv_path: Path,
    *,
    source_type: str = "synthetic",
    generated_at: str = "2026-01-31T00:00:00+00:00",
    overrides: dict[str, object] | None = None,
) -> dict[str, object]:
    payload: dict[str, object] = {
        "provenance_schema_version": PROVENANCE_SCHEMA_VERSION,
        "dataset_id": "synthetic-contract-v1",
        "source_type": source_type,
        "generated_at": generated_at,
        "consent_confirmed": source_type == "consented_anonymized",
        "pii_removed": True,
        "point_in_time_correct": True,
        "recorded_at_policy": RECORDED_AT_POLICY,
        "csv_sha256": hashlib.sha256(csv_path.read_bytes()).hexdigest(),
    }
    payload.update(overrides or {})
    path.write_text(json.dumps(payload), encoding="utf-8")
    return payload


@pytest.fixture
def dataset_factory(tmp_path: Path, monkeypatch: pytest.MonkeyPatch):
    def factory(
        *,
        histories: int = 50,
        periods: int = 12,
        source_type: str = "synthetic",
    ) -> tuple[Path, Path, list[dict[str, str]]]:
        csv_path = tmp_path / f"source-{histories}-{periods}.csv"
        provenance_path = tmp_path / f"source-{histories}-{periods}.provenance.json"
        rows: list[dict[str, str]] = []
        start = date(2024, 1, 1)
        for period_index in range(periods):
            period = month_start(start, period_index)
            due = period.replace(day=28)
            created_at = datetime.combine(period, datetime.min.time(), tzinfo=UTC)
            for member_index in range(histories):
                family_index = member_index // 5
                expected = 10_000 + ((member_index % 7) * 1_000)
                overdue = (member_index + period_index) % 4 == 0
                partial = overdue and (member_index + period_index) % 8 == 0
                if not overdue:
                    amount_paid = expected
                    first_payment = created_at.replace(day=23, hour=10)
                    fully_paid = first_payment
                    payment_count = 1
                elif partial:
                    amount_paid = expected // 2
                    first_payment = created_at.replace(day=26, hour=10)
                    fully_paid = datetime.combine(
                        due + timedelta(days=5), datetime.min.time(), tzinfo=UTC
                    )
                    payment_count = 1
                else:
                    amount_paid = 0
                    first_payment = datetime.combine(
                        due + timedelta(days=2), datetime.min.time(), tzinfo=UTC
                    )
                    fully_paid = datetime.combine(
                        due + timedelta(days=7), datetime.min.time(), tzinfo=UTC
                    )
                    payment_count = 0
                rows.append(
                    {
                        "family_key": opaque_key(f"family-{family_index}"),
                        "member_key": opaque_key(f"member-{member_index}"),
                        "obligation_key": opaque_key(
                            f"obligation-{member_index}-{period.isoformat()}"
                        ),
                        "period_start": period.isoformat(),
                        "obligation_created_at": created_at.isoformat(),
                        "due_date": due.isoformat(),
                        "expected_amount_minor": str(expected),
                        "amount_paid_by_due_minor": str(amount_paid),
                        "first_payment_recorded_at": first_payment.isoformat(),
                        "fully_paid_recorded_at": fully_paid.isoformat(),
                        "payment_count_by_due": str(payment_count),
                        "is_backfilled": "false",
                    }
                )
        write_csv(csv_path, rows)
        write_provenance(provenance_path, csv_path, source_type=source_type)
        monkeypatch.setattr(
            "payment_risk.validation.APPROVED_SOURCE_CSV",
            csv_path.resolve(),
        )
        return csv_path, provenance_path, rows

    return factory
