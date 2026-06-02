import * as db from './supabase.mjs';
import * as email from './email.mjs';
import * as logs from './logs.mjs';
import { sanitizeString } from './security.mjs';

function isStaff(user) {
  return ['admin', 'superadmin'].includes(user?.role);
}

export async function show(user, accessToken, query) {
  const userId = query.user_id || user.id;
  const staff = isStaff(user);
  if (userId !== user.id && !staff) {
    return { error: 'Forbidden.', status: 403 };
  }

  const res = await db.from(
    'beneficiaries',
    'GET',
    null,
    `select=*&user_id=eq.${encodeURIComponent(userId)}&is_deleted=eq.false&limit=1`,
    staff,
    accessToken
  );
  const record = res.ok && res.data?.[0] ? res.data[0] : null;
  return { data: { beneficiary: record } };
}

export async function save(user, accessToken, body, event) {
  const staff = isStaff(user);
  const targetUserId = body.user_id || user.id;
  if (targetUserId !== user.id && !staff) {
    return { error: 'Forbidden.', status: 403 };
  }

  const payload = {
    user_id: targetUserId,
    fullname: sanitizeString(body.fullname),
    relationship: sanitizeString(body.relationship),
    contact_number: sanitizeString(body.contact_number),
    address: sanitizeString(body.address),
    status: 'Updated',
    updated_at: new Date().toISOString(),
  };

  const existing = await db.from(
    'beneficiaries',
    'GET',
    null,
    `select=beneficiary_id&user_id=eq.${encodeURIComponent(targetUserId)}&is_deleted=eq.false&limit=1`,
    staff,
    accessToken
  );

  let res;
  if (existing.ok && existing.data?.[0]?.beneficiary_id) {
    const id = existing.data[0].beneficiary_id;
    res = await db.from('beneficiaries', 'PATCH', payload, `beneficiary_id=eq.${id}`, staff, accessToken);
  } else {
    res = await db.from('beneficiaries', 'POST', payload, null, staff, accessToken);
  }

  if (!res.ok) return { error: 'Failed to save beneficiary.', status: 500 };

  if (staff && targetUserId !== user.id) {
    await logs.recordActivity(user.id, `Updated beneficiary for user ${targetUserId}`, targetUserId, 'info', event);
    const profile = await db.from('users', 'GET', null, `select=email,fullname&id=eq.${targetUserId}`, true);
    if (profile.ok && profile.data?.[0]?.email) {
      await email.send(
        profile.data[0].email,
        'Beneficiary Information Updated',
        'An administrator updated your beneficiary information. Please review your student dashboard.',
        targetUserId
      );
    }
  }

  const saved = Array.isArray(res.data) ? res.data[0] : res.data;
  return { data: { beneficiary: saved }, message: 'Beneficiary information saved successfully.' };
}
