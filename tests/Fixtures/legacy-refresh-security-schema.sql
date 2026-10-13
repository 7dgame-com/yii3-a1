-- Temporary legacy compatibility ledger. Apply only through the fixed development operator.
-- statement
CREATE TABLE identity_legacy_security_versions (
  user_id INT NOT NULL PRIMARY KEY,
  epoch BIGINT UNSIGNED NOT NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
-- statement
CREATE TRIGGER identity_legacy_security_user_insert AFTER INSERT ON user
FOR EACH ROW
INSERT INTO identity_legacy_security_versions(user_id,epoch,updated_at)
VALUES(NEW.id,1,UTC_TIMESTAMP(3))
ON DUPLICATE KEY UPDATE epoch=epoch+1,updated_at=UTC_TIMESTAMP(3);
-- statement
CREATE TRIGGER identity_legacy_security_user_update AFTER UPDATE ON user
FOR EACH ROW
BEGIN
  IF NOT (NEW.id <=> OLD.id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Identity user IDs are immutable';
  END IF;
  IF NOT (CAST(NEW.password_hash AS BINARY) <=> CAST(OLD.password_hash AS BINARY))
    OR NOT (CAST(NEW.auth_key AS BINARY) <=> CAST(OLD.auth_key AS BINARY))
    OR NOT (NEW.status <=> OLD.status) THEN
    INSERT INTO identity_legacy_security_versions(user_id,epoch,updated_at)
    VALUES(NEW.id,1,UTC_TIMESTAMP(3))
    ON DUPLICATE KEY UPDATE epoch=epoch+1,updated_at=UTC_TIMESTAMP(3);
  END IF;
END;
-- statement
CREATE TRIGGER identity_legacy_security_user_delete AFTER DELETE ON user
FOR EACH ROW
INSERT INTO identity_legacy_security_versions(user_id,epoch,updated_at)
VALUES(OLD.id,1,UTC_TIMESTAMP(3))
ON DUPLICATE KEY UPDATE epoch=epoch+1,updated_at=UTC_TIMESTAMP(3);
-- statement
INSERT INTO identity_legacy_security_versions(user_id,epoch,updated_at)
SELECT id,1,UTC_TIMESTAMP(3) FROM user
ON DUPLICATE KEY UPDATE epoch=identity_legacy_security_versions.epoch;
