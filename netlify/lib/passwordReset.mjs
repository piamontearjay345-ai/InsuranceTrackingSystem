import bcrypt from 'bcryptjs';
import crypto from 'crypto';
import * as db from './supabase.mjs';
import * as email from './email.mjs';
import { sanitizeEmail } from './security.mjs';
import { env, envInt } from './env.mjs';

const codeExpiry = () => envInt('PASSWORD_RESET_CODE_EXPIRY_MINUTES', 15);
const tokenExpiry = () => envInt('PASSWORD_RESET_TOKEN_EXPIRY_MINUTES', 30);
const maxAttempts = () => envInt('PASSWORD_RESET_MAX_ATTEMPTS', 5);

async function findUserByEmail(email) {
  const res = await db.from(
    'users',
    'GET',
    null,
    `select=id,email,fullname&email=eq.${encodeURIComponent(email)}&is_deleted=eq.false`,
    true
  );
  return res.ok && res.data?.[0] ? res.data[0] : null;
}

async function invalidatePendingCodes(email) {
  await db.from(
    'password_reset_codes',
    'PATCH',
    { used_at: new Date().toISOString() },
    `email=eq.${encodeURIComponent(email)}&used_at=is.null`,
    true
  );
}

export async function sendVerificationCode(rawEmail) {
  const emailAddr = sanitizeEmail(rawEmail);
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailAddr)) {
    return { success: false, message: 'Valid email is required.' };
  }

  const profile = await findUserByEmail(emailAddr);
  if (!profile) {
    return {
      success: true,
      message: 'If that email is registered, a verification code has been sent.',
    };
  }

  await invalidatePendingCodes(emailAddr);
  const code = String(Math.floor(Math.random() * 1000000)).padStart(6, '0');
  const expiresAt = new Date(Date.now() + codeExpiry() * 60 * 1000).toISOString();
  const hash = await bcrypt.hash(code, 10);

  const insert = await db.from('password_reset_codes', 'POST', {
    email: emailAddr,
    code_hash: hash,
    expires_at: expiresAt,
    attempts: 0,
  }, null, true);

  if (!insert.ok) {
    return { success: false, message: 'Could not start password reset. Please try again.' };
  }

  const appName = env('APP_NAME', 'Insurance Tracking System');
  const body = `Your password reset verification code is:\n\n${code}\n\nThis code expires in ${codeExpiry()} minutes.\nIf you did not request this, you can ignore this email.`;
  const sent = await email.send(emailAddr, `${appName} — Password reset code`, body, profile.id);
  if (!sent) {
    const reason = email.getLastError();
    return {
      success: false,
      message: reason ? `Could not send verification email. ${reason}` : 'Could not send verification email. Check mail settings.',
    };
  }

  return { success: true, message: 'A 6-digit verification code was sent to your email.' };
}

export async function verifyCode(rawEmail, rawCode) {
  const emailAddr = sanitizeEmail(rawEmail);
  const code = String(rawCode || '').replace(/\D/g, '');
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailAddr)) {
    return { success: false, message: 'Valid email is required.' };
  }
  if (!/^\d{6}$/.test(code)) {
    return { success: false, message: 'Enter the 6-digit code from your email.' };
  }

  const row = await findActiveCodeRow(emailAddr);
  if (!row) return { success: false, message: 'Invalid or expired code. Request a new code.' };
  if ((row.attempts || 0) >= maxAttempts()) {
    return { success: false, message: 'Too many attempts. Request a new code.' };
  }

  const match = await bcrypt.compare(code, row.code_hash || '');
  if (!match) {
    await db.from('password_reset_codes', 'PATCH', { attempts: (row.attempts || 0) + 1 }, `id=eq.${row.id}`, true);
    return { success: false, message: 'Incorrect verification code.' };
  }

  const resetToken = crypto.randomBytes(32).toString('hex');
  const resetExpires = new Date(Date.now() + tokenExpiry() * 60 * 1000).toISOString();
  const tokenHash = await bcrypt.hash(resetToken, 10);

  const patch = await db.from('password_reset_codes', 'PATCH', {
    verified_at: new Date().toISOString(),
    reset_token_hash: tokenHash,
    reset_expires_at: resetExpires,
  }, `id=eq.${row.id}`, true);

  if (!patch.ok) return { success: false, message: 'Could not verify code. Please try again.' };

  return {
    success: true,
    message: 'Code verified. You can set a new password.',
    data: { reset_token: resetToken },
  };
}

export async function resetPassword(rawEmail, resetToken, password) {
  const emailAddr = sanitizeEmail(rawEmail);
  resetToken = String(resetToken || '').trim();
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailAddr)) {
    return { success: false, message: 'Valid email is required.' };
  }
  if (!resetToken) {
    return { success: false, message: 'Reset session expired. Start again from forgot password.' };
  }
  if (password.length < 8 || !/[A-Za-z]/.test(password) || !/[0-9]/.test(password)) {
    return { success: false, message: 'Password must be at least 8 characters with letters and numbers.' };
  }

  const row = await findVerifiedRow(emailAddr);
  if (!row?.reset_token_hash || row.used_at) {
    return { success: false, message: 'Reset session expired. Request a new code.' };
  }
  if (row.reset_expires_at && new Date(row.reset_expires_at) < new Date()) {
    return { success: false, message: 'Reset session expired. Request a new code.' };
  }

  const valid = await bcrypt.compare(resetToken, row.reset_token_hash);
  if (!valid) return { success: false, message: 'Reset session invalid. Request a new code.' };

  const profile = await findUserByEmail(emailAddr);
  if (!profile) return { success: false, message: 'Account not found.' };

  const updated = await db.adminUpdateUser(profile.id, { password });
  if (!updated.ok) {
    return { success: false, message: updated.error || 'Could not update password.' };
  }

  await db.from('password_reset_codes', 'PATCH', { used_at: new Date().toISOString() }, `id=eq.${row.id}`, true);
  await invalidatePendingCodes(emailAddr);

  return { success: true, message: 'Password updated successfully. You can sign in now.' };
}

async function findActiveCodeRow(email) {
  const res = await db.from(
    'password_reset_codes',
    'GET',
    null,
    `select=*&email=eq.${encodeURIComponent(email)}&used_at=is.null&verified_at=is.null&order=created_at.desc&limit=1`,
    true
  );
  if (!res.ok || !res.data?.[0]) return null;
  const row = res.data[0];
  if (row.expires_at && new Date(row.expires_at) < new Date()) return null;
  return row;
}

async function findVerifiedRow(email) {
  const res = await db.from(
    'password_reset_codes',
    'GET',
    null,
    `select=*&email=eq.${encodeURIComponent(email)}&used_at=is.null&verified_at=not.is.null&order=verified_at.desc&limit=1`,
    true
  );
  return res.ok && res.data?.[0] ? res.data[0] : null;
}
