<?php
header('Content-Type: text/plain; charset=utf-8');

echo "=== KIỂM TRA MÔI TRƯỜNG BIÊN DỊCH LATEX TRÊN SERVER ===\n\n";

// 1. Kiểm tra hàm exec
if (function_exists('exec')) {
    echo "[OK] Hàm exec() ĐANG HOẠT ĐỘNG.\n";
} else {
    echo "[LỖI] Hàm exec() bị VÔ HIỆU HÓA (nằm trong disable_functions).\n";
    echo "-> Cách khắc phục: Bạn cần vào cPanel -> Select PHP Version -> Options hoặc MultiPHP INI Editor để xóa 'exec' khỏi mục disable_functions.\n\n";
}

// 2. Kiểm tra hàm shell_exec
if (function_exists('shell_exec')) {
    echo "[OK] Hàm shell_exec() ĐANG HOẠT ĐỘNG.\n";
} else {
    echo "[LỖI] Hàm shell_exec() bị VÔ HIỆU HÓA.\n\n";
}

// 3. Kiểm tra pdflatex
if (function_exists('exec')) {
    $output = [];
    $returnVar = 0;
    exec('which pdflatex', $output, $returnVar);
    if ($returnVar === 0 && !empty($output)) {
        echo "[OK] Tìm thấy pdflatex tại: " . implode("\n", $output) . "\n";
        
        // Chạy thử pdflatex --version
        $version = [];
        exec('pdflatex --version', $version);
        echo "Chi tiết phiên bản:\n" . implode("\n", array_slice($version, 0, 2)) . "\n";
    } else {
        // Thử tìm ở một số đường dẫn phổ biến
        $commonPaths = ['/usr/bin/pdflatex', '/usr/local/bin/pdflatex', '/Library/TeX/texbin/pdflatex'];
        $found = false;
        foreach ($commonPaths as $path) {
            if (file_exists($path)) {
                echo "[OK] Tìm thấy pdflatex tại đường dẫn cứng: $path\n";
                $found = true;
                break;
            }
        }
        if (!$found) {
            echo "[LỖI] Không tìm thấy lệnh 'pdflatex' trong hệ thống.\n";
            echo "-> Cách khắc phục: Nếu là VPS, hãy chạy lệnh 'sudo apt-get install texlive-full' (Ubuntu) hoặc tương đương. Nếu là Shared Hosting, bạn cần liên hệ nhà cung cấp hosting để hỏi xem họ có hỗ trợ cài đặt LaTeX hay không.\n";
        }
    }
}
