export function registration(data) {
  const errors = {};
  const fullname = String(data.fullname ?? '').trim();
  const studentId = String(data.student_id ?? '').trim();
  const email = String(data.email ?? '').trim();
  const username = String(data.username ?? '').trim();
  const password = data.password ?? '';
  const confirm = data.confirm_password ?? '';

  if (!fullname) errors.fullname = 'Full name is required.';
  if (!studentId) errors.student_id = 'ID number is required.';
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.email = 'Valid email is required.';
  if (!/^[A-Za-z0-9_]{3,20}$/.test(username)) {
    errors.username = 'Username must be 3-20 characters (letters, numbers, underscore).';
  }
  if (password.length < 8 || !/[A-Za-z]/.test(password) || !/[0-9]/.test(password)) {
    errors.password = 'Password must be at least 8 characters with letters and numbers.';
  }
  if (password !== confirm) errors.confirm_password = 'Passwords do not match.';
  return Object.keys(errors).length ? errors : null;
}

export function beneficiary(data) {
  const errors = {};
  if (!String(data.fullname ?? '').trim()) errors.fullname = 'Beneficiary full name is required.';
  if (!String(data.relationship ?? '').trim()) errors.relationship = 'Relationship is required.';
  if (!String(data.contact_number ?? '').trim()) errors.contact_number = 'Contact number is required.';
  if (!String(data.address ?? '').trim()) errors.address = 'Address is required.';
  return Object.keys(errors).length ? errors : null;
}
