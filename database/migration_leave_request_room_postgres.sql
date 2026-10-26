ALTER TABLE public.leave_requests
  ADD COLUMN IF NOT EXISTS room_no VARCHAR(50);
