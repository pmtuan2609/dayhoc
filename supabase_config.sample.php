<?php
/**
 * Tệp cấu hình Supabase (Tùy chọn)
 * Thầy có thể cấu hình thông tin kết nối Supabase tại đây, hoặc thêm trực tiếp vào mục "Environment" trên Render.
 * 
 * Cách lấy thông tin trên Supabase:
 * 1. Vào Settings (biểu tượng bánh răng) -> API
 * 2. URL: "Project URL"
 * 3. KEY: "service_role" secret key (để có toàn quyền đọc/ghi dữ liệu an toàn từ server)
 */

return [
    'url' => '', // Ví dụ: 'https://abcdefghijk.supabase.co'
    'key' => ''  // Ví dụ: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...'
];
