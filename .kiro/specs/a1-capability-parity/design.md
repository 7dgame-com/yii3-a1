# Design Document: A1 Capability Parity

## Overview

Y1 already implements the main Yii3 routes for the A1 API, but the production failure shows that its data model is stricter than Yii2's ActiveRecord layer. The primary design is to make the Yii3 snapshot model tolerant of the production `snapshot` schema while preserving the Yii2 serialization contract.

The work is intentionally narrow: align snapshot model hydration, snapshot serialization, and V1/V2 snapshot query construction with A1. Broader API parity remains validated by the existing `docker/test-api-compare.sh` workflow.

## Architecture

### Snapshot Model

`App\Model\Snapshot` remains the single ActiveRecord for the `snapshot` table. It will declare the `space` public property and widen JSON-like column types to accept values returned by the Yii3 MySQL driver. Serialization remains explicit through `jsonSerialize()`, `toExpandedArray()`, and `toFullArray()`.

### Snapshot Search

`App\Search\SnapshotSearch` will keep building `ActiveQuery` objects directly. It will add a shared A1-compatible filter helper for the base search fields, and then layer public/checkin/private/group scope filters on top.

Public and checkin queries should avoid extra joins or ordering that A1 does not require. Tag filtering still joins `verse_tags` only when `tags` contains valid positive IDs.

### Services and Controllers

`SnapshotQueryService` keeps caching paginated results for 30 seconds. V1 and V2 controllers continue delegating to the service and using the existing serializer helpers. The expected external behavior is that snapshot rows with production JSON/schema fields no longer produce HTTP 500.

`PhototypeController` will be added for the active A1 route `GET /v1/phototype/info`. The controller delegates to a small service so tests can verify found and not-found behavior without needing a live database.

## Error Handling

Hydration mismatches are treated as bugs, not as user-facing errors. The API should not catch and hide them in controllers. Instead, model property declarations and tests must prevent the mismatch.

## Testing Strategy

1. Add failing tests first for snapshot model schema compatibility, including the `space` column.
2. Add failing tests for A1-compatible filter query construction.
3. Update the implementation only after the failing tests demonstrate the gap.
4. Run focused PHPUnit suites for snapshot model/search/service/controller behavior.
5. Add route/controller tests for active A1 routes that were missing in Y1.
6. Use `docker/test-api-compare.sh` or live curl checks after deployment to verify A1/Y1 endpoint parity.

## Non-Goals

- Do not expose `space` in API JSON unless A1 exposes it.
- Do not redesign auth, Redis, health, Swagger, or deployment flows unless a parity test proves they differ.
- Do not add new API features beyond A1 behavior.
