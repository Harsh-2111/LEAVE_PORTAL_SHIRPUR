CREATE INDEX IF NOT EXISTS idx_leave_requests_sap_created
  ON public.leave_requests (sap_id, created_at);

CREATE INDEX IF NOT EXISTS idx_leave_requests_hostel_status_end_created
  ON public.leave_requests (hostel, status, end_date, created_at);
