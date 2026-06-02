import * as db from './supabase.mjs';
import { clientIp, browserInfo, deviceInfo } from './security.mjs';

export async function recordLogin(userId, email, status, role, event) {
  await db.from('login_history', 'POST', {
    user_id: userId,
    email,
    login_status: status,
    ip_address: clientIp(event),
    browser_info: browserInfo(event),
    device_info: deviceInfo(),
    role,
  }, null, true);
}

export async function recordActivity(adminId, action, affected = null, severity = 'info', event) {
  await db.from('activity_logs', 'POST', {
    admin_id: adminId,
    action,
    affected_record: affected,
    ip_address: clientIp(event),
    browser_info: browserInfo(event),
    device_info: deviceInfo(),
    severity_level: severity,
  }, null, true);
}

export async function getLoginHistory(page = 1, limit = 20) {
  const offset = (page - 1) * limit;
  return db.from('login_history', 'GET', null, `select=*&order=created_at.desc&limit=${limit}&offset=${offset}`, true);
}

export async function getActivityLogs(page = 1, limit = 20) {
  const offset = (page - 1) * limit;
  return db.from(
    'activity_logs',
    'GET',
    null,
    `select=*,users(fullname,username)&order=created_at.desc&limit=${limit}&offset=${offset}`,
    true
  );
}
