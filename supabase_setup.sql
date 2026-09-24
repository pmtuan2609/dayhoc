-- ==============================================================
-- SQL KHỞI TẠO BẢNG LƯU TRỮ VĨNH VIỄN CHO HỆ THỐNG WEB THẦY TUẤN
-- Hướng dẫn: Mở Supabase -> Vào mục "SQL Editor" -> Dán đoạn mã này vào -> Bấm "Run"
-- ==============================================================

-- 1. Tạo bảng lưu trữ app_storage
CREATE TABLE IF NOT EXISTS public.app_storage (
    key TEXT PRIMARY KEY,
    value JSONB NOT NULL,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT timezone('utc'::text, now()) NOT NULL
);

-- 2. Kích hoạt Row Level Security (RLS) để bảo mật
ALTER TABLE public.app_storage ENABLE ROW LEVEL SECURITY;

-- 3. Tạo chính sách bảo mật cho phép máy chủ (sử dụng service_role key) có toàn quyền đọc và ghi
CREATE POLICY "Allow server full access to app_storage"
ON public.app_storage
FOR ALL
TO authenticated, anon, service_role
USING (true)
WITH CHECK (true);

-- 4. Thông báo hoàn tất
COMMENT ON TABLE public.app_storage IS 'Bảng lưu trữ vĩnh viễn dữ liệu hệ thống đề thi, học sinh Thầy Tuấn';
