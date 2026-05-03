# Implementation Plan: A1 Capability Parity

## Tasks

- [x] 1. Snapshot ActiveRecord schema compatibility
  - [x] 1.1 Add a failing regression test proving `App\Model\Snapshot` declares production snapshot columns, including `space`.
  - [x] 1.2 Update `App\Model\Snapshot` public properties so Yii3 hydration accepts production `snapshot` rows.
  - [x] 1.3 Verify `space` is not exposed by default or through the A1-supported extra field list.
  - _Requirements: 1.1, 1.2, 1.3, 1.4, 2.1, 2.3, 2.4_

- [x] 2. A1-compatible snapshot filters
  - [x] 2.1 Add failing tests for `id`, `verse_id`, `created_by`, `created_at`, `uuid`, `code`, `data`, `metas`, and `resources` filters in `SnapshotSearch`.
    - Result: RED tests captured missing base filter parity before implementation.
  - [x] 2.2 Implement shared base filter application in `SnapshotSearch`.
  - [x] 2.3 Verify invalid or empty `tags` filters do not add unnecessary joins.
  - _Requirements: 3.4, 3.5, 4.3_

- [x] 3. Public/checkin query parity
  - [x] 3.1 Add failing tests that public/checkin queries do not require the extra `verse` join or forced ordering that A1 does not use.
  - [x] 3.2 Update public/checkin query construction to match A1's Yii2 query shape.
  - [x] 3.3 Verify list endpoints still paginate and serialize snapshot items as `[]` by default.
  - _Requirements: 2.1, 3.1, 3.2, 3.3, 4.4_

- [x] 4. Active A1 route coverage
  - [x] 4.1 Add failing route/controller tests for `GET /v1/phototype/info`.
    - Result: RED tests captured the missing active A1 route before implementation.
  - [x] 4.2 Implement `PhototypeController` and route wiring.
  - [x] 4.3 Implement A1-compatible phototype/resource/file serialization.
  - _Requirements: 5.1, 5.2, 5.3, 5.4_

- [x] 5. Focused verification
  - [x] 5.1 Run focused PHPUnit tests for snapshot model, search, query service, V1/V2 controllers, and phototype route/controller behavior.
    - Result: `vendor/bin/phpunit tests/Unit/Model/SnapshotSchemaCompatibilityTest.php tests/Unit/Service/SnapshotDiagnosticsServiceTest.php tests/Unit/Controller/DebugControllerTest.php tests/Unit/Search/SnapshotSearchTest.php tests/Property/SnapshotQueryPropertyTest.php tests/Unit/Service/SnapshotQueryServiceTest.php tests/Unit/Controller/V1/ServerControllerTest.php tests/Unit/Controller/V2/SnapshotControllerTest.php tests/Unit/Controller/V1/PhototypeControllerTest.php tests/Unit/Config/RoutesTest.php tests/Property/SerializationPropertyTest.php` passed with 134 tests and 7325 assertions.
  - [x] 5.2 Run or document the side-by-side comparison command for A1/Y1 parity.
    - Result: live side-by-side must be run after this code is deployed to Y1; before deployment the live `https://y1.d.xrteeth.com` host still serves the old code. The Docker-based local comparison is blocked on this workstation because Docker daemon is not running.
  - [x] 5.3 Record any remaining non-snapshot capability gaps as explicit follow-up tasks instead of silently broadening this change.
    - Result: full PHPUnit still requires a reachable Redis for auth/refresh-token tests; sandbox run reports `Operation not permitted [tcp://127.0.0.1:6379]`, and sandbox-external run reports `Connection refused [tcp://127.0.0.1:6379]`. This is environment setup, not part of the snapshot/phototype parity change.
  - _Requirements: 4.1, 4.2, 4.3, 4.4_
