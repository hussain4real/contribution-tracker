from __future__ import annotations

import json
from datetime import UTC, datetime
from pathlib import Path

import pytest
from conftest import write_csv, write_provenance

from payment_risk.contracts import CSV_COLUMNS, RECORDED_AT_POLICY
from payment_risk.validation import (
    DataQualityGateError,
    DatasetValidationError,
    _parse_boolean,
    _parse_date,
    _parse_datetime,
    _parse_non_negative_integer,
    _parse_source_row,
    _validate_headers,
    load_validated_source,
)


def test_valid_source_passes_exact_contract_and_minimum_gates(dataset_factory):
    csv_path, provenance_path, _ = dataset_factory()

    validated = load_validated_source(csv_path, provenance_path)

    assert len(validated.rows) == 600
    assert validated.data_quality == {
        "dataset_id": "synthetic-contract-v1",
        "source_type": "synthetic",
        "valid_row_count": 600,
        "distinct_member_count": 50,
        "distinct_period_count": 12,
        "on_time_count": 450,
        "overdue_count": 150,
        "gate_passed": True,
        "gates": {
            "minimum_rows": True,
            "minimum_distinct_members": True,
            "minimum_complete_periods": True,
            "minimum_on_time_class": True,
            "minimum_overdue_class": True,
        },
    }
    assert validated.rows[0].history_key == (
        validated.rows[0].family_key,
        validated.rows[0].member_key,
    )
    assert validated.rows[0].is_overdue in {0, 1}


@pytest.mark.parametrize(
    ("headers", "message"),
    [
        ((*CSV_COLUMNS, "email"), "PII columns are forbidden"),
        ((*CSV_COLUMNS, "unexpected"), "ordered whitelist"),
        (CSV_COLUMNS[:-1], "ordered whitelist"),
        ((CSV_COLUMNS[0], *CSV_COLUMNS), "duplicate column"),
        ((CSV_COLUMNS[1], CSV_COLUMNS[0], *CSV_COLUMNS[2:]), "ordered whitelist"),
    ],
)
def test_csv_header_contract_rejects_pii_extra_missing_duplicate_or_reordered(
    dataset_factory, headers, message
):
    csv_path, provenance_path, rows = dataset_factory()
    write_csv(csv_path, rows, headers=headers)
    write_provenance(provenance_path, csv_path)

    with pytest.raises(DatasetValidationError, match=message):
        load_validated_source(csv_path, provenance_path)


def test_empty_and_unreadable_csv_are_rejected(dataset_factory):
    with pytest.raises(DatasetValidationError, match="source CSV is empty"):
        _validate_headers(None)
    csv_path, provenance_path, _ = dataset_factory()
    csv_path.write_text(",".join(CSV_COLUMNS) + "\n", encoding="utf-8")
    write_provenance(provenance_path, csv_path)
    with pytest.raises(DatasetValidationError, match="contain data rows"):
        load_validated_source(csv_path, provenance_path)

    csv_path.write_bytes(b"\xff\xfe")
    write_provenance(provenance_path, csv_path)
    with pytest.raises(DatasetValidationError, match="readable strict UTF-8 CSV"):
        load_validated_source(csv_path, provenance_path)


def test_private_source_location_extension_and_existence_are_gated(
    dataset_factory, monkeypatch, tmp_path
):
    csv_path, provenance_path, _ = dataset_factory()
    monkeypatch.setattr(
        "payment_risk.validation.APPROVED_SOURCE_CSV",
        tmp_path / "storage/app/private/payment-risk/source/member-periods.csv",
    )
    with pytest.raises(DatasetValidationError, match="approved private"):
        load_validated_source(csv_path, provenance_path)

    renamed = csv_path.with_suffix(".txt")
    csv_path.rename(renamed)
    monkeypatch.setattr("payment_risk.validation.APPROVED_SOURCE_CSV", renamed.resolve())
    with pytest.raises(DatasetValidationError, match=r"\.csv extension"):
        load_validated_source(renamed, provenance_path)
    monkeypatch.setattr("payment_risk.validation.APPROVED_SOURCE_CSV", csv_path.resolve())
    with pytest.raises(DatasetValidationError, match="must both exist"):
        load_validated_source(csv_path, provenance_path)


def _rewrite_provenance(path: Path, payload: object) -> None:
    path.write_text(json.dumps(payload), encoding="utf-8")


@pytest.mark.parametrize(
    ("update", "message"),
    [
        ({"extra": True}, "keys do not match"),
        ({"provenance_schema_version": "v0"}, "unsupported provenance"),
        ({"dataset_id": "x"}, "opaque 3-64"),
        ({"source_type": "private"}, "source_type"),
        ({"pii_removed": 1}, "JSON boolean"),
        ({"pii_removed": False}, "pii_removed must be true"),
        ({"point_in_time_correct": False}, "point_in_time_correct must be true"),
        ({"recorded_at_policy": "event_date_only"}, "recorded_at_policy"),
        (
            {"recorded_at_policy": "system_known_by_cutoff"},
            RECORDED_AT_POLICY,
        ),
        ({"generated_at": 123}, "generated_at must be"),
        ({"generated_at": "2026-01-31T00:00:00"}, "timezone offset"),
        ({"generated_at": "2099-01-31T00:00:00+00:00"}, "future"),
        ({"csv_sha256": "bad"}, "lowercase SHA-256"),
        ({"csv_sha256": "0" * 64}, "does not match"),
    ],
)
def test_provenance_contract_rejects_invalid_values(dataset_factory, update, message):
    csv_path, provenance_path, _ = dataset_factory()
    payload = json.loads(provenance_path.read_text(encoding="utf-8"))
    payload.update(update)
    _rewrite_provenance(provenance_path, payload)

    with pytest.raises(DatasetValidationError, match=message):
        load_validated_source(csv_path, provenance_path)


def test_real_provenance_requires_consent_and_json_object(dataset_factory):
    csv_path, provenance_path, _ = dataset_factory(source_type="consented_anonymized")
    payload = json.loads(provenance_path.read_text(encoding="utf-8"))
    payload["consent_confirmed"] = False
    _rewrite_provenance(provenance_path, payload)
    with pytest.raises(DatasetValidationError, match="requires consent"):
        load_validated_source(csv_path, provenance_path)

    _rewrite_provenance(provenance_path, [])
    with pytest.raises(DatasetValidationError, match="JSON object"):
        load_validated_source(csv_path, provenance_path)

    provenance_path.write_text("not-json", encoding="utf-8")
    with pytest.raises(DatasetValidationError, match="readable UTF-8 JSON"):
        load_validated_source(csv_path, provenance_path)


def _valid_raw_row(dataset_factory) -> dict[str, str]:
    _, _, rows = dataset_factory()
    return dict(rows[1])


@pytest.mark.parametrize(
    ("changes", "message"),
    [
        ({"family_key": "raw-id"}, "opaque lowercase SHA-256"),
        ({"period_start": "2024-01-02"}, "first day"),
        ({"due_date": "2024-02-28"}, "inside period_start month"),
        ({"is_backfilled": "true"}, "backfilled"),
        ({"obligation_created_at": "2024-01-25T00:00:00+00:00"}, "at least 7 days"),
        ({"expected_amount_minor": "0"}, "must be positive"),
        ({"amount_paid_by_due_minor": "999999"}, "cannot exceed"),
        ({"payment_count_by_due": "0"}, "must agree"),
        ({"first_payment_recorded_at": ""}, "requires first payment"),
        (
            {"first_payment_recorded_at": "2023-12-01T00:00:00+00:00"},
            "predates the obligation",
        ),
        (
            {
                "amount_paid_by_due_minor": "0",
                "payment_count_by_due": "0",
                "first_payment_recorded_at": "2024-01-23T10:00:00+00:00",
            },
            "timing contradicts",
        ),
        (
            {"fully_paid_recorded_at": "2024-01-22T10:00:00+00:00"},
            "must follow",
        ),
        (
            {
                "amount_paid_by_due_minor": "5000",
                "fully_paid_recorded_at": "2024-01-27T10:00:00+00:00",
            },
            "full-settlement timing contradicts",
        ),
        ({"fully_paid_recorded_at": ""}, "needs settlement timestamp"),
    ],
)
def test_row_quality_rules_are_strict(dataset_factory, changes, message):
    raw = _valid_raw_row(dataset_factory)
    raw.update(changes)

    with pytest.raises(DatasetValidationError, match=message):
        _parse_source_row(raw, 2, datetime(2026, 1, 31, tzinfo=UTC))


def test_row_parser_rejects_whitespace_invalid_scalars_and_future_observations(dataset_factory):
    raw = _valid_raw_row(dataset_factory)
    raw["family_key"] += " "
    with pytest.raises(DatasetValidationError, match="outer whitespace"):
        _parse_source_row(raw, 2, datetime(2026, 1, 31, tzinfo=UTC))

    raw = _valid_raw_row(dataset_factory)
    raw["family_key"] = None
    with pytest.raises(DatasetValidationError, match="is missing"):
        _parse_source_row(raw, 2, datetime(2026, 1, 31, tzinfo=UTC))

    for function, value, message in (
        (_parse_date, "20240101", "canonical"),
        (_parse_date, "not-a-date", "YYYY-MM-DD"),
        (_parse_datetime, "not-a-time", "ISO-8601"),
        (_parse_datetime, "2024-01-01T00:00:00", "timezone"),
        (_parse_non_negative_integer, "-1", "non-negative"),
        (_parse_boolean, "True", "exactly true or false"),
    ):
        with pytest.raises(DatasetValidationError, match=message):
            function(value, "field")
    assert _parse_datetime("", "field", optional=True) is None
    assert _parse_boolean("true", "field") is True
    assert _parse_boolean("false", "field") is False

    raw = _valid_raw_row(dataset_factory)
    with pytest.raises(DatasetValidationError, match="exceeds generated_at"):
        _parse_source_row(raw, 2, datetime(2024, 1, 22, tzinfo=UTC))
    with pytest.raises(DatasetValidationError, match="period is not complete"):
        _parse_source_row(raw, 2, datetime(2024, 1, 28, 23, tzinfo=UTC))
    with pytest.raises(DatasetValidationError, match="period is not complete"):
        _parse_source_row(raw, 2, datetime(2024, 1, 29, 23, tzinfo=UTC))


def test_duplicate_and_minimum_quality_gates_are_rejected(dataset_factory):
    csv_path, provenance_path, rows = dataset_factory()
    rows[1]["obligation_key"] = rows[0]["obligation_key"]
    write_csv(csv_path, rows)
    write_provenance(provenance_path, csv_path)
    with pytest.raises(DatasetValidationError, match="obligation_key must be globally unique"):
        load_validated_source(csv_path, provenance_path)

    csv_path, provenance_path, rows = dataset_factory()
    rows[1]["family_key"] = rows[0]["family_key"]
    rows[1]["member_key"] = rows[0]["member_key"]
    write_csv(csv_path, rows)
    write_provenance(provenance_path, csv_path)
    with pytest.raises(DatasetValidationError, match="one obligation per period"):
        load_validated_source(csv_path, provenance_path)

    csv_path, provenance_path, _ = dataset_factory(histories=49)
    with pytest.raises(
        DataQualityGateError,
        match="minimum data-quality gates failed",
    ) as caught:
        load_validated_source(csv_path, provenance_path)
    assert caught.value.data_quality["gate_passed"] is False
    assert caught.value.data_quality["gates"]["minimum_distinct_members"] is False
