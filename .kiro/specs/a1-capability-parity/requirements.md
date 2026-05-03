# Requirements Document: A1 Capability Parity

## Introduction

Y1 (`yii3-a1`) is the Yii3 replacement for A1 (`mrpp-api-a1`). The two services must expose equivalent capabilities for the API surface used by clients, with matching status codes, response structures, pagination headers, authentication requirements, and snapshot serialization behavior.

This remediation spec addresses gaps found after deployment, especially snapshot endpoints that return HTTP 500 on Y1 while A1 returns valid snapshot responses.

## Requirements

### Requirement 1: Snapshot ActiveRecord Schema Compatibility

**User Story:** As an API client, I need Y1 to read every snapshot row that A1 can read, so Yii3 hydration never fails when the production `snapshot` table contains legacy or newer columns.

#### Acceptance Criteria

1. THE `App\Model\Snapshot` model SHALL declare public properties for every column present in the production `snapshot` table that A1 reads from.
2. THE `App\Model\Snapshot` model SHALL support the `space` column without exposing it by default.
3. THE JSON-like snapshot columns `data`, `metas`, `resources`, `managers`, and `space` SHALL accept strings, arrays, objects, or null values returned by the Yii3 MySQL driver.
4. WHEN a snapshot row contains JSON column values, THE API SHALL not return HTTP 500 during ActiveRecord hydration or serialization.

### Requirement 2: Snapshot Serialization Compatibility

**User Story:** As a frontend client, I need Y1 snapshot list and detail responses to match A1's Yii2 serializer contract, so existing client parsing keeps working.

#### Acceptance Criteria

1. WHEN a snapshot response is requested without `expand`, THE API SHALL return `[]` for each snapshot item, matching A1 `fields()=[]`.
2. WHEN a snapshot response includes `expand`, THE API SHALL include only the requested A1-supported extra fields.
3. THE supported snapshot extra fields SHALL remain `id`, `name`, `description`, `image`, `author_id`, `author`, `uuid`, `verse_id`, `code`, `data`, `metas`, `resources`, and `managers`.
4. THE `space` column SHALL not be included in snapshot JSON unless A1 adds equivalent behavior later.

### Requirement 3: V1 Snapshot Query Compatibility

**User Story:** As an API client, I need `/v1/server/public`, `/v1/server/checkin`, `/v1/server/private`, `/v1/server/group`, and `/v1/server/snapshot` on Y1 to behave like A1.

#### Acceptance Criteria

1. WHEN requesting `GET /v1/server/public`, THE API SHALL query snapshots associated with `property.key = 'public'` and return HTTP 200 when matching rows exist.
2. WHEN requesting `GET /v1/server/checkin`, THE API SHALL query snapshots associated with `property.key = 'checkin'` and return HTTP 200 when matching rows exist.
3. THE public and checkin query joins SHALL match the A1 Yii2 query shape closely enough to avoid extra behavior not present in A1.
4. THE snapshot list endpoints SHALL support A1-compatible filter parameters: `id`, `verse_id`, `created_by`, `created_at`, `uuid`, `code`, `data`, `metas`, `resources`, and `tags`.
5. WHEN `tags` filters match no snapshots, THE API SHALL return HTTP 200 with `[]` and pagination headers.

### Requirement 4: Capability Comparison Workflow

**User Story:** As a maintainer, I need a repeatable way to compare A1 and Y1, so parity regressions are visible before deployment.

#### Acceptance Criteria

1. THE existing side-by-side comparison script SHALL include the A1/Y1 endpoints used for parity validation.
2. THE test suite SHALL include a regression test proving snapshot hydration works when the table contains the `space` column.
3. THE test suite SHALL include query-construction tests for A1-compatible snapshot filters.
4. BEFORE considering this spec complete, THE maintainer SHALL run the focused PHPUnit tests that cover snapshot model, search, service, and controller behavior.

### Requirement 5: Active A1 Route Coverage

**User Story:** As an API client, I need every active A1 route to exist on Y1, so switching hosts does not remove API capabilities.

#### Acceptance Criteria

1. THE Y1 route table SHALL include active A1 routes from the deployed Yii2 `files/api/config/web.php` configuration.
2. WHEN requesting `GET /v1/phototype/info?type={type}`, THE API SHALL query `phototype.type` and return the A1-compatible shape `{id, data, title, resource}` when found.
3. WHEN no phototype exists for the requested type, THE API SHALL return HTTP 400 with the A1-compatible message `model not found.`.
4. THE `resource` field SHALL use the A1 resource serialization shape: `id`, `info`, `uuid`, `type`, and `file`.
