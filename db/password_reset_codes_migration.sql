-- Password reset verification codes (run once in Supabase SQL Editor)
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

ALTER TABLE public.password_reset_codes ENABLE ROW LEVEL SECURITY;
