import * as db from './supabase.mjs';
import * as auth from './auth.mjs';
import * as email from './email.mjs';
import * as logs from './logs.mjs';
import { sanitizeEmail, sanitizeString } from './security.mjs';

function asArray(data) {
  return Array.isArray(data) ? data : [];
}

export async function stats(user) {
  const allowed = ['admin', 'superadmin'];
  if (!user || !allowed.includes(user.role)) {
    return { error: 'Unauthorized. Please sign in.', status: 401 };
  }

  const students = await db.from('users', 'GET', null, 'select=id&role=eq.student&is_deleted=eq.false', true);
  const admins = await db.from('users', 'GET', null, 'select=id&role=eq.admin&is_deleted=eq.false', true);
  const beneficiaries = await db.from('beneficiaries', 'GET', null, 'select=status,user_id&is_deleted=eq.false', true);

  const totalStudents = Array.isArray(students.data) ? students.data.length : 0;
  const totalAdmins = Array.isArray(admins.data) ? admins.data.length : 0;
  const totalBeneficiaries = Array.isArray(beneficiaries.data) ? beneficiaries.data.length : 0;
  let updated = 0;
  let notUpdated = 0;

  for (const b of beneficiaries.data || []) {
    if (b.status === 'Updated') updated++;
    else notUpdated++;
  }
  const withoutRecord = Math.max(0, totalStudents - (updated + notUpdated));
  notUpdated += withoutRecord;

  return {
    data: {
      total_students: totalStudents,
      total_admins: totalAdmins,
      total_beneficiaries: totalBeneficiaries,
      updated_records: updated,
      not_updated_records: notUpdated,
    },
  };
}

export async function students(query) {
  const page = Math.max(1, parseInt(query.page, 10) || 1);
  const limit = Math.min(50, Math.max(5, parseInt(query.limit, 10) || 10));
  const offset = (page - 1) * limit;
  const search = String(query.search || '').trim();
  const statusFilter = query.status || '';
  const allowedStatuses = ['Updated', 'Not Updated', 'Update Beneficiary'];
  const hasStatusFilter = allowedStatuses.includes(statusFilter);

  let q =
    'select=id,student_id,fullname,email,username,created_at,beneficiaries(status,updated_at,fullname,relationship,contact_number,address)&role=eq.student&is_deleted=eq.false&order=fullname.asc';
  if (search) {
    const s = encodeURIComponent(`%${search}%`);
    q += `&or=(fullname.ilike.${s},email.ilike.${s},student_id.ilike.${s},username.ilike.${s})`;
  }
  q += hasStatusFilter ? '&limit=10000' : `&limit=${limit}&offset=${offset}`;

  const res = await db.from('users', 'GET', null, q, true);
  if (!res.ok) return { error: res.error || 'Failed to load students.', status: res.status || 500 };
  let rows = asArray(res.data);

  if (hasStatusFilter) {
    rows = rows.filter((row) => {
      const ben = row.beneficiaries?.[0];
      const status = ben?.status ?? 'Not Updated';
      return status === statusFilter;
    });
    rows = rows.slice(offset, offset + limit);
  }

  return { data: { students: rows, page, limit } };
}

export async function notifications(query) {
  const page = Math.max(1, parseInt(query.page, 10) || 1);
  const limit = Math.min(50, Math.max(5, parseInt(query.limit, 10) || 20));
  const offset = (page - 1) * limit;
  const res = await db.from(
    'notifications',
    'GET',
    null,
    `select=*,users(fullname,email)&order=created_at.desc&limit=${limit}&offset=${offset}`,
    true
  );
  if (!res.ok) return { error: res.error || 'Failed to load notifications.', status: res.status || 500 };
  return { data: { notifications: asArray(res.data) } };
}

export async function beneficiaryUpdateRequests(query) {
  const search = String(query.search || '').trim();
  let q =
    'select=id,student_id,fullname,email,beneficiaries(status)&role=eq.student&is_deleted=eq.false&order=fullname.asc&limit=10000';
  if (search) {
    const s = encodeURIComponent(`%${search}%`);
    q += `&or=(fullname.ilike.${s},email.ilike.${s},student_id.ilike.${s},username.ilike.${s})`;
  }
  const res = await db.from('users', 'GET', null, q, true);
  if (!res.ok) return { error: res.error || 'Failed to load students.', status: res.status || 500 };
  return { data: { students: asArray(res.data) } };
}

export async function sendBeneficiaryUpdateRequest(admin, body, event) {
  const userId = String(body.user_id || '').trim();
  if (!userId) return { error: 'Student user id is required.', status: 400 };
  const student = await findStudent(userId);
  if (!student) return { error: 'Student not found.', status: 404 };
  const result = await requestBeneficiaryUpdate(student, admin.id, event);
  return { data: result, message: beneficiaryRequestMessage(result) };
}

export async function sendAllBeneficiaryUpdateRequests(admin, event) {
  const res = await db.from(
    'users',
    'GET',
    null,
    'select=id,student_id,fullname,email&role=eq.student&is_deleted=eq.false&order=fullname.asc&limit=10000',
    true
  );
  const students = res.data || [];
  let sent = 0;
  let mailFailed = 0;
  let statusFailed = 0;
  for (const student of students) {
    const result = await requestBeneficiaryUpdate(student, admin.id, event);
    if (result.email_sent) sent++;
    else if (result.status_updated) mailFailed++;
    else statusFailed++;
  }
  return {
    data: { total: students.length, sent, mail_failed: mailFailed, status_failed: statusFailed },
    message: 'Beneficiary update requests processed.',
  };
}

export async function failedNotifications() {
  const res = await db.from('failed_notifications', 'GET', null, 'select=*&order=created_at.desc&limit=50', true);
  if (!res.ok) return { error: res.error || 'Failed to load failed notifications.', status: res.status || 500 };
  return { data: { failed: asArray(res.data) } };
}

export async function retryNotification(admin, body, event) {
  const id = body.id;
  if (!id) return { error: 'Notification id required.', status: 400 };
  const result = await email.retryFailed(id);
  await logs.recordActivity(admin.id, `Retried failed notification ${id}`, null, 'info', event);
  if (!result.success) return { error: result.message, status: 500 };
  return { message: result.message };
}

export async function loginHistory(query) {
  const page = Math.max(1, parseInt(query.page, 10) || 1);
  const res = await logs.getLoginHistory(page);
  if (!res.ok) return { error: res.error || 'Failed to load login history.', status: res.status || 500 };
  return { data: { history: asArray(res.data) } };
}

export async function activityLogs(query) {
  const page = Math.max(1, parseInt(query.page, 10) || 1);
  const res = await logs.getActivityLogs(page);
  if (!res.ok) return { error: res.error || 'Failed to load activity logs.', status: res.status || 500 };
  return { data: { logs: asArray(res.data) } };
}

export async function users(query) {
  const page = Math.max(1, parseInt(query.page, 10) || 1);
  const limit = Math.min(100, Math.max(5, parseInt(query.limit, 10) || 25));
  const offset = (page - 1) * limit;
  const search = String(query.search || '').trim();
  const role = String(query.role || '').trim();

  let q = `select=id,student_id,fullname,email,username,role,permissions,is_deleted,created_at&order=created_at.desc&limit=${limit}&offset=${offset}`;
  if (['student', 'admin', 'superadmin'].includes(role)) q += `&role=eq.${role}`;
  if (search) {
    const s = encodeURIComponent(`%${search}%`);
    q += `&or=(fullname.ilike.${s},email.ilike.${s},student_id.ilike.${s},username.ilike.${s})`;
  }

  const res = await db.from('users', 'GET', null, q, true);
  if (!res.ok) return { error: res.error || 'Failed to load users.', status: res.status || 500 };
  return { data: { users: asArray(res.data), page, limit } };
}

export async function createUser(actor, body, event) {
  const emailAddr = sanitizeEmail(body.email);
  const username = sanitizeString(body.username);
  const fullname = String(body.fullname || '').trim();
  const password = body.password || '';
  const role = String(body.role || 'admin').trim();

  if (!emailAddr || !username || !fullname || !password) {
    return { error: 'Email, username, full name, and password are required.', status: 400 };
  }
  if (!['admin', 'superadmin'].includes(role)) {
    return { error: 'Role must be admin or superadmin.', status: 400 };
  }

  const result = await auth.registerRole(
    { email: emailAddr, username, fullname, student_id: body.student_id || '', password },
    role
  );
  if (!result.success) return { error: result.message, status: 400 };
  await logs.recordActivity(actor.id, `Created ${role} account for ${emailAddr}`, null, 'info', event);
  return { message: result.message };
}

export async function updateUser(actor, body, event) {
  const id = String(body.id || '').trim();
  if (!id) return { error: 'User id is required.', status: 400 };

  const payload = {};
  if (body.role !== undefined) {
    const role = String(body.role).trim();
    if (!['student', 'admin', 'superadmin'].includes(role)) {
      return { error: 'Invalid role.', status: 400 };
    }
    payload.role = role;
  }
  if (body.permissions !== undefined) {
    payload.permissions = sanitizePermissions(body.permissions);
  }
  if (body.is_deleted !== undefined) {
    if (id === actor.id && body.is_deleted) {
      return { error: 'You cannot disable your own account.', status: 400 };
    }
    payload.is_deleted = Boolean(body.is_deleted);
  }
  if (!Object.keys(payload).length) return { error: 'No changes submitted.', status: 400 };

  const res = await db.from('users', 'PATCH', payload, `id=eq.${encodeURIComponent(id)}`, true);
  if (!res.ok) return { error: res.error || 'Failed to update user.', status: 500 };
  await logs.recordActivity(actor.id, 'Updated user role/permissions', id, 'info', event);
  return { data: { user: res.data?.[0] ?? null }, message: 'User updated.' };
}

export async function resetUserPassword(actor, body, event) {
  const emailAddr = sanitizeEmail(body.email);
  if (!emailAddr) return { error: 'Email is required.', status: 400 };
  const result = await auth.passwordReset.sendVerificationCode(emailAddr);
  if (!result.success) return { error: result.message, status: 500 };
  await logs.recordActivity(actor.id, `Requested password reset for ${emailAddr}`, null, 'info', event);
  return { message: result.message };
}

async function findStudent(userId) {
  const res = await db.from(
    'users',
    'GET',
    null,
    `select=id,student_id,fullname,email&role=eq.student&is_deleted=eq.false&id=eq.${encodeURIComponent(userId)}&limit=1`,
    true
  );
  return res.ok && res.data?.[0] ? res.data[0] : null;
}

async function requestBeneficiaryUpdate(student, adminId, event) {
  const userId = student.id || '';
  if (!userId) {
    return { status_updated: false, email_sent: false, error: 'Student user id is missing.' };
  }

  const existing = await db.from(
    'beneficiaries',
    'GET',
    null,
    `select=beneficiary_id&user_id=eq.${encodeURIComponent(userId)}&is_deleted=eq.false&limit=1`,
    true
  );

  const payload = {
    user_id: userId,
    status: 'Update Beneficiary',
    updated_at: new Date().toISOString(),
  };

  let statusRes;
  if (existing.ok && existing.data?.[0]?.beneficiary_id) {
    statusRes = await db.from(
      'beneficiaries',
      'PATCH',
      payload,
      `beneficiary_id=eq.${existing.data[0].beneficiary_id}`,
      true
    );
  } else {
    statusRes = await db.from(
      'beneficiaries',
      'POST',
      {
        ...payload,
        fullname: '',
        relationship: '',
        contact_number: '',
        address: '',
      },
      null,
      true
    );
  }

  if (!statusRes.ok) {
    await logs.recordActivity(adminId, 'Failed beneficiary update request status change', userId, 'info', event);
    return {
      status_updated: false,
      email_sent: false,
      status: 'Update Beneficiary',
      error: statusRes.error || 'Could not update beneficiary status.',
      hint: 'Run db/beneficiary_update_request_status_migration.sql in Supabase if needed.',
    };
  }

  const sent = await email.send(
    student.email || '',
    'Beneficiary Information Update Required',
    'You are required to review and update your beneficiary information in the Student Insurance Tracking System. Please sign in to your student dashboard, review your details, and click Save after making the required updates.',
    userId
  );

  await logs.recordActivity(adminId, 'Sent beneficiary update request', userId, 'info', event);
  return {
    status_updated: true,
    email_sent: sent,
    status: 'Update Beneficiary',
    error: sent ? null : email.getLastError(),
    hint: sent ? null : 'Configure SMTP env vars. Status was updated but email was not sent.',
  };
}

function beneficiaryRequestMessage(result) {
  if (result.status_updated && result.email_sent) return 'Notification sent and status updated.';
  if (result.status_updated) return 'Status updated to Update Beneficiary, but the email was not sent. Configure mail settings.';
  return 'Could not update the student status. Run the beneficiary status migration and try again.';
}

function sanitizePermissions(permissions) {
  const input = permissions && typeof permissions === 'object' ? permissions : {};
  return {
    manage_students: Boolean(input.manage_students),
    track_insurance: Boolean(input.track_insurance),
    manage_notifications: Boolean(input.manage_notifications),
    view_logs: Boolean(input.view_logs),
  };
}
