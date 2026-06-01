-- Allow administrators to request beneficiary updates as a distinct status.
-- Run this once in Supabase SQL Editor for existing databases.

ALTER TABLE public.beneficiaries
  ALTER COLUMN status TYPE VARCHAR(30);

ALTER TABLE public.beneficiaries
  DROP CONSTRAINT IF EXISTS beneficiaries_status_check;

ALTER TABLE public.beneficiaries
  ADD CONSTRAINT beneficiaries_status_check
  CHECK (status IN ('Updated', 'Not Updated', 'Update Beneficiary'));
