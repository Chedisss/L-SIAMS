<?php
// Prepares the Week 7 integration test database: closes open sessions, moves schedule 18 to
// cover the current time, clears login lockouts and rate limits, and issues a fresh API key
// for terminal DEMO-DEV-0004. Point the require below at the test copy, never at live data.
require '/home/user/lsiams-testcopy/bootstrap.php';
use App\Core\Database; use App\Services\ApiKeyService; use App\Services\AttendanceSessionService;
$db = Database::instance();
foreach ($db->select("SELECT session_id FROM attendance_sessions WHERE status='open'") as $s) AttendanceSessionService::close((int)$s['session_id']);
$start = gmdate('H:i:00', time() - 300); $end = gmdate('H:i:00', time() + 3300);
$db->execute("UPDATE schedules SET start_time=:s, end_time=:e WHERE schedule_id=18", ['s'=>$start,'e'=>$end]);
$db->execute("UPDATE api_keys SET status='revoked' WHERE device_row_id=4 AND status='active'");
$db->execute("UPDATE users SET locked_until=NULL, failed_login_count=0");
$db->execute("DELETE FROM rate_limits"); $db->execute("DELETE FROM login_attempts");
$db->execute("UPDATE user_sessions SET terminated_at=NOW(), termination_reason='logout' WHERE terminated_at IS NULL");
$db->execute("DELETE FROM students WHERE student_number='2026-IT-0001'");
$k = ApiKeyService::generateForDevice(4, 1);
$db->execute("UPDATE devices SET last_heartbeat_at=NOW(), status='active', claim_status='claimed' WHERE id=4");
$cards = $db->select("SELECT r.card_uid, st.student_id FROM rfid_cards r JOIN students st ON st.student_id=r.student_id WHERE r.status='active' AND st.section_id=(SELECT section_id FROM schedules WHERE schedule_id=18) AND NOT EXISTS (SELECT 1 FROM attendance_records ar JOIN attendance_sessions ss ON ss.session_id=ar.session_id WHERE ar.student_id=st.student_id AND ss.schedule_id=18 AND ss.session_date=CURDATE() AND (ar.time_in IS NOT NULL OR ar.arrival_status<>'absent')) ORDER BY st.student_id LIMIT 3");
$other = $db->selectOne("SELECT r.card_uid FROM rfid_cards r JOIN students st ON st.student_id=r.student_id WHERE r.status='active' AND st.section_id<>(SELECT section_id FROM schedules WHERE schedule_id=18) LIMIT 1");
echo json_encode(['device_id'=>'DEMO-DEV-0004','api_key'=>$k['api_key'],'hmac'=>$k['hmac_secret'],'cards'=>$cards,'other_section_card'=>$other['card_uid']]);
