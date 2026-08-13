-- ===========================================================================
-- L-SIAMS · 012 · Carry enrollment_station through v_device_status
-- ===========================================================================
-- Every enrolment picker orders stations first, and they all read the view
-- rather than `devices` so they can show health in the same breath.
-- ===========================================================================

SET NAMES utf8mb4;
CREATE OR REPLACE VIEW v_device_status AS
SELECT
  dev.id AS device_row_id,
  dev.device_id,
  dev.device_name,
  dev.mac_address,
  dev.ip_address,
  dev.firmware_version,
  dev.device_role,
  dev.enrollment_station,
  dev.classroom_id,
  c.room_number,
  c.building,
  dev.wifi_signal,
  dev.queue_depth,
  dev.uptime_seconds,
  dev.battery_percent,
  dev.status AS configured_status,
  dev.claim_status,
  dev.last_heartbeat_at,
  TIMESTAMPDIFF(SECOND, dev.last_heartbeat_at, NOW()) AS seconds_since_heartbeat,
  CASE
    WHEN dev.status IN ('disabled','decommissioned','suspended') THEN 'disabled'
    WHEN dev.claim_status <> 'claimed' THEN 'pending'
    WHEN dev.last_heartbeat_at IS NULL THEN 'offline'
    WHEN TIMESTAMPDIFF(SECOND, dev.last_heartbeat_at, NOW()) <= 45 THEN 'online'
    WHEN TIMESTAMPDIFF(SECOND, dev.last_heartbeat_at, NOW()) <= 90 THEN 'warning'
    ELSE 'offline'
  END AS health,
  dev.ip_allowlist,
  dev.heartbeat_interval_sec,
  dev.sync_interval_sec,
  dev.offline_queue_limit,
  dev.location_note,
  ak.key_id,
  ak.secret_last_four,
  ak.status AS key_status,
  ak.created_at AS key_created_at,
  DATEDIFF(NOW(), ak.created_at) AS key_age_days,
  ak.last_used_at AS key_last_used_at,
  ak.use_count AS key_use_count,
  sess.session_id AS active_session_id,
  sess.session_code AS active_session_code
FROM devices dev
LEFT JOIN classrooms c ON c.classroom_id = dev.classroom_id
-- Matching on the primary key of one deliberately chosen row, rather than on a
-- condition several rows can satisfy, is what makes this a one-to-one join.
-- FIELD() puts 'active' ahead of 'rotating' so the key the terminal should be
-- using wins over the one it is being moved off.
LEFT JOIN api_keys ak ON ak.api_key_id = (
    SELECT inner_ak.api_key_id
      FROM api_keys inner_ak
     WHERE inner_ak.device_row_id = dev.id
       AND inner_ak.status IN ('active','rotating')
     ORDER BY FIELD(inner_ak.status, 'active', 'rotating'), inner_ak.created_at DESC
     LIMIT 1
)
LEFT JOIN attendance_sessions sess ON sess.device_row_id = dev.id AND sess.status = 'open'
WHERE dev.deleted_at IS NULL;
