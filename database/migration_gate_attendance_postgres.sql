ALTER TABLE public.leave_requests
  ADD COLUMN IF NOT EXISTS gate_out_time TIMESTAMPTZ,
  ADD COLUMN IF NOT EXISTS gate_in_time TIMESTAMPTZ,
  ADD COLUMN IF NOT EXISTS return_timing_status VARCHAR(20) NOT NULL DEFAULT 'PENDING_EXIT',
  ADD COLUMN IF NOT EXISTS variance_summary VARCHAR(100);

ALTER TABLE public.leave_requests
  DROP CONSTRAINT IF EXISTS leave_requests_status_check,
  ADD CONSTRAINT leave_requests_status_check
    CHECK (status IN ('Pending Verification', 'Granted', 'Rejected', 'Cancelled', 'CHECKED_OUT', 'CHECKED_IN'));

ALTER TABLE public.leave_requests
  DROP CONSTRAINT IF EXISTS leave_requests_return_timing_status_check,
  ADD CONSTRAINT leave_requests_return_timing_status_check
    CHECK (return_timing_status IN ('PENDING_EXIT', 'OUTSIDE', 'ON_TIME', 'EARLY_RETURN', 'LATE_RETURN'));
