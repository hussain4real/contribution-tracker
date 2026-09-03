"""Strict validation for the external, anonymised source dataset."""

from __future__ import annotations

import csv
import hashlib
import json
import re
from dataclasses import dataclass
from datetime import UTC, date, datetime, timedelta
from pathlib import Path
from typing import Any, Final, cast

from payment_risk.contracts import (
    CSV_COLUMNS,
    MINIMUM_CLASS_ROWS,
    MINIMUM_DAYS_TO_DUE,
    MINIMUM_HISTORIES,
    MINIMUM_PERIODS,
    MINIMUM_ROWS,
    PROVENANCE_KEYS,
    PROVENANCE_SCHEMA_VERSION,
    RECORDED_AT_POLICY,
    DatasetProvenance,
    SourceRow,
)

PACKAGE_ROOT: Final = Path(__file__).resolve().parents[2]
APPROVED_SOURCE_CSV: Final = (
    PACKAGE_ROOT.parents[1]
    / "storage"
    / "app"
    / "private"
    / "payment-risk"
    / "source"
    / "member-periods.csv"
)
HASHED_KEY_PATTERN: Final = re.compile(r"[0-9a-f]{64}\Z")
DATASET_ID_PATTERN: Final = re.compile(r"[A-Za-z0-9][A-Za-z0-9._-]{2,63}\Z")
NON_NEGATIVE_INTEGER_PATTERN: Final = re.compile(r"(?:0|[1-9][0-9]*)\Z")
PII_HEADER_TOKENS: Final = (
    "address",
    "email",
    "family_id",
    "first_name",
    "last_name",
    "member_id",
    "name",
    "phone",
    "user_id",
)


class DatasetValidationError(ValueError):
    """Raised when a source dataset violates its strict contract."""


class DataQualityGateError(DatasetValidationError):
    """Raised with aggregate evidence when minimum dataset gates fail."""

    def __init__(self, message: str, data_quality: dict[str, Any]) -> None:
        super().__init__(message)
        self.data_quality = data_quality


@dataclass(frozen=True, slots=True)
class ValidatedSource:
    """Validated source rows with provenance and quality evidence."""

    rows: tuple[SourceRow, ...]
    provenance: DatasetProvenance
    data_quality: dict[str, Any]


def sha256_file(path: Path) -> str:
    """Return a streaming SHA-256 digest for a file."""

    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def _require_exact_keys(payload: dict[str, Any], expected: frozenset[str], label: str) -> None:
    actual = frozenset(payload)
    if actual == expected:
        return
    missing = sorted(expected - actual)
    extra = sorted(actual - expected)
    raise DatasetValidationError(
        f"{label} keys do not match contract; missing={missing}, extra={extra}"
    )


def _parse_datetime(value: str, field: str, *, optional: bool = False) -> datetime | None:
    if value == "" and optional:
        return None
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError as error:
        raise DatasetValidationError(f"{field} must be an ISO-8601 timestamp") from error
    if parsed.tzinfo is None or parsed.utcoffset() is None:
        raise DatasetValidationError(f"{field} must include a timezone offset")
    return parsed.astimezone(UTC)


def _parse_date(value: str, field: str) -> date:
    try:
        parsed = date.fromisoformat(value)
    except ValueError as error:
        raise DatasetValidationError(f"{field} must use YYYY-MM-DD") from error
    if parsed.isoformat() != value:
        raise DatasetValidationError(f"{field} must use canonical YYYY-MM-DD")
    return parsed


def _parse_non_negative_integer(value: str, field: str) -> int:
    if NON_NEGATIVE_INTEGER_PATTERN.fullmatch(value) is None:
        raise DatasetValidationError(f"{field} must be a non-negative base-10 integer")
    return int(value)


def _parse_boolean(value: str, field: str) -> bool:
    if value not in {"true", "false"}:
        raise DatasetValidationError(f"{field} must be exactly true or false")
    return value == "true"


def _load_provenance(
    provenance_path: Path,
    csv_path: Path,
    *,
    now: datetime,
) -> DatasetProvenance:
    try:
        payload = json.loads(provenance_path.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as error:
        raise DatasetValidationError("provenance must be readable UTF-8 JSON") from error
    if not isinstance(payload, dict):
        raise DatasetValidationError("provenance must be a JSON object")
    _require_exact_keys(payload, PROVENANCE_KEYS, "provenance")

    if payload["provenance_schema_version"] != PROVENANCE_SCHEMA_VERSION:
        raise DatasetValidationError("unsupported provenance_schema_version")
    dataset_id = payload["dataset_id"]
    if not isinstance(dataset_id, str) or DATASET_ID_PATTERN.fullmatch(dataset_id) is None:
        raise DatasetValidationError("dataset_id must be an opaque 3-64 character identifier")
    source_type = payload["source_type"]
    if source_type not in {"consented_anonymized", "synthetic"}:
        raise DatasetValidationError("source_type must be consented_anonymized or synthetic")
    for key in ("consent_confirmed", "pii_removed", "point_in_time_correct"):
        if type(payload[key]) is not bool:
            raise DatasetValidationError(f"{key} must be a JSON boolean")
    if source_type == "consented_anonymized" and not payload["consent_confirmed"]:
        raise DatasetValidationError("consented_anonymized data requires consent_confirmed=true")
    if not payload["pii_removed"]:
        raise DatasetValidationError("pii_removed must be true")
    if not payload["point_in_time_correct"]:
        raise DatasetValidationError("point_in_time_correct must be true")
    if payload["recorded_at_policy"] != RECORDED_AT_POLICY:
        raise DatasetValidationError(f"recorded_at_policy must be {RECORDED_AT_POLICY}")

    generated_at_raw = payload["generated_at"]
    if not isinstance(generated_at_raw, str):
        raise DatasetValidationError("generated_at must be an ISO-8601 timestamp string")
    generated_at = cast(datetime, _parse_datetime(generated_at_raw, "generated_at"))
    if generated_at > now.astimezone(UTC) + timedelta(minutes=5):
        raise DatasetValidationError("generated_at cannot be in the future")

    expected_digest = payload["csv_sha256"]
    digest_is_invalid = (
        not isinstance(expected_digest, str)
        or HASHED_KEY_PATTERN.fullmatch(expected_digest) is None
    )
    if digest_is_invalid:
        raise DatasetValidationError("csv_sha256 must be a lowercase SHA-256 digest")
    if sha256_file(csv_path) != expected_digest:
        raise DatasetValidationError("csv_sha256 does not match the source CSV")

    return DatasetProvenance(
        dataset_id=dataset_id,
        source_type=source_type,
        generated_at=generated_at,
        consent_confirmed=payload["consent_confirmed"],
        csv_sha256=expected_digest,
    )


def _validate_headers(headers: list[str] | None) -> None:
    if headers is None:
        raise DatasetValidationError("source CSV is empty")
    if len(headers) != len(set(headers)):
        raise DatasetValidationError("source CSV contains duplicate column names")
    extras = sorted(set(headers) - set(CSV_COLUMNS))
    pii_headers = [
        header
        for header in extras
        if any(token in header.casefold() for token in PII_HEADER_TOKENS)
    ]
    if pii_headers:
        raise DatasetValidationError(f"PII columns are forbidden: {sorted(pii_headers)}")
    if tuple(headers) != CSV_COLUMNS:
        missing = sorted(set(CSV_COLUMNS) - set(headers))
        raise DatasetValidationError(
            "CSV columns must exactly match the ordered whitelist; "
            f"missing={missing}, extra={extras}"
        )


def _parse_source_row(raw: dict[str, str], row_number: int, generated_at: datetime) -> SourceRow:
    for field, value in raw.items():
        if value is None:
            raise DatasetValidationError(f"row {row_number}: {field} is missing")
        if value != value.strip():
            raise DatasetValidationError(f"row {row_number}: {field} contains outer whitespace")

    for field in ("family_key", "member_key", "obligation_key"):
        if HASHED_KEY_PATTERN.fullmatch(raw[field]) is None:
            raise DatasetValidationError(
                f"row {row_number}: {field} must be an opaque lowercase SHA-256 key"
            )

    period_start = _parse_date(raw["period_start"], f"row {row_number}: period_start")
    due_date = _parse_date(raw["due_date"], f"row {row_number}: due_date")
    obligation_created_at = cast(
        datetime,
        _parse_datetime(raw["obligation_created_at"], f"row {row_number}: obligation_created_at"),
    )
    first_payment_recorded_at = _parse_datetime(
        raw["first_payment_recorded_at"],
        f"row {row_number}: first_payment_recorded_at",
        optional=True,
    )
    fully_paid_recorded_at = _parse_datetime(
        raw["fully_paid_recorded_at"],
        f"row {row_number}: fully_paid_recorded_at",
        optional=True,
    )
    expected_amount = _parse_non_negative_integer(
        raw["expected_amount_minor"], f"row {row_number}: expected_amount_minor"
    )
    amount_paid = _parse_non_negative_integer(
        raw["amount_paid_by_due_minor"], f"row {row_number}: amount_paid_by_due_minor"
    )
    payment_count = _parse_non_negative_integer(
        raw["payment_count_by_due"], f"row {row_number}: payment_count_by_due"
    )
    is_backfilled = _parse_boolean(raw["is_backfilled"], f"row {row_number}: is_backfilled")

    if period_start.day != 1:
        raise DatasetValidationError(f"row {row_number}: period_start must be the first day")
    if (due_date.year, due_date.month) != (period_start.year, period_start.month):
        raise DatasetValidationError(
            f"row {row_number}: due_date must be inside period_start month"
        )
    if is_backfilled:
        raise DatasetValidationError(f"row {row_number}: backfilled obligations are forbidden")
    if (due_date - obligation_created_at.date()).days < MINIMUM_DAYS_TO_DUE:
        raise DatasetValidationError(
            f"row {row_number}: obligation must be recorded at least "
            f"{MINIMUM_DAYS_TO_DUE} days before due"
        )
    if expected_amount <= 0:
        raise DatasetValidationError(f"row {row_number}: expected_amount_minor must be positive")
    if amount_paid > expected_amount:
        raise DatasetValidationError(f"row {row_number}: paid amount cannot exceed expected amount")
    if (amount_paid == 0) != (payment_count == 0):
        raise DatasetValidationError(
            f"row {row_number}: payment_count_by_due must agree with amount_paid_by_due_minor"
        )
    if amount_paid > 0 and first_payment_recorded_at is None:
        raise DatasetValidationError(
            f"row {row_number}: paid amount requires first payment timestamp"
        )
    if first_payment_recorded_at is not None:
        if first_payment_recorded_at < obligation_created_at:
            raise DatasetValidationError(f"row {row_number}: first payment predates the obligation")
        first_is_by_due = first_payment_recorded_at.date() <= due_date
        if first_is_by_due != (amount_paid > 0):
            raise DatasetValidationError(
                f"row {row_number}: first payment timing contradicts paid-by-due amount"
            )
    if fully_paid_recorded_at is not None:
        if first_payment_recorded_at is None or fully_paid_recorded_at < first_payment_recorded_at:
            raise DatasetValidationError(
                f"row {row_number}: full settlement must follow the first payment"
            )
        full_is_by_due = fully_paid_recorded_at.date() <= due_date
        if full_is_by_due != (amount_paid == expected_amount):
            raise DatasetValidationError(
                f"row {row_number}: full-settlement timing contradicts paid-by-due amount"
            )
    elif amount_paid == expected_amount:
        raise DatasetValidationError(
            f"row {row_number}: on-time full payment needs settlement timestamp"
        )

    observed_timestamps = [obligation_created_at, first_payment_recorded_at, fully_paid_recorded_at]
    if any(timestamp is not None and timestamp > generated_at for timestamp in observed_timestamps):
        raise DatasetValidationError(f"row {row_number}: recorded timestamp exceeds generated_at")
    if (period_start.year, period_start.month) >= (generated_at.year, generated_at.month):
        raise DatasetValidationError(f"row {row_number}: contribution period is not complete")

    return SourceRow(
        family_key=raw["family_key"],
        member_key=raw["member_key"],
        obligation_key=raw["obligation_key"],
        period_start=period_start,
        obligation_created_at=obligation_created_at,
        due_date=due_date,
        expected_amount_minor=expected_amount,
        amount_paid_by_due_minor=amount_paid,
        first_payment_recorded_at=first_payment_recorded_at,
        fully_paid_recorded_at=fully_paid_recorded_at,
        payment_count_by_due=payment_count,
        is_backfilled=is_backfilled,
    )


def _quality_report(rows: tuple[SourceRow, ...], provenance: DatasetProvenance) -> dict[str, Any]:
    histories = {row.history_key for row in rows}
    periods = {row.period_start for row in rows}
    overdue_count = sum(row.is_overdue for row in rows)
    on_time_count = len(rows) - overdue_count
    gates = {
        "minimum_rows": len(rows) >= MINIMUM_ROWS,
        "minimum_distinct_members": len(histories) >= MINIMUM_HISTORIES,
        "minimum_complete_periods": len(periods) >= MINIMUM_PERIODS,
        "minimum_on_time_class": on_time_count >= MINIMUM_CLASS_ROWS,
        "minimum_overdue_class": overdue_count >= MINIMUM_CLASS_ROWS,
    }
    return {
        "dataset_id": provenance.dataset_id,
        "source_type": provenance.source_type,
        "valid_row_count": len(rows),
        "distinct_member_count": len(histories),
        "distinct_period_count": len(periods),
        "on_time_count": on_time_count,
        "overdue_count": overdue_count,
        "gate_passed": all(gates.values()),
        "gates": gates,
    }


def load_validated_source(
    csv_path: Path,
    provenance_path: Path,
    *,
    now: datetime | None = None,
) -> ValidatedSource:
    """Load data attested to exclude reversed histories, then enforce privacy and quality gates."""

    resolved_csv = csv_path.resolve()
    resolved_provenance = provenance_path.resolve()
    if resolved_csv != APPROVED_SOURCE_CSV.resolve():
        raise DatasetValidationError(
            "source CSV must be the approved private "
            "storage/app/private/payment-risk/source/member-periods.csv export"
        )
    if resolved_csv.suffix.casefold() != ".csv":
        raise DatasetValidationError("source dataset must use a .csv extension")
    if not resolved_csv.is_file() or not resolved_provenance.is_file():
        raise DatasetValidationError("source CSV and provenance JSON must both exist")

    current_time = now or datetime.now(UTC)
    provenance = _load_provenance(
        resolved_provenance,
        resolved_csv,
        now=current_time,
    )
    try:
        with resolved_csv.open("r", encoding="utf-8", newline="") as handle:
            reader = csv.DictReader(handle)
            _validate_headers(reader.fieldnames)
            rows = tuple(
                _parse_source_row(raw, row_number, provenance.generated_at)
                for row_number, raw in enumerate(reader, start=2)
            )
    except (OSError, UnicodeDecodeError, csv.Error) as error:
        raise DatasetValidationError("source CSV must be readable strict UTF-8 CSV") from error
    if not rows:
        raise DatasetValidationError("source CSV must contain data rows")

    obligation_keys = [row.obligation_key for row in rows]
    history_periods = [(row.history_key, row.period_start) for row in rows]
    if len(obligation_keys) != len(set(obligation_keys)):
        raise DatasetValidationError("obligation_key must be globally unique")
    if len(history_periods) != len(set(history_periods)):
        raise DatasetValidationError("each member history may have only one obligation per period")

    ordered_rows = tuple(
        sorted(rows, key=lambda row: (row.period_start, row.family_key, row.member_key))
    )
    report = _quality_report(ordered_rows, provenance)
    if not report["gate_passed"]:
        failed = sorted(name for name, passed in report["gates"].items() if not passed)
        raise DataQualityGateError(
            f"minimum data-quality gates failed: {failed}",
            report,
        )

    return ValidatedSource(rows=ordered_rows, provenance=provenance, data_quality=report)
