CREATE INDEX IF NOT EXISTS idx_leave_requests_gate_out
  ON public.leave_requests (gate_out_time);

CREATE INDEX IF NOT EXISTS idx_leave_requests_gate_in
  ON public.leave_requests (gate_in_time);

CREATE INDEX IF NOT EXISTS idx_leave_requests_status_gate_times
  ON public.leave_requests (status, gate_out_time, gate_in_time);
