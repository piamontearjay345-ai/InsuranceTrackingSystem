-- Superadmin migration for existing Supabase projects.
-- Run this once in Supabase SQL Editor after the original schema exists.

ALTER TABLE public.users
  DROP CONSTRAINT IF EXISTS users_role_check;

ALTER TABLE public.users
  ADD CONSTRAINT users_role_check
  CHECK (role IN ('student', 'admin', 'superadmin'));

ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS permissions JSONB NOT NULL DEFAULT '{}'::jsonb;

DROP POLICY IF EXISTS users_select_own ON public.users;
CREATE POLICY users_select_own ON public.users
  FOR SELECT USING (auth.uid() = id OR public.current_user_role() IN ('admin', 'superadmin'));

DROP POLICY IF EXISTS users_update_own ON public.users;
CREATE POLICY users_update_own ON public.users
  FOR UPDATE USING (auth.uid() = id OR public.current_user_role() IN ('admin', 'superadmin'));

DROP POLICY IF EXISTS beneficiaries_student ON public.beneficiaries;
CREATE POLICY beneficiaries_student ON public.beneficiaries
  FOR ALL USING (
    user_id = auth.uid()
    OR public.current_user_role() IN ('admin', 'superadmin')
  );

DROP POLICY IF EXISTS notifications_access ON public.notifications;
CREATE POLICY notifications_access ON public.notifications
  FOR ALL USING (
    user_id = auth.uid()
    OR public.current_user_role() IN ('admin', 'superadmin')
  );

DROP POLICY IF EXISTS activity_logs_admin ON public.activity_logs;
CREATE POLICY activity_logs_admin ON public.activity_logs
  FOR SELECT USING (public.current_user_role() IN ('admin', 'superadmin'));

DROP POLICY IF EXISTS activity_logs_insert ON public.activity_logs;
CREATE POLICY activity_logs_insert ON public.activity_logs
  FOR INSERT WITH CHECK (public.current_user_role() IN ('admin', 'superadmin') OR auth.uid() IS NOT NULL);

DROP POLICY IF EXISTS login_history_access ON public.login_history;
CREATE POLICY login_history_access ON public.login_history
  FOR SELECT USING (
    user_id = auth.uid()
    OR public.current_user_role() IN ('admin', 'superadmin')
  );

DROP POLICY IF EXISTS failed_notifications_admin ON public.failed_notifications;
CREATE POLICY failed_notifications_admin ON public.failed_notifications
  FOR ALL USING (public.current_user_role() IN ('admin', 'superadmin'));

-- After registering your own account, replace this email and run it to create the first superadmin.
-- UPDATE public.users SET role = 'superadmin' WHERE email = 'piamontearjay345@gmail.com';