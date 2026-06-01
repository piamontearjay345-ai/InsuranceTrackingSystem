-- Student Insurance Tracking System - Supabase PostgreSQL Schema
-- Run in Supabase SQL Editor (Dashboard > SQL > New query)

-- Extensions
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- =============================================================================
-- USERS (profile table; links to auth.users via id)
-- =============================================================================
CREATE TABLE IF NOT EXISTS public.users (
  id UUID PRIMARY KEY REFERENCES auth.users(id) ON DELETE CASCADE,
  student_id VARCHAR(50) NOT NULL,
  fullname VARCHAR(200) NOT NULL,
  email VARCHAR(255) NOT NULL UNIQUE,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255),
  role VARCHAR(20) NOT NULL CHECK (role IN ('student', 'admin', 'superadmin')),
  permissions JSONB NOT NULL DEFAULT '{}'::jsonb,
  is_deleted BOOLEAN NOT NULL DEFAULT FALSE,
  failed_login_attempts INT NOT NULL DEFAULT 0,
  locked_until TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_users_role ON public.users(role) WHERE is_deleted = FALSE;
CREATE INDEX IF NOT EXISTS idx_users_student_id ON public.users(student_id);
CREATE INDEX IF NOT EXISTS idx_users_email ON public.users(email);

-- =============================================================================
-- BENEFICIARIES
-- =============================================================================
CREATE TABLE IF NOT EXISTS public.beneficiaries (
  beneficiary_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  user_id UUID NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
  fullname VARCHAR(200) NOT NULL,
  relationship VARCHAR(100) NOT NULL,
  contact_number VARCHAR(30) NOT NULL,
  address TEXT NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'Not Updated'
    CHECK (status IN ('Updated', 'Not Updated', 'Update Beneficiary')),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  is_deleted BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE INDEX IF NOT EXISTS idx_beneficiaries_user_id ON public.beneficiaries(user_id);
CREATE INDEX IF NOT EXISTS idx_beneficiaries_status ON public.beneficiaries(status);

-- =============================================================================
-- NOTIFICATIONS
-- =============================================================================
CREATE TABLE IF NOT EXISTS public.notifications (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  user_id UUID NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
  title VARCHAR(200) NOT NULL,
  message TEXT NOT NULL,
  delivery_status VARCHAR(30) NOT NULL DEFAULT 'pending'
    CHECK (delivery_status IN ('pending', 'sent', 'failed')),
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_notifications_user_id ON public.notifications(user_id);
CREATE INDEX IF NOT EXISTS idx_notifications_created_at ON public.notifications(created_at DESC);

-- =============================================================================
-- PASSWORD RESET CODES (forgot password flow)
-- =============================================================================
CREATE TABLE IF NOT EXISTS public.password_reset_codes (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  email VARCHAR(255) NOT NULL,
  code_hash TEXT NOT NULL,
  reset_token_hash TEXT,
  expires_at TIMESTAMPTZ NOT NULL,
  reset_expires_at TIMESTAMPTZ,
  verified_at TIMESTAMPTZ,
  used_at TIMESTAMPTZ,
  attempts INT NOT NULL DEFAULT 0,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_password_reset_codes_email ON public.password_reset_codes(email);
CREATE INDEX IF NOT EXISTS idx_password_reset_codes_created_at ON public.password_reset_codes(created_at DESC);

-- =============================================================================
-- ACTIVITY LOGS (admin actions)
-- =============================================================================
CREATE TABLE IF NOT EXISTS public.activity_logs (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  admin_id UUID REFERENCES public.users(id) ON DELETE SET NULL,
  action TEXT NOT NULL,
  affected_record VARCHAR(255),
  ip_address VARCHAR(45),
  browser_info TEXT,
  device_info TEXT,
  severity_level VARCHAR(20) NOT NULL DEFAULT 'info'
    CHECK (severity_level IN ('info', 'warning', 'error', 'critical')),
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_activity_logs_admin_id ON public.activity_logs(admin_id);
CREATE INDEX IF NOT EXISTS idx_activity_logs_created_at ON public.activity_logs(created_at DESC);

-- =============================================================================
-- LOGIN HISTORY
-- =============================================================================
CREATE TABLE IF NOT EXISTS public.login_history (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  user_id UUID REFERENCES public.users(id) ON DELETE SET NULL,
  email VARCHAR(255) NOT NULL,
  login_status VARCHAR(20) NOT NULL CHECK (login_status IN ('success', 'failed', 'locked')),
  ip_address VARCHAR(45),
  browser_info TEXT,
  device_info TEXT,
  role VARCHAR(20),
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_login_history_email ON public.login_history(email);
CREATE INDEX IF NOT EXISTS idx_login_history_created_at ON public.login_history(created_at DESC);

-- =============================================================================
-- FAILED NOTIFICATIONS
-- =============================================================================
CREATE TABLE IF NOT EXISTS public.failed_notifications (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  recipient_email VARCHAR(255) NOT NULL,
  payload JSONB,
  error_reason TEXT,
  retry_count INT NOT NULL DEFAULT 0,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_failed_notifications_created_at ON public.failed_notifications(created_at DESC);

-- =============================================================================
-- USER SESSIONS
-- =============================================================================
CREATE TABLE IF NOT EXISTS public.user_sessions (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  user_id UUID NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
  token TEXT NOT NULL,
  expires_at TIMESTAMPTZ NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_user_sessions_user_id ON public.user_sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_user_sessions_token ON public.user_sessions(token);

-- =============================================================================
-- HELPER: current user role from JWT
-- =============================================================================
CREATE OR REPLACE FUNCTION public.current_user_role()
RETURNS TEXT
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = public
AS $$
  SELECT role FROM public.users
  WHERE id = auth.uid() AND is_deleted = FALSE
  LIMIT 1;
$$;

-- =============================================================================
-- ROW LEVEL SECURITY
-- =============================================================================
ALTER TABLE public.users ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.beneficiaries ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.notifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.activity_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.login_history ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.failed_notifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.user_sessions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.password_reset_codes ENABLE ROW LEVEL SECURITY;

-- Users policies
DROP POLICY IF EXISTS users_select_own ON public.users;
CREATE POLICY users_select_own ON public.users
  FOR SELECT USING (auth.uid() = id OR public.current_user_role() IN ('admin', 'superadmin'));

DROP POLICY IF EXISTS users_update_own ON public.users;
CREATE POLICY users_update_own ON public.users
  FOR UPDATE USING (auth.uid() = id OR public.current_user_role() IN ('admin', 'superadmin'));

-- Beneficiaries: students own row; admins all
DROP POLICY IF EXISTS beneficiaries_student ON public.beneficiaries;
CREATE POLICY beneficiaries_student ON public.beneficiaries
  FOR ALL USING (
    user_id = auth.uid()
    OR public.current_user_role() IN ('admin', 'superadmin')
  );

-- Notifications
DROP POLICY IF EXISTS notifications_access ON public.notifications;
CREATE POLICY notifications_access ON public.notifications
  FOR ALL USING (
    user_id = auth.uid()
    OR public.current_user_role() IN ('admin', 'superadmin')
  );

-- Activity logs: admin read; insert via service role
DROP POLICY IF EXISTS activity_logs_admin ON public.activity_logs;
CREATE POLICY activity_logs_admin ON public.activity_logs
  FOR SELECT USING (public.current_user_role() IN ('admin', 'superadmin'));

DROP POLICY IF EXISTS activity_logs_insert ON public.activity_logs;
CREATE POLICY activity_logs_insert ON public.activity_logs
  FOR INSERT WITH CHECK (public.current_user_role() IN ('admin', 'superadmin') OR auth.uid() IS NOT NULL);

-- Login history: admin sees all; users see own
DROP POLICY IF EXISTS login_history_access ON public.login_history;
CREATE POLICY login_history_access ON public.login_history
  FOR SELECT USING (
    user_id = auth.uid()
    OR public.current_user_role() IN ('admin', 'superadmin')
  );

-- Failed notifications: admin only
DROP POLICY IF EXISTS failed_notifications_admin ON public.failed_notifications;
CREATE POLICY failed_notifications_admin ON public.failed_notifications
  FOR ALL USING (public.current_user_role() IN ('admin', 'superadmin'));

-- User sessions: own sessions only
DROP POLICY IF EXISTS user_sessions_own ON public.user_sessions;
CREATE POLICY user_sessions_own ON public.user_sessions
  FOR ALL USING (user_id = auth.uid());

-- =============================================================================
-- TRIGGER: sync profile on auth signup (optional; PHP also inserts)
-- =============================================================================
CREATE OR REPLACE FUNCTION public.handle_new_user()
RETURNS TRIGGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public
AS $$
BEGIN
  INSERT INTO public.users (id, student_id, fullname, email, username, role, password_hash)
  VALUES (
    NEW.id,
    COALESCE(NEW.raw_user_meta_data->>'student_id', ''),
    COALESCE(NEW.raw_user_meta_data->>'fullname', NEW.email),
    NEW.email,
    COALESCE(NEW.raw_user_meta_data->>'username', split_part(NEW.email, '@', 1)),
    COALESCE(NEW.raw_user_meta_data->>'role', 'student'),
    NULL
  )
  ON CONFLICT (id) DO NOTHING;
  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS on_auth_user_created ON auth.users;
CREATE TRIGGER on_auth_user_created
  AFTER INSERT ON auth.users
  FOR EACH ROW EXECUTE FUNCTION public.handle_new_user();

-- Grant usage to authenticated role
GRANT USAGE ON SCHEMA public TO authenticated, anon, service_role;
GRANT ALL ON ALL TABLES IN SCHEMA public TO service_role;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO authenticated;
