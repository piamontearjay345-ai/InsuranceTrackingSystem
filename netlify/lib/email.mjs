import nodemailer from 'nodemailer';
import * as db from './supabase.mjs';
import { env, envInt } from './env.mjs';

let lastError = '';

export function getLastError() {
  return lastError;
}

function createTransport() {
  const host = env('MAIL_HOST');
  const port = envInt('MAIL_PORT', 587);
  const user = env('MAIL_USERNAME');
  const pass = env('MAIL_PASSWORD').replace(/\s/g, '');
  if (!host || !user || !pass) return null;

  let from = env('MAIL_FROM_ADDRESS', user);
  if (host.includes('gmail.com') && from.toLowerCase() !== user.toLowerCase()) {
    from = user;
  }

  const secure = env('MAIL_ENCRYPTION', port === 465 ? 'ssl' : 'tls') === 'ssl';
  return {
    transport: nodemailer.createTransport({
      host,
      port,
      secure,
      auth: { user, pass },
      connectionTimeout: envInt('MAIL_TIMEOUT', 20) * 1000,
    }),
    from,
    fromName: env('MAIL_FROM_NAME', env('APP_NAME', 'Insurance Tracking System')),
  };
}

export async function send(to, subject, body, userId = null) {
  lastError = '';
  const cfg = createTransport();
  if (!cfg) {
    lastError = 'SMTP is not configured. Set MAIL_HOST, MAIL_USERNAME, and MAIL_PASSWORD.';
    return false;
  }
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(to)) {
    lastError = 'Recipient email address is invalid.';
    return false;
  }

  const html = `<html><body><h2>${escapeHtml(subject)}</h2><p>${nl2br(escapeHtml(body))}</p></body></html>`;

  try {
    await cfg.transport.sendMail({
      from: `"${cfg.fromName}" <${cfg.from}>`,
      to,
      subject,
      text: body,
      html,
    });
  } catch (e) {
    lastError = e.message || 'SMTP send failed';
    if (userId) {
      await db.from('notifications', 'POST', {
        user_id: userId,
        title: subject,
        message: body,
        delivery_status: 'failed',
      }, null, true);
    }
    await db.from('failed_notifications', 'POST', {
      recipient_email: to,
      payload: JSON.stringify({ subject, body }),
      error_reason: lastError,
    }, null, true);
    return false;
  }

  if (userId) {
    await db.from('notifications', 'POST', {
      user_id: userId,
      title: subject,
      message: body,
      delivery_status: 'sent',
    }, null, true);
  }
  return true;
}

export async function retryFailed(failedId) {
  const res = await db.from('failed_notifications', 'GET', null, `id=eq.${failedId}`, true);
  if (!res.ok || !res.data?.[0]) {
    return { success: false, message: 'Record not found.' };
  }
  const row = res.data[0];
  const payload = typeof row.payload === 'string' ? JSON.parse(row.payload) : row.payload;
  const sent = await send(row.recipient_email, payload?.subject || 'Notification', payload?.body || '');
  if (sent) {
    await db.from('failed_notifications', 'DELETE', null, `id=eq.${failedId}`, true);
  } else {
    await db.from('failed_notifications', 'PATCH', {
      retry_count: (parseInt(row.retry_count, 10) || 0) + 1,
    }, `id=eq.${failedId}`, true);
  }
  return { success: sent, message: sent ? 'Email sent.' : 'Retry failed.' };
}

function escapeHtml(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function nl2br(s) {
  return s.replace(/\n/g, '<br>');
}
