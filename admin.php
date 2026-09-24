<?php
/**
 * TRANG QUẢN TRỊ - TỰ ĐỘNG BÓC TÁCH LATEX (TEX)
 * CẬP NHẬT MỚI: TÍCH HỢP SỔ ĐIỂM ĐỒNG BỘ THEO LỚP
 */
if (session_status() === PHP_SESSION_NONE) session_start();
if (function_exists('opcache_reset')) {
    @opcache_reset();
}
ob_start();
error_reporting(0); 
date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'cloud_db.php';

function safe_file_get_contents($path) {
    if (class_exists('CloudDB') && CloudDB::isEnabled()) {
        $key = CloudDB::pathToKey($path);
        if ($key) {
            if (!file_exists($path) || filesize($path) <= 3) {
                $cloudData = CloudDB::get($key);
                if ($cloudData !== null) {
                    $jsonStr = is_string($cloudData) ? $cloudData : json_encode($cloudData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    @file_put_contents($path, $jsonStr);
                    clearstatcache(true, $path);
                    return $jsonStr;
                }
            }
        }
    }
    if (!file_exists($path)) return false;
    clearstatcache(true, $path);
    return @file_get_contents($path);
}

function safe_file_put_contents($path, $content) {
    $result = @file_put_contents($path, $content);
    clearstatcache(true, $path);
    if (class_exists('CloudDB') && CloudDB::isEnabled()) {
        $key = CloudDB::pathToKey($path);
        if ($key) {
            CloudDB::set($key, $content);
        }
    }
    return $result;
}

function clean_compare_name($name) {
    $name = trim(mb_strtoupper($name, 'UTF-8'));
    $name = preg_replace('/\s+/', ' ', $name);
    return $name;
}

function exam_matches_student($exam, $student_class) {
    if (empty($student_class)) return false;
    
    // Tách khối lớp của học sinh từ tên lớp (VD: 12A1 -> 12, 11B2 -> 11, 10A -> 10)
    $student_grade = '';
    if (preg_match('/^(\d+)/', trim($student_class), $m)) {
        $student_grade = $m[1];
    }
    
    $exam_grade = $exam['grade'] ?? '12';
    $exam_assigned_class = trim($exam['assignedClass'] ?? '');
    $selected_classes = $exam['selectedClasses'] ?? [];
    
    // Kiểm tra xem đề thi có được giao riêng cho lớp cụ thể hay không
    $has_specific_class = (!empty($exam_assigned_class) && $exam_assigned_class !== 'all') 
                          || (!empty($selected_classes) && is_array($selected_classes) && count($selected_classes) > 0);
                          
    if ($has_specific_class) {
        // Đã gắn riêng cho lớp -> Chỉ đúng lớp mới được làm
        if (!empty($exam_assigned_class) && $exam_assigned_class !== 'all') {
            if (clean_compare_name($exam_assigned_class) === clean_compare_name($student_class)) {
                return true;
            }
        }
        if (!empty($selected_classes) && is_array($selected_classes)) {
            foreach ($selected_classes as $sc) {
                $cName = is_array($sc) ? ($sc['className'] ?? '') : (string)$sc;
                if (clean_compare_name($cName) === clean_compare_name($student_class)) {
                    return true;
                }
            }
        }
        return false;
    }
    
    // Không gắn riêng lớp -> Dành chung cho tất cả các lớp của khối đó (K12, K11, K10)
    if ($student_grade === '12') {
        return ($exam_grade === '12' || $exam_grade === 'tot_nghiep' || empty($exam_grade));
    } else if ($student_grade === '11') {
        return ($exam_grade === '11');
    } else if ($student_grade === '10') {
        return ($exam_grade === '10');
    }
    
    return false;
}

function normalize_study_scores($scores) {
    $default = [ 'tx1' => '', 'tx2' => '', 'tx3' => '', 'tx4' => '', 'tx5' => '', 'gk' => '', 'ck' => '' ];
    if (empty($scores) || !is_array($scores)) {
        return [
            'hk1' => $default,
            'hk2' => $default
        ];
    }
    if (isset($scores['tx1']) || isset($scores['gk']) || isset($scores['ck'])) {
        return [
            'hk1' => array_merge($default, $scores),
            'hk2' => $default
        ];
    }
    return [
        'hk1' => array_merge($default, $scores['hk1'] ?? []),
        'hk2' => array_merge($default, $scores['hk2'] ?? [])
    ];
}

function normalize_publish_status($status, $className) {
    $default = [ 'tx1' => false, 'tx2' => false, 'tx3' => false, 'tx4' => false, 'tx5' => false, 'gk' => false, 'ck' => false ];
    $classVal = $status[$className] ?? [];
    if ($classVal === true || $classVal === 1 || $classVal === '1') {
        $allTrue = [ 'tx1' => true, 'tx2' => true, 'tx3' => true, 'tx4' => true, 'tx5' => true, 'gk' => true, 'ck' => true ];
        return [ 'hk1' => $allTrue, 'hk2' => $default ];
    }
    if (!is_array($classVal)) {
        return [ 'hk1' => $default, 'hk2' => $default ];
    }
    if (isset($classVal['tx1']) || isset($classVal['gk']) || isset($classVal['ck'])) {
        return [
            'hk1' => array_merge($default, $classVal),
            'hk2' => $default
        ];
    }
    return [
        'hk1' => array_merge($default, $classVal['hk1'] ?? []),
        'hk2' => array_merge($default, $classVal['hk2'] ?? [])
    ];
}

function getLiveSessionsDir() {
    $base = __DIR__;
    // Tự động nhảy ra thư mục gốc chứa index.php nếu tệp này được chạy trong thư mục con bất kỳ
    if (file_exists(dirname($base) . DIRECTORY_SEPARATOR . 'index.php')) {
        $base = dirname($base);
    }
    $dir = $base . DIRECTORY_SEPARATOR . 'live_sessions';
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    return $dir;
}


$ADMIN_USER = 'thaytuan@admin';
$ADMIN_PASS = 'thaytuangdtxq12';

// Path to users file
$usersFile = __DIR__ . DIRECTORY_SEPARATOR . 'users_data.json';
$studentsFile = __DIR__ . DIRECTORY_SEPARATOR . 'students_accounts.json';
if (!file_exists($studentsFile)) { safe_file_put_contents($studentsFile, json_encode(array())); }
$users = json_decode(safe_file_get_contents($usersFile), true);
if (!is_array($users) || empty($users)) {
    $users = array(
        array(
            'username' => 'thaytuan@admin',
            'password' => 'thaytuangdtxq12',
            'fullName' => 'Ths.Phạm Minh Tuấn',
            'is_owner' => true
        )
    );
    safe_file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
}

// Check who is currently logged in
$current_admin = isset($_SESSION['admin_username']) ? $_SESSION['admin_username'] : 'thaytuan@admin';

// Check if current_admin is owner
$is_owner = (strtolower($current_admin) === 'thaytuan@admin');
$updated = false;
if (is_array($users)) {
    foreach ($users as &$u) {
        if ($u['username'] === 'thaytuan@admin') {
            if (!isset($u['fullName']) || $u['fullName'] !== 'Ths.Phạm Minh Tuấn') {
                $u['fullName'] = 'Ths.Phạm Minh Tuấn';
                $updated = true;
            }
        }
        if (strtolower($u['username']) === strtolower($current_admin)) {
            $is_owner = !empty($u['is_owner']);
        }
    }
}
if ($updated) {
    safe_file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
}

// Suffix for other admins
$suffix = ($is_owner) ? '' : '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $current_admin);

$uploadFolderName = 'uploads';
$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . $uploadFolderName . DIRECTORY_SEPARATOR;
$dataFile = __DIR__ . DIRECTORY_SEPARATOR . 'exams_data' . $suffix . '.json';
$historyFile = __DIR__ . DIRECTORY_SEPARATOR . 'history_data' . $suffix . '.json';
$liveFile = __DIR__ . DIRECTORY_SEPARATOR . 'live_data' . $suffix . '.json';
$classFile = __DIR__ . DIRECTORY_SEPARATOR . 'classes_data' . $suffix . '.json'; 
$docFile = __DIR__ . DIRECTORY_SEPARATOR . 'documents_data' . $suffix . '.json';
$videoFile = __DIR__ . DIRECTORY_SEPARATOR . 'videos_data' . $suffix . '.json';

if (!file_exists($docFile)) { safe_file_put_contents($docFile, json_encode(array())); }
if (!file_exists($videoFile)) { safe_file_put_contents($videoFile, json_encode(array())); }
if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }
if (!file_exists($dataFile)) { safe_file_put_contents($dataFile, json_encode(array())); }
if (!file_exists($historyFile)) { safe_file_put_contents($historyFile, json_encode(array())); }
if (!file_exists($liveFile)) { safe_file_put_contents($liveFile, json_encode(array())); }
if (!file_exists($classFile)) { safe_file_put_contents($classFile, json_encode(array())); }

function rrmdir($dir) {
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . DIRECTORY_SEPARATOR . $object) && !is_link($dir . "/" . $object)) {
                    rrmdir($dir . DIRECTORY_SEPARATOR . $object);
                } else {
                    @unlink($dir . DIRECTORY_SEPARATOR . $object);
                }
            }
        }
        @rmdir($dir);
    }
}

function compileTexToPdf($texFilePath, $outputDir, $id) {
    if (!function_exists('exec')) {
        return [
            'success' => false,
            'log' => 'Lỗi: Hàm exec() của PHP đã bị vô hiệu hóa trên hosting/server. Vui lòng bật hàm này để sử dụng tính năng biên dịch LaTeX.'
        ];
    }

    $tempDir = $outputDir . 'temp_' . $id . DIRECTORY_SEPARATOR;
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0777, true);
    }
    
    // Copy any .sty files from root directory to temp compile folder
    $rootFiles = glob(__DIR__ . DIRECTORY_SEPARATOR . '*.sty');
    if ($rootFiles) {
        foreach ($rootFiles as $styFile) {
            $dest = $tempDir . basename($styFile);
            @copy($styFile, $dest);
        }
    }
    
    $content = file_get_contents($texFilePath);
    
    // Construct full latex document if it's only a fragment (doesn't contain \begin{document})
    if (strpos($content, '\begin{document}') === false) {
        $preamble = "\\documentclass[12pt,a4paper,oneside]{article}\n"
                  . "\\usepackage[utf8]{vietnam}\n"
                  . "\\usepackage{amsmath,amsfonts,amssymb,graphicx}\n"
                  . "\\usepackage[top=.5cm,bottom=.5cm,left=.5cm,right=.5cm]{geometry}\n"
                  . "\\usepackage{tikz,tkz-euclide,tkz-tab}\n"
                  . "\\usepackage{esvect}\n"
                  . "\\def\\vec{\\vv}\\def\\vt{\\vv}\n"
                  . "\\def\\overrightarrow{\\vv}\n"
                  . "\\usepackage{ex_test}\n"
                  . "\\def\\phanmot{\\noindent\\textbf{PHẦN I. Câu trắc nghiệm nhiều phương án lựa chọn \\setcounter{ex}{0}}}\n"
                  . "\\def\\phanhai{\\noindent\\textbf{PHẦN II. Câu trắc nghiệm đúng sai \\setcounter{ex}{0}}}\n"
                  . "\\def\\phanba{\\noindent\\textbf{PHẦN III. Câu trắc nghiệm trả lời ngắn \\setcounter{ex}{0}}}\n"
                  . "\\newcommand{\\hoac}[1]{\\left[\\begin{aligned}#1\\end{aligned}\\right.}\n"
                  . "\\newcommand{\\heva}[1]{\\left\\{\\begin{aligned}#1\\end{aligned}\\right.}\n"
                  . "\\graphicspath{{../}{../../uploads/}{../../}}\n"
                  . "\\begin{document}\n";
        
        $fullTex = $preamble . $content . "\n\\end{document}";
    } else {
        // Inject graphicspath to allow loading images from uploads folder
        $fullTex = str_replace('\begin{document}', "\\graphicspath{{../}{../../uploads/}{../../}}\n\\begin{document}", $content);
    }
    
    $tempTexFile = $tempDir . 'document.tex';
    file_put_contents($tempTexFile, $fullTex);
    
    // Detect compiler path (pdflatex)
    $compiler = 'pdflatex';
    if (file_exists('/Library/TeX/texbin/pdflatex')) {
        $compiler = '/Library/TeX/texbin/pdflatex';
    } else {
        $whichCompiler = shell_exec('which pdflatex');
        if ($whichCompiler) {
            $compiler = trim($whichCompiler);
        }
    }
    
    // Compile command
    $cmd = escapeshellcmd($compiler) . ' -interaction=nonstopmode -output-directory=' . escapeshellarg($tempDir) . ' ' . escapeshellarg($tempTexFile) . ' 2>&1';
    
    $output = [];
    $returnVar = 0;
    exec($cmd, $output, $returnVar);
    // Run second time for page numbers / cross-references
    exec($cmd);
    
    $tempPdfFile = $tempDir . 'document.pdf';
    
    if (file_exists($tempPdfFile) && filesize($tempPdfFile) > 0) {
        $destPdf = $outputDir . $id . '.pdf';
        @rename($tempPdfFile, $destPdf);
        
        rrmdir($tempDir);
        
        return [
            'success' => true,
            'pdfName' => $id . '.pdf',
            'pdfPath' => $destPdf
        ];
    } else {
        $logContent = '';
        $logFile = $tempDir . 'document.log';
        if (file_exists($logFile)) {
            $logContent = file_get_contents($logFile);
        } else {
            $logContent = implode("\n", $output);
        }
        
        rrmdir($tempDir);
        
        return [
            'success' => false,
            'log' => $logContent
        ];
    }
}

function compileTikzToImage($tikzContent, $uploadDir, $uploadFolderName) {
    if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }

    $preamble = "\\usepackage{amsmath,amssymb,amsfonts}\n\\usepackage{tikz}\n\\usetikzlibrary{calc,angles,quotes,patterns,intersections,arrows.meta,shapes.geometric,decorations.pathreplacing}\n\\usepackage{tkz-tab}\n\\usepackage{tkz-euclide}\n\\usepackage{pgfplots}\n\\pgfplotsset{compat=1.15}\n\\newcommand{\\hoac}[1]{\\left[ \\begin{array}{l} #1 \\end{array} \\right.}\n\\newcommand{\\heva}[1]{\\left\\{ \\begin{array}{l} #1 \\end{array} \\right.}\n\\newcommand{\\True}{\\text{True}}\n\\newcommand{\\False}{\\text{False}}\n\\newcommand{\\vv}[1]{\\overrightarrow{#1}}\n\\newcommand{\\vt}[1]{\\overrightarrow{#1}}";

    $postData = array(
        'formula' => $tikzContent,
        'fsize' => '20px',
        'fcolor' => '000000',
        'mode' => '0',
        'out' => '1',
        'remhost' => 'quicklatex.com',
        'preamble' => $preamble
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://www.quicklatex.com/latex3.f");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData, '', '&', PHP_QUERY_RFC3986));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 60); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);

    $lines = explode("\n", $response);
    
    if (isset($lines[0]) && trim($lines[0]) === '0') {
        if (isset($lines[1]) && strpos($lines[1], 'http') === 0) {
            $imageUrl = trim(explode(' ', $lines[1])[0]); 
            
            $imageName = 'tikz_' . md5($tikzContent . $preamble) . '.png';
            $imagePath = $uploadDir . $imageName;

            if (!file_exists($imagePath) || filesize($imagePath) == 0) { 
                $imgData = @file_get_contents($imageUrl);
                if ($imgData) {
                    @file_put_contents($imagePath, $imgData);
                } else {
                    $ch2 = curl_init($imageUrl);
                    $fp = @fopen($imagePath, 'wb');
                    if ($fp) {
                        curl_setopt($ch2, CURLOPT_FILE, $fp);
                        curl_setopt($ch2, CURLOPT_HEADER, 0);
                        curl_setopt($ch2, CURLOPT_TIMEOUT, 30);
                        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
                        curl_exec($ch2);
                        curl_close($ch2);
                        fclose($fp);
                    }
                }
            }
            
            if (file_exists($imagePath) && filesize($imagePath) > 0) {
                return $uploadFolderName . '/' . $imageName;
            } else {
                return "ERROR_LATEX|||Lỗi Hosting không cho phép ghi file ảnh vào thư mục uploads. Hãy CHMOD 777 thư mục này.|||" . $tikzContent;
            }
        }
    }
    
    $errorDetails = implode("\n", array_slice($lines, 2));
    if (empty(trim($errorDetails))) { $errorDetails = $response; }
    return "ERROR_LATEX|||" . trim($errorDetails) . "|||" . trim($tikzContent);
}

function extract_braces($str) {
    $results = array(); $stack = 0; $start = 0;
    for($i=0; $i<strlen($str); $i++) {
        if($str[$i] == '{') { if($stack == 0) $start = $i + 1; $stack++; }
        elseif($str[$i] == '}') { $stack--; if($stack == 0) $results[] = substr($str, $start, $i - $start); }
    }
    return $results;
}

function process_immini($text) {
    $offset = 0;
    while (($pos = strpos($text, '\immini', $offset)) !== false) {
        $b1_start = strpos($text, '{', $pos);
        if ($b1_start === false) break;
        $stack = 1; $b1_end = -1;
        for ($i = $b1_start + 1; $i < strlen($text); $i++) {
            if ($text[$i] == '{') $stack++; elseif ($text[$i] == '}') $stack--;
            if ($stack == 0) { $b1_end = $i; break; }
        }
        
        $b2_start = strpos($text, '{', $b1_end + 1);
        if ($b2_start === false) break;
        $stack = 1; $b2_end = -1;
        for ($i = $b2_start + 1; $i < strlen($text); $i++) {
            if ($text[$i] == '{') $stack++; elseif ($text[$i] == '}') $stack--;
            if ($stack == 0) { $b2_end = $i; break; }
        }
        
        if ($b1_end !== -1 && $b2_end !== -1) {
            $part1 = substr($text, $b1_start + 1, $b1_end - $b1_start - 1);
            $part2 = substr($text, $b2_start + 1, $b2_end - $b2_start - 1);
            $replacement = '<div class="flex flex-col md:flex-row items-start gap-8 my-4 w-full"><div class="flex-1">' . $part1 . '</div><div class="shrink-0 max-w-full overflow-x-auto bg-white p-4 rounded-xl border border-slate-100 shadow-sm flex items-center justify-center">' . $part2 . '</div></div>';
            $text = substr_replace($text, $replacement, $pos, $b2_end - $pos + 1);
        } else { $offset = $pos + 7; }
    }
    return $text;
}

function parseTexContent($text, $uploadDir, $uploadFolderName) {
    $text = str_replace('\%', '__LATEX_PERCENT_ESCAPED__', $text);
    $text = preg_replace('/%.*$/m', '', $text); 
    $text = str_replace('__LATEX_PERCENT_ESCAPED__', '\%', $text);

    $text = process_immini($text);

    $text = preg_replace_callback('/\\\\begin\{(tikzpicture|tkizpicture)\}([\s\S]*?)\\\\end\{\1\}/', function($m) use ($uploadDir, $uploadFolderName) {
        $tikzContent = "\\begin{tikzpicture}" . $m[2] . "\\end{tikzpicture}";
        $imgPath = compileTikzToImage($tikzContent, $uploadDir, $uploadFolderName);
        
        if (strpos($imgPath, 'ERROR_LATEX|||') === 0) {
            $parts = explode('|||', $imgPath);
            $errorMsg = htmlspecialchars(isset($parts[1]) ? $parts[1] : 'Lỗi không xác định');
            $failedCode = htmlspecialchars(isset($parts[2]) ? $parts[2] : '');
            
            return '<div class="text-rose-700 border-2 border-rose-300 bg-rose-50 p-5 rounded-2xl my-6 text-left text-[14px] overflow-x-auto font-mono leading-relaxed shadow-sm w-full">
                        <b class="block mb-3 font-sans text-rose-800 text-lg uppercase tracking-widest border-b border-rose-200 pb-2">⚠️ Lỗi Máy Chủ: Không thể dịch hình TikZ</b>
                        <p class="mb-4 font-sans text-slate-700">Đoạn code TikZ chứa lệnh hoặc thư viện mà máy chủ không hỗ trợ (hoặc sai cú pháp). Xem chi tiết lỗi bên dưới:</p>
                        <p class="mb-1 font-bold font-sans text-rose-900 text-xs tracking-widest uppercase">Mã lỗi máy chủ trả về:</p>
                        <pre class="bg-white p-4 rounded-xl border border-rose-200 mb-5 whitespace-pre-wrap word-wrap">' . $errorMsg . '</pre>
                        <p class="mb-1 font-bold font-sans text-slate-500 text-xs tracking-widest uppercase">Đoạn code gây lỗi:</p>
                        <pre class="bg-slate-100 p-4 rounded-xl border border-slate-200 text-slate-500 max-h-40 overflow-y-auto whitespace-pre-wrap word-wrap">' . $failedCode . '</pre>
                    </div>';
        } else if ($imgPath) {
            return '<div class="flex justify-center w-full my-6 overflow-x-auto"><img src="' . $imgPath . '" class="max-w-none lg:max-w-full h-auto rounded-xl shadow-sm border border-slate-200" alt="Hình vẽ TikZ"/></div>';
        }
        return '';
    }, $text);

    $text = preg_replace('/\\\\includegraphics(?:\[.*?\])?\{(.+?)\}/', '<div class="flex justify-center w-full my-4 overflow-x-auto"><img src="uploads/$1" alt="Hình vẽ" class="max-w-none lg:max-w-full h-auto rounded-xl shadow-sm border border-slate-200" onerror="this.src=\'$1\'" /></div>', $text);

    // Strip \begin{table}[...] and \end{table}
    $text = preg_replace('/\\\\begin\{table\}(?:\[.*?\])?/', '', $text);
    $text = str_replace('\end{table}', '', $text);

    $text = preg_replace('/\\\\begin\{tabular\}\{([\s\S]*?)\}/', '<div class="overflow-x-auto flex justify-center w-full my-6"><div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm table-wrapper">$$ \\\\begin{array}{$1}', $text);
    $text = str_replace('\end{tabular}', '\\end{array} $$</div></div>', $text);

    $text = preg_replace_callback('/\\\\begin\{itemchoice\}(?:\[.*?\])?([\s\S]*?)\\\\end\{itemchoice\}/', function($m) {
        $content = $m[1];
        $counter = 0;
        $content = preg_replace_callback('/\\\\itemch\s*/', function($m2) use (&$counter) {
            $chars = ['a)', 'b)', 'c)', 'd)', 'e)', 'f)', 'g)', 'h)'];
            $char = isset($chars[$counter]) ? $chars[$counter] : '*';
            $counter++;
            return '<br/><span class="font-black text-blue-600 mr-2 inline-block mt-2">' . $char . '</span> ';
        }, $content);
        return '<div class="itemchoice-container my-3 pl-2">' . $content . '</div>';
    }, $text);

    $mathEnvs = 'array|matrix|bmatrix|pmatrix|vmatrix|Vmatrix|cases|aligned|align\*?|equation\*?|eqnarray\*?';
    $parts = preg_split('/(\$\$[\s\S]*?\$\$|\$.*?\$|\\\\\[[\s\S]*?\\\\\]|\\\\\([\s\S]*?\\\\\)|\\\\begin\{(?:' . $mathEnvs . ')\}[\s\S]*?\\\\end\{(?:' . $mathEnvs . ')\})/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    
    foreach ($parts as &$part) {
        if (preg_match('/^\\\\begin\{(?:' . $mathEnvs . ')\}/', $part)) {
            $part = '$$' . $part . '$$';
        } else if (!preg_match('/^(\$\$|\$|\\\\\[|\\\\\(|\\\\begin\{)/', $part)) {
            $part = preg_replace('/\\\\\\\\(?:\s*\[.*?\])?/', '<br/>', $part);
            $part = str_replace('\newline', '<br/>', $part);
            $part = str_replace('\hfill', '<span style="display:inline-block; width:20px;"></span>', $part);
        }
    }
    $text = implode('', $parts);

    $text = str_replace('\begin{itemize}', '<div style="margin-left: 20px; margin-top: 10px; margin-bottom: 10px;">', $text);
    $text = str_replace('\end{itemize}', '</div>', $text);
    $text = str_replace('\begin{enumerate}', '<div style="margin-left: 20px; margin-top: 10px; margin-bottom: 10px;">', $text);
    $text = str_replace('\end{enumerate}', '</div>', $text);
    $text = preg_replace('/\\\\item\s*/', '<br/> <span style="color:#2563eb; font-weight:bold; font-size: 1.2em;">&bull;</span> ', $text);
    $text = str_replace('\begin{center}', '<div class="flex w-full justify-center my-4 text-center"><div class="overflow-x-auto max-w-full">', $text);
    $text = str_replace('\end{center}', '</div></div>', $text);

    $docStart = strpos($text, '\begin{document}');
    if ($docStart === false) {
        $docStart = 0;
    }
    $pos1 = strpos($text, '\phanmot', $docStart);
    $pos2 = strpos($text, '\phanhai', $docStart);
    $pos3 = strpos($text, '\phanba', $docStart);

    $questionsData = array('p1' => array(), 'p2' => array(), 'p3' => array());
    $correctAnswers = array('p1' => (object)array(), 'p2' => (object)array(), 'p3' => (object)array());

    preg_match_all('/\\\\begin\{ex\}([\s\S]*?)\\\\end\{ex\}/', $text, $blocks, PREG_OFFSET_CAPTURE);
    
    foreach ($blocks[1] as $blockMatch) {
        $qRaw = $blockMatch[0];
        $offset = $blockMatch[1];
        
        $part = 'p1';
        if ($pos2 !== false && $offset > $pos2) $part = 'p2';
        if ($pos3 !== false && $offset > $pos3) $part = 'p3';

        // Tự động phân loại lại theo từ khóa đặc trưng trong câu hỏi nếu có lệch phần
        if (strpos($qRaw, '\choiceTF') !== false) {
            $part = 'p2';
        } elseif (strpos($qRaw, '\choice') !== false) {
            $part = 'p1';
        } elseif (strpos($qRaw, '\shortans') !== false) {
            $part = 'p3';
        }

        $qRawSplit = explode('\\loigiai', $qRaw);
        $qMain = $qRawSplit[0];

        $solution = "";
        $lgPos = strpos($qRaw, '\loigiai');
        if ($lgPos !== false) {
            $braceStart = strpos($qRaw, '{', $lgPos);
            if ($braceStart !== false) {
                $stack = 1; $braceEnd = -1;
                for ($i = $braceStart + 1; $i < strlen($qRaw); $i++) {
                    if ($qRaw[$i] == '{') $stack++;
                    elseif ($qRaw[$i] == '}') $stack--;
                    if ($stack == 0) { $braceEnd = $i; break; }
                }
                if ($braceEnd !== -1) {
                    $solution = trim(substr($qRaw, $braceStart + 1, $braceEnd - $braceStart - 1));
                }
            }
        }

        if ($part === 'p3') {
            if (preg_match('/\\\\shortans(?:\[.*?\])?\s*\{([\s\S]*?)\}/', $qRaw, $match)) {
                $ans = isset($match[1]) ? trim(strip_tags($match[1])) : '';
                $ans = str_replace(array('$', '\\(', '\\)'), '', $ans);
                $qText = trim(preg_replace('/\\\\shortans.*$/s', '', $qMain));
                $questionsData['p3'][] = array('q' => $qText, 'explain' => $solution);
                $correctAnswers['p3']->{count($questionsData['p3'])} = $ans;
            } else {
                $questionsData['p3'][] = array('q' => trim($qMain), 'explain' => $solution);
                $correctAnswers['p3']->{count($questionsData['p3'])} = '';
            }
        } 
        elseif ($part === 'p2') {
            if (preg_match('/\\\\choiceTF(?:\[.*?\])?([\s\S]*)/', $qMain, $match)) {
                $qText = trim(preg_replace('/\\\\choiceTF.*$/s', '', $qMain));
                $optsStr = isset($match[1]) ? $match[1] : ''; 
                $opts = extract_braces($optsStr);
                $procOpts = array(); $ansArr = (object)array();
                $limit = min(count($opts), 4);
                for($k=0; $k<$limit; $k++) {
                    $char = chr(97 + $k);
                    $ansArr->{$char} = (strpos($opts[$k], '\\True') !== false);
                    $procOpts[] = trim(str_replace(array('\\True', '\\False'), '', $opts[$k]));
                }
                $questionsData['p2'][] = array('q' => $qText, 'opts' => $procOpts, 'explain' => $solution);
                $correctAnswers['p2']->{count($questionsData['p2'])} = $ansArr;
            }
        }
        else {
            if (preg_match('/\\\\choice(?:\[.*?\])?([\s\S]*)/', $qMain, $match)) {
                $qText = trim(preg_replace('/\\\\choice.*$/s', '', $qMain));
                $optsStr = isset($match[1]) ? $match[1] : ''; 
                $opts = extract_braces($optsStr);
                $procOpts = array(); $finalAns = "";
                $limit = min(count($opts), 4);
                for($k=0; $k<$limit; $k++) {
                    if (strpos($opts[$k], '\\True') !== false) $finalAns = chr(65 + $k); 
                    $procOpts[] = trim(str_replace(array('\\True', '\\False'), '', $opts[$k]));
                }
                $questionsData['p1'][] = array('q' => $qText, 'opts' => $procOpts, 'explain' => $solution);
                $correctAnswers['p1']->{count($questionsData['p1'])} = $finalAns;
            }
        }
    }
    return array('questions' => $questionsData, 'correct' => $correctAnswers);
}

if (isset($_GET['action'])) {
    $action = $_GET['action'];
    if ($action !== 'download_tex') {
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
    }

    // =======================================================
    // 1. API QUẢN LÝ TÀI LIỆU (ĐÃ FIX LỖI)
    // =======================================================
    if ($action === 'list_documents') {
        $docs = json_decode(safe_file_get_contents($docFile), true);
        if (!is_array($docs)) $docs = [];
        
        $isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
        
        if (!$isAdmin) {
            $docs = array_values(array_filter($docs, function($d) {
                return isset($d['isPublished']) && $d['isPublished'] === true;
            }));
        }
        
        echo json_encode($docs);
        exit;
    }

    if ($action === 'upload_document' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $file = $_FILES['file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf' && $ext !== 'tex') { 
            echo json_encode(['success' => false, 'message' => 'Chỉ hỗ trợ file PDF hoặc .tex!']); 
            exit; 
        }
        
        $id = 'doc_' . time();
        $fileName = $id . '.pdf';
        
        if ($ext === 'tex') {
            $tempTexPath = $uploadDir . $id . '.tex';
            if (move_uploaded_file($file['tmp_name'], $tempTexPath)) {
                $compileResult = compileTexToPdf($tempTexPath, $uploadDir, $id);
                @unlink($tempTexPath);
                
                if (!$compileResult['success']) {
                    echo json_encode([
                        'success' => false, 
                        'message' => 'Biên dịch LaTeX thất bại! Vui lòng kiểm tra lại mã nguồn .tex.',
                        'log' => $compileResult['log']
                    ]);
                    exit;
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Lỗi tải lên file .tex!']);
                exit;
            }
        } else {
            if (!move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
                echo json_encode(['success' => false, 'message' => 'Lỗi lưu file PDF!']);
                exit;
            }
        }
        
        $docs = json_decode(safe_file_get_contents($docFile), true);
        if (!is_array($docs)) $docs = [];
        $docs[] = [
            'id' => $id, 
            'title' => $_POST['title'], 
            'grade' => isset($_POST['grade']) ? $_POST['grade'] : '12', 
            'category' => $_POST['category'],
            'filePath' => $uploadFolderName . '/' . $fileName, 
            'isPublished' => true,
            'createdAt' => date('d/m/Y H:i'), 
            'size' => round(filesize($uploadDir . $fileName) / 1024 / 1024, 2) . ' MB'
        ];
        safe_file_put_contents($docFile, json_encode($docs));
        echo json_encode(['success' => true, 'id' => $id, 'title' => $_POST['title']]);
        exit;
    }

    if ($action === 'toggle_document' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $docs = json_decode(safe_file_get_contents($docFile), true);
        if (is_array($docs)) {
            foreach ($docs as &$d) {
                if ($d['id'] === $input['id']) {
                    $d['isPublished'] = !$d['isPublished'];
                    break;
                }
            }
            safe_file_put_contents($docFile, json_encode($docs));
            echo json_encode(['success' => true]);
        }
        exit;
    }

    if ($action === 'delete_document') {
        if ($action === 'edit_document_title' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $docs = json_decode(safe_file_get_contents($docFile), true);
        if (is_array($docs)) {
            foreach ($docs as &$d) {
                if ($d['id'] === $input['id']) {
                    $d['title'] = $input['title'];
                    break;
                }
            }
            safe_file_put_contents($docFile, json_encode($docs));
            echo json_encode(['success' => true]);
        }
        exit;
    }
        $docs = json_decode(safe_file_get_contents($docFile), true);
        if (is_array($docs)) {
            $newDocs = [];
            foreach ($docs as $d) {
                if ($d['id'] !== $_GET['id']) {
                    $newDocs[] = $d;
                } else {
                    $localPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $d['filePath']);
                    if (file_exists($localPath)) @unlink($localPath);
                }
            }
            safe_file_put_contents($docFile, json_encode($newDocs));
            echo json_encode(['success' => true]);
        }
        exit;
    }

    if ($action === 'list_videos') {
        $vids = json_decode(safe_file_get_contents($videoFile), true);
        echo json_encode(is_array($vids) ? $vids : array());
        exit;
    }

    if ($action === 'add_video' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $title = trim($input['title']);
        $videoUrl = trim($input['videoUrl']);
        $grade = isset($input['grade']) ? $input['grade'] : '12';
        $category = trim($input['category']);
        
        if (empty($title) || empty($videoUrl) || empty($category)) {
            echo json_encode(['success' => false, 'message' => 'Vui lòng nhập đầy đủ tiêu đề, đường dẫn YouTube và chuyên mục!']);
            exit;
        }
        
        $vids = json_decode(safe_file_get_contents($videoFile), true);
        if (!is_array($vids)) $vids = [];
        
        $vidId = 'vid_' . time();
        $vids[] = [
            'id' => $vidId,
            'title' => $title,
            'grade' => $grade,
            'category' => $category,
            'videoUrl' => $videoUrl,
            'isPublished' => true,
            'createdAt' => date('d/m/Y H:i')
        ];
        
        safe_file_put_contents($videoFile, json_encode($vids, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true, 'id' => $vidId, 'title' => $title]);
        exit;
    }

    if ($action === 'toggle_video' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $vids = json_decode(safe_file_get_contents($videoFile), true);
        if (is_array($vids)) {
            foreach ($vids as &$v) {
                if ($v['id'] === $input['id']) {
                    $v['isPublished'] = !$v['isPublished'];
                    break;
                }
            }
            safe_file_put_contents($videoFile, json_encode($vids, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true]);
        }
        exit;
    }

    if ($action === 'edit_video_title' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $vids = json_decode(safe_file_get_contents($videoFile), true);
        if (is_array($vids)) {
            foreach ($vids as &$v) {
                if ($v['id'] === $input['id']) {
                    $v['title'] = trim($input['title']);
                    break;
                }
            }
            safe_file_put_contents($videoFile, json_encode($vids, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true]);
        }
        exit;
    }

    if ($action === 'delete_video') {
        $vids = json_decode(safe_file_get_contents($videoFile), true);
        if (is_array($vids)) {
            $newVids = [];
            foreach ($vids as $v) {
                if ($v['id'] !== $_GET['id']) {
                    $newVids[] = $v;
                }
            }
            safe_file_put_contents($videoFile, json_encode($newVids, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true]);
        }
        exit;
    }

    // =======================================================
    // 3. API QUẢN LÝ THÔNG BÁO (Dành riêng cho Owner)
    // =======================================================
    if ($action === 'list_notifications') {
        $notifFile = __DIR__ . DIRECTORY_SEPARATOR . 'notifications_data.json';
        $notifs = json_decode(safe_file_get_contents($notifFile), true);
        if (!is_array($notifs)) $notifs = [];
        
        $isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
        if (!$isAdmin) {
            $notifs = array_values(array_filter($notifs, function($n) {
                return isset($n['isPublished']) && $n['isPublished'] === true;
            }));
        }
        echo json_encode($notifs);
        exit;
    }

    if ($action === 'add_notification' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $title = trim($input['title']);
        $link = isset($input['link']) ? trim($input['link']) : '';
        $docId = isset($input['documentId']) ? trim($input['documentId']) : '';
        $vidId = isset($input['videoId']) ? trim($input['videoId']) : '';
        
        if (empty($title)) {
            echo json_encode(['success' => false, 'message' => 'Tiêu đề không được để trống!']);
            exit;
        }
        
        $notifFile = __DIR__ . DIRECTORY_SEPARATOR . 'notifications_data.json';
        $notifs = json_decode(safe_file_get_contents($notifFile), true);
        if (!is_array($notifs)) $notifs = [];
        
        $notifs[] = [
            'id' => 'notif_' . time(),
            'title' => $title,
            'link' => $link,
            'documentId' => $docId,
            'videoId' => $vidId,
            'isPublished' => true,
            'createdAt' => date('d/m/Y H:i')
        ];
        
        safe_file_put_contents($notifFile, json_encode($notifs, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'toggle_notification' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $notifFile = __DIR__ . DIRECTORY_SEPARATOR . 'notifications_data.json';
        $notifs = json_decode(safe_file_get_contents($notifFile), true);
        if (is_array($notifs)) {
            foreach ($notifs as &$n) {
                if ($n['id'] === $input['id']) {
                    $n['isPublished'] = !$n['isPublished'];
                    break;
                }
            }
            safe_file_put_contents($notifFile, json_encode($notifs, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true]);
        }
        exit;
    }

    if ($action === 'edit_notification_title' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $notifFile = __DIR__ . DIRECTORY_SEPARATOR . 'notifications_data.json';
        $notifs = json_decode(safe_file_get_contents($notifFile), true);
        if (is_array($notifs)) {
            foreach ($notifs as &$n) {
                if ($n['id'] === $input['id']) {
                    $n['title'] = trim($input['title']);
                    break;
                }
            }
            safe_file_put_contents($notifFile, json_encode($notifs, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true]);
        }
        exit;
    }

    if ($action === 'delete_notification') {
        $notifFile = __DIR__ . DIRECTORY_SEPARATOR . 'notifications_data.json';
        $notifs = json_decode(safe_file_get_contents($notifFile), true);
        if (is_array($notifs)) {
            $newNotifs = [];
            foreach ($notifs as $n) {
                if ($n['id'] !== $_GET['id']) {
                    $newNotifs[] = $n;
                }
            }
            safe_file_put_contents($notifFile, json_encode($newNotifs, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true]);
        }
        exit;
    }


    if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $new_user = trim($input['username']);
        $new_pass = trim($input['password']);
        $new_fullname = trim($input['fullName']);
        
        if (empty($new_user) || empty($new_pass) || empty($new_fullname)) {
            echo json_encode(['success' => false, 'message' => 'Vui lòng điền đầy đủ thông tin (Họ tên, tài khoản, mật khẩu)!']);
            exit;
        }
        
        if (!preg_match('/^[a-zA-Z0-9@\._-]+$/', $new_user)) {
            echo json_encode(['success' => false, 'message' => 'Tên đăng nhập chứa ký tự không hợp lệ!']);
            exit;
        }

        $users = json_decode(safe_file_get_contents($usersFile), true);
        if (!is_array($users)) $users = [];
        
        foreach ($users as $u) {
            if (strtolower($u['username']) === strtolower($new_user)) {
                echo json_encode(['success' => false, 'message' => 'Tên đăng nhập đã tồn tại!']);
                exit;
            }
        }
        
        $users[] = [
            'username' => $new_user,
            'password' => $new_pass,
            'fullName' => $new_fullname,
            'is_owner' => false
        ];
        
        $write1 = safe_file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
        if ($write1 === false) {
            echo json_encode(['success' => false, 'message' => 'Lỗi máy chủ: Không thể cập nhật tệp tài khoản (users_data.json). Vui lòng phân quyền ghi thư mục (chmod 777)!']);
            exit;
        }
        
        // Initialize databases for the new user
        $new_suffix = '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $new_user);
        
        $write2 = safe_file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'exams_data' . $new_suffix . '.json', json_encode([]));
        $write3 = safe_file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'history_data' . $new_suffix . '.json', json_encode([]));
        $write4 = safe_file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'live_data' . $new_suffix . '.json', json_encode([]));
        $write5 = safe_file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'classes_data' . $new_suffix . '.json', json_encode([]));
        $write6 = safe_file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'documents_data' . $new_suffix . '.json', json_encode([]));
        $write7 = safe_file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'videos_data' . $new_suffix . '.json', json_encode([]));
        
        if ($write2 === false || $write3 === false || $write4 === false || $write5 === false || $write6 === false || $write7 === false) {
            echo json_encode(['success' => false, 'message' => 'Lỗi máy chủ: Không thể khởi tạo các tệp cơ sở dữ liệu học tập. Vui lòng phân quyền ghi thư mục (chmod 777)!']);
            exit;
        }
        
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $users = json_decode(safe_file_get_contents($usersFile), true);
        $authenticated = false;
        $matched_user = null;
        $input_username = isset($input['username']) ? trim($input['username']) : '';
        $input_password = isset($input['password']) ? trim($input['password']) : '';

        // 1. Check hardcoded owner fallback
        if (strtolower($input_username) === 'thaytuan@admin' && $input_password === 'thaytuangdtxq12') {
            $authenticated = true;
            $matched_user = array(
                'username' => 'thaytuan@admin',
                'password' => 'thaytuangdtxq12',
                'fullName' => 'Ths.Phạm Minh Tuấn',
                'is_owner' => true
            );
        } else if (is_array($users)) {
            // 2. Check JSON database for sub-admins or owner
            foreach ($users as $u) {
                if (strtolower($u['username']) === strtolower($input_username) && $u['password'] === $input_password) {
                    $authenticated = true;
                    $matched_user = $u;
                    break;
                }
            }
        }
        $is_student = false;
        $matched_student = null;
        if (!$authenticated) {
            $students = file_exists($studentsFile) ? json_decode(safe_file_get_contents($studentsFile), true) : [];
            if (is_array($students)) {
                $student_updated = false;
                foreach ($students as &$s) {
                    if (trim($s['id']) === trim($input_username) && trim($s['password']) === trim($input_password)) {
                        $is_student = true;
                        $s['loginCount'] = intval($s['loginCount'] ?? 0) + 1;
                        $s['lastLogin'] = date('H:i d/m/Y');
                        $student_updated = true;
                        $matched_student = $s;
                        break;
                    }
                }
                unset($s);
                if ($student_updated) {
                    safe_file_put_contents($studentsFile, json_encode($students, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
            }
        }

        if ($authenticated) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $matched_user['username'];
            $_SESSION['admin_fullname'] = isset($matched_user['fullName']) ? $matched_user['fullName'] : $matched_user['username'];
            $_SESSION['is_owner'] = !empty($matched_user['is_owner']);
            
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array(
                'success' => true, 
                'username' => $matched_user['username'],
                'fullName' => $_SESSION['admin_fullname'],
                'is_owner' => !empty($matched_user['is_owner']),
                'role' => 'admin'
            ));
        } else if ($is_student) {
            $_SESSION['student_logged_in'] = true;
            $_SESSION['student_id'] = $matched_student['id'];
            $_SESSION['student_fullname'] = $matched_student['fullName'];
            $_SESSION['student_class'] = $matched_student['class'];
            $_SESSION['student_teacher'] = $matched_student['teacher'] ?? 'thaytuan@admin';
            
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            $student_teacher = $matched_student['teacher'] ?? 'thaytuan@admin';
            $t_suffix = (strtolower($student_teacher) === 'thaytuan@admin') ? '' : '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $student_teacher);
            $publishFile = __DIR__ . DIRECTORY_SEPARATOR . 'gradebook_publish_status' . $t_suffix . '.json';
            $publishStatus = file_exists($publishFile) ? json_decode(safe_file_get_contents($publishFile), true) : [];
            if (!is_array($publishStatus)) $publishStatus = [];
            
            $classPub = $publishStatus[$matched_student['class']] ?? [];
            if ($classPub === true || $classPub === 1 || $classPub === '1') {
                $classPub = [
                    'tx1' => true, 'tx2' => true, 'tx3' => true, 'tx4' => true, 'tx5' => true, 'gk' => true, 'ck' => true
                ];
            } else if (!is_array($classPub)) {
                $classPub = [];
            }

            $normalizedScores = normalize_study_scores($matched_student['studyScores'] ?? null);
            $normalizedPub = normalize_publish_status($publishStatus, $matched_student['class']);
            echo json_encode(array(
                'success' => true,
                'username' => $matched_student['id'],
                'fullName' => $matched_student['fullName'],
                'role' => 'student',
                'class' => $matched_student['class'],
                'studyScores' => $normalizedScores,
                'studyScoresPublished' => $normalizedPub,
                'bonusPoints' => $matched_student['bonusPoints'] ?? 0,
                'bonusPointsHistory' => $matched_student['bonusPointsHistory'] ?? [],
                'loginCount' => $matched_student['loginCount'] ?? 1,
                'lastLogin' => $matched_student['lastLogin'] ?? date('H:i d/m/Y')
            ));
        } else {
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('success' => false, 'message' => 'Tên đăng nhập hoặc mật khẩu không đúng!'));
        }
        exit;
    }

    if ($action === 'change_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
            echo json_encode(array('success' => false, 'message' => 'Bạn chưa đăng nhập!'));
            exit;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $oldPassword = isset($input['oldPassword']) ? trim($input['oldPassword']) : '';
        $newPassword = isset($input['newPassword']) ? trim($input['newPassword']) : '';
        
        if (empty($oldPassword) || empty($newPassword)) {
            echo json_encode(array('success' => false, 'message' => 'Vui lòng nhập đầy đủ thông tin!'));
            exit;
        }
        
        $username = $_SESSION['admin_username'];
        $users = json_decode(safe_file_get_contents($usersFile), true);
        if (!is_array($users)) {
            $users = array();
        }
        $updated = false;
        
        foreach ($users as &$u) {
            if (strtolower($u['username']) === strtolower($username)) {
                if ($u['password'] === $oldPassword) {
                    $u['password'] = $newPassword;
                    $updated = true;
                }
                break;
            }
        }
        
        if (!$updated && strtolower($username) === 'thaytuan@admin' && 'thaytuangdtxq12' === $oldPassword) {
            $found = false;
            foreach ($users as &$u) {
                if (strtolower($u['username']) === 'thaytuan@admin') {
                    $u['password'] = $newPassword;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                array_push($users, array(
                    'username' => 'thaytuan@admin',
                    'password' => $newPassword,
                    'fullName' => 'Ths.Phạm Minh Tuấn',
                    'is_owner' => true
                ));
            }
            $updated = true;
        }
        
        if ($updated) {
            safe_file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
            echo json_encode(array('success' => true));
        } else {
            echo json_encode(array('success' => false, 'message' => 'Mật khẩu cũ không chính xác!'));
        }
        exit;
    }

    if ($action === 'update_profile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
            echo json_encode(array('success' => false, 'message' => 'Bạn chưa đăng nhập!'));
            exit;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $fullName = isset($input['fullName']) ? trim($input['fullName']) : '';
        
        if (empty($fullName)) {
            echo json_encode(array('success' => false, 'message' => 'Họ và tên không được để trống!'));
            exit;
        }
        
        $username = $_SESSION['admin_username'];
        $users = json_decode(safe_file_get_contents($usersFile), true);
        if (!is_array($users)) {
            $users = array();
        }
        $updated = false;
        
        foreach ($users as &$u) {
            if (strtolower($u['username']) === strtolower($username)) {
                $u['fullName'] = $fullName;
                $updated = true;
                break;
            }
        }
        
        if (!$updated && strtolower($username) === 'thaytuan@admin') {
            array_push($users, array(
                'username' => 'thaytuan@admin',
                'password' => 'thaytuangdtxq12',
                'fullName' => $fullName,
                'is_owner' => true
            ));
            $updated = true;
        }
        
        if ($updated) {
            $_SESSION['admin_fullname'] = $fullName;
            safe_file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
            echo json_encode(array('success' => true, 'fullName' => $fullName));
        } else {
            echo json_encode(array('success' => false, 'message' => 'Không tìm thấy tài khoản!'));
        }
        exit;
    }

    $public_actions = ['login', 'register', 'auth_check', 'student_get_grades', 'student_get_assigned_exams', 'student_change_password'];
    $is_student_auth = isset($_SESSION['student_logged_in']);
    $is_admin_auth = isset($_SESSION['admin_logged_in']);
    
    if (!empty($action) && !in_array($action, $public_actions)) {
        if (in_array($action, ['student_get_grades', 'student_get_assigned_exams']) && !$is_student_auth) {
            header('HTTP/1.1 401 Unauthorized');
            exit;
        }
        if (!in_array($action, ['student_get_grades', 'student_get_assigned_exams']) && !$is_admin_auth) {
            header('HTTP/1.1 401 Unauthorized');
            exit;
        }
    }

    // Protect document/video/notification functionality: only allow owner (admin chính)
    $doc_actions = ['list_documents', 'upload_document', 'toggle_document', 'edit_document_title', 'delete_document', 'list_videos', 'add_video', 'toggle_video', 'edit_video_title', 'delete_video', 'list_notifications', 'add_notification', 'toggle_notification', 'delete_notification', 'edit_notification_title'];
    if (in_array($action, $doc_actions) && empty($_SESSION['is_owner'])) {
        header('HTTP/1.1 403 Forbidden');
        echo json_encode(['success' => false, 'message' => 'Bạn không có quyền truy cập chức năng này!']);
        exit;
    }
    if ($action === 'auth_check') { 
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        if (isset($_SESSION['admin_logged_in'])) {
            echo json_encode(array(
                'loggedIn' => true, 
                'role' => 'admin',
                'username' => $_SESSION['admin_username'], 
                'fullName' => isset($_SESSION['admin_fullname']) ? $_SESSION['admin_fullname'] : $_SESSION['admin_username'], 
                'is_owner' => !empty($_SESSION['is_owner'])
            )); 
        } else if (isset($_SESSION['student_logged_in'])) {
            $studentsFile = __DIR__ . DIRECTORY_SEPARATOR . 'students_accounts.json';
            $students = file_exists($studentsFile) ? json_decode(safe_file_get_contents($studentsFile), true) : [];
            if (!is_array($students)) $students = [];
            
            $studyScores = null;
            $bonusPoints = 0;
            $bonusPointsHistory = [];
            $loginCount = 0;
            $lastLogin = '';
            $student_teacher = $_SESSION['student_teacher'] ?? 'thaytuan@admin';
            foreach ($students as $s) {
                if ($s['id'] === $_SESSION['student_id']) {
                    $studyScores = $s['studyScores'] ?? null;
                    $bonusPoints = $s['bonusPoints'] ?? 0;
                    $bonusPointsHistory = $s['bonusPointsHistory'] ?? [];
                    $loginCount = intval($s['loginCount'] ?? 0);
                    $lastLogin = $s['lastLogin'] ?? '';
                    break;
                }
            }
            
            $t_suffix = (strtolower($student_teacher) === 'thaytuan@admin') ? '' : '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $student_teacher);
            $publishFile = __DIR__ . DIRECTORY_SEPARATOR . 'gradebook_publish_status' . $t_suffix . '.json';
            $publishStatus = file_exists($publishFile) ? json_decode(safe_file_get_contents($publishFile), true) : [];
            if (!is_array($publishStatus)) $publishStatus = [];
            
            $classPub = $publishStatus[$_SESSION['student_class']] ?? [];
            if ($classPub === true || $classPub === 1 || $classPub === '1') {
                $classPub = [
                    'tx1' => true, 'tx2' => true, 'tx3' => true, 'tx4' => true, 'tx5' => true, 'gk' => true, 'ck' => true
                ];
            } else if (!is_array($classPub)) {
                $classPub = [];
            }

            $normalizedScores = normalize_study_scores($studyScores);
            $normalizedPub = normalize_publish_status($publishStatus, $_SESSION['student_class']);
            echo json_encode(array(
                'loggedIn' => true,
                'role' => 'student',
                'username' => $_SESSION['student_id'],
                'fullName' => $_SESSION['student_fullname'],
                'class' => $_SESSION['student_class'],
                'studyScores' => $normalizedScores,
                'studyScoresPublished' => $normalizedPub,
                'bonusPoints' => $bonusPoints,
                'bonusPointsHistory' => $bonusPointsHistory,
                'loginCount' => $loginCount,
                'lastLogin' => $lastLogin
            ));
        } else {
            echo json_encode(array('loggedIn' => false));
        }
        exit; 
    }
    if ($action === 'logout') { 
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        session_destroy(); 
        echo json_encode(array('success' => true)); 
        exit; 
    }

    if ($action === 'student_change_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_SESSION['student_logged_in'])) {
            header('HTTP/1.1 401 Unauthorized');
            exit;
        }
        $studentsFile = __DIR__ . DIRECTORY_SEPARATOR . 'students_accounts.json';
        $existing = file_exists($studentsFile) ? json_decode(safe_file_get_contents($studentsFile), true) : [];
        if (!is_array($existing)) $existing = [];
        
        $input = json_decode(file_get_contents('php://input'), true);
        $current_pwd = trim($input['currentPassword'] ?? '');
        $new_pwd = trim($input['newPassword'] ?? '');
        
        if (empty($new_pwd)) {
            echo json_encode(['success' => false, 'message' => 'Mật khẩu mới không được để trống!']);
            exit;
        }
        
        $student_id = $_SESSION['student_id'];
        $found = false;
        foreach ($existing as &$e) {
            if ($e['id'] === $student_id) {
                if ($e['password'] !== $current_pwd) {
                    echo json_encode(['success' => false, 'message' => 'Mật khẩu hiện tại không chính xác!']);
                    exit;
                }
                $e['password'] = $new_pwd;
                $found = true;
                break;
            }
        }
        
        if ($found) {
            safe_file_put_contents($studentsFile, json_encode($existing, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy tài khoản học sinh!']);
        }
        exit;
    }

    if ($action === 'delete_class_students' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $studentsFile = __DIR__ . DIRECTORY_SEPARATOR . 'students_accounts.json';
        $existing = file_exists($studentsFile) ? json_decode(safe_file_get_contents($studentsFile), true) : [];
        if (!is_array($existing)) $existing = [];
        
        $input = json_decode(file_get_contents('php://input'), true);
        $className = trim($input['class'] ?? '');
        
        if (empty($className)) {
            echo json_encode(['success' => false, 'message' => 'Lớp không được để trống!']);
            exit;
        }
        
        $new_list = [];
        $count = 0;
        foreach ($existing as $e) {
            if (trim($e['class']) === $className) {
                if (strtolower($_SESSION['admin_username']) === 'thaytuan@admin' || strtolower($e['teacher'] ?? '') === strtolower($_SESSION['admin_username'])) {
                    $count++;
                    continue;
                }
            }
            $new_list[] = $e;
        }
        
        safe_file_put_contents($studentsFile, json_encode($new_list, JSON_PRETTY_PRINT));
        echo json_encode(['success' => true, 'deletedCount' => $count]);
        exit;
    }

    if ($action === 'student_get_grades') {
        if (!isset($_SESSION['student_logged_in'])) {
            header('HTTP/1.1 401 Unauthorized');
            exit;
        }
        $t_username = $_SESSION['student_teacher'];
        $t_suffix = (strtolower($t_username) === 'thaytuan@admin') ? '' : '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $t_username);
        
        $t_historyFile = __DIR__ . DIRECTORY_SEPARATOR . 'history_data' . $t_suffix . '.json';
        $t_dataFile = __DIR__ . DIRECTORY_SEPARATOR . 'exams_data' . $t_suffix . '.json';
        
        $history = file_exists($t_historyFile) ? json_decode(safe_file_get_contents($t_historyFile), true) : [];
        if (!is_array($history)) $history = [];
        
        $exams = file_exists($t_dataFile) ? json_decode(safe_file_get_contents($t_dataFile), true) : [];
        if (!is_array($exams)) $exams = [];
        
        $std_name = $_SESSION['student_fullname'];
        $std_class = $_SESSION['student_class'];
        
        $results = [];
        foreach ($history as $h) {
            if (clean_compare_name($h['name']) === clean_compare_name($std_name) && clean_compare_name($h['class']) === clean_compare_name($std_class)) {
                $exam = null;
                foreach ($exams as $e) {
                    if ($e['id'] === $h['examId']) {
                        $exam = $e;
                        break;
                    }
                }
                
                // Đề thi đã xóa đi rồi hoặc đã đưa vào lưu trữ -> Coi như mất, HS không thấy bài đó nữa
                if (!$exam || !empty($exam['isDeleted'])) {
                    continue;
                }
                
                $exam_mode = $exam ? ($exam['examMode'] ?? 'normal') : 'normal';
                $allow_review = $exam ? ($exam['allowReview'] ?? true) : true;
                $is_scores_published = $exam ? (!empty($exam['publishGrades'])) : false;
                $is_answers_published = $exam ? (!empty($exam['publishAnswers'])) : false;
                
                $hide_score = ($exam_mode === 'test' && !$is_scores_published);
                $hide_review = ($exam_mode === 'test' && !$is_answers_published) || !$allow_review;
                
                $results[] = [
                    'examTitle' => $h['examTitle'] ?? ($exam ? $exam['title'] : 'Bài kiểm tra'),
                    'score' => $hide_score ? 'N/A' : ($h['score'] ?? 0),
                    'hideScore' => $hide_score,
                    'hideReview' => $hide_review,
                    'examId' => $h['examId'],
                    'answers' => $h['userAnswers'] ?? $h['answers'] ?? null,
                    'startTime' => $h['startTime'] ?? null,
                    'endTime' => $h['endTime'] ?? null,
                    'exam' => $hide_review ? null : $exam
                ];
            }
        }
        
        echo json_encode($results);
        exit;
    }

    if ($action === 'student_get_assigned_exams') {
        if (!isset($_SESSION['student_logged_in'])) {
            header('HTTP/1.1 401 Unauthorized');
            exit;
        }
        $t_username = $_SESSION['student_teacher'] ?? 'thaytuan@admin';
        $t_suffix = (strtolower($t_username) === 'thaytuan@admin') ? '' : '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $t_username);
        
        $t_historyFile = __DIR__ . DIRECTORY_SEPARATOR . 'history_data' . $t_suffix . '.json';
        $t_dataFile = __DIR__ . DIRECTORY_SEPARATOR . 'exams_data' . $t_suffix . '.json';
        
        $history = file_exists($t_historyFile) ? json_decode(safe_file_get_contents($t_historyFile), true) : [];
        if (!is_array($history)) $history = [];
        
        $exams = file_exists($t_dataFile) ? json_decode(safe_file_get_contents($t_dataFile), true) : [];
        if (!is_array($exams)) $exams = [];
        
        $std_name = $_SESSION['student_fullname'] ?? '';
        $std_class = $_SESSION['student_class'] ?? '';
        
        $assignedExams = [];
        foreach ($exams as $e) {
            // Chỉ hiển thị đề thi đang được CÔNG BỐ (isPublished === true) và KHÔNG bị xóa/lưu trữ (isDeleted = false)
            if (!empty($e['isDeleted'])) continue;
            if (empty($e['isPublished']) || $e['isPublished'] !== true) continue;
            if (isset($e['visible']) && $e['visible'] === false) continue;
            
            if (exam_matches_student($e, $std_class)) {
                $submissions = [];
                foreach ($history as $h) {
                    if ($h['examId'] === $e['id'] && 
                        clean_compare_name($h['name'] ?? '') === clean_compare_name($std_name) && 
                        clean_compare_name($h['class'] ?? '') === clean_compare_name($std_class)) {
                        $submissions[] = $h;
                    }
                }
                
                $submissionCount = count($submissions);
                $latestSub = $submissionCount > 0 ? $submissions[$submissionCount - 1] : null;
                $latestScore = null;
                if ($latestSub) {
                    $exam_mode = $e['examMode'] ?? 'normal';
                    $is_scores_pub = !empty($e['publishGrades']);
                    if ($exam_mode !== 'test' || $is_scores_pub) {
                        $latestScore = $latestSub['score'] ?? null;
                    }
                }
                
                $assignedTarget = 'Khối ' . ($e['grade'] === 'tot_nghiep' ? '12 (Ôn TN)' : ($e['grade'] ?? '12'));
                if (!empty($e['assignedClass']) && $e['assignedClass'] !== 'all') {
                    $assignedTarget = 'Lớp ' . $e['assignedClass'];
                } elseif (!empty($e['selectedClasses']) && is_array($e['selectedClasses']) && count($e['selectedClasses']) > 0) {
                    $classNames = array_map(function($c) { return is_array($c) ? ($c['className'] ?? '') : (string)$c; }, $e['selectedClasses']);
                    $assignedTarget = 'Lớp ' . implode(', ', array_filter($classNames));
                }
                
                $assignedExams[] = [
                    'id' => $e['id'],
                    'title' => $e['title'] ?? 'Bài kiểm tra',
                    'grade' => $e['grade'] ?? '12',
                    'category' => $e['category'] ?? '',
                    'assignedTarget' => $assignedTarget,
                    'assignedClass' => $e['assignedClass'] ?? '',
                    'examMode' => $e['examMode'] ?? 'normal',
                    'duration' => $e['duration'] ?? 90,
                    'questionCount' => (($e['config']['p1'] ?? 0) + ($e['config']['p2'] ?? 0) + ($e['config']['p3'] ?? 0)),
                    'maxAttempts' => $e['maxAttempts'] ?? 0,
                    'submissionCount' => $submissionCount,
                    'hasSubmitted' => $submissionCount > 0,
                    'latestScore' => $latestScore,
                    'createdAt' => $e['createdAt'] ?? ''
                ];
            }
        }
        
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($assignedExams);
        exit;
    }

    if ($action === 'list_students') {
        $students = file_exists($studentsFile) ? json_decode(safe_file_get_contents($studentsFile), true) : [];
        if (!is_array($students)) $students = [];
        foreach ($students as &$s) {
            $s['studyScores'] = normalize_study_scores($s['studyScores'] ?? null);
            $s['loginCount'] = intval($s['loginCount'] ?? 0);
            $s['lastLogin'] = $s['lastLogin'] ?? '';
        }
        unset($s);
        
        $current_admin = $_SESSION['admin_username'];
        $is_owner = (strtolower($current_admin) === 'thaytuan@admin');
        
        if (!$is_owner) {
            $students = array_values(array_filter($students, function($s) use ($current_admin) {
                return strtolower($s['teacher'] ?? '') === strtolower($current_admin);
            }));
        }
        
        echo json_encode($students);
        exit;
    }

    if ($action === 'get_gradebook_publish_status') {
        $publishFile = __DIR__ . DIRECTORY_SEPARATOR . 'gradebook_publish_status' . $suffix . '.json';
        $status = file_exists($publishFile) ? json_decode(safe_file_get_contents($publishFile), true) : [];
        if (!is_array($status)) $status = [];
        
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($status);
        exit;
    }

    if ($action === 'save_gradebook_publish_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $class = $input['class'] ?? '';
        $column = $input['column'] ?? '';
        $published = !empty($input['published']);
        $semester = $input['semester'] ?? 'hk1';
        
        $publishFile = __DIR__ . DIRECTORY_SEPARATOR . 'gradebook_publish_status' . $suffix . '.json';
        $status = file_exists($publishFile) ? json_decode(safe_file_get_contents($publishFile), true) : [];
        if (!is_array($status)) $status = [];
        
        if (!isset($status[$class]) || !is_array($status[$class])) {
            $status[$class] = [];
        }
        
        // Handle legacy flat structure
        if (isset($status[$class]['tx1']) || isset($status[$class]['gk']) || isset($status[$class]['ck']) || isset($status[$class]['all'])) {
            $default = [ 'tx1' => false, 'tx2' => false, 'tx3' => false, 'tx4' => false, 'tx5' => false, 'gk' => false, 'ck' => false ];
            $status[$class] = [
                'hk1' => array_merge($default, $status[$class]),
                'hk2' => $default
            ];
        }
        
        if (!isset($status[$class][$semester])) {
            $status[$class][$semester] = [ 'tx1' => false, 'tx2' => false, 'tx3' => false, 'tx4' => false, 'tx5' => false, 'gk' => false, 'ck' => false ];
        }
        
        if ($column !== '') {
            $status[$class][$semester][$column] = $published;
        } else {
            foreach (['tx1', 'tx2', 'tx3', 'tx4', 'tx5', 'gk', 'ck'] as $col) {
                $status[$class][$semester][$col] = $published;
            }
        }
        safe_file_put_contents($publishFile, json_encode($status, JSON_PRETTY_PRINT));
        
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'save_class_gradebook' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $scoresMap = $input['scoresMap'] ?? [];
        $semester = $input['semester'] ?? 'hk1';
        
        $studentsFile = __DIR__ . DIRECTORY_SEPARATOR . 'students_accounts.json';
        $students = file_exists($studentsFile) ? json_decode(safe_file_get_contents($studentsFile), true) : [];
        if (!is_array($students)) $students = [];
        
        foreach ($students as &$s) {
            if (isset($scoresMap[$s['id']])) {
                $s['studyScores'] = normalize_study_scores($s['studyScores'] ?? null);
                $s['studyScores'][$semester] = $scoresMap[$s['id']];
            }
        }
        
        safe_file_put_contents($studentsFile, json_encode($students, JSON_PRETTY_PRINT));
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'update_student_study_scores' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? '';
        $scores = $input['scores'] ?? [];
        $semester = $input['semester'] ?? 'hk1';
        
        $studentsFile = __DIR__ . DIRECTORY_SEPARATOR . 'students_accounts.json';
        $students = file_exists($studentsFile) ? json_decode(safe_file_get_contents($studentsFile), true) : [];
        if (!is_array($students)) $students = [];
        
        $updated = false;
        foreach ($students as &$s) {
            if ($s['id'] === $id) {
                $s['studyScores'] = normalize_study_scores($s['studyScores'] ?? null);
                $s['studyScores'][$semester] = $scores;
                $updated = true;
                break;
            }
        }
        
        if ($updated) {
            safe_file_put_contents($studentsFile, json_encode($students, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy học sinh!']);
        }
        exit;
    }

    if ($action === 'update_student_bonus_points' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? '';
        $bonusPoints = $input['bonusPoints'] ?? 0;
        if (is_numeric($bonusPoints)) {
            $bonusPoints = floatval($bonusPoints);
        } elseif (is_array($bonusPoints)) {
            foreach ($bonusPoints as $k => $v) {
                $bonusPoints[$k] = floatval($v);
            }
        }
        
        $studentsFile = __DIR__ . DIRECTORY_SEPARATOR . 'students_accounts.json';
        $students = file_exists($studentsFile) ? json_decode(safe_file_get_contents($studentsFile), true) : [];
        if (!is_array($students)) $students = [];
        
        $updated = false;
        foreach ($students as &$s) {
            if ($s['id'] === $id) {
                $s['bonusPoints'] = $bonusPoints;
                $s['bonusPointsHistory'] = $input['bonusPointsHistory'] ?? [];
                $updated = true;
                break;
            }
        }
        
        if ($updated) {
            safe_file_put_contents($studentsFile, json_encode($students, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy học sinh!']);
        }
        exit;
    }


    if ($action === 'create_student_accounts' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $existing = file_exists($studentsFile) ? json_decode(safe_file_get_contents($studentsFile), true) : [];
        if (!is_array($existing)) $existing = [];
        
        $input = json_decode(file_get_contents('php://input'), true);
        $new_accounts = $input['accounts'] ?? [];
        
        $added = 0;
        foreach ($new_accounts as $new_acc) {
            $id = trim($new_acc['id']);
            if (empty($id)) continue;
            
            $exists = false;
            foreach ($existing as $e) {
                if ($e['id'] === $id) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $new_acc['fullName'] = trim(mb_strtoupper($new_acc['fullName'] ?? '', 'UTF-8'));
                $new_acc['class'] = trim(mb_strtoupper($new_acc['class'] ?? '', 'UTF-8'));
                $new_acc['teacher'] = $_SESSION['admin_username'];
                $new_acc['loginCount'] = 0;
                $new_acc['lastLogin'] = '';
                $existing[] = $new_acc;
                $added++;
            }
        }
        
        safe_file_put_contents($studentsFile, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode(['success' => true, 'added' => $added]);
        exit;
    }

    if ($action === 'reset_student_login_count' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $studentsFile = __DIR__ . DIRECTORY_SEPARATOR . 'students_accounts.json';
        $existing = file_exists($studentsFile) ? json_decode(safe_file_get_contents($studentsFile), true) : [];
        if (!is_array($existing)) $existing = [];
        
        $input = json_decode(file_get_contents('php://input'), true);
        $id = trim($input['id'] ?? '');
        
        $found = false;
        $currentAdmin = strtolower($_SESSION['admin_username'] ?? '');
        $isOwner = ($currentAdmin === 'thaytuan@admin' || !empty($_SESSION['is_owner']));
        
        foreach ($existing as &$e) {
            if ($e['id'] === $id) {
                if ($isOwner || strtolower($e['teacher'] ?? '') === $currentAdmin) {
                    $e['loginCount'] = 0;
                    $e['lastLogin'] = '';
                    $found = true;
                    break;
                }
            }
        }
        unset($e);
        
        if ($found) {
            safe_file_put_contents($studentsFile, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy tài khoản hoặc bạn không có quyền!']);
        }
        exit;
    }

    if ($action === 'delete_student_account' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $existing = file_exists($studentsFile) ? json_decode(safe_file_get_contents($studentsFile), true) : [];
        if (!is_array($existing)) $existing = [];
        
        $input = json_decode(file_get_contents('php://input'), true);
        $id = trim($input['id'] ?? '');
        
        $new_list = [];
        $found = false;
        foreach ($existing as $e) {
            if ($e['id'] === $id) {
                if (strtolower($_SESSION['admin_username']) === 'thaytuan@admin' || strtolower($e['teacher'] ?? '') === strtolower($_SESSION['admin_username'])) {
                    $found = true;
                    continue;
                }
            }
            $new_list[] = $e;
        }
        
        if ($found) {
            safe_file_put_contents($studentsFile, json_encode($new_list, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy tài khoản hoặc bạn không có quyền xóa!']);
        }
        exit;
    }

    if ($action === 'update_student_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $existing = file_exists($studentsFile) ? json_decode(safe_file_get_contents($studentsFile), true) : [];
        if (!is_array($existing)) $existing = [];
        
        $input = json_decode(file_get_contents('php://input'), true);
        $id = trim($input['id'] ?? '');
        $new_password = trim($input['password'] ?? '');
        
        if (empty($new_password)) {
            echo json_encode(['success' => false, 'message' => 'Mật khẩu không được để trống!']);
            exit;
        }
        
        $found = false;
        foreach ($existing as &$e) {
            if ($e['id'] === $id) {
                if (strtolower($_SESSION['admin_username']) === 'thaytuan@admin' || strtolower($e['teacher'] ?? '') === strtolower($_SESSION['admin_username'])) {
                    $e['password'] = $new_password;
                    $found = true;
                    break;
                }
            }
        }
        
        if ($found) {
            safe_file_put_contents($studentsFile, json_encode($existing, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Không thể đổi mật khẩu!']);
        }
        exit;
    }

    if ($action === 'update_student_info' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        $existing = file_exists($studentsFile) ? json_decode(safe_file_get_contents($studentsFile), true) : [];
        if (!is_array($existing)) $existing = [];
        
        $input = json_decode(file_get_contents('php://input'), true);
        $oldId = trim($input['oldId'] ?? ($input['id'] ?? ''));
        $newId = trim($input['newId'] ?? ($input['id'] ?? ''));
        $fullName = trim($input['fullName'] ?? '');
        $class = trim($input['class'] ?? '');
        $password = trim($input['password'] ?? '');

        if (empty($oldId)) {
            echo json_encode(['success' => false, 'message' => 'Mã học sinh không hợp lệ!']);
            exit;
        }
        if (empty($newId)) {
            echo json_encode(['success' => false, 'message' => 'Mã học sinh mới không được để trống!']);
            exit;
        }
        if (empty($fullName)) {
            echo json_encode(['success' => false, 'message' => 'Họ và tên không được để trống!']);
            exit;
        }
        if (empty($class)) {
            echo json_encode(['success' => false, 'message' => 'Lớp không được để trống!']);
            exit;
        }

        // Check duplicate newId if changed
        if ($newId !== $oldId) {
            foreach ($existing as $e) {
                if (($e['id'] ?? '') === $newId) {
                    echo json_encode(['success' => false, 'message' => "Mã học sinh $newId đã tồn tại trên hệ thống!"]);
                    exit;
                }
            }
        }

        $found = false;
        $currentAdmin = strtolower($_SESSION['admin_username'] ?? '');
        $isOwner = ($currentAdmin === 'thaytuan@admin' || !empty($_SESSION['is_owner']));

        foreach ($existing as &$e) {
            if (($e['id'] ?? '') === $oldId) {
                if ($isOwner || strtolower($e['teacher'] ?? '') === $currentAdmin) {
                    $e['id'] = $newId;
                    $e['fullName'] = mb_strtoupper($fullName, 'UTF-8');
                    $e['class'] = mb_strtoupper($class, 'UTF-8');
                    if (!empty($password)) {
                        $e['password'] = $password;
                    }
                    $found = true;
                    break;
                }
            }
        }

        if ($found) {
            safe_file_put_contents($studentsFile, json_encode($existing, JSON_PRETTY_PRINT));
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy tài khoản hoặc bạn không có quyền chỉnh sửa!']);
        }
        exit;
    }
    
    // API quản lý tài khoản admin phụ (Chỉ dành cho Owner)
    if ($action === 'list_users') {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        if (empty($_SESSION['is_owner'])) {
            echo json_encode(array('success' => false, 'message' => 'Bạn không có quyền quản lý tài khoản!'));
            exit;
        }
        $users = json_decode(safe_file_get_contents($usersFile), true);
        if (!is_array($users)) {
            $users = array();
        }
        $result = array();
        foreach ($users as $u) {
            $result[] = array(
                'username' => $u['username'],
                'fullName' => isset($u['fullName']) ? $u['fullName'] : $u['username'],
                'is_owner' => !empty($u['is_owner'])
            );
        }
        echo json_encode(array('success' => true, 'users' => $result));
        exit;
    }

    if ($action === 'add_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        if (empty($_SESSION['is_owner'])) {
            echo json_encode(array('success' => false, 'message' => 'Bạn không có quyền thêm tài khoản!'));
            exit;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $new_user = trim($input['username']);
        $new_pass = trim($input['password']);
        $new_fullname = trim($input['fullName']);
        
        if (empty($new_user) || empty($new_pass) || empty($new_fullname)) {
            echo json_encode(array('success' => false, 'message' => 'Vui lòng điền đầy đủ thông tin!'));
            exit;
        }
        
        if (!preg_match('/^[a-zA-Z0-9@\._-]+$/', $new_user)) {
            echo json_encode(array('success' => false, 'message' => 'Tên đăng nhập chứa ký tự không hợp lệ!'));
            exit;
        }

        $users = json_decode(safe_file_get_contents($usersFile), true);
        if (!is_array($users)) {
            $users = array();
        }
        
        foreach ($users as $u) {
            if (strtolower($u['username']) === strtolower($new_user)) {
                echo json_encode(array('success' => false, 'message' => 'Tên đăng nhập đã tồn tại!'));
                exit;
            }
        }
        
        $users[] = array(
            'username' => $new_user,
            'password' => $new_pass,
            'fullName' => $new_fullname,
            'is_owner' => false
        );
        
        $write1 = safe_file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
        if ($write1 === false) {
            echo json_encode(array('success' => false, 'message' => 'Không thể cập nhật tệp tài khoản!'));
            exit;
        }
        
        // Khởi tạo các database học tập cho admin phụ mới
        $new_suffix = '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $new_user);
        safe_file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'exams_data' . $new_suffix . '.json', json_encode(array()));
        safe_file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'history_data' . $new_suffix . '.json', json_encode(array()));
        safe_file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'live_data' . $new_suffix . '.json', json_encode(array()));
        safe_file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'classes_data' . $new_suffix . '.json', json_encode(array()));
        safe_file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'documents_data' . $new_suffix . '.json', json_encode(array()));
        safe_file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'videos_data' . $new_suffix . '.json', json_encode(array()));
        
        echo json_encode(array('success' => true));
        exit;
    }

    if ($action === 'delete_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        if (empty($_SESSION['is_owner'])) {
            echo json_encode(array('success' => false, 'message' => 'Bạn không có quyền xóa tài khoản!'));
            exit;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $target_user = trim($input['username']);
        
        if (strtolower($target_user) === 'thaytuan@admin') {
            echo json_encode(array('success' => false, 'message' => 'Không thể xóa tài khoản Admin chính!'));
            exit;
        }

        $users = json_decode(safe_file_get_contents($usersFile), true);
        if (!is_array($users)) {
            $users = array();
        }
        
        $new_users = array();
        $found = false;
        foreach ($users as $u) {
            if (strtolower($u['username']) === strtolower($target_user)) {
                $found = true;
            } else {
                $new_users[] = $u;
            }
        }
        
        if (!$found) {
            echo json_encode(array('success' => false, 'message' => 'Không tìm thấy tài khoản để xóa!'));
            exit;
        }
        
        $write1 = safe_file_put_contents($usersFile, json_encode($new_users, JSON_PRETTY_PRINT));
        if ($write1 === false) {
            echo json_encode(array('success' => false, 'message' => 'Không thể cập nhật tệp tài khoản!'));
            exit;
        }
        
        echo json_encode(array('success' => true));
        exit;
    }

    if ($action === 'reset_admin_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        if (empty($_SESSION['is_owner'])) {
            echo json_encode(array('success' => false, 'message' => 'Bạn không có quyền thay đổi mật khẩu tài khoản khác!'));
            exit;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $target_user = trim($input['username']);
        $new_pass = trim($input['password']);
        
        if (empty($target_user) || empty($new_pass)) {
            echo json_encode(array('success' => false, 'message' => 'Vui lòng cung cấp đầy đủ thông tin!'));
            exit;
        }

        $users = json_decode(safe_file_get_contents($usersFile), true);
        if (!is_array($users)) {
            $users = array();
        }
        
        $updated = false;
        foreach ($users as &$u) {
            if (strtolower($u['username']) === strtolower($target_user)) {
                $u['password'] = $new_pass;
                $updated = true;
                break;
            }
        }
        
        if (!$updated) {
            echo json_encode(array('success' => false, 'message' => 'Không tìm thấy tài khoản!'));
            exit;
        }
        
        $write1 = safe_file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
        if ($write1 === false) {
            echo json_encode(array('success' => false, 'message' => 'Không thể cập nhật mật khẩu!'));
            exit;
        }
        
        echo json_encode(array('success' => true));
        exit;
    }

    if ($action === 'update_subadmin_name' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        if (empty($_SESSION['is_owner'])) {
            echo json_encode(array('success' => false, 'message' => 'Bạn không có quyền thay đổi họ tên tài khoản khác!'));
            exit;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $target_user = trim($input['username']);
        $new_fullname = trim($input['fullName']);
        
        if (empty($target_user) || empty($new_fullname)) {
            echo json_encode(array('success' => false, 'message' => 'Vui lòng cung cấp đầy đủ thông tin!'));
            exit;
        }

        $users = json_decode(safe_file_get_contents($usersFile), true);
        if (!is_array($users)) {
            $users = array();
        }
        
        $updated = false;
        foreach ($users as &$u) {
            if (strtolower($u['username']) === strtolower($target_user)) {
                $u['fullName'] = $new_fullname;
                $updated = true;
                break;
            }
        }
        
        if (!$updated) {
            echo json_encode(array('success' => false, 'message' => 'Không tìm thấy tài khoản!'));
            exit;
        }
        
        $write1 = safe_file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
        if ($write1 === false) {
            echo json_encode(array('success' => false, 'message' => 'Không thể cập nhật họ tên!'));
            exit;
        }
        
        echo json_encode(array('success' => true));
        exit;
    }
    
    if ($action === 'list') { 
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");
        $content = safe_file_get_contents($dataFile);
        $data = json_decode($content, true);
        echo json_encode(is_array($data) ? $data : array()); 
        exit; 
    }

    if ($action === 'get_classes') {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        $classes = json_decode(safe_file_get_contents($classFile), true);
        echo json_encode(is_array($classes) ? array_values($classes) : array());
        exit;
    }

    if ($action === 'save_class' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $classes = json_decode(safe_file_get_contents($classFile), true);
        if (!is_array($classes)) $classes = [];

        if (empty($input['id'])) $input['id'] = 'class_' . time();
        
        $found = false;
        foreach($classes as &$c) {
            if ($c['id'] === $input['id']) {
                $c = $input; 
                $found = true; 
                break;
            }
        }
        if (!$found) $classes[] = $input;
        
        safe_file_put_contents($classFile, json_encode(array_values($classes)));
        echo json_encode(array('success' => true)); 
        exit;
    }

    if ($action === 'delete_class' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $classes = json_decode(safe_file_get_contents($classFile), true);
        if (is_array($classes)) {
            $classes = array_filter($classes, function($c) use ($input) { return $c['id'] !== $input['id']; });
            safe_file_put_contents($classFile, json_encode(array_values($classes)));
        }
        echo json_encode(array('success' => true)); 
        exit;
    }

    if ($action === 'save_student_list' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $exams = json_decode(safe_file_get_contents($dataFile), true);
        if(is_array($exams)) {
            foreach($exams as &$e) {
                if ($e['id'] == $input['id']) {
                    $e['requireLoginList'] = isset($input['requireLoginList']) ? $input['requireLoginList'] : false;
                    $e['allowedStudents'] = isset($input['allowedStudents']) ? array_values($input['allowedStudents']) : [];
                    $e['customStudents'] = isset($input['customStudents']) ? array_values($input['customStudents']) : [];
                    $e['selectedClasses'] = isset($input['selectedClasses']) ? array_values($input['selectedClasses']) : [];
                    safe_file_put_contents($dataFile, json_encode($exams));
                    echo json_encode(array('success' => true)); exit;
                }
            }
        }
        echo json_encode(array('success' => false)); exit;
    }

    if ($action === 'recalculate_scores' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $examId = $input['examId'];

        $exams = json_decode(safe_file_get_contents($dataFile), true);
        $currentExam = null;
        if(is_array($exams)){
            foreach($exams as $e){
                if((string)$e['id'] === (string)$examId){
                    $currentExam = $e;
                    break;
                }
            }
        }

        if ($currentExam) {
            $history = json_decode(safe_file_get_contents($historyFile), true);
            if(is_array($history)){
                $conf = isset($currentExam['config']) ? $currentExam['config'] : array('p1'=>0,'p2'=>0,'p3'=>0);
                $scoring = isset($currentExam['scoring']) ? $currentExam['scoring'] : array('p1'=>0.25,'p2'=>array('4'=>1.0,'3'=>0.5,'2'=>0.25,'1'=>0.1),'p3'=>0.5);
                $cAns = isset($currentExam['correctAnswers']) ? $currentExam['correctAnswers'] : array();

                $sP1 = (float)(isset($scoring['p1']) ? $scoring['p1'] : 0.25);
                $sP2_4 = (float)(isset($scoring['p2']['4']) ? $scoring['p2']['4'] : 1.0);
                $sP2_3 = (float)(isset($scoring['p2']['3']) ? $scoring['p2']['3'] : 0.5);
                $sP2_2 = (float)(isset($scoring['p2']['2']) ? $scoring['p2']['2'] : 0.25);
                $sP2_1 = (float)(isset($scoring['p2']['1']) ? $scoring['p2']['1'] : 0.1);
                $sP3 = (float)(isset($scoring['p3']) ? $scoring['p3'] : 0.5);

                $updated = false;
                foreach($history as &$h){
                    if((string)$h['examId'] === (string)$examId){
                        $score = 0;
                        $uAns = isset($h['userAnswers']) ? $h['userAnswers'] : array();
                        
                        $ansP1 = isset($uAns['p1']) ? $uAns['p1'] : array();
                        $ansP2 = isset($uAns['p2']) ? $uAns['p2'] : array();
                        $ansP3 = isset($uAns['p3']) ? $uAns['p3'] : array();

                        $corP1 = isset($cAns['p1']) ? $cAns['p1'] : array();
                        $corP2 = isset($cAns['p2']) ? $cAns['p2'] : array();
                        $corP3 = isset($cAns['p3']) ? $cAns['p3'] : array();

                        $limitP1 = isset($conf['p1']) ? (int)$conf['p1'] : 0;
                        for ($i = 1; $i <= $limitP1; $i++) {
                            if (isset($ansP1[$i]) && isset($corP1[$i]) && trim((string)$ansP1[$i]) === trim((string)$corP1[$i])) {
                                $score += $sP1;
                            }
                        }

                        $limitP2 = isset($conf['p2']) ? (int)$conf['p2'] : 0;
                        for ($i = 1; $i <= $limitP2; $i++) {
                            $correctCount = 0;
                            foreach (array('a', 'b', 'c', 'd') as $sub) {
                                if (isset($ansP2[$i][$sub]) && isset($corP2[$i][$sub])) {
                                    $uVal = is_bool($ansP2[$i][$sub]) ? ($ansP2[$i][$sub] ? '1':'0') : trim((string)$ansP2[$i][$sub]);
                                    $cVal = is_bool($corP2[$i][$sub]) ? ($corP2[$i][$sub] ? '1':'0') : trim((string)$corP2[$i][$sub]);
                                    if ($uVal === $cVal) {
                                        $correctCount++;
                                    }
                                }
                            }
                            if ($correctCount === 4) $score += $sP2_4;
                            elseif ($correctCount === 3) $score += $sP2_3;
                            elseif ($correctCount === 2) $score += $sP2_2;
                            elseif ($correctCount === 1) $score += $sP2_1;
                        }

                        $limitP3 = isset($conf['p3']) ? (int)$conf['p3'] : 0;
                        for ($i = 1; $i <= $limitP3; $i++) {
                            $uA = preg_replace('/[ ,$]/', '', strtolower(trim((string)(isset($ansP3[$i]) ? $ansP3[$i] : ''))));
                            $cA = preg_replace('/[ ,$]/', '', strtolower(trim((string)(isset($corP3[$i]) ? $corP3[$i] : ''))));
                            if ($uA !== '' && $cA !== '' && $uA === $cA) {
                                $score += $sP3;
                            }
                        }

                        $finalScore = number_format($score, 2, '.', '');
                        if(strpos($finalScore, '.00') !== false) $finalScore = str_replace('.00', '', $finalScore);
                        $h['score'] = $finalScore;
                        $updated = true;
                    }
                }
                if ($updated) {
                    safe_file_put_contents($historyFile, json_encode($history));
                }
            }
        }
        echo json_encode(array('success' => true)); exit;
    }

    if ($action === 'upload_image' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $file = $_FILES['image'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'])) {
            $fileName = 'img_' . time() . '_' . rand(100,999) . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
                echo json_encode(array('success' => true, 'fileName' => $fileName));
                exit;
            }
        }
        echo json_encode(array('success' => false, 'message' => 'Lỗi tải ảnh!'));
        exit;
    }

    if ($action === 'get_live') {
        $liveSessionsDir = getLiveSessionsDir();
        
        $filtered = [];
        $examId = $_GET['examId'] ?? '';
        $now = time();
        
        $files = glob($liveSessionsDir . DIRECTORY_SEPARATOR . 'live_session_*.json');
        if (is_array($files)) {
            foreach ($files as $file) {
                $content = safe_file_get_contents($file);
                if ($content) {
                    $v = json_decode($content, true);
                    if ($v) {
                        $lastPing = (int)($v['lastPing'] ?? 0);
                        // Chỉ xóa file nếu quá 1 tiếng không hoạt động
                        if ($now - $lastPing > 3600) {
                            @unlink($file);
                        } elseif ((string)$v['examId'] === (string)$examId) {
                            $filtered[] = $v;
                        }
                    } else {
                        @unlink($file);
                    }
                }
            }
        }
        echo json_encode(array_values($filtered));
        exit;
    }

    if ($action === 'compile_tikz' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $tikzContent = $input['tikz'] ?? '';
        if (empty($tikzContent)) {
            echo json_encode(['success' => false, 'message' => 'Mã TikZ trống!']);
            exit;
        }
        
        $imgPath = compileTikzToImage($tikzContent, $uploadDir, $uploadFolderName);
        if (strpos($imgPath, 'ERROR_LATEX|||') === 0) {
            $parts = explode('|||', $imgPath);
            $errorMsg = isset($parts[1]) ? $parts[1] : 'Lỗi không xác định';
            echo json_encode(['success' => false, 'message' => 'Lỗi biên dịch TikZ: ' . $errorMsg]);
        } elseif ($imgPath) {
            echo json_encode(['success' => true, 'fileName' => basename($imgPath)]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Không thể biên dịch TikZ!']);
        }
        exit;
    }

    // Endpoint mới để tải toàn bộ lịch sử (dành cho Sổ Điểm)
    if ($action === 'get_all_history') {
        $history = json_decode(safe_file_get_contents($historyFile), true);
        echo json_encode(is_array($history) ? $history : array());
        exit;
    }

    if ($action === 'get_history') {
        $history = json_decode(safe_file_get_contents($historyFile), true);
        if(!is_array($history)) $history = array();
        $filtered = array();
        foreach($history as $h) { if((string)$h['examId'] === (string)$_GET['examId']) $filtered[] = $h; }
        echo json_encode($filtered); exit;
    }

    if ($action === 'delete_history' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $history = json_decode(safe_file_get_contents($historyFile), true);
        if(is_array($history)) {
            $new = array();
            foreach($history as $h) { 
                if((string)$h['examId'] === (string)$input['examId'] && (string)$h['startTime'] === (string)$input['startTime']) {
                    continue; 
                }
                $new[] = $h; 
            }
            safe_file_put_contents($historyFile, json_encode($new));
        }
        echo json_encode(array('success' => true)); exit;
    }

    if ($action === 'get_tex') {
        $id = $_GET['id'];
        $exams = json_decode(safe_file_get_contents($dataFile), true);
        if(is_array($exams)) {
            foreach($exams as $e) {
                if ($e['id'] == $id) {
                    $localPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $e['filePath']);
                    if (file_exists($localPath)) echo json_encode(array('success' => true, 'content' => safe_file_get_contents($localPath)));
                    else echo json_encode(array('success' => false, 'message' => 'File không tồn tại'));
                    exit;
                }
            }
        }
        echo json_encode(array('success' => false, 'message' => 'Không tìm thấy đề')); exit;
    }

    if ($action === 'save_tex' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id']; $content = $input['content'];

        $exams = json_decode(safe_file_get_contents($dataFile), true);
        if(is_array($exams)) {
            foreach($exams as &$e) {
                if ($e['id'] == $id) {
                    $localPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $e['filePath']);
                    safe_file_put_contents($localPath, $content);
                    
                    $parsed = parseTexContent($content, $uploadDir, $uploadFolderName);
                    $e['questions'] = $parsed['questions'];
                    $e['correctAnswers'] = $parsed['correct'];
                    $e['config'] = array('p1' => count($parsed['questions']['p1']), 'p2' => count($parsed['questions']['p2']), 'p3' => count($parsed['questions']['p3']));
                    
                    safe_file_put_contents($dataFile, json_encode($exams));
                    echo json_encode(array('success' => true)); exit;
                }
            }
        }
        echo json_encode(array('success' => false)); exit;
    }

    if ($action === 'save_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $exams = json_decode(safe_file_get_contents($dataFile), true);
        if(is_array($exams)) {
            foreach($exams as &$e) {
                if ($e['id'] == $input['id']) {
                    $e['title'] = $input['title'];
                    $e['duration'] = (int)$input['duration'];
                    $e['maxViolations'] = (int)$input['maxViolations'];
                    $e['maxAttempts'] = (int)$input['maxAttempts'];
                    $e['allowReview'] = isset($input['allowReview']) ? $input['allowReview'] : true;
                    $e['examMode'] = isset($input['examMode']) ? $input['examMode'] : 'normal';
                    $e['grade'] = $input['grade'];
                    $e['category'] = $input['category'];
                    $e['assignedClass'] = isset($input['assignedClass']) ? trim($input['assignedClass']) : '';
                    
                    safe_file_put_contents($dataFile, json_encode($exams));
                    echo json_encode(array('success' => true)); exit;
                }
            }
        }
        echo json_encode(array('success' => false)); exit;
    }

    if ($action === 'save_scoring' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $exams = json_decode(safe_file_get_contents($dataFile), true);
        if(is_array($exams)) {
            foreach($exams as &$e) {
                if ($e['id'] == $input['id']) {
                    $e['scoring'] = $input['scoring'];
                    safe_file_put_contents($dataFile, json_encode($exams));
                    echo json_encode(array('success' => true)); exit;
                }
            }
        }
        echo json_encode(array('success' => false)); exit;
    }

    if ($action === 'save_schedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $exams = json_decode(safe_file_get_contents($dataFile), true);
        if(is_array($exams)) {
            foreach($exams as &$e) {
                if ($e['id'] == $input['id']) {
                    $e['publishStartTime'] = $input['publishStartTime'];
                    $e['publishEndTime'] = $input['publishEndTime'];
                    $e['isPublished'] = true; 
                    safe_file_put_contents($dataFile, json_encode($exams));
                    echo json_encode(array('success' => true)); exit;
                }
            }
        }
        echo json_encode(array('success' => false)); exit;
    }

    if ($action === 'toggle_publish' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $exams = json_decode(safe_file_get_contents($dataFile), true);
        if(is_array($exams)) {
            foreach($exams as &$e) {
                if ($e['id'] == $input['id']) {
                    $e['isPublished'] = isset($input['forceState']) ? $input['forceState'] : (isset($e['isPublished']) ? !$e['isPublished'] : true);
                    safe_file_put_contents($dataFile, json_encode($exams));
                    echo json_encode(array('success' => true)); exit;
                }
            }
        }
        echo json_encode(array('success' => false)); exit;
    }

    if ($action === 'toggle_publish_grades' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $exams = json_decode(safe_file_get_contents($dataFile), true);
        if(is_array($exams)) {
            foreach($exams as &$e) {
                if ($e['id'] == $input['id']) {
                    $e['publishGrades'] = isset($e['publishGrades']) ? !$e['publishGrades'] : true;
                    safe_file_put_contents($dataFile, json_encode($exams));
                    echo json_encode(array('success' => true)); exit;
                }
            }
        }
        echo json_encode(array('success' => false)); exit;
    }

    if ($action === 'toggle_publish_answers' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $exams = json_decode(safe_file_get_contents($dataFile), true);
        if(is_array($exams)) {
            foreach($exams as &$e) {
                if ($e['id'] == $input['id']) {
                    $e['publishAnswers'] = isset($e['publishAnswers']) ? !$e['publishAnswers'] : true;
                    safe_file_put_contents($dataFile, json_encode($exams));
                    echo json_encode(array('success' => true)); exit;
                }
            }
        }
        echo json_encode(array('success' => false)); exit;
    }

    if ($action === 'save_publish_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? '';
        
        $exams = json_decode(safe_file_get_contents($dataFile), true);
        if(is_array($exams)) {
            foreach($exams as &$e) {
                if ($e['id'] == $id) {
                    $e['isPublished'] = !empty($input['isPublished']);
                    $e['publishGrades'] = !empty($input['publishGrades']);
                    $e['publishAnswers'] = !empty($input['publishAnswers']);
                    
                    safe_file_put_contents($dataFile, json_encode($exams));
                    echo json_encode(array('success' => true)); exit;
                }
            }
        }
        echo json_encode(array('success' => false)); exit;
    }

    if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $file = $_FILES['file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $id = time(); $fileName = $id . '.' . $ext;
        
        if (move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
            $exams = json_decode(safe_file_get_contents($dataFile), true);
            if(!is_array($exams)) $exams = array();
            
            $text = safe_file_get_contents($uploadDir . $fileName);
            $parsed = parseTexContent($text, $uploadDir, $uploadFolderName);

            $exams[] = array(
                'id' => (string)$id,
                'title' => $_POST['title'],
                'grade' => isset($_POST['grade']) ? $_POST['grade'] : 'tot_nghiep', 
                'category' => $_POST['category'],
                'type' => $ext,
                'filePath' => $uploadFolderName . '/' . $fileName,
                'questions' => $parsed['questions'],
                'correctAnswers' => $parsed['correct'],
                'config' => array('p1' => count($parsed['questions']['p1']), 'p2' => count($parsed['questions']['p2']), 'p3' => count($parsed['questions']['p3'])),
                'duration' => (int)$_POST['duration'] ?: 90,
                'maxViolations' => isset($_POST['maxViolations']) ? (int)$_POST['maxViolations'] : 2,
                'maxAttempts' => isset($_POST['maxAttempts']) ? (int)$_POST['maxAttempts'] : 0,
                'examMode' => isset($_POST['examMode']) ? $_POST['examMode'] : 'normal',
                'allowReview' => isset($_POST['allowReview']) ? ($_POST['allowReview'] === 'true') : true,
                'publishStartTime' => '',
                'publishEndTime' => '',
                'isPublished' => false,
                'isDeleted' => false,
                'createdAt' => date('d/m/Y H:i'),
                'visible' => true,
                'assignedClass' => isset($_POST['assignedClass']) ? trim($_POST['assignedClass']) : '',
                'requireLoginList' => false, 
                'allowedStudents' => [],
                'customStudents' => [],
                'selectedClasses' => []
            );
            
            safe_file_put_contents($dataFile, json_encode($exams));
            echo json_encode(array('success' => true));
        }
        exit;
    }
    
    if ($action === 'delete') {
        $exams = json_decode(safe_file_get_contents($dataFile), true);
        if(is_array($exams)) {
            foreach($exams as &$e) { 
                if($e['id'] == $_GET['id']) {
                    $e['isDeleted'] = true; 
                    $e['isPublished'] = false; 
                }
            }
            safe_file_put_contents($dataFile, json_encode($exams));
        }
        echo json_encode(array('success' => true)); exit;
    }

    if ($action === 'restore') {
        $exams = json_decode(safe_file_get_contents($dataFile), true);
        if(is_array($exams)) {
            foreach($exams as &$e) { 
                if($e['id'] == $_GET['id']) {
                    $e['isDeleted'] = false; 
                }
            }
            safe_file_put_contents($dataFile, json_encode($exams));
        }
        echo json_encode(array('success' => true)); exit;
    }

    if ($action === 'hard_delete') {
        $exams = json_decode(safe_file_get_contents($dataFile), true);
        if(is_array($exams)) {
            $new = array();
            foreach($exams as $e) { 
                if($e['id'] != $_GET['id']) {
                    $new[] = $e; 
                } else {
                    $localPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $e['filePath']);
                    if (file_exists($localPath)) @unlink($localPath);
                }
            }
            safe_file_put_contents($dataFile, json_encode($new));
        }
        echo json_encode(array('success' => true)); exit;
    }

    if ($action === 'download_tex') {
        $id = $_GET['id'];
        $exams = json_decode(safe_file_get_contents($dataFile), true);
        if(is_array($exams)) {
            foreach($exams as $e) {
                if ($e['id'] == $id) {
                    $localPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $e['filePath']);
                    if (file_exists($localPath)) {
                        header('Content-Description: File Transfer');
                        header('Content-Type: application/x-tex');
                        header('Content-Disposition: attachment; filename="de_thi_' . $id . '.tex"');
                        header('Expires: 0');
                        header('Cache-Control: must-revalidate');
                        header('Pragma: public');
                        header('Content-Length: ' . filesize($localPath));
                        readfile($localPath);
                        exit;
                    }
                }
            }
        }
        header("HTTP/1.0 404 Not Found");
        echo "File không tồn tại.";
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Quản Trị Hệ Thống - tMath</title>

    <!-- Hệ thống phát hiện & hiển thị lỗi JS trực tiếp trên màn hình -->
    <script>
        window.onerror = function(message, source, lineno, colno, error) {
            // Ignore generic external script errors, adblocker errors, or hosting injection errors
            if (message === 'Script error.' && !source) {
                return false; 
            }
            if (source && !source.includes('admin.php') && !source.includes('localhost')) {
                return false;
            }
            var div = document.getElementById('debug-error-overlay');
            if (!div) {
                div = document.createElement('div');
                div.id = 'debug-error-overlay';
                div.style.position = 'fixed';
                div.style.top = '10px';
                div.style.left = '10px';
                div.style.right = '10px';
                div.style.background = '#ffebee';
                div.style.color = '#c62828';
                div.style.border = '2px solid #ef5350';
                div.style.padding = '15px';
                div.style.borderRadius = '8px';
                div.style.zIndex = '999999';
                div.style.fontFamily = 'monospace';
                div.style.fontSize = '14px';
                div.style.whiteSpace = 'pre-wrap';
                div.style.boxShadow = '0 4px 6px rgba(0,0,0,0.1)';
                document.body.appendChild(div);
            }
            div.innerHTML = '<b>[Lỗi JavaScript]</b>\nTin nhắn: ' + message + '\nTệp tin: ' + source + '\nDòng: ' + lineno + ' | Cột: ' + colno + (error ? '\nStack: ' + error.stack : '');
            return false;
        };
        window.addEventListener('unhandledrejection', function(event) {
            var div = document.getElementById('debug-error-overlay');
            if (!div) {
                div = document.createElement('div');
                div.id = 'debug-error-overlay';
                div.style.position = 'fixed';
                div.style.top = '10px';
                div.style.left = '10px';
                div.style.right = '10px';
                div.style.background = '#fff3e0';
                div.style.color = '#ef6c00';
                div.style.border = '2px solid #ffb74d';
                div.style.padding = '15px';
                div.style.borderRadius = '8px';
                div.style.zIndex = '999999';
                div.style.fontFamily = 'monospace';
                div.style.fontSize = '14px';
                div.style.whiteSpace = 'pre-wrap';
                div.style.boxShadow = '0 4px 6px rgba(0,0,0,0.1)';
                document.body.appendChild(div);
            }
            div.innerHTML = '<b>[Promise Bị Từ Chối]</b>\nLý do: ' + event.reason;
        });
    </script>

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/react@18.3.1/umd/react.production.min.js" crossorigin></script>
    <script src="https://cdn.jsdelivr.net/npm/react-dom@18.3.1/umd/react-dom.production.min.js" crossorigin></script>

    <script src="https://cdn.jsdelivr.net/npm/lucide@0.395.0/dist/umd/lucide.min.js" crossorigin></script>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/addon/dialog/dialog.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/mode/stex/stex.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/addon/search/searchcursor.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/addon/search/search.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/addon/dialog/dialog.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/addon/edit/matchbrackets.min.js"></script>

    <script>
        window.MathJax = {
            tex: { 
                inlineMath: [['$', '$'], ['\\(', '\\)']], 
                displayMath: [['$$', '$$']],
                macros: { 
                    hoac: ["\\left[ \\begin{array}{l} #1 \\end{array} \\right.", 1], 
                    heva: ["\\left\\{ \\begin{array}{l} #1 \\end{array} \\right.", 1], 
                    True: "\\text{True}", 
                    False: "\\text{False}",
                    vv: ["\\overrightarrow{#1}", 1],
                    vec: ["\\overrightarrow{#1}", 1],
                    vt: ["\\overrightarrow{#1}", 1]
                }
            },
            chtml: { matchFontHeight: false },
            startup: {
                ready: () => {
                    MathJax.startup.defaultReady();
                    MathJax.startup.document.inputJax[0].preFilters.add(({math}) => {
                        if (math.display === false) math.math = "\\displaystyle " + math.math;
                    });
                }
            },
            options: { skipHtmlTags: ['script', 'noscript', 'style', 'textarea', 'pre'] }
        };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap');
        html, body, #root { background-color: #fafaf9 !important; background: radial-gradient(circle at 50% 120%, #f5f3ff 0%, #fafaf9 100%) !important; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        
        .tex2jax_process { line-height: 1.8; font-size: 16px; overflow: visible !important; padding: 4px 0;}
        mjx-container { overflow: visible !important; }
        .tex2jax_process svg { max-width: 100%; height: auto; }
        
        /* CSS cho môi trường bảng LaTeX/MathJax trong đề thi */
        .table-wrapper mjx-mtable {
            border-collapse: collapse !important;
            border: 1.5px solid #94a3b8 !important;
            background-color: #ffffff !important;
        }
        .table-wrapper mjx-mtd {
            border: 1px solid #cbd5e1 !important;
            padding: 10px 16px !important;
            text-align: center !important;
            min-width: 70px;
        }
        .table-wrapper mjx-mtr:nth-child(even) mjx-mtd {
            background-color: #f8fafc !important;
        }
        .table-wrapper mjx-mtr:first-child mjx-mtd {
            background-color: #f1f5f9 !important;
            font-weight: bold !important;
            color: #0f172a !important;
        }
        
        pre { white-space: pre-wrap; word-wrap: break-word; }
        
        @keyframes ping-large {
            75%, 100% { transform: scale(2); opacity: 0; }
        }
        .animate-ping-large { animation: ping-large 1.5s cubic-bezier(0, 0, 0.2, 1) infinite; }

        @keyframes shimmer {
            100% { transform: translateX(100%); }
        }

        .CodeMirror { 
            height: 100% !important; 
            font-family: 'Consolas', 'Monaco', 'Courier New', monospace; 
            font-size: 15px; 
            line-height: 1.6; 
        }
        .cm-s-default.CodeMirror { background: #ffffff; color: #000000; }
        .cm-s-default .cm-builtin { color: #0000FF !important; font-weight: bold; } 
        .cm-s-default .cm-keyword { color: #800000 !important; } 
        .cm-s-default .cm-atom { color: #008000 !important; } 
        .cm-s-default .cm-bracket { color: #000000 !important; font-weight: bold; }
        .cm-s-default .cm-comment { color: #808080 !important; font-style: italic; }
        .cm-s-default .cm-string { color: #008000 !important; }
        .CodeMirror-dialog { background: #f8fafc; border-bottom: 1px solid #cbd5e1; padding: 10px 15px; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 13px; font-weight: bold; }
        .CodeMirror-dialog input { border: 1px solid #cbd5e1; border-radius: 6px; padding: 4px 10px; outline: none; margin: 0 5px; }
        .CodeMirror-dialog input:focus { border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37,99,235,0.2); }
        .CodeMirror-dialog button { cursor: pointer; margin-left: 5px; padding: 4px 10px; background: #2563eb; color: white; border: none; border-radius: 6px; }
    </style>
</head>
<body>
    <div id="root"></div>
    <script id="react-app-source" type="text/plain">
        const { useState, useEffect, useRef } = React;

        const removeVietnameseTones = (str) => {
            if (!str) return "";
            str = str.replace(/à|á|ạ|ả|ã|â|ầ|ấ|ậ|ẩ|ẫ|ă|ằ|ắ|ặ|ẳ|ẵ/g, "a");
            str = str.replace(/è|é|ẹ|ẻ|ẽ|ê|ề|ế|ệ|ể|ễ/g, "e");
            str = str.replace(/ì|í|ị|ỉ|ĩ/g, "i");
            str = str.replace(/ò|ó|ọ|ỏ|õ|ô|ồ|ố|ộ|ổ|ỗ|ơ|ờ|ớ|ợ|ở|ỡ/g, "o");
            str = str.replace(/ù|ú|ụ|ủ|ũ|ư|ừ|ứ|ự|ử|ữ/g, "u");
            str = str.replace(/ỳ|ý|ỵ|ỷ|ỹ/g, "y");
            str = str.replace(/đ/g, "d");
            return str;
        };

        const generatePassword = (lastName, firstName) => {
            let fullName = `${lastName || ''} ${firstName || ''}`.trim();
            if (!fullName) return '';
            fullName = removeVietnameseTones(fullName.toLowerCase());
            fullName = fullName.replace(/[^a-z\s]/g, '');
            const parts = fullName.split(/\s+/).filter(Boolean);
            const initials = parts.map(p => p.charAt(0)).join('');
            return initials + '123@';
        };

        const downloadExcelTemplate = () => {
            try {
                const XLSX = window.XLSX;
                const headers = ["Lớp", "Họ và đệm", "Tên"];
                const sampleData = [
                    headers,
                    ["12A1", "Nguyễn Văn", "An"],
                    ["12A1", "Trần Thị", "Bình"],
                    ["12A2", "Phạm Minh", "Tuấn"]
                ];
                const ws = XLSX.utils.aoa_to_sheet(sampleData);
                ws['!cols'] = [
                    { wch: 12 }, // Lớp
                    { wch: 22 }, // Họ và đệm
                    { wch: 12 }  // Tên
                ];
                const wb = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(wb, ws, "Danh sách học sinh");
                XLSX.writeFile(wb, "mau_danh_sach_hoc_sinh.xlsx");
            } catch (err) {
                alert("Lỗi xuất file Excel mẫu: " + err.message);
            }
        };

        const LatexCodeEditor = ({ value, onChange }) => {
            const editorRef = useRef(null);
            const cmRef = useRef(null);

            useEffect(() => {
                if (editorRef.current && !cmRef.current) {
                    cmRef.current = window.CodeMirror.fromTextArea(editorRef.current, {
                        mode: "stex",
                        lineNumbers: true,
                        lineWrapping: true,
                        matchBrackets: true,
                        theme: "default",
                        extraKeys: {
                            "Ctrl-F": "findPersistent", 
                            "Cmd-F": "findPersistent", 
                            "Ctrl-H": "replace",
                            "Cmd-Option-F": "replace"
                        },
                        phrases: {
                            "Search:": "🔎 Tìm kiếm:",
                            "(Use /re/ syntax for regexp search)": "", 
                            "Replace:": "🔄 Tìm từ cần thay:",
                            "With:": "✍️ Thay thế thành:",
                            "Yes": "✅ Có (Y)",
                            "No": "❌ Không (N)",
                            "All": "🔄 Tất cả (A)",
                            "Stop": "🛑 Dừng (S)",
                            "Replace?": "Xác nhận thay thế?"
                        }
                    });
                    cmRef.current.on('change', (instance) => {
                        onChange(instance.getValue());
                    });
                    cmRef.current.setValue(value || "");
                }
            }, []);

            useEffect(() => {
                if (cmRef.current && value !== cmRef.current.getValue()) {
                    cmRef.current.setValue(value || "");
                }
            }, [value]);

            return (
                <div className="absolute inset-0 w-full h-full text-left bg-white">
                    <textarea ref={editorRef} style={{display: 'none'}} />
                    <div className="absolute top-2 right-4 z-10 flex gap-2 text-[11px] bg-white/95 px-3 py-1.5 rounded-lg shadow-sm border border-slate-200 text-slate-600 font-bold backdrop-blur-md items-center">
                        <button onClick={() => cmRef.current?.execCommand('findPersistent')} className="bg-blue-50 text-blue-600 hover:bg-blue-500 hover:text-white px-2 py-1 rounded flex items-center gap-1.5 transition-colors">
                            <Icon name="search" size={12}/> Tìm kiếm
                        </button>
                        <button onClick={() => cmRef.current?.execCommand('replace')} className="bg-rose-50 text-rose-600 hover:bg-rose-500 hover:text-white px-2 py-1 rounded flex items-center gap-1.5 transition-colors">
                            <Icon name="refresh-cw" size={12}/> Thay thế
                        </button>
                        <span className="text-slate-300 mx-1 hidden sm:inline">|</span>
                        <span className="hidden sm:inline-block">Phím tắt: <kbd className="bg-slate-100 border border-slate-300 rounded px-1">Ctrl</kbd> + <kbd className="bg-slate-100 border border-slate-300 rounded px-1">F</kbd> / <kbd className="bg-slate-100 border border-slate-300 rounded px-1">H</kbd></span>
                    </div>
                </div>
            );
        };

        const CATEGORY_TREE = {
            'on_thi_tn': {
                label: 'Ôn thi TN',
                items: { 'chinh_thuc': 'Đề chính thức', 'thi_thu': 'Đề thi thử', 'truong_sgd': 'Đề Sở / Trường' }
            },
            'on_giua_ki': {
                label: 'Ôn tập giữa kì',
                items: { 'ghk1': 'Ôn Giữa HK1', 'ghk2': 'Ôn Giữa HK2' }
            },
            'on_hoc_ki': {
                label: 'Ôn tập học kì',
                items: { 'hk1': 'Ôn HK1', 'hk2': 'Ôn HK2' }
            }
        };

        const Icon = ({ name, size=18, strokeWidth, className="" }) => {
            const ref = useRef(null);
            useEffect(() => { 
                if (window.lucide) {
                    const attrs = { width: size, height: size };
                    if (strokeWidth) attrs['stroke-width'] = strokeWidth;
                    window.lucide.createIcons({ root: ref.current, attrs }); 
                }
            }, [name, size, strokeWidth]);
            return <span ref={ref} className={`inline-flex items-center justify-center ${className}`}><i data-lucide={name}></i></span>;
        };

        const CustomDialog = ({ dialog, onClose }) => {
            if (!dialog.isOpen) return null;
            return (
                <div className="fixed inset-0 z-[100] flex items-center justify-center p-4">
                    <div className="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onClick={dialog.type === 'alert' ? () => { dialog.onConfirm(); onClose(); } : undefined}></div>
                    <div className="relative bg-white rounded-[2rem] shadow-2xl w-full max-w-sm overflow-hidden animate-in zoom-in-95 duration-200 border-2 border-white/20">
                        <div className="p-6 text-center">
                            <div className={`w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-5 shadow-xl ${
                                dialog.type === 'confirm' ? 'bg-gradient-to-br from-amber-400 to-orange-500 text-white animate-bounce' : 
                                dialog.type === 'danger' ? 'bg-gradient-to-br from-rose-500 to-red-600 text-white animate-pulse' :
                                'bg-gradient-to-br from-blue-400 to-blue-600 text-white'
                            }`}>
                                <Icon name={dialog.type === 'danger' ? "alert-triangle" : dialog.type === 'confirm' ? "help-circle" : "info"} size={40} strokeWidth={2.5} />
                            </div>
                            <h3 className={`text-xl font-black mb-2 ${dialog.type === 'confirm' ? 'text-orange-600' : dialog.type === 'danger' ? 'text-rose-600' : 'text-slate-800'}`}>
                                {dialog.type === 'confirm' || dialog.type === 'danger' ? 'XÁC NHẬN' : 'THÔNG BÁO'}
                            </h3>
                            <p className="text-[15px] font-bold text-slate-600 leading-relaxed whitespace-pre-wrap">{dialog.message}</p>
                        </div>
                        <div className="p-4 bg-slate-50 flex gap-3 border-t border-slate-100">
                            {(dialog.type === 'confirm' || dialog.type === 'danger') && (
                                <button onClick={onClose} className="flex-1 py-3.5 rounded-xl font-bold text-sm text-slate-500 bg-white border border-slate-200 hover:bg-slate-100 transition-colors shadow-sm">Hủy Bỏ</button>
                            )}
                            <button onClick={() => { dialog.onConfirm(); onClose(); }} className={`flex-1 py-3.5 rounded-xl font-black text-sm text-white shadow-lg transition-transform hover:-translate-y-1 ${
                                dialog.type === 'confirm' ? 'bg-gradient-to-r from-orange-500 to-amber-500 shadow-orange-500/40 hover:from-orange-600 hover:to-amber-600' : 
                                dialog.type === 'danger' ? 'bg-gradient-to-r from-rose-600 to-red-600 shadow-rose-500/40 hover:from-rose-700 hover:to-red-700' : 
                                'bg-gradient-to-r from-blue-500 to-blue-700 shadow-blue-500/30 hover:from-blue-600 hover:to-blue-800'
                            }`}>
                                {dialog.type === 'confirm' || dialog.type === 'danger' ? 'Đồng Ý' : 'Đã Hiểu'}
                            </button>
                        </div>
                    </div>
                </div>
            );
        };

        const ClassManagementModal = ({ onClose, loadExams, showAlert, showDangerConfirm, showConfirm, globalClasses, setGlobalClasses, openGradebookForClass }) => {
            const [newClassName, setNewClassName] = useState('');
            const [newStudents, setNewStudents] = useState([]);
            const [isSaving, setIsSaving] = useState(false);
            const [editingClass, setEditingClass] = useState(null);
            const [editNewStudent, setEditNewStudent] = useState({ lastName: '', firstName: '' });

            const handleFileUpload = (e) => {
                const file = e.target.files[0];
                if(!file) return;
                const reader = new FileReader();
                reader.onload = (evt) => {
                    try {
                        const bstr = evt.target.result;
                        const wb = window.XLSX.read(bstr, {type:'binary'});
                        const wsname = wb.SheetNames[0];
                        const ws = wb.Sheets[wsname];
                        const data = window.XLSX.utils.sheet_to_json(ws, {header: 1});
                        
                        const list = [];
                        for(let i = 1; i < data.length; i++) {
                            const row = data[i];
                            if(row && row.length >= 3 && (row[1] || row[2])) {
                                list.push({
                                    class: String(row[0]||'').trim(),
                                    lastName: String(row[1]||'').trim(),
                                    firstName: String(row[2]||'').trim()
                                });
                            }
                        }
                        
                        // Hỏi xem có cấp mật khẩu hay không
                        showConfirm(
                            `Đã đọc ${list.length} học sinh. Bạn có muốn tự động cấp mật khẩu cho học sinh không?\n(Mật khẩu sẽ được tạo dạng chữ viết tắt tên ghép 123@, ví dụ: Phạm Minh Tuấn -> pmt123@)`,
                            () => {
                                const listWithPass = list.map(s => ({
                                    ...s,
                                    password: generatePassword(s.lastName, s.firstName)
                                }));
                                setNewStudents(listWithPass);
                                showAlert(`Đã tải lên ${listWithPass.length} học sinh và tạo mật khẩu thành công!`);
                            },
                            () => {
                                setNewStudents(list);
                                showAlert(`Đã tải lên ${list.length} học sinh (không dùng mật khẩu)!`);
                            }
                        );
                    } catch(err) {
                        showAlert("Lỗi đọc file Excel. Vui lòng kiểm tra lại định dạng.");
                    }
                };
                reader.readAsBinaryString(file);
                e.target.value = null; 
            };

            const handleSaveClass = async (e) => {
                e.preventDefault();
                if (!newClassName.trim()) return showAlert("Vui lòng nhập tên lớp!");
                if (newStudents.length === 0) return showAlert("Vui lòng tải lên danh sách học sinh (Excel)!");

                setIsSaving(true);
                const payload = {
                    id: 'class_' + Date.now(),
                    className: newClassName.trim().toUpperCase(),
                    students: newStudents
                };

                const r = await fetch('?action=save_class', { method: 'POST', body: JSON.stringify(payload) });
                const res = await r.json();
                setIsSaving(false);

                if (res.success) {
                    showAlert("Tạo lớp thành công!");
                    setNewClassName('');
                    setNewStudents([]);
                    fetch('?action=get_classes&t=' + Date.now()).then(r=>r.json()).then(setGlobalClasses);
                } else {
                    showAlert("Lỗi khi tạo lớp!");
                }
            };

            const handleDeleteClass = (cls) => {
                showDangerConfirm(`Bạn có chắc chắn muốn xóa lớp ${cls.className}?\nDanh sách này sẽ không thể khôi phục!`, async () => {
                    const r = await fetch('?action=delete_class', { method: 'POST', body: JSON.stringify({ id: cls.id }) });
                    const res = await r.json();
                    if (res.success) {
                        fetch('?action=get_classes&t=' + Date.now()).then(r=>r.json()).then(setGlobalClasses);
                    }
                });
            };

            const handleSaveEditedClass = async () => {
                setIsSaving(true);
                const r = await fetch('?action=save_class', { method: 'POST', body: JSON.stringify(editingClass) });
                const res = await r.json();
                setIsSaving(false);
                if (res.success) {
                    showAlert("Cập nhật lớp thành công!");
                    setEditingClass(null);
                    fetch('?action=get_classes&t=' + Date.now()).then(r=>r.json()).then(setGlobalClasses);
                } else {
                    showAlert("Lỗi cập nhật!");
                }
            };

            return (
                <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
                    <div className="bg-white w-full max-w-4xl h-[85vh] rounded-[2rem] shadow-2xl flex flex-col overflow-hidden animate-in zoom-in duration-300">
                        <header className="p-5 border-b flex justify-between items-center bg-slate-50 shrink-0">
                            <div className="flex items-center gap-3">
                                <div className="p-2 bg-indigo-100 text-indigo-600 rounded-lg"><Icon name="library" size={18}/></div>
                                <div>
                                    <h2 className="text-base font-black uppercase tracking-widest text-slate-800">Quản lý lớp học</h2>
                                    <p className="text-[10px] font-bold text-slate-400 mt-0.5">Tạo và lưu trữ danh sách lớp để dùng chung</p>
                                </div>
                            </div>
                            <button type="button" onClick={onClose} className="w-8 h-8 flex items-center justify-center bg-rose-500 text-white hover:bg-rose-600 rounded-full transition-colors shadow-md"><Icon name="x" size={18}/></button>
                        </header>
                        
                        {editingClass ? (
                            <div className="flex-1 flex flex-col overflow-hidden">
                                <div className="p-5 border-b bg-indigo-50/50 flex justify-between items-center shrink-0">
                                    <h3 className="font-black text-indigo-700 text-sm md:text-lg uppercase flex items-center gap-2">
                                        <Icon name="edit-3" size={18}/> Chỉnh sửa lớp: <span className="bg-white px-2 py-1 rounded-md shadow-sm border border-indigo-100">{editingClass.className}</span>
                                    </h3>
                                    <span className="font-bold text-slate-500 text-xs md:text-sm bg-white px-3 py-1.5 rounded-full border border-slate-200">Sĩ số: {editingClass.students.length} HS</span>
                                </div>
                                <div className="flex-1 overflow-y-auto p-4 md:p-5 custom-scrollbar bg-slate-50">
                                    <div className="flex flex-wrap gap-2 items-end bg-white p-4 rounded-xl border border-slate-200 mb-5 shadow-sm">
                                        <div className="flex-[2] min-w-[120px]">
                                            <label className="text-[10px] font-black uppercase text-slate-500 mb-1 block pl-1">Họ và đệm</label>
                                            <input value={editNewStudent.lastName} onChange={e=>setEditNewStudent({...editNewStudent, lastName: e.target.value})} className="w-full p-2 text-sm rounded border border-slate-300 font-bold outline-none focus:border-indigo-500" placeholder="VD: Nguyễn Văn"/>
                                        </div>
                                        <div className="flex-[2] min-w-[100px]">
                                            <label className="text-[10px] font-black uppercase text-slate-500 mb-1 block pl-1">Tên</label>
                                            <input value={editNewStudent.firstName} onChange={e=>setEditNewStudent({...editNewStudent, firstName: e.target.value})} onKeyDown={e=>{
                                                if(e.key === 'Enter') {
                                                    if(!editNewStudent.firstName || !editNewStudent.lastName) return showAlert("Vui lòng nhập đủ Họ và Tên!");
                                                    const hasPasswordMode = (editingClass.students || []).some(s => s.password && s.password.trim() !== '');
                                                    const newStu = {
                                                        class: editingClass.className,
                                                        lastName: editNewStudent.lastName,
                                                        firstName: editNewStudent.firstName,
                                                        ...(hasPasswordMode ? { password: generatePassword(editNewStudent.lastName, editNewStudent.firstName) } : {})
                                                    };
                                                    setEditingClass({...editingClass, students: [newStu, ...editingClass.students]});
                                                    setEditNewStudent({lastName:'', firstName:''});
                                                }
                                            }} className="w-full p-2 text-sm rounded border border-slate-300 font-bold outline-none focus:border-indigo-500" placeholder="VD: An"/>
                                        </div>
                                        <button onClick={() => {
                                            if(!editNewStudent.firstName || !editNewStudent.lastName) return showAlert("Vui lòng nhập đủ Họ và Tên!");
                                            const hasPasswordMode = (editingClass.students || []).some(s => s.password && s.password.trim() !== '');
                                            const newStu = {
                                                class: editingClass.className,
                                                lastName: editNewStudent.lastName,
                                                firstName: editNewStudent.firstName,
                                                ...(hasPasswordMode ? { password: generatePassword(editNewStudent.lastName, editNewStudent.firstName) } : {})
                                            };
                                            setEditingClass({...editingClass, students: [newStu, ...editingClass.students]});
                                            setEditNewStudent({lastName:'', firstName:''});
                                        }} className="bg-indigo-600 text-white px-4 py-2 rounded font-black text-xs uppercase hover:bg-indigo-500 transition-colors h-[38px] shadow-sm flex items-center gap-1"><Icon name="plus" size={14}/> Thêm HS</button>
                                    </div>

                                    <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
                                        {editingClass.students.map((s, i) => (
                                            <div key={i} className="flex justify-between items-center p-3 border-b border-slate-100 hover:bg-slate-50">
                                                <div className="flex items-center gap-3">
                                                    <span className="text-xs font-bold text-slate-400 w-6 text-center">{i+1}</span>
                                                    <span className="font-bold text-slate-700 text-sm">{s.lastName} {s.firstName}</span>
                                                    {s.password && <span className="ml-3 font-mono text-[10px] text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded-full font-bold">MK: {s.password}</span>}
                                                </div>
                                                <button onClick={() => {
                                                    const newStudents = [...editingClass.students];
                                                    newStudents.splice(i, 1);
                                                    setEditingClass({...editingClass, students: newStudents});
                                                }} className="w-8 h-8 flex items-center justify-center bg-rose-50 text-rose-500 rounded hover:bg-rose-500 hover:text-white transition-colors" title="Xóa HS">
                                                    <Icon name="trash-2" size={14}/>
                                                </button>
                                            </div>
                                        ))}
                                        {editingClass.students.length === 0 && <div className="p-8 text-center text-slate-400 font-bold text-sm">Lớp trống</div>}
                                    </div>
                                </div>
                                <div className="p-5 border-t flex justify-end gap-3 bg-white shrink-0">
                                    <button type="button" onClick={() => setEditingClass(null)} className="px-6 py-2.5 font-bold text-xs uppercase tracking-widest text-slate-500 hover:text-slate-800 transition-colors bg-slate-100 hover:bg-slate-200 rounded-xl">Quay Lại</button>
                                    <button onClick={handleSaveEditedClass} disabled={isSaving} className="bg-indigo-600 text-white px-8 py-2.5 rounded-xl font-black uppercase tracking-widest shadow-md hover:bg-indigo-500 transition-colors flex items-center gap-2 text-xs">
                                        {isSaving ? <Icon name="loader" className="animate-spin" size={14}/> : <Icon name="save" size={14}/>} LƯU CẬP NHẬT
                                    </button>
                                </div>
                            </div>
                        ) : (
                            <div className="flex-1 overflow-hidden flex flex-col md:flex-row">
                                <div className="w-full md:w-1/3 bg-slate-50 p-5 border-r border-slate-200 flex flex-col shrink-0 overflow-y-auto">
                                    <h3 className="font-black text-sm text-indigo-700 uppercase tracking-widest mb-4 flex items-center gap-2"><Icon name="plus-circle" size={16}/> Tạo Lớp Mới</h3>
                                    
                                    <form onSubmit={handleSaveClass} className="flex flex-col gap-4">
                                        <div>
                                            <label className="block text-[11px] font-bold text-slate-500 uppercase mb-1.5 ml-1">Tên Lớp</label>
                                            <input value={newClassName} onChange={e=>setNewClassName(e.target.value)} placeholder="VD: 12A1" className="w-full bg-white border border-slate-300 p-3 rounded-xl font-bold text-slate-700 outline-none focus:ring-2 focus:ring-indigo-500 text-sm uppercase" required />
                                        </div>
                                        
                                        <div>
                                            <label className="block text-[11px] font-bold text-slate-500 uppercase mb-1.5 ml-1">Danh sách học sinh (Excel)</label>
                                            <label className={`w-full h-24 border-2 border-dashed rounded-xl flex flex-col items-center justify-center gap-1 cursor-pointer transition-colors ${newStudents.length > 0 ? 'border-emerald-400 bg-emerald-50 text-emerald-600' : 'border-slate-300 bg-white text-slate-500 hover:border-indigo-400 hover:bg-indigo-50'}`}>
                                                <Icon name={newStudents.length > 0 ? "check-circle" : "upload-cloud"} size={24}/>
                                                <span className="text-xs font-bold">{newStudents.length > 0 ? `Đã tải ${newStudents.length} HS` : 'Tải File .XLSX'}</span>
                                                <input type="file" accept=".xlsx, .xls, .csv" className="hidden" onChange={handleFileUpload} />
                                            </label>
                                        </div>

                                        <button type="submit" disabled={isSaving || newStudents.length === 0 || !newClassName} className="mt-2 bg-indigo-600 text-white p-3 rounded-xl font-black uppercase tracking-widest shadow-md hover:bg-indigo-500 transition-colors disabled:opacity-50 flex items-center justify-center gap-2 text-xs">
                                            {isSaving ? <Icon name="loader" className="animate-spin" size={14}/> : <Icon name="save" size={14}/>} 
                                            LƯU LỚP
                                        </button>
                                    </form>

                                    {/* HỘP HƯỚNG DẪN CẤU TRÚC EXCEL */}
                                    <div className="mt-6 bg-blue-50 border border-blue-200 rounded-2xl p-4 text-xs text-blue-800 space-y-2.5">
                                        <h4 className="font-black uppercase tracking-wider flex items-center gap-1.5 text-blue-900">
                                            <Icon name="help-circle" size={14}/> Hướng dẫn File Excel
                                        </h4>
                                        <p className="leading-relaxed text-[11px]">File Excel danh sách học sinh cần có tối thiểu 3 cột theo đúng thứ tự sau:</p>
                                        <div className="overflow-hidden border border-blue-200 rounded-lg bg-white shadow-sm">
                                            <table className="w-full text-center border-collapse">
                                                <thead>
                                                    <tr className="bg-blue-100/70 text-[9px] font-black uppercase text-blue-900 border-b border-blue-200">
                                                        <th className="py-1 border-r border-blue-200">Cột A</th>
                                                        <th className="py-1 border-r border-blue-200">Cột B</th>
                                                        <th className="py-1">Cột C</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr className="border-b border-blue-100 text-[10px] font-bold">
                                                        <td className="py-1 border-r border-blue-200 text-slate-500">Tên Lớp</td>
                                                        <td className="py-1 border-r border-blue-200 text-slate-500">Họ và đệm</td>
                                                        <td className="py-1 text-slate-500">Tên</td>
                                                    </tr>
                                                    <tr className="text-[10px] bg-slate-50/50 text-slate-400">
                                                        <td className="py-1 border-r border-blue-200">12A1</td>
                                                        <td className="py-1 border-r border-blue-200">Nguyễn Văn</td>
                                                        <td className="py-1">An</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                        <ul className="list-disc list-inside space-y-1 pl-1 text-[11px] text-blue-700 font-medium">
                                            <li>Dòng 1 là tiêu đề (Lớp, Họ đệm, Tên) và sẽ được bỏ qua.</li>
                                            <li>Có thể tự động tạo mật khẩu gợi ý cho học sinh sau khi tải lên file.</li>
                                        </ul>
                                        <button 
                                            type="button" 
                                            onClick={downloadExcelTemplate} 
                                            className="w-full mt-1.5 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all flex items-center justify-center gap-1.5 shadow-sm hover:shadow-md"
                                        >
                                            <Icon name="download" size={12}/> Tải File Mẫu (.xlsx)
                                        </button>
                                    </div>
                                </div>

                                <div className="flex-1 p-5 overflow-y-auto custom-scrollbar bg-white">
                                    <h3 className="font-black text-sm text-slate-700 uppercase tracking-widest mb-4 flex items-center gap-2"><Icon name="list" size={16}/> Các lớp đã lưu ({globalClasses.length})</h3>
                                    
                                    <div className="grid grid-cols-1 gap-3">
                                        {globalClasses.map((cls) => (
                                            <div key={cls.id} className="border border-slate-200 rounded-xl p-3 sm:p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:border-indigo-300 hover:shadow-sm transition-all bg-white">
                                                <div className="flex items-center gap-3 overflow-hidden">
                                                    <div className="px-3 py-1.5 rounded-lg bg-indigo-100 text-indigo-700 font-black text-sm shrink-0 whitespace-nowrap truncate max-w-[150px]" title={cls.className}>
                                                        {cls.className}
                                                    </div>
                                                    <div className="shrink-0">
                                                        <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Sĩ số</p>
                                                        <p className="text-sm font-black text-slate-700">{cls.students?.length || 0} HS</p>
                                                    </div>
                                                </div>
                                                <div className="flex gap-2 shrink-0">
                                                    <button onClick={() => setEditingClass(JSON.parse(JSON.stringify(cls)))} className="flex-1 sm:flex-none bg-blue-50 text-blue-600 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase hover:bg-blue-500 hover:text-white transition-all flex items-center justify-center gap-1.5 border border-blue-100 hover:border-blue-500">
                                                        <Icon name="eye" size={14}/> Xem
                                                    </button>
                                                    <button onClick={() => handleDeleteClass(cls)} className="flex-1 sm:flex-none bg-rose-50 text-rose-500 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase hover:bg-rose-500 hover:text-white transition-all flex items-center justify-center gap-1.5 border border-rose-100 hover:border-rose-500">
                                                        <Icon name="trash-2" size={14}/> Xóa
                                                    </button>
                                                </div>
                                            </div>
                                        ))}
                                        {globalClasses.length === 0 && (
                                            <div className="col-span-full py-10 text-center flex flex-col items-center gap-2 text-slate-400 opacity-60">
                                                <Icon name="folder-open" size={32} />
                                                <p className="text-sm font-bold">Chưa có lớp nào được tạo</p>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            );
        };

        const StudentListModal = ({ exam, onClose, loadExams, showAlert, showDangerConfirm, globalClasses }) => {
            const [requireList, setRequireList] = useState(exam.requireLoginList || false);
            
            const [selectedClasses, setSelectedClasses] = useState(exam.selectedClasses || []);
            const [customStudents, setCustomStudents] = useState(() => {
                if (exam.hasOwnProperty('customStudents')) return exam.customStudents;
                if (exam.hasOwnProperty('selectedClasses')) return []; 
                return exam.allowedStudents || []; 
            });
            
            const [isSaving, setIsSaving] = useState(false);
            const [selectedIdxs, setSelectedIdxs] = useState(new Set()); 
            
            const [showAddManual, setShowAddManual] = useState(false);
            const [newStudent, setNewStudent] = useState({ class: '', lastName: '', firstName: '' });
            const [showClassPicker, setShowClassPicker] = useState(false);

            const [viewingClass, setViewingClass] = useState(null);
            const [editClassStudent, setEditClassStudent] = useState({ lastName: '', firstName: '' });

            const handleFileUpload = (e) => {
                const file = e.target.files[0];
                if(!file) return;
                const reader = new FileReader();
                reader.onload = (evt) => {
                    try {
                        const bstr = evt.target.result;
                        const wb = window.XLSX.read(bstr, {type:'binary'});
                        const wsname = wb.SheetNames[0];
                        const ws = wb.Sheets[wsname];
                        const data = window.XLSX.utils.sheet_to_json(ws, {header: 1});
                        
                        const list = [];
                        for(let i = 1; i < data.length; i++) {
                            const row = data[i];
                            if(row && row.length >= 3 && (row[1] || row[2])) {
                                list.push({
                                    class: String(row[0]||'').trim(),
                                    lastName: String(row[1]||'').trim(),
                                    firstName: String(row[2]||'').trim()
                                });
                            }
                        }
                        
                        setCustomStudents(prev => {
                            const existingKeys = new Set(prev.map(s => `${s.lastName} ${s.firstName}`.trim().toLowerCase()));
                            const newStudents = list.filter(s => !existingKeys.has(`${s.lastName} ${s.firstName}`.trim().toLowerCase()));
                            return [...prev, ...newStudents];
                        });
                        
                        setSelectedIdxs(new Set()); 
                        showAlert(`Đã tải và chèn thêm thành công danh sách gồm ${list.length} học sinh!`);
                    } catch(err) {
                        showAlert("Lỗi đọc file Excel. Vui lòng kiểm tra lại định dạng.");
                    }
                };
                reader.readAsBinaryString(file);
                e.target.value = null; 
            };

            const handleAddStudentToClass = () => {
                if (!editClassStudent.lastName || !editClassStudent.firstName) {
                    showAlert("Vui lòng nhập đủ Họ và Tên!"); return;
                }
                const hasPasswordMode = (viewingClass.students || []).some(s => s.password && s.password.trim() !== '');
                const newStudent = { 
                    class: viewingClass.className, 
                    lastName: editClassStudent.lastName, 
                    firstName: editClassStudent.firstName,
                    ...(hasPasswordMode ? { password: generatePassword(editClassStudent.lastName, editClassStudent.firstName) } : {})
                };
                const updatedClass = { ...viewingClass, students: [newStudent, ...(viewingClass.students || [])] };
                
                setViewingClass(updatedClass);
                setSelectedClasses(selectedClasses.map(c => c.id === updatedClass.id ? updatedClass : c));
                setEditClassStudent({ lastName: '', firstName: '' });
            };

            const handleRemoveStudentFromClass = (idx) => {
                const updatedStudents = viewingClass.students.filter((_, i) => i !== idx);
                const updatedClass = { ...viewingClass, students: updatedStudents };
                
                setViewingClass(updatedClass);
                setSelectedClasses(selectedClasses.map(c => c.id === updatedClass.id ? updatedClass : c));
            };

            const handleSave = async (e) => {
                e.preventDefault();
                setIsSaving(true);
                
                let combined = [...customStudents];
                selectedClasses.forEach(c => {
                    if (c.students) combined = combined.concat(c.students);
                });

                const payload = { 
                    id: exam.id, 
                    requireLoginList: requireList, 
                    allowedStudents: combined,
                    customStudents: customStudents,
                    selectedClasses: selectedClasses
                };

                const r = await fetch('?action=save_student_list', { 
                    method: 'POST', 
                    body: JSON.stringify(payload) 
                });
                const res = await r.json();
                setIsSaving(false);
                if (res.success) {
                    showAlert("Cập nhật danh sách lớp thành công!");
                    loadExams();
                    onClose();
                } else showAlert("Lỗi khi lưu!");
            };

            return (
                <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
                    <div className="bg-white w-full max-w-6xl h-[90vh] rounded-[2rem] shadow-2xl flex flex-col overflow-hidden animate-in zoom-in duration-300">
                        <header className="p-4 sm:p-5 border-b flex justify-between items-center bg-slate-50 shrink-0">
                            <div className="flex items-center gap-3">
                                <div className="p-2 bg-blue-100 text-blue-600 rounded-lg"><Icon name="users" size={18}/></div>
                                <div>
                                    <h2 className="text-base font-black uppercase tracking-widest text-slate-800">Danh sách cấp phép thi</h2>
                                    <p className="text-[10px] font-bold text-slate-400 mt-0.5 truncate max-w-[200px] md:max-w-md">{exam.title}</p>
                                </div>
                            </div>
                            <button type="button" onClick={onClose} className="w-8 h-8 flex items-center justify-center bg-rose-500 text-white hover:bg-rose-600 rounded-full transition-colors shadow-md"><Icon name="x" size={18}/></button>
                        </header>
                        
                        <div className="p-4 sm:p-5 border-b border-slate-100 bg-white shrink-0">
                            <div className="flex flex-col sm:flex-row items-center justify-between bg-blue-50/50 p-4 rounded-xl border border-blue-100 gap-4">
                                <div>
                                    <p className="text-sm font-black text-blue-800 uppercase tracking-tight">Kích hoạt danh sách duyệt</p>
                                    <p className="text-[11px] font-medium text-blue-600 mt-1 max-w-2xl leading-relaxed">Khi <b>BẬT</b>, chỉ học sinh có Họ Tên khớp với danh sách mới được phép vào thi. Khi <b>TẮT</b>, học sinh được phép điền thông tin tự do.</p>
                                </div>
                                <button type="button" onClick={() => setRequireList(!requireList)} className={`relative inline-flex h-8 w-14 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 shadow-inner ${requireList ? 'bg-blue-600' : 'bg-slate-300'}`}>
                                    <span className={`pointer-events-none inline-block h-7 w-7 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${requireList ? 'translate-x-6' : 'translate-x-0'}`}/>
                                </button>
                            </div>
                        </div>

                        <div className="flex-1 overflow-hidden flex flex-col md:flex-row gap-4 p-4 sm:p-5 pt-0">
                            
                            <div className="w-full md:w-1/3 bg-slate-50 border border-slate-200 rounded-xl flex flex-col overflow-hidden shrink-0">
                                <div className="p-3 border-b border-slate-200 bg-slate-100 flex justify-between items-center relative">
                                    <span className="text-[11px] font-black uppercase tracking-widest text-slate-600">Lớp hệ thống ({selectedClasses.length})</span>
                                    <button 
                                        type="button" 
                                        onClick={() => setShowClassPicker(!showClassPicker)}
                                        className="bg-blue-600 text-white hover:bg-blue-500 px-2 py-1 rounded flex items-center gap-1 text-[10px] font-black uppercase transition-all shadow-sm"
                                    >
                                        <Icon name="plus" size={12}/> Thêm
                                    </button>

                                    {showClassPicker && (
                                        <div className="absolute top-full left-0 right-0 mt-1 bg-white rounded-xl shadow-xl border border-slate-200 z-50 overflow-hidden animate-in fade-in slide-in-from-top-2">
                                            <div className="p-2 bg-slate-50 border-b border-slate-100 flex justify-between items-center">
                                                <span className="text-[9px] font-black text-blue-600 uppercase tracking-widest">Click đúp để chọn</span>
                                                <button type="button" onClick={() => setShowClassPicker(false)} className="text-slate-400 hover:text-rose-500"><Icon name="x" size={12}/></button>
                                            </div>
                                            <div className="max-h-48 overflow-y-auto custom-scrollbar p-1.5 space-y-1">
                                                {globalClasses.length === 0 ? (
                                                    <div className="p-3 text-center text-[10px] font-bold text-slate-400">Chưa có lớp nào được lưu.</div>
                                                ) : (
                                                    globalClasses.map(c => (
                                                        <div 
                                                            key={c.id} 
                                                            onDoubleClick={() => {
                                                                setShowClassPicker(false);
                                                                if (selectedClasses.some(sc => sc.id === c.id)) {
                                                                    showAlert(`Lớp ${c.className} đã có trong danh sách!`);
                                                                    return;
                                                                }
                                                                setSelectedClasses([...selectedClasses, c]);
                                                                showAlert(`Đã thêm lớp ${c.className} vào danh sách cấp phép!`);
                                                            }}
                                                            className="px-2 py-1.5 rounded-lg hover:bg-blue-50 cursor-pointer border border-transparent hover:border-blue-100 transition-colors flex justify-between items-center group select-none"
                                                            title="Click đúp chuột để thêm Lớp này"
                                                        >
                                                            <span className="font-bold text-xs text-slate-700 group-hover:text-blue-700">{c.className}</span>
                                                            <span className="text-[9px] font-black text-slate-400 bg-slate-100 px-1.5 py-0.5 rounded-full">{c.students?.length || 0} HS</span>
                                                        </div>
                                                    ))
                                                )}
                                            </div>
                                        </div>
                                    )}
                                </div>
                                <div className="flex-1 overflow-y-auto p-2 custom-scrollbar space-y-1.5">
                                    {selectedClasses.length === 0 && (
                                        <div className="text-center py-6 text-slate-400 font-medium text-[10px] flex flex-col items-center gap-1.5">
                                            <Icon name="library" size={24} className="opacity-20"/>
                                            Chưa chọn lớp hệ thống nào.
                                        </div>
                                    )}
                                    {selectedClasses.map((c, i) => (
                                        <div key={i} className="bg-white border border-slate-200 rounded-lg p-2 flex justify-between items-center shadow-sm group">
                                            <div className="flex items-center gap-2 cursor-pointer flex-1 overflow-hidden" onClick={() => setViewingClass(c)}>
                                                <div className="w-6 h-6 bg-blue-100 text-blue-600 rounded-md flex items-center justify-center shrink-0">
                                                    <Icon name="users" size={12}/>
                                                </div>
                                                <div className="flex-1 min-w-0">
                                                    <h4 className="font-black text-xs text-slate-700 truncate group-hover:text-blue-600 transition-colors">{c.className}</h4>
                                                    <p className="text-[9px] font-bold text-slate-400">{c.students?.length || 0} học sinh</p>
                                                </div>
                                            </div>
                                            <button onClick={() => {
                                                showDangerConfirm(`Xóa lớp ${c.className} khỏi danh sách cấp phép?`, () => {
                                                    setSelectedClasses(selectedClasses.filter((_, idx) => idx !== i));
                                                });
                                            }} className="w-6 h-6 flex items-center justify-center bg-rose-50 text-rose-500 hover:bg-rose-500 hover:text-white rounded transition-colors shrink-0">
                                                <Icon name="trash-2" size={12}/>
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            <div className="w-full md:w-2/3 bg-white border border-slate-200 rounded-xl flex flex-col overflow-hidden">
                                <div className="p-3 border-b border-slate-200 bg-slate-50 flex justify-between items-center gap-2 flex-wrap">
                                    <span className="text-[11px] font-black uppercase tracking-widest text-slate-600">HS Excel / Thủ công ({customStudents.length})</span>
                                    <div className="flex gap-2">
                                        <button 
                                            type="button"
                                            onClick={downloadExcelTemplate}
                                            className="bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 hover:text-blue-600 px-3 py-1.5 rounded-lg font-black uppercase tracking-widest text-[10px] flex items-center justify-center gap-1.5 cursor-pointer transition-all shadow-sm"
                                            title="Tải file Excel mẫu"
                                        >
                                            <Icon name="download" size={12}/> Mẫu
                                        </button>
                                        <label className="bg-white border border-blue-300 text-blue-600 hover:bg-blue-50 hover:border-blue-500 px-3 py-1.5 rounded-lg font-black uppercase tracking-widest text-[10px] flex items-center justify-center gap-1.5 cursor-pointer transition-all shadow-sm">
                                            <Icon name="upload-cloud" size={12}/> Excel
                                            <input type="file" accept=".xlsx, .xls, .csv" className="hidden" onChange={handleFileUpload} />
                                        </label>
                                        <button onClick={() => setShowAddManual(!showAddManual)} className="bg-slate-200 text-slate-600 hover:bg-slate-300 px-3 py-1.5 rounded-lg font-black uppercase tracking-widest text-[10px] flex items-center justify-center gap-1.5 transition-all shadow-sm">
                                            <Icon name="plus" size={12}/> Thêm
                                        </button>
                                    </div>
                                </div>
                                
                                {showAddManual && (
                                    <div className="flex flex-wrap gap-2 items-end bg-slate-100 p-3 border-b border-slate-200 animate-in slide-in-from-top-2">
                                        <div className="flex-1 min-w-[80px]">
                                            <label className="text-[10px] font-black uppercase text-slate-500 mb-1 block pl-1">Lớp</label>
                                            <input value={newStudent.class} onChange={e=>setNewStudent({...newStudent, class: e.target.value.toUpperCase()})} className="w-full p-2 text-xs rounded border border-slate-300 font-bold outline-none focus:border-blue-500" placeholder="VD: 12A1"/>
                                        </div>
                                        <div className="flex-[2] min-w-[120px]">
                                            <label className="text-[10px] font-black uppercase text-slate-500 mb-1 block pl-1">Họ và đệm</label>
                                            <input value={newStudent.lastName} onChange={e=>setNewStudent({...newStudent, lastName: e.target.value})} className="w-full p-2 text-xs rounded border border-slate-300 font-bold outline-none focus:border-blue-500" placeholder="VD: Nguyễn Văn"/>
                                        </div>
                                        <div className="flex-[2] min-w-[100px]">
                                            <label className="text-[10px] font-black uppercase text-slate-500 mb-1 block pl-1">Tên</label>
                                            <input value={newStudent.firstName} onChange={e=>setNewStudent({...newStudent, firstName: e.target.value})} onKeyDown={e=>{
                                                if(e.key === 'Enter') {
                                                    if(!newStudent.firstName || !newStudent.lastName) return showAlert("Vui lòng nhập đủ Họ và Tên!");
                                                    setCustomStudents([{...newStudent}, ...customStudents]);
                                                    setNewStudent({class:'', lastName:'', firstName:''});
                                                }
                                            }} className="w-full p-2 text-xs rounded border border-slate-300 font-bold outline-none focus:border-blue-500" placeholder="VD: An"/>
                                        </div>
                                        <button onClick={() => {
                                            if(!newStudent.firstName || !newStudent.lastName) return showAlert("Vui lòng nhập đủ Họ và Tên!");
                                            setCustomStudents([{...newStudent}, ...customStudents]);
                                            setNewStudent({class:'', lastName:'', firstName:''});
                                        }} className="bg-blue-600 text-white px-4 py-2 rounded font-black text-[10px] uppercase hover:bg-blue-500 transition-colors h-[34px] shadow-sm">Lưu</button>
                                    </div>
                                )}

                                <div className="p-2 border-b border-slate-100 bg-white flex items-center gap-2">
                                    {selectedIdxs.size > 0 && (
                                        <button onClick={() => {
                                            showDangerConfirm(`Xóa ${selectedIdxs.size} học sinh đã chọn khỏi danh sách thủ công?`, () => {
                                                setCustomStudents(customStudents.filter((_, idx) => !selectedIdxs.has(idx)));
                                                setSelectedIdxs(new Set());
                                            });
                                        }} className="bg-rose-500 text-white px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest shadow-sm hover:bg-rose-600 flex items-center gap-1 transition-all">
                                            Xóa chọn ({selectedIdxs.size})
                                        </button>
                                    )}
                                    {customStudents.length > 0 && (
                                        <button onClick={() => {
                                            showDangerConfirm('Bạn có chắc chắn muốn xóa toàn bộ danh sách nhập từ Excel/Thủ công?', () => {
                                                setCustomStudents([]);
                                                setSelectedIdxs(new Set());
                                            });
                                        }} className="border border-rose-500 text-rose-500 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest hover:bg-rose-50 flex items-center gap-1 transition-all ml-auto">
                                            Xóa tất cả
                                        </button>
                                    )}
                                </div>

                                <div className="grid grid-cols-12 gap-4 bg-slate-100 p-2.5 border-b border-slate-200 text-[10px] font-black text-slate-500 uppercase tracking-widest shrink-0 items-center">
                                    <div className="col-span-1 text-center">
                                        <input type="checkbox" checked={customStudents.length > 0 && selectedIdxs.size === customStudents.length} onChange={() => {
                                            if (selectedIdxs.size === customStudents.length) setSelectedIdxs(new Set());
                                            else setSelectedIdxs(new Set(customStudents.map((_, i) => i)));
                                        }} className="w-3.5 h-3.5 text-blue-600 rounded border-gray-300 cursor-pointer"/>
                                    </div>
                                    <div className="col-span-1 text-center">STT</div>
                                    <div className="col-span-2 text-center">Lớp</div>
                                    <div className="col-span-6 pl-2">Họ và Tên</div>
                                    <div className="col-span-2 text-center">Thao tác</div>
                                </div>
                                
                                <div className="flex-1 overflow-y-auto custom-scrollbar p-2 space-y-1">
                                    {customStudents.map((s, i) => (
                                        <div key={i} className={`grid grid-cols-12 gap-4 bg-white p-2 rounded border text-sm font-bold items-center shadow-sm transition-colors ${selectedIdxs.has(i) ? 'border-blue-300 bg-blue-50/50' : 'border-slate-100 text-slate-700'}`}>
                                            <div className="col-span-1 text-center">
                                                <input type="checkbox" checked={selectedIdxs.has(i)} onChange={() => {
                                                    const newSet = new Set(selectedIdxs);
                                                    if(newSet.has(i)) newSet.delete(i); else newSet.add(i);
                                                    setSelectedIdxs(newSet);
                                                }} className="w-3.5 h-3.5 text-blue-600 rounded border-gray-300 cursor-pointer"/>
                                            </div>
                                            <div className="col-span-1 text-center text-slate-400 text-[10px]">{i + 1}</div>
                                            <div className="col-span-2 text-center text-blue-600 bg-blue-50/80 py-1 rounded text-[10px]">{s.class || '-'}</div>
                                            <div className="col-span-6 pl-2 truncate text-xs" title={`${s.lastName} ${s.firstName}`}>{s.lastName} {s.firstName}</div>
                                            <div className="col-span-2 flex justify-center">
                                                <button onClick={() => {
                                                    showDangerConfirm(`Xóa học sinh ${s.lastName} ${s.firstName} khỏi danh sách?`, () => {
                                                        setCustomStudents(customStudents.filter((_, idx) => idx !== i));
                                                        if(selectedIdxs.has(i)) {
                                                            const newSet = new Set(selectedIdxs);
                                                            newSet.delete(i);
                                                            setSelectedIdxs(newSet);
                                                        }
                                                    });
                                                }} className="bg-rose-50 text-rose-500 hover:bg-rose-500 hover:text-white px-2 py-1 rounded text-[10px] font-black uppercase tracking-widest transition-all border border-rose-200 hover:border-rose-500 shadow-sm">
                                                    Xóa
                                                </button>
                                            </div>
                                        </div>
                                    ))}
                                    {customStudents.length === 0 && (
                                        <div className="flex flex-col items-center justify-center py-8 px-4 space-y-4">
                                            <div className="text-center text-slate-400 font-medium text-xs flex flex-col items-center gap-2">
                                                <Icon name="file-spreadsheet" size={32} className="opacity-40"/>
                                                <span>Danh sách nhập ngoài trống</span>
                                            </div>
                                            
                                            <div className="w-full max-w-md bg-blue-50 border border-blue-200 rounded-2xl p-4 text-xs text-blue-800 space-y-2.5 shadow-sm text-left">
                                                <h4 className="font-black uppercase tracking-wider flex items-center gap-1.5 text-blue-900">
                                                    <Icon name="help-circle" size={14}/> Hướng dẫn Cấu trúc File Excel
                                                </h4>
                                                <p className="leading-relaxed text-[11px]">Tải lên file Excel (.xlsx, .xls, .csv) với danh sách học sinh theo 3 cột:</p>
                                                <div className="overflow-hidden border border-blue-200 rounded-lg bg-white shadow-sm">
                                                    <table className="w-full text-center border-collapse">
                                                        <thead>
                                                            <tr className="bg-blue-100/70 text-[9px] font-black uppercase text-blue-900 border-b border-blue-200">
                                                                <th className="py-1 border-r border-blue-200">Cột A</th>
                                                                <th className="py-1 border-r border-blue-200">Cột B</th>
                                                                <th className="py-1">Cột C</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <tr className="border-b border-blue-100 text-[10px] font-bold">
                                                                <td className="py-1 border-r border-blue-200 text-slate-500">Tên Lớp</td>
                                                                <td className="py-1 border-r border-blue-200 text-slate-500">Họ và đệm</td>
                                                                <td className="py-1 text-slate-500">Tên</td>
                                                            </tr>
                                                            <tr className="text-[10px] bg-slate-50/50 text-slate-400">
                                                                <td className="py-1 border-r border-blue-200">12A1</td>
                                                                <td className="py-1 border-r border-blue-200">Nguyễn Văn</td>
                                                                <td className="py-1">An</td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <p className="text-[10px] text-blue-600 font-medium">💡 <b>Lưu ý:</b> Dòng 1 chứa tiêu đề cột (sẽ tự động bỏ qua). Bạn có thể bấm nút bên dưới để tải file mẫu chuẩn về chỉnh sửa.</p>
                                                <button 
                                                    type="button" 
                                                    onClick={downloadExcelTemplate} 
                                                    className="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all flex items-center justify-center gap-1.5 shadow-sm hover:shadow-md"
                                                >
                                                    <Icon name="download" size={12}/> Tải File Excel Mẫu (.xlsx)
                                                </button>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>

                        {viewingClass && (
                            <div className="fixed inset-0 z-[70] bg-slate-950/60 backdrop-blur-sm flex items-center justify-center p-4" onClick={(e) => { if(e.target === e.currentTarget) setViewingClass(null); }}>
                                <div className="bg-white w-full max-w-lg rounded-2xl shadow-2xl flex flex-col overflow-hidden animate-in zoom-in-95 duration-200">
                                    <header className="p-4 border-b bg-slate-50 flex justify-between items-center">
                                        <h3 className="font-black text-slate-800 text-sm uppercase flex items-center gap-2"><Icon name="users" size={16} className="text-blue-500"/> Danh sách Lớp: {viewingClass.className}</h3>
                                        <button onClick={() => setViewingClass(null)} className="text-slate-400 hover:text-rose-500 bg-white p-1 rounded border shadow-sm"><Icon name="x" size={14}/></button>
                                    </header>
                                    
                                    <div className="p-3 bg-slate-100 border-b border-slate-200 flex gap-2 items-end">
                                        <div className="flex-[2]">
                                            <label className="text-[10px] font-black uppercase text-slate-500 mb-1 block pl-1">Họ và đệm</label>
                                            <input value={editClassStudent.lastName} onChange={e=>setEditClassStudent({...editClassStudent, lastName: e.target.value})} className="w-full p-2 text-xs rounded border border-slate-300 font-bold outline-none focus:border-blue-500" placeholder="VD: Nguyễn Văn"/>
                                        </div>
                                        <div className="flex-[2]">
                                            <label className="text-[10px] font-black uppercase text-slate-500 mb-1 block pl-1">Tên</label>
                                            <input value={editClassStudent.firstName} onChange={e=>setEditClassStudent({...editClassStudent, firstName: e.target.value})} onKeyDown={e=>{
                                                if(e.key === 'Enter') handleAddStudentToClass();
                                            }} className="w-full p-2 text-xs rounded border border-slate-300 font-bold outline-none focus:border-blue-500" placeholder="VD: An"/>
                                        </div>
                                        <button onClick={handleAddStudentToClass} className="bg-blue-600 text-white px-3 py-2 rounded font-black text-[10px] uppercase hover:bg-blue-500 transition-colors h-[34px] shadow-sm flex items-center gap-1">
                                            <Icon name="plus" size={12}/> Thêm
                                        </button>
                                    </div>

                                    <div className="p-0 max-h-[50vh] overflow-y-auto custom-scrollbar">
                                        <table className="w-full text-left text-sm">
                                            <thead className="sticky top-0 bg-white shadow-sm">
                                                <tr className="border-b-2 text-[10px] text-slate-400 font-black uppercase tracking-widest bg-slate-50">
                                                    <th className="py-2.5 px-3 w-12 text-center">STT</th>
                                                    <th className="py-2.5 px-3">Họ và Tên</th>
                                                    <th className="py-2.5 px-3">Mật khẩu</th>
                                                    <th className="py-2.5 px-3 w-16 text-center">Xóa</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {viewingClass.students?.map((s, i) => (
                                                    <tr key={i} className="border-b border-slate-100 last:border-0 hover:bg-slate-50 transition-colors group">
                                                        <td className="py-2.5 px-3 text-center text-slate-400 font-bold text-xs">{i+1}</td>
                                                        <td className="py-2.5 px-3 font-bold text-slate-700">{s.lastName} {s.firstName}</td>
                                                        <td className="py-2.5 px-3 font-mono text-xs text-slate-500 font-bold">{s.password || '-'}</td>
                                                        <td className="py-2.5 px-3 text-center">
                                                            <button onClick={() => handleRemoveStudentFromClass(i)} className="w-6 h-6 inline-flex items-center justify-center bg-rose-50 text-rose-500 hover:bg-rose-500 hover:text-white rounded transition-colors opacity-0 group-hover:opacity-100">
                                                                <Icon name="trash-2" size={12}/>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                ))}
                                                {(!viewingClass.students || viewingClass.students.length === 0) && (
                                                    <tr>
                                                        <td colSpan="4" className="py-8 text-center text-slate-400 font-bold text-xs italic">Lớp này hiện chưa có học sinh nào.</td>
                                                    </tr>
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        )}

                        <div className="p-4 sm:p-5 border-t flex justify-end gap-3 bg-slate-50 shrink-0">
                            <button type="button" onClick={onClose} className="px-6 py-2.5 font-bold text-xs uppercase tracking-widest text-slate-500 hover:text-slate-800 transition-colors bg-white border border-slate-200 rounded-xl shadow-sm hover:bg-slate-100">ĐÓNG</button>
                            <button onClick={handleSave} disabled={isSaving} className="bg-blue-600 text-white px-8 py-2.5 rounded-xl font-black uppercase tracking-widest shadow-md hover:bg-blue-500 transition-colors flex items-center justify-center gap-2 text-xs">
                                {isSaving ? <Icon name="loader" className="animate-spin" size={16}/> : <Icon name="save" size={16}/>} LƯU CÀI ĐẶT
                            </button>
                        </div>
                    </div>
                </div>
            );
        };

        const LiveMonitorModal = ({ exam, onClose }) => {
            const [liveData, setLiveData] = useState([]);
            const [historyData, setHistoryData] = useState([]);
            const [currentTime, setCurrentTime] = useState(Date.now());
            const [viewingRecord, setViewingRecord] = useState(null);

            const totalQ = (exam?.config?.p1 || 0) + (exam?.config?.p2 || 0) + (exam?.config?.p3 || 0);

            const calculateLiveScore = (uAns) => {
                let score = 0;
                let completed = 0;
                if (!uAns || typeof uAns !== 'object') return { score: 0, completed: 0 };
                
                const conf = exam.config || {p1:0, p2:0, p3:0};
                const cAns = exam.correctAnswers || {};
                const scoring = exam.scoring || {p1:0.25, p2:{'4':1,'3':0.5,'2':0.25,'1':0.1}, p3:0.5};

                if (uAns.p1) {
                    for (let i = 1; i <= conf.p1; i++) {
                        if (uAns.p1[i]) {
                            completed++;
                            if (cAns.p1 && String(uAns.p1[i]).trim() === String(cAns.p1[i]).trim()) {
                                score += parseFloat(scoring.p1);
                            }
                        }
                    }
                }
                if (uAns.p2) {
                    for (let i = 1; i <= conf.p2; i++) {
                        let hasAnswer = false;
                        let correctCount = 0;
                        ['a','b','c','d'].forEach(sub => {
                            if (uAns.p2[i] && uAns.p2[i][sub] !== undefined && uAns.p2[i][sub] !== null && uAns.p2[i][sub] !== '') {
                                hasAnswer = true;
                                let uVal = typeof uAns.p2[i][sub] === 'boolean' ? (uAns.p2[i][sub] ? '1':'0') : String(uAns.p2[i][sub]).trim();
                                let cVal = typeof cAns.p2?.[i]?.[sub] === 'boolean' ? (cAns.p2[i][sub] ? '1':'0') : String(cAns.p2?.[i]?.[sub]).trim();
                                if (uVal === cVal) correctCount++;
                            }
                        });
                        if (hasAnswer) {
                            completed++;
                            if (correctCount === 4) score += parseFloat(scoring.p2['4']);
                            else if (correctCount === 3) score += parseFloat(scoring.p2['3']);
                            else if (correctCount === 2) score += parseFloat(scoring.p2['2']);
                            else if (correctCount === 1) score += parseFloat(scoring.p2['1']);
                        }
                    }
                }
                if (uAns.p3) {
                    for (let i = 1; i <= conf.p3; i++) {
                        if (uAns.p3[i] !== undefined && uAns.p3[i] !== null && uAns.p3[i] !== '') {
                            completed++;
                            let uA = String(uAns.p3[i]).trim().toLowerCase().replace(/[ ,$]/g, '').replace(',', '.');
                            let cA = String(cAns.p3?.[i] || '').trim().toLowerCase().replace(/[ ,$]/g, '').replace(',', '.');
                            if (uA !== '' && uA === cA) {
                                score += parseFloat(scoring.p3);
                            }
                        }
                    }
                }
                return { score, completed };
            };

            useEffect(() => {
                const fetchData = () => {
                    Promise.all([
                        fetch(`?action=get_live&examId=${exam.id}&t=${Date.now()}`).then(r => r.json()),
                        fetch(`?action=get_history&examId=${exam.id}&t=${Date.now()}`).then(r => r.json())
                    ]).then(([live, hist]) => {
                        const lData = Array.isArray(live) ? live : [];
                        const hData = Array.isArray(hist) ? hist : [];
                        
                        const submittedKeys = new Set(hData.map(h => `${h.name}_${h.class}_${String(h.startTime)}`));
                        const activeOnly = lData.filter(l => !submittedKeys.has(`${l.name}_${l.class}_${String(l.startTime)}`));
                        
                        const activeWithLiveScores = activeOnly.map(s => {
                            if (s.userAnswers) {
                                const { score, completed } = calculateLiveScore(s.userAnswers);
                                return { ...s, score, completed };
                            }
                            return s;
                        });

                        activeWithLiveScores.sort((a, b) => parseFloat(b.score || 0) - parseFloat(a.score || 0));
                        
                        setLiveData([...activeWithLiveScores]);
                        setHistoryData([...hData]);
                    });
                };
                fetchData();
                const pollInterval = setInterval(fetchData, 1000);
                const timeInterval = setInterval(() => setCurrentTime(Date.now()), 1000);
                return () => { clearInterval(pollInterval); clearInterval(timeInterval); };
            }, [exam.id]);

            const formatDuration = (startTime, endTime = null) => {
                if (!startTime) return '0s';
                const sTime = new Date(startTime).getTime();
                const eTime = endTime ? new Date(endTime).getTime() : currentTime;
                if (isNaN(sTime)) return '0s';
                const diff = Math.max(0, Math.floor((eTime - sTime) / 1000));
                const m = Math.floor(diff / 60);
                const s = diff % 60;
                return `${m}p ${s}s`;
            };

            const totalJoined = liveData.length + historyData.length;

            const StudentResultDetail = ({ record, onClose }) => {
                const p1Count = exam?.config?.p1 || 0;
                const p2Count = exam?.config?.p2 || 0;
                const p3Count = exam?.config?.p3 || 0;
                
                return (
                    <div 
                        className="fixed inset-0 z-[120] bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-2"
                        onClick={onClose}
                    >
                        <div 
                            className="bg-slate-100 w-full max-w-[1050px] max-h-[95vh] rounded-2xl shadow-2xl flex flex-col overflow-hidden border border-slate-300 animate-in zoom-in-95 duration-200"
                            onClick={(e) => e.stopPropagation()}
                        >
                            <header className="px-3 py-2 bg-white border-b flex justify-between items-center shrink-0">
                                <div className="flex items-center gap-2 md:gap-3 flex-wrap">
                                    <h2 className="text-[13px] md:text-sm font-black uppercase text-blue-900 flex items-center gap-1.5">
                                        <Icon name="user" size={14} className="hidden sm:block"/> {record?.name || 'Vô danh'}
                                    </h2>
                                    <div className="flex items-center gap-1.5 flex-wrap">
                                        <span className="bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded text-[9px] font-bold uppercase">Lớp: {record?.class || '-'}</span>
                                        <span className="bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded text-[9px] font-bold"><Icon name="clock" size={9} className="inline"/> {formatDuration(record?.startTime, record?.endTime)}</span>
                                        <span className={`px-1.5 py-0.5 rounded text-[9px] font-black ${parseFloat(record?.score || 0) >= 5 ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'}`}>ĐIỂM: {parseFloat(record?.score || 0).toFixed(2)}</span>
                                        {(record?.tabSwitches || 0) > 0 && <span className="bg-rose-500 text-white text-[9px] px-1.5 py-0.5 rounded font-bold">LỖI: {record.tabSwitches}</span>}
                                    </div>
                                </div>
                                <button onClick={onClose} className="w-6 h-6 flex items-center justify-center bg-rose-100 text-rose-600 hover:bg-rose-500 hover:text-white rounded-full transition-colors"><Icon name="x" size={14}/></button>
                            </header>
                            
                            <div className="flex-1 flex flex-col lg:flex-row gap-2 p-2 min-h-0">
                                {/* Phần I */}
                                {p1Count > 0 && (
                                    <div className="flex-[1.2] flex flex-col bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                                        <div className="bg-blue-50/50 py-1.5 px-2 border-b border-blue-100 text-[10px] font-black text-blue-800 uppercase tracking-widest flex items-center gap-1.5 shrink-0">
                                            <Icon name="check-circle" size={12} className="text-blue-500"/> Phần I
                                        </div>
                                        <div className="flex-1 overflow-y-auto custom-scrollbar p-1.5">
                                            <div className="grid grid-cols-4 sm:grid-cols-5 gap-1">
                                                {Array.from({length: p1Count}, (_, i)=>i+1).map(n => {
                                                    const uA = record?.userAnswers?.p1?.[n]; 
                                                    const cA = exam?.correctAnswers?.p1?.[n]; 
                                                    const isC = (String(uA) === String(cA));
                                                    return (
                                                        <div key={n} className={`p-1 border rounded flex justify-between items-center ${isC ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-rose-50 border-rose-200 text-rose-700'}`}>
                                                            <span className="font-bold opacity-70 text-[8px]">C.{n}</span>
                                                            <span className="font-black text-[10px]">{uA || '-'}</span>
                                                        </div>
                                                    )
                                                })}
                                            </div>
                                        </div>
                                    </div>
                                )}

                                {/* Phần II */}
                                {p2Count > 0 && (
                                    <div className="flex-[1.5] flex flex-col bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                                        <div className="bg-cyan-50/50 py-1.5 px-2 border-b border-cyan-100 text-[10px] font-black text-cyan-800 uppercase tracking-widest flex items-center gap-1.5 shrink-0">
                                            <Icon name="list-checks" size={12} className="text-cyan-500"/> Phần II
                                        </div>
                                        <div className="flex-1 overflow-y-auto custom-scrollbar p-1.5">
                                            <div className="grid grid-cols-1 md:grid-cols-2 gap-1.5">
                                                {Array.from({length: p2Count}, (_, i)=>i+1).map(n => (
                                                    <div key={n} className="border border-slate-200 p-1.5 rounded bg-slate-50 flex flex-col">
                                                        <span className="font-black text-[9px] text-slate-500 mb-1">CÂU {n}</span>
                                                        <div className="grid grid-cols-2 gap-1 flex-1">
                                                            {['a','b','c','d'].map(s => {
                                                                const uA = record?.userAnswers?.p2?.[n]?.[s]; 
                                                                const cA = exam?.correctAnswers?.p2?.[n]?.[s]; 
                                                                const isC = uA !== undefined && uA === cA;
                                                                return (
                                                                    <div key={s} className={`text-[8px] p-1 rounded flex justify-between items-center border ${isC ? 'bg-emerald-100 border-emerald-200 text-emerald-700' : 'bg-rose-100 border-rose-200 text-rose-700'}`}>
                                                                        <span className="font-bold uppercase">{s}) {uA === true ? 'Đ' : (uA === false ? 'S' : '-')}</span>
                                                                        {!isC && <span className="font-bold opacity-60 bg-white/80 px-0.5 rounded text-[7px]">Đ/A:{cA?'Đ':'S'}</span>}
                                                                    </div>
                                                                )
                                                            })}
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                )}

                                {/* Phần III */}
                                {p3Count > 0 && (
                                    <div className="flex-1 flex flex-col bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                                        <div className="bg-orange-50/50 py-1.5 px-2 border-b border-orange-100 text-[10px] font-black text-orange-800 uppercase tracking-widest flex items-center gap-1.5 shrink-0">
                                            <Icon name="edit-3" size={12} className="text-orange-500"/> Phần III
                                        </div>
                                        <div className="flex-1 overflow-y-auto custom-scrollbar p-1.5">
                                            <div className="grid grid-cols-2 gap-1.5">
                                                {Array.from({length: p3Count}, (_, i)=>i+1).map(n => {
                                                    let uA = (record?.userAnswers?.p3?.[n] || '').toString().trim().toLowerCase().replace(/[ ,$]/g, '').replace(',', '.');
                                                    let cA = (exam?.correctAnswers?.p3?.[n] || '').toString().trim().toLowerCase().replace(/[ ,$]/g, '').replace(',', '.'); 
                                                    const isC = uA !== '' && uA === cA;
                                                    return (
                                                        <div key={n} className={`p-1.5 border rounded flex flex-col gap-0.5 justify-center ${isC ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-rose-50 border-rose-200 text-rose-700'}`}>
                                                            <div className="flex justify-between items-center">
                                                                <span className="font-bold opacity-70 text-[8px]">Câu {n}</span>
                                                                {!isC && <span className="font-bold text-[7px] bg-rose-200 text-rose-800 px-1 rounded">Đ/A: {exam?.correctAnswers?.p3?.[n] || '-'}</span>}
                                                            </div>
                                                            <span className="font-black text-[10px] truncate" title={record?.userAnswers?.p3?.[n]}>{record?.userAnswers?.p3?.[n] || '-'}</span>
                                                        </div>
                                                    )
                                                })}
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                );
            };

            return (
                <div className="fixed inset-0 z-[100] bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4 md:p-8 font-sans">
                    <style>{`
                        @keyframes live-pulse {
                            0%, 100% { transform: scale(1); }
                            50% { transform: scale(1.15); }
                        }
                        .animate-live-pulse { animation: live-pulse 1.2s ease-in-out infinite; }
                    `}</style>

                    {viewingRecord && <StudentResultDetail record={viewingRecord} onClose={() => setViewingRecord(null)} />}

                    <div className="bg-[#0f1522] w-full max-w-[1400px] h-[90vh] rounded-[2rem] shadow-2xl border border-white/10 flex flex-col overflow-hidden text-white animate-in zoom-in-95 duration-200">
                        {/* Header Giám Sát */}
                        <div className="flex flex-col md:flex-row justify-between items-start md:items-center p-5 md:p-6 lg:p-8 bg-[#161c2a] border-b border-white/5 shrink-0 gap-6">
                            <div className="flex items-center gap-3">
                                <div className="w-3 h-3 rounded-full bg-rose-500 animate-pulse shadow-[0_0_10px_rgba(244,63,94,0.8)]"></div>
                                <div>
                                    <h1 className="text-xl md:text-2xl font-bold uppercase tracking-wide">Theo dõi giám sát</h1>
                                    <p className="text-[11px] font-medium text-slate-400 mt-1">
                                        Đang thi: <span className="text-blue-400 font-bold">{exam.title}</span> | Cập nhật lúc: {new Date().toLocaleTimeString('vi-VN')}
                                    </p>
                                </div>
                            </div>

                            <div className="flex gap-3 items-center">
                                <div className="bg-[#1c2130] px-4 py-2 rounded-xl border border-[#2a3143] flex flex-col items-center min-w-[90px] md:min-w-[110px]">
                                    <span className="text-[9px] font-bold text-blue-400 uppercase tracking-widest mb-0.5">Đã vào</span>
                                    <span className="text-xl font-bold">{totalJoined}</span>
                                </div>
                                <div className="bg-[#133027] px-4 py-2 rounded-xl border border-emerald-900 flex flex-col items-center min-w-[90px] md:min-w-[110px]">
                                    <span className="text-[9px] font-bold text-emerald-500 uppercase tracking-widest mb-0.5">Đang làm</span>
                                    <span className="text-xl font-bold text-emerald-400">{liveData.length}</span>
                                </div>
                                <div className="bg-[#1c2130] px-4 py-2 rounded-xl border border-[#2a3143] flex flex-col items-center min-w-[90px] md:min-w-[110px]">
                                    <span className="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-0.5">Đã nộp</span>
                                    <span className="text-xl font-bold">{historyData.length}</span>
                                </div>
                                <button onClick={onClose} className="w-10 h-10 ml-2 rounded-full bg-rose-500 hover:bg-rose-400 transition-colors flex items-center justify-center shrink-0 shadow-lg">
                                    <Icon name="x" size={20} className="text-white"/>
                                </button>
                            </div>
                        </div>

                        {/* Content Giám Sát */}
                        <div className="flex flex-1 overflow-hidden gap-6 p-5 md:p-6 lg:p-8">
                            {/* CỘT TRÁI: ĐANG LÀM BÀI */}
                            <div className="flex-1 flex flex-col min-w-0">
                                <h2 className="text-sm font-bold text-slate-400 uppercase tracking-wider mb-4">
                                    Đang làm bài ({liveData.length})
                                </h2>
                                
                                {liveData.length === 0 ? (
                                    <div className="flex-1 flex flex-col items-center justify-center opacity-30 border-2 border-dashed border-[#2a3143] rounded-3xl">
                                        <Icon name="monitor-off" size={48} className="mb-4"/>
                                        <p className="font-semibold uppercase tracking-widest text-sm">Trống</p>
                                    </div>
                                ) : (
                                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 overflow-y-auto custom-scrollbar pr-2 pb-4">
                                        {liveData.map((s) => {
                                            const completed = s.completed ? parseInt(s.completed) : 0;
                                            const score = parseFloat(s.score || 0);
                                            const percent = totalQ > 0 ? Math.min(100, Math.round((completed / totalQ) * 100)) : 0;
                                            const hasError = s.tabSwitches > 0;
                                            const pingAge = Math.floor(currentTime / 1000) - (s.lastPing || 0);
                                            const isOnline = pingAge <= 15;
                                            
                                            return (
                                                <div key={`${s.name}-${s.startTime}`} className={`p-4 rounded-xl border flex flex-col justify-between transition-all duration-300 cursor-pointer hover:border-blue-500/50 hover:scale-[1.02] ${!isOnline ? 'bg-[#181d29] border-[#202737] opacity-60' : (hasError ? 'bg-[#2a161b] border-rose-900/80 shadow-[0_0_15px_rgba(225,29,72,0.1)]' : 'bg-[#1c2130] border-[#2a3143]')}`} onClick={() => setViewingRecord(s)}>
                                                    <div className="flex gap-2.5 items-start mb-3">
                                                        <div className={`px-2 py-1 rounded text-[9px] font-black shrink-0 mt-0.5 border ${!isOnline ? 'bg-slate-700/20 text-slate-400 border-slate-600/30' : (hasError ? 'bg-rose-500/20 text-rose-400 border-rose-500/30' : 'bg-blue-600/20 text-blue-400 border-blue-500/30')}`}>
                                                            {s.class || 'N/A'}
                                                        </div>
                                                        <div className="flex flex-col min-w-0 flex-1">
                                                            <h3 className="text-[11px] font-bold text-white uppercase leading-snug line-clamp-2 mb-1" title={s.name}>{s.name}</h3>
                                                            
                                                            {/* Hàng 1: Thời gian bắt đầu & Trạng thái kết nối */}
                                                            <div className="flex items-center gap-2 flex-wrap">
                                                                <span className="text-[9px] text-slate-400 flex items-center gap-1 font-medium"><Icon name="clock" size={10}/> {formatDuration(s.startTime)}</span>
                                                                
                                                                {!isOnline ? (
                                                                    <span className="bg-slate-600 text-white text-[8px] font-bold px-1.5 py-0.5 rounded flex items-center gap-1">
                                                                        <Icon name="wifi-off" size={8}/> RỜI TRANG ({pingAge}s)
                                                                    </span>
                                                                ) : (
                                                                    <span className="bg-emerald-500 text-white text-[8px] font-bold px-2 py-0.5 rounded shadow-[0_0_8px_rgba(16,185,129,0.5)] flex items-center gap-1 animate-live-pulse origin-center">
                                                                        <div className="w-1.5 h-1.5 bg-white rounded-full"></div> LIVE
                                                                    </span>
                                                                )}
                                                            </div>

                                                            {/* Hàng 2: Trạng thái đang làm bài & Cảnh báo lỗi vi phạm */}
                                                            <div className="flex items-center gap-1.5 mt-1.5 flex-wrap">
                                                                    {isOnline && (
                                                                        <span className="text-[9px] font-black text-emerald-400 flex items-center gap-1 bg-emerald-500/10 px-2 py-0.5 rounded border border-emerald-500/20">
                                                                            <span className="relative flex h-1 w-1 shrink-0">
                                                                                <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                                                                <span className="relative inline-flex rounded-full h-1 w-1 bg-emerald-500"></span>
                                                                            </span>
                                                                            <span>ĐANG LÀM BÀI</span>
                                                                        </span>
                                                                    )}
                                                                
                                                                {hasError && (
                                                                    <span className="bg-rose-600 text-white text-[8px] font-bold px-1.5 py-0.5 rounded flex items-center gap-0.5 shadow-[0_0_8px_rgba(225,29,72,0.4)]">
                                                                        <Icon name="alert-triangle" size={8}/> LỖI: {s.tabSwitches}
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </div>
 
                                                    <div>
                                                        <div className="flex justify-between items-end text-[10px] font-semibold text-slate-400 mb-1.5">
                                                            <span>{completed}/{totalQ} CÂU</span>
                                                            <span className="text-amber-400 font-bold">ĐIỂM: {score.toFixed(2).replace(/\.00$/, '')}</span>
                                                            <span>{percent}%</span>
                                                        </div>
                                                        {/* Thanh Tiến Độ Bóng Lên (Glossy/Shimmer) */}
                                                        <div className="h-2 w-full bg-[#1e2436] rounded-full overflow-hidden shadow-inner border border-white/5 relative">
                                                            <div 
                                                                className={`h-full transition-all duration-500 relative ${!isOnline ? 'bg-slate-500' : (hasError ? 'bg-rose-500 shadow-[0_0_10px_#f43f5e]' : 'bg-gradient-to-r from-blue-600 via-blue-400 to-cyan-400 shadow-[0_0_12px_rgba(56,189,248,0.8)]')}`} 
                                                                style={{ width: `${percent}%` }}
                                                            >
                                                                <div className="absolute top-0 inset-x-0 h-[40%] bg-white/30 rounded-t-full"></div>
                                                                {!hasError && isOnline && <div className="absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/40 to-transparent animate-[shimmer_2s_infinite]"></div>}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            )
                                        })}
                                    </div>
                                )}
                            </div>

                            {/* CỘT PHẢI: ĐÃ NỘP BÀI */}
                            <div className="w-full md:w-[320px] flex flex-col shrink-0 border-l border-[#2a3143] md:pl-6 h-full">
                                <h2 className="text-sm font-bold text-emerald-500 uppercase tracking-wider mb-4">
                                    Đã nộp bài ({historyData.length})
                                </h2>
                                <div className="flex-1 overflow-y-auto custom-scrollbar space-y-2 pr-1">
                                    {historyData.map((h, idx) => (
                                        <div key={idx} className="bg-[#1c2130] p-3.5 rounded-xl flex items-center justify-between border border-[#2a3143] hover:border-emerald-500/50 transition-colors group">
                                            <div className="min-w-0 flex-1">
                                                <h4 className="text-[11px] font-bold text-slate-200 uppercase truncate pr-2 mb-1.5">{h.name}</h4>
                                                <div className="flex items-center gap-2">
                                                    <span className="text-[9px] font-semibold text-blue-400 bg-blue-500/10 px-1.5 py-0.5 rounded border border-blue-500/20">{h.class || 'N/A'}</span>
                                                    <span className="text-[9px] font-medium text-slate-500 flex items-center gap-1"><Icon name="clock" size={8}/> {formatDuration(h.startTime, h.endTime)}</span>
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-3 shrink-0">
                                                <div className={`text-sm font-bold ${parseFloat(h.score) >= 5 ? 'text-emerald-400' : 'text-rose-400'}`}>{parseFloat(h.score).toFixed(2).replace(/\.00$/, '')}</div>
                                                <button 
                                                    onClick={() => setViewingRecord(h)}
                                                    className="w-7 h-7 rounded-lg bg-blue-500/20 text-blue-400 hover:bg-blue-500 hover:text-white flex items-center justify-center transition-colors border border-blue-500/30"
                                                    title="Xem chi tiết"
                                                >
                                                    <Icon name="eye" size={14}/>
                                                </button>
                                            </div>
                                        </div>
                                    ))}
                                    {historyData.length === 0 && (
                                        <div className="py-20 text-center text-slate-600 font-bold uppercase text-xs tracking-widest">
                                            Chưa có bài nộp
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            );
        };

        const HistoryModal = ({ exam, onClose, showConfirm, showAlert }) => {
            const [history, setHistory] = useState([]);
            const [viewing, setViewing] = useState(null);
            const [showStats, setShowStats] = useState(false);
            const [selectedClass, setSelectedClass] = useState('ALL');
            const [searchQuery, setSearchQuery] = useState('');

            const loadHistory = () => {
                fetch(`?action=get_history&examId=${exam.id}`).then(r=>r.json()).then(d => setHistory(Array.isArray(d) ? d : []));
            };

            useEffect(() => { loadHistory(); }, [exam.id]);

            const classList = React.useMemo(() => {
                const set = new Set();
                history.forEach(h => {
                    const c = (h.class || '').trim();
                    if (c) set.add(c);
                });
                return Array.from(set).sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));
            }, [history]);

            const classCounts = React.useMemo(() => {
                const counts = {};
                history.forEach(h => {
                    const c = (h.class || '').trim();
                    if (c) counts[c] = (counts[c] || 0) + 1;
                });
                return counts;
            }, [history]);

            const emptyClassCount = React.useMemo(() => {
                return history.filter(h => !(h.class || '').trim()).length;
            }, [history]);
            const hasEmptyClass = emptyClassCount > 0;

            const filteredHistory = React.useMemo(() => {
                return history.filter(h => {
                    const c = (h.class || '').trim();
                    const matchClass = (selectedClass === 'ALL')
                        ? true
                        : (selectedClass === '__NONE__')
                            ? !c
                            : (c === selectedClass);
                    if (!matchClass) return false;

                    if (searchQuery.trim()) {
                        const q = removeVietnameseTones(searchQuery).trim().toLowerCase();
                        const name = removeVietnameseTones(h.name || '').toLowerCase();
                        if (!name.includes(q)) return false;
                    }
                    return true;
                });
            }, [history, selectedClass, searchQuery]);

            const deleteRecord = (record) => {
                showConfirm(`Xóa bài làm của học sinh ${record.name}?\nHọc sinh sẽ được làm lại bài này.`, () => {
                    fetch('?action=delete_history', {
                        method: 'POST',
                        body: JSON.stringify({ examId: record.examId, startTime: record.startTime })
                    }).then(r => r.json()).then(res => {
                        if(res.success) loadHistory();
                    });
                });
            };

            const recalculateScores = () => {
                showConfirm("Hệ thống sẽ chấm lại điểm cho toàn bộ học sinh dựa trên đáp án MỚI NHẤT của đề.\nBạn có chắc chắn muốn thực hiện?", () => {
                    fetch('?action=recalculate_scores', {
                        method: 'POST',
                        body: JSON.stringify({ examId: exam.id })
                    }).then(r => r.json()).then(res => {
                        if(res.success) {
                            loadHistory();
                            if(showAlert) showAlert("Cập nhật điểm hàng loạt thành công!");
                        }
                    });
                });
            };

            const formatTime = (ts) => {
                if (!ts) return '-';
                const d = new Date(ts);
                return d.toLocaleTimeString('vi-VN', {hour: '2-digit', minute:'2-digit'}) + ' ' + d.toLocaleDateString('vi-VN');
            };
            
            const formatDuration = (start, end) => {
                if (!start || !end) return '-';
                const diff = Math.floor((end - start) / 1000);
                const m = Math.floor(diff / 60);
                const s = diff % 60;
                return `${m}p ${s}s`;
            };

            const exportExcel = () => {
                const headers = ['Họ và đệm', 'Tên', 'Lớp', 'Thời gian bắt đầu', 'Thời gian nộp bài', 'Thời gian làm bài', 'Điểm số', 'Vi phạm (Lần chuyển tab)'];
                const dataToExport = filteredHistory;
                const rows = dataToExport.map(h => {
                    const fullName = (h.name || '').trim();
                    const parts = fullName.split(' ');
                    const firstName = parts.length > 1 ? parts.pop() : fullName;
                    const lastName = parts.length > 0 ? parts.join(' ') : '';
                    
                    return [
                        `"${lastName}"`, 
                        `"${firstName}"`, 
                        `"${h.class || ''}"`, 
                        `"${formatTime(h.startTime)}"`,
                        `"${formatTime(h.endTime)}"`,
                        `"${formatDuration(h.startTime, h.endTime)}"`,
                        `"${h.score}"`, 
                        `"${h.tabSwitches}"`
                    ];
                });
                const csvContent = "\uFEFF" + [headers, ...rows].map(e => e.join(",")).join("\n");
                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const link = document.createElement("a");
                link.setAttribute("href", url);
                const classSuffix = selectedClass !== 'ALL' ? (selectedClass === '__NONE__' ? '_Khac' : `_Lop_${selectedClass}`) : '';
                link.setAttribute("download", `Ket_Qua_${exam.title.replace(/\s+/g, '_')}${classSuffix}.csv`);
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            };

            const validScores = filteredHistory.map(h => parseFloat(h.score) || 0);
            const avg = validScores.length ? (validScores.reduce((a,b)=>a+b,0)/validScores.length).toFixed(2) : 0;
            const max = validScores.length ? Math.max(...validScores) : 0;
            const pass = validScores.filter(s => s >= 5).length;
            const passRate = validScores.length ? Math.round((pass/validScores.length)*100) : 0;

            const ranges = ["0-<1", "1-<2", "2-<3", "3-<4", "4-<5", "5-<6", "6-<7", "7-<8", "8-<9", "9-10"];
            const counts = Array(10).fill(0);
            validScores.forEach(s => {
                if (s >= 9) counts[9]++;
                else counts[Math.floor(s)]++;
            });
            const maxCount = Math.max(...counts, 1);

            if (viewing) {
                const conf = exam.config;
                return (
                    <div className="fixed inset-0 z-50 bg-slate-950/60 backdrop-blur-md flex items-center justify-center p-4 md:p-6">
                        <div className="bg-slate-50 w-full max-w-5xl h-[90vh] rounded-[2rem] shadow-2xl flex flex-col overflow-hidden border border-slate-200">
                            <header className="p-4 lg:p-6 bg-white border-b flex justify-between items-center shrink-0">
                                <div>
                                    <h2 className="text-lg lg:text-xl font-black uppercase text-blue-900 flex items-center flex-wrap gap-2">
                                        Bài làm: {viewing.name}
                                        {viewing.tabSwitches > 0 && <span className="bg-rose-500 text-white text-[10px] px-2 py-1 rounded-md tracking-widest shadow-sm">VI PHẠM: {viewing.tabSwitches} LẦN</span>}
                                    </h2>
                                    <div className="flex flex-wrap gap-2 lg:gap-4 mt-2">
                                        <span className="bg-blue-50 text-blue-600 px-3 py-1 rounded-full text-xs font-bold">Lớp: {viewing.class}</span>
                                        <span className="bg-orange-50 text-orange-600 px-3 py-1 rounded-full text-xs font-bold flex items-center gap-1"><Icon name="clock" size={12}/> {formatDuration(viewing.startTime, viewing.endTime)}</span>
                                        <span className={`px-3 py-1 rounded-full text-xs font-black ${parseFloat(viewing.score)>=5?'bg-emerald-50 text-emerald-600':'bg-rose-50 text-rose-600'}`}>Điểm: {viewing.score}</span>
                                    </div>
                                </div>
                                <button onClick={() => setViewing(null)} className="w-10 h-10 flex shrink-0 items-center justify-center bg-rose-500 text-white hover:bg-rose-600 rounded-full transition-colors shadow-md"><Icon name="x" size={20}/></button>
                            </header>
                            <div className="flex-1 overflow-y-auto p-4 md:p-8 custom-scrollbar space-y-6 lg:space-y-8">
                                
                                {conf?.p1 > 0 && (
                                    <div className="bg-white p-4 lg:p-8 rounded-[1.5rem] shadow-sm border border-slate-100">
                                        <h3 className="font-black text-blue-900 text-sm lg:text-base uppercase tracking-widest mb-4 flex items-center gap-2"><Icon name="check-circle" className="text-emerald-500"/> Phần I ({exam?.scoring?.p1 || 0.25}đ / câu)</h3>
                                        <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
                                            {Array.from({length: conf.p1}, (_, i)=>i+1).map(n => {
                                                const uA = viewing.userAnswers?.p1?.[n]; 
                                                const cA = exam.correctAnswers?.p1?.[n]; 
                                                const isC = (String(uA) === String(cA));
                                                return (
                                                    <div key={n} className={`p-3 border rounded-xl text-sm flex justify-between items-center transition-all ${isC ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-rose-50 border-rose-200 text-rose-700'}`}>
                                                        <span className="font-bold opacity-60 text-xs">Câu {n}</span>
                                                        <span className="font-black text-lg">{uA || '-'}</span>
                                                    </div>
                                                )
                                            })}
                                        </div>
                                    </div>
                                )}

                                {conf?.p2 > 0 && (
                                    <div className="bg-white p-4 lg:p-8 rounded-[1.5rem] shadow-sm border border-slate-100">
                                        <h3 className="font-black text-blue-900 text-sm lg:text-base uppercase tracking-widest mb-4 flex items-center gap-2"><Icon name="list-checks" className="text-cyan-500"/> Phần II (Đúng/Sai)</h3>
                                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                                            {Array.from({length: conf.p2}, (_, i)=>i+1).map(n => (
                                                <div key={n} className="border border-slate-100 p-4 rounded-2xl bg-slate-50/50 overflow-visible">
                                                    <span className="font-black text-sm text-cyan-600 mb-3 block uppercase">Câu {n}</span>
                                                    <div className="space-y-2 overflow-visible">
                                                        {['a','b','c','d'].map(s => {
                                                            const uA = viewing.userAnswers?.p2?.[n]?.[s]; 
                                                            const cA = exam.correctAnswers?.p2?.[n]?.[s]; 
                                                            const isC = uA !== undefined && uA === cA;
                                                            return (
                                                                <div key={s} className={`text-xs p-2.5 rounded-lg flex justify-between items-center border overflow-visible ${isC ? 'bg-emerald-100 border-emerald-200 text-emerald-700' : 'bg-rose-100 border-rose-200 text-rose-700'}`}>
                                                                    <span className="font-bold">{s}) {uA === true ? 'ĐÚNG' : (uA === false ? 'SAI' : '-')}</span>
                                                                    {!isC && <span className="font-bold opacity-60 bg-white/50 px-2 py-0.5 rounded">Đ/A: {cA ? 'Đ' : 'S'}</span>}
                                                                </div>
                                                            )
                                                        })}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {conf?.p3 > 0 && (
                                    <div className="bg-white p-4 lg:p-8 rounded-[1.5rem] shadow-sm border border-slate-100">
                                        <h3 className="font-black text-blue-900 text-sm lg:text-base uppercase tracking-widest mb-4 flex items-center gap-2"><Icon name="edit-3" className="text-orange-500"/> Phần III ({exam?.scoring?.p3 || 0.5}đ / câu)</h3>
                                        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                                            {Array.from({length: conf.p3}, (_, i)=>i+1).map(n => {
                                                let uA = (viewing.userAnswers?.p3?.[n] || '').toString().trim().toLowerCase().replace(/[ ,$]/g, '').replace(',', '.');
                                                let cA = (exam.correctAnswers?.p3?.[n] || '').toString().trim().toLowerCase().replace(/[ ,$]/g, '').replace(',', '.'); 
                                                const isC = uA !== '' && uA === cA;
                                                return (
                                                    <div key={n} className={`p-4 border rounded-2xl flex flex-col gap-2 ${isC ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-rose-50 border-rose-200 text-rose-700'}`}>
                                                        <div className="flex justify-between items-center">
                                                            <span className="font-bold opacity-60 text-xs uppercase">Câu {n}</span>
                                                            {!isC && <span className="font-bold text-[10px] bg-rose-200 text-rose-800 px-2 py-0.5 rounded-md">Đ/A: {exam.correctAnswers?.p3?.[n]}</span>}
                                                        </div>
                                                        <span className="font-black text-lg truncate" title={viewing.userAnswers?.p3?.[n]}>{viewing.userAnswers?.p3?.[n] || '-'}</span>
                                                    </div>
                                                )
                                            })}
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                );
            }

            return (
                <div className="fixed inset-0 z-40 bg-slate-950/60 backdrop-blur-sm flex items-center justify-center p-4 md:p-6">
                    <div className="bg-white w-full max-w-6xl h-[90vh] rounded-[2rem] shadow-2xl flex flex-col overflow-hidden">
                        <header className="p-5 lg:p-8 border-b flex justify-between items-center bg-white shrink-0">
                            <div>
                                <h2 className="text-lg lg:text-2xl font-black uppercase text-blue-900 tracking-tight">Lịch sử làm bài</h2>
                                <p className="text-xs lg:text-sm font-bold text-slate-500 mt-1 truncate max-w-[200px] lg:max-w-md">{exam.title}</p>
                            </div>
                            <div className="flex items-center gap-2 lg:gap-4">
                                <button onClick={() => setShowStats(!showStats)} className="hidden md:flex bg-gradient-to-r from-indigo-500 to-purple-500 text-white px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest hover:shadow-lg hover:shadow-indigo-500/30 transition-all items-center gap-2">
                                    <Icon name={showStats ? "list" : "bar-chart-2"} size={16}/> {showStats ? 'Danh Sách' : 'Thống Kê'}
                                </button>
                                <button onClick={recalculateScores} className="hidden md:flex bg-gradient-to-r from-amber-500 to-orange-500 text-white px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest hover:shadow-lg hover:shadow-orange-500/30 transition-all items-center gap-2">
                                    <Icon name="refresh-cw" size={16}/> Cập nhật điểm
                                </button>
                                <button onClick={exportExcel} className="hidden md:flex bg-gradient-to-r from-emerald-500 to-teal-500 text-white px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest hover:shadow-lg hover:shadow-emerald-500/30 transition-all items-center gap-2">
                                    <Icon name="download" size={16}/> Xuất Excel
                                </button>
                                <button onClick={onClose} className="w-10 h-10 flex shrink-0 items-center justify-center bg-rose-500 text-white hover:bg-rose-600 rounded-full transition-colors shadow-md"><Icon name="x" size={24}/></button>
                            </div>
                        </header>
                        <div className="flex-1 overflow-y-auto p-4 md:p-8 custom-scrollbar bg-slate-50/50">
                            
                            <div className="flex gap-2 mb-4 md:hidden flex-wrap">
                                <button onClick={() => setShowStats(!showStats)} className="flex-1 min-w-[100px] bg-gradient-to-r from-indigo-500 to-purple-500 text-white py-3 rounded-xl text-xs font-black uppercase tracking-widest hover:shadow-lg transition-all flex justify-center items-center gap-2">
                                    <Icon name={showStats ? "list" : "bar-chart-2"} size={16}/> {showStats ? 'Danh Sách' : 'Thống Kê'}
                                </button>
                                <button onClick={recalculateScores} className="flex-1 min-w-[100px] bg-gradient-to-r from-amber-500 to-orange-500 text-white py-3 rounded-xl text-xs font-black uppercase tracking-widest hover:shadow-lg transition-all flex justify-center items-center gap-2">
                                    <Icon name="refresh-cw" size={16}/> Chấm lại
                                </button>
                                <button onClick={exportExcel} className="flex-1 min-w-[100px] bg-gradient-to-r from-emerald-500 to-teal-500 text-white py-3 rounded-xl text-xs font-black uppercase tracking-widest hover:shadow-lg transition-all flex justify-center items-center gap-2">
                                    <Icon name="download" size={16}/> Xuất Excel
                                </button>
                            </div>

                            {/* Thanh công cụ lọc lớp & tìm kiếm */}
                            {history.length > 0 && (
                                <div className="bg-white p-3 md:p-4 rounded-2xl border border-slate-200/80 shadow-sm mb-4 flex flex-col md:flex-row md:items-center justify-between gap-3">
                                    {/* Bộ lọc theo lớp */}
                                    <div className="flex items-center gap-2 flex-wrap min-w-0">
                                        <div className="flex items-center gap-1.5 text-xs font-black text-slate-500 uppercase tracking-wider mr-1 shrink-0">
                                            <Icon name="filter" size={14} className="text-blue-600" />
                                            <span>Lọc theo lớp:</span>
                                        </div>
                                        <div className="flex items-center gap-1.5 flex-wrap">
                                            <button
                                                type="button"
                                                onClick={() => setSelectedClass('ALL')}
                                                className={`px-3.5 py-1.5 rounded-xl text-xs font-black transition-all cursor-pointer ${
                                                    selectedClass === 'ALL'
                                                        ? 'bg-blue-600 text-white shadow-md shadow-blue-500/25 ring-2 ring-blue-600/30'
                                                        : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                                                }`}
                                            >
                                                Tất cả ({history.length})
                                            </button>
                                            {classList.map(cls => {
                                                const count = classCounts[cls] || 0;
                                                const isSelected = selectedClass === cls;
                                                return (
                                                    <button
                                                        key={cls}
                                                        type="button"
                                                        onClick={() => setSelectedClass(cls)}
                                                        className={`px-3.5 py-1.5 rounded-xl text-xs font-black transition-all cursor-pointer ${
                                                            isSelected
                                                                ? 'bg-blue-600 text-white shadow-md shadow-blue-500/25 ring-2 ring-blue-600/30'
                                                                : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                                                        }`}
                                                    >
                                                        {cls} ({count})
                                                    </button>
                                                );
                                            })}
                                            {hasEmptyClass && (
                                                <button
                                                    type="button"
                                                    onClick={() => setSelectedClass('__NONE__')}
                                                    className={`px-3.5 py-1.5 rounded-xl text-xs font-black transition-all cursor-pointer ${
                                                        selectedClass === '__NONE__'
                                                            ? 'bg-blue-600 text-white shadow-md shadow-blue-500/25 ring-2 ring-blue-600/30'
                                                            : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                                                    }`}
                                                >
                                                    Khác ({emptyClassCount})
                                                </button>
                                            )}
                                        </div>
                                    </div>

                                    {/* Ô tìm kiếm học sinh */}
                                    <div className="relative w-full md:w-64 shrink-0">
                                        <input 
                                            type="text" 
                                            placeholder="Tìm theo tên học sinh..."
                                            value={searchQuery}
                                            onChange={e => setSearchQuery(e.target.value)}
                                            className="w-full pl-8 pr-7 py-1.5 text-xs font-bold bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none transition-all text-slate-800"
                                        />
                                        <Icon name="search" size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400" />
                                        {searchQuery && (
                                            <button type="button" onClick={() => setSearchQuery('')} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">
                                                <Icon name="x" size={12} />
                                            </button>
                                        )}
                                    </div>
                                </div>
                            )}

                            {showStats ? (
                                <div className="flex flex-col h-full animate-in fade-in zoom-in-95 duration-300">
                                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                                        <div className="bg-white border border-slate-200 p-4 rounded-2xl shadow-sm flex flex-col items-center justify-center text-center">
                                            <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">
                                                Tổng Số Bài {selectedClass !== 'ALL' ? `(${selectedClass === '__NONE__' ? 'Khác' : selectedClass})` : ''}
                                            </p>
                                            <p className="text-3xl font-black text-indigo-600">{filteredHistory.length}</p>
                                        </div>
                                        <div className="bg-white border border-slate-200 p-4 rounded-2xl shadow-sm flex flex-col items-center justify-center text-center">
                                            <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Điểm Trung Bình</p>
                                            <p className="text-3xl font-black text-blue-600">{avg}</p>
                                        </div>
                                        <div className="bg-white border border-slate-200 p-4 rounded-2xl shadow-sm flex flex-col items-center justify-center text-center">
                                            <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Điểm Cao Nhất</p>
                                            <p className="text-3xl font-black text-emerald-600">{max}</p>
                                        </div>
                                        <div className="bg-white border border-slate-200 p-4 rounded-2xl shadow-sm flex flex-col items-center justify-center text-center">
                                            <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Tỉ Lệ Đạt (≥ 5)</p>
                                            <p className="text-3xl font-black text-orange-500">{passRate}%</p>
                                        </div>
                                    </div>
                                    
                                    <div className="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex-1 flex flex-col min-h-[350px]">
                                        <h3 className="font-black text-slate-700 uppercase tracking-widest mb-8 flex items-center gap-2"><Icon name="bar-chart-2" size={18} className="text-indigo-500"/> Phổ điểm thi</h3>
                                        <div className="flex-1 flex items-end gap-1.5 sm:gap-4 relative pt-10 px-2 sm:px-4">
                                            <div className="absolute inset-x-4 inset-y-10 flex flex-col justify-between pointer-events-none opacity-20">
                                                <div className="border-b border-dashed border-slate-400 w-full flex-1"></div>
                                                <div className="border-b border-dashed border-slate-400 w-full flex-1"></div>
                                                <div className="border-b border-dashed border-slate-400 w-full flex-1"></div>
                                            </div>
                                            
                                            {counts.map((count, i) => (
                                                <div key={i} className="flex-1 flex flex-col items-center group relative h-full justify-end z-10">
                                                    <div className="absolute -top-10 bg-indigo-900/90 text-white text-[10px] font-black px-2.5 py-1.5 rounded shadow-lg opacity-0 group-hover:opacity-100 transition-opacity backdrop-blur-sm pointer-events-none mb-2 whitespace-nowrap">
                                                        {count} Học sinh
                                                        <div className="absolute -bottom-1 left-1/2 -translate-x-1/2 w-2 h-2 bg-indigo-900/90 rotate-45"></div>
                                                    </div>
                                                    <div className="w-full bg-gradient-to-t from-indigo-500 to-purple-400 rounded-t-md transition-all duration-500 hover:from-indigo-400 hover:to-purple-300 relative overflow-hidden shadow-sm" style={{height: `${(count/maxCount)*100}%`, minHeight: count > 0 ? '4px' : '0'}}>
                                                        <div className="absolute inset-0 bg-white/20 w-full h-full transform -skew-x-12 -translate-x-full group-hover:animate-[shimmer_1s_infinite]"></div>
                                                    </div>
                                                    <div className="mt-3 text-[9px] sm:text-[11px] font-bold text-slate-500 whitespace-nowrap">{ranges[i]}</div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                <div className="bg-white rounded-[1.5rem] border border-slate-200 shadow-sm overflow-hidden animate-in fade-in zoom-in-95 duration-300">
                                    <div className="overflow-x-auto w-full">
                                        <table className="w-full text-left min-w-[700px]">
                                            <thead>
                                                <tr className="bg-slate-50/80 border-b border-slate-200">
                                                    <th className="py-4 px-5 text-[11px] font-black text-slate-400 uppercase tracking-widest">Học sinh</th>
                                                    <th className="py-4 px-5 text-[11px] font-black text-slate-400 uppercase tracking-widest">Lớp</th>
                                                    <th className="py-4 px-5 text-[11px] font-black text-slate-400 uppercase tracking-widest">Thời gian</th>
                                                    <th className="py-4 px-5 text-[11px] font-black text-slate-400 uppercase tracking-widest text-center">Vi phạm</th>
                                                    <th className="py-4 px-5 text-[11px] font-black text-slate-400 uppercase tracking-widest text-center">Điểm số</th>
                                                    <th className="py-4 px-5 text-[11px] font-black text-slate-400 uppercase tracking-widest text-right">Chi tiết</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-100">
                                                {filteredHistory.map((h, i) => (
                                                    <tr key={i} className="hover:bg-blue-50/50 transition-colors group">
                                                        <td className="py-3 px-5">
                                                            <p className="font-black text-slate-800 text-sm uppercase">{h.name}</p>
                                                        </td>
                                                        <td className="py-3 px-5">
                                                            <span className="font-bold text-slate-600 bg-slate-100 px-3 py-1.5 rounded-lg text-xs">{h.class || '-'}</span>
                                                        </td>
                                                        <td className="py-3 px-5 text-xs font-medium text-slate-600">
                                                            <div className="flex flex-col gap-1">
                                                                <span className="flex items-center gap-1.5 text-emerald-600"><Icon name="log-in" size={12}/> {formatTime(h.startTime)}</span>
                                                                <span className="flex items-center gap-1.5 text-slate-400"><Icon name="log-out" size={12}/> {formatTime(h.endTime)}</span>
                                                                <span className="flex items-center gap-1.5 font-bold text-blue-500 mt-1"><Icon name="clock" size={12}/> {formatDuration(h.startTime, h.endTime)}</span>
                                                            </div>
                                                        </td>
                                                        <td className="py-3 px-5 text-center">
                                                            {h.tabSwitches > 0 ? (
                                                                <span className="inline-flex items-center gap-1 bg-rose-50 text-rose-600 px-2 py-1 rounded-md text-xs font-bold border border-rose-100"><Icon name="alert-triangle" size={12}/> {h.tabSwitches} lần</span>
                                                            ) : (
                                                                <span className="inline-flex items-center gap-1 bg-emerald-50 text-emerald-600 px-2 py-1 rounded-md text-xs font-bold border border-emerald-100"><Icon name="check" size={12}/> An toàn</span>
                                                            )}
                                                        </td>
                                                        <td className="py-3 px-5 text-center">
                                                            <span className={`font-black text-xl ${parseFloat(h.score) >= 5 ? 'text-emerald-500' : 'text-rose-500'}`}>{h.score}</span>
                                                        </td>
                                                        <td className="py-3 px-5 text-right">
                                                            <div className="flex items-center justify-end gap-2">
                                                                <button onClick={() => setViewing(h)} className="bg-white border border-slate-200 text-slate-500 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase hover:border-blue-500 hover:text-blue-600 hover:bg-blue-50 transition-all inline-flex items-center gap-1.5 shadow-sm">
                                                                    <Icon name="eye" size={14}/> Xem
                                                                </button>
                                                                <button onClick={() => deleteRecord(h)} className="w-8 h-8 flex items-center justify-center bg-rose-50 text-rose-500 rounded-lg hover:bg-rose-500 hover:text-white transition-all shadow-md border border-rose-200" title="Xóa bài làm (HS sẽ được thi lại)">
                                                                    <Icon name="trash-2" size={14}/>
                                                                </button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ))}
                                                {filteredHistory.length === 0 && (
                                                    <tr>
                                                        <td colSpan="6" className="py-16 text-center text-slate-400 font-medium italic">
                                                            {history.length === 0 
                                                                ? 'Chưa có học sinh nào nộp bài.' 
                                                                : `Không tìm thấy học sinh nào ${selectedClass !== 'ALL' ? `thuộc lớp ${selectedClass === '__NONE__' ? 'Khác' : selectedClass}` : ''} ${searchQuery ? `phù hợp với "${searchQuery}"` : ''}.`}
                                                        </td>
                                                    </tr>
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )
        };

        const ScoringModal = ({ exam, onClose, loadExams, showAlert }) => {
            const defaultScoring = { p1: 0.25, p2: { '4': 1.0, '3': 0.5, '2': 0.25, '1': 0.1 }, p3: 0.5 };
            const [form, setForm] = useState(exam.scoring || defaultScoring);
            const [isSaving, setIsSaving] = useState(false);

            const handleSave = async (e) => {
                e.preventDefault();
                setIsSaving(true);
                const r = await fetch('?action=save_scoring', { method: 'POST', body: JSON.stringify({ id: exam.id, scoring: form }) });
                const res = await r.json();
                setIsSaving(false);
                if (res.success) {
                    showAlert("Cập nhật điểm thành công!");
                    loadExams();
                    onClose();
                } else showAlert("Lỗi khi lưu điểm!");
            };

            return (
                <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
                    <form onSubmit={handleSave} className="bg-white w-full max-w-sm rounded-[2rem] shadow-2xl flex flex-col overflow-hidden animate-in zoom-in duration-300">
                        <header className="p-5 border-b flex justify-between items-center bg-slate-50 shrink-0">
                            <div className="flex items-center gap-3">
                                <div className="p-2 bg-amber-100 text-amber-600 rounded-lg"><Icon name="star" size={18}/></div>
                                <h2 className="text-base font-black uppercase tracking-widest text-slate-800">Cài đặt Điểm số</h2>
                            </div>
                            <button type="button" onClick={onClose} className="w-8 h-8 flex items-center justify-center bg-rose-500 text-white hover:bg-rose-600 rounded-full transition-colors shadow-md"><Icon name="x" size={18}/></button>
                        </header>
                        <div className="p-6 space-y-5">
                            <div>
                                <label className="block text-[11px] font-black text-emerald-500 uppercase tracking-widest mb-1.5 ml-1">Phần I (Điểm/Câu)</label>
                                <input type="number" step="0.01" value={form.p1} onChange={e=>setForm({...form, p1: e.target.value})} className="w-full bg-slate-50 border border-slate-200 p-3 rounded-xl font-bold outline-none focus:ring-2 focus:ring-amber-500 text-sm" required />
                            </div>
                            <div className="border-t border-slate-100 pt-4">
                                <label className="block text-[11px] font-black text-blue-500 uppercase tracking-widest mb-2 ml-1">Phần II (Cấu trúc 4 ý)</label>
                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <span className="text-[10px] text-slate-400 font-bold mb-1 block">Đúng 4 ý</span>
                                        <input type="number" step="0.01" value={form.p2['4']} onChange={e=>setForm({...form, p2:{...form.p2, '4': e.target.value}})} className="w-full bg-slate-50 border border-slate-200 p-2 rounded-lg font-bold outline-none focus:ring-2 focus:ring-amber-500 text-sm text-center" required />
                                    </div>
                                    <div>
                                        <span className="text-[10px] text-slate-400 font-bold mb-1 block">Đúng 3 ý</span>
                                        <input type="number" step="0.01" value={form.p2['3']} onChange={e=>setForm({...form, p2:{...form.p2, '3': e.target.value}})} className="w-full bg-slate-50 border border-slate-200 p-2 rounded-lg font-bold outline-none focus:ring-2 focus:ring-amber-500 text-sm text-center" required />
                                    </div>
                                    <div>
                                        <span className="text-[10px] text-slate-400 font-bold mb-1 block">Đúng 2 ý</span>
                                        <input type="number" step="0.01" value={form.p2['2']} onChange={e=>setForm({...form, p2:{...form.p2, '2': e.target.value}})} className="w-full bg-slate-50 border border-slate-200 p-2 rounded-lg font-bold outline-none focus:ring-2 focus:ring-amber-500 text-sm text-center" required />
                                    </div>
                                    <div>
                                        <span className="text-[10px] text-slate-400 font-bold mb-1 block">Đúng 1 ý</span>
                                        <input type="number" step="0.01" value={form.p2['1']} onChange={e=>setForm({...form, p2:{...form.p2, '1': e.target.value}})} className="w-full bg-slate-50 border border-slate-200 p-2 rounded-lg font-bold outline-none focus:ring-2 focus:ring-amber-500 text-sm text-center" required />
                                    </div>
                                </div>
                            </div>
                            <div className="border-t border-slate-100 pt-4">
                                <label className="block text-[11px] font-black text-orange-500 uppercase tracking-widest mb-1.5 ml-1">Phần III (Điểm/Câu)</label>
                                <input type="number" step="0.01" value={form.p3} onChange={e=>setForm({...form, p3: e.target.value})} className="w-full bg-slate-50 border border-slate-200 p-3 rounded-xl font-bold outline-none focus:ring-2 focus:ring-amber-500 text-sm" required />
                            </div>
                        </div>
                        <div className="p-5 border-t flex justify-end gap-3 bg-slate-50 shrink-0">
                            <button type="button" onClick={onClose} className="px-6 py-2.5 font-bold text-xs uppercase tracking-widest text-slate-500 hover:text-slate-800 transition-colors">HỦY</button>
                            <button type="submit" disabled={isSaving} className="bg-amber-500 text-white px-8 py-2.5 rounded-xl font-black uppercase tracking-widest shadow-md hover:bg-amber-400 transition-colors flex items-center justify-center gap-2 text-xs w-full justify-center">
                                {isSaving ? <Icon name="loader" className="animate-spin" size={16}/> : <Icon name="save" size={16}/>} LƯU ĐIỂM
                            </button>
                        </div>
                    </form>
                </div>
            );
        };

        const ScheduleModal = ({ exam, onClose, loadExams, showAlert }) => {
            const formatForInput = (isoString) => {
                if (!isoString) return '';
                const date = new Date(isoString);
                const tzOffset = date.getTimezoneOffset() * 60000;
                return (new Date(date.getTime() - tzOffset)).toISOString().slice(0, 16);
            };

            const [form, setForm] = useState({
                publishStartTime: formatForInput(exam.publishStartTime),
                publishEndTime: formatForInput(exam.publishEndTime)
            });
            const [isSaving, setIsSaving] = useState(false);

            const handleSave = async (e) => {
                e.preventDefault();
                setIsSaving(true);
                
                const startT = form.publishStartTime ? new Date(form.publishStartTime).toISOString() : '';
                const endT = form.publishEndTime ? new Date(form.publishEndTime).toISOString() : '';

                await fetch('?action=toggle_publish', { method: 'POST', body: JSON.stringify({ id: exam.id, forceState: true }) });
                
                const r = await fetch('?action=save_schedule', { method: 'POST', body: JSON.stringify({ id: exam.id, publishStartTime: startT, publishEndTime: endT }) });
                const res = await r.json();
                setIsSaving(false);
                if (res.success) {
                    showAlert("Cập nhật hẹn giờ thành công!");
                    loadExams();
                    onClose();
                } else showAlert("Lỗi khi lưu!");
            };

            return (
                <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
                    <form onSubmit={handleSave} className="bg-white w-full max-w-sm rounded-[2rem] shadow-2xl flex flex-col overflow-hidden animate-in zoom-in duration-300">
                        <header className="p-5 border-b flex justify-between items-center bg-slate-50 shrink-0">
                            <div className="flex items-center gap-3">
                                <div className="p-2 bg-cyan-100 text-cyan-600 rounded-lg"><Icon name="calendar" size={18}/></div>
                                <h2 className="text-base font-black uppercase tracking-widest text-slate-800">Hẹn giờ xuất bản</h2>
                            </div>
                            <button type="button" onClick={onClose} className="w-8 h-8 flex items-center justify-center bg-rose-500 text-white hover:bg-rose-600 rounded-full transition-colors shadow-md"><Icon name="x" size={18}/></button>
                        </header>
                        <div className="p-6 space-y-5">
                            <div className="bg-amber-50/50 p-4 rounded-2xl border border-amber-100">
                                <label className="block text-[11px] font-black text-amber-600 uppercase tracking-widest mb-2 flex items-center gap-1.5"><Icon name="play-circle" size={14}/> Thời gian Mở Đề</label>
                                <input type="datetime-local" value={form.publishStartTime} onChange={e=>setForm({...form, publishStartTime: e.target.value})} className="w-full bg-white border border-amber-200 p-3 rounded-xl font-bold text-amber-700 outline-none focus:ring-2 focus:ring-amber-500 text-sm shadow-sm" />
                                <p className="text-[10px] text-slate-400 mt-2 font-medium">Bỏ trống nếu muốn mở đề ngay lập tức.</p>
                            </div>
                            <div className="bg-rose-50/50 p-4 rounded-2xl border border-rose-100">
                                <label className="block text-[11px] font-black text-rose-500 uppercase tracking-widest mb-2 flex items-center gap-1.5"><Icon name="stop-circle" size={14}/> Thời gian Đóng Đề</label>
                                <input type="datetime-local" value={form.publishEndTime} onChange={e=>setForm({...form, publishEndTime: e.target.value})} className="w-full bg-white border border-rose-200 p-3 rounded-xl font-bold text-rose-700 outline-none focus:ring-2 focus:ring-rose-500 text-sm shadow-sm" />
                                <p className="text-[10px] text-slate-400 mt-2 font-medium">Bỏ trống nếu không muốn tự động đóng đề.</p>
                            </div>
                        </div>
                        <div className="p-5 border-t flex justify-end gap-3 bg-slate-50 shrink-0">
                            <button type="button" onClick={onClose} className="px-6 py-2.5 font-bold text-xs uppercase tracking-widest text-slate-500 hover:text-slate-800 transition-colors">HỦY</button>
                            <button type="submit" disabled={isSaving} className="bg-cyan-600 text-white px-8 py-2.5 rounded-xl font-black uppercase tracking-widest shadow-md hover:bg-cyan-500 transition-colors flex items-center justify-center gap-2 text-xs w-full justify-center">
                                {isSaving ? <Icon name="loader" className="animate-spin" size={16}/> : <Icon name="save" size={16}/>} LƯU HẸN GIỜ
                            </button>
                        </div>
                    </form>
                </div>
            );
        };
       const DocumentManagerModal = ({ onClose, showAlert, showDangerConfirm, showConfirm }) => {
            const [documents, setDocuments] = useState([]);
            const [isUploading, setIsUploading] = useState(false);
            const [selectedFileName, setSelectedFileName] = useState("");
            const [filterGrade, setFilterGrade] = useState('all');
            const [uploadGrade, setUploadGrade] = useState('12'); 
            
            // Khai báo thêm State chỉnh sửa tên
            const [editingDocId, setEditingDocId] = useState(null);
            const [editDocTitle, setEditDocTitle] = useState("");
            const [promptDialog, setPromptDialog] = useState({ isOpen: false, defaultValue: '', documentId: null });

            const getCategoryName = (catId) => {
                for (const parent of Object.values(CATEGORY_TREE)) {
                    if (parent.items[catId]) return parent.items[catId];
                }
                return catId;
            };

            const loadDocs = () => {
                fetch('?action=list_documents&t=' + Date.now())
                    .then(r => r.json())
                    .then(d => setDocuments(Array.isArray(d) ? d : []));
            };

            useEffect(() => { loadDocs(); }, []);

            const handleUpload = (e) => {
                e.preventDefault();
                setIsUploading(true);
                const formData = new FormData(e.target);
                
                fetch('?action=upload_document', { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(res => {
                        setIsUploading(false);
                        if (res.success) {
                            e.target.reset();
                            setSelectedFileName("");
                            loadDocs();
                            showConfirm("Tải tài liệu lên thành công!\n\nBạn có muốn tạo thông báo trên trang chủ học sinh cho tài liệu này không?", () => {
                                setPromptDialog({ isOpen: true, defaultValue: res.title || "", documentId: res.id });
                            });
                        } else {
                            if (res.log) {
                                alert(res.message + "\n\nChi tiết lỗi log:\n" + res.log.substring(0, 1000) + (res.log.length > 1000 ? "\n..." : ""));
                            } else {
                                showAlert(res.message || "Lỗi tải lên!");
                            }
                        }
                    });
            };

            const togglePublish = (id) => {
                fetch('?action=toggle_document', { method: 'POST', body: JSON.stringify({ id }) })
                    .then(r => r.json())
                    .then(res => { if (res.success) loadDocs(); });
            };

            const deleteDoc = (id, title) => {
                showDangerConfirm(`Xóa vĩnh viễn tài liệu "${title}"?`, () => {
                    fetch(`?action=delete_document&id=${id}&t=${Date.now()}`)
                        .then(r => r.json())
                        .then(res => { if (res.success) loadDocs(); });
                });
            };

            // THÊM HÀM LƯU TÊN TÀI LIỆU
            const saveDocTitle = (id) => {
                if (!editDocTitle.trim()) {
                    showAlert("Tên tài liệu không được để trống!"); return;
                }
                fetch('?action=edit_document_title', {
                    method: 'POST',
                    body: JSON.stringify({ id, title: editDocTitle.trim() })
                }).then(r => r.json()).then(res => {
                    if (res.success) {
                        setEditingDocId(null);
                        loadDocs();
                    } else {
                        showAlert("Lỗi khi đổi tên tài liệu!");
                    }
                });
            };

            const filteredDocs = filterGrade === 'all' ? documents : documents.filter(d => (d.grade || '12') === filterGrade);

            return (
                <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
                    <div className="bg-white w-full max-w-5xl h-[85vh] rounded-[2rem] shadow-2xl flex flex-col overflow-hidden animate-in zoom-in duration-300">
                        <header className="p-5 border-b flex justify-between items-center bg-slate-50 shrink-0">
                            <div className="flex items-center gap-3">
                                <div className="p-2 bg-fuchsia-100 text-fuchsia-600 rounded-lg"><Icon name="file-text" size={18}/></div>
                                <div>
                                    <h2 className="text-base font-black uppercase tracking-widest text-slate-800">Quản lý Tài liệu PDF</h2>
                                    <p className="text-[10px] font-bold text-slate-400 mt-0.5">Tải lên & điều khiển hiển thị tài liệu theo giai đoạn</p>
                                </div>
                            </div>
                            <button type="button" onClick={onClose} className="w-8 h-8 flex items-center justify-center bg-rose-500 text-white hover:bg-rose-600 rounded-full transition-colors shadow-md"><Icon name="x" size={18}/></button>
                        </header>
                        
                        <div className="flex-1 flex flex-col md:flex-row overflow-hidden">
                            {/* CỘT UPLOAD */}
                            <div className="w-full md:w-[35%] bg-slate-50 p-5 border-r border-slate-200 shrink-0 overflow-y-auto custom-scrollbar">
                                <h3 className="font-black text-sm text-fuchsia-700 uppercase tracking-widest mb-4 flex items-center gap-2"><Icon name="upload-cloud" size={16}/> Tải lên mới</h3>
                                <form onSubmit={handleUpload} className="flex flex-col gap-4">
                                    <div>
                                        <label className="block text-[11px] font-bold text-slate-500 uppercase mb-1.5 ml-1">Tên tài liệu</label>
                                        <input name="title" placeholder="VD: Đề cương HK1..." className="w-full bg-white border border-slate-300 p-3 rounded-xl font-bold text-slate-700 outline-none focus:ring-2 focus:ring-fuchsia-500 text-sm" required />
                                    </div>
                                    <div className="flex gap-2">
                                        <div className="flex-1">
                                            <label className="block text-[11px] font-bold text-slate-500 uppercase mb-1.5 ml-1">Khối</label>
                                            <select name="grade" value={uploadGrade} onChange={(e) => setUploadGrade(e.target.value)} className="w-full bg-white border border-slate-300 p-3 rounded-xl font-bold text-slate-700 outline-none focus:ring-2 focus:ring-fuchsia-500 text-sm cursor-pointer">
                                                <option value="12">Khối 12</option>
                                                <option value="11">Khối 11</option>
                                                <option value="10">Khối 10</option>
                                                <option value="tot_nghiep">Tốt Nghiệp</option>
                                            </select>
                                        </div>
                                        <div className="flex-[1.5]">
                                            <label className="block text-[11px] font-bold text-slate-500 uppercase mb-1.5 ml-1">Giai đoạn</label>
                                            <select name="category" className="w-full bg-white border border-slate-300 p-3 rounded-xl font-bold text-slate-700 outline-none focus:ring-2 focus:ring-fuchsia-500 text-sm cursor-pointer">
                                                {Object.entries(CATEGORY_TREE)
                                                    .filter(([k, v]) => uploadGrade === 'tot_nghiep' ? k === 'on_thi_tn' : k !== 'on_thi_tn')
                                                    .map(([parentKey, parentVal]) => (
                                                    <optgroup key={parentKey} label={parentVal.label}>
                                                        {Object.entries(parentVal.items).map(([childKey, childName]) => (
                                                            <option key={childKey} value={childKey}>{childName}</option>
                                                        ))}
                                                    </optgroup>
                                                ))}
                                            </select>
                                        </div>
                                    </div>
                                    <div>
                                        <label className={`w-full h-24 border-2 border-dashed rounded-xl flex flex-col items-center justify-center gap-1 cursor-pointer transition-colors ${selectedFileName ? 'border-emerald-400 bg-emerald-50 text-emerald-600' : 'border-slate-300 bg-white text-slate-500 hover:border-fuchsia-400 hover:bg-fuchsia-50'}`}>
                                            <Icon name={selectedFileName ? "check-circle" : "file"} size={24}/>
                                            <span className="text-xs font-bold truncate max-w-[200px] px-2">{selectedFileName || 'Chọn File PDF hoặc .tex'}</span>
                                            <input type="file" name="file" accept=".pdf,.tex" className="hidden" required onChange={(e) => setSelectedFileName(e.target.files[0]?.name || "")} />
                                        </label>
                                    </div>
                                    <button type="submit" disabled={isUploading || !selectedFileName} className="mt-2 bg-gradient-to-r from-fuchsia-600 to-purple-600 text-white p-3 rounded-xl font-black uppercase tracking-widest shadow-md hover:shadow-lg transition-all disabled:opacity-50 flex items-center justify-center gap-2 text-xs">
                                        {isUploading ? <Icon name="loader" className="animate-spin" size={14}/> : <Icon name="plus" size={14}/>} {isUploading ? 'ĐANG TẢI...' : 'THÊM TÀI LIỆU'}
                                    </button>
                                </form>

                                {/* HƯỚNG DẪN SỬ DỤNG TÀI LIỆU */}
                                <div className="mt-6 bg-fuchsia-50 border border-fuchsia-200 rounded-2xl p-4 text-xs text-fuchsia-800 space-y-2">
                                    <h4 className="font-black uppercase tracking-wider flex items-center gap-1.5 text-fuchsia-900">
                                        <Icon name="help-circle" size={14}/> Hướng dẫn Tài liệu
                                    </h4>
                                    <ul className="list-disc list-inside space-y-1.5 text-[11px] text-fuchsia-700 font-medium leading-relaxed">
                                        <li>
                                            <strong className="text-fuchsia-900">Định dạng file:</strong> Hỗ trợ tệp tin định dạng <strong className="text-fuchsia-900">.pdf</strong> hoặc <strong className="text-fuchsia-900">.tex</strong> (hệ thống tự động biên dịch .tex thành PDF).
                                        </li>
                                        <li>
                                            <strong className="text-fuchsia-900">Mặc định ẩn:</strong> Tài liệu mới tải lên sẽ có trạng thái <strong className="text-fuchsia-900">Đang Ẩn</strong> để bạn có thời gian chuẩn bị. Hãy nhấn nút <strong className="text-fuchsia-900">Đang ẩn -> Đang mở</strong> để hiển thị cho học sinh.
                                        </li>
                                        <li>
                                            <strong className="text-fuchsia-900">Copy Link gửi nhanh:</strong> Nhấn nút <strong className="text-fuchsia-900">Copy Link</strong> ở danh sách bên phải để sao chép đường dẫn trực tiếp của tài liệu. Bạn có thể gửi link này qua Zalo, Messenger hoặc chèn vào đề thi.
                                        </li>
                                        <li>
                                            <strong className="text-fuchsia-900">Thông báo trang chủ:</strong> Sau khi tải lên thành công, hệ thống hỗ trợ tạo nhanh thông báo trên bảng tin học sinh để thông báo về tài liệu mới này.
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            {/* CỘT HIỂN THỊ DANH SÁCH */}
                            <div className="flex-1 flex flex-col overflow-hidden bg-white">
                                <div className="p-4 border-b border-slate-100 flex gap-2 overflow-x-auto custom-scrollbar shrink-0">
                                    <button onClick={() => setFilterGrade('all')} className={`px-4 py-1.5 rounded-lg text-[10px] font-black tracking-widest uppercase border transition-colors shrink-0 ${filterGrade === 'all' ? 'bg-slate-800 text-white border-slate-800' : 'bg-slate-50 text-slate-500 hover:bg-slate-100 border-slate-200'}`}>Tất cả</button>
                                    <button onClick={() => setFilterGrade('12')} className={`px-4 py-1.5 rounded-lg text-[10px] font-black tracking-widest uppercase border transition-colors shrink-0 ${filterGrade === '12' ? 'bg-indigo-600 text-white border-indigo-600 shadow-md shadow-indigo-600/10' : 'bg-slate-50 text-slate-500 hover:bg-slate-100 border-slate-200'}`}>Khối 12</button>
                                    <button onClick={() => setFilterGrade('11')} className={`px-4 py-1.5 rounded-lg text-[10px] font-black tracking-widest uppercase border transition-colors shrink-0 ${filterGrade === '11' ? 'bg-emerald-600 text-white border-emerald-600 shadow-md shadow-emerald-600/10' : 'bg-slate-50 text-slate-500 hover:bg-slate-100 border-slate-200'}`}>Khối 11</button>
                                    <button onClick={() => setFilterGrade('10')} className={`px-4 py-1.5 rounded-lg text-[10px] font-black tracking-widest uppercase border transition-colors shrink-0 ${filterGrade === '10' ? 'bg-blue-600 text-white border-blue-600 shadow-md shadow-blue-600/10' : 'bg-slate-50 text-slate-500 hover:bg-slate-100 border-slate-200'}`}>Khối 10</button>
                                    <button onClick={() => setFilterGrade('tot_nghiep')} className={`px-4 py-1.5 rounded-lg text-[10px] font-black tracking-widest uppercase border transition-colors shrink-0 ${filterGrade === 'tot_nghiep' ? 'bg-rose-600 text-white border-rose-600 shadow-md shadow-rose-600/10' : 'bg-slate-50 text-slate-500 hover:bg-slate-100 border-slate-200'}`}>Tốt Nghiệp</button>
                                </div>
                                <div className="flex-1 p-4 overflow-y-auto custom-scrollbar">
                                    <div className="grid grid-cols-1 gap-3">
                                        {filteredDocs.map((doc) => (
                                            <div key={doc.id} className={`border p-4 rounded-xl flex flex-col sm:flex-row sm:items-center justify-between gap-4 transition-all ${doc.isPublished ? 'border-slate-200 bg-white hover:border-fuchsia-300 shadow-sm' : 'border-slate-200 bg-slate-50 opacity-70'}`}>
                                                <div className="flex items-start gap-3 overflow-hidden">
                                                    <div className={`p-2.5 rounded-lg shrink-0 ${doc.isPublished ? 'bg-rose-100 text-rose-600' : 'bg-slate-200 text-slate-400'}`}>
                                                        <Icon name="file-text" size={20}/>
                                                    </div>
                                                    <div className="min-w-0 flex-1">
                                                        {editingDocId === doc.id ? (
                                                            <div className="flex items-center gap-2 mb-1">
                                                                <input autoFocus value={editDocTitle} onChange={e => setEditDocTitle(e.target.value)} onKeyDown={e => {if(e.key==='Enter') saveDocTitle(doc.id);}} className="text-sm font-bold text-slate-700 bg-white border border-fuchsia-300 px-2 py-1 rounded outline-none focus:ring-2 focus:ring-fuchsia-500 w-full max-w-[250px]" />
                                                                <button onClick={() => saveDocTitle(doc.id)} className="p-1.5 bg-emerald-100 text-emerald-600 rounded hover:bg-emerald-500 hover:text-white transition-colors" title="Lưu"><Icon name="check" size={14}/></button>
                                                                <button onClick={() => setEditingDocId(null)} className="p-1.5 bg-rose-100 text-rose-600 rounded hover:bg-rose-500 hover:text-white transition-colors" title="Hủy"><Icon name="x" size={14}/></button>
                                                            </div>
                                                        ) : (
                                                            <h4 className="font-black text-sm text-slate-800 truncate flex items-center gap-2 group/title" title={doc.title}>
                                                                {doc.title}
                                                                <button onClick={() => { setEditingDocId(doc.id); setEditDocTitle(doc.title); }} className="opacity-0 group-hover/title:opacity-100 p-1 text-slate-400 hover:text-fuchsia-600 hover:bg-fuchsia-50 rounded transition-all" title="Đổi tên">
                                                                    <Icon name="edit-3" size={14}/>
                                                                </button>
                                                            </h4>
                                                        )}
                                                        <div className="flex flex-wrap items-center gap-2 mt-1">
                                                            <span className="bg-slate-100 text-slate-600 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-widest">Khối {doc.grade === 'tot_nghiep' ? 'TN' : doc.grade}</span>
                                                            <span className="bg-fuchsia-50 text-fuchsia-600 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-widest">{getCategoryName(doc.category)}</span>
                                                            <span className="text-[10px] font-bold text-slate-400 flex items-center gap-1"><Icon name="hard-drive" size={10}/> {doc.size}</span>
                                                            <span className="text-[10px] font-bold text-slate-400 flex items-center gap-1"><Icon name="calendar" size={10}/> {doc.createdAt}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2 shrink-0 border-t sm:border-t-0 pt-3 sm:pt-0 border-slate-100">
    {/* NÚT COPY LINK TÀI LIỆU */}
    {/* NÚT COPY LINK TÀI LIỆU (ĐÃ NÂNG CẤP COPY XUYÊN HTTP) */}
     {/* NÚT 1: COPY LINK TÀI LIỆU (ĐÃ NÂNG CẤP COPY XUYÊN HTTP) */}
    <button 
        onClick={() => {
            const link = window.location.origin + window.location.pathname.replace(/[^/]*$/, '') + 'index.php?doc_id=' + doc.id;
            
            // Dùng cách copy cổ điển để lách luật HTTPS của trình duyệt
            const textArea = document.createElement("textarea");
            textArea.value = link;
            textArea.style.position = "fixed"; // Ẩn khỏi màn hình
            textArea.style.opacity = "0";
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            
            try {
                document.execCommand('copy');
                showAlert("✅ Đã copy link tài liệu:\n" + link);
            } catch (err) {
                showAlert("⚠️ Vẫn bị trình duyệt chặn, bạn copy tay link này nhé:\n" + link);
            }
            
            document.body.removeChild(textArea);
        }} 
        className="px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest flex items-center gap-1.5 transition-colors bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white border border-blue-200"
        title="Copy link gửi học sinh"
    >
        <Icon name="link" size={14}/> Copy Link
    </button>

    {/* NÚT 2: ẨN / HIỆN TÀI LIỆU */}
    <button onClick={() => togglePublish(doc.id)} className={`px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest flex items-center gap-1.5 transition-colors ${doc.isPublished ? 'bg-emerald-50 text-emerald-600 hover:bg-emerald-500 hover:text-white border border-emerald-200' : 'bg-slate-200 text-slate-600 hover:bg-emerald-500 hover:text-white border border-slate-300'}`}>
        <Icon name={doc.isPublished ? "eye" : "eye-off"} size={14}/> {doc.isPublished ? 'Đang Mở' : 'Đang Ẩn'}
    </button>

    {/* NÚT 3: XÓA TÀI LIỆU */}
    <button onClick={() => deleteDoc(doc.id, doc.title)} className="w-8 h-8 flex items-center justify-center bg-rose-50 text-rose-500 hover:bg-rose-500 hover:text-white rounded-lg transition-colors border border-rose-200">
        <Icon name="trash-2" size={14}/>
    </button>
</div> 
                                            </div>
                                        ))}
                                        {filteredDocs.length === 0 && (
                                            <div className="py-10 text-center flex flex-col items-center gap-2 text-slate-400 opacity-60">
                                                <Icon name="file" size={32} />
                                                <p className="text-sm font-bold">Không có tài liệu nào thuộc khối này</p>
                                            </div>
                                        )}
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    {/* Custom Prompt Dialog */}
                    {promptDialog.isOpen && (
                        <div className="fixed inset-0 z-[110] flex items-center justify-center p-4 animate-in fade-in duration-200">
                            <div className="absolute inset-0 bg-slate-950/60 backdrop-blur-sm" onClick={() => setPromptDialog({ ...promptDialog, isOpen: false })}></div>
                            <div className="relative bg-white rounded-[2rem] shadow-2xl w-full max-w-md overflow-hidden border border-slate-100 flex flex-col animate-in zoom-in-95 duration-200">
                                <header className="p-5 border-b border-slate-100 bg-slate-50 flex items-center gap-3 shrink-0">
                                    <div className="p-2 bg-indigo-100 text-indigo-600 rounded-xl">
                                        <Icon name="edit-3" size={16}/>
                                    </div>
                                    <h3 className="text-sm font-black uppercase tracking-widest text-slate-800">Tạo thông báo</h3>
                                </header>
                                <form onSubmit={(e) => {
                                    e.preventDefault();
                                    const val = e.target.inputVal.value;
                                    if (val && val.trim() !== "") {
                                        fetch('?action=add_notification', {
                                            method: 'POST',
                                            headers: { 'Content-Type': 'application/json' },
                                            body: JSON.stringify({ title: val, documentId: promptDialog.documentId })
                                        })
                                        .then(r => r.json())
                                        .then(nRes => {
                                            if (nRes.success) {
                                                showAlert("Đã tạo thông báo trên trang chủ!");
                                            } else {
                                                showAlert("Lỗi tạo thông báo!");
                                            }
                                        });
                                    }
                                    setPromptDialog({ ...promptDialog, isOpen: false });
                                }} className="p-6 space-y-6">
                                    <div>
                                        <label className="block text-[10px] font-black text-slate-400 uppercase tracking-wider mb-2 ml-1">Nhập tiêu đề thông báo:</label>
                                        <input 
                                            name="inputVal" 
                                            type="text"
                                            defaultValue={promptDialog.defaultValue} 
                                            placeholder="Nhập tiêu đề thông báo..." 
                                            required
                                            className="w-full bg-slate-50 px-4 py-3 rounded-xl border border-slate-200 focus:bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none font-bold text-slate-800 text-sm transition-all shadow-inner"
                                        />
                                    </div>
                                    <div className="flex gap-3 pt-2">
                                        <button type="button" onClick={() => setPromptDialog({ ...promptDialog, isOpen: false })} className="flex-1 py-3 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl font-bold text-xs uppercase tracking-wider transition-all">Hủy</button>
                                        <button type="submit" className="flex-1 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold text-xs uppercase tracking-wider transition-all shadow-md shadow-indigo-600/10">Đồng ý</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    )}
                </div>
            </div>
            );
        };

       const VideoManagerModal = ({ onClose, showAlert, showDangerConfirm, showConfirm }) => {
            const [videos, setVideos] = useState([]);
            const [isAdding, setIsAdding] = useState(false);
            const [filterGrade, setFilterGrade] = useState('all');
            const [uploadGrade, setUploadGrade] = useState('12'); 
            
            const [editingVidId, setEditingVidId] = useState(null);
            const [editVidTitle, setEditVidTitle] = useState("");
            const [promptDialog, setPromptDialog] = useState({ isOpen: false, defaultValue: '', videoId: null });

            const getCategoryName = (catId) => {
                for (const parent of Object.values(CATEGORY_TREE)) {
                    if (parent.items[catId]) return parent.items[catId];
                }
                return catId;
            };

            const loadVideos = () => {
                fetch('?action=list_videos&t=' + Date.now())
                    .then(r => r.json())
                    .then(d => setVideos(Array.isArray(d) ? d : []));
            };

            useEffect(() => { loadVideos(); }, []);

            const handleAdd = (e) => {
                e.preventDefault();
                setIsAdding(true);
                const formData = new FormData(e.target);
                const payload = {};
                formData.forEach((v, k) => payload[k] = v);
                
                fetch('?action=add_video', { 
                    method: 'POST', 
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload) 
                })
                .then(r => r.json())
                .then(res => {
                    setIsAdding(false);
                    if (res.success) {
                        e.target.reset();
                        loadVideos();
                        showConfirm("Thêm video thành công!\n\nBạn có muốn tạo thông báo trên trang chủ học sinh cho video này không?", () => {
                            setPromptDialog({ isOpen: true, defaultValue: res.title || "", videoId: res.id });
                        });
                    } else {
                        showAlert(res.message || "Lỗi khi thêm video!");
                    }
                });
            };

            const togglePublish = (id) => {
                fetch('?action=toggle_video', { method: 'POST', body: JSON.stringify({ id }) })
                    .then(r => r.json())
                    .then(res => { if (res.success) loadVideos(); });
            };

            const deleteVid = (id, title) => {
                showDangerConfirm(`Xóa vĩnh viễn video "${title}"?`, () => {
                    fetch(`?action=delete_video&id=${id}&t=${Date.now()}`)
                        .then(r => r.json())
                        .then(res => { if (res.success) loadVideos(); });
                });
            };

            const saveVidTitle = (id) => {
                if (!editVidTitle.trim()) {
                    showAlert("Tiêu đề video không được để trống!"); return;
                }
                fetch('?action=edit_video_title', {
                    method: 'POST',
                    body: JSON.stringify({ id, title: editVidTitle.trim() })
                }).then(r => r.json()).then(res => {
                    if (res.success) {
                        setEditingVidId(null);
                        loadVideos();
                    } else {
                        showAlert("Lỗi khi đổi tên video!");
                    }
                });
            };

            const filteredVideos = filterGrade === 'all' ? videos : videos.filter(v => (v.grade || '12') === filterGrade);

            return (
                <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
                    <div className="bg-white w-full max-w-5xl h-[85vh] rounded-[2rem] shadow-2xl flex flex-col overflow-hidden animate-in zoom-in duration-300">
                        <header className="p-5 border-b flex justify-between items-center bg-slate-50 shrink-0">
                            <div className="flex items-center gap-3">
                                <div className="p-2 bg-pink-100 text-pink-600 rounded-lg"><Icon name="video" size={18}/></div>
                                <div>
                                    <h2 className="text-base font-black uppercase tracking-widest text-slate-800">Quản lý Video Bài giảng</h2>
                                    <p className="text-[10px] font-bold text-slate-400 mt-0.5">Thêm đường dẫn YouTube & phân loại theo giai đoạn</p>
                                </div>
                            </div>
                            <button type="button" onClick={onClose} className="w-8 h-8 flex items-center justify-center bg-rose-500 text-white hover:bg-rose-600 rounded-full transition-colors shadow-md"><Icon name="x" size={18}/></button>
                        </header>
                        
                        <div className="flex-1 flex flex-col md:flex-row overflow-hidden">
                            {/* CỘT THÊM LINK VIDEO */}
                            <div className="w-full md:w-[35%] bg-slate-50 p-5 border-r border-slate-200 shrink-0 overflow-y-auto custom-scrollbar">
                                <h3 className="font-black text-sm text-pink-700 uppercase tracking-widest mb-4 flex items-center gap-2"><Icon name="plus" size={16}/> Thêm video mới</h3>
                                <form onSubmit={handleAdd} className="flex flex-col gap-4">
                                    <div>
                                        <label className="block text-[11px] font-bold text-slate-500 uppercase mb-1.5 ml-1">Tiêu đề video</label>
                                        <input name="title" placeholder="VD: Ôn tập chương 1..." className="w-full bg-white border border-slate-300 p-3 rounded-xl font-bold text-slate-700 outline-none focus:ring-2 focus:ring-pink-500 text-sm" required />
                                    </div>
                                    <div>
                                        <label className="block text-[11px] font-bold text-slate-500 uppercase mb-1.5 ml-1">Đường dẫn YouTube</label>
                                        <input name="videoUrl" placeholder="VD: https://www.youtube.com/watch?v=..." className="w-full bg-white border border-slate-300 p-3 rounded-xl font-bold text-slate-700 outline-none focus:ring-2 focus:ring-pink-500 text-sm" required />
                                    </div>
                                    <div className="flex gap-2">
                                        <div className="flex-1">
                                            <label className="block text-[11px] font-bold text-slate-500 uppercase mb-1.5 ml-1">Khối</label>
                                            <select name="grade" value={uploadGrade} onChange={(e) => setUploadGrade(e.target.value)} className="w-full bg-white border border-slate-300 p-3 rounded-xl font-bold text-slate-700 outline-none focus:ring-2 focus:ring-pink-500 text-sm cursor-pointer">
                                                <option value="12">Khối 12</option>
                                                <option value="11">Khối 11</option>
                                                <option value="10">Khối 10</option>
                                                <option value="tot_nghiep">Tốt Nghiệp</option>
                                            </select>
                                        </div>
                                        <div className="flex-[1.5]">
                                            <label className="block text-[11px] font-bold text-slate-500 uppercase mb-1.5 ml-1">Giai đoạn</label>
                                            <select name="category" className="w-full bg-white border border-slate-300 p-3 rounded-xl font-bold text-slate-700 outline-none focus:ring-2 focus:ring-pink-500 text-sm cursor-pointer">
                                                {Object.entries(CATEGORY_TREE)
                                                    .filter(([k, v]) => uploadGrade === 'tot_nghiep' ? k === 'on_thi_tn' : k !== 'on_thi_tn')
                                                    .map(([parentKey, parentVal]) => (
                                                    <optgroup key={parentKey} label={parentVal.label}>
                                                        {Object.entries(parentVal.items).map(([childKey, childName]) => (
                                                            <option key={childKey} value={childKey}>{childName}</option>
                                                        ))}
                                                    </optgroup>
                                                ))}
                                            </select>
                                        </div>
                                    </div>
                                    <button type="submit" disabled={isAdding} className="w-full mt-2 bg-gradient-to-r from-pink-600 to-rose-500 text-white p-3 rounded-xl font-black uppercase tracking-widest text-[11px] shadow-lg shadow-pink-500/20 hover:shadow-pink-500/40 hover:-translate-y-0.5 transition-all flex items-center justify-center gap-1.5">
                                        {isAdding ? <Icon name="loader" className="animate-spin" size={14}/> : <Icon name="plus" size={14}/>}
                                        {isAdding ? 'Đang thêm...' : 'Thêm video'}
                                    </button>
                                </form>

                                {/* HƯỚNG DẪN SỬ DỤNG VIDEO */}
                                <div className="mt-6 bg-pink-50 border border-pink-200 rounded-2xl p-4 text-xs text-pink-800 space-y-2">
                                    <h4 className="font-black uppercase tracking-wider flex items-center gap-1.5 text-pink-900">
                                        <Icon name="help-circle" size={14}/> Hướng dẫn Thêm Video
                                    </h4>
                                    <ul className="list-disc list-inside space-y-1.5 text-[11px] text-pink-700 font-medium leading-relaxed">
                                        <li>
                                            <strong className="text-pink-900">Đường dẫn YouTube:</strong> Chấp nhận link YouTube chuẩn, ví dụ:
                                            <div className="bg-white/80 p-1 px-2 rounded border border-pink-100 font-mono text-[9px] mt-1 break-all select-all">
                                                https://www.youtube.com/watch?v=dQw4w9WgXcQ
                                            </div>
                                            hoặc link rút gọn:
                                            <div className="bg-white/80 p-1 px-2 rounded border border-pink-100 font-mono text-[9px] mt-1 break-all select-all">
                                                https://youtu.be/dQw4w9WgXcQ
                                            </div>
                                        </li>
                                        <li>
                                            <strong className="text-pink-900">Phân loại & Hiển thị:</strong> Video được gán theo <strong className="text-pink-900">Khối</strong> và <strong className="text-pink-900">Giai đoạn học</strong> để tự động hiển thị trong thư viện video tương ứng của học sinh.
                                        </li>
                                        <li>
                                            <strong className="text-pink-900">Ẩn/Mở:</strong> Click nút <strong className="text-pink-900">Mở/Ẩn</strong> ở danh sách bên phải để bật hoặc tạm tắt hiển thị đối với học sinh.
                                        </li>
                                        <li>
                                            <strong className="text-pink-900">Thông báo tự động:</strong> Sau khi lưu, hệ thống sẽ hỏi bạn có muốn tạo thông báo kèm link video trên trang chủ học sinh hay không.
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            
                            {/* DANH SÁCH VIDEO */}
                            <div className="flex-1 p-5 flex flex-col overflow-hidden bg-slate-50/50">
                                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4 shrink-0">
                                    <h3 className="font-black text-sm text-slate-800 uppercase tracking-widest">Danh sách video ({filteredVideos.length})</h3>
                                    
                                    {/* LỌC THEO KHỐI */}
                                    <div className="flex items-center gap-2">
                                        <span className="text-[10px] font-black uppercase text-slate-400 tracking-wider">Lọc:</span>
                                        <select value={filterGrade} onChange={(e) => setFilterGrade(e.target.value)} className="bg-white border border-slate-200 text-slate-600 px-3 py-1.5 rounded-xl text-[11px] font-bold outline-none focus:ring-2 focus:ring-pink-500 cursor-pointer shadow-sm">
                                            <option value="all">Tất cả Khối</option>
                                            <option value="12">Khối 12</option>
                                            <option value="11">Khối 11</option>
                                            <option value="10">Khối 10</option>
                                            <option value="tot_nghiep">Tốt Nghiệp</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div className="flex-1 overflow-y-auto custom-scrollbar pr-1">
                                    <div className="flex flex-col gap-3">
                                        {filteredVideos.map(vid => (
                                            <div key={vid.id} className="bg-white border border-slate-200 hover:border-pink-300 p-4 rounded-2xl flex items-center justify-between gap-4 transition-all shadow-sm hover:shadow-md group">
                                                <div className="flex-1 min-w-0 flex items-start gap-3">
                                                    <div className="w-10 h-10 bg-rose-50 text-rose-500 rounded-xl flex items-center justify-center shrink-0 border border-rose-100 group-hover:scale-105 transition-transform"><Icon name="video" size={18}/></div>
                                                    <div className="flex-1 min-w-0 space-y-1">
                                                        {editingVidId === vid.id ? (
                                                            <div className="flex items-center gap-2 w-full mt-0.5">
                                                                <input 
                                                                    value={editVidTitle} 
                                                                    onChange={(e) => setEditVidTitle(e.target.value)} 
                                                                    className="flex-1 bg-slate-50 border border-slate-300 px-3 py-1.5 rounded-lg text-xs font-bold text-slate-800 outline-none focus:ring-2 focus:ring-pink-500"
                                                                    required
                                                                    autoFocus
                                                                />
                                                                <button onClick={() => saveVidTitle(vid.id)} className="w-7 h-7 bg-emerald-50 text-emerald-600 rounded-lg flex items-center justify-center hover:bg-emerald-500 hover:text-white transition-colors border border-emerald-200">
                                                                    <Icon name="check" size={14}/>
                                                                </button>
                                                                <button onClick={() => setEditingVidId(null)} className="w-7 h-7 bg-slate-100 text-slate-500 rounded-lg flex items-center justify-center hover:bg-slate-200 transition-colors">
                                                                    <Icon name="x" size={14}/>
                                                                </button>
                                                            </div>
                                                        ) : (
                                                            <div className="flex items-center gap-2">
                                                                <h4 className="font-bold text-slate-800 text-sm truncate cursor-pointer hover:text-pink-600 transition-colors" title="Bấm để chỉnh sửa tên" onClick={() => { setEditingVidId(vid.id); setEditVidTitle(vid.title); }}>
                                                                    {vid.title}
                                                                </h4>
                                                                <button onClick={() => { setEditingVidId(vid.id); setEditVidTitle(vid.title); }} className="opacity-0 group-hover:opacity-100 text-slate-400 hover:text-slate-600 transition-all" title="Đổi tên">
                                                                    <Icon name="edit" size={12}/>
                                                                </button>
                                                            </div>
                                                        )}
                                                        <div className="flex flex-wrap items-center gap-2 text-[10px] font-bold text-slate-400">
                                                            <span className="bg-slate-100 text-slate-500 px-1.5 py-0.5 rounded uppercase tracking-wider">{vid.grade === 'tot_nghiep' ? 'Tốt Nghiệp' : `Khối ${vid.grade}`}</span>
                                                            <span className="w-1 h-1 bg-slate-300 rounded-full"></span>
                                                            <span className="text-slate-500 font-medium">{getCategoryName(vid.category)}</span>
                                                            <span className="w-1 h-1 bg-slate-300 rounded-full"></span>
                                                            <span className="text-slate-500 font-medium">{vid.createdAt}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div className="flex items-center gap-2 shrink-0">
                                                    {/* NÚT 1: COPY LINK GỬI HỌC SINH */}
                                                    <button 
                                                        onClick={() => {
                                                            const link = window.location.origin + window.location.pathname.replace(/[^/]*$/, '') + 'index.php?video_id=' + vid.id;
                                                            
                                                            const textArea = document.createElement("textarea");
                                                            textArea.value = link;
                                                            textArea.style.position = "fixed";
                                                            textArea.style.opacity = "0";
                                                            document.body.appendChild(textArea);
                                                            textArea.focus();
                                                            textArea.select();
                                                            
                                                            try {
                                                                document.execCommand('copy');
                                                                showAlert("✅ Đã copy link video bài giảng:\n" + link);
                                                            } catch (err) {
                                                                showAlert("⚠️ Vẫn bị trình duyệt chặn, bạn copy tay link này nhé:\n" + link);
                                                            }
                                                            
                                                            document.body.removeChild(textArea);
                                                        }} 
                                                        className="px-2.5 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest flex items-center gap-1 transition-colors bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white border border-blue-200"
                                                        title="Copy link gửi học sinh"
                                                    >
                                                        <Icon name="link" size={12}/> Link
                                                    </button>

                                                    {/* NÚT 2: BẬT TẮT XUẤT BẢN */}
                                                    <button onClick={() => togglePublish(vid.id)} className={`px-2.5 py-1.5 rounded-lg border text-[10px] font-black uppercase tracking-widest transition-colors ${vid.isPublished ? 'bg-emerald-50 text-emerald-600 border-emerald-200 hover:bg-emerald-500 hover:text-white' : 'bg-slate-100 text-slate-400 border-slate-200 hover:bg-slate-500 hover:text-white'}`} title={vid.isPublished ? "Đang hiển thị cho học sinh - Click để ẩn" : "Đang ẩn - Click để hiển thị"}>
                                                        {vid.isPublished ? 'Mở' : 'Ẩn'}
                                                    </button>

                                                    {/* NÚT 3: XEM LINK */}
                                                    <a href={vid.videoUrl} target="_blank" rel="noopener noreferrer" className="w-8 h-8 flex items-center justify-center bg-blue-50 text-blue-500 hover:bg-blue-500 hover:text-white rounded-lg transition-colors border border-blue-200" title="Xem trên YouTube">
                                                        <Icon name="external-link" size={14}/>
                                                    </a>

                                                    {/* NÚT 4: XÓA VIDEO */}
                                                    <button onClick={() => deleteVid(vid.id, vid.title)} className="w-8 h-8 flex items-center justify-center bg-rose-50 text-rose-500 hover:bg-rose-500 hover:text-white rounded-lg transition-colors border border-rose-200" title="Xóa video">
                                                        <Icon name="trash-2" size={14}/>
                                                    </button>
                                                </div> 
                                            </div>
                                        ))}
                                        {filteredVideos.length === 0 && (
                                            <div className="py-10 text-center flex flex-col items-center gap-2 text-slate-400 opacity-60">
                                                <Icon name="video" size={32} />
                                                <p className="text-sm font-bold">Không có video bài giảng nào thuộc khối này</p>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    {/* Custom Prompt Dialog */}
                    {promptDialog.isOpen && (
                        <div className="fixed inset-0 z-[110] flex items-center justify-center p-4 animate-in fade-in duration-200">
                            <div className="absolute inset-0 bg-slate-950/60 backdrop-blur-sm" onClick={() => setPromptDialog({ ...promptDialog, isOpen: false })}></div>
                            <div className="relative bg-white rounded-[2rem] shadow-2xl w-full max-w-md overflow-hidden border border-slate-100 flex flex-col animate-in zoom-in-95 duration-200">
                                <header className="p-5 border-b border-slate-100 bg-slate-50 flex items-center gap-3 shrink-0">
                                    <div className="p-2 bg-indigo-100 text-indigo-600 rounded-xl">
                                        <Icon name="edit-3" size={16}/>
                                    </div>
                                    <h3 className="text-sm font-black uppercase tracking-widest text-slate-800">Tạo thông báo</h3>
                                </header>
                                <form onSubmit={(e) => {
                                    e.preventDefault();
                                    const val = e.target.inputVal.value;
                                    if (val && val.trim() !== "") {
                                        fetch('?action=add_notification', {
                                            method: 'POST',
                                            headers: { 'Content-Type': 'application/json' },
                                            body: JSON.stringify({ title: val, videoId: promptDialog.videoId })
                                        })
                                        .then(r => r.json())
                                        .then(nRes => {
                                            if (nRes.success) {
                                                showAlert("Đã tạo thông báo trên trang chủ!");
                                            } else {
                                                showAlert("Lỗi tạo thông báo!");
                                            }
                                        });
                                    }
                                    setPromptDialog({ ...promptDialog, isOpen: false });
                                }} className="p-6 space-y-6">
                                    <div>
                                        <label className="block text-[10px] font-black text-slate-400 uppercase tracking-wider mb-2 ml-1">Nhập tiêu đề thông báo:</label>
                                        <input 
                                            name="inputVal" 
                                            type="text"
                                            defaultValue={promptDialog.defaultValue} 
                                            placeholder="Nhập tiêu đề thông báo..." 
                                            required
                                            className="w-full bg-slate-50 px-4 py-3 rounded-xl border border-slate-200 focus:bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none font-bold text-slate-800 text-sm transition-all shadow-inner"
                                        />
                                    </div>
                                    <div className="flex gap-3 pt-2">
                                        <button type="button" onClick={() => setPromptDialog({ ...promptDialog, isOpen: false })} className="flex-1 py-3 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl font-bold text-xs uppercase tracking-wider transition-all">Hủy</button>
                                        <button type="submit" className="flex-1 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold text-xs uppercase tracking-wider transition-all shadow-md shadow-indigo-600/10">Đồng ý</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    )}
                </div>
            );
        };

        const NotificationManagerModal = ({ onClose, showAlert, showDangerConfirm }) => {
            const [notifs, setNotifs] = useState([]);
            const [title, setTitle] = useState('');
            const [link, setLink] = useState('');
            const [editingNotifId, setEditingNotifId] = useState(null);
            const [editNotifTitle, setEditNotifTitle] = useState('');
            const [isAdding, setIsAdding] = useState(false);
            const [isLoading, setIsLoading] = useState(true);

            const [linkType, setLinkType] = useState('none');
            const [selectedDocId, setSelectedDocId] = useState('');
            const [selectedVideoId, setSelectedVideoId] = useState('');
            const [docsList, setDocsList] = useState([]);
            const [videosList, setVideosList] = useState([]);

            const loadNotifs = async () => {
                setIsLoading(true);
                const r = await fetch('?action=list_notifications&t=' + Date.now());
                const d = await r.json();
                setNotifs(Array.isArray(d) ? d : []);
                setIsLoading(false);
            };

            useEffect(() => { 
                loadNotifs(); 
                fetch('?action=list_documents&t=' + Date.now())
                    .then(r => r.json())
                    .then(d => setDocsList(Array.isArray(d) ? d : []));
                fetch('?action=list_videos&t=' + Date.now())
                    .then(r => r.json())
                    .then(d => setVideosList(Array.isArray(d) ? d : []));
            }, []);

            const handleDocChange = (docId) => {
                setSelectedDocId(docId);
                const doc = docsList.find(d => String(d.id) === String(docId));
                if (doc && !title.trim()) {
                    setTitle(`Tài liệu mới: ${doc.title}`);
                }
            };

            const handleVideoChange = (vidId) => {
                setSelectedVideoId(vidId);
                const vid = videosList.find(v => String(v.id) === String(vidId));
                if (vid && !title.trim()) {
                    setTitle(`Video mới: ${vid.title}`);
                }
            };

            const handleAdd = async (e) => {
                e.preventDefault();
                if (!title.trim()) return showAlert("Vui lòng nhập tiêu đề!");
                
                let docId = '';
                let vidId = '';
                let finalLink = '';
                
                if (linkType === 'doc') {
                    if (!selectedDocId) return showAlert("Vui lòng chọn tài liệu!");
                    docId = selectedDocId;
                } else if (linkType === 'video') {
                    if (!selectedVideoId) return showAlert("Vui lòng chọn video!");
                    vidId = selectedVideoId;
                } else if (linkType === 'url') {
                    if (!link.trim()) return showAlert("Vui lòng nhập đường dẫn liên kết!");
                    finalLink = link;
                }
                
                const r = await fetch('?action=add_notification', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ title, link: finalLink, documentId: docId, videoId: vidId })
                });
                const res = await r.json();
                if (res.success) {
                    showAlert("Thêm thông báo thành công!");
                    setTitle('');
                    setLink('');
                    setSelectedDocId('');
                    setSelectedVideoId('');
                    setLinkType('none');
                    setIsAdding(false);
                    loadNotifs();
                } else {
                    showAlert(res.message || "Lỗi khi thêm thông báo!");
                }
            };

            const toggleNotif = async (id) => {
                const r = await fetch('?action=toggle_notification', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const res = await r.json();
                if (res.success) loadNotifs();
            };

            const deleteNotif = (id, notifTitle) => {
                showDangerConfirm(`Xóa thông báo: "${notifTitle}"?\nHọc sinh sẽ không thấy thông báo này nữa.`, async () => {
                    const r = await fetch(`?action=delete_notification&id=${id}&t=${Date.now()}`);
                    const res = await r.json();
                    if (res.success) loadNotifs();
                });
            };

            const saveNotifTitle = (id) => {
                if (!editNotifTitle.trim()) {
                    showAlert("Tiêu đề thông báo không được để trống!"); return;
                }
                fetch('?action=edit_notification_title', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id, title: editNotifTitle.trim() })
                }).then(r => r.json()).then(res => {
                    if (res.success) {
                        setEditingNotifId(null);
                        loadNotifs();
                    } else {
                        showAlert("Lỗi khi chỉnh sửa tiêu đề thông báo!");
                    }
                });
            };

            return (
                <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
                    <div className="bg-white w-full max-w-4xl h-[80vh] rounded-[2rem] shadow-2xl flex flex-col overflow-hidden animate-in zoom-in duration-300">
                        <header className="p-5 border-b flex justify-between items-center bg-slate-50 shrink-0">
                            <div className="flex items-center gap-3">
                                <div className="p-2 bg-rose-100 text-rose-600 rounded-lg"><Icon name="bell" size={18}/></div>
                                <div>
                                    <h2 className="text-base font-black uppercase tracking-widest text-slate-800">Quản lý Thông báo</h2>
                                    <p className="text-[10px] font-bold text-slate-400 mt-0.5">Đăng thông báo quan trọng lên trang chủ học sinh</p>
                                </div>
                            </div>
                            <button type="button" onClick={onClose} className="w-8 h-8 flex items-center justify-center bg-rose-500 text-white hover:bg-rose-600 rounded-full transition-colors shadow-md"><Icon name="x" size={18}/></button>
                        </header>

                        <div className="flex-1 flex flex-col md:flex-row overflow-hidden">
                            {/* CỘT THÊM MỚI */}
                            <div className="w-full md:w-[35%] bg-slate-50 p-5 border-r border-slate-200 shrink-0 overflow-y-auto custom-scrollbar">
                                <h3 className="font-black text-sm text-rose-700 uppercase tracking-widest mb-4 flex items-center gap-2"><Icon name="plus-circle" size={16}/> Tạo thông báo</h3>
                                <form onSubmit={handleAdd} className="flex flex-col gap-4">
                                    <div>
                                        <label className="block text-[11px] font-bold text-slate-500 uppercase mb-1.5 ml-1">Tiêu đề thông báo</label>
                                        <textarea value={title} onChange={e => setTitle(e.target.value)} placeholder="Nhập tiêu đề hoặc nội dung thông báo ngắn..." className="w-full bg-white border border-slate-300 p-3 rounded-xl font-bold text-slate-700 outline-none focus:ring-2 focus:ring-rose-500 text-sm h-20 resize-none" required />
                                    </div>
                                    
                                    <div>
                                        <label className="block text-[11px] font-bold text-slate-500 uppercase mb-1.5 ml-1">Liên kết tới</label>
                                        <select value={linkType} onChange={e => { setLinkType(e.target.value); setTitle(''); setLink(''); setSelectedDocId(''); setSelectedVideoId(''); }} className="w-full bg-white border border-slate-300 p-3 rounded-xl font-bold text-slate-700 outline-none focus:ring-2 focus:ring-rose-500 text-sm">
                                            <option value="none">Không có liên kết</option>
                                            <option value="doc">Tài liệu PDF đã tải lên</option>
                                            <option value="video">Video bài giảng đã tải lên</option>
                                            <option value="url">Nhập đường dẫn liên kết tự do (https://...)</option>
                                        </select>
                                    </div>

                                    {linkType === 'doc' && (
                                        <div>
                                            <label className="block text-[11px] font-bold text-slate-500 uppercase mb-1.5 ml-1">Chọn tài liệu PDF</label>
                                            <select value={selectedDocId} onChange={e => handleDocChange(e.target.value)} className="w-full bg-white border border-slate-300 p-3 rounded-xl font-bold text-slate-700 outline-none focus:ring-2 focus:ring-rose-500 text-sm" required>
                                                <option value="">-- Chọn tài liệu --</option>
                                                {docsList.map(doc => (
                                                    <option key={doc.id} value={doc.id}>
                                                        [{doc.grade === 'tot_nghiep' ? 'TN' : 'Khối ' + doc.grade}] {doc.title}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                    )}

                                    {linkType === 'video' && (
                                        <div>
                                            <label className="block text-[11px] font-bold text-slate-500 uppercase mb-1.5 ml-1">Chọn video bài giảng</label>
                                            <select value={selectedVideoId} onChange={e => handleVideoChange(e.target.value)} className="w-full bg-white border border-slate-300 p-3 rounded-xl font-bold text-slate-700 outline-none focus:ring-2 focus:ring-rose-500 text-sm" required>
                                                <option value="">-- Chọn video --</option>
                                                {videosList.map(vid => (
                                                    <option key={vid.id} value={vid.id}>
                                                        [{vid.grade === 'tot_nghiep' ? 'TN' : 'Khối ' + vid.grade}] {vid.title}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                    )}

                                    {linkType === 'url' && (
                                        <div>
                                            <label className="block text-[11px] font-bold text-slate-500 uppercase mb-1.5 ml-1">Đường dẫn liên kết</label>
                                            <input value={link} onChange={e => setLink(e.target.value)} placeholder="https://..." className="w-full bg-white border border-slate-300 p-3 rounded-xl font-bold text-slate-700 outline-none focus:ring-2 focus:ring-rose-500 text-sm" required />
                                        </div>
                                    )}

                                    <button type="submit" className="w-full bg-rose-600 text-white p-3 rounded-xl font-black uppercase text-xs tracking-widest shadow-md hover:bg-rose-500 transition-colors flex items-center justify-center gap-1.5 mt-2">
                                        <Icon name="check" size={14}/> Đăng thông báo
                                    </button>
                                </form>

                                {/* HƯỚNG DẪN SỬ DỤNG THÔNG BÁO */}
                                <div className="mt-6 bg-rose-50 border border-rose-200 rounded-2xl p-4 text-xs text-rose-800 space-y-2">
                                    <h4 className="font-black uppercase tracking-wider flex items-center gap-1.5 text-rose-900">
                                        <Icon name="help-circle" size={14}/> Hướng dẫn Thông báo
                                    </h4>
                                    <ul className="list-disc list-inside space-y-1.5 text-[11px] text-rose-700 font-medium leading-relaxed">
                                        <li>
                                            <strong className="text-rose-900">Nội dung thông báo:</strong> Nên viết ngắn gọn, súc tích (dưới 200 chữ) để hiển thị đẹp mắt trên màn hình điện thoại/máy tính của học sinh.
                                        </li>
                                        <li>
                                            <strong className="text-rose-900">Đường dẫn liên kết:</strong> Nếu có link bài thi, link video, link tài liệu hoặc website bên ngoài, hãy dán vào ô <strong className="text-rose-900">Đường dẫn</strong>. Học sinh click vào thông báo sẽ mở link ngay lập tức.
                                        </li>
                                        <li>
                                            <strong className="text-rose-900">Hiện/Ẩn thông báo:</strong> Trạng thái <strong className="text-rose-900">Mở/Ẩn</strong> giúp bạn kiểm soát việc đăng bài. Khi tắt, thông báo vẫn được lưu trong hệ thống nhưng học sinh không nhìn thấy.
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            {/* CỘT DANH SÁCH */}
                            <div className="flex-1 flex flex-col overflow-hidden bg-white">
                                <div className="flex-1 p-4 overflow-y-auto custom-scrollbar">
                                    {isLoading ? (
                                        <div className="py-20 text-center text-slate-400 font-bold text-xs uppercase tracking-widest flex flex-col items-center gap-2"><Icon name="loader" size={24} className="animate-spin"/> Đang tải...</div>
                                    ) : (
                                        <div className="grid grid-cols-1 gap-3">
                                            {notifs.map(n => (
                                                <div key={n.id} className={`border p-4 rounded-xl flex items-center justify-between gap-4 transition-all ${n.isPublished ? 'border-slate-200 bg-white hover:border-rose-300 shadow-sm' : 'border-slate-200 bg-slate-50 opacity-70'}`}>
                                                    <div className="min-w-0 flex-1">
                                                        {editingNotifId === n.id ? (
                                                            <div className="flex items-center gap-2 mb-2">
                                                                <textarea autoFocus value={editNotifTitle} onChange={e => setEditNotifTitle(e.target.value)} onKeyDown={e => {if(e.key==='Enter' && !e.shiftKey) { e.preventDefault(); saveNotifTitle(n.id); }}} className="text-sm font-bold text-slate-700 bg-white border border-rose-300 px-3 py-2 rounded-xl outline-none focus:ring-2 focus:ring-rose-500 w-full resize-none h-16" />
                                                                <button onClick={() => saveNotifTitle(n.id)} className="p-2 bg-emerald-100 text-emerald-600 rounded-lg hover:bg-emerald-500 hover:text-white transition-colors" title="Lưu"><Icon name="check" size={14}/></button>
                                                                <button onClick={() => setEditingNotifId(null)} className="p-2 bg-rose-100 text-rose-600 rounded-lg hover:bg-rose-500 hover:text-white transition-colors" title="Hủy"><Icon name="x" size={14}/></button>
                                                            </div>
                                                        ) : (
                                                            <p className="font-bold text-slate-800 text-sm leading-relaxed whitespace-pre-wrap flex items-start gap-2 group/title">
                                                                {n.title}
                                                                <button onClick={() => { setEditingNotifId(n.id); setEditNotifTitle(n.title); }} className="opacity-0 group-hover/title:opacity-100 p-1 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded transition-all shrink-0 mt-0.5" title="Sửa tiêu đề">
                                                                    <Icon name="edit-3" size={12}/>
                                                                </button>
                                                            </p>
                                                        )}
                                                        <div className="flex flex-wrap items-center gap-2 text-[10px] font-bold text-slate-400 mt-2">
                                                            <span className="text-slate-500 font-medium">{n.createdAt}</span>
                                                            {n.link && (
                                                                <>
                                                                    <span className="w-1 h-1 bg-slate-300 rounded-full"></span>
                                                                    <span className="text-blue-500 font-medium truncate max-w-[200px]" title={n.link}><Icon name="link" size={10} className="inline mr-0.5"/> {n.link}</span>
                                                                </>
                                                            )}
                                                            {n.documentId && (
                                                                <>
                                                                    <span className="w-1 h-1 bg-slate-300 rounded-full"></span>
                                                                    <span className="text-fuchsia-500 font-medium"><Icon name="file-text" size={10} className="inline mr-0.5"/> Đính kèm tài liệu</span>
                                                                </>
                                                            )}
                                                            {n.videoId && (
                                                                <>
                                                                    <span className="w-1 h-1 bg-slate-300 rounded-full"></span>
                                                                    <span className="text-pink-500 font-medium"><Icon name="video" size={10} className="inline mr-0.5"/> Đính kèm video</span>
                                                                </>
                                                            )}
                                                        </div>
                                                    </div>
                                                    <div className="flex items-center gap-2 shrink-0">
                                                        {/* NÚT COPY LINK LIÊN KẾT */}
                                                        <button 
                                                            onClick={() => {
                                                                let link = window.location.origin + window.location.pathname.replace(/[^/]*$/, '') + 'index.php';
                                                                if (n.link) {
                                                                    link = n.link;
                                                                } else if (n.documentId) {
                                                                    link = window.location.origin + window.location.pathname.replace(/[^/]*$/, '') + 'index.php?doc_id=' + n.documentId;
                                                                } else if (n.videoId) {
                                                                    link = window.location.origin + window.location.pathname.replace(/[^/]*$/, '') + 'index.php?video_id=' + n.videoId;
                                                                }
                                                                
                                                                const textArea = document.createElement("textarea");
                                                                textArea.value = link;
                                                                textArea.style.position = "fixed";
                                                                textArea.style.opacity = "0";
                                                                document.body.appendChild(textArea);
                                                                textArea.focus();
                                                                textArea.select();
                                                                
                                                                try {
                                                                    document.execCommand('copy');
                                                                    showAlert("✅ Đã copy link liên kết của thông báo:\n" + link);
                                                                } catch (err) {
                                                                    showAlert("⚠️ Vẫn bị trình duyệt chặn, bạn copy tay link này nhé:\n" + link);
                                                                }
                                                                
                                                                document.body.removeChild(textArea);
                                                            }} 
                                                            className="px-2.5 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest flex items-center gap-1 transition-colors bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white border border-blue-200"
                                                            title="Copy link đính kèm của thông báo"
                                                        >
                                                            <Icon name="link" size={12}/> Link
                                                        </button>

                                                        <button onClick={() => toggleNotif(n.id)} className={`px-2.5 py-1.5 rounded-lg border text-[10px] font-black uppercase tracking-widest transition-colors ${n.isPublished ? 'bg-emerald-50 text-emerald-600 border-emerald-200 hover:bg-emerald-500 hover:text-white' : 'bg-slate-100 text-slate-400 border-slate-200 hover:bg-slate-500 hover:text-white'}`}>
                                                            {n.isPublished ? 'Mở' : 'Ẩn'}
                                                        </button>
                                                        <button 
                                                            onClick={() => { setEditingNotifId(n.id); setEditNotifTitle(n.title); }} 
                                                            className="px-2.5 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest flex items-center gap-1 transition-colors bg-amber-50 text-amber-600 hover:bg-amber-600 hover:text-white border border-amber-200"
                                                            title="Sửa tiêu đề thông báo"
                                                        >
                                                            <Icon name="edit-3" size={12}/> Sửa
                                                        </button>
                                                        <button onClick={() => deleteNotif(n.id, n.title)} className="w-8 h-8 flex items-center justify-center bg-rose-50 text-rose-500 hover:bg-rose-500 hover:text-white rounded-lg transition-colors border border-rose-200">
                                                            <Icon name="trash-2" size={14}/>
                                                        </button>
                                                    </div>
                                                </div>
                                            ))}
                                            {notifs.length === 0 && (
                                                <div className="py-20 text-center flex flex-col items-center gap-2 text-slate-400 opacity-60">
                                                    <Icon name="bell-off" size={32} />
                                                    <p className="text-sm font-bold">Không có thông báo nào</p>
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            );
        };

        const StudentReviewModal = ({ result, onClose }) => {
            const { exam, examTitle, score } = result;
            const answers = result.answers || result.userAnswers;
            const conf = exam?.config || exam?.conf || { p1: 0, p2: 0, p3: 0 };
            
            const formatDuration = (start, end) => {
                if (!start || !end) return '-';
                const diff = Math.floor((end - start) / 1000);
                const m = Math.floor(diff / 60);
                const s = diff % 60;
                return `${m}p ${s}s`;
            };

            return (
                <div className="fixed inset-0 z-[100] bg-slate-950/60 backdrop-blur-md flex items-center justify-center p-4 md:p-6 animate-in fade-in duration-300">
                    <div className="bg-slate-50 w-full max-w-5xl h-[90vh] rounded-[2rem] shadow-2xl flex flex-col overflow-hidden border border-slate-200 animate-in zoom-in-95 duration-200">
                        <header className="p-4 lg:p-6 bg-white border-b flex justify-between items-center shrink-0">
                            <div>
                                <h2 className="text-lg lg:text-xl font-black uppercase text-indigo-900 flex items-center gap-2">
                                    Chi tiết bài làm: {examTitle}
                                </h2>
                                <div className="flex flex-wrap gap-2 lg:gap-4 mt-2">
                                    {result.startTime && result.endTime && (
                                        <span className="bg-orange-50 text-orange-600 px-3 py-1 rounded-full text-xs font-bold flex items-center gap-1">
                                            <Icon name="clock" size={12}/> Thời gian làm: {formatDuration(result.startTime, result.endTime)}
                                        </span>
                                    )}
                                    <span className={`px-3 py-1 rounded-full text-xs font-black ${parseFloat(score)>=5?'bg-emerald-50 text-emerald-600':'bg-rose-50 text-rose-600'}`}>Điểm: {score}</span>
                                </div>
                            </div>
                            <button onClick={onClose} className="w-10 h-10 flex shrink-0 items-center justify-center bg-rose-500 text-white hover:bg-rose-600 rounded-full transition-colors shadow-md"><Icon name="x" size={20}/></button>
                        </header>
                        
                        <div className="flex-1 overflow-y-auto p-4 md:p-8 custom-scrollbar space-y-6 lg:space-y-8">
                            {conf.p1 > 0 && (
                                <div className="bg-white p-4 lg:p-8 rounded-[1.5rem] shadow-sm border border-slate-100">
                                    <h3 className="font-black text-indigo-900 text-sm lg:text-base uppercase tracking-widest mb-4 flex items-center gap-2">
                                        <Icon name="check-circle" className="text-emerald-500"/> Phần I (Trắc nghiệm nhiều lựa chọn)
                                    </h3>
                                    <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
                                        {Array.from({length: conf.p1}, (_, i)=>i+1).map(n => {
                                            const uA = answers?.p1?.[n]; 
                                            const cA = exam.correctAnswers?.p1?.[n]; 
                                            const isC = (String(uA) === String(cA));
                                            return (
                                                <div key={n} className={`p-3 border rounded-xl text-sm flex justify-between items-center transition-all ${isC ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-rose-50 border-rose-200 text-rose-700'}`}>
                                                    <span className="font-bold opacity-60 text-xs">Câu {n}</span>
                                                    <div className="flex items-center gap-1.5">
                                                        <span className="font-black text-lg">{uA || '-'}</span>
                                                        {!isC && <span className="text-[10px] font-bold bg-white/80 border px-1 rounded text-rose-500">Đ/A: {cA}</span>}
                                                    </div>
                                                </div>
                                            )
                                        })}
                                    </div>
                                </div>
                            )}

                            {conf.p2 > 0 && (
                                <div className="bg-white p-4 lg:p-8 rounded-[1.5rem] shadow-sm border border-slate-100">
                                    <h3 className="font-black text-indigo-900 text-sm lg:text-base uppercase tracking-widest mb-4 flex items-center gap-2">
                                        <Icon name="list-checks" className="text-cyan-500"/> Phần II (Đúng/Sai)
                                    </h3>
                                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                                        {Array.from({length: conf.p2}, (_, i)=>i+1).map(n => (
                                            <div key={n} className="border border-slate-100 p-4 rounded-2xl bg-slate-50/50">
                                                <span className="font-black text-sm text-cyan-600 mb-3 block uppercase">Câu {n}</span>
                                                <div className="space-y-2">
                                                    {['a','b','c','d'].map(s => {
                                                        const uA = answers?.p2?.[n]?.[s]; 
                                                        const cA = exam.correctAnswers?.p2?.[n]?.[s]; 
                                                        const isC = uA !== undefined && uA === cA;
                                                        return (
                                                            <div key={s} className={`text-xs p-2.5 rounded-lg flex justify-between items-center border ${isC ? 'bg-emerald-100 border-emerald-200 text-emerald-700' : 'bg-rose-100 border-rose-200 text-rose-700'}`}>
                                                                <span className="font-bold">{s}) {uA === true ? 'ĐÚNG' : (uA === false ? 'SAI' : '-')}</span>
                                                                {!isC && <span className="font-bold opacity-60 bg-white/50 px-2 py-0.5 rounded">Đ/A: {cA ? 'Đ' : 'S'}</span>}
                                                            </div>
                                                        )
                                                    })}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {conf.p3 > 0 && (
                                <div className="bg-white p-4 lg:p-8 rounded-[1.5rem] shadow-sm border border-slate-100">
                                    <h3 className="font-black text-indigo-900 text-sm lg:text-base uppercase tracking-widest mb-4 flex items-center gap-2">
                                        <Icon name="edit-3" className="text-orange-500"/> Phần III (Điền đáp số)
                                    </h3>
                                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                                        {Array.from({length: conf.p3}, (_, i)=>i+1).map(n => {
                                            let uA = (answers?.p3?.[n] || '').toString().trim().toLowerCase().replace(/[ ,$]/g, '').replace(',', '.');
                                            let cA = (exam.correctAnswers?.p3?.[n] || '').toString().trim().toLowerCase().replace(/[ ,$]/g, '').replace(',', '.'); 
                                            const isC = uA !== '' && uA === cA;
                                            return (
                                                <div key={n} className={`p-4 border rounded-2xl flex flex-col gap-2 ${isC ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-rose-50 border-rose-200 text-rose-700'}`}>
                                                    <div className="flex justify-between items-center">
                                                        <span className="font-bold opacity-60 text-xs uppercase">Câu {n}</span>
                                                        {!isC && <span className="font-bold text-[10px] bg-rose-200 text-rose-800 px-2 py-0.5 rounded-md">Đ/A: {exam.correctAnswers?.p3?.[n]}</span>}
                                                    </div>
                                                    <span className="font-black text-lg truncate" title={answers?.p3?.[n]}>{answers?.p3?.[n] || '-'}</span>
                                                </div>
                                            )
                                        })}
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            );
        };

        const getBonusPoints = (student, semester) => {
            if (!student.bonusPoints) return 0;
            if (typeof student.bonusPoints === 'object') {
                return student.bonusPoints[semester] || 0;
            }
            return semester === 'hk1' ? student.bonusPoints : 0;
        };

        const getBonusHistory = (student, semester) => {
            if (!student.bonusPointsHistory) {
                if (semester === 'hk1' && student.bonusPoints && typeof student.bonusPoints !== 'object') {
                    return [{
                        date: 'Khởi tạo',
                        amount: student.bonusPoints,
                        reason: 'Điểm tích lũy cũ'
                    }];
                }
                return [];
            }
            if (typeof student.bonusPointsHistory === 'object' && !Array.isArray(student.bonusPointsHistory)) {
                return student.bonusPointsHistory[semester] || [];
            }
            return semester === 'hk1' ? student.bonusPointsHistory : [];
        };

        const StudentDashboard = ({ user, onClose, showAlert }) => {
            const [grades, setGrades] = useState([]);
            const [isLoading, setIsLoading] = useState(true);
            const [assignedExams, setAssignedExams] = useState([]);
            const [isLoadingAssigned, setIsLoadingAssigned] = useState(true);
            const [activeDashboardTab, setActiveDashboardTab] = useState('assigned');
            const [assignedFilter, setAssignedFilter] = useState('all');
            const [viewingResult, setViewingResult] = useState(null);
            const [showChangePasswordModal, setShowChangePasswordModal] = useState(false);
            const [showStudyScores, setShowStudyScores] = useState(false);
            const [viewingBonusSemester, setViewingBonusSemester] = useState(null);

            const StudentStudyScoresModal = ({ user, onClose }) => {
                const [activeTab, setActiveTab] = useState('hk1'); // 'hk1', 'hk2', 'estimate'
                
                // Semester scores
                const scores = (user.studyScores && user.studyScores[activeTab]) || { tx1: '', tx2: '', tx3: '', tx4: '', tx5: '', gk: '', ck: '' };
                const pubStatus = (user.studyScoresPublished && user.studyScoresPublished[activeTab]) || {};
                
                const isPublished = (col) => {
                    return !!pubStatus[col];
                };
                
                const hasAnyPublished = Object.values(pubStatus).some(Boolean);
                
                // Calculator States
                const [estTx1, setEstTx1] = useState('');
                const [estTx2, setEstTx2] = useState('');
                const [estTx3, setEstTx3] = useState('');
                const [estTx4, setEstTx4] = useState('');
                const [estTx5, setEstTx5] = useState('');
                const [estGk, setEstGk] = useState('');
                const [estCk, setEstCk] = useState('');

                const [estHk1, setEstHk1] = useState('');
                const [estHk2, setEstHk2] = useState('');

                // Helper to parse Vietnamese decimals (using comma as separator)
                const parseScore = (val) => {
                    if (val === undefined || val === null || val === '') return NaN;
                    const str = String(val).replace(',', '.').trim();
                    const f = parseFloat(str);
                    return isNaN(f) ? NaN : f;
                };

                // Detect active TX columns dynamically based on scores and publication status
                const activeTxCols = ['tx1', 'tx2', 'tx3'];
                if (isPublished('tx4') || (scores.tx4 !== '' && scores.tx4 !== null && scores.tx4 !== undefined)) {
                    activeTxCols.push('tx4');
                }
                if (isPublished('tx5') || (scores.tx5 !== '' && scores.tx5 !== null && scores.tx5 !== undefined)) {
                    activeTxCols.push('tx5');
                }

                // Helper to load scores for the calculator
                const loadSemesterScoresForCalculator = (semester) => {
                    if (semester === 'estimate') {
                        const getSemAvg = (sObj, pObj) => {
                            const txList = [sObj.tx1, sObj.tx2, sObj.tx3, sObj.tx4, sObj.tx5]
                                .filter((v, i) => pObj['tx' + (i+1)] && v !== '' && v !== null && v !== undefined)
                                .map(v => parseScore(v))
                                .filter(v => !isNaN(v));
                                
                            const gkScore = parseScore(sObj.gk);
                            const ckScore = parseScore(sObj.ck);

                            if (txList.length > 0 && pObj.gk && !isNaN(gkScore) && pObj.ck && !isNaN(ckScore)) {
                                const sumTx = txList.reduce((a, b) => a + b, 0);
                                return ((sumTx + gkScore*2 + ckScore*3) / (txList.length + 5)).toFixed(1);
                            }
                            return '';
                        };

                        const hk1Scores = (user.studyScores && user.studyScores.hk1) || {};
                        const hk1Pub = (user.studyScoresPublished && user.studyScoresPublished.hk1) || {};
                        const hk2Scores = (user.studyScores && user.studyScores.hk2) || {};
                        const hk2Pub = (user.studyScoresPublished && user.studyScoresPublished.hk2) || {};

                        setEstHk1(getSemAvg(hk1Scores, hk1Pub));
                        setEstHk2(getSemAvg(hk2Scores, hk2Pub));
                        return;
                    }

                    const semScores = (user.studyScores && user.studyScores[semester]) || {};
                    const semPub = (user.studyScoresPublished && user.studyScoresPublished[semester]) || {};
                    
                    const getEstScore = (pub, val) => {
                        if (!pub || val === '' || val === null || val === undefined) return '';
                        const sNum = parseScore(val);
                        return isNaN(sNum) ? '' : sNum.toFixed(1);
                    };
                    setEstTx1(getEstScore(semPub.tx1, semScores.tx1));
                    setEstTx2(getEstScore(semPub.tx2, semScores.tx2));
                    setEstTx3(getEstScore(semPub.tx3, semScores.tx3));
                    setEstTx4(getEstScore(semPub.tx4, semScores.tx4));
                    setEstTx5(getEstScore(semPub.tx5, semScores.tx5));
                    setEstGk(getEstScore(semPub.gk, semScores.gk));
                    setEstCk(getEstScore(semPub.ck, semScores.ck));
                };

                // Sync scores on tab change
                useEffect(() => {
                    loadSemesterScoresForCalculator(activeTab);
                }, [activeTab]);

                const handleTabChange = (tab) => {
                    setActiveTab(tab);
                };

                const handleEstChange = (field, value) => {
                    let normalized = value.replace(',', '.');
                    if (normalized === '' || /^[0-9]*\.?[0-9]*$/.test(normalized)) {
                        const num = parseFloat(normalized);
                        if (!isNaN(num) && (num < 0 || num > 10)) {
                            return;
                        }
                        if (field === 'tx1') setEstTx1(normalized);
                        if (field === 'tx2') setEstTx2(normalized);
                        if (field === 'tx3') setEstTx3(normalized);
                        if (field === 'tx4') setEstTx4(normalized);
                        if (field === 'tx5') setEstTx5(normalized);
                        if (field === 'gk') setEstGk(normalized);
                        if (field === 'ck') setEstCk(normalized);
                    }
                };

                const getEstimatedGPA = () => {
                    const txValues = [estTx1, estTx2, estTx3, estTx4, estTx5]
                        .slice(0, activeTxCols.length)
                        .map(v => parseScore(v))
                        .filter(v => !isNaN(v));
                    const gkVal = parseScore(estGk);
                    const ckVal = parseScore(estCk);

                    if (txValues.length === 0 && isNaN(gkVal) && isNaN(ckVal)) {
                        return null;
                    }

                    let totalWeighted = 0;
                    let totalWeight = 0;

                    txValues.forEach(val => {
                        totalWeighted += val * 1;
                        totalWeight += 1;
                    });

                    if (!isNaN(gkVal)) {
                        totalWeighted += gkVal * 2;
                        totalWeight += 2;
                    }

                    if (!isNaN(ckVal)) {
                        totalWeighted += ckVal * 3;
                        totalWeight += 3;
                    }

                    if (totalWeight === 0) return null;
                    const avg = totalWeighted / totalWeight;
                    return parseFloat(avg.toFixed(1));
                };

                const getEstimatedYearGPA = () => {
                    const hk1 = parseScore(estHk1);
                    const hk2 = parseScore(estHk2);
                    if (isNaN(hk1) && isNaN(hk2)) return null;
                    
                    let totalWeighted = 0;
                    let totalWeight = 0;
                    if (!isNaN(hk1)) {
                        totalWeighted += hk1 * 1;
                        totalWeight += 1;
                    }
                    if (!isNaN(hk2)) {
                        totalWeighted += hk2 * 2;
                        totalWeight += 2;
                    }
                    if (totalWeight === 0) return null;
                    return parseFloat((totalWeighted / totalWeight).toFixed(1));
                };

                // Calculate current semester GPA based on published scores
                let totalWeightedScore = 0;
                let totalWeight = 0;

                const checkAndAdd = (colId, weight) => {
                    if (isPublished(colId)) {
                        const val = scores[colId];
                        if (val !== '' && val !== null && val !== undefined) {
                            const scoreNum = parseScore(val);
                            if (!isNaN(scoreNum)) {
                                totalWeightedScore += scoreNum * weight;
                                totalWeight += weight;
                            }
                        }
                    }
                };

                ['tx1', 'tx2', 'tx3', 'tx4', 'tx5'].forEach(col => checkAndAdd(col, 1));
                checkAndAdd('gk', 2);
                checkAndAdd('ck', 3);

                const semesterGPA = totalWeight > 0 ? (totalWeightedScore / totalWeight).toFixed(1) : null;



                const renderScoreCard = (colId, label, coefficient = 1) => {
                    const isPub = isPublished(colId);
                    const val = scores[colId];
                    
                    let bgStyle = 'bg-slate-50/40 border-slate-100 text-slate-600 hover:bg-slate-50/60';
                    let valStyle = 'text-slate-800 text-xl font-black';
                    
                    if (isPub && val !== '' && val !== null && val !== undefined) {
                        const scoreNum = parseScore(val);
                        if (!isNaN(scoreNum)) {
                            if (scoreNum >= 8.0) {
                                bgStyle = 'bg-emerald-50/30 border-emerald-100/60 hover:bg-emerald-50/50';
                                valStyle = 'text-emerald-700 text-xl font-black';
                            } else if (scoreNum >= 6.5) {
                                bgStyle = 'bg-sky-50/30 border-sky-100/60 hover:bg-sky-50/50';
                                valStyle = 'text-sky-700 text-xl font-black';
                            } else if (scoreNum >= 5.0) {
                                bgStyle = 'bg-amber-50/30 border-amber-100/60 hover:bg-amber-50/50';
                                valStyle = 'text-amber-700 text-xl font-black';
                            } else {
                                bgStyle = 'bg-rose-50/30 border-rose-100/60 hover:bg-rose-50/50';
                                valStyle = 'text-rose-700 text-xl font-black';
                            }
                        }
                    } else if (isPub) {
                        bgStyle = 'bg-slate-50 border-slate-100 text-slate-500';
                        valStyle = 'text-slate-400 text-xl font-black';
                    } else {
                        bgStyle = 'bg-slate-100/30 border-slate-200/30 text-slate-400 opacity-80';
                        valStyle = 'text-slate-400 text-xs font-bold';
                    }

                    return (
                        <div className={`p-3 rounded-2xl border flex flex-col justify-between items-center text-center gap-1.5 transition-all duration-300 w-full min-h-[90px] relative overflow-hidden shadow-sm hover:scale-[1.03] ${bgStyle}`}>
                            <span className="font-black text-[9px] text-slate-450 uppercase tracking-widest">{label}</span>
                            <div className="w-full flex items-center justify-center gap-1 py-0.5">
                                {isPub ? (
                                    <span className={valStyle}>
                                        {val !== '' && val !== null && val !== undefined && !isNaN(parseScore(val)) 
                                            ? parseScore(val).toFixed(1) 
                                            : '-'}
                                    </span>
                                ) : (
                                    <span className="text-[9px] font-black text-slate-455 select-none bg-slate-100 border border-slate-200/50 px-1.5 py-0.5 rounded-lg uppercase tracking-wider inline-flex items-center gap-1">
                                        <Icon name="eye-off" size={9} className="opacity-60"/>
                                        Chưa CB
                                    </span>
                                )}
                            </div>
                            <span className="font-bold text-[8px] text-slate-450 uppercase tracking-widest">Hệ số {coefficient}</span>
                        </div>
                    );
                };

                return (
                    <div className="fixed inset-0 z-[150] bg-slate-950/60 backdrop-blur-md flex items-center justify-center p-4">
                        <div className="bg-white w-full max-w-3xl rounded-[2rem] shadow-2xl flex flex-col overflow-hidden border border-slate-100 animate-in zoom-in-95 duration-200">
                            <header className="p-4 border-b flex justify-between items-center bg-slate-50/50 shrink-0">
                                <div className="flex items-center gap-3">
                                    <div className="p-2 bg-indigo-50 text-indigo-600 rounded-xl"><Icon name="award" size={15}/></div>
                                    <div className="text-left">
                                        <h3 className="text-xs md:text-sm font-black uppercase tracking-widest text-slate-800">Điểm số học tập cá nhân</h3>
                                        <p className="text-[9px] font-bold text-slate-400 mt-0.5">{user.fullName} - Lớp {user.class}</p>
                                    </div>
                                </div>
                                <button onClick={onClose} className="w-7 h-7 flex items-center justify-center bg-rose-500 text-white hover:bg-rose-600 rounded-full transition-colors shadow-md"><Icon name="x" size={16}/></button>
                            </header>

                            {/* Tabs Switcher */}
                            <div className="flex border-b border-slate-100 p-2 gap-2 bg-slate-50/50 shrink-0">
                                <button 
                                    onClick={() => handleTabChange('hk1')}
                                    className={`flex-1 py-2 rounded-xl text-xs font-black uppercase tracking-wider transition-all flex items-center justify-center gap-1.5 border ${activeTab === 'hk1' ? 'bg-white text-indigo-600 border-slate-200 shadow-md shadow-indigo-600/5' : 'text-slate-500 border-transparent hover:bg-slate-100/50'}`}
                                >
                                    <Icon name="calendar" size={13}/> Học kỳ I
                                </button>
                                <button 
                                    onClick={() => handleTabChange('hk2')}
                                    className={`flex-1 py-2 rounded-xl text-xs font-black uppercase tracking-wider transition-all flex items-center justify-center gap-1.5 border ${activeTab === 'hk2' ? 'bg-white text-indigo-600 border-slate-200 shadow-md shadow-indigo-600/5' : 'text-slate-500 border-transparent hover:bg-slate-100/50'}`}
                                >
                                    <Icon name="calendar" size={13}/> Học kỳ II
                                </button>
                                <button 
                                    onClick={() => handleTabChange('estimate')}
                                    className={`flex-1 py-2 rounded-xl text-xs font-black uppercase tracking-wider transition-all flex items-center justify-center gap-1.5 border ${activeTab === 'estimate' ? 'bg-white text-indigo-600 border-slate-200 shadow-md shadow-indigo-600/5' : 'text-slate-500 border-transparent hover:bg-slate-100/50'}`}
                                >
                                    <Icon name="calculator" size={13}/> Ước tính cả năm
                                </button>
                            </div>
                            
                            <div className="flex-1 overflow-y-auto max-h-[60vh] custom-scrollbar px-6 py-4">
                                {activeTab !== 'estimate' ? (
                                    !hasAnyPublished ? (
                                        <div className="py-8 text-center flex flex-col items-center justify-center gap-2.5 bg-white">
                                            <div className="p-3 bg-amber-50 text-amber-500 rounded-full"><Icon name="eye-off" size={28}/></div>
                                            <h4 className="text-xs font-black uppercase text-slate-800">Chưa Công Bố Điểm</h4>
                                            <p className="text-[11px] text-slate-500 font-bold max-w-xs leading-relaxed">Sổ điểm học tập Học kỳ {activeTab === 'hk1' ? 'I' : 'II'} của lớp {user.class} chưa được giáo viên công bố.</p>
                                        </div>
                                    ) : (
                                        <div className="space-y-4">
                                            {/* Sổ điểm chính thức */}
                                            <div className="space-y-2">
                                                <h4 className="text-[10px] font-black text-slate-400 uppercase tracking-widest pb-0.5 border-b border-slate-100 text-left">
                                                    Bảng điểm thành phần chính thức ({activeTab === 'hk1' ? 'Học kỳ I' : 'Học kỳ II'})
                                                </h4>
                                                <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-flow-col md:auto-cols-fr gap-2.5">
                                                    {activeTxCols.map((col, idx) => (
                                                        renderScoreCard(col, `TX ${idx+1}`, 1)
                                                    ))}
                                                    {renderScoreCard('gk', 'Giữa kỳ', 2)}
                                                    {renderScoreCard('ck', 'Cuối kỳ', 3)}
                                                </div>
                                            </div>

                                            {/* Máy tính thử điểm */}
                                            <div className="p-4 bg-slate-50/30 rounded-3xl border border-slate-100 flex flex-col md:flex-row gap-4 items-stretch">
                                                <div className="flex-1 flex flex-col justify-between gap-3">
                                                    <div className="text-left">
                                                        <h4 className="text-[9px] font-black text-slate-700 uppercase tracking-widest flex items-center gap-1.5">
                                                            <Icon name="calculator" size={12} className="text-indigo-600"/>
                                                            MÁY TÍNH THỬ ĐIỂM HỌC KỲ {activeTab === 'hk1' ? 'I' : 'II'}
                                                        </h4>
                                                        <p className="text-[8px] font-bold text-slate-400 mt-0.5">
                                                            Học sinh có thể chỉnh sửa thử các điểm số dưới đây để mô phỏng và xem điểm trung bình học kỳ dự kiến.
                                                        </p>
                                                    </div>
                                                    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-flow-col md:auto-cols-fr gap-2">
                                                        {activeTxCols.map((col, idx) => {
                                                            const isPub = isPublished(col);
                                                            const val = col === 'tx1' ? estTx1 : col === 'tx2' ? estTx2 : col === 'tx3' ? estTx3 : col === 'tx4' ? estTx4 : estTx5;
                                                            return (
                                                                <div key={col} className="flex flex-col items-center gap-0.5 w-full">
                                                                    <label className="block text-[8px] font-black text-slate-400 uppercase tracking-wider text-center">TX{idx+1}</label>
                                                                    <input 
                                                                        type="text" 
                                                                        value={val} 
                                                                        onChange={e => handleEstChange(col, e.target.value)} 
                                                                        className="w-full bg-white border border-slate-200 p-1.5 rounded-xl text-center font-bold text-slate-700 text-xs focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition-all" 
                                                                        placeholder={isPub ? "-" : "Chưa CB"} 
                                                                    />
                                                                    <span className="block text-[8px] font-bold text-slate-400 uppercase tracking-tighter text-center">H.Số 1</span>
                                                                </div>
                                                            );
                                                        })}
                                                        <div className="flex flex-col items-center gap-0.5 w-full">
                                                            <label className="block text-[8px] font-black text-slate-400 uppercase tracking-wider text-center">GK</label>
                                                            <input 
                                                                type="text" 
                                                                value={estGk} 
                                                                onChange={e => handleEstChange('gk', e.target.value)} 
                                                                className="w-full bg-white border border-slate-200 p-1.5 rounded-xl text-center font-bold text-slate-700 text-xs focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition-all" 
                                                                placeholder={isPublished('gk') ? "-" : "Chưa CB"} 
                                                            />
                                                            <span className="block text-[8px] font-bold text-slate-400 uppercase tracking-tighter text-center">H.Số 2</span>
                                                        </div>
                                                        <div className="flex flex-col items-center gap-0.5 w-full">
                                                            <label className="block text-[8px] font-black text-slate-400 uppercase tracking-wider text-center">CK</label>
                                                            <input 
                                                                type="text" 
                                                                value={estCk} 
                                                                onChange={e => handleEstChange('ck', e.target.value)} 
                                                                className="w-full bg-white border border-slate-200 p-1.5 rounded-xl text-center font-bold text-slate-700 text-xs focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition-all" 
                                                                placeholder={isPublished('ck') ? "-" : "Chưa CB"} 
                                                            />
                                                            <span className="block text-[8px] font-bold text-slate-400 uppercase tracking-tighter text-center">H.Số 3</span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="w-full md:w-44 shrink-0 flex items-stretch">
                                                    {(() => {
                                                        const estGPA = getEstimatedGPA();
                                                        const scoreToShow = estGPA !== null ? estGPA.toFixed(1) : '0.0';
                                                        const scoreNum = estGPA !== null ? estGPA : 0;
                                                        
                                                        let classification = 'HỌC LỰC YẾU';
                                                        let boxStyle = 'bg-rose-50 border border-rose-100/50 text-rose-700';
                                                        let scoreStyle = 'text-rose-500';
                                                        if (scoreNum >= 8.0) {
                                                            classification = 'HỌC LỰC GIỎI';
                                                            boxStyle = 'bg-emerald-50 border border-emerald-100/50 text-emerald-700';
                                                            scoreStyle = 'text-emerald-500';
                                                        } else if (scoreNum >= 6.5) {
                                                            classification = 'HỌC LỰC KHÁ';
                                                            boxStyle = 'bg-sky-50 border border-sky-100/50 text-sky-700';
                                                            scoreStyle = 'text-sky-500';
                                                        } else if (scoreNum >= 5.0) {
                                                            classification = 'HỌC LỰC TRUNG BÌNH';
                                                            boxStyle = 'bg-amber-50 border border-amber-100/50 text-amber-700';
                                                            scoreStyle = 'text-amber-500';
                                                        }
                                                        
                                                        return (
                                                            <div className={`p-3 rounded-2xl flex flex-col justify-center items-center text-center w-full transition-all ${boxStyle}`}>
                                                                <span className="text-[8px] font-black uppercase tracking-widest opacity-80">Điểm học kỳ dự kiến</span>
                                                                <strong className={`text-3xl font-black my-0.5 font-sans ${scoreStyle}`}>{scoreToShow}</strong>
                                                                <span className="text-[8px] font-black uppercase tracking-wider">{classification}</span>
                                                            </div>
                                                        );
                                                    })()}
                                                </div>
                                            </div>

                                            {/* Điểm cộng */}
                                            <div>
                                                <h4 className="text-[10px] font-black text-pink-650 uppercase tracking-widest mb-2 pb-0.5 border-b border-pink-100 text-left">Điểm cộng học kỳ</h4>
                                                <div 
                                                    onClick={() => setViewingBonusSemester(activeTab)}
                                                    className="p-3 rounded-2xl border border-pink-100 bg-pink-50/20 flex items-center justify-between transition-all duration-300 shadow-sm hover:scale-[1.01] relative overflow-hidden cursor-pointer hover:bg-pink-50/40"
                                                    title="Bấm để xem chi tiết lịch sử điểm cộng"
                                                >
                                                    <div className="flex items-center gap-3">
                                                        <div className="w-9 h-9 rounded-xl flex items-center justify-center text-white font-black shadow-md bg-pink-500 shadow-pink-500/30">
                                                            <Icon name="plus-circle" size={15}/>
                                                        </div>
                                                        <div className="text-left">
                                                            <span className="block font-black text-xs text-slate-800">Tổng điểm cộng</span>
                                                            <span className="block text-[8px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">Tích lũy từ hoạt động</span>
                                                        </div>
                                                    </div>
                                                    <div className="w-16">
                                                        <span className="text-pink-700 bg-white border border-pink-250/30 px-2 py-1 rounded-xl shadow-sm text-xs font-black w-full text-center block">
                                                            +{getBonusPoints(user, activeTab)}
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    )
                                ) : (
                                    <div className="space-y-4 animate-in fade-in duration-250">
                                        {/* Estimate Year GPA */}
                                        <div className="p-4 bg-slate-50/30 rounded-3xl border border-slate-100 flex flex-col md:flex-row gap-4 items-stretch">
                                            <div className="flex-1 flex flex-col justify-between gap-3">
                                                <div className="text-left">
                                                    <h4 className="text-[9px] font-black text-slate-700 uppercase tracking-widest flex items-center gap-1.5">
                                                        <Icon name="award" size={12} className="text-emerald-600"/>
                                                        DỰ TÍNH KẾT QUẢ CẢ NĂM
                                                    </h4>
                                                    <p className="text-[8px] font-bold text-slate-400 mt-0.5">
                                                        Nhập điểm trung bình học kỳ 1 (hệ số 1) và học kỳ 2 (hệ số 2) để dự tính kết quả cả năm.
                                                    </p>
                                                </div>
                                                <div className="grid grid-cols-2 gap-3">
                                                    <div className="text-left">
                                                        <span className="block text-[8px] font-black text-slate-400 uppercase tracking-wider mb-1 pl-0.5">ĐTB Học kỳ I (H.Số 1)</span>
                                                        <input 
                                                            type="text" 
                                                            value={estHk1} 
                                                            onChange={e => {
                                                                let val = e.target.value.replace(',', '.');
                                                                if (val === '' || /^[0-9]*\.?[0-9]*$/.test(val)) {
                                                                    const num = parseFloat(val);
                                                                    if (!isNaN(num) && (num < 0 || num > 10)) return;
                                                                    setEstHk1(val);
                                                                }
                                                            }} 
                                                            className="w-full bg-white border border-slate-200 p-2 rounded-xl text-center font-bold text-slate-700 text-xs focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all" 
                                                            placeholder="ĐTB HK1" 
                                                        />
                                                    </div>
                                                    <div className="text-left">
                                                        <span className="block text-[8px] font-black text-slate-450 uppercase tracking-wider mb-1 pl-0.5">ĐTB Học kỳ II (H.Số 2)</span>
                                                        <input 
                                                            type="text" 
                                                            value={estHk2} 
                                                            onChange={e => {
                                                                let val = e.target.value.replace(',', '.');
                                                                if (val === '' || /^[0-9]*\.?[0-9]*$/.test(val)) {
                                                                    const num = parseFloat(val);
                                                                    if (!isNaN(num) && (num < 0 || num > 10)) return;
                                                                    setEstHk2(val);
                                                                }
                                                            }} 
                                                            className="w-full bg-white border border-slate-200 p-2 rounded-xl text-center font-bold text-slate-700 text-xs focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all" 
                                                            placeholder="ĐTB HK2" 
                                                        />
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="w-full md:w-44 shrink-0 flex items-stretch">
                                                {(() => {
                                                    const estGPA = getEstimatedYearGPA();
                                                    const scoreToShow = estGPA !== null ? estGPA.toFixed(1) : '0.0';
                                                    const scoreNum = estGPA !== null ? estGPA : 0;
                                                    
                                                    let classification = 'HỌC LỰC YẾU';
                                                    let boxStyle = 'bg-rose-50 border border-rose-100/50 text-rose-700';
                                                    let scoreStyle = 'text-rose-500';
                                                    if (scoreNum >= 8.0) {
                                                        classification = 'HỌC LỰC GIỎI';
                                                        boxStyle = 'bg-emerald-50 border border-emerald-100/50 text-emerald-700';
                                                        scoreStyle = 'text-emerald-500';
                                                    } else if (scoreNum >= 6.5) {
                                                        classification = 'HỌC LỰC KHÁ';
                                                        boxStyle = 'bg-sky-50 border border-sky-100/50 text-sky-700';
                                                        scoreStyle = 'text-sky-500';
                                                    } else if (scoreNum >= 5.0) {
                                                        classification = 'HỌC LỰC TRUNG BÌNH';
                                                        boxStyle = 'bg-amber-50 border border-amber-100/50 text-amber-700';
                                                        scoreStyle = 'text-amber-500';
                                                    }
                                                    
                                                    return (
                                                        <div className={`p-3 rounded-2xl flex flex-col justify-center items-center text-center w-full transition-all ${boxStyle}`}>
                                                            <span className="text-[8px] font-black uppercase tracking-widest opacity-80">Điểm cả năm dự kiến</span>
                                                            <strong className={`text-3xl font-black my-0.5 font-sans ${scoreStyle}`}>{scoreToShow}</strong>
                                                            <span className="text-[8px] font-black uppercase tracking-wider">{classification}</span>
                                                        </div>
                                                    );
                                                })()}
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </div>
                            
                            <footer className="p-3 border-t border-slate-100 bg-slate-50 flex justify-end shrink-0">
                                <button onClick={onClose} className="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 font-black rounded-xl text-[10px] transition-colors uppercase tracking-widest">Đóng</button>
                            </footer>
                        </div>
                    </div>
                );
            };

            const StudentBonusHistoryModal = ({ user, semester, onClose }) => {
                const history = getBonusHistory(user, semester);
                const totalPoints = getBonusPoints(user, semester);
                
                return (
                    <div className="fixed inset-0 z-[150] bg-slate-950/60 backdrop-blur-md flex items-center justify-center p-4">
                        <div className="bg-white w-full max-w-md rounded-[2.5rem] shadow-2xl flex flex-col overflow-hidden border border-slate-100 animate-in zoom-in-95 duration-200">
                            <header className="p-5 border-b flex justify-between items-center bg-pink-50 shrink-0">
                                <div className="flex items-center gap-3">
                                    <div className="p-2.5 bg-pink-100 text-pink-600 rounded-2xl"><Icon name="plus-circle" size={16}/></div>
                                    <div className="text-left">
                                        <h3 className="text-sm font-black uppercase tracking-widest text-slate-800 font-sans">Lịch sử điểm cộng {semester.toUpperCase()}</h3>
                                        <p className="text-[10px] font-bold text-slate-400 mt-0.5">Tổng điểm: +{totalPoints}</p>
                                    </div>
                                </div>
                                <button onClick={onClose} className="w-8 h-8 flex items-center justify-center bg-rose-500 text-white hover:bg-rose-600 rounded-full transition-colors shadow-md"><Icon name="x" size={18}/></button>
                            </header>
                            
                            <div className="flex-1 overflow-y-auto max-h-[50vh] p-6 space-y-3 custom-scrollbar">
                                {history.length === 0 ? (
                                    <div className="py-12 text-center flex flex-col items-center justify-center gap-2 text-slate-400 opacity-60">
                                        <Icon name="plus-circle" size={32} />
                                        <p className="text-sm font-bold">Chưa có lịch sử cộng điểm</p>
                                        <p className="text-[10px]">Điểm cộng sẽ được giáo viên cập nhật trực tiếp tại đây.</p>
                                    </div>
                                ) : (
                                    [...history].reverse().map((entry, index) => (
                                        <div key={index} className="p-4 bg-slate-50/50 hover:bg-slate-50 border border-slate-100 rounded-2xl flex items-center justify-between transition-colors">
                                            <div className="text-left space-y-1">
                                                <p className="font-bold text-xs text-slate-700">{entry.reason || 'Cộng điểm học tập'}</p>
                                                <p className="text-[9px] font-medium text-slate-400 flex items-center gap-1">
                                                    <Icon name="clock" size={10}/> {entry.date}
                                                </p>
                                            </div>
                                            <span className={`text-sm font-black font-sans px-2.5 py-1 rounded-xl shadow-sm border ${entry.amount >= 0 ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-rose-50 text-rose-700 border-rose-100'}`}>
                                                {entry.amount >= 0 ? `+${entry.amount}` : entry.amount}
                                            </span>
                                        </div>
                                    ))
                                )}
                            </div>
                            <footer className="p-4 border-t border-slate-100 bg-slate-50 flex justify-end shrink-0">
                                <button onClick={onClose} className="px-5 py-2.5 bg-slate-250 hover:bg-slate-300 text-slate-700 font-black rounded-xl text-[10px] transition-colors uppercase tracking-widest">Đóng</button>
                            </footer>
                        </div>
                    </div>
                );
            };

            const formatTime = (ts) => {
                if (!ts) return 'Không rõ';
                const num = Number(ts);
                if (isNaN(num)) {
                    const d = new Date(ts);
                    if (!isNaN(d.getTime())) return d.toLocaleString('vi-VN');
                    return ts;
                }
                const date = new Date(num);
                const hrs = date.getHours().toString().padStart(2, '0');
                const mins = date.getMinutes().toString().padStart(2, '0');
                const secs = date.getSeconds().toString().padStart(2, '0');
                const day = date.getDate().toString().padStart(2, '0');
                const month = (date.getMonth() + 1).toString().padStart(2, '0');
                const year = date.getFullYear();
                return `${hrs}:${mins}:${secs} ${day}/${month}/${year}`;
            };

            useEffect(() => {
                fetch('?action=student_get_grades')
                    .then(r => r.json())
                    .then(d => {
                        setGrades(Array.isArray(d) ? d : []);
                        setIsLoading(false);
                    })
                    .catch(() => {
                        showAlert("Lỗi tải thông tin điểm số!");
                        setIsLoading(false);
                    });

                fetch('?action=student_get_assigned_exams')
                    .then(r => r.json())
                    .then(d => {
                        setAssignedExams(Array.isArray(d) ? d : []);
                        setIsLoadingAssigned(false);
                    })
                    .catch(() => {
                        setIsLoadingAssigned(false);
                    });
            }, []);

            return (
                <div className="min-h-screen bg-slate-50 font-sans pb-10">
                    <div className="absolute top-0 left-0 right-0 h-[220px] bg-gradient-to-b from-indigo-900 to-slate-50 pointer-events-none z-0"></div>
                    
                    <div className="p-4 md:p-6 max-w-[1200px] mx-auto relative z-10 pt-6">
                        <header className="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-6 bg-white p-5 md:p-6 rounded-[2rem] shadow-md border border-slate-100 transition-all hover:shadow-lg w-full">
                            <div className="flex items-start gap-4 text-left w-full md:w-auto">
                                <div className="w-12 h-12 bg-gradient-to-br from-indigo-600 to-violet-500 text-white rounded-2xl flex items-center justify-center shadow-md shadow-indigo-500/20 border border-white shrink-0 mt-1">
                                    <Icon name="graduation-cap" size={20} strokeWidth={2}/>
                                </div>
                                <div>
                                    <h1 className="text-lg md:text-xl font-black text-slate-800 uppercase tracking-tight">CỔNG TRA CỨU ĐIỂM SỐ</h1>
                                    <div className="flex flex-col gap-2 mt-3 text-left">
                                        <div className="flex items-center gap-2 text-xs font-bold text-slate-600">
                                            <Icon name="user" size={12} className="text-slate-400 w-4"/>
                                            <span className="w-14 shrink-0 text-slate-400">Họ tên:</span>
                                            <strong className="text-indigo-600 uppercase bg-indigo-50/50 px-2.5 py-0.5 rounded-lg border border-indigo-100/60 font-black text-[11px]">{user.fullName}</strong>
                                        </div>
                                        <div className="flex items-center gap-2 text-xs font-bold text-slate-600">
                                            <Icon name="hash" size={12} className="text-slate-400 w-4"/>
                                            <span className="w-14 shrink-0 text-slate-400">Mã HS:</span>
                                            <strong className="text-violet-600 bg-violet-50 px-2.5 py-0.5 rounded-lg border border-violet-100 font-mono font-black text-[11px]">{user.username}</strong>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-2 text-xs font-bold text-slate-600">
                                            <Icon name="folder" size={12} className="text-slate-400 w-4"/>
                                            <span className="w-14 shrink-0 text-slate-400">Lớp:</span>
                                            <strong className="text-emerald-600 bg-emerald-50 px-2.5 py-0.5 rounded-lg border border-emerald-100 font-black text-[11px]">{user.class}</strong>
                                            
                                            <span className="text-slate-300 mx-1 select-none">|</span>
                                            <span className="text-slate-400 font-medium">Điểm cộng HK1:</span>
                                            <strong className="text-pink-600 bg-pink-50 hover:bg-pink-100 cursor-pointer px-2 py-0.5 rounded-lg border border-pink-100 font-black text-[10px] transition-colors shadow-sm" onClick={() => setViewingBonusSemester('hk1')} title="Xem lịch sử điểm cộng HK1">
                                                +{getBonusPoints(user, 'hk1')}
                                            </strong>
                                            
                                            <span className="text-slate-400 font-medium ml-2">HK2:</span>
                                            <strong className="text-pink-600 bg-pink-50 hover:bg-pink-100 cursor-pointer px-2 py-0.5 rounded-lg border border-pink-100 font-black text-[10px] transition-colors shadow-sm" onClick={() => setViewingBonusSemester('hk2')} title="Xem lịch sử điểm cộng HK2">
                                                +{getBonusPoints(user, 'hk2')}
                                            </strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div className="flex flex-wrap items-center gap-2.5 w-full md:w-auto shrink-0 justify-center md:justify-end mt-4 md:mt-0">
                                <button 
                                    onClick={() => setShowStudyScores(true)} 
                                    className="flex-1 sm:flex-none flex items-center justify-center gap-2 px-4 py-2.5 bg-sky-50 hover:bg-sky-600 text-sky-700 hover:text-white rounded-xl border border-sky-100 hover:border-sky-600 text-[10px] font-black uppercase tracking-widest transition-all active:scale-95 duration-150 shadow-sm"
                                >
                                    <Icon name="award" size={13}/>
                                    <span>Điểm thường xuyên</span>
                                </button>

                                <button 
                                    onClick={() => setShowChangePasswordModal(true)} 
                                    className="flex-1 sm:flex-none flex items-center justify-center gap-2 px-4 py-2.5 bg-violet-50 hover:bg-violet-600 text-violet-700 hover:text-white rounded-xl border border-violet-100 hover:border-violet-600 text-[10px] font-black uppercase tracking-widest transition-all active:scale-95 duration-150 shadow-sm"
                                >
                                    <Icon name="key" size={13}/>
                                    <span>Mật khẩu</span>
                                </button>

                                <button 
                                    onClick={onClose} 
                                    className="flex-1 sm:flex-none flex items-center justify-center gap-2 px-4 py-2.5 bg-rose-50 hover:bg-rose-600 text-rose-700 hover:text-white rounded-xl border border-rose-100 hover:border-rose-600 text-[10px] font-black uppercase tracking-widest transition-all active:scale-95 duration-150 shadow-sm"
                                >
                                    <Icon name="log-out" size={13}/>
                                    <span>Đăng xuất</span>
                                </button>
                            </div>
                        </header>

                        {/* Tabs Header */}
                        <div className="flex flex-wrap items-center gap-3 mb-6">
                            <button
                                onClick={() => setActiveDashboardTab('assigned')}
                                className={`px-5 py-3 rounded-2xl font-black text-xs uppercase tracking-wider transition-all flex items-center gap-2.5 shadow-sm ${activeDashboardTab === 'assigned' ? 'bg-indigo-600 text-white shadow-indigo-600/25' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200/80'}`}
                            >
                                <Icon name="book-open" size={16}/>
                                <span>Bài tập & Đề kiểm tra được giao</span>
                                <span className={`px-2 py-0.5 rounded-full text-[10px] font-black ${activeDashboardTab === 'assigned' ? 'bg-white/20 text-white' : 'bg-indigo-50 text-indigo-600'}`}>
                                    {assignedExams.length}
                                </span>
                            </button>

                            <button
                                onClick={() => setActiveDashboardTab('history')}
                                className={`px-5 py-3 rounded-2xl font-black text-xs uppercase tracking-wider transition-all flex items-center gap-2.5 shadow-sm ${activeDashboardTab === 'history' ? 'bg-indigo-600 text-white shadow-indigo-600/25' : 'bg-white text-slate-600 hover:bg-slate-100 border border-slate-200/80'}`}
                            >
                                <Icon name="history" size={16}/>
                                <span>Lịch sử bài đã nộp</span>
                                <span className={`px-2 py-0.5 rounded-full text-[10px] font-black ${activeDashboardTab === 'history' ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600'}`}>
                                    {grades.length}
                                </span>
                            </button>
                        </div>

                        {activeDashboardTab === 'assigned' ? (
                            <div className="bg-white rounded-[2rem] shadow-sm border border-slate-100 p-6 md:p-8">
                                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 pb-4 border-b border-slate-100">
                                    <div>
                                        <h2 className="text-sm font-black text-slate-800 uppercase tracking-tight flex items-center gap-2">
                                            <Icon name="clipboard-list" size={16} className="text-indigo-600"/>
                                            Danh sách bài được giao cho bạn ({assignedExams.length})
                                        </h2>
                                    </div>
                                    <div className="flex items-center gap-1.5 bg-slate-100 p-1 rounded-xl">
                                        <button
                                            onClick={() => setAssignedFilter('all')}
                                            className={`px-3 py-1.5 rounded-lg text-[11px] font-black uppercase tracking-wider transition-all ${assignedFilter === 'all' ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500 hover:text-slate-800'}`}
                                        >
                                            Tất cả ({assignedExams.length})
                                        </button>
                                        <button
                                            onClick={() => setAssignedFilter('pending')}
                                            className={`px-3 py-1.5 rounded-lg text-[11px] font-black uppercase tracking-wider transition-all ${assignedFilter === 'pending' ? 'bg-amber-500 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800'}`}
                                        >
                                            Chưa nộp ({assignedExams.filter(e => !e.hasSubmitted).length})
                                        </button>
                                        <button
                                            onClick={() => setAssignedFilter('submitted')}
                                            className={`px-3 py-1.5 rounded-lg text-[11px] font-black uppercase tracking-wider transition-all ${assignedFilter === 'submitted' ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800'}`}
                                        >
                                            Đã nộp ({assignedExams.filter(e => e.hasSubmitted).length})
                                        </button>
                                    </div>
                                </div>

                                {isLoadingAssigned ? (
                                    <div className="py-20 text-center text-slate-400 font-bold flex flex-col items-center gap-3">
                                        <Icon name="loader-2" className="animate-spin" size={32}/>
                                        Đang tải danh sách bài tập được giao...
                                    </div>
                                ) : assignedExams.filter(item => {
                                    if (assignedFilter === 'pending') return !item.hasSubmitted;
                                    if (assignedFilter === 'submitted') return item.hasSubmitted;
                                    return true;
                                }).length === 0 ? (
                                    <div className="py-16 text-center flex flex-col items-center gap-2 text-slate-400 opacity-70">
                                        <div className="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center text-slate-400 mb-2">
                                            <Icon name="inbox" size={32}/>
                                        </div>
                                        <p className="text-base font-bold text-slate-700">Chưa có bài tập nào</p>
                                        <p className="text-xs">
                                            {assignedFilter === 'pending' ? 'Bạn đã hoàn thành tất cả các bài được giao!' : 'Khi thầy cô giao bài tập cho khối hoặc lớp của bạn, đề thi sẽ xuất hiện tại đây.'}
                                        </p>
                                    </div>
                                ) : (
                                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                        {assignedExams
                                            .filter(item => {
                                                if (assignedFilter === 'pending') return !item.hasSubmitted;
                                                if (assignedFilter === 'submitted') return item.hasSubmitted;
                                                return true;
                                            })
                                            .map((item) => {
                                                const isThayTuan = window.location.pathname.includes('thaytuan');
                                                const targetUrl = `${isThayTuan ? 'index_thaytuan.php' : 'index.php'}?exam_id=${encodeURIComponent(item.id)}&name=${encodeURIComponent(user.fullName || '')}&class=${encodeURIComponent(user.class || '')}`;
                                                const isTest = item.examMode === 'test';
                                                const accentBar = isTest ? 'bg-purple-500' : 'bg-blue-500';

                                                return (
                                                    <div 
                                                        key={item.id} 
                                                        className="relative overflow-hidden bg-white p-4 rounded-2xl border border-slate-200/80 shadow-sm hover:shadow-md hover:border-indigo-300 transition-all flex flex-col justify-between group"
                                                    >
                                                        <div className={`absolute top-0 left-0 right-0 h-1 ${accentBar}`}></div>
                                                        
                                                        <div className="pt-0.5">
                                                            <div className="flex items-center justify-between gap-2 mb-2">
                                                                <span className={`text-[9px] font-black uppercase tracking-wider px-2 py-0.5 rounded-md border ${isTest ? 'bg-purple-50 text-purple-700 border-purple-100' : 'bg-blue-50 text-blue-700 border-blue-100'}`}>
                                                                    {isTest ? 'Kiểm tra' : 'Luyện tập'}
                                                                </span>

                                                                {item.hasSubmitted ? (
                                                                    <span className="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-100">
                                                                        <Icon name="check-circle" size={11}/> Đã nộp
                                                                        {item.latestScore !== null && item.latestScore !== undefined && (
                                                                            <strong className="text-emerald-700 font-black">({parseFloat(item.latestScore).toFixed(1)}đ)</strong>
                                                                        )}
                                                                    </span>
                                                                ) : (
                                                                    <span className="inline-flex items-center gap-1 text-[10px] font-bold text-amber-600 bg-amber-50 px-2 py-0.5 rounded-md border border-amber-100">
                                                                        <Icon name="alert-circle" size={11}/> Chưa làm
                                                                    </span>
                                                                )}
                                                            </div>

                                                            <h3 className="font-extrabold text-slate-800 text-sm leading-snug group-hover:text-indigo-600 transition-colors line-clamp-2 mb-2.5" title={item.title}>
                                                                {item.title}
                                                            </h3>

                                                            {/* Hàng thông tin chi tiết siêu gọn gàng */}
                                                            <div className="flex flex-wrap items-center gap-1.5 text-[11px] font-bold text-slate-500 mb-3 bg-slate-50/80 p-2 rounded-xl border border-slate-100">
                                                                <span className="inline-flex items-center gap-1">
                                                                    <Icon name="clock" size={11} className="text-slate-400 shrink-0"/>
                                                                    {item.duration}p
                                                                </span>
                                                                <span className="text-slate-300">•</span>
                                                                <span className="inline-flex items-center gap-1">
                                                                    <Icon name="help-circle" size={11} className="text-slate-400 shrink-0"/>
                                                                    {item.questionCount || 0} câu
                                                                </span>
                                                                <span className="text-slate-300">•</span>
                                                                <span className="inline-flex items-center gap-1">
                                                                    <Icon name="repeat" size={11} className="text-slate-400 shrink-0"/>
                                                                    {item.maxAttempts > 0 
                                                                        ? `Thi: ${item.submissionCount}/${item.maxAttempts}` 
                                                                        : (item.submissionCount > 0 ? `Thi: Tự do (${item.submissionCount} lần)` : 'Thi: Tự do')}
                                                                </span>
                                                            </div>
                                                        </div>

                                                        <div className="pt-2 border-t border-slate-100">
                                                            <a
                                                                href={targetUrl}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                className="w-full py-2 px-3 bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-700 hover:to-violet-700 text-white font-black text-xs rounded-xl flex items-center justify-center gap-1.5 shadow-sm shadow-indigo-600/20 active:scale-[0.98] transition-all cursor-pointer"
                                                            >
                                                                <span>VÀO LÀM BÀI</span>
                                                                <Icon name="arrow-right" size={13}/>
                                                            </a>
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                    </div>
                                )}
                            </div>
                        ) : (
                            <div className="bg-white rounded-[2rem] shadow-sm border border-slate-100 p-6 md:p-8">
                                <h2 className="text-xs font-black text-slate-400 uppercase tracking-widest mb-6 flex items-center gap-2">
                                    <Icon name="clipboard-list" size={13}/> Danh sách bài kiểm tra đã làm ({grades.length})
                                </h2>

                                {isLoading ? (
                                    <div className="py-20 text-center text-slate-400 font-bold flex flex-col items-center gap-3">
                                        <Icon name="loader-2" className="animate-spin" size={32}/>
                                        Đang tải bảng điểm...
                                    </div>
                                ) : grades.length === 0 ? (
                                    <div className="py-20 text-center flex flex-col items-center gap-2 text-slate-400 opacity-60">
                                        <Icon name="award" size={48} />
                                        <p className="text-base font-bold">Chưa có kết quả bài làm nào được ghi nhận</p>
                                        <p className="text-xs">Hãy tham gia làm bài tập trên trang chủ để xem điểm tại đây.</p>
                                    </div>
                                ) : (
                                    <div className="overflow-hidden border border-slate-100 rounded-3xl shadow-sm">
                                        <div className="overflow-x-auto">
                                            <table className="w-full text-left border-collapse">
                                                <thead>
                                                    <tr className="bg-slate-50/60 border-b border-slate-100 text-[10px] font-black text-slate-400 uppercase tracking-widest">
                                                        <th className="py-4 pl-5 w-16">STT</th>
                                                        <th className="py-4">Tên bài kiểm tra</th>
                                                        <th className="py-4 w-48">Thời gian vào làm</th>
                                                        <th className="py-4 text-center w-36">Điểm số</th>
                                                        <th className="py-4 pr-5 text-right w-36">Chi tiết</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-slate-100/60">
                                                    {grades.map((item, idx) => (
                                                        <tr key={idx} className="text-xs text-slate-700 hover:bg-slate-50/60 transition-all duration-150">
                                                            <td className="py-4 pl-5 font-bold text-slate-400">{String(idx + 1).padStart(2, '0')}</td>
                                                            <td className="py-4">
                                                                <div className="flex items-center gap-2">
                                                                    <div className="p-2 bg-indigo-50 text-indigo-600 rounded-lg shrink-0"><Icon name="file-text" size={12}/></div>
                                                                    <span className="font-bold text-slate-800 text-sm line-clamp-1">{item.examTitle}</span>
                                                                </div>
                                                            </td>
                                                            <td className="py-4">
                                                                <div className="flex items-center gap-1.5 text-slate-500 font-bold font-sans">
                                                                    <Icon name="clock" size={12} className="text-slate-400"/>
                                                                    <span>{formatTime(item.startTime)}</span>
                                                                </div>
                                                            </td>
                                                            <td className="py-4 text-center">
                                                                {item.hideScore ? (
                                                                    <span className="text-[10px] bg-amber-50 text-amber-600 px-2.5 py-1 rounded-lg font-black uppercase tracking-wider border border-amber-200/40 inline-flex items-center gap-1 shadow-sm">
                                                                        <Icon name="lock" size={9}/> Khóa
                                                                    </span>
                                                                ) : (
                                                                    <span className={`text-xs px-3 py-1.5 rounded-xl font-black shadow-sm border ${parseFloat(item.score) >= 5.0 ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200'}`}>
                                                                        {parseFloat(item.score).toFixed(1)}Đ
                                                                    </span>
                                                                )}
                                                            </td>
                                                            <td className="py-4 pr-5 text-right">
                                                                {!item.hideReview ? (
                                                                    <button onClick={() => setViewingResult(item)} className="px-3.5 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl transition-all text-[9px] font-black uppercase tracking-widest inline-flex items-center gap-1 shadow-sm hover:shadow active:scale-95 duration-150">
                                                                        <Icon name="eye" size={11}/> Xem lại
                                                                    </button>
                                                                ) : (
                                                                    <span className="text-[10px] font-bold text-slate-350 select-none bg-slate-100 px-2 py-1 rounded-md">Ẩn đáp án</span>
                                                                )}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}

                        {/* Footer thông tin bản quyền */}
                        <div className="w-full flex justify-center py-6 mt-8">
                            <div className="px-6 py-2 bg-white/80 border border-slate-200/60 rounded-full backdrop-blur-md shadow-sm">
                                <p className="text-slate-500 font-extrabold text-[10px] uppercase tracking-widest text-center">
                                    &copy; Copyright Thầy Phạm Minh Tuấn - GV Toán TT GDNN - GDTX Quận 12
                                </p>
                            </div>
                        </div>
                    </div>

                    {viewingResult && (
                        <StudentReviewModal result={viewingResult} onClose={() => setViewingResult(null)} />
                    )}

                    {showStudyScores && (
                        <StudentStudyScoresModal user={user} onClose={() => setShowStudyScores(false)} />
                    )}

                    {viewingBonusSemester && (
                        <StudentBonusHistoryModal user={user} semester={viewingBonusSemester} onClose={() => setViewingBonusSemester(null)} />
                    )}

                    {showChangePasswordModal && (
                        <div className="fixed inset-0 z-[120] flex items-center justify-center p-4 animate-in fade-in duration-200">
                            <div className="absolute inset-0 bg-slate-950/60 backdrop-blur-sm" onClick={() => setShowChangePasswordModal(false)}></div>
                            <div className="relative bg-white rounded-[2rem] shadow-2xl w-full max-w-md overflow-hidden border border-slate-100 flex flex-col animate-in zoom-in-95 duration-200">
                                <header className="p-5 border-b border-slate-100 bg-slate-50 flex items-center gap-3 shrink-0">
                                    <div className="p-2 bg-indigo-100 text-indigo-600 rounded-xl">
                                        <Icon name="key" size={16}/>
                                    </div>
                                    <h3 className="text-sm font-black uppercase tracking-widest text-slate-800">Đổi mật khẩu</h3>
                                </header>
                                <form onSubmit={(e) => {
                                    e.preventDefault();
                                    const currentPassword = e.target.currentPassword.value;
                                    const newPassword = e.target.newPassword.value;
                                    const confirmPassword = e.target.confirmPassword.value;
                                    
                                    if (newPassword !== confirmPassword) {
                                        return showAlert("Xác nhận mật khẩu mới không khớp!");
                                    }
                                    
                                    fetch('?action=student_change_password', {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json' },
                                        body: JSON.stringify({ currentPassword, newPassword })
                                    })
                                    .then(r => r.json())
                                    .then(res => {
                                        if (res.success) {
                                            showAlert("Đổi mật khẩu thành công!");
                                            setShowChangePasswordModal(false);
                                        } else {
                                            showAlert(res.message || "Lỗi khi đổi mật khẩu!");
                                        }
                                    });
                                }} className="p-6 space-y-4">
                                    <div>
                                        <label className="block text-[10px] font-black text-slate-400 uppercase tracking-wider mb-1.5 ml-1">Mật khẩu hiện tại:</label>
                                        <input name="currentPassword" type="password" required className="w-full bg-slate-50 px-4 py-3 rounded-xl border border-slate-200 focus:bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none font-bold text-slate-800 text-sm transition-all" />
                                    </div>
                                    <div>
                                        <label className="block text-[10px] font-black text-slate-400 uppercase tracking-wider mb-1.5 ml-1">Mật khẩu mới:</label>
                                        <input name="newPassword" type="password" required className="w-full bg-slate-50 px-4 py-3 rounded-xl border border-slate-200 focus:bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none font-bold text-slate-800 text-sm transition-all" />
                                    </div>
                                    <div>
                                        <label className="block text-[10px] font-black text-slate-400 uppercase tracking-wider mb-1.5 ml-1">Xác nhận mật khẩu mới:</label>
                                        <input name="confirmPassword" type="password" required className="w-full bg-slate-50 px-4 py-3 rounded-xl border border-slate-200 focus:bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none font-bold text-slate-800 text-sm transition-all" />
                                    </div>
                                    <div className="flex gap-3 pt-2">
                                        <button type="button" onClick={() => setShowChangePasswordModal(false)} className="flex-1 py-3 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl font-bold text-xs uppercase tracking-wider transition-all">Hủy</button>
                                        <button type="submit" className="flex-1 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold text-xs uppercase tracking-wider transition-all shadow-md shadow-indigo-600/10">Đồng ý</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    )}
                </div>
            );
        };

        const StudyScoresEditorModal = ({ student, onClose, onSave, showAlert }) => {
            const [selectedSemester, setSelectedSemester] = useState('hk1');
            const initialScores = student.studyScores || { hk1: {}, hk2: {} };
            const defaultScores = { tx1: '', tx2: '', tx3: '', tx4: '', tx5: '', gk: '', ck: '' };
            
            const [scores, setScores] = useState({
                hk1: { ...defaultScores, ...(initialScores.hk1 || {}) },
                hk2: { ...defaultScores, ...(initialScores.hk2 || {}) }
            });
            const [isSaving, setIsSaving] = useState(false);

            const handleSave = () => {
                setIsSaving(true);
                onSave(student.id, scores[selectedSemester], () => {
                    setIsSaving(false);
                    onClose();
                }, selectedSemester);
            };

            const currentScores = scores[selectedSemester];
            const setField = (field, val) => {
                setScores(prev => ({
                    ...prev,
                    [selectedSemester]: {
                        ...prev[selectedSemester],
                        [field]: val
                    }
                }));
            };

            return (
                <div className="fixed inset-0 z-[150] bg-slate-950/60 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-white w-full max-w-md rounded-[2rem] shadow-2xl flex flex-col overflow-hidden border border-slate-100 animate-in zoom-in-95 duration-200">
                        <header className="p-5 border-b flex justify-between items-center bg-sky-50 shrink-0">
                            <div className="flex items-center gap-3">
                                <div className="p-2 bg-sky-100 text-sky-700 rounded-lg"><Icon name="award" size={14}/></div>
                                <div>
                                    <h3 className="text-sm font-black uppercase tracking-widest text-slate-800">Nhập điểm học tập</h3>
                                    <p className="text-[10px] font-bold text-slate-400 mt-0.5">{student.fullName} ({student.class})</p>
                                </div>
                            </div>
                            <button onClick={onClose} className="w-8 h-8 flex items-center justify-center bg-rose-500 text-white hover:bg-rose-600 rounded-full transition-colors shadow-md"><Icon name="x" size={18}/></button>
                        </header>
                        
                        {/* Semester Select */}
                        <div className="flex bg-slate-50 border-b border-slate-100 p-2 gap-2 shrink-0">
                            <button 
                                onClick={() => setSelectedSemester('hk1')}
                                className={`flex-1 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border ${selectedSemester === 'hk1' ? 'bg-white text-indigo-600 border-slate-200 shadow-sm' : 'text-slate-500 border-transparent hover:bg-slate-100/50'}`}
                            >
                                Học kỳ I
                            </button>
                            <button 
                                onClick={() => setSelectedSemester('hk2')}
                                className={`flex-1 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border ${selectedSemester === 'hk2' ? 'bg-white text-indigo-600 border-slate-200 shadow-sm' : 'text-slate-500 border-transparent hover:bg-slate-100/50'}`}
                            >
                                Học kỳ II
                            </button>
                        </div>

                        <div className="p-6 space-y-4 max-h-[50vh] overflow-y-auto custom-scrollbar">
                            <div className="space-y-4">
                                <div>
                                    <label className="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2.5 pb-1 border-b border-slate-100">Điểm thường xuyên (Hệ số 1)</label>
                                    <div className="grid grid-cols-5 gap-1.5 md:gap-2">
                                        <div>
                                            <label className="block text-[8px] font-black text-slate-400 uppercase tracking-wider mb-1 text-center">TX 1</label>
                                            <input type="text" value={currentScores.tx1 || ''} onChange={e => setField('tx1', e.target.value)} className="w-full bg-slate-50 border border-slate-200 px-1 py-2 rounded-xl text-center font-bold outline-none focus:bg-white focus:border-sky-500 text-xs" placeholder="-" />
                                        </div>
                                        <div>
                                            <label className="block text-[8px] font-black text-slate-400 uppercase tracking-wider mb-1 text-center">TX 2</label>
                                            <input type="text" value={currentScores.tx2 || ''} onChange={e => setField('tx2', e.target.value)} className="w-full bg-slate-50 border border-slate-200 px-1 py-2 rounded-xl text-center font-bold outline-none focus:bg-white focus:border-sky-500 text-xs" placeholder="-" />
                                        </div>
                                        <div>
                                            <label className="block text-[8px] font-black text-slate-400 uppercase tracking-wider mb-1 text-center">TX 3</label>
                                            <input type="text" value={currentScores.tx3 || ''} onChange={e => setField('tx3', e.target.value)} className="w-full bg-slate-50 border border-slate-200 px-1 py-2 rounded-xl text-center font-bold outline-none focus:bg-white focus:border-sky-500 text-xs" placeholder="-" />
                                        </div>
                                        <div>
                                            <label className="block text-[8px] font-black text-slate-400 uppercase tracking-wider mb-1 text-center">TX 4</label>
                                            <input type="text" value={currentScores.tx4 || ''} onChange={e => setField('tx4', e.target.value)} className="w-full bg-slate-50 border border-slate-200 px-1 py-2 rounded-xl text-center font-bold outline-none focus:bg-white focus:border-sky-500 text-xs" placeholder="-" />
                                        </div>
                                        <div>
                                            <label className="block text-[8px] font-black text-slate-400 uppercase tracking-wider mb-1 text-center">TX 5</label>
                                            <input type="text" value={currentScores.tx5 || ''} onChange={e => setField('tx5', e.target.value)} className="w-full bg-slate-50 border border-slate-200 px-1 py-2 rounded-xl text-center font-bold outline-none focus:bg-white focus:border-sky-500 text-xs" placeholder="-" />
                                        </div>
                                    </div>
                                </div>
                                <div className="border-t border-slate-100 pt-3">
                                    <label className="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2.5 pb-1 border-b border-slate-100">Điểm định kỳ</label>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div>
                                            <label className="block text-[10px] font-black text-slate-400 tracking-wider mb-1 text-indigo-600 uppercase">Giữa kỳ (GK) - Hệ số 2</label>
                                            <input type="text" value={currentScores.gk || ''} onChange={e => setField('gk', e.target.value)} className="w-full bg-indigo-50/50 border border-indigo-100 px-3 py-2 rounded-xl text-center font-black text-indigo-600 outline-none focus:bg-white focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/20 text-sm" placeholder="-" />
                                        </div>
                                        <div>
                                            <label className="block text-[10px] font-black text-slate-400 tracking-wider mb-1 text-emerald-600 uppercase">Cuối kỳ (CK) - Hệ số 3</label>
                                            <input type="text" value={currentScores.ck || ''} onChange={e => setField('ck', e.target.value)} className="w-full bg-emerald-50/50 border border-emerald-100 px-3 py-2 rounded-xl text-center font-black text-emerald-600 outline-none focus:bg-white focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 text-sm" placeholder="-" />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <footer className="p-4 border-t border-slate-100 bg-slate-50 flex justify-end gap-2 shrink-0">
                            <button onClick={onClose} className="px-5 py-2.5 bg-slate-200 hover:bg-slate-300 text-slate-700 font-black rounded-xl text-[10px] transition-colors uppercase tracking-widest">Hủy</button>
                            <button onClick={handleSave} disabled={isSaving} className="px-5 py-2.5 bg-sky-600 hover:bg-sky-700 text-white font-black rounded-xl text-[10px] transition-all uppercase tracking-widest shadow-md shadow-sky-600/15">{isSaving ? 'Đang lưu...' : 'Lưu điểm'}</button>
                        </footer>
                    </div>
                </div>
            );
        };

        const BonusPointsEditorModal = ({ student, semester, onClose, onSave, showAlert }) => {
            const initialHistory = getBonusHistory(student, semester);
            
            const [history, setHistory] = useState(initialHistory);
            const [reason, setReason] = useState('Cộng điểm học tập');
            const [pointsChange, setPointsChange] = useState(1);
            const [isSaving, setIsSaving] = useState(false);

            const handleAddAdjustment = () => {
                const amount = parseFloat(pointsChange) || 0;
                if (amount === 0) return showAlert("Vui lòng nhập số điểm khác 0!");
                
                const newEntry = {
                    date: new Date().toLocaleString('vi-VN'),
                    amount: amount,
                    reason: reason.trim() || 'Cộng điểm học tập'
                };
                
                setHistory(prev => [...prev, newEntry]);
                setReason('Cộng điểm học tập');
                setPointsChange(1);
            };

            const handleDeleteEntry = (idxToDelete) => {
                setHistory(prev => prev.filter((_, idx) => idx !== idxToDelete));
            };

            const totalPoints = parseFloat(history.reduce((sum, entry) => sum + (parseFloat(entry.amount) || 0), 0).toFixed(2));

            const handleSave = () => {
                setIsSaving(true);
                onSave(student.id, semester, totalPoints, history, () => {
                    setIsSaving(false);
                    onClose();
                });
            };

            const setQuickPoints = (amount) => {
                setPointsChange(amount);
            };

            return (
                <div className="fixed inset-0 z-[150] bg-slate-950/60 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-white w-full max-w-3xl rounded-[2rem] shadow-2xl flex flex-col overflow-hidden border border-slate-100 animate-in zoom-in-95 duration-200 md:h-[500px] h-[85vh]">
                        <header className="p-5 border-b flex justify-between items-center bg-pink-50 shrink-0">
                            <div className="flex items-center gap-3">
                                <div className="p-2 bg-pink-100 text-pink-600 rounded-lg"><Icon name="plus-circle" size={14}/></div>
                                <div>
                                    <h3 className="text-sm font-black uppercase tracking-widest text-slate-800">Quản lý điểm cộng ({semester.toUpperCase()})</h3>
                                    <p className="text-[10px] font-bold text-slate-400 mt-0.5">{student.fullName} ({student.class})</p>
                                </div>
                            </div>
                            <button onClick={onClose} className="w-8 h-8 flex items-center justify-center bg-rose-500 text-white hover:bg-rose-600 rounded-full transition-colors shadow-md"><Icon name="x" size={18}/></button>
                        </header>
                        
                        <div className="flex-1 overflow-y-auto p-6 custom-scrollbar">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-6 h-full">
                                {/* Left Column: Score Total & History */}
                                <div className="space-y-4 flex flex-col h-full justify-between">
                                    <div className="flex justify-between items-center bg-pink-50/40 p-4 rounded-2xl border border-pink-100/60 shrink-0">
                                        <span className="text-xs font-black text-pink-800 uppercase tracking-widest">Tổng điểm cộng</span>
                                        <span className="text-3xl font-black text-pink-600 font-sans tracking-tight">+{totalPoints}</span>
                                    </div>

                                    <div className="space-y-2 text-left flex-1 flex flex-col min-h-[180px]">
                                        <span className="block text-[10px] font-black text-slate-400 uppercase tracking-widest pl-1 shrink-0">Lịch sử điều chỉnh</span>
                                        <div className="border border-slate-100 rounded-2xl flex-1 overflow-y-auto custom-scrollbar divide-y divide-slate-100 bg-slate-50/30">
                                            {history.length === 0 ? (
                                                <p className="p-6 text-center text-xs font-bold text-slate-400 italic">Chưa có lịch sử cộng điểm</p>
                                            ) : (
                                                history.map((entry, idx) => (
                                                    <div key={idx} className="p-3 flex justify-between items-center hover:bg-slate-50 transition-colors">
                                                        <div className="text-left space-y-0.5">
                                                            <p className="font-bold text-xs text-slate-700">{entry.reason}</p>
                                                            <p className="text-[9px] font-medium text-slate-400">{entry.date}</p>
                                                        </div>
                                                        <div className="flex items-center gap-2">
                                                            <span className={`text-xs font-black px-2 py-0.5 rounded-lg border font-sans ${entry.amount >= 0 ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-rose-50 text-rose-700 border-rose-100'}`}>
                                                                {entry.amount >= 0 ? `+${entry.amount}` : entry.amount}
                                                            </span>
                                                            <button onClick={() => handleDeleteEntry(idx)} className="p-1 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-md transition-colors" title="Xóa dòng này">
                                                                <Icon name="trash-2" size={12}/>
                                                            </button>
                                                        </div>
                                                    </div>
                                                ))
                                            )}
                                        </div>
                                    </div>
                                </div>

                                {/* Right Column: Add Adjustment */}
                                <div className="p-5 bg-slate-50 rounded-2xl border border-slate-150 space-y-4 text-left flex flex-col justify-between h-full">
                                    <span className="block text-[10px] font-black text-slate-500 uppercase tracking-widest shrink-0">Thêm điều chỉnh điểm</span>
                                    
                                    <div className="grid grid-cols-2 gap-3 shrink-0">
                                        <div>
                                            <label className="block text-[9px] font-black text-slate-400 uppercase tracking-wider mb-1 ml-1">Lý do điều chỉnh</label>
                                            <input 
                                                type="text" 
                                                value={reason} 
                                                onChange={e => setReason(e.target.value)} 
                                                className="w-full bg-white border border-slate-200 px-3 py-2 rounded-xl text-xs font-bold outline-none focus:border-pink-500 text-slate-700" 
                                                placeholder="Ví dụ: Phát biểu bài..." 
                                            />
                                        </div>
                                        <div>
                                            <label className="block text-[9px] font-black text-slate-400 uppercase tracking-wider mb-1 ml-1">Số điểm (Cộng/Trừ)</label>
                                            <input type="number" step="0.01" value={pointsChange} onChange={e => setPointsChange(parseFloat(e.target.value) || 0)} className="w-full bg-white border border-slate-200 px-3 py-2 rounded-xl text-xs font-black outline-none focus:border-pink-500 text-center text-slate-700" />
                                        </div>
                                    </div>

                                    <div className="space-y-1.5 shrink-0">
                                        <span className="block text-[9px] font-black text-slate-400 uppercase tracking-wider pl-1">Nhập nhanh số điểm</span>
                                        <div className="grid grid-cols-5 gap-2">
                                            <button onClick={() => setQuickPoints(0.25)} className={`py-1.5 rounded-xl text-[10px] font-black transition-all border ${pointsChange === 0.25 ? 'bg-pink-600 text-white border-pink-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-100'}`}>+0.25</button>
                                            <button onClick={() => setQuickPoints(0.5)} className={`py-1.5 rounded-xl text-[10px] font-black transition-all border ${pointsChange === 0.5 ? 'bg-pink-600 text-white border-pink-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-100'}`}>+0.5</button>
                                            <button onClick={() => setQuickPoints(0.75)} className={`py-1.5 rounded-xl text-[10px] font-black transition-all border ${pointsChange === 0.75 ? 'bg-pink-600 text-white border-pink-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-100'}`}>+0.75</button>
                                            <button onClick={() => setQuickPoints(1)} className={`py-1.5 rounded-xl text-[10px] font-black transition-all border ${pointsChange === 1 ? 'bg-pink-600 text-white border-pink-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-100'}`}>+1</button>
                                            <button onClick={() => setQuickPoints(-0.5)} className={`py-1.5 rounded-xl text-[10px] font-black transition-all border ${pointsChange === -0.5 ? 'bg-pink-600 text-white border-pink-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-100'}`}>-0.5</button>
                                        </div>
                                    </div>

                                    <button onClick={handleAddAdjustment} className="w-full py-2.5 bg-pink-50 hover:bg-pink-600 text-pink-600 hover:text-white border border-pink-200 hover:border-pink-600 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all text-center shrink-0">Thêm vào lịch sử</button>
                                </div>
                            </div>
                        </div>

                        <footer className="p-4 border-t border-slate-100 bg-slate-50 flex justify-end gap-2 shrink-0">
                            <button onClick={onClose} className="px-5 py-2.5 bg-slate-200 hover:bg-slate-300 text-slate-700 font-black rounded-xl text-[10px] transition-colors uppercase tracking-widest">Hủy</button>
                            <button onClick={handleSave} disabled={isSaving} className="px-5 py-2.5 bg-pink-600 hover:bg-pink-700 text-white font-black rounded-xl text-[10px] transition-all uppercase tracking-widest shadow-md shadow-pink-600/15">{isSaving ? 'Đang lưu...' : 'Lưu điểm cộng'}</button>
                        </footer>
                    </div>
                </div>
            );
        };

        const EditStudentInfoModal = ({ student, classes = [], onClose, onSaved, showAlert }) => {
            const [formId, setFormId] = useState(student ? (student.id || '') : '');
            const [formFullName, setFormFullName] = useState(student ? (student.fullName || '') : '');
            const [formClass, setFormClass] = useState(student ? (student.class || '') : '');
            const [formPassword, setFormPassword] = useState(student ? (student.password || '') : '');
            const [showPassword, setShowPassword] = useState(false);
            const [isSubmitting, setIsSubmitting] = useState(false);

            const handleResetPassword = () => {
                const cleanName = (formFullName || '').normalize("NFD")
                    .replace(/[\u0300-\u036f]/g, "")
                    .replace(/đ/g, "d")
                    .replace(/Đ/g, "d")
                    .toLowerCase();
                const nameParts = cleanName.split(/\s+/).filter(Boolean);
                const initials = nameParts.map(p => p.charAt(0)).join("");
                const defaultPass = (initials || 'hs') + "123@";
                setFormPassword(defaultPass);
            };

            const handleSubmit = (e) => {
                if (e) e.preventDefault();
                const cleanId = formId.trim();
                const cleanName = formFullName.trim().toUpperCase();
                const cleanClass = formClass.trim().toUpperCase();
                const cleanPass = formPassword.trim();

                if (!cleanId) return showAlert("Mã học sinh không được để trống!");
                if (!cleanName) return showAlert("Họ và tên không được để trống!");
                if (!cleanClass) return showAlert("Lớp không được để trống!");
                if (!cleanPass) return showAlert("Mật khẩu không được để trống!");

                setIsSubmitting(true);
                fetch('?action=update_student_info', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        oldId: student.id,
                        newId: cleanId,
                        fullName: cleanName,
                        class: cleanClass,
                        password: cleanPass
                    })
                })
                .then(r => r.json())
                .then(res => {
                    setIsSubmitting(false);
                    if (res.success) {
                        showAlert("Cập nhật thông tin học sinh thành công!");
                        if (onSaved) {
                            onSaved({
                                ...student,
                                id: cleanId,
                                fullName: cleanName,
                                class: cleanClass,
                                password: cleanPass
                            }, student.id);
                        }
                        onClose();
                    } else {
                        showAlert(res.message || "Lỗi khi cập nhật thông tin học sinh!");
                    }
                })
                .catch(() => {
                    setIsSubmitting(false);
                    showAlert("Lỗi kết nối máy chủ khi lưu thông tin!");
                });
            };

            const classList = Array.from(new Set([
                ...(classes || []).map(c => typeof c === 'string' ? c : (c.className || '')).filter(Boolean),
                formClass
            ])).filter(Boolean).sort();

            return (
                <div className="fixed inset-0 z-[150] bg-slate-950/60 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-white w-full max-w-md rounded-2xl shadow-2xl flex flex-col overflow-hidden border border-slate-100 animate-in zoom-in-95 duration-200">
                        <div className="px-6 py-4 bg-gradient-to-r from-sky-600 via-indigo-600 to-indigo-700 text-white flex justify-between items-center shadow-sm">
                            <div className="flex items-center gap-2.5">
                                <div className="p-2 bg-white/15 rounded-xl">
                                    <Icon name="user-check" size={18} />
                                </div>
                                <div>
                                    <h3 className="font-black text-sm tracking-wide">Chỉnh sửa thông tin học sinh</h3>
                                    <p className="text-[11px] text-sky-100/90 font-medium">Cập nhật Mã HS, Họ tên, Lớp và Mật khẩu</p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={onClose}
                                className="w-8 h-8 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-all"
                            >
                                <Icon name="x" size={16} />
                            </button>
                        </div>

                        <form onSubmit={handleSubmit} className="p-6 space-y-4">
                            <div>
                                <label className="block text-[11px] font-black uppercase tracking-wider text-slate-500 mb-1">
                                    Mã học sinh (ID đăng nhập)
                                </label>
                                <input
                                    type="text"
                                    value={formId}
                                    onChange={e => setFormId(e.target.value)}
                                    placeholder="Ví dụ: 25121201"
                                    className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl font-mono font-bold text-xs text-slate-800 focus:bg-white focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 outline-none transition-all"
                                    required
                                />
                                <p className="text-[10px] text-slate-400 mt-1">Lưu ý: Nếu đổi mã học sinh, điểm số và các thông tin đã lưu vẫn được giữ nguyên.</p>
                            </div>

                            <div>
                                <label className="block text-[11px] font-black uppercase tracking-wider text-slate-500 mb-1">
                                    Họ và tên học sinh
                                </label>
                                <input
                                    type="text"
                                    value={formFullName}
                                    onChange={e => setFormFullName(e.target.value.toUpperCase())}
                                    placeholder="Ví dụ: NGUYỄN VĂN A"
                                    className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl font-bold text-xs text-slate-800 focus:bg-white focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 outline-none transition-all uppercase"
                                    required
                                />
                            </div>

                            <div>
                                <label className="block text-[11px] font-black uppercase tracking-wider text-slate-500 mb-1">
                                    Lớp
                                </label>
                                <div className="flex gap-2">
                                    <input
                                        type="text"
                                        value={formClass}
                                        onChange={e => setFormClass(e.target.value.toUpperCase())}
                                        placeholder="Ví dụ: 12A1"
                                        className="flex-1 px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl font-bold text-xs text-slate-800 focus:bg-white focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 outline-none transition-all uppercase"
                                        required
                                    />
                                    {classList.length > 0 && (
                                        <select
                                            value={formClass}
                                            onChange={e => setFormClass(e.target.value)}
                                            className="px-3 py-2.5 bg-slate-100 border border-slate-200 rounded-xl text-xs font-bold text-slate-700 outline-none cursor-pointer"
                                        >
                                            <option value="">-- Chọn lớp --</option>
                                            {classList.map(c => (
                                                <option key={c} value={c}>{c}</option>
                                            ))}
                                        </select>
                                    )}
                                </div>
                            </div>

                            <div>
                                <div className="flex justify-between items-center mb-1">
                                    <label className="block text-[11px] font-black uppercase tracking-wider text-slate-500">
                                        Mật khẩu đăng nhập
                                    </label>
                                    <button
                                        type="button"
                                        onClick={handleResetPassword}
                                        className="text-[10px] font-bold text-indigo-600 hover:text-indigo-800 flex items-center gap-1 transition-colors"
                                        title="Đặt lại mật khẩu theo quy tắc viết tắt tên + 123@"
                                    >
                                        <Icon name="rotate-ccw" size={10} /> Đặt lại mặc định
                                    </button>
                                </div>
                                <div className="relative">
                                    <input
                                        type={showPassword ? "text" : "password"}
                                        value={formPassword}
                                        onChange={e => setFormPassword(e.target.value)}
                                        placeholder="Mật khẩu học sinh..."
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl font-mono font-bold text-xs text-slate-800 focus:bg-white focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 outline-none transition-all pr-10"
                                        required
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPassword(!showPassword)}
                                        className="absolute right-3 top-2.5 text-slate-400 hover:text-slate-600"
                                    >
                                        <Icon name={showPassword ? "eye-off" : "eye"} size={15} />
                                    </button>
                                </div>
                            </div>

                            {/* Thống kê đăng nhập hệ thống */}
                            <div className="p-3.5 bg-slate-50 border border-slate-200/80 rounded-xl flex items-center justify-between text-xs">
                                <div className="flex items-center gap-2.5">
                                    <div className="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0">
                                        <Icon name="log-in" size={14} />
                                    </div>
                                    <div>
                                        <div className="font-black text-slate-700 uppercase text-[10px] tracking-wider">Số lần đăng nhập</div>
                                        <div className="text-[11px] text-slate-500 font-medium mt-0.5">
                                            {student.lastLogin ? `Lần cuối: ${student.lastLogin}` : 'Chưa từng đăng nhập hệ thống'}
                                        </div>
                                    </div>
                                </div>
                                <span className={`px-2.5 py-1 rounded-full text-xs font-black ${
                                    Number(student.loginCount || 0) > 0 ? 'bg-emerald-100 text-emerald-800 border border-emerald-200' : 'bg-slate-200 text-slate-600'
                                }`}>
                                    {Number(student.loginCount || 0)} lần
                                </span>
                            </div>

                            <div className="pt-3 flex items-center justify-end gap-2.5 border-t border-slate-100">
                                <button
                                    type="button"
                                    onClick={onClose}
                                    disabled={isSubmitting}
                                    className="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold rounded-xl transition-all"
                                >
                                    Hủy bỏ
                                </button>
                                <button
                                    type="submit"
                                    disabled={isSubmitting}
                                    className="px-5 py-2.5 bg-gradient-to-r from-sky-600 to-indigo-600 hover:from-sky-700 hover:to-indigo-700 text-white text-xs font-black rounded-xl shadow-md shadow-indigo-500/20 transition-all flex items-center gap-1.5"
                                >
                                    {isSubmitting ? (
                                        <>
                                            <Icon name="loader-2" className="animate-spin" size={14} />
                                            Đang lưu...
                                        </>
                                    ) : (
                                        <>
                                            <Icon name="check" size={14} />
                                            Lưu thay đổi
                                        </>
                                    )}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            );
        };

        const StudentAccountModal = ({ onClose, showAlert, showConfirm, showDangerConfirm, globalClasses = [] }) => {
            const [students, setStudents] = useState([]);
            const [searchQuery, setSearchQuery] = useState('');
            const [isLoading, setIsLoading] = useState(true);
            const [promptDialog, setPromptDialog] = useState({ isOpen: false, title: '', defaultValue: '', placeholder: '', onConfirm: null });
            const [showGuide, setShowGuide] = useState(false);
            const [selectedClass, setSelectedClass] = useState(null);
            const [showSyncModal, setShowSyncModal] = useState(false);
            const [editingStudyScores, setEditingStudyScores] = useState(null);
            const [editingStudentInfo, setEditingStudentInfo] = useState(null);


            const handleSaveStudyScores = (studentId, scores, callback, semester = 'hk1') => {
                fetch('?action=update_student_study_scores', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: studentId, scores, semester })
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        showAlert("Cập nhật điểm học tập thành công!");
                        loadStudents();
                        if (callback) callback();
                    } else {
                        showAlert(res.message || "Lỗi khi cập nhật điểm!");
                    }
                });
            };

            const handleClassSync = (c) => {
                if (!c.students || c.students.length === 0) {
                    return showAlert(`Lớp ${c.className} không có học sinh nào!`);
                }
                
                setPromptDialog({
                    isOpen: true,
                    title: `Đồng bộ lớp ${c.className} - Nhập 2 số đầu ID`,
                    defaultValue: "25",
                    placeholder: "Ví dụ: 25 đại diện năm 2025",
                    onConfirm: (prefix) => {
                        if (!prefix || prefix.trim().length !== 2 || isNaN(prefix)) {
                            return showAlert("Tiền tố ID bắt buộc phải có đúng 2 số!");
                        }
                        
                        const accounts = c.students.map((item, idx) => {
                            const classMatches = c.className.match(/\d+/g) || [];
                            const grade = classMatches[0] || "12";
                            let room = classMatches[1] || "00";
                            if (room.length === 1) room = "0" + room;
                            const classCode = grade + room;
                            
                            const sttVal = idx + 1;
                            const sttCode = sttVal.toString().padStart(2, '0');
                            const studentId = prefix.trim() + classCode + sttCode;
                            
                            const fullName = `${item.lastName || ''} ${item.firstName || ''}`.trim().toUpperCase();
                            const cleanName = fullName.normalize("NFD")
                                .replace(/[\u0300-\u036f]/g, "")
                                .replace(/đ/g, "d")
                                .replace(/Đ/g, "d")
                                .toLowerCase();
                            const nameParts = cleanName.split(/\s+/).filter(Boolean);
                            const initials = nameParts.map(p => p.charAt(0)).join("");
                            const password = initials + "123@";
                            
                            return {
                                id: studentId,
                                fullName: fullName,
                                class: c.className,
                                password: password
                            };
                        });
                        
                        fetch('?action=create_student_accounts', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ accounts })
                        })
                        .then(r => r.json())
                        .then(res => {
                            if (res.success) {
                                showAlert(`Đồng bộ thành công! Đã tạo ${res.added} tài khoản mới cho lớp ${c.className}.`);
                                loadStudents();
                                setShowSyncModal(false);
                            } else {
                                showAlert(res.message || "Lỗi khi đồng bộ!");
                            }
                        });
                    }
                });
            };

            const deleteClassStudents = (className, e) => {
                e.stopPropagation();
                showDangerConfirm(`Xóa TOÀN BỘ tài khoản học sinh thuộc lớp ${className}?\nHành động này sẽ xóa tất cả học sinh lớp này khỏi hệ thống và không thể khôi phục.`, () => {
                    fetch('?action=delete_class_students', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ class: className })
                    })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            showAlert(`Đã xóa thành công ${res.deletedCount} tài khoản thuộc lớp ${className}!`);
                            setSelectedClass(null);
                            loadStudents();
                        } else {
                            showAlert(res.message || "Lỗi khi xóa!");
                        }
                    });
                });
            };

            const resetToDefaultPassword = (student) => {
                const cleanName = student.fullName.normalize("NFD")
                    .replace(/[\u0300-\u036f]/g, "")
                    .replace(/đ/g, "d")
                    .replace(/Đ/g, "d")
                    .toLowerCase();
                const nameParts = cleanName.split(/\s+/).filter(Boolean);
                const initials = nameParts.map(p => p.charAt(0)).join("");
                const defaultPassword = initials + "123@";
                
                showConfirm(`Khôi phục mật khẩu học sinh ${student.fullName} về mặc định (${defaultPassword})?`, () => {
                    fetch('?action=update_student_password', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: student.id, password: defaultPassword })
                    })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            showAlert("Đã khôi phục mật khẩu mặc định thành công!");
                            loadStudents();
                        } else {
                            showAlert(res.message || "Lỗi khôi phục mật khẩu!");
                        }
                    });
                });
            };

            const loadStudents = () => {
                setIsLoading(true);
                fetch('?action=list_students')
                    .then(r => r.json())
                    .then(d => {
                        setStudents(Array.isArray(d) ? d : []);
                        setIsLoading(false);
                    })
                    .catch(() => {
                        showAlert("Lỗi kết nối máy chủ!");
                        setIsLoading(false);
                    });
            };

            useEffect(() => { loadStudents(); }, []);

            const handleExcelUpload = (e) => {
                const file = e.target.files[0];
                if (!file) return;
                const reader = new FileReader();
                reader.onload = (evt) => {
                    try {
                        const bstr = evt.target.result;
                        const wb = window.XLSX.read(bstr, { type: 'binary' });
                        const wsname = wb.SheetNames[0];
                        const ws = wb.Sheets[wsname];
                        const data = window.XLSX.utils.sheet_to_json(ws, { header: 1 });
                        
                        const parsedList = [];
                        for (let i = 1; i < data.length; i++) {
                            const row = data[i];
                            if (row && row.length >= 3 && row[2]) {
                                parsedList.push({
                                    stt: String(row[0] || '').trim(),
                                    class: String(row[1] || '').trim(),
                                    name: String(row[2] || '').trim().toUpperCase()
                                });
                            }
                        }
                        
                        if (parsedList.length === 0) {
                            return showAlert("Không tìm thấy học sinh nào hợp lệ! File Excel cần đúng 3 cột: STT | LỚP | Họ và tên");
                        }
                        
                        showConfirm(`Đã đọc ${parsedList.length} học sinh. Bạn có muốn sinh tài khoản đăng nhập cho danh sách này không?`, () => {
                            setPromptDialog({
                                isOpen: true,
                                title: "Nhập 2 số đầu của mã ID",
                                defaultValue: "25",
                                placeholder: "Ví dụ: 25 đại diện cho năm 2025",
                                onConfirm: (prefix) => {
                                    if (!prefix || prefix.trim().length !== 2 || isNaN(prefix)) {
                                        return showAlert("Tiền tố ID bắt buộc phải có đúng 2 số!");
                                    }
                                    
                                    const accounts = parsedList.map(item => {
                                        const classMatches = item.class.match(/\d+/g) || [];
                                        const grade = classMatches[0] || "12";
                                        let room = classMatches[1] || "00";
                                        if (room.length === 1) room = "0" + room;
                                        const classCode = grade + room;
                                        
                                        const sttVal = parseInt(item.stt) || 1;
                                        const sttCode = sttVal.toString().padStart(2, '0');
                                        const studentId = prefix.trim() + classCode + sttCode;
                                        
                                        const cleanName = item.name.normalize("NFD")
                                            .replace(/[\u0300-\u036f]/g, "")
                                            .replace(/đ/g, "d")
                                            .replace(/Đ/g, "d")
                                            .toLowerCase();
                                        const nameParts = cleanName.split(/\s+/).filter(Boolean);
                                        const initials = nameParts.map(p => p.charAt(0)).join("");
                                        const password = initials + "123@";
                                        
                                        return {
                                            id: studentId,
                                            password: password,
                                            fullName: item.name,
                                            class: item.class,
                                            stt: item.stt
                                        };
                                    });
                                    
                                    fetch('?action=create_student_accounts', {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json' },
                                        body: JSON.stringify({ accounts })
                                    })
                                    .then(r => r.json())
                                    .then(res => {
                                        if (res.success) {
                                            showAlert(`Tạo thành công ${res.added} tài khoản học sinh!`);
                                            loadStudents();
                                        } else {
                                            showAlert("Lỗi khi lưu tài khoản!");
                                        }
                                    });
                                }
                            });
                        });
                        
                    } catch (err) {
                        showAlert("Lỗi đọc file Excel!");
                    }
                };
                reader.readAsBinaryString(file);
                e.target.value = null;
            };

            const downloadTemplate = () => {
                const XLSX = window.XLSX;
                const sampleData = [
                    ["STT", "LỚP", "Họ và tên"],
                    ["01", "12A26", "Đoàn Thị Vân Anh"],
                    ["02", "12A26", "Nguyễn Văn B"]
                ];
                const ws = XLSX.utils.aoa_to_sheet(sampleData);
                const wb = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(wb, ws, "Mẫu DS Học sinh");
                XLSX.writeFile(wb, "mau_danh_sach_cap_tai_khoan_hs.xlsx");
            };

            const deleteStudent = (id, fullName) => {
                showDangerConfirm(`Xóa vĩnh viễn tài khoản của học sinh ${fullName} (${id})?`, () => {
                    fetch('?action=delete_student_account', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id })
                    })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            loadStudents();
                        } else {
                            showAlert(res.message || "Lỗi khi xóa!");
                        }
                    });
                });
            };

            const changePassword = (student) => {
                setPromptDialog({
                    isOpen: true,
                    title: `Mật khẩu mới cho: ${student.fullName}`,
                    defaultValue: student.password,
                    placeholder: "Nhập mật khẩu mới...",
                    onConfirm: (newPassword) => {
                        if (!newPassword || newPassword.trim() === "") return showAlert("Mật khẩu không được để trống!");
                        fetch('?action=update_student_password', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ id: student.id, password: newPassword.trim() })
                        })
                        .then(r => r.json())
                        .then(res => {
                            if (res.success) {
                                showAlert("Đổi mật khẩu thành công!");
                                loadStudents();
                            } else {
                                showAlert(res.message || "Lỗi khi đổi mật khẩu!");
                            }
                        });
                    }
                });
            };

            const uniqueClasses = (globalClasses || []).map(c => c.className).filter(Boolean).sort();
            const [loginFilter, setLoginFilter] = useState('all');

            const currentClassStudents = students.filter(s => selectedClass === 'all' || s.class === selectedClass);

            const filtered = students.filter(s => {
                const matchSearch = (s.fullName || '').toLowerCase().includes(searchQuery.toLowerCase()) ||
                                    (s.id || '').toLowerCase().includes(searchQuery.toLowerCase()) ||
                                    (s.class || '').toLowerCase().includes(searchQuery.toLowerCase());
                const matchClass = selectedClass === 'all' || s.class === selectedClass;
                const matchLogin = loginFilter === 'all' ? true :
                                   loginFilter === 'active' ? (Number(s.loginCount || 0) > 0) :
                                   (Number(s.loginCount || 0) === 0);
                return matchSearch && matchClass && matchLogin;
            });

            return (
                <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-3 md:p-6">
                    <div className="bg-white w-full max-w-[1400px] h-[92vh] md:h-[88vh] rounded-[2rem] shadow-2xl flex flex-col overflow-hidden animate-in zoom-in duration-300 relative border border-slate-100">
                        <header className="p-5 border-b flex justify-between items-center bg-slate-50 shrink-0">
                            <div className="flex items-center gap-3">
                                <div className="p-2.5 bg-fuchsia-100 text-fuchsia-600 rounded-xl"><Icon name="users" size={16}/></div>
                                <div>
                                    <h2 className="text-base md:text-lg font-black uppercase tracking-widest text-slate-800">Quản lý tài khoản Học sinh</h2>
                                    <p className="text-[10px] md:text-xs font-bold text-slate-400 mt-0.5">Tải file Excel mẫu và cấp tài khoản tự động hàng loạt</p>
                                </div>
                            </div>
                            <button type="button" onClick={onClose} className="w-8 h-8 flex items-center justify-center bg-rose-500 text-white hover:bg-rose-600 rounded-full transition-colors shadow-md"><Icon name="x" size={18}/></button>
                        </header>

                        <div className="p-4 md:p-5 bg-white border-b border-slate-100 flex flex-col lg:flex-row justify-between items-center gap-4 shrink-0">
                            <div className="flex gap-2.5 w-full lg:w-auto flex-1 max-w-xl">
                                <div className="relative flex-1">
                                    <input 
                                        type="text" 
                                        placeholder="Tìm theo Tên, Lớp hoặc ID học sinh..." 
                                        value={searchQuery}
                                        onChange={e => setSearchQuery(e.target.value)}
                                        className="w-full bg-slate-50 border border-slate-200 px-4 py-2.5 pl-10 rounded-xl outline-none focus:bg-white focus:border-fuchsia-500 text-xs font-bold transition-all text-slate-700" 
                                    />
                                    <div className="absolute left-3.5 top-3.5 text-slate-400"><Icon name="search" size={12}/></div>
                                </div>
                                <select 
                                    value={loginFilter} 
                                    onChange={e => setLoginFilter(e.target.value)}
                                    className="bg-slate-50 border border-slate-200 px-3.5 py-2.5 rounded-xl text-xs font-bold text-slate-700 outline-none focus:bg-white focus:border-fuchsia-500 transition-all cursor-pointer shrink-0"
                                    title="Lọc học sinh theo trạng thái đăng nhập"
                                >
                                    <option value="all">Tất cả ({currentClassStudents.length})</option>
                                    <option value="active">Đã đăng nhập ({currentClassStudents.filter(s => Number(s.loginCount || 0) > 0).length})</option>
                                    <option value="inactive">Chưa đăng nhập ({currentClassStudents.filter(s => Number(s.loginCount || 0) === 0).length})</option>
                                </select>
                            </div>

                            <div className="flex gap-2.5 w-full lg:w-auto flex-wrap sm:flex-nowrap justify-end">
                                <button onClick={() => setShowGuide(true)} className="flex-1 sm:flex-none justify-center px-4 py-2.5 bg-amber-50 hover:bg-amber-100 text-amber-700 border border-amber-200 font-black uppercase tracking-widest text-[10px] rounded-xl transition-all flex items-center gap-1.5">
                                    <Icon name="help-circle" size={12}/> Hướng dẫn
                                </button>
                                <button onClick={() => setShowSyncModal(true)} className="flex-1 sm:flex-none justify-center px-4 py-2.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 font-black uppercase tracking-widest text-[10px] rounded-xl transition-all flex items-center gap-1.5">
                                    <Icon name="refresh-cw" size={12}/> Lấy từ Lớp Học
                                </button>
                                <button onClick={downloadTemplate} className="flex-1 sm:flex-none justify-center px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-200 font-black uppercase tracking-widest text-[10px] rounded-xl transition-all flex items-center gap-1.5">
                                    <Icon name="download" size={12}/> Tải File Mẫu
                                </button>
                                <label className="flex-1 sm:flex-none justify-center px-4 py-2.5 bg-fuchsia-50 hover:bg-fuchsia-600 hover:text-white text-fuchsia-600 border border-fuchsia-200 font-black uppercase tracking-widest text-[10px] rounded-xl transition-all flex items-center gap-1.5 cursor-pointer text-center">
                                    <Icon name="upload" size={12}/> Tải lên Excel (.xlsx)
                                    <input type="file" accept=".xlsx, .xls" className="hidden" onChange={handleExcelUpload} />
                                </label>
                            </div>
                        </div>

                        <div className="flex-1 flex overflow-hidden">
                            {/* Cột trái: Danh sách lớp */}
                            <div className="w-56 md:w-60 border-r border-slate-100 bg-slate-50/50 flex flex-col shrink-0">
                                <div className="p-4 border-b border-slate-100 bg-slate-50 shrink-0">
                                    <h3 className="text-[10px] font-black text-slate-400 uppercase tracking-widest flex items-center gap-1.5"><Icon name="folder" size={12}/> Danh sách lớp</h3>
                                </div>
                                <div className="flex-1 overflow-y-auto p-2 space-y-1 custom-scrollbar">
                                    <button 
                                        onClick={() => setSelectedClass('all')}
                                        className={`w-full text-left px-3.5 py-2.5 rounded-xl font-bold text-xs flex justify-between items-center transition-all ${selectedClass === 'all' ? 'bg-fuchsia-600 text-white shadow-md shadow-fuchsia-500/20' : 'text-slate-600 hover:bg-slate-100/80'}`}
                                    >
                                        <span>Tất cả học sinh</span>
                                        <span className={`text-[9px] px-2 py-0.5 rounded-full font-bold ${selectedClass === 'all' ? 'bg-white/20 text-white' : 'bg-slate-200 text-slate-500'}`}>{students.length}</span>
                                    </button>
                                    {uniqueClasses.map(c => {
                                        const count = students.filter(s => s.class === c).length;
                                        return (
                                            <div 
                                                key={c}
                                                onClick={() => setSelectedClass(c)}
                                                className={`group w-full px-3.5 py-2.5 rounded-xl font-bold text-xs flex justify-between items-center transition-all cursor-pointer ${selectedClass === c ? 'bg-fuchsia-600 text-white shadow-md shadow-fuchsia-500/20' : 'text-slate-600 hover:bg-slate-100/80'}`}
                                            >
                                                <span className="truncate">{c}</span>
                                                <div className="flex items-center gap-1.5 shrink-0">
                                                    <span className={`text-[9px] px-2 py-0.5 rounded-full font-bold ${selectedClass === c ? 'bg-white/20 text-white' : 'bg-slate-200 text-slate-500'}`}>{count}</span>
                                                    <button 
                                                        onClick={(e) => deleteClassStudents(c, e)} 
                                                        className={`p-1 rounded-md transition-all ${selectedClass === c ? 'text-white/60 hover:text-white hover:bg-white/10' : 'text-slate-400 hover:text-rose-600 hover:bg-rose-50'}`}
                                                        title={`Xóa toàn bộ học sinh lớp ${c}`}
                                                    >
                                                        <Icon name="trash-2" size={12}/>
                                                    </button>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Cột phải: Bảng tài khoản */}
                            <div className="flex-1 flex flex-col overflow-hidden bg-white">
                                <div className="flex-1 overflow-y-auto p-4 md:p-6 custom-scrollbar">
                                    {selectedClass === null ? (
                                        <div className="py-20 text-center flex flex-col items-center justify-center text-slate-400 opacity-60">
                                            <Icon name="folder-open" size={48} className="mb-3" />
                                            <p className="text-sm font-bold uppercase tracking-wider">Vui lòng chọn một lớp ở cột bên trái</p>
                                            <p className="text-xs mt-1">Chọn lớp hoặc "Tất cả học sinh" để xem danh sách tài khoản.</p>
                                        </div>
                                    ) : isLoading ? (
                                        <div className="py-20 text-center text-slate-400 font-bold flex flex-col items-center gap-2">
                                            <Icon name="loader-2" className="animate-spin" size={24}/>
                                            Đang tải dữ liệu...
                                        </div>
                                    ) : filtered.length === 0 ? (
                                        <div className="py-20 text-center flex flex-col items-center gap-2 text-slate-400 opacity-60">
                                            <Icon name="user-x" size={32} />
                                            <p className="text-sm font-bold">Không tìm thấy tài khoản học sinh nào</p>
                                        </div>
                                    ) : (
                                        <div className="overflow-x-auto">
                                            <table className="w-full text-left border-collapse min-w-[950px]">
                                                <thead>
                                                    <tr className="border-b border-slate-100 text-[10px] font-black text-slate-400 uppercase tracking-widest bg-slate-50/50">
                                                        <th className="py-3.5 pl-4 pr-2 text-center w-12">STT</th>
                                                        <th className="py-3.5 px-3 whitespace-nowrap">ID (Tài khoản)</th>
                                                        <th className="py-3.5 px-4 min-w-[180px]">Họ và tên</th>
                                                        <th className="py-3.5 px-3 text-center whitespace-nowrap">Lớp</th>
                                                        <th className="py-3.5 px-3 whitespace-nowrap">Mật khẩu</th>
                                                        <th className="py-3.5 px-4 text-center whitespace-nowrap">Số lần ĐN</th>
                                                        <th className="py-3.5 px-3 whitespace-nowrap">Giáo viên cấp</th>
                                                        <th className="py-3.5 pr-4 pl-2 text-right whitespace-nowrap">Thao tác</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-slate-100/50">
                                                    {filtered.map((item, idx) => (
                                                        <tr key={item.id} className="text-xs text-slate-700 hover:bg-slate-50/60 transition-colors">
                                                            <td className="py-3 pl-4 pr-2 text-center font-bold text-slate-400">{idx + 1}</td>
                                                            <td className="py-3 px-3 font-mono font-bold text-indigo-600 whitespace-nowrap">{item.id}</td>
                                                            <td className="py-3 px-4 font-bold text-slate-800">{item.fullName}</td>
                                                            <td className="py-3 px-3 text-center font-bold text-slate-500 whitespace-nowrap">{item.class}</td>
                                                            <td className="py-3 px-3 font-mono font-bold text-emerald-600 whitespace-nowrap">{item.password}</td>
                                                            <td className="py-3 px-4 text-center whitespace-nowrap">
                                                                <span 
                                                                    className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] ${
                                                                        Number(item.loginCount || 0) > 0 
                                                                            ? 'bg-emerald-50 text-emerald-700 border border-emerald-200/80 font-black shadow-xs' 
                                                                            : 'bg-slate-100 text-slate-400 font-bold'
                                                                    }`}
                                                                    title={item.lastLogin ? `Lần đăng nhập gần nhất: ${item.lastLogin}` : 'Chưa từng đăng nhập hệ thống'}
                                                                >
                                                                    <Icon name="log-in" size={11} className={Number(item.loginCount || 0) > 0 ? 'text-emerald-600' : 'text-slate-400'} />
                                                                    <span>{Number(item.loginCount || 0)} lần</span>
                                                                </span>
                                                                {item.lastLogin && (
                                                                    <div className="text-[9px] text-slate-400 font-medium mt-0.5 tracking-tight" title={`Đăng nhập gần nhất: ${item.lastLogin}`}>
                                                                        {item.lastLogin}
                                                                    </div>
                                                                )}
                                                            </td>
                                                            <td className="py-3 px-3 text-[11px] text-slate-400 font-medium whitespace-nowrap">{item.teacher || 'thaytuan@admin'}</td>
                                                            
                                                            <td className="py-3 pr-4 pl-2 text-right whitespace-nowrap">
                                                                <div className="flex justify-end items-center gap-1.5">
                                                                    <button onClick={() => setEditingStudentInfo(item)} className="p-1.5 bg-sky-50 text-sky-600 rounded-lg hover:bg-sky-600 hover:text-white border border-sky-100 transition-all font-bold" title="Chỉnh sửa thông tin học sinh (Mã HS, Họ tên, Lớp, Mật khẩu)">
                                                                        <Icon name="edit-3" size={12}/>
                                                                    </button>
                                                                    <button onClick={() => resetToDefaultPassword(item)} className="p-1.5 bg-amber-50 text-amber-600 rounded-lg hover:bg-amber-500 hover:text-white border border-amber-100 transition-all font-bold" title="Reset về mật khẩu mặc định">
                                                                        <Icon name="rotate-ccw" size={12}/>
                                                                    </button>
                                                                    <button onClick={() => changePassword(item)} className="p-1.5 bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-600 hover:text-white border border-blue-100 transition-all font-bold" title="Đổi mật khẩu tùy chọn">
                                                                        <Icon name="key" size={12}/>
                                                                    </button>
                                                                    <button onClick={() => setEditingStudyScores(item)} className="p-1.5 bg-sky-50 text-sky-600 rounded-lg hover:bg-sky-600 hover:text-white border border-sky-100 transition-all font-bold" title="Nhập điểm học tập">
                                                                        <Icon name="award" size={12}/>
                                                                    </button>
                                                                    <button onClick={() => deleteStudent(item.id, item.fullName)} className="p-1.5 bg-rose-50 text-rose-600 rounded-lg hover:bg-rose-600 hover:text-white border border-rose-100 transition-all" title="Xóa tài khoản">
                                                                        <Icon name="trash-2" size={12}/>
                                                                    </button>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>

                        {showSyncModal && (
                            <div className="fixed inset-0 z-[130] bg-slate-950/60 backdrop-blur-sm flex items-center justify-center p-4 animate-in fade-in duration-200">
                                <div className="bg-white w-full max-w-md rounded-[2rem] shadow-2xl flex flex-col overflow-hidden border border-slate-100 animate-in zoom-in-95 duration-200">
                                    <header className="p-5 border-b flex justify-between items-center bg-indigo-50 shrink-0">
                                        <div className="flex items-center gap-3">
                                            <div className="p-2 bg-indigo-100 text-indigo-700 rounded-lg"><Icon name="refresh-cw" size={14}/></div>
                                            <h3 className="text-sm font-black uppercase tracking-widest text-slate-800 font-sans">Lấy danh sách từ lớp học</h3>
                                        </div>
                                        <button onClick={() => setShowSyncModal(false)} className="w-8 h-8 flex items-center justify-center bg-rose-500 text-white hover:bg-rose-600 rounded-full transition-colors shadow-md"><Icon name="x" size={18}/></button>
                                    </header>
                                    <div className="p-6 overflow-y-auto space-y-3 text-xs max-h-[50vh] custom-scrollbar">
                                        <p className="text-slate-500 font-bold mb-2">Chọn một lớp học đã tạo ở hệ thống để tự động cấp tài khoản học sinh:</p>
                                        {globalClasses.length === 0 ? (
                                            <div className="text-center py-10 text-slate-400 font-bold">Chưa có lớp học nào được tạo trong Quản lý Lớp.</div>
                                        ) : (
                                            globalClasses.map(c => (
                                                <div key={c.id} className="p-4 bg-slate-50 hover:bg-indigo-50/30 rounded-2xl border border-slate-100 hover:border-indigo-100 flex justify-between items-center transition-all group">
                                                    <div>
                                                        <h4 className="font-black text-slate-800 text-sm">{c.className}</h4>
                                                        <p className="text-[10px] font-bold text-slate-400 mt-0.5">{c.students?.length || 0} học sinh trong danh sách</p>
                                                    </div>
                                                    <button onClick={() => handleClassSync(c)} className="px-3.5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-[10px] font-black uppercase tracking-widest rounded-xl transition-all shadow-md shadow-indigo-600/10">Sinh TK</button>
                                                </div>
                                            ))
                                        )}
                                    </div>
                                    <footer className="p-4 border-t border-slate-100 bg-slate-50 flex justify-end shrink-0">
                                        <button onClick={() => setShowSyncModal(false)} className="px-5 py-2.5 bg-slate-200 hover:bg-slate-300 text-slate-700 font-black rounded-xl text-[10px] transition-colors uppercase tracking-widest">Đóng</button>
                                    </footer>
                                </div>
                            </div>
                        )}

                        {editingStudyScores && (
                            <StudyScoresEditorModal 
                                student={editingStudyScores} 
                                onClose={() => setEditingStudyScores(null)} 
                                onSave={handleSaveStudyScores}
                                showAlert={showAlert}
                            />
                        )}


                        {showGuide && (
                            <div className="fixed inset-0 z-[130] bg-slate-950/60 backdrop-blur-sm flex items-center justify-center p-4">
                                <div className="bg-white w-full max-w-lg rounded-[2rem] shadow-2xl flex flex-col overflow-hidden border border-slate-100 animate-in zoom-in-95 duration-200">
                                    <header className="p-5 border-b flex justify-between items-center bg-amber-50 shrink-0">
                                        <div className="flex items-center gap-3">
                                            <div className="p-2 bg-amber-100 text-amber-700 rounded-lg"><Icon name="help-circle" size={18}/></div>
                                            <h3 className="text-sm font-black uppercase tracking-widest text-slate-800">Hướng dẫn cấp tài khoản</h3>
                                        </div>
                                        <button onClick={() => setShowGuide(false)} className="w-8 h-8 flex items-center justify-center bg-rose-500 text-white hover:bg-rose-600 rounded-full transition-colors shadow-md"><Icon name="x" size={18}/></button>
                                    </header>
                                    <div className="p-6 overflow-y-auto space-y-4 text-xs text-slate-600 max-h-[60vh] custom-scrollbar">
                                        <div className="space-y-2">
                                            <h4 className="font-black uppercase text-slate-800 flex items-center gap-1"><Icon name="file-text" size={14} className="text-amber-600"/> 1. Định dạng File Mẫu Excel</h4>
                                            <p className="leading-relaxed font-medium">Tệp Excel tải lên cần chứa tối thiểu 3 cột theo đúng thứ tự (bắt đầu từ dòng thứ 2 sau tiêu đề):</p>
                                            <ul className="list-disc list-inside pl-2 space-y-1 font-semibold text-slate-700">
                                                <li>Cột A (STT): Số thứ tự học sinh (Ví dụ: 01, 02...)</li>
                                                <li>Cột B (LỚP): Tên lớp học sinh (Ví dụ: 12A26, 11A3...)</li>
                                                <li>Cột C (Họ và tên): Họ tên đầy đủ (Ví dụ: Đoàn Thị Vân Anh...)</li>
                                            </ul>
                                        </div>
                                        <div className="space-y-2">
                                            <h4 className="font-black uppercase text-slate-800 flex items-center gap-1"><Icon name="key" size={14} className="text-indigo-600"/> 2. Quy tắc tạo ID Học sinh tự động</h4>
                                            <p className="leading-relaxed font-medium">Học sinh được cấp mã đăng nhập gồm 8 chữ số dựa trên tiền tố năm và khối lớp:</p>
                                            <div className="bg-slate-50 p-3 rounded-xl border border-slate-100 space-y-1.5 font-mono text-[11px] text-slate-800">
                                                <div><strong>Mã lớp YYGGCCSS:</strong></div>
                                                <div>• <strong>YY:</strong> Tiền tố giáo viên nhập (Ví dụ: 25 đại diện năm 2025)</div>
                                                <div>• <strong>GG:</strong> Khối học sinh (Ví dụ: 12)</div>
                                                <div>• <strong>CC:</strong> Số hiệu lớp (Ví dụ: 26)</div>
                                                <div>• <strong>SS:</strong> Số thứ tự STT (Ví dụ: 01)</div>
                                                <div className="text-indigo-600 font-bold mt-1">Lớp 12A26, STT 01 & Tiền tố 25 → ID: 25122601</div>
                                            </div>
                                        </div>
                                        <div className="space-y-2">
                                            <h4 className="font-black uppercase text-slate-800 flex items-center gap-1"><Icon name="lock" size={14} className="text-emerald-600"/> 3. Quy tắc tạo Mật khẩu tự động</h4>
                                            <p className="leading-relaxed font-medium">Mật khẩu được tạo tự động từ viết tắt chữ cái đầu của họ tên (không dấu) + đuôi <span className="font-bold text-emerald-600">123@</span>:</p>
                                            <div className="bg-slate-50 p-3 rounded-xl border border-slate-100 font-semibold text-slate-700">
                                                Ví dụ: <span className="text-slate-800">Đoàn Thị Vân Anh</span> → viết tắt là <span className="text-emerald-600 font-bold">dtva</span> → Mật khẩu: <span className="text-emerald-600 font-black font-mono text-xs">dtva123@</span>
                                            </div>
                                        </div>
                                        <div className="space-y-2">
                                            <h4 className="font-black uppercase text-slate-800 flex items-center gap-1"><Icon name="monitor" size={14} className="text-blue-600"/> 4. Cách học sinh đăng nhập</h4>
                                            <p className="leading-relaxed font-medium">Học sinh truy cập vào đúng đường dẫn quản trị của Giáo viên (ví dụ: <span className="font-mono bg-slate-50 px-1 py-0.5 rounded border">admin.php</span>), nhập ID và Mật khẩu vừa sinh để đăng nhập xem bảng điểm cá nhân.</p>
                                        </div>
                                    </div>
                                    <footer className="p-4 border-t border-slate-100 bg-slate-50 flex justify-end shrink-0">
                                        <button onClick={() => setShowGuide(false)} className="px-5 py-2.5 bg-slate-200 hover:bg-slate-300 text-slate-700 font-black rounded-xl text-[10px] transition-colors uppercase tracking-widest">Đóng hướng dẫn</button>
                                    </footer>
                                </div>
                            </div>
                        )}

                        {promptDialog.isOpen && (
                            <div className="fixed inset-0 z-[120] flex items-center justify-center p-4 animate-in fade-in duration-200">
                                <div className="absolute inset-0 bg-slate-950/60 backdrop-blur-sm" onClick={() => setPromptDialog({ ...promptDialog, isOpen: false })}></div>
                                <div className="relative bg-white rounded-[2rem] shadow-2xl w-full max-w-md overflow-hidden border border-slate-100 flex flex-col animate-in zoom-in-95 duration-200">
                                    <header className="p-5 border-b border-slate-100 bg-slate-50 flex items-center gap-3 shrink-0">
                                        <div className="p-2 bg-indigo-100 text-indigo-600 rounded-xl">
                                            <Icon name="edit-3" size={16}/>
                                        </div>
                                        <h3 className="text-sm font-black uppercase tracking-widest text-slate-800">{promptDialog.title}</h3>
                                    </header>
                                    <form onSubmit={(e) => {
                                        e.preventDefault();
                                        const val = e.target.inputVal.value;
                                        promptDialog.onConfirm(val);
                                        setPromptDialog({ ...promptDialog, isOpen: false });
                                    }} className="p-6 space-y-6">
                                        <div>
                                            <input 
                                                name="inputVal" 
                                                type="text"
                                                defaultValue={promptDialog.defaultValue} 
                                                placeholder={promptDialog.placeholder}
                                                required
                                                className="w-full bg-slate-50 px-4 py-3 rounded-xl border border-slate-200 focus:bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none font-bold text-slate-800 text-sm transition-all shadow-inner"
                                            />
                                        </div>
                                        <div className="flex gap-3 pt-2">
                                            <button type="button" onClick={() => setPromptDialog({ ...promptDialog, isOpen: false })} className="flex-1 py-3 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl font-bold text-xs uppercase tracking-wider transition-all">Hủy</button>
                                            <button type="submit" className="flex-1 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold text-xs uppercase tracking-wider transition-all shadow-md shadow-indigo-600/10">Đồng ý</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        )}

                        {editingStudentInfo && (
                            <EditStudentInfoModal
                                student={editingStudentInfo}
                                classes={globalClasses}
                                onClose={() => setEditingStudentInfo(null)}
                                showAlert={showAlert}
                                onSaved={() => loadStudents()}
                            />
                        )}
                    </div>
                </div>
            );
        };

        const cleanCompareName = (name) => {
            if (!name) return '';
            return String(name).trim().normalize("NFD")
                .replace(/[\u0300-\u036f]/g, "")
                .replace(/đ/g, "d")
                .replace(/Đ/g, "d")
                .toUpperCase()
                .replace(/\s+/g, ' ');
        };

        const ExcelScoreImportModal = ({ 
            onClose, 
            classStudents = [], 
            selectedClass = '', 
            scoresGrid = {}, 
            onApplyScores, 
            showAlert 
        }) => {
            const [targetColumn, setTargetColumn] = useState('tx1');
            const [fileData, setFileData] = useState(null);
            const [fileName, setFileName] = useState('');
            const [columnMapping, setColumnMapping] = useState({ sbdCol: 0, nameCol: 1, scoreCol: 2, startRow: 1 });
            const [headers, setHeaders] = useState([]);
            const [parsedResults, setParsedResults] = useState(null);
            const [overwriteExisting, setOverwriteExisting] = useState(true);
            const [fillMissingWithZero, setFillMissingWithZero] = useState(false);
            const [showColumnConfig, setShowColumnConfig] = useState(false);
            const fileInputRef = useRef(null);

            const columnOptions = [
                { id: 'tx1', label: 'TX 1' },
                { id: 'tx2', label: 'TX 2' },
                { id: 'tx3', label: 'TX 3' },
                { id: 'tx4', label: 'TX 4' },
                { id: 'tx5', label: 'TX 5' },
                { id: 'gk', label: 'Giữa kỳ' },
                { id: 'ck', label: 'Cuối kỳ' },
            ];

            const downloadSampleTemplate = () => {
                const sampleData = [
                    ["SBD", "Họ và tên", "Điểm"]
                ];
                classStudents.forEach(s => {
                    sampleData.push([s.id, s.fullName, ""]);
                });
                const ws = window.XLSX.utils.aoa_to_sheet(sampleData);
                const wb = window.XLSX.utils.book_new();
                window.XLSX.utils.book_append_sheet(wb, ws, "Diem_" + selectedClass);
                window.XLSX.writeFile(wb, `mau_nhap_diem_lop_${selectedClass}.xlsx`);
            };

            const findStudentMatch = (cleanSbd, cleanName, students) => {
                // 1. Exact ID / SBD match
                if (cleanSbd) {
                    const exact = students.find(s => String(s.id).trim().toLowerCase() === cleanSbd.toLowerCase());
                    if (exact) return exact;
                }

                // 2. Numeric SBD match
                if (cleanSbd && !isNaN(cleanSbd)) {
                    const numSbd = parseInt(cleanSbd, 10);
                    const numMatch = students.find(s => !isNaN(s.id) && parseInt(s.id, 10) === numSbd);
                    if (numMatch) return numMatch;
                }

                // 3. Suffix / SBD match (e.g. SBD is 121201 or 01 matching ID 25121201)
                if (cleanSbd && cleanSbd.length >= 2) {
                    const suffix = students.find(s => {
                        const sId = String(s.id).trim();
                        return sId.endsWith(cleanSbd) || cleanSbd.endsWith(sId);
                    });
                    if (suffix) return suffix;
                }

                // 4. If SBD is numeric 1-2 digits (like STT in class), check student at that index
                if (cleanSbd && !isNaN(cleanSbd)) {
                    const idx = parseInt(cleanSbd, 10) - 1;
                    if (idx >= 0 && idx < students.length) {
                        const cand = students[idx];
                        if (cand) {
                            if (!cleanName || cleanCompareName(cand.fullName) === cleanCompareName(cleanName) || String(cand.id).endsWith(String(idx + 1).padStart(2, '0'))) {
                                return cand;
                            }
                        }
                    }
                }

                // 5. Fallback to Họ và tên match
                if (cleanName) {
                    const nameMatch = students.find(s => cleanCompareName(s.fullName) === cleanCompareName(cleanName));
                    if (nameMatch) return nameMatch;
                }

                return null;
            };

            const analyzeData = (rows, mapping) => {
                if (!rows || rows.length <= mapping.startRow) {
                    setParsedResults(null);
                    return;
                }

                const matched = [];
                const unmatchedInFile = [];
                const matchedStudentIds = new Set();

                for (let r = mapping.startRow; r < rows.length; r++) {
                    const row = rows[r];
                    if (!row || row.length === 0) continue;

                    const rawSbd = row[mapping.sbdCol];
                    const rawName = row[mapping.nameCol];
                    const rawScore = row[mapping.scoreCol];

                    if (rawSbd === undefined && rawName === undefined && rawScore === undefined) continue;

                    const cleanSbd = String(rawSbd !== undefined ? rawSbd : '').trim();
                    const cleanName = String(rawName !== undefined ? rawName : '').trim();
                    
                    let parsedScore = '';
                    if (rawScore !== undefined && rawScore !== null && String(rawScore).trim() !== '') {
                        const sStr = String(rawScore).trim().replace(',', '.');
                        const num = parseFloat(sStr);
                        if (!isNaN(num)) {
                            parsedScore = String(Math.round(num * 100) / 100);
                        } else {
                            parsedScore = sStr;
                        }
                    }

                    if (!cleanSbd && !cleanName) continue;

                    const stu = findStudentMatch(cleanSbd, cleanName, classStudents);
                    if (stu) {
                        matchedStudentIds.add(stu.id);
                        matched.push({
                            fileSbd: cleanSbd,
                            fileName: cleanName,
                            score: parsedScore,
                            student: stu,
                            matchType: (cleanSbd && (String(stu.id) === cleanSbd || String(stu.id).endsWith(cleanSbd))) ? 'SBD' : 'Họ tên'
                        });
                    } else {
                        unmatchedInFile.push({
                            fileSbd: cleanSbd,
                            fileName: cleanName,
                            score: parsedScore
                        });
                    }
                }

                const missingStudents = classStudents.filter(s => !matchedStudentIds.has(s.id));

                setParsedResults({
                    matched,
                    unmatchedInFile,
                    missingStudents,
                    totalRowsInFile: matched.length + unmatchedInFile.length
                });
            };

            const handleFileSelect = (e) => {
                const file = e.target.files[0];
                if (!file) return;
                setFileName(file.name);

                const reader = new FileReader();
                reader.onload = (evt) => {
                    try {
                        const bstr = evt.target.result;
                        const wb = window.XLSX.read(bstr, { type: 'binary' });
                        const wsname = wb.SheetNames[0];
                        const ws = wb.Sheets[wsname];
                        const rawRows = window.XLSX.utils.sheet_to_json(ws, { header: 1 });
                        if (!rawRows || rawRows.length === 0) {
                            showAlert("File Excel không có dữ liệu!");
                            return;
                        }

                        let sbdCol = -1;
                        let nameCol = -1;
                        let scoreCol = -1;
                        let headerRowIndex = -1;

                        for (let r = 0; r < Math.min(rawRows.length, 6); r++) {
                            const row = rawRows[r] || [];
                            for (let c = 0; c < row.length; c++) {
                                const cellVal = String(row[c] || '').toLowerCase().trim();
                                const norm = cellVal.normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/đ/g, "d");
                                
                                if (sbdCol === -1 && (
                                    norm === 'sbd' || norm.includes('so bao danh') || norm.includes('sobaodanh') || 
                                    norm.includes('ma hs') || norm.includes('mahocsinh') || norm.includes('ma so') || 
                                    norm === 'id' || norm === 'mssv'
                                )) {
                                    sbdCol = c;
                                    headerRowIndex = r;
                                }
                                if (nameCol === -1 && (
                                    norm.includes('ho va ten') || norm.includes('ho ten') || norm.includes('hoten') || 
                                    norm === 'ten' || norm === 'name' || norm === 'fullname' || norm.includes('hoc sinh')
                                )) {
                                    nameCol = c;
                                    headerRowIndex = r;
                                }
                                if (scoreCol === -1 && (
                                    norm.includes('diem') || norm === 'score' || norm.includes('tong diem') || 
                                    norm.includes('ket qua') || norm === 'kq' || norm.includes('tx')
                                )) {
                                    scoreCol = c;
                                    headerRowIndex = r;
                                }
                            }
                            if (sbdCol !== -1 && scoreCol !== -1) {
                                break;
                            }
                        }

                        const firstDataRow = headerRowIndex !== -1 ? headerRowIndex + 1 : 1;
                        const sampleRow = rawRows[firstDataRow] || rawRows[0] || [];
                        const detectedHeaders = (headerRowIndex !== -1 ? rawRows[headerRowIndex] : []) || [];

                        if (sbdCol === -1 || scoreCol === -1) {
                            if (sampleRow.length <= 3) {
                                sbdCol = 0;
                                nameCol = 1;
                                scoreCol = 2;
                            } else {
                                sbdCol = 1;
                                nameCol = 2;
                                scoreCol = 3;
                            }
                        }
                        if (nameCol === -1) {
                            nameCol = sbdCol === 0 ? 1 : (sbdCol === 1 ? 2 : 1);
                        }

                        setFileData(rawRows);
                        setHeaders(detectedHeaders);
                        const mapping = { sbdCol, nameCol, scoreCol, startRow: firstDataRow };
                        setColumnMapping(mapping);
                        analyzeData(rawRows, mapping);
                    } catch (err) {
                        showAlert("Lỗi đọc file Excel: " + (err.message || "Định dạng không hợp lệ"));
                    }
                };
                reader.readAsBinaryString(file);
                e.target.value = null;
            };

            const handleColumnChange = (field, val) => {
                const newMapping = { ...columnMapping, [field]: parseInt(val, 10) };
                setColumnMapping(newMapping);
                if (fileData) {
                    analyzeData(fileData, newMapping);
                }
            };

            const handleConfirmImport = () => {
                if (!parsedResults || parsedResults.matched.length === 0) {
                    showAlert("Không có điểm học sinh nào khớp để nhập!");
                    return;
                }

                const newGrid = { ...scoresGrid };
                let appliedCount = 0;

                parsedResults.matched.forEach(item => {
                    const sId = item.student.id;
                    const currentScore = newGrid[sId]?.[targetColumn] || '';
                    if (overwriteExisting || currentScore === '') {
                        newGrid[sId] = {
                            ...(newGrid[sId] || {}),
                            [targetColumn]: item.score
                        };
                        appliedCount++;
                    }
                });

                if (fillMissingWithZero && parsedResults.missingStudents) {
                    parsedResults.missingStudents.forEach(stu => {
                        newGrid[stu.id] = {
                            ...(newGrid[stu.id] || {}),
                            [targetColumn]: "0"
                        };
                    });
                }

                onApplyScores(newGrid, targetColumn, appliedCount);
                onClose();
            };

            const maxColCount = fileData && fileData.length > 0 ? Math.max(...fileData.slice(0, 5).map(r => r ? r.length : 0)) : 4;
            const colOptions = Array.from({ length: maxColCount }, (_, i) => {
                const colLetter = String.fromCharCode(65 + i);
                const colHeader = headers[i] ? ` (${headers[i]})` : '';
                return { index: i, label: `Cột ${colLetter}${colHeader}` };
            });

            return (
                <div className="fixed inset-0 z-[160] bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="bg-white w-full max-w-3xl rounded-[2rem] shadow-2xl flex flex-col max-h-[92vh] overflow-hidden border border-slate-100 animate-in zoom-in-95 duration-200">
                        {/* Header */}
                        <div className="bg-gradient-to-r from-emerald-600 via-teal-600 to-cyan-700 text-white p-5 flex justify-between items-center shrink-0">
                            <div className="flex items-center gap-3">
                                <div className="p-2.5 bg-white/15 rounded-2xl">
                                    <Icon name="file-spreadsheet" size={22} />
                                </div>
                                <div>
                                    <h3 className="font-black text-sm uppercase tracking-wide">Nhập điểm từ File Excel (SBD)</h3>
                                    <p className="text-[11px] text-teal-100/90 font-medium">Nhận diện Số báo danh & đổ vào cột điểm lớp <b>{selectedClass}</b></p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={onClose}
                                className="w-8 h-8 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-all"
                            >
                                <Icon name="x" size={16} />
                            </button>
                        </div>

                        {/* Content Body */}
                        <div className="flex-1 overflow-y-auto p-6 space-y-5 custom-scrollbar bg-slate-50/40">
                            {/* Target Column Selector */}
                            <div className="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-sm space-y-2.5">
                                <div className="flex items-center justify-between">
                                    <label className="text-[11px] font-black uppercase tracking-wider text-slate-700 flex items-center gap-1.5">
                                        <Icon name="award" size={14} className="text-emerald-600" />
                                        1. Chọn Cột Điểm Đích Muốn Nhập Vào:
                                    </label>
                                    <button 
                                        type="button" 
                                        onClick={downloadSampleTemplate}
                                        className="text-[10px] font-black text-indigo-600 hover:text-indigo-800 flex items-center gap-1 transition-colors"
                                        title="Tải file mẫu Excel có sẵn danh sách SBD lớp này"
                                    >
                                        <Icon name="download" size={12} /> Tải file mẫu lớp {selectedClass}
                                    </button>
                                </div>
                                <div className="grid grid-cols-4 sm:grid-cols-7 gap-2">
                                    {columnOptions.map(col => {
                                        const isSelected = targetColumn === col.id;
                                        return (
                                            <button
                                                key={col.id}
                                                type="button"
                                                onClick={() => setTargetColumn(col.id)}
                                                className={`py-2 px-1 rounded-xl font-black text-xs transition-all border text-center ${
                                                    isSelected 
                                                        ? 'bg-emerald-600 text-white border-emerald-600 shadow-md shadow-emerald-600/25' 
                                                        : 'bg-slate-50 hover:bg-slate-100 text-slate-700 border-slate-200'
                                                }`}
                                            >
                                                {col.label}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* File Upload Box */}
                            <div className="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-sm space-y-3">
                                <label className="text-[11px] font-black uppercase tracking-wider text-slate-700 flex items-center gap-1.5">
                                    <Icon name="upload" size={14} className="text-teal-600" />
                                    2. Chọn File Excel (.xlsx, .xls, .csv):
                                </label>

                                <input
                                    type="file"
                                    ref={fileInputRef}
                                    accept=".xlsx, .xls, .csv"
                                    className="hidden"
                                    onChange={handleFileSelect}
                                />

                                {!fileData ? (
                                    <div 
                                        onClick={() => fileInputRef.current && fileInputRef.current.click()}
                                        className="border-2 border-dashed border-slate-200 hover:border-emerald-500 rounded-2xl p-6 text-center cursor-pointer bg-slate-50/50 hover:bg-emerald-50/30 transition-all group"
                                    >
                                        <div className="w-12 h-12 bg-emerald-100/80 text-emerald-600 group-hover:bg-emerald-600 group-hover:text-white rounded-2xl flex items-center justify-center mx-auto mb-2 transition-all">
                                            <Icon name="file-spreadsheet" size={24} />
                                        </div>
                                        <p className="text-xs font-bold text-slate-700 group-hover:text-emerald-700">Bấm vào đây để tải lên file Excel điểm</p>
                                        <p className="text-[10px] text-slate-400 mt-1">File chỉ cần 3 cột: <b>SBD</b>, <b>Họ và tên</b>, <b>Điểm</b> (hoặc tương đương)</p>
                                    </div>
                                ) : (
                                    <div className="space-y-3">
                                        <div className="flex items-center justify-between bg-emerald-50/80 border border-emerald-200 p-3 rounded-xl">
                                            <div className="flex items-center gap-2.5">
                                                <Icon name="file-spreadsheet" size={18} className="text-emerald-700" />
                                                <div>
                                                    <p className="text-xs font-black text-emerald-900">{fileName}</p>
                                                    <p className="text-[10px] text-emerald-700">Đã đọc {fileData.length - columnMapping.startRow} dòng dữ liệu</p>
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => setShowColumnConfig(!showColumnConfig)}
                                                    className="px-2.5 py-1.5 bg-white border border-emerald-300 text-emerald-800 text-[10px] font-bold rounded-lg hover:bg-emerald-100 transition-all"
                                                >
                                                    {showColumnConfig ? "Ẩn chỉnh cột" : "Chỉnh cột dữ liệu"}
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => fileInputRef.current && fileInputRef.current.click()}
                                                    className="px-2.5 py-1.5 bg-emerald-600 text-white text-[10px] font-bold rounded-lg hover:bg-emerald-700 transition-all"
                                                >
                                                    Đổi file khác
                                                </button>
                                            </div>
                                        </div>

                                        {/* Column config if user wants to override */}
                                        {showColumnConfig && (
                                            <div className="p-3 bg-slate-50 rounded-xl border border-slate-200 text-xs space-y-2 animate-in slide-in-from-top-2">
                                                <p className="text-[10px] font-bold text-slate-500 uppercase">Tùy chỉnh cột trong File Excel:</p>
                                                <div className="grid grid-cols-3 gap-3">
                                                    <div>
                                                        <label className="block text-[10px] font-bold text-slate-600 mb-1">Cột SBD:</label>
                                                        <select
                                                            value={columnMapping.sbdCol}
                                                            onChange={e => handleColumnChange('sbdCol', e.target.value)}
                                                            className="w-full bg-white border border-slate-200 rounded-lg p-1.5 text-xs font-bold text-slate-700"
                                                        >
                                                            {colOptions.map(opt => (
                                                                <option key={opt.index} value={opt.index}>{opt.label}</option>
                                                            ))}
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label className="block text-[10px] font-bold text-slate-600 mb-1">Cột Họ tên:</label>
                                                        <select
                                                            value={columnMapping.nameCol}
                                                            onChange={e => handleColumnChange('nameCol', e.target.value)}
                                                            className="w-full bg-white border border-slate-200 rounded-lg p-1.5 text-xs font-bold text-slate-700"
                                                        >
                                                            {colOptions.map(opt => (
                                                                <option key={opt.index} value={opt.index}>{opt.label}</option>
                                                            ))}
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label className="block text-[10px] font-bold text-slate-600 mb-1">Cột Điểm:</label>
                                                        <select
                                                            value={columnMapping.scoreCol}
                                                            onChange={e => handleColumnChange('scoreCol', e.target.value)}
                                                            className="w-full bg-white border border-slate-200 rounded-lg p-1.5 text-xs font-bold text-slate-700"
                                                        >
                                                            {colOptions.map(opt => (
                                                                <option key={opt.index} value={opt.index}>{opt.label}</option>
                                                            ))}
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>

                            {/* Analysis Summary & Options */}
                            {parsedResults && (
                                <div className="space-y-4">
                                    {/* Stats grid */}
                                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                        <div className="bg-white p-3 rounded-2xl border border-slate-200 shadow-sm text-center">
                                            <p className="text-[10px] font-black uppercase text-slate-400">Dòng trong file</p>
                                            <p className="text-lg font-black text-slate-800 mt-0.5">{parsedResults.totalRowsInFile}</p>
                                        </div>
                                        <div className="bg-emerald-50/80 p-3 rounded-2xl border border-emerald-200 shadow-sm text-center">
                                            <p className="text-[10px] font-black uppercase text-emerald-600">Khớp với lớp</p>
                                            <p className="text-lg font-black text-emerald-700 mt-0.5">{parsedResults.matched.length} / {classStudents.length}</p>
                                        </div>
                                        <div className="bg-amber-50/80 p-3 rounded-2xl border border-amber-200 shadow-sm text-center">
                                            <p className="text-[10px] font-black uppercase text-amber-600">Không tìm thấy</p>
                                            <p className="text-lg font-black text-amber-700 mt-0.5">{parsedResults.unmatchedInFile.length}</p>
                                        </div>
                                        <div className="bg-slate-100 p-3 rounded-2xl border border-slate-200 shadow-sm text-center">
                                            <p className="text-[10px] font-black uppercase text-slate-500">Chưa có điểm</p>
                                            <p className="text-lg font-black text-slate-700 mt-0.5">{parsedResults.missingStudents.length}</p>
                                        </div>
                                    </div>

                                    {/* Options */}
                                    <div className="bg-white p-3.5 rounded-2xl border border-slate-200/80 shadow-sm flex flex-wrap gap-4 text-xs">
                                        <label className="flex items-center gap-2 cursor-pointer font-bold text-slate-700 select-none">
                                            <input
                                                type="checkbox"
                                                checked={overwriteExisting}
                                                onChange={e => setOverwriteExisting(e.target.checked)}
                                                className="w-4 h-4 rounded text-emerald-600 accent-emerald-600"
                                            />
                                            Ghi đè nếu học sinh đã có điểm ở cột này
                                        </label>
                                        <label className="flex items-center gap-2 cursor-pointer font-bold text-slate-700 select-none">
                                            <input
                                                type="checkbox"
                                                checked={fillMissingWithZero}
                                                onChange={e => setFillMissingWithZero(e.target.checked)}
                                                className="w-4 h-4 rounded text-emerald-600 accent-emerald-600"
                                            />
                                            Gán 0 điểm nếu học sinh trong lớp không có trong file
                                        </label>
                                    </div>

                                    {/* Preview Table */}
                                    <div className="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
                                        <div className="p-3 bg-slate-50 border-b border-slate-100 flex justify-between items-center">
                                            <h4 className="text-[11px] font-black uppercase tracking-wider text-slate-700">
                                                Xem trước kết quả nhận diện ({parsedResults.matched.length} HS được khớp)
                                            </h4>
                                            <span className="text-[10px] font-bold text-emerald-700 bg-emerald-100 px-2 py-0.5 rounded-full">
                                                Đích: {targetColumn.toUpperCase()}
                                            </span>
                                        </div>
                                        <div className="max-h-56 overflow-y-auto custom-scrollbar">
                                            <table className="w-full text-left text-xs border-collapse">
                                                <thead className="sticky top-0 bg-slate-100/90 backdrop-blur-sm text-[9px] font-black uppercase text-slate-500 border-b border-slate-200">
                                                    <tr>
                                                        <th className="py-2 pl-3">STT</th>
                                                        <th className="py-2">SBD File</th>
                                                        <th className="py-2">Họ tên File</th>
                                                        <th className="py-2 text-center">Điểm</th>
                                                        <th className="py-2">Khớp học sinh trong lớp</th>
                                                        <th className="py-2 pr-3 text-right">Nhận diện qua</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-slate-100 font-medium">
                                                    {parsedResults.matched.map((item, idx) => (
                                                        <tr key={idx} className="hover:bg-emerald-50/30 transition-colors">
                                                            <td className="py-2 pl-3 text-slate-400 font-bold text-[10px]">{idx + 1}</td>
                                                            <td className="py-2 font-mono font-bold text-slate-700">{item.fileSbd || '—'}</td>
                                                            <td className="py-2 font-bold text-slate-800">{item.fileName || '—'}</td>
                                                            <td className="py-2 text-center">
                                                                <span className="px-2 py-0.5 bg-emerald-100 text-emerald-800 font-black rounded-md text-xs">
                                                                    {item.score !== '' ? item.score : '—'}
                                                                </span>
                                                            </td>
                                                            <td className="py-2 font-bold text-indigo-700">
                                                                {item.student.fullName} <span className="font-mono text-[10px] text-slate-400">({item.student.id})</span>
                                                            </td>
                                                            <td className="py-2 pr-3 text-right">
                                                                <span className="px-1.5 py-0.5 bg-sky-50 text-sky-700 border border-sky-200 rounded text-[9px] font-bold">
                                                                    {item.matchType}
                                                                </span>
                                                            </td>
                                                        </tr>
                                                    ))}
                                                    {parsedResults.unmatchedInFile.map((item, idx) => (
                                                        <tr key={'unmatched-' + idx} className="bg-amber-50/40 hover:bg-amber-50 text-amber-900">
                                                            <td className="py-2 pl-3 text-amber-400 font-bold text-[10px]">!</td>
                                                            <td className="py-2 font-mono font-bold text-amber-800">{item.fileSbd || '—'}</td>
                                                            <td className="py-2 font-bold text-amber-900">{item.fileName || '—'}</td>
                                                            <td className="py-2 text-center font-bold">{item.score || '—'}</td>
                                                            <td className="py-2 text-[11px] text-amber-600 italic">Không tìm thấy trong lớp {selectedClass}</td>
                                                            <td className="py-2 pr-3 text-right">
                                                                <span className="px-1.5 py-0.5 bg-amber-100 text-amber-700 rounded text-[9px] font-bold">Bỏ qua</span>
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>

                        {/* Footer */}
                        <div className="p-4 bg-white border-t border-slate-100 flex justify-between items-center shrink-0">
                            <button
                                type="button"
                                onClick={onClose}
                                className="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold rounded-xl transition-all"
                            >
                                Hủy bỏ
                            </button>
                            <button
                                type="button"
                                onClick={handleConfirmImport}
                                disabled={!parsedResults || parsedResults.matched.length === 0}
                                className={`px-6 py-2.5 rounded-xl font-black text-xs uppercase tracking-wider flex items-center gap-2 transition-all shadow-md ${
                                    parsedResults && parsedResults.matched.length > 0
                                        ? 'bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white shadow-emerald-600/20'
                                        : 'bg-slate-200 text-slate-400 cursor-not-allowed shadow-none'
                                }`}
                            >
                                <Icon name="check" size={14} />
                                {parsedResults && parsedResults.matched.length > 0
                                    ? `Đổ điểm vào cột ${targetColumn.toUpperCase()} (${parsedResults.matched.length} HS)`
                                    : 'Xác nhận nhập điểm'}
                            </button>
                        </div>
                    </div>
                </div>
            );
        };

        const GradebookModal = ({ onClose, showAlert, showConfirm, showDangerConfirm, globalClasses = [], initialClass = null }) => {
            const [students, setStudents] = useState([]);
            const [editingBonusPoints, setEditingBonusPoints] = useState(null);
            const [editingStudentInfo, setEditingStudentInfo] = useState(null);
            const [showExcelScoreModal, setShowExcelScoreModal] = useState(false);

            const handleSaveBonusPoints = (studentId, semester, totalPoints, historyList, callback) => {
                const student = students.find(s => s.id === studentId);
                if (!student) return;

                const currentBonusPoints = typeof student.bonusPoints === 'object' ? { ...student.bonusPoints } : { hk1: student.bonusPoints || 0, hk2: 0 };
                const currentHistory = (student.bonusPointsHistory && typeof student.bonusPointsHistory === 'object' && !Array.isArray(student.bonusPointsHistory))
                    ? { ...student.bonusPointsHistory }
                    : { hk1: student.bonusPointsHistory || [], hk2: [] };

                currentBonusPoints[semester] = totalPoints;
                currentHistory[semester] = historyList;

                fetch('?action=update_student_bonus_points', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: studentId, bonusPoints: currentBonusPoints, bonusPointsHistory: currentHistory })
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        showAlert("Cập nhật điểm cộng thành công!");
                        loadGradebookData();
                        if (callback) callback();
                    } else {
                        showAlert(res.message || "Lỗi khi cập nhật điểm cộng!");
                    }
                });
            };

            const [publishStatus, setPublishStatus] = useState({});
            const [exams, setExams] = useState([]);
            const [selectedClass, setSelectedClass] = useState(initialClass);
            const [selectedSemester, setSelectedSemester] = useState('hk1'); // 'hk1' or 'hk2'
            const [scoresGrid, setScoresGrid] = useState({});
            const [isLoading, setIsLoading] = useState(true);
            const [isSaving, setIsSaving] = useState(false);
            const [showSyncDialog, setShowSyncDialog] = useState(false);
            const [syncParams, setSyncParams] = useState({ examId: '', column: 'tx1', mode: 'highest' });
            const [showUploadGuide, setShowUploadGuide] = useState(false);
            const [isScoresSaved, setIsScoresSaved] = useState(true);
            const [promptDialog, setPromptDialog] = useState({ isOpen: false, title: '', defaultValue: '', placeholder: '', onConfirm: null });

            const loadGradebookData = () => {
                setIsLoading(true);
                Promise.all([
                    fetch('?action=list_students').then(r => r.json()),
                    fetch('?action=get_gradebook_publish_status').then(r => r.json()),
                    fetch('?action=list').then(r => r.json())
                ])
                .then(([stus, pub, exms]) => {
                    setStudents(stus || []);
                    setPublishStatus(pub || {});
                    setExams(exms || []); // allow syncing from any exam mode (test, normal, practice) linked to the class
                    
                    // Initialize scores grid based on selected semester
                    const grid = {};
                    (stus || []).forEach(s => {
                        const sem = s.studyScores && s.studyScores[selectedSemester] ? s.studyScores[selectedSemester] : { tx1: '', tx2: '', tx3: '', tx4: '', tx5: '', gk: '', ck: '' };
                        grid[s.id] = sem;
                    });
                    setScoresGrid(grid);
                    setIsLoading(false);
                })
                .catch(() => {
                    showAlert("Lỗi tải dữ liệu sổ điểm!");
                    setIsLoading(false);
                });
            };

            useEffect(() => {
                loadGradebookData();
            }, []);

            // Re-map scores when semester changes
            useEffect(() => {
                if (students.length > 0) {
                    const grid = {};
                    students.forEach(s => {
                        const sem = s.studyScores && s.studyScores[selectedSemester] ? s.studyScores[selectedSemester] : { tx1: '', tx2: '', tx3: '', tx4: '', tx5: '', gk: '', ck: '' };
                        grid[s.id] = sem;
                    });
                    setScoresGrid(grid);
                }
            }, [selectedSemester, students]);

            const uniqueClasses = (globalClasses || []).map(c => c.className).filter(Boolean).sort();

            useEffect(() => {
                if (initialClass) {
                    setSelectedClass(initialClass);
                } else if (uniqueClasses.length > 0 && !selectedClass) {
                    setSelectedClass(uniqueClasses[0]);
                }
            }, [students, uniqueClasses, initialClass]);

            useEffect(() => {
                setIsScoresSaved(true);
            }, [selectedClass]);

            const classStudents = students.filter(s => s.class === selectedClass);

            const handleCellChange = (studentId, field, val) => {
                setIsScoresSaved(false);
                setScoresGrid(prev => ({
                    ...prev,
                    [studentId]: {
                        ...prev[studentId],
                        [field]: val
                    }
                }));
            };

            const handleSyncClassAccounts = () => {
                const classObj = globalClasses.find(c => c.className === selectedClass);
                if (!classObj) return;
                setPromptDialog({
                    isOpen: true,
                    title: `Đồng bộ tài khoản lớp ${selectedClass} - Nhập 2 số đầu ID`,
                    defaultValue: "25",
                    placeholder: "Ví dụ: 25 đại diện năm 2025",
                    onConfirm: (prefix) => {
                        if (!prefix || prefix.trim().length !== 2 || isNaN(prefix)) {
                            return showAlert("Tiền tố ID bắt buộc phải có đúng 2 số!");
                        }
                        
                        const accounts = classObj.students.map((item, idx) => {
                            const classMatches = selectedClass.normalize("NFD").replace(/[\u0300-\u036f]/g, "").match(/\d+/g) || [];
                            const grade = classMatches[0] || "12";
                            let room = classMatches[1] || "00";
                            if (room.length === 1) room = "0" + room;
                            const classCode = grade + room;
                            
                            const sttVal = idx + 1;
                            const sttCode = sttVal.toString().padStart(2, '0');
                            const studentId = prefix.trim() + classCode + sttCode;
                            
                            const fullName = `${item.lastName || ''} ${item.firstName || ''}`.trim();
                            const cleanName = fullName.normalize("NFD")
                                .replace(/[\u0300-\u036f]/g, "")
                                .replace(/đ/g, "d")
                                .replace(/Đ/g, "d")
                                .toLowerCase();
                            const nameParts = cleanName.split(/\s+/).filter(Boolean);
                            const initials = nameParts.map(p => p.charAt(0)).join("");
                            const password = initials + "123@";
                            
                            return {
                                id: studentId,
                                fullName: fullName,
                                class: selectedClass,
                                password: password
                            };
                        });
                        
                        fetch('?action=create_student_accounts', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ accounts })
                        })
                        .then(r => r.json())
                        .then(res => {
                            if (res.success) {
                                showAlert(`Đồng bộ thành công! Đã tạo ${res.added} tài khoản học sinh cho lớp ${selectedClass}.`);
                                loadGradebookData();
                            } else {
                                showAlert(res.message || "Lỗi khi đồng bộ!");
                            }
                        });
                    }
                });
            };

            const handleSaveGradebook = () => {
                setIsSaving(true);
                fetch('?action=save_class_gradebook', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ scoresMap: scoresGrid, semester: selectedSemester })
                })
                .then(r => r.json())
                .then(res => {
                    setIsSaving(false);
                    if (res.success) {
                        showAlert("Lưu sổ điểm thành công!");
                        setIsScoresSaved(true);
                        loadGradebookData();
                    } else {
                        showAlert(res.message || "Lỗi khi lưu điểm!");
                    }
                });
            };

            const isColumnPublished = (col) => {
                const cPub = publishStatus[selectedClass];
                if (cPub === true) return true;
                if (!cPub || typeof cPub !== 'object') return false;
                if (cPub[selectedSemester] && typeof cPub[selectedSemester] === 'object') {
                    return !!cPub[selectedSemester][col];
                }
                return !!cPub[col];
            };

            const handleToggleColumnPublish = (col) => {
                if (!selectedClass) return;
                const nextState = !isColumnPublished(col);
                fetch('?action=save_gradebook_publish_status', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ class: selectedClass, column: col, published: nextState, semester: selectedSemester })
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        setPublishStatus(prev => {
                            const current = prev[selectedClass] || {};
                            let newClassVal = {};
                            if (current.hk1 || current.hk2) {
                                newClassVal = {
                                    hk1: current.hk1 ? { ...current.hk1 } : {},
                                    hk2: current.hk2 ? { ...current.hk2 } : {}
                                };
                            } else {
                                const flatDefault = current === true 
                                    ? { tx1: true, tx2: true, tx3: true, tx4: true, tx5: true, gk: true, ck: true }
                                    : (typeof current === 'object' ? { ...current } : {});
                                newClassVal = {
                                    hk1: flatDefault,
                                    hk2: { tx1: false, tx2: false, tx3: false, tx4: false, tx5: false, gk: false, ck: false }
                                };
                            }
                            if (!newClassVal[selectedSemester]) newClassVal[selectedSemester] = {};
                            newClassVal[selectedSemester][col] = nextState;
                            return {
                                ...prev,
                                [selectedClass]: newClassVal
                            };
                        });
                        showAlert(`Đã ${nextState ? 'CÔNG BỐ' : 'HỦY CÔNG BỐ'} điểm cột ${col.toUpperCase()} (Học kỳ ${selectedSemester === 'hk1' ? 'I' : 'II'}) lớp ${selectedClass}!`);
                    } else {
                        showAlert("Lỗi khi cập nhật trạng thái công bố!");
                    }
                });
            };



            const cleanCompareName = (name) => {
                if (!name) return '';
                return name.trim().normalize("NFD")
                    .replace(/[\u0300-\u036f]/g, "")
                    .replace(/đ/g, "d")
                    .replace(/Đ/g, "d")
                    .toUpperCase()
                    .replace(/\s+/, ' ');
            };

            const handleSyncFromWeb = () => {
                if (!syncParams.examId) {
                    return showAlert("Vui lòng chọn bài kiểm tra để đồng bộ!");
                }
                
                setIsLoading(true);
                fetch('?action=get_history&examId=' + syncParams.examId)
                .then(r => r.json())
                .then(attempts => {
                    setIsLoading(false);
                    if (!attempts || attempts.length === 0) {
                        return showAlert("Không tìm thấy kết quả làm bài nào cho đề này!");
                    }

                    const newGrid = { ...scoresGrid };
                    let syncCount = 0;

                    classStudents.forEach(stu => {
                        const stuAttempts = attempts.filter(att => 
                            cleanCompareName(att.name) === cleanCompareName(stu.fullName) &&
                            cleanCompareName(att.class) === cleanCompareName(stu.class)
                        );
                        
                        if (stuAttempts.length > 0) {
                            let selectedAttempt = stuAttempts[0];
                            if (syncParams.mode === 'highest') {
                                stuAttempts.forEach(att => {
                                    if (parseFloat(att.score || 0) > parseFloat(selectedAttempt.score || 0)) {
                                        selectedAttempt = att;
                                    }
                                });
                            } else {
                                // latest
                                stuAttempts.forEach(att => {
                                    if (Number(att.startTime || 0) > Number(selectedAttempt.startTime || 0)) {
                                        selectedAttempt = att;
                                    }
                                });
                            }
                            
                            newGrid[stu.id] = {
                                ...newGrid[stu.id],
                                [syncParams.column]: String(selectedAttempt.score)
                            };
                            syncCount++;
                        } else {
                            newGrid[stu.id] = {
                                ...newGrid[stu.id],
                                [syncParams.column]: "0"
                            };
                        }
                    });

                    setIsScoresSaved(false);
                    setScoresGrid(newGrid);
                    setShowSyncDialog(false);
                    showAlert(`Đồng bộ thành công! Điền ${syncCount} điểm từ web, gán 0 điểm cho ${classStudents.length - syncCount} HS chưa đăng nhập làm bài.`);
                })
                .catch(() => {
                    setIsLoading(false);
                    showAlert("Lỗi tải lịch sử thi!");
                });
            };

            const handleExcelUpload = (e) => {
                const file = e.target.files[0];
                if (!file) return;
                const reader = new FileReader();
                reader.onload = (evt) => {
                    try {
                        const bstr = evt.target.result;
                        const wb = window.XLSX.read(bstr, { type: 'binary' });
                        const wsname = wb.SheetNames[0];
                        const ws = wb.Sheets[wsname];
                        const data = window.XLSX.utils.sheet_to_json(ws, { header: 1 });
                        
                        const newGrid = { ...scoresGrid };
                        let matchedCount = 0;

                        // Columns mapping: A=STT, B=Mã HS, C=Họ và tên, D=TX1, E=TX2, F=TX3, G=TX4, H=GK, I=CK
                        for (let i = 1; i < data.length; i++) {
                            const row = data[i];
                            if (row && row.length >= 3) {
                                const id = String(row[1] || '').trim();
                                const name = String(row[2] || '').trim();
                                
                                // Match by ID first, then by name
                                const matchedStu = classStudents.find(s => 
                                    (id && s.id === id) || 
                                    (!id && cleanCompareName(s.fullName) === cleanCompareName(name))
                                );
                                
                                if (matchedStu) {
                                    newGrid[matchedStu.id] = {
                                        tx1: String(row[3] !== undefined ? row[3] : (newGrid[matchedStu.id]?.tx1 || '')).trim(),
                                        tx2: String(row[4] !== undefined ? row[4] : (newGrid[matchedStu.id]?.tx2 || '')).trim(),
                                        tx3: String(row[5] !== undefined ? row[5] : (newGrid[matchedStu.id]?.tx3 || '')).trim(),
                                        tx4: String(row[6] !== undefined ? row[6] : (newGrid[matchedStu.id]?.tx4 || '')).trim(),
                                        tx5: String(row[7] !== undefined ? row[7] : (newGrid[matchedStu.id]?.tx5 || '')).trim(),
                                        gk: String(row[8] !== undefined ? row[8] : (newGrid[matchedStu.id]?.gk || '')).trim(),
                                        ck: String(row[9] !== undefined ? row[9] : (newGrid[matchedStu.id]?.ck || '')).trim(),
                                    };
                                    matchedCount++;
                                }
                            }
                        }

                        setIsScoresSaved(false);
                        setScoresGrid(newGrid);
                        showAlert(`Đã tải lên điểm từ Excel! Khớp được ${matchedCount}/${classStudents.length} học sinh.`);
                    } catch (err) {
                        showAlert("Lỗi đọc file Excel!");
                    }
                };
                reader.readAsBinaryString(file);
                e.target.value = null;
            };

            const downloadTemplate = () => {
                const sampleData = [
                    ["STT", "Mã HS", "Họ và tên", "TX1", "TX2", "TX3", "TX4", "TX5", "Giữa kỳ", "Cuối kỳ"]
                ];
                classStudents.forEach((s, idx) => {
                    const gridItem = scoresGrid[s.id] || {};
                    sampleData.push([
                        String(idx + 1).padStart(2, '0'),
                        s.id,
                        s.fullName,
                        gridItem.tx1 || '',
                        gridItem.tx2 || '',
                        gridItem.tx3 || '',
                        gridItem.tx4 || '',
                        gridItem.tx5 || '',
                        gridItem.gk || '',
                        gridItem.ck || ''
                    ]);
                });

                const ws = window.XLSX.utils.aoa_to_sheet(sampleData);
                const wb = window.XLSX.utils.book_new();
                window.XLSX.utils.book_append_sheet(wb, ws, "Sổ điểm lớp " + selectedClass);
                window.XLSX.writeFile(wb, `so_diem_lop_${selectedClass}.xlsx`);
            };

            return (
                <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
                    <div className="bg-white w-full max-w-[95vw] h-[92vh] rounded-[2.5rem] shadow-2xl flex flex-col overflow-hidden animate-in zoom-in duration-300 relative">
                        <header className="p-5 border-b flex justify-between items-center bg-slate-50 shrink-0">
                            <div className="flex items-center gap-3">
                                <div className="p-2 bg-sky-100 text-sky-700 rounded-lg"><Icon name="award" size={14}/></div>
                                <div>
                                    <h2 className="text-base font-black uppercase tracking-widest text-slate-800">Sổ Điểm Học Tập</h2>
                                    <p className="text-[10px] font-bold text-slate-400 mt-0.5">Nhập tay, đồng bộ kết quả web, hoặc tải lên Excel lớp học</p>
                                </div>
                            </div>
                            <button type="button" onClick={onClose} className="w-8 h-8 flex items-center justify-center bg-rose-500 text-white hover:bg-rose-600 rounded-full transition-colors shadow-md"><Icon name="x" size={18}/></button>
                        </header>

                        <div className="flex-1 flex overflow-hidden">
                            {/* Class Sidebar */}
                            <aside className="w-56 border-r border-slate-100 flex flex-col shrink-0 bg-slate-50/50">
                                <div className="p-4 border-b border-slate-100">
                                    <h3 className="text-[10px] font-black text-slate-400 uppercase tracking-widest pl-1">Danh sách Lớp học</h3>
                                </div>
                                <div className="flex-1 overflow-y-auto p-2 space-y-1 custom-scrollbar">
                                    {uniqueClasses.map(cName => {
                                        const count = students.filter(s => s.class === cName).length;
                                        const isSelected = selectedClass === cName;
                                        
                                        const cPub = publishStatus[cName];
                                        let isPub = false;
                                        if (cPub === true) {
                                            isPub = true;
                                        } else if (cPub && typeof cPub === 'object') {
                                            const semPub = cPub[selectedSemester];
                                            if (semPub && typeof semPub === 'object') {
                                                isPub = Object.values(semPub).some(Boolean);
                                            } else if (selectedSemester === 'hk1') {
                                                isPub = Object.values(cPub).some(Boolean);
                                            }
                                        }
                                        
                                        return (
                                            <button 
                                                key={cName} 
                                                onClick={() => setSelectedClass(cName)}
                                                className={`w-full p-3 rounded-2xl flex justify-between items-center transition-all ${isSelected ? 'bg-sky-600 text-white shadow-lg shadow-sky-600/15' : 'hover:bg-slate-100/80 text-slate-600'}`}
                                            >
                                                <div className="text-left">
                                                    <span className="font-black text-xs uppercase block">{cName}</span>
                                                    <span className={`text-[8px] font-black uppercase tracking-wider ${isSelected ? 'text-sky-200' : (isPub ? 'text-emerald-500' : 'text-slate-400')}`}>
                                                        {isPub ? 'Đã công bố' : 'Chưa công bố'}
                                                    </span>
                                                </div>
                                                <span className={`px-2 py-0.5 rounded-full text-[9px] font-black ${isSelected ? 'bg-white/20 text-white' : 'bg-slate-200 text-slate-500'}`}>{count}</span>
                                            </button>
                                        );
                                    })}
                                </div>


                            </aside>

                            {/* Main Content Area */}
                            <div className="flex-1 flex flex-col overflow-hidden bg-white">
                                {selectedClass ? (
                                    <>
                                        <div className="p-4 border-b border-slate-100 bg-slate-50/20 flex flex-wrap justify-between items-center gap-4 shrink-0">
                                            <div className="flex items-center gap-4 flex-wrap">
                                                <h3 className="font-black text-slate-800 text-sm">BẢNG ĐIỂM LỚP: <span className="bg-sky-100 text-sky-700 px-2 py-0.5 rounded-md font-black">{selectedClass}</span></h3>
                                                {/* Semester Tabs */}
                                                <div className="flex bg-slate-100 rounded-lg p-0.5 border border-slate-200">
                                                    <button 
                                                        onClick={() => setSelectedSemester('hk1')}
                                                        className={`px-3 py-1 rounded-md text-[10px] font-black uppercase tracking-wider transition-all ${selectedSemester === 'hk1' ? 'bg-white text-indigo-600 shadow-sm' : 'text-slate-500 hover:text-slate-800'}`}
                                                    >
                                                        Học kỳ I
                                                    </button>
                                                    <button 
                                                        onClick={() => setSelectedSemester('hk2')}
                                                        className={`px-3 py-1 rounded-md text-[10px] font-black uppercase tracking-wider transition-all ${selectedSemester === 'hk2' ? 'bg-white text-indigo-600 shadow-sm' : 'text-slate-500 hover:text-slate-800'}`}
                                                    >
                                                        Học kỳ II
                                                    </button>
                                                </div>
                                            </div>
                                            
                                            <div className="flex flex-wrap gap-2 w-full sm:w-auto">
                                                <button onClick={() => setShowExcelScoreModal(true)} className="flex-1 sm:flex-none justify-center px-3.5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-black uppercase tracking-widest text-[9px] rounded-xl transition-all flex items-center gap-1.5 shadow-md shadow-emerald-600/20" title="Nhập điểm từ file Excel theo SBD, Họ tên, Điểm vào cột TX mong muốn">
                                                    <Icon name="file-spreadsheet" size={11}/> Upload điểm Excel (SBD)
                                                </button>
                                                <button onClick={() => setShowUploadGuide(!showUploadGuide)} className="flex-1 sm:flex-none justify-center px-3.5 py-2.5 bg-amber-50 hover:bg-amber-100 text-amber-700 border border-amber-200 font-black uppercase tracking-widest text-[9px] rounded-xl transition-all flex items-center gap-1.5 shadow-sm">
                                                    <Icon name="help-circle" size={11}/> Hướng dẫn Excel
                                                </button>
                                                <button onClick={() => setShowSyncDialog(true)} className="flex-1 sm:flex-none justify-center px-3.5 py-2.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 font-black uppercase tracking-widest text-[9px] rounded-xl transition-all flex items-center gap-1.5 shadow-sm">
                                                    <Icon name="refresh-cw" size={11}/> Đồng bộ từ Web
                                                </button>
                                                <button onClick={downloadTemplate} className="flex-1 sm:flex-none justify-center px-3.5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 border border-slate-200 font-black uppercase tracking-widest text-[9px] rounded-xl transition-all flex items-center gap-1.5 shadow-sm">
                                                    <Icon name="download" size={11}/> Tải File Mẫu
                                                </button>
                                                <label className="flex-1 sm:flex-none justify-center px-3.5 py-2.5 bg-sky-50 hover:bg-sky-600 hover:text-white text-sky-600 border border-sky-200 font-black uppercase tracking-widest text-[9px] rounded-xl transition-all flex items-center gap-1.5 cursor-pointer text-center shadow-sm">
                                                    <Icon name="upload" size={11}/> Nhập từ Excel
                                                    <input type="file" accept=".xlsx, .xls" className="hidden" onChange={handleExcelUpload} />
                                                </label>
                                                <button onClick={handleSaveGradebook} disabled={isSaving} className="flex-1 sm:flex-none justify-center px-5 py-2.5 bg-sky-600 hover:bg-sky-700 text-white font-black uppercase tracking-widest text-[9px] rounded-xl transition-all flex items-center gap-1.5 shadow-md shadow-sky-600/15">
                                                    <Icon name="save" size={11}/> {isSaving ? 'Đang lưu...' : 'Lưu Sổ Điểm'}
                                                </button>

                                            </div>
                                        </div>

                                        <div className="flex-1 p-4 flex flex-col overflow-hidden bg-white">
                                            {showUploadGuide && (
                                                <div className="mb-4 bg-blue-50 border border-blue-200 rounded-2xl p-4 text-xs text-blue-800 space-y-2.5 animate-in slide-in-from-top duration-200">
                                                    <h4 className="font-black uppercase tracking-wider flex items-center gap-1.5 text-blue-900">
                                                        <Icon name="help-circle" size={14}/> Cấu trúc File Excel Nhập Điểm
                                                    </h4>
                                                    <p className="leading-relaxed text-[11px]">Hệ thống sẽ dựa vào <b>Mã HS</b> hoặc <b>Họ và tên</b> để khớp điểm. File Excel cần có cấu trúc các cột như sau:</p>
                                                    <div className="overflow-hidden border border-blue-200 rounded-lg bg-white shadow-sm max-w-2xl">
                                                        <table className="w-full text-center border-collapse">
                                                            <thead>
                                                                <tr className="bg-blue-100/70 text-[9px] font-black uppercase text-blue-900 border-b border-blue-200">
                                                                    <th className="py-1 border-r border-blue-200">Cột A</th>
                                                                    <th className="py-1 border-r border-blue-200">Cột B</th>
                                                                    <th className="py-1 border-r border-blue-200">Cột C</th>
                                                                    <th className="py-1 border-r border-blue-200">Cột D</th>
                                                                    <th className="py-1 border-r border-blue-200">Cột E</th>
                                                                    <th className="py-1 border-r border-blue-200">Cột F</th>
                                                                    <th className="py-1 border-r border-blue-200">Cột G</th>
                                                                    <th className="py-1 border-r border-blue-200">Cột H</th>
                                                                    <th className="py-1 border-r border-blue-200">Cột I</th>
                                                                    <th className="py-1">Cột J</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <tr className="border-b border-blue-100 text-[10px] font-bold">
                                                                    <td className="py-1 border-r border-blue-200 text-slate-500">STT</td>
                                                                    <td className="py-1 border-r border-blue-200 text-slate-500">Mã HS</td>
                                                                    <td className="py-1 border-r border-blue-200 text-slate-500">Họ và tên</td>
                                                                    <td className="py-1 border-r border-blue-200 text-slate-500">TX1</td>
                                                                    <td className="py-1 border-r border-blue-200 text-slate-500">TX2</td>
                                                                    <td className="py-1 border-r border-blue-200 text-slate-500">TX3</td>
                                                                    <td className="py-1 border-r border-blue-200 text-slate-500">TX4</td>
                                                                    <td className="py-1 border-r border-blue-200 text-slate-500">TX5</td>
                                                                    <td className="py-1 border-r border-blue-200 text-slate-500">Giữa kỳ</td>
                                                                    <td className="py-1 text-slate-500">Cuối kỳ</td>
                                                                </tr>
                                                                <tr className="text-[10px] bg-slate-50/50 text-slate-400">
                                                                    <td className="py-1 border-r border-blue-200">01</td>
                                                                    <td className="py-1 border-r border-blue-200 font-mono">HS001</td>
                                                                    <td className="py-1 border-r border-blue-200 text-slate-700">NGUYỄN VĂN AN</td>
                                                                    <td className="py-1 border-r border-blue-200">8.0</td>
                                                                    <td className="py-1 border-r border-blue-200">9.0</td>
                                                                    <td className="py-1 border-r border-blue-200"></td>
                                                                    <td className="py-1 border-r border-blue-200"></td>
                                                                    <td className="py-1 border-r border-blue-200"></td>
                                                                    <td className="py-1 border-r border-blue-200">8.5</td>
                                                                    <td className="py-1">9.0</td>
                                                                </tr>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                    <ul className="list-disc list-inside space-y-1 pl-1 text-[11px] text-blue-700 font-medium">
                                                        <li>Dòng 1 là tiêu đề (STT, Mã HS, Họ tên, ...) và sẽ được bỏ qua.</li>
                                                        <li>Bạn có thể để trống các cột điểm chưa có, điểm của học sinh sẽ không bị thay đổi.</li>
                                                        <li>Khuyên dùng: Bấm nút <b>"Tải File Mẫu"</b> bên trên để tải sẵn Excel có cấu trúc và danh sách học sinh của lớp hiện tại, sau đó chỉ cần nhập điểm và tải lên lại.</li>
                                                    </ul>
                                                </div>
                                            )}
                                            {isLoading ? (
                                                <div className="py-20 text-center text-slate-400 font-bold flex flex-col items-center gap-2">
                                                    <Icon name="loader-2" className="animate-spin" size={24}/>
                                                    Đang tải...
                                                </div>
                                            ) : classStudents.length === 0 ? (
                                                <div className="py-20 text-center flex flex-col items-center justify-center text-slate-400">
                                                    <div className="p-4 bg-sky-50 text-sky-600 rounded-full mb-3">
                                                        <Icon name="users" size={32} />
                                                    </div>
                                                    <h4 className="text-sm font-black uppercase text-slate-800 mb-1 font-sans">Lớp chưa có tài khoản học sinh</h4>
                                                    <p className="text-xs text-slate-500 font-bold max-w-xs leading-relaxed mb-4">
                                                        {globalClasses.find(c => c.className === selectedClass)?.students?.length > 0
                                                            ? `Lớp ${selectedClass} đã có danh sách học sinh trong Quản lý Lớp nhưng chưa được khởi tạo tài khoản đăng nhập.`
                                                            : `Lớp ${selectedClass} chưa có cả danh sách học sinh và tài khoản đăng nhập.`}
                                                    </p>
                                                    {globalClasses.find(c => c.className === selectedClass)?.students?.length > 0 ? (
                                                        <button 
                                                            onClick={handleSyncClassAccounts}
                                                            className="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-black rounded-xl text-[10px] transition-all uppercase tracking-widest shadow-md shadow-indigo-600/10 flex items-center gap-1.5"
                                                        >
                                                            <Icon name="refresh-cw" size={12}/> Sinh tài khoản tự động
                                                        </button>
                                                    ) : (
                                                        <p className="text-[11px] text-amber-600 font-bold bg-amber-50 px-3 py-1.5 rounded-lg border border-amber-200">
                                                            Vui lòng vào Quản lý Lớp -> chọn lớp -> Nhập danh sách học sinh trước.
                                                        </p>
                                                    )}
                                                </div>
                                            ) : (
                                                                                                <div className="flex-1 overflow-auto border border-slate-100 rounded-2xl shadow-sm relative custom-scrollbar">
                                                    <table className="w-full text-center border-collapse">
                                                        <thead className="sticky top-0 z-10 bg-slate-50/95 backdrop-blur-sm shadow-[inset_0_-1px_0_rgba(226,232,240,1)]">
                                                            <tr className="bg-slate-50/80 border-b border-slate-100 text-[10px] font-black text-slate-500 uppercase tracking-widest">
                                                                <th className="py-3 pl-4 text-left w-12">STT</th>
                                                                <th className="py-3 text-left w-20">Mã HS</th>
                                                                <th className="py-3 text-left w-36 pl-2">Họ và tên</th>
                                                                
                                                                {/* TX1 Header */}
                                                                <th className="py-2 w-16 border-r border-slate-100">
                                                                    <div className="flex flex-col items-center gap-1">
                                                                        <span>TX 1</span>
                                                                        <button onClick={() => handleToggleColumnPublish('tx1')} className={`p-1 rounded-md transition-all border ${isColumnPublished('tx1') ? 'bg-emerald-50 text-emerald-600 border-emerald-200' : 'bg-slate-100 text-slate-400 border-slate-200'}`} title="Bật/Tắt công bố TX1">
                                                                            <Icon name={isColumnPublished('tx1') ? "eye" : "eye-off"} size={9}/>
                                                                        </button>
                                                                    </div>
                                                                </th>
                                                                
                                                                {/* TX2 Header */}
                                                                <th className="py-2 w-16 border-r border-slate-100">
                                                                    <div className="flex flex-col items-center gap-1">
                                                                        <span>TX 2</span>
                                                                        <button onClick={() => handleToggleColumnPublish('tx2')} className={`p-1 rounded-md transition-all border ${isColumnPublished('tx2') ? 'bg-emerald-50 text-emerald-600 border-emerald-200' : 'bg-slate-100 text-slate-400 border-slate-200'}`} title="Bật/Tắt công bố TX2">
                                                                            <Icon name={isColumnPublished('tx2') ? "eye" : "eye-off"} size={9}/>
                                                                        </button>
                                                                    </div>
                                                                </th>

                                                                {/* TX3 Header */}
                                                                <th className="py-2 w-16 border-r border-slate-100">
                                                                    <div className="flex flex-col items-center gap-1">
                                                                        <span>TX 3</span>
                                                                        <button onClick={() => handleToggleColumnPublish('tx3')} className={`p-1 rounded-md transition-all border ${isColumnPublished('tx3') ? 'bg-emerald-50 text-emerald-600 border-emerald-200' : 'bg-slate-100 text-slate-400 border-slate-200'}`} title="Bật/Tắt công bố TX3">
                                                                            <Icon name={isColumnPublished('tx3') ? "eye" : "eye-off"} size={9}/>
                                                                        </button>
                                                                    </div>
                                                                </th>

                                                                {/* TX4 Header */}
                                                                <th className="py-2 w-16 border-r border-slate-100">
                                                                    <div className="flex flex-col items-center gap-1">
                                                                        <span>TX 4</span>
                                                                        <button onClick={() => handleToggleColumnPublish('tx4')} className={`p-1 rounded-md transition-all border ${isColumnPublished('tx4') ? 'bg-emerald-50 text-emerald-600 border-emerald-200' : 'bg-slate-100 text-slate-400 border-slate-200'}`} title="Bật/Tắt công bố TX4">
                                                                            <Icon name={isColumnPublished('tx4') ? "eye" : "eye-off"} size={9}/>
                                                                        </button>
                                                                    </div>
                                                                </th>

                                                                {/* TX5 Header */}
                                                                <th className="py-2 w-16 border-r border-slate-100">
                                                                    <div className="flex flex-col items-center gap-1">
                                                                        <span>TX 5</span>
                                                                        <button onClick={() => handleToggleColumnPublish('tx5')} className={`p-1 rounded-md transition-all border ${isColumnPublished('tx5') ? 'bg-emerald-50 text-emerald-600 border-emerald-200' : 'bg-slate-100 text-slate-400 border-slate-200'}`} title="Bật/Tắt công bố TX5">
                                                                            <Icon name={isColumnPublished('tx5') ? "eye" : "eye-off"} size={9}/>
                                                                        </button>
                                                                    </div>
                                                                </th>

                                                                {/* GK Header */}
                                                                <th className="py-2 w-20 text-violet-600 border-r border-slate-100">
                                                                    <div className="flex flex-col items-center gap-1">
                                                                        <span>Giữa kỳ</span>
                                                                        <button onClick={() => handleToggleColumnPublish('gk')} className={`p-1 rounded-md transition-all border ${isColumnPublished('gk') ? 'bg-violet-100 text-violet-700 border-violet-200' : 'bg-slate-100 text-slate-400 border-slate-200'}`} title="Bật/Tắt công bố Giữa kỳ">
                                                                            <Icon name={isColumnPublished('gk') ? "eye" : "eye-off"} size={9}/>
                                                                        </button>
                                                                    </div>
                                                                </th>

                                                                {/* CK Header */}
                                                                <th className="py-2 w-20 text-rose-600">
                                                                    <div className="flex flex-col items-center gap-1">
                                                                        <span>Cuối kỳ</span>
                                                                        <button onClick={() => handleToggleColumnPublish('ck')} className={`p-1 rounded-md transition-all border ${isColumnPublished('ck') ? 'bg-rose-100 text-rose-700 border-rose-200' : 'bg-slate-100 text-slate-400 border-slate-200'}`} title="Bật/Tắt công bố Cuối kỳ">
                                                                            <Icon name={isColumnPublished('ck') ? "eye" : "eye-off"} size={9}/>
                                                                        </button>
                                                                    </div>
                                                                </th>
                                                                <th className="py-2 w-20 text-pink-600 border-l border-slate-100">Cộng HK1</th>
                                                                <th className="py-2 w-20 text-pink-600 border-l border-slate-100">Cộng HK2</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody className="divide-y divide-slate-100/60">
                                                            {classStudents.map((s, idx) => {
                                                                const sScores = scoresGrid[s.id] || { tx1: '', tx2: '', tx3: '', tx4: '', tx5: '', gk: '', ck: '' };
                                                                return (
                                                                    <tr key={s.id} className="text-xs text-slate-700 hover:bg-slate-50/30 transition-colors">
                                                                        <td className="py-2 pl-4 text-left font-bold text-slate-400">{String(idx + 1).padStart(2, '0')}</td>
                                                                        <td className="py-2 text-left font-mono font-bold text-slate-400">{s.id}</td>
                                                                        <td className="py-2 text-left font-bold text-slate-800 pl-2">
                                                                            <div className="flex items-center gap-1.5">
                                                                                <span>{s.fullName}</span>
                                                                                <button
                                                                                    type="button"
                                                                                    onClick={() => setEditingStudentInfo(s)}
                                                                                    className="p-1 text-slate-400 hover:text-sky-600 hover:bg-sky-100/70 rounded-md transition-all shrink-0"
                                                                                    title="Chỉnh sửa thông tin học sinh (Mã HS, Họ tên, Lớp, Mật khẩu)"
                                                                                >
                                                                                    <Icon name="edit-3" size={12} />
                                                                                </button>
                                                                            </div>
                                                                        </td>
                                                                        <td className="py-1">
                                                                            <input type="text" value={sScores.tx1 || ''} onChange={e => handleCellChange(s.id, 'tx1', e.target.value)} className="w-12 px-1 py-1 border border-emerald-200 bg-emerald-50/30 text-emerald-800 rounded-lg text-center font-bold outline-none focus:bg-white focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 text-xs" />
                                                                        </td>
                                                                        <td className="py-1">
                                                                            <input type="text" value={sScores.tx2 || ''} onChange={e => handleCellChange(s.id, 'tx2', e.target.value)} className="w-12 px-1 py-1 border border-emerald-200 bg-emerald-50/30 text-emerald-800 rounded-lg text-center font-bold outline-none focus:bg-white focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 text-xs" />
                                                                        </td>
                                                                        <td className="py-1">
                                                                            <input type="text" value={sScores.tx3 || ''} onChange={e => handleCellChange(s.id, 'tx3', e.target.value)} className="w-12 px-1 py-1 border border-emerald-200 bg-emerald-50/30 text-emerald-800 rounded-lg text-center font-bold outline-none focus:bg-white focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 text-xs" />
                                                                        </td>
                                                                        <td className="py-1">
                                                                            <input type="text" value={sScores.tx4 || ''} onChange={e => handleCellChange(s.id, 'tx4', e.target.value)} className="w-12 px-1 py-1 border border-emerald-200 bg-emerald-50/30 text-emerald-800 rounded-lg text-center font-bold outline-none focus:bg-white focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 text-xs" />
                                                                        </td>
                                                                        <td className="py-1">
                                                                            <input type="text" value={sScores.tx5 || ''} onChange={e => handleCellChange(s.id, 'tx5', e.target.value)} className="w-12 px-1 py-1 border border-emerald-200 bg-emerald-50/30 text-emerald-800 rounded-lg text-center font-bold outline-none focus:bg-white focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 text-xs" />
                                                                        </td>
                                                                        <td className="py-1">
                                                                            <input type="text" value={sScores.gk || ''} onChange={e => handleCellChange(s.id, 'gk', e.target.value)} className="w-14 px-1 py-1 border border-violet-200 bg-violet-50/35 text-violet-850 rounded-lg text-center font-black outline-none focus:bg-white focus:border-violet-500 focus:ring-1 focus:ring-violet-500/20 text-xs" />
                                                                        </td>
                                                                        <td className="py-1">
                                                                            <input type="text" value={sScores.ck || ''} onChange={e => handleCellChange(s.id, 'ck', e.target.value)} className="w-14 px-1 py-1 border border-rose-200 bg-rose-50/30 text-rose-850 rounded-lg text-center font-black outline-none focus:bg-white focus:border-rose-500 focus:ring-1 focus:ring-rose-500/20 text-xs" />
                                                                        </td>
                                                                        <td className="py-1 border-l border-slate-100">
                                                                            <button onClick={() => setEditingBonusPoints({ ...s, semester: 'hk1' })} className="px-2 py-1 bg-pink-50 hover:bg-pink-100 text-pink-700 font-black rounded-lg border border-pink-150 text-[10px] transition-all shadow-sm">
                                                                                +{typeof s.bonusPoints === 'object' ? (s.bonusPoints.hk1 || 0) : (s.bonusPoints || 0)}
                                                                            </button>
                                                                        </td>
                                                                        <td className="py-1 border-l border-slate-100">
                                                                            <button onClick={() => setEditingBonusPoints({ ...s, semester: 'hk2' })} className="px-2 py-1 bg-pink-50 hover:bg-pink-100 text-pink-700 font-black rounded-lg border border-pink-150 text-[10px] transition-all shadow-sm">
                                                                                +{typeof s.bonusPoints === 'object' ? (s.bonusPoints.hk2 || 0) : 0}
                                                                            </button>
                                                                        </td>
                                                                    </tr>
                                                                );
                                                            })}
                                                        </tbody>
                                                    </table>
                                                </div>
                                            )}
                                        </div>
                                    </>
                                ) : (
                                    <div className="py-20 text-center flex flex-col items-center justify-center text-slate-400 opacity-60">
                                        <Icon name="folder-open" size={48} className="mb-3" />
                                        <p className="text-sm font-bold uppercase tracking-wider">Vui lòng chọn một lớp ở cột bên trái</p>
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Sync from Web Quiz dialog */}
                        {showSyncDialog && (
                            <div className="fixed inset-0 z-[120] bg-slate-950/60 backdrop-blur-sm flex items-center justify-center p-4">
                                <div className="bg-white w-full max-w-sm rounded-[2rem] shadow-2xl flex flex-col overflow-hidden border border-slate-100 animate-in zoom-in-95 duration-200">
                                    <header className="p-5 border-b flex justify-between items-center bg-indigo-50 shrink-0">
                                        <div className="flex items-center gap-3">
                                            <div className="p-2 bg-indigo-100 text-indigo-700 rounded-lg"><Icon name="refresh-cw" size={14}/></div>
                                            <h3 className="text-sm font-black uppercase tracking-widest text-slate-800">Đồng bộ từ Bài thi Web</h3>
                                        </div>
                                        <button onClick={() => setShowSyncDialog(false)} className="w-8 h-8 flex items-center justify-center bg-rose-500 text-white hover:bg-rose-600 rounded-full transition-colors shadow-md"><Icon name="x" size={18}/></button>
                                    </header>
                                    <div className="p-6 space-y-4 text-xs">
                                        <div>
                                            <label className="block text-[10px] font-black text-slate-400 uppercase tracking-wider mb-1.5 ml-1">Chọn Đề kiểm tra trên Web:</label>
                                            <select 
                                                value={syncParams.examId} 
                                                onChange={e => setSyncParams({ ...syncParams, examId: e.target.value })}
                                                className="w-full bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-200 font-bold outline-none text-slate-700"
                                            >
                                                <option value="">-- Chọn bài kiểm tra --</option>
                                                {exams.filter(ex => {
                                                    if (!ex.selectedClasses) return false;
                                                    return ex.selectedClasses.some(c => {
                                                        if (typeof c === 'string') return c === selectedClass;
                                                        return c && c.className === selectedClass;
                                                    });
                                                }).map(ex => (
                                                    <option key={ex.id} value={ex.id}>{ex.title}</option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="grid grid-cols-2 gap-3">
                                            <div>
                                                <label className="block text-[10px] font-black text-slate-400 uppercase tracking-wider mb-1.5 ml-1">Đổ vào Cột Điểm:</label>
                                                <select 
                                                    value={syncParams.column} 
                                                    onChange={e => setSyncParams({ ...syncParams, column: e.target.value })}
                                                    className="w-full bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-200 font-bold outline-none text-slate-700"
                                                >
                                                    <option value="tx1">TX 1</option>
                                                    <option value="tx2">TX 2</option>
                                                    <option value="tx3">TX 3</option>
                                                    <option value="tx4">TX 4</option>
                                                    <option value="tx5">TX 5</option>
                                                    <option value="gk">Giữa kỳ</option>
                                                    <option value="ck">Cuối kỳ</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label className="block text-[10px] font-black text-slate-400 uppercase tracking-wider mb-1.5 ml-1">Phương thức lấy điểm:</label>
                                                <select 
                                                    value={syncParams.mode} 
                                                    onChange={e => setSyncParams({ ...syncParams, mode: e.target.value })}
                                                    className="w-full bg-slate-50 px-3 py-2.5 rounded-xl border border-slate-200 font-bold outline-none text-slate-700"
                                                >
                                                    <option value="highest">Điểm cao nhất</option>
                                                    <option value="latest">Lần thi cuối</option>
                                                </select>
                                            </div>
                                        </div>
                                        <p className="text-[10px] text-amber-600 font-bold leading-relaxed bg-amber-50 p-3 rounded-xl border border-amber-100">
                                            * Hệ thống sẽ tự chuẩn hóa viết hoa và khoảng trắng để so khớp họ tên học sinh lớp {selectedClass} với lịch sử thi trên Web.
                                        </p>
                                    </div>
                                    <footer className="p-4 border-t border-slate-100 bg-slate-50 flex justify-end gap-2 shrink-0">
                                        <button onClick={() => setShowSyncDialog(false)} className="px-4 py-2.5 bg-slate-200 hover:bg-slate-300 text-slate-700 font-black rounded-xl text-[10px] uppercase tracking-widest">Hủy</button>
                                        <button onClick={handleSyncFromWeb} className="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-black rounded-xl text-[10px] uppercase tracking-widest shadow-md shadow-indigo-600/10">Bắt đầu Đồng bộ</button>
                                    </footer>
                                </div>
                            </div>
                        )}
                        {promptDialog.isOpen && (
                            <div className="fixed inset-0 z-[200] bg-slate-950/60 backdrop-blur-sm flex items-center justify-center p-4">
                                <div className="bg-white w-full max-w-sm rounded-[2rem] shadow-2xl overflow-hidden border border-slate-100 p-6 space-y-4 animate-in zoom-in-95 duration-200">
                                    <h4 className="text-sm font-black uppercase tracking-widest text-slate-800">{promptDialog.title}</h4>
                                    <input 
                                        type="text" 
                                        defaultValue={promptDialog.defaultValue}
                                        placeholder={promptDialog.placeholder}
                                        id="gradebook-prompt-input-value"
                                        className="w-full bg-slate-50 border border-slate-200 px-4 py-3 rounded-xl font-bold outline-none focus:bg-white focus:border-sky-500 text-sm text-center" 
                                    />
                                    <div className="flex gap-2">
                                        <button onClick={() => setPromptDialog({ ...promptDialog, isOpen: false })} className="flex-1 py-3 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl font-black text-[10px] uppercase tracking-wider transition-all">Hủy</button>
                                        <button onClick={() => {
                                            const val = document.getElementById("gradebook-prompt-input-value")?.value;
                                            promptDialog.onConfirm(val);
                                            setPromptDialog({ ...promptDialog, isOpen: false });
                                        }} className="flex-1 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-black text-[10px] uppercase tracking-wider transition-all shadow-md shadow-indigo-600/10">Xác nhận</button>
                                    </div>
                                </div>
                            </div>
                        )}
                        {editingBonusPoints && (
                            <BonusPointsEditorModal 
                                student={editingBonusPoints} 
                                semester={editingBonusPoints.semester}
                                onClose={() => {
                                    setEditingBonusPoints(null);
                                }} 
                                onSave={handleSaveBonusPoints}
                                showAlert={showAlert}
                            />
                        )}
                        {editingStudentInfo && (
                            <EditStudentInfoModal 
                                student={editingStudentInfo}
                                classes={globalClasses}
                                onClose={() => setEditingStudentInfo(null)}
                                showAlert={showAlert}
                                onSaved={(updatedStudent, oldId) => {
                                    if (oldId !== updatedStudent.id) {
                                        setScoresGrid(prev => {
                                            const next = { ...prev };
                                            if (next[oldId] !== undefined) {
                                                next[updatedStudent.id] = next[oldId];
                                                delete next[oldId];
                                            }
                                            return next;
                                        });
                                    }
                                    loadGradebookData();
                                }}
                            />
                        )}
                        {showExcelScoreModal && (
                            <ExcelScoreImportModal 
                                onClose={() => setShowExcelScoreModal(false)}
                                classStudents={classStudents}
                                selectedClass={selectedClass}
                                scoresGrid={scoresGrid}
                                showAlert={showAlert}
                                onApplyScores={(newGrid, colName, count) => {
                                    setScoresGrid(newGrid);
                                    setIsScoresSaved(false);
                                    showAlert(`Đã đổ điểm vào cột ${colName.toUpperCase()} cho ${count}/${classStudents.length} học sinh lớp ${selectedClass}! Nhớ bấm "Lưu Sổ Điểm" để lưu lại.`);
                                }}
                            />
                        )}
                    </div>
                </div>
            );
        };

        const UserManagerModal = ({ onClose, showAlert, showDangerConfirm }) => {
            const [users, setUsers] = useState([]);
            const [isLoading, setIsLoading] = useState(true);
            const [isAdding, setIsAdding] = useState(false);
            const [promptDialog, setPromptDialog] = useState({ isOpen: false, title: '', placeholder: '', defaultValue: '', onConfirm: null });

            const loadUsers = async () => {
                setIsLoading(true);
                try {
                    const r = await fetch('?action=list_users&t=' + Date.now());
                    const res = await r.json();
                    if (res.success) {
                        setUsers(res.users);
                    } else {
                        showAlert(res.message || "Lỗi tải danh sách tài khoản!");
                    }
                } catch(e) {
                    showAlert("Lỗi kết nối máy chủ!");
                }
                setIsLoading(false);
            };

            useEffect(() => {
                loadUsers();
            }, []);

            const handleAddUser = async (e) => {
                e.preventDefault();
                setIsAdding(true);
                const formData = new FormData(e.target);
                const data = {};
                formData.forEach((v, k) => data[k] = v);

                try {
                    const r = await fetch('?action=add_user', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(data)
                    });
                    const res = await r.json();
                    if (res.success) {
                        showAlert("Thêm tài khoản thành công!");
                        e.target.reset();
                        loadUsers();
                    } else {
                        showAlert(res.message || "Lỗi thêm tài khoản!");
                    }
                } catch(e) {
                    showAlert("Lỗi kết nối máy chủ!");
                }
                setIsAdding(false);
            };

            const handleDeleteUser = async (username) => {
                showDangerConfirm(`Bạn có chắc chắn muốn xóa tài khoản "${username}" không?\nHành động này không thể hoàn tác!`, async () => {
                    try {
                        const r = await fetch('?action=delete_user', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ username })
                        });
                        const res = await r.json();
                        if (res.success) {
                            showAlert("Đã xóa tài khoản!");
                            loadUsers();
                        } else {
                            showAlert(res.message || "Lỗi xóa tài khoản!");
                        }
                    } catch(e) {
                        showAlert("Lỗi kết nối máy chủ!");
                    }
                });
            };

            const handleUpdateFullName = (username, oldName) => {
                setPromptDialog({
                    isOpen: true,
                    title: 'Đổi họ tên hiển thị',
                    placeholder: 'Nhập họ và tên hiển thị mới...',
                    defaultValue: oldName,
                    onConfirm: async (newName) => {
                        if (newName.trim() === '') {
                            return showAlert("Họ và tên không được để trống!");
                        }
                        try {
                            const r = await fetch('?action=update_subadmin_name', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ username, fullName: newName.trim() })
                            });
                            const res = await r.json();
                            if (res.success) {
                                showAlert("Cập nhật họ và tên thành công!");
                                loadUsers();
                            } else {
                                showAlert(res.message || "Lỗi cập nhật!");
                            }
                        } catch(e) {
                            showAlert("Lỗi kết nối máy chủ!");
                        }
                    }
                });
            };

            return (
                <div className="fixed inset-0 z-[100] bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4 animate-in fade-in duration-300">
                    <div className="bg-white w-full max-w-4xl rounded-[2rem] shadow-2xl flex flex-col overflow-hidden relative border border-slate-200 h-[90vh] md:h-[80vh]">
                        <header className="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50 shrink-0">
                            <div className="flex items-center gap-3">
                                <div className="p-2.5 bg-cyan-100 text-cyan-600 rounded-xl">
                                    <Icon name="users" size={18}/>
                                </div>
                                <div>
                                    <h2 className="text-base font-black uppercase tracking-widest text-slate-800">Quản Lý Tài Khoản Admin Phụ</h2>
                                    <p className="text-[10px] font-bold text-slate-400 mt-0.5">Thêm, xóa và cấp quyền tài khoản trợ giảng</p>
                                </div>
                            </div>
                            <button onClick={onClose} className="flex items-center gap-1.5 px-4 py-2 bg-rose-50 hover:bg-rose-500 text-rose-600 hover:text-white rounded-2xl transition-all border border-rose-100 hover:border-rose-500 shadow-sm text-xs font-black uppercase tracking-widest">
                                <Icon name="x" size={14}/>
                                <span>Đóng</span>
                            </button>
                        </header>

                        <div className="flex-1 overflow-y-auto p-6 md:p-8 flex flex-col lg:flex-row gap-8">
                            {/* FORM THÊM TÀI KHOẢN MỚI */}
                            <div className="w-full lg:w-80 shrink-0">
                                <div className="bg-slate-50 border border-slate-100 rounded-3xl p-5 md:p-6">
                                    <h3 className="text-xs font-black text-slate-700 uppercase tracking-widest mb-4 flex items-center gap-1.5 pb-2 border-b border-slate-200/80">
                                        <Icon name="user-plus" size={14} className="text-cyan-500"/>
                                        Thêm tài khoản mới
                                    </h3>
                                    <form onSubmit={handleAddUser} className="space-y-4">
                                        <div>
                                            <label className="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5 ml-1">Tên đăng nhập</label>
                                            <input name="username" placeholder="Ví dụ: thaynam@admin" className="w-full bg-white border border-slate-200 px-3.5 py-2.5 rounded-xl font-bold outline-none focus:ring-2 focus:ring-cyan-500 transition-all text-xs" required />
                                        </div>
                                        <div>
                                            <label className="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5 ml-1">Mật khẩu</label>
                                            <input name="password" type="password" placeholder="••••••••" className="w-full bg-white border border-slate-200 px-3.5 py-2.5 rounded-xl font-bold outline-none focus:ring-2 focus:ring-cyan-500 transition-all text-xs" required />
                                        </div>
                                        <div>
                                            <label className="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1.5 ml-1">Họ và tên hiển thị</label>
                                            <input name="fullName" placeholder="Ví dụ: Thầy Nguyễn Văn Nam" className="w-full bg-white border border-slate-200 px-3.5 py-2.5 rounded-xl font-bold outline-none focus:ring-2 focus:ring-cyan-500 transition-all text-xs" required />
                                        </div>
                                        <button type="submit" disabled={isAdding} className="w-full bg-cyan-600 hover:bg-cyan-700 disabled:opacity-50 text-white py-3 rounded-xl font-black uppercase tracking-widest text-xs shadow-md shadow-cyan-500/10 transition-all mt-2">
                                            {isAdding ? "Đang xử lý..." : "Tạo tài khoản"}
                                        </button>
                                    </form>
                                </div>
                            </div>

                            {/* DANH SÁCH TÀI KHOẢN HIỆN TẠI */}
                            <div className="flex-1 min-w-0 flex flex-col">
                                <h3 className="text-xs font-black text-slate-700 uppercase tracking-widest mb-4 flex items-center gap-1.5 pb-2 border-b border-slate-100">
                                    <Icon name="list" size={14} className="text-cyan-500"/>
                                    Danh sách tài khoản ({users.length})
                                </h3>

                                {isLoading ? (
                                    <div className="flex-1 flex items-center justify-center py-20 text-slate-400">
                                        <Icon name="loader-2" size={32} className="animate-spin" />
                                    </div>
                                ) : (
                                    <div className="flex-1 space-y-3">
                                        {users.map((u, idx) => (
                                            <div key={idx} className="bg-white border border-slate-200/80 rounded-2xl p-4 flex justify-between items-center hover:border-slate-300 hover:shadow-sm transition-all duration-300">
                                                <div className="flex items-center gap-3.5 min-w-0">
                                                    <div className={`w-10 h-10 rounded-xl flex items-center justify-center shrink-0 font-black uppercase text-sm ${u.is_owner ? 'bg-rose-50 text-rose-600 border border-rose-100' : 'bg-slate-100 text-slate-600 border border-slate-200'}`}>
                                                        {u.fullName ? u.fullName.substring(0, 1) : u.username.substring(0, 1)}
                                                    </div>
                                                    <div className="min-w-0">
                                                        <div className="flex items-center gap-2">
                                                            <span className="font-black text-slate-800 text-sm truncate">{u.fullName}</span>
                                                            {u.is_owner && (
                                                                <span className="bg-rose-500 text-white text-[8px] font-black uppercase px-1.5 py-0.5 rounded shadow-sm shadow-rose-500/10">Owner</span>
                                                            )}
                                                        </div>
                                                        <span className="block text-[10px] font-medium text-slate-400 mt-0.5">{u.username}</span>
                                                    </div>
                                                </div>

                                                <div className="flex items-center gap-2">
                                                    {!u.is_owner && (
                                                        <>
                                                            <button onClick={() => handleUpdateFullName(u.username, u.fullName)} className="flex items-center gap-1 px-2.5 py-1.5 bg-amber-50 hover:bg-amber-500 text-amber-700 hover:text-white rounded-xl transition-all border border-amber-100 hover:border-amber-500 text-[10px] font-bold" title="Đổi họ tên hiển thị">
                                                                <Icon name="edit-3" size={12}/>
                                                                <span>Đổi tên</span>
                                                            </button>
                                                            <button onClick={() => {
                                                                setPromptDialog({
                                                                    isOpen: true,
                                                                    title: 'Đổi mật khẩu',
                                                                    placeholder: 'Nhập mật khẩu mới...',
                                                                    defaultValue: '',
                                                                    onConfirm: async (newPass) => {
                                                                        if (newPass.trim() === '') {
                                                                            return showAlert("Mật khẩu không được để trống!");
                                                                        }
                                                                        try {
                                                                            const r = await fetch('?action=reset_admin_password', {
                                                                                method: 'POST',
                                                                                headers: { 'Content-Type': 'application/json' },
                                                                                body: JSON.stringify({ username: u.username, password: newPass.trim() })
                                                                            });
                                                                            const res = await r.json();
                                                                            if (res.success) {
                                                                                showAlert("Đổi mật khẩu thành công!");
                                                                            } else {
                                                                                showAlert(res.message || "Lỗi đổi mật khẩu!");
                                                                            }
                                                                        } catch (err) {
                                                                            showAlert("Lỗi kết nối máy chủ!");
                                                                        }
                                                                    }
                                                                });
                                                            }} className="flex items-center gap-1 px-2.5 py-1.5 bg-cyan-50 hover:bg-cyan-500 text-cyan-700 hover:text-white rounded-xl transition-all border border-cyan-100 hover:border-cyan-500 text-[10px] font-bold" title="Đổi mật khẩu tài khoản này">
                                                                <Icon name="key" size={12}/>
                                                                <span>Mật khẩu</span>
                                                            </button>
                                                            <button onClick={() => handleDeleteUser(u.username)} className="flex items-center gap-1 px-2.5 py-1.5 bg-rose-50 hover:bg-rose-500 text-rose-700 hover:text-white rounded-xl transition-all border border-rose-100 hover:border-rose-500 text-[10px] font-bold" title="Xóa tài khoản này">
                                                                <Icon name="trash-2" size={12}/>
                                                                <span>Xóa</span>
                                                            </button>
                                                        </>
                                                    )}
                                                </div>
                                            </div>
                                        ))}

                                        {users.length === 0 && (
                                            <div className="py-20 text-center flex flex-col items-center gap-2 text-slate-400 opacity-60">
                                                <Icon name="users" size={32} />
                                                <p className="text-sm font-bold">Chưa có tài khoản nào được tạo</p>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {promptDialog.isOpen && (
                        <div className="fixed inset-0 z-[110] flex items-center justify-center p-4 animate-in fade-in duration-200">
                            <div className="absolute inset-0 bg-slate-950/60 backdrop-blur-sm" onClick={() => setPromptDialog({ ...promptDialog, isOpen: false })}></div>
                            <div className="relative bg-white rounded-[2rem] shadow-2xl w-full max-w-md overflow-hidden border border-slate-100 flex flex-col animate-in zoom-in-95 duration-200">
                                <header className="p-5 border-b border-slate-100 bg-slate-50 flex items-center gap-3 shrink-0">
                                    <div className="p-2 bg-indigo-100 text-indigo-600 rounded-xl">
                                        <Icon name={promptDialog.title === 'Đổi mật khẩu' ? 'key' : 'edit-3'} size={16}/>
                                    </div>
                                    <h3 className="text-sm font-black uppercase tracking-widest text-slate-800">{promptDialog.title}</h3>
                                </header>
                                <form onSubmit={(e) => {
                                    e.preventDefault();
                                    const val = e.target.inputVal.value;
                                    promptDialog.onConfirm(val);
                                    setPromptDialog({ ...promptDialog, isOpen: false });
                                }} className="p-6 space-y-6">
                                    <div>
                                        <input 
                                            name="inputVal" 
                                            type={promptDialog.title === 'Đổi mật khẩu' ? 'password' : 'text'}
                                            defaultValue={promptDialog.defaultValue} 
                                            placeholder={promptDialog.placeholder}
                                            className="w-full bg-slate-50 border border-slate-200 p-4 rounded-2xl font-bold outline-none focus:ring-2 focus:ring-indigo-500 text-slate-800 text-center text-sm transition-all"
                                            required 
                                            autoFocus
                                        />
                                    </div>
                                    <div className="flex gap-3 pt-2">
                                        <button type="button" onClick={() => setPromptDialog({ ...promptDialog, isOpen: false })} className="flex-1 py-3 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl font-bold text-xs uppercase tracking-wider transition-all">Hủy</button>
                                        <button type="submit" className="flex-1 py-3 bg-gradient-to-r from-indigo-500 to-blue-500 hover:from-indigo-600 hover:to-blue-600 text-white rounded-xl font-black text-xs uppercase tracking-wider shadow-lg shadow-indigo-500/20 hover:shadow-indigo-500/40 transition-all">Xác nhận</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    )}
                </div>
            );
        };

        const App = () => {
            const [isLogin, setIsLogin] = useState(false);
            const [adminUser, setAdminUser] = useState({ username: '', fullName: '', isOwner: false, role: 'admin' });
            const [showStudentManager, setShowStudentManager] = useState(false);
            const [adminFormMode, setAdminFormMode] = useState('login');
            const [viewMode, setViewMode] = useState('active'); // 'active' or 'trash'
            const [sharingExam, setSharingExam] = useState(null);
            const [exams, setExams] = useState([]);
            const [globalClasses, setGlobalClasses] = useState([]); 
            const [showClassManager, setShowClassManager] = useState(false); 
            const [showGradebook, setShowGradebook] = useState(false); // State hiển thị Sổ Điểm
            const [gradebookInitialClass, setGradebookInitialClass] = useState(null);
            const [showPublishSettings, setShowPublishSettings] = useState(null);
            const [showDocManager, setShowDocManager] = useState(false); // State hiển thị Quản Lý Tài Liệu
            const [showVideoManager, setShowVideoManager] = useState(false); // State hiển thị Quản Lý Video
            const [showNotifManager, setShowNotifManager] = useState(false); // State hiển thị Quản Lý Thông Báo
            const [showUserManager, setShowUserManager] = useState(false); // State hiển thị Quản Lý Tài Khoản Admin phụ
            const [selectedFileName, setSelectedFileName] = useState("");
            const [activeGrade, setActiveGrade] = useState('tot_nghiep'); 
            const [activeParentCategory, setActiveParentCategory] = useState('on_thi_tn'); 
            const [activeChildCategory, setActiveChildCategory] = useState('chinh_thuc');

            const [trashState, setTrashState] = useState({ step: 1, grade: null });
            const [trashModal, setTrashModal] = useState({ isOpen: false, grade: null, category: null });

            const [isUploading, setIsUploading] = useState(false);
            const [editingTex, setEditingTex] = useState(null);
            
            const [aiContext, setAiContext] = useState(null);

            const [texContent, setTexContent] = useState("");
            const [isSavingTex, setIsSavingTex] = useState(false);
            const [isUploadingImg, setIsUploadingImg] = useState(false);
            const [imgCodeModal, setImgCodeModal] = useState(null); 
            
            // TikZ Direct Editor states
            const [showTikzModal, setShowTikzModal] = useState(false);
            const [tikzCode, setTikzCode] = useState(
`\\begin{tikzpicture}[scale=1, >=stealth]
    % Định nghĩa các điểm
    \\coordinate (O) at (0,0);
    \\coordinate (A) at (3,0);
    \\coordinate (B) at (1.5,2.59);
    
    % Vẽ tam giác
    \\draw[thick, fill=blue!5] (A) -- (B) -- (O) -- cycle;
    
    % Kí hiệu đỉnh
    \\draw (O) node[below left] {$O$};
    \\draw (A) node[below right] {$A$};
    \\draw (B) node[above] {$B$};
\\end{tikzpicture}`
            );
            const [isCompilingTikz, setIsCompilingTikz] = useState(false);
            
            const [uploadMode, setUploadMode] = useState('normal');

            const [editingSettings, setEditingSettings] = useState(null);
            const [editingScoring, setEditingScoring] = useState(null);
            const [editingSchedule, setEditingSchedule] = useState(null); 
            const [uploadingList, setUploadingList] = useState(null);

            const [editForm, setEditForm] = useState({});
            const [isSavingSettings, setIsSavingSettings] = useState(false);
            
            const [viewHistory, setViewHistory] = useState(null);
            const [viewLive, setViewLive] = useState(null);
            
            const [dialog, setDialog] = useState({ isOpen: false, type: 'alert', message: '', onConfirm: () => {} });
            
            const showAlert = (message, callback = null) => setDialog({ 
                isOpen: true, 
                type: 'alert', 
                message, 
                onConfirm: () => {
                    setDialog(prev => ({ ...prev, isOpen: false }));
                    if (callback) callback();
                } 
            });
            const showConfirm = (message, onConfirm) => setDialog({ isOpen: true, type: 'confirm', message, onConfirm });
            const showDangerConfirm = (message, onConfirm) => setDialog({ isOpen: true, type: 'danger', message, onConfirm });
            const closeDialog = () => setDialog(prev => ({ ...prev, isOpen: false }));

            const copyToClipboard = (text, successMsg) => {
                const ref = window.CodeMirror && document.querySelector('.CodeMirror');
                if (ref && ref.CodeMirror) {
                    ref.CodeMirror.focus();
                }

                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(() => {
                        showAlert(successMsg);
                    }).catch(() => fallbackCopy(text, successMsg));
                } else {
                    fallbackCopy(text, successMsg);
                }
            };

            const fallbackCopy = (text, successMsg) => {
                const textArea = document.createElement("textarea");
                textArea.value = text;
                textArea.style.position = "fixed"; 
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                try {
                    document.execCommand('copy');
                    showAlert(successMsg);
                } catch (err) {
                    showAlert("Trình duyệt không hỗ trợ tự động copy, vui lòng copy thủ công:\n\n" + text);
                }
                document.body.removeChild(textArea);
            };

            const getStatusWeight = (e) => {
                const now = Date.now();
                if (!e.isPublished) return 2;
                const hasStart = !!e.publishStartTime;
                const hasEnd = !!e.publishEndTime;
                const startT = hasStart ? new Date(e.publishStartTime).getTime() : 0;
                const endT = hasEnd ? new Date(e.publishEndTime).getTime() : 0;

                if (hasStart && now < startT) return 1; 
                if (hasEnd && now > endT) return 3; 
                return 0; 
            };

            const load = () => {
                fetch('?action=list&t=' + Date.now()).then(r => r.json()).then(d => {
                    setExams(d || []);
                });
                fetch('?action=get_classes&t=' + Date.now()).then(r => r.json()).then(d => {
                    setGlobalClasses(d || []);
                });
            };

            useEffect(() => {
                fetch('?action=auth_check').then(r=>r.json()).then(d => { 
                    if(d.loggedIn) { 
                        setIsLogin(true); 
                        setAdminUser({ 
                            username: d.username, 
                            fullName: d.fullName, 
                            isOwner: d.is_owner || false, 
                            role: d.role || 'admin',
                            class: d.class || '',
                            studyScores: d.studyScores || null,
                            studyScoresPublished: d.studyScoresPublished || false,
                            bonusPoints: d.bonusPoints ?? 0,
                            bonusPointsHistory: d.bonusPointsHistory ?? []
                        });
                        if (d.role !== 'student') {
                            load(); 
                        }
                    } 
                });
            }, []);

            const openEditTex = async (exam) => {
                const r = await fetch(`?action=get_tex&id=${exam.id}&t=${Date.now()}`);
                const res = await r.json();
                if (res.success) { setTexContent(res.content); setEditingTex(exam); } 
                else { showAlert("Không thể tải mã nguồn!"); }
            };

            const openAiGenerator = async (exam) => {
                const r = await fetch(`?action=get_tex&id=${exam.id}&t=${Date.now()}`);
                const res = await r.json();
                if (res.success) {
                    setAiContext({ ...exam, texContent: res.content });
                } else {
                    showAlert("Không thể tải mã nguồn đề gốc!");
                }
            };

            const saveTex = async () => {
                setIsSavingTex(true);
                const r = await fetch('?action=save_tex', { method: 'POST', body: JSON.stringify({ id: editingTex.id, content: texContent }) });
                const res = await r.json();
                setIsSavingTex(false);
                if (res.success) { showAlert("Cập nhật và bóc tách lại thành công!"); setEditingTex(null); load(); } 
                else { showAlert("Lỗi khi lưu!"); }
            };

            const openEditSettings = (exam) => {
                setEditForm({
                    id: exam.id,
                    title: exam.title,
                    duration: exam.duration,
                    maxViolations: exam.maxViolations !== undefined ? exam.maxViolations : 2,
                    maxAttempts: exam.maxAttempts !== undefined ? exam.maxAttempts : 0, 
                    allowReview: exam.allowReview !== undefined ? exam.allowReview : true,
                    examMode: exam.examMode || 'normal',
                    grade: exam.grade || '12',
                    category: exam.category || 'ghk1',
                    assignedClass: exam.assignedClass || ''
                });
                setEditingSettings(exam);
            };

            const saveSettings = async (e) => {
                e.preventDefault();
                setIsSavingSettings(true);
                const r = await fetch('?action=save_settings', { method: 'POST', body: JSON.stringify(editForm) });
                const res = await r.json();
                setIsSavingSettings(false);
                if (res.success) { showAlert("Cập nhật cài đặt thành công!"); setEditingSettings(null); load(); } 
                else { showAlert("Lỗi khi lưu!"); }
            };
            
            const togglePublish = async (id) => {
                const r = await fetch('?action=toggle_publish', { method: 'POST', body: JSON.stringify({ id }) });
                const res = await r.json();
                if(res.success) load();
            };
            
            const togglePublishGrades = async (id) => {
                const r = await fetch('?action=toggle_publish_grades', { method: 'POST', body: JSON.stringify({ id }) });
                const res = await r.json();
                if(res.success) load();
            };
            
            const togglePublishAnswers = async (id) => {
                const r = await fetch('?action=toggle_publish_answers', { method: 'POST', body: JSON.stringify({ id }) });
                const res = await r.json();
                if(res.success) load();
            };

            const restoreExam = async (id) => {
                await fetch(`?action=restore&id=${id}&t=${Date.now()}`);
                showAlert("Đã khôi phục đề thi thành công!");
                load();
            };

            const hardDeleteExam = (id) => {
                showDangerConfirm('Bạn có chắc chắn muốn XÓA VĨNH VIỄN đề này không?\nThao tác này KHÔNG THỂ KHÔI PHỤC và file LaTeX gốc trên máy chủ cũng sẽ bị xóa!', async () => {
                    await fetch(`?action=hard_delete&id=${id}&t=${Date.now()}`);
                    load();
                });
            };

            const handleGradeChange = (gradeId) => {
                setActiveGrade(gradeId);
                if (gradeId === 'tot_nghiep') {
                    setActiveParentCategory('on_thi_tn');
                    setActiveChildCategory('chinh_thuc');
                } else if (activeParentCategory === 'on_thi_tn') {
                    setActiveParentCategory('on_giua_ki');
                    setActiveChildCategory('ghk1');
                }
            };

            const handleParentChange = (parentKey) => {
                setActiveParentCategory(parentKey);
                const firstChildKey = Object.keys(CATEGORY_TREE[parentKey].items)[0];
                setActiveChildCategory(firstChildKey);
            };

            const availableParentCategories = Object.entries(CATEGORY_TREE).filter(([k,v]) => {
                if (activeGrade !== 'tot_nghiep' && k === 'on_thi_tn') return false;
                if (activeGrade === 'tot_nghiep' && k !== 'on_thi_tn') return false;
                return true;
            });

            const getCategoriesForGrade = (gradeId) => {
                let cats = [];
                Object.entries(CATEGORY_TREE).forEach(([parentKey, parentVal]) => {
                    if (gradeId === 'tot_nghiep' && parentKey === 'on_thi_tn') {
                        Object.entries(parentVal.items).forEach(([childKey, childName]) => cats.push({id: childKey, label: childName, parent: parentVal.label}));
                    } else if (gradeId !== 'tot_nghiep' && parentKey !== 'on_thi_tn') {
                        Object.entries(parentVal.items).forEach(([childKey, childName]) => cats.push({id: childKey, label: childName, parent: parentVal.label}));
                    }
                });
                return cats;
            };

            const filteredExams = React.useMemo(() => {
                const filtered = exams.filter(e => !e.isDeleted && (e.category || 'khac') === activeChildCategory && (e.grade || '12') === activeGrade);
                return filtered.sort((a, b) => getStatusWeight(a) - getStatusWeight(b));
            }, [exams, activeChildCategory, activeGrade]);
            
            const normalExams = filteredExams.filter(e => !e.examMode || e.examMode === 'normal');
            const testExams = filteredExams.filter(e => e.examMode === 'test');
            const practiceExams = filteredExams.filter(e => e.examMode === 'practice');

        const PublishSettingsModal = ({ exam, onClose, loadExams, showAlert }) => {
            const [publishGrades, setPublishGrades] = useState(exam.publishGrades || false);
            const [publishAnswers, setPublishAnswers] = useState(exam.publishAnswers || false);
            const [isSaving, setIsSaving] = useState(false);

            const handleSave = async () => {
                setIsSaving(true);
                const payload = {
                    id: exam.id,
                    isPublished: exam.isPublished,
                    publishGrades,
                    publishAnswers
                };
                const r = await fetch('?action=save_publish_settings', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const res = await r.json();
                setIsSaving(false);
                if (res.success) {
                    showAlert("Cập nhật cấu hình công bố thành công!");
                    loadExams();
                    onClose();
                } else {
                    showAlert(res.message || "Lỗi khi lưu cấu hình công bố!");
                }
            };

            return (
                <div className="fixed inset-0 z-[140] bg-slate-950/60 backdrop-blur-sm flex items-center justify-center p-4 animate-in fade-in duration-200">
                    <div className="bg-white w-full max-w-md rounded-[2rem] shadow-2xl flex flex-col overflow-hidden border border-slate-100 animate-in zoom-in-95 duration-200">
                        <header className="p-5 border-b flex justify-between items-center bg-indigo-50 shrink-0">
                            <div className="flex items-center gap-3">
                                <div className="p-2 bg-indigo-100 text-indigo-700 rounded-lg"><Icon name="eye" size={14}/></div>
                                <div>
                                    <h3 className="text-sm font-black uppercase tracking-widest text-slate-800 font-sans">Cấu hình Công Bố</h3>
                                    <p className="text-[10px] font-bold text-slate-400 mt-0.5 truncate max-w-[250px]">{exam.title}</p>
                                </div>
                            </div>
                            <button onClick={onClose} className="w-8 h-8 flex items-center justify-center bg-rose-500 text-white hover:bg-rose-600 rounded-full transition-colors shadow-md"><Icon name="x" size={18}/></button>
                        </header>
                        <div className="p-6 space-y-4">
                            <div className="p-4 bg-slate-50 border border-slate-100 rounded-2xl flex items-center justify-between">
                                <div>
                                    <h4 className="text-xs font-black text-slate-800 uppercase tracking-wide">Công bố điểm số</h4>
                                    <p className="text-[10px] font-bold text-slate-400 mt-0.5">Học sinh xem được điểm số sau khi hoàn thành</p>
                                </div>
                                <button 
                                    onClick={() => setPublishGrades(!publishGrades)}
                                    className={`w-12 h-6 rounded-full p-1 transition-all duration-300 ${publishGrades ? 'bg-emerald-600' : 'bg-slate-300'}`}
                                >
                                    <div className={`bg-white w-4 h-4 rounded-full shadow transition-all duration-300 ${publishGrades ? 'translate-x-6' : 'translate-x-0'}`}></div>
                                </button>
                            </div>

                            <div className="p-4 bg-slate-50 border border-slate-100 rounded-2xl flex items-center justify-between">
                                <div>
                                    <h4 className="text-xs font-black text-slate-800 uppercase tracking-wide">Công bố đáp án & lời giải</h4>
                                    <p className="text-[10px] font-bold text-slate-400 mt-0.5">Học sinh được xem lại bài thi và giải thích chi tiết</p>
                                </div>
                                <button 
                                    onClick={() => setPublishAnswers(!publishAnswers)}
                                    className={`w-12 h-6 rounded-full p-1 transition-all duration-300 ${publishAnswers ? 'bg-sky-600' : 'bg-slate-300'}`}
                                >
                                    <div className={`bg-white w-4 h-4 rounded-full shadow transition-all duration-300 ${publishAnswers ? 'translate-x-6' : 'translate-x-0'}`}></div>
                                </button>
                            </div>
                        </div>
                        <footer className="p-4 border-t border-slate-100 bg-slate-50 flex justify-end gap-2 shrink-0">
                            <button onClick={onClose} className="px-5 py-2.5 bg-slate-200 hover:bg-slate-300 text-slate-700 font-black rounded-xl text-[10px] transition-colors uppercase tracking-widest">Hủy</button>
                            <button onClick={handleSave} disabled={isSaving} className="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-black rounded-xl text-[10px] transition-all uppercase tracking-widest shadow-md shadow-indigo-600/10">{isSaving ? 'Đang lưu...' : 'Lưu cấu hình'}</button>
                        </footer>
                    </div>
                </div>
            );
        };

        const AdminExamCard = ({ e, showPublishSettings }) => {
                const [currentTime, setCurrentTime] = useState(Date.now());
                useEffect(() => {
                    const timer = setInterval(() => setCurrentTime(Date.now()), 1000);
                    return () => clearInterval(timer);
                }, []);

                if (e.isDeleted) {
                    return (
                        <div className="bg-white p-3 md:p-4 rounded-xl shadow-sm border border-slate-200 flex flex-col md:flex-row justify-between items-start md:items-center gap-3 transition-all opacity-95 hover:opacity-100 hover:shadow-md hover:border-blue-300 group">
                            <div className="flex flex-col gap-1 flex-1 min-w-0 w-full">
                                <div className="flex items-center gap-2">
                                    <span className="bg-rose-100 text-rose-600 px-1.5 py-0.5 rounded text-[10px] font-black uppercase tracking-widest shrink-0" title="Đã lưu trữ"><Icon name="archive" size={12}/></span>
                                    <b className="text-sm text-slate-700 font-black truncate group-hover:text-blue-700 transition-colors" title={e.title}><del>{e.title}</del></b>
                                </div>
                                <div className="flex flex-wrap items-center gap-2 mt-1 pl-7">
                                    <span className="text-slate-500 text-[11px] font-bold flex items-center gap-1"><Icon name="calendar" size={12}/> {e.createdAt}</span>
                                    <span className="w-1 h-1 bg-slate-300 rounded-full"></span>
                                    <span className="text-slate-500 text-[11px] font-bold flex items-center gap-1"><Icon name="clock" size={12}/> {e.examMode === 'practice' ? 'Tự do' : (e?.duration || 0) + ' phút'}</span>
                                    <span className="w-1 h-1 bg-slate-300 rounded-full"></span>
                                    <span className="text-slate-500 text-[11px] font-bold flex items-center gap-1"><Icon name="list" size={12}/> {(e?.config?.p1||0)+(e?.config?.p2||0)+(e?.config?.p3||0)} Câu</span>
                                </div>
                            </div>
                            
                            <div className="flex items-center gap-1.5 shrink-0 w-full md:w-auto justify-end border-t md:border-t-0 pt-3 md:pt-0 border-slate-100">
                                <button onClick={() => restoreExam(e.id)} className="px-3 py-2 bg-emerald-50 text-emerald-600 border border-emerald-200 rounded-lg hover:bg-emerald-500 hover:text-white transition-all flex items-center gap-1.5 text-[10px] font-black uppercase shadow-sm" title="Khôi phục">
                                    <Icon name="refresh-ccw" size={14}/> <span className="hidden sm:inline">Khôi phục</span>
                                </button>
                                <a href={`?action=download_tex&id=${e.id}`} target="_blank" className="px-3 py-2 bg-blue-50 text-blue-600 border border-blue-200 rounded-lg hover:bg-blue-500 hover:text-white transition-all flex items-center gap-1.5 text-[10px] font-black uppercase shadow-sm" title="Tải LaTeX">
                                    <Icon name="download" size={14}/> <span className="hidden sm:inline">Tải LaTeX</span>
                                </a>
                                <button onClick={() => hardDeleteExam(e.id)} className="px-3 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-all flex items-center gap-1.5 text-[10px] font-black uppercase shadow-sm" title="Xóa vĩnh viễn">
                                    <Icon name="trash-2" size={14}/> <span className="hidden sm:inline">Xóa hẳn</span>
                                </button>
                            </div>
                        </div>
                    );
                }

                const hasStart = !!e.publishStartTime;
                const hasEnd = !!e.publishEndTime;
                const startT = hasStart ? new Date(e.publishStartTime).getTime() : 0;
                const endT = hasEnd ? new Date(e.publishEndTime).getTime() : 0;

                let statusText = 'Chưa mở';
                let statusClass = 'bg-slate-100 text-slate-500 border-slate-200';
                let barColor = 'bg-slate-300';
                let isLiveAllowed = false;

                if (e.isPublished) {
                    if (hasStart && currentTime < startT) {
                        statusText = 'Chờ Mở Đề';
                        statusClass = 'bg-cyan-100 text-cyan-700 border-cyan-200';
                        barColor = 'bg-cyan-400';
                    } else if (hasEnd && currentTime > endT) {
                        statusText = 'Đã Đóng Đề';
                        statusClass = 'bg-rose-100 text-rose-700 border-rose-200';
                        barColor = 'bg-rose-400';
                    } else {
                        statusText = 'Đang mở';
                        statusClass = 'bg-emerald-100 text-emerald-700 border-emerald-200';
                        barColor = 'bg-emerald-400';
                        isLiveAllowed = true;
                    }
                }

                return (
                    <div className="bg-white p-4 rounded-xl shadow-sm border border-slate-200 flex flex-col gap-3 relative overflow-hidden transition-all hover:shadow-md hover:-translate-y-1">
                        <div className={`absolute left-0 top-0 bottom-0 w-1.5 transition-colors duration-500 ${barColor}`}></div>
                        
                        <button onClick={() => showDangerConfirm('Bạn có chắc chắn muốn đưa đề này vào lưu trữ?', () => fetch('?action=delete&id='+e.id).then(load))} className="absolute top-3 right-3 px-2 py-1 bg-amber-50 text-amber-600 rounded-md hover:bg-amber-500 hover:text-white transition-all shadow-sm flex items-center gap-1 z-10 border border-amber-200" title="Chuyển vào lưu trữ">
                            <Icon name="archive" size={10}/>
                            <span className="text-[9px] font-black uppercase tracking-widest">Lưu trữ</span>
                        </button>

                        <div className="flex flex-col gap-2 pl-2 pr-14">
                            <div className="flex justify-between items-start gap-2">
                                <b className="text-sm text-slate-800 font-black line-clamp-2" title={e.title}>{e.title}</b>
                            </div>
                            
                            <div className="flex flex-wrap items-center gap-1.5 mt-1">
                                <span className="bg-slate-50 border border-slate-100 text-slate-500 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-widest">{e.examMode === 'practice' ? 'Tự do' : (e?.duration || 0) + 'p'}</span>
                                <span className="bg-slate-50 border border-slate-100 text-slate-500 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-widest">{(e?.config?.p1||0)+(e?.config?.p2||0)+(e?.config?.p3||0)} Câu</span>
                                <span className="bg-slate-50 border border-slate-100 text-slate-500 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-widest" title="Số lần được làm (0=Tự do/Vô hạn)">{e.maxAttempts == 0 ? 'Thi: Tự do' : `Thi: ${e.maxAttempts}`}</span>
                                {e.examMode !== 'practice' && (e.maxViolations !== undefined ? Number(e.maxViolations) > 0 : true) && (
                                    <span className="bg-rose-50 border border-rose-100 text-rose-500 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-widest">
                                        Lỗi: {e.maxViolations !== undefined ? e.maxViolations : 2}
                                    </span>
                                )}
                                {e.assignedClass && e.assignedClass !== 'all' && (
                                    <span className="bg-emerald-50 border border-emerald-200 text-emerald-700 px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-widest flex items-center gap-1" title="Giao riêng cho lớp">
                                        <Icon name="users" size={10}/> Lớp {e.assignedClass}
                                    </span>
                                )}
                                
                                <div className="flex flex-wrap items-center gap-1.5">
                                    <button 
                                        onClick={() => togglePublish(e.id)} 
                                        className={`px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-widest border transition-all active:scale-95 shadow-sm cursor-pointer hover:opacity-80 flex items-center gap-1 ${statusClass}`} 
                                        title="Nhấn để Bật/Tắt mở đề thi"
                                    >
                                        <Icon name={e.isPublished ? "unlock" : "lock"} size={10}/>
                                        <span>{statusText}</span>
                                    </button>
                                    {e.examMode === 'test' && (
                                        <>
                                            {e.publishGrades && (
                                                <span className="px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-widest border bg-emerald-50 text-emerald-600 border-emerald-100" title="Công bố điểm">
                                                    Đã công bố điểm
                                                </span>
                                            )}
                                            {e.publishAnswers && (
                                                <span className="px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-widest border bg-sky-50 text-sky-600 border-sky-100" title="Công bố đáp án">
                                                    Đã công bố đáp án
                                                </span>
                                            )}
                                        </>
                                    )}
                                    {isLiveAllowed && (
                                        <button onClick={() => setViewLive(e)} className="bg-rose-500 text-white px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-widest border border-rose-600 transition-colors shadow-sm flex items-center gap-1 animate-pulse hover:bg-rose-600" title="Giám sát trực tiếp">
                                            <Icon name="radar" size={10}/> LIVE
                                        </button>
                                    )}
                                </div>
                            </div>

                            {(hasStart || hasEnd) && (
                                <div className="bg-cyan-50/50 border border-cyan-100 p-2 rounded-md mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                                    {hasStart && <div className="text-[9px] text-cyan-700 font-bold flex items-center gap-1"><Icon name="play-circle" size={10}/> Mở: {new Date(e.publishStartTime).toLocaleString('vi-VN')}</div>}
                                    {hasEnd && <div className="text-[9px] text-rose-700 font-bold flex items-center gap-1"><Icon name="stop-circle" size={10}/> Đóng: {new Date(e.publishEndTime).toLocaleString('vi-VN')}</div>}
                                </div>
                            )}
                        </div>
                        
                        <div className="flex flex-wrap gap-1 w-full pl-2 border-t pt-2 border-slate-100 mt-auto">
                            <button onClick={() => setSharingExam(e)} className="px-1.5 py-0.5 bg-teal-50 text-teal-600 border border-teal-100 rounded hover:bg-teal-600 hover:text-white transition-all flex items-center gap-0.5 text-[9px] font-bold" title="Chia sẻ Link & Mã QR">
                                <Icon name="share-2" size={10}/> Link
                            </button>
                            <button onClick={() => setViewHistory(e)} className="px-1.5 py-0.5 bg-blue-50 text-blue-600 rounded hover:bg-blue-600 hover:text-white transition-all flex items-center gap-0.5 text-[9px] font-bold" title="Kết quả">
                                <Icon name="users" size={10}/> KQ
                            </button>
                            <button onClick={() => setEditingScoring(e)} className="px-1.5 py-0.5 bg-amber-50 text-amber-600 rounded hover:bg-amber-500 hover:text-white transition-all flex items-center gap-0.5 text-[9px] font-bold" title="Cài đặt điểm số">
                                <Icon name="star" size={10}/> Điểm
                            </button>
                            <button onClick={() => setUploadingList(e)} className="px-1.5 py-0.5 bg-emerald-50 text-emerald-600 border border-emerald-200/80 hover:bg-emerald-600 hover:text-white transition-all flex items-center gap-0.5 text-[9px] font-bold" title="Danh sách lớp">
                                <Icon name="list" size={10}/> Lớp
                            </button>
                            <button onClick={() => setEditingSchedule(e)} className="px-1.5 py-0.5 bg-cyan-50 text-cyan-600 rounded hover:bg-cyan-600 hover:text-white transition-all flex items-center gap-0.5 text-[9px] font-bold" title="Hẹn giờ xuất bản">
                                <Icon name="calendar" size={10}/> Giờ
                            </button>
                            <button onClick={() => openEditSettings(e)} className="px-1.5 py-0.5 bg-slate-100 text-slate-600 rounded hover:bg-slate-600 hover:text-white transition-all flex items-center gap-0.5 text-[9px] font-bold" title="Cài đặt đề thi">
                                <Icon name="settings" size={10}/> Cài đặt
                            </button>
                            <button onClick={() => openEditTex(e)} className="px-1.5 py-0.5 bg-blue-50 text-blue-600 rounded hover:bg-blue-600 hover:text-white transition-all flex items-center gap-0.5 text-[9px] font-bold" title="Sửa Code LaTeX">
                                <Icon name="code-2" size={10}/> Code
                            </button>
                            {e.examMode === 'test' && (
                                <button onClick={() => showPublishSettings(e)} className="px-1.5 py-0.5 bg-indigo-50 text-indigo-600 border border-indigo-100 rounded hover:bg-indigo-600 hover:text-white transition-all flex items-center gap-0.5 text-[9px] font-black uppercase shadow-sm" title="Cấu hình công bố điểm & đáp án">
                                    <Icon name="eye" size={10}/> Đáp án
                                </button>
                            )}
                        </div>
                    </div>
                );
            };

            if (!isLogin) return (
                <div className="h-screen flex items-center justify-center bg-slate-950 p-4 relative overflow-hidden font-sans selection:bg-blue-500 selection:text-white">
                    <div className="absolute top-[-10%] left-[-10%] w-[60vw] h-[60vw] max-w-[500px] max-h-[500px] bg-blue-600/20 rounded-full blur-[100px] animate-pulse" style={{animationDuration: '10s'}}></div>
                    <div className="absolute bottom-[-10%] right-[-10%] w-[60vw] h-[60vw] max-w-[500px] max-h-[500px] bg-cyan-600/20 rounded-full blur-[100px] animate-pulse" style={{animationDuration: '12s', animationDelay: '2s'}}></div>
                    <div className="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PGRlZnM+PHBhdHRlcm4gaWQ9ImdyaWQiIHdpZHRoPSI0MCIgaGVpZ2h0PSI0MCIgcGF0dGVyblVuaXRzPSJ1c2VyU3BhY2VPblVzZSI+PHBhdGggZD0iTSAwIDEwIEwgNDAgMTAgTSAxMCAwIEwgMTAgNDAiIGZpbGw9Im5vbmUiIHN0cm9rZT0icmdiYSgyNTUsMjU1LDI1NSwwLjAyKSIgc3Ryb2tlLXdpZHRoPSIxIi8+PC9wYXR0ZXJuPjwvZGVmcz48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSJ1cmwoI2dyaWQpIi8+PC9zdmc+')] opacity-20 pointer-events-none"></div>

                    {adminFormMode === 'login' ? (
                        <form className="relative z-10 bg-white/[0.03] p-8 md:p-12 rounded-[2.5rem] shadow-2xl w-full max-w-sm border border-white/10 backdrop-blur-xl text-center animate-in fade-in duration-300" onSubmit={e => {
                            e.preventDefault();
                            const data = {};
                            new FormData(e.target).forEach((v,k) => data[k]=v);
                            fetch('?action=login', {method:'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data)})
                            .then(r=>r.json()).then(d => {
                                if (d.success) {
                                    setAdminUser({ 
                                        username: d.username, 
                                        fullName: d.fullName, 
                                        isOwner: d.is_owner || false, 
                                        role: d.role || 'admin',
                                        class: d.class || '',
                                        studyScores: d.studyScores || null,
                                        studyScoresPublished: d.studyScoresPublished || false,
                                        bonusPoints: d.bonusPoints ?? 0,
                                        bonusPointsHistory: d.bonusPointsHistory ?? []
                                    });
                                    setIsLogin(true);
                                    if (d.role !== 'student') {
                                        load();
                                    }
                                } else {
                                    showAlert(d.message);
                                }
                            });
                        }}>
                            <div className="w-20 h-20 bg-gradient-to-br from-blue-600 to-cyan-500 text-white rounded-[1.5rem] flex items-center justify-center mx-auto mb-6 shadow-lg shadow-blue-500/30"><Icon name="shield-check" size={36}/></div>
                            <h2 className="text-2xl font-black mb-2 text-transparent bg-clip-text bg-gradient-to-r from-white to-slate-400 uppercase tracking-tighter">ĐĂNG NHẬP HỆ THỐNG</h2>
                            <p className="text-slate-400 font-medium text-xs mb-8 uppercase tracking-widest">Dành cho Giáo viên & Học sinh</p>
                            <div className="space-y-4 mb-6">
                                <input name="username" placeholder="Tên đăng nhập / Mã học sinh" className="w-full bg-slate-900/50 border border-white/10 text-white placeholder:text-slate-500 p-4 rounded-2xl font-bold outline-none focus:ring-2 focus:ring-blue-500 transition-all text-center" required />
                                <input name="password" type="password" placeholder="Mật khẩu" className="w-full bg-slate-900/50 border border-white/10 text-white placeholder:text-slate-500 p-4 rounded-2xl font-bold outline-none focus:ring-2 focus:ring-blue-500 transition-all text-center" required />
                            </div>
                            <button className="w-full bg-gradient-to-r from-blue-600 to-cyan-500 text-white p-4 rounded-2xl font-black uppercase tracking-widest shadow-lg shadow-blue-500/20 hover:shadow-blue-500/40 hover:-translate-y-1 transition-all">ĐĂNG NHẬP</button>
                            
                            <button type="button" onClick={() => setAdminFormMode('register')} className="text-[10px] font-bold text-slate-400 hover:text-white transition-colors uppercase tracking-widest block mx-auto mt-4">
                                Đăng ký tài khoản quản trị mới
                            </button>
                        </form>
                    ) : (
                        <form className="relative z-10 bg-white/[0.03] p-8 md:p-12 rounded-[2.5rem] shadow-2xl w-full max-w-sm border border-white/10 backdrop-blur-xl text-center animate-in fade-in duration-300" onSubmit={e => {
                            e.preventDefault();
                            const data = {};
                            new FormData(e.target).forEach((v,k) => data[k]=v);
                            fetch('?action=register', {method:'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data)})
                            .then(r=>r.json()).then(d => {
                                if (d.success) {
                                    showAlert("Đăng ký tài khoản thành công! Hãy đăng nhập.");
                                    setAdminFormMode('login');
                                } else {
                                    showAlert(d.message);
                                }
                            });
                        }}>
                            <div className="w-20 h-20 bg-gradient-to-br from-emerald-600 to-teal-500 text-white rounded-[1.5rem] flex items-center justify-center mx-auto mb-6 shadow-lg shadow-emerald-500/30"><Icon name="user-plus" size={36}/></div>
                            <h2 className="text-2xl font-black mb-2 text-transparent bg-clip-text bg-gradient-to-r from-white to-slate-400 uppercase tracking-tighter">Đăng Ký Mới</h2>
                            <p className="text-slate-400 font-medium text-xs mb-8 uppercase tracking-widest">Tạo tài khoản quản trị</p>
                            <div className="space-y-4 mb-6">
                                <input name="fullName" placeholder="Họ và Tên giáo viên" className="w-full bg-slate-900/50 border border-white/10 text-white placeholder:text-slate-500 p-4 rounded-2xl font-bold outline-none focus:ring-2 focus:ring-emerald-500 transition-all text-center" required />
                                <input name="username" placeholder="Tên đăng nhập" className="w-full bg-slate-900/50 border border-white/10 text-white placeholder:text-slate-500 p-4 rounded-2xl font-bold outline-none focus:ring-2 focus:ring-emerald-500 transition-all text-center" required />
                                <input name="password" type="password" placeholder="Mật khẩu" className="w-full bg-slate-900/50 border border-white/10 text-white placeholder:text-slate-500 p-4 rounded-2xl font-bold outline-none focus:ring-2 focus:ring-emerald-500 transition-all text-center" required />
                            </div>
                            <button className="w-full bg-gradient-to-r from-emerald-600 to-teal-500 text-white p-4 rounded-2xl font-black uppercase tracking-widest shadow-lg shadow-emerald-500/20 hover:shadow-emerald-500/40 hover:-translate-y-1 transition-all">ĐĂNG KÝ</button>
                            
                            <button type="button" onClick={() => setAdminFormMode('login')} className="text-[10px] font-bold text-slate-400 hover:text-white transition-colors uppercase tracking-widest block mx-auto mt-4">
                                Quay lại Đăng nhập
                            </button>
                        </form>
                    )}
                    <CustomDialog dialog={dialog} onClose={closeDialog} />
                </div>
            );

            if (adminUser.role === 'student') {
                return (
                    <StudentDashboard 
                        user={adminUser} 
                        onClose={() => {
                            fetch('?action=logout').then(() => {
                                window.location.href = 'index.php';
                            });
                        }} 
                        showAlert={showAlert} 
                    />
                );
            }

            return (
                <div className="min-h-screen relative pb-20">
                    <div className="absolute top-0 left-0 right-0 h-[220px] bg-gradient-to-b from-blue-900 to-slate-50 pointer-events-none z-0"></div>

                    <div className="p-4 md:p-6 max-w-[1400px] mx-auto relative z-10 pt-4 md:pt-6">
                        <header className="flex flex-col md:flex-row justify-between items-center mb-5 gap-4 bg-white/80 backdrop-blur-xl p-4 md:p-6 rounded-[1.5rem] shadow-sm border border-white">
                            <div className="flex items-center gap-4 text-center md:text-left flex-col md:flex-row w-full md:w-auto">
                                <div className="w-12 h-12 md:w-14 md:h-14 bg-gradient-to-br from-blue-600 to-cyan-500 rounded-[1rem] flex items-center justify-center text-white shadow-lg shadow-blue-500/30 shrink-0 border-2 border-white/50 backdrop-blur-md">
                                    <Icon name="cloud-upload" size={24} strokeWidth={2.5}/>
                                </div>
                                <div>
                                    <h1 className="text-xl lg:text-2xl font-black text-slate-800 uppercase tracking-tighter">Cài Đặt hệ thống</h1>
                                    <p className="text-slate-500 font-bold text-xs mt-0.5 tracking-wider">Xin chào, {adminUser.fullName || adminUser.username}! 👋</p>
                                </div>
                            </div>
                            <div className="flex gap-1.5 md:gap-2 w-full md:w-auto flex-wrap justify-center md:justify-end flex-1 max-w-full md:max-w-4xl">
                                <button onClick={() => setViewMode(viewMode === 'active' ? 'trash' : 'active')} className={`flex-1 md:flex-none justify-center px-2.5 py-1.5 font-black uppercase tracking-wider text-[10px] rounded-xl transition-colors flex items-center gap-1 ${viewMode === 'active' ? 'bg-amber-50 text-amber-600 hover:bg-amber-600 hover:text-white border border-amber-200' : 'bg-slate-100 text-slate-600 hover:bg-slate-600 hover:text-white border border-slate-200'}`}>
                                    {viewMode === 'active' ? 'Lưu Trữ' : 'Quay Lại'}
                                </button>
                                <button onClick={() => setShowGradebook(true)} className="flex-1 md:flex-none justify-center px-3 py-1.5 bg-indigo-50 text-indigo-600 border border-indigo-200 font-black uppercase tracking-wider text-[10px] rounded-xl hover:bg-indigo-600 hover:text-white transition-colors shadow-sm flex items-center justify-center gap-1">
                                    Sổ Điểm
                                </button>
                                {adminUser.isOwner && (
                                    <button onClick={() => setShowDocManager(true)} className="flex-1 md:flex-none justify-center px-2.5 py-1.5 bg-fuchsia-50 text-fuchsia-600 border border-fuchsia-200 font-black uppercase tracking-wider text-[10px] rounded-xl hover:bg-fuchsia-600 hover:text-white transition-colors">
                                        Tài Liệu
                                    </button>
                                )}
                                {adminUser.isOwner && (
                                    <button onClick={() => setShowVideoManager(true)} className="flex-1 md:flex-none justify-center px-2.5 py-1.5 bg-pink-50 text-pink-600 border border-pink-200 font-black uppercase tracking-wider text-[10px] rounded-xl hover:bg-pink-600 hover:text-white transition-colors">
                                        Video
                                    </button>
                                )}
                                {adminUser.isOwner && (
                                    <button onClick={() => setShowNotifManager(true)} className="flex-1 md:flex-none justify-center px-2.5 py-1.5 bg-rose-50 text-rose-600 border border-rose-200 font-black uppercase tracking-wider text-[10px] rounded-xl hover:bg-rose-600 hover:text-white transition-colors">
                                        Thông Báo
                                    </button>
                                )}
                                {adminUser.isOwner && (
                                    <button onClick={() => setShowUserManager(true)} className="flex-1 md:flex-none justify-center px-2.5 py-1.5 bg-cyan-50 text-cyan-600 border border-cyan-200 font-black uppercase tracking-wider text-[10px] rounded-xl hover:bg-cyan-600 hover:text-white transition-colors">
                                        Quản Lý TK
                                    </button>
                                )}
                                <button onClick={() => setShowStudentManager(true)} className="flex-1 md:flex-none justify-center px-2.5 py-1.5 bg-fuchsia-50 text-fuchsia-600 border border-fuchsia-200 font-black uppercase tracking-wider text-[10px] rounded-xl hover:bg-fuchsia-600 hover:text-white transition-colors">
                                    QL Tài Khoản HS
                                </button>

                                <button onClick={() => setShowClassManager(true)} className="flex-1 md:flex-none justify-center px-2.5 py-1.5 bg-indigo-50 text-indigo-600 border border-indigo-200 font-black uppercase tracking-wider text-[10px] rounded-xl hover:bg-indigo-600 hover:text-white transition-colors">
                                    Quản Lý Lớp
                                </button>
                                <a href="index.php" target="_blank" className="flex-1 md:flex-none justify-center px-2.5 py-1.5 bg-blue-50 text-blue-600 border border-blue-200 font-black uppercase tracking-wider text-[10px] rounded-xl hover:bg-blue-600 hover:text-white transition-colors">
                                    Giao Diện HS
                                </a>
                                <button onClick={() => fetch('?action=logout').then(() => window.location.href = 'index.php')} className="flex-1 md:flex-none justify-center px-2.5 py-1.5 bg-rose-50 text-rose-600 border border-rose-200 font-black uppercase tracking-wider text-[10px] rounded-xl hover:bg-rose-600 hover:text-white transition-colors">
                                    Đăng Xuất
                                </button>
                            </div>
                        </header>

                        {viewMode === 'active' ? (
                            <>
                                <div className="bg-white p-5 rounded-[1.5rem] shadow-sm border border-slate-100 mb-5">
                                    <div className="flex items-center gap-2 mb-4">
                                        <div className="w-8 h-8 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center shrink-0"><Icon name="upload" size={16}/></div>
                                        <h2 className="font-black text-slate-800 uppercase text-base tracking-tight">Tải Đề Lên Hệ Thống</h2>
                                    </div>
                                    <form className="flex flex-col gap-3" onSubmit={e => {
                                        e.preventDefault(); setIsUploading(true);
                                        fetch('?action=upload', {method:'POST', body: new FormData(e.target)}).then(r=>r.json()).then(res => {
                                            setIsUploading(false);
                                            if(res.success) { 
                                                load(); 
                                                e.target.reset(); 
                                                setSelectedFileName(""); 
                                                showAlert("Tải lên và bóc tách tự động thành công!"); 
                                            } else showAlert("Lỗi tải lên!");
                                        });
                                    }}>
                                        <div className="flex flex-col lg:flex-row gap-3">
                                            <div className="flex-1 flex flex-col sm:flex-row gap-3">
                                                <input name="title" placeholder="Nhập tên đề thi..." className="bg-slate-50 border border-slate-200 px-3 py-2.5 rounded-xl flex-1 font-bold outline-none focus:ring-2 focus:ring-blue-500 transition-all text-sm" required />
                                                <input name="duration" type="number" placeholder="Thời gian (Phút)" className="bg-slate-50 border border-slate-200 px-3 py-2.5 rounded-xl w-full sm:w-36 font-bold outline-none focus:ring-2 focus:ring-blue-500 text-center transition-all text-sm" required />
                                            </div>
                                            
                                            <div className="flex flex-col sm:flex-row gap-3 w-full lg:w-auto">
                                                <select name="grade" className="bg-slate-50 border border-slate-200 px-3 py-2.5 rounded-xl font-bold text-slate-600 outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer w-full sm:w-32 text-sm" onChange={(e) => {
                                                    const newGrade = e.target.value;
                                                    if (newGrade === 'tot_nghiep') {
                                                        e.target.form.category.value = 'chinh_thuc';
                                                    } else {
                                                        if (e.target.form.category.value === 'chinh_thuc' || e.target.form.category.value === 'thi_thu' || e.target.form.category.value === 'truong_sgd') {
                                                            e.target.form.category.value = 'ghk1';
                                                        }
                                                    }
                                                }}>
                                                    <option value="tot_nghiep">Tốt Nghiệp</option>
                                                    <option value="12">Khối 12</option>
                                                    <option value="11">Khối 11</option>
                                                    <option value="10">Khối 10</option>
                                                </select>
                                                <select name="category" className="bg-slate-50 border border-slate-200 px-3 py-2.5 rounded-xl font-bold text-slate-600 outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer w-full sm:w-48 text-sm">
                                                    {Object.entries(CATEGORY_TREE).map(([parentKey, parentVal]) => (
                                                        <optgroup key={parentKey} label={parentVal.label}>
                                                            {Object.entries(parentVal.items).map(([childKey, childName]) => (
                                                                <option key={childKey} value={childKey}>{childName}</option>
                                                            ))}
                                                        </optgroup>
                                                    ))}
                                                </select>
                                            </div>
                                        </div>

                                        <div className="grid grid-cols-1 sm:grid-cols-12 gap-3 mt-1 items-end">
                                            <div className="col-span-1 sm:col-span-4 lg:col-span-3 relative flex flex-col justify-center bg-slate-50 border border-slate-200 p-1 rounded-xl h-[44px]">
                                                <label className="absolute -top-2 left-2 bg-white px-1 text-[9px] font-black text-blue-500 uppercase tracking-widest">Chế độ thi</label>
                                                <input type="hidden" name="examMode" value={uploadMode} />
                                                <div className="flex w-full gap-1 h-full">
                                                    <button type="button" onClick={() => setUploadMode('practice')} className={`flex-1 text-[9px] font-black uppercase rounded-lg transition-all ${uploadMode === 'practice' ? 'bg-emerald-500 text-white shadow' : 'text-slate-500 hover:bg-slate-200'}`}>Luyện</button>
                                                    <button type="button" onClick={() => setUploadMode('normal')} className={`flex-1 text-[9px] font-black uppercase rounded-lg transition-all ${uploadMode === 'normal' ? 'bg-blue-500 text-white shadow' : 'text-slate-500 hover:bg-slate-200'}`}>Thường</button>
                                                    <button type="button" onClick={() => setUploadMode('test')} className={`flex-1 text-[9px] font-black uppercase rounded-lg transition-all ${uploadMode === 'test' ? 'bg-rose-500 text-white shadow' : 'text-slate-500 hover:bg-slate-200'}`}>K.Tra</button>
                                                </div>
                                            </div>
                                            <div className="relative col-span-1 sm:col-span-2">
                                                <label className="absolute -top-2 left-2 bg-white px-1 text-[9px] font-black text-blue-400 uppercase tracking-widest" title="Số lần tối đa HS được làm (0 là vô hạn)">Lần thi</label>
                                                <input name="maxAttempts" type="number" defaultValue="0" placeholder="0=∞" className="bg-blue-50 border border-blue-200 px-3 py-2.5 rounded-xl w-full font-black text-blue-600 outline-none focus:ring-2 focus:ring-blue-500 text-center transition-all text-sm h-[44px]" required />
                                            </div>
                                            <div className="relative col-span-1 sm:col-span-2">
                                                <label className="absolute -top-2 left-2 bg-white px-1 text-[9px] font-black text-rose-400 uppercase tracking-widest" title="Số lần chuyển tab tối đa (0 = Tắt)">Lỗi Tab</label>
                                                <input name="maxViolations" type="number" defaultValue="2" placeholder="0=Tắt" className="bg-rose-50 border border-rose-200 px-3 py-2.5 rounded-xl w-full font-black text-rose-600 outline-none focus:ring-2 focus:ring-rose-500 text-center transition-all text-sm h-[44px]" required />
                                            </div>

                                            <label className={`col-span-1 sm:col-span-4 lg:col-span-3 h-[44px] relative overflow-hidden bg-slate-50 border-2 border-dashed ${selectedFileName ? 'border-blue-400 bg-blue-50/30' : 'border-slate-300 hover:border-blue-400 hover:bg-slate-100'} px-3 rounded-xl font-bold text-slate-500 flex items-center justify-center gap-2 cursor-pointer transition-all`} title={selectedFileName || 'Chọn File .TEX'}>
                                                <Icon name={selectedFileName ? "check-circle" : "upload-cloud"} className={`shrink-0 ${selectedFileName ? 'text-blue-500' : ''}`} size={16}/> 
                                                <span className={`truncate text-xs ${selectedFileName ? 'text-blue-600' : ''}`}>{selectedFileName ? selectedFileName : 'Kéo thả / Chọn File .TEX'}</span>
                                                <input type="file" name="file" accept=".tex" className="hidden" required onChange={(e) => setSelectedFileName(e.target.files[0]?.name || "")} />
                                            </label>
                                            
                                            <button disabled={isUploading} className="col-span-1 sm:col-span-12 lg:col-span-2 bg-gradient-to-r from-blue-600 to-cyan-500 text-white h-[44px] rounded-xl font-black uppercase tracking-widest disabled:opacity-50 shadow-md shadow-blue-500/30 hover:shadow-blue-500/50 hover:-translate-y-0.5 transition-all flex items-center justify-center gap-2 w-full shrink-0 text-[10px]">
                                                {isUploading ? <Icon name="loader" className="animate-spin" size={14}/> : <Icon name="plus" size={14}/>} {isUploading ? 'ĐANG BÓC TÁCH...' : 'TẠO MỚI'}
                                            </button>
                                        </div>
                                    </form>
                                </div>

                                <div className="bg-white p-4 lg:p-5 rounded-[1.5rem] shadow-sm border border-slate-100 mb-5">
                                    <div className="flex gap-2 mb-4 pb-4 w-full overflow-x-auto custom-scrollbar border-b border-slate-100">
                                        {[
                                            { id: 'tot_nghiep', label: 'TỐT NGHIỆP' },
                                            { id: '12', label: 'KHỐI 12' },
                                            { id: '11', label: 'KHỐI 11' },
                                            { id: '10', label: 'KHỐI 10' }
                                        ].map(g => {
                                            const activeColorClass = 
                                                g.id === 'tot_nghiep' ? 'bg-rose-600 border-rose-600 shadow-rose-600/20' :
                                                g.id === '12' ? 'bg-indigo-600 border-indigo-600 shadow-indigo-600/20' :
                                                g.id === '11' ? 'bg-emerald-600 border-emerald-600 shadow-emerald-600/20' :
                                                'bg-blue-600 border-blue-600 shadow-blue-600/20';
                                            return (
                                                <button 
                                                    key={g.id} 
                                                    onClick={() => handleGradeChange(g.id)}
                                                    className={`px-5 py-2 rounded-xl font-black text-[11px] uppercase transition-all whitespace-nowrap border ${activeGrade === g.id ? `${activeColorClass} text-white` : 'bg-white text-slate-500 border-slate-200 hover:bg-slate-50'}`}
                                                >
                                                    {g.label}
                                                </button>
                                            );
                                        })}
                                    </div>

                                    <div className="flex overflow-x-auto gap-2 mb-3 pb-2 custom-scrollbar">
                                        {availableParentCategories.map(([k, v]) => (
                                            <button 
                                                key={k} 
                                                onClick={() => handleParentChange(k)} 
                                                className={`whitespace-nowrap px-4 py-1.5 rounded-full font-black text-[10px] tracking-widest uppercase transition-all ${activeParentCategory === k ? 'bg-slate-800 text-white' : 'bg-transparent text-slate-400 hover:text-slate-800'}`}
                                            >
                                                {v.label}
                                            </button>
                                        ))}
                                    </div>

                                    <div className="flex overflow-x-auto gap-2 custom-scrollbar pb-1">
                                        {Object.entries(CATEGORY_TREE[activeParentCategory].items).map(([k, v]) => (
                                            <button 
                                                key={k} 
                                                onClick={() => setActiveChildCategory(k)} 
                                                className={`whitespace-nowrap px-3 py-1.5 rounded-lg font-bold text-[10px] tracking-widest uppercase transition-all border ${activeChildCategory === k ? 'bg-blue-50 text-blue-700 border-blue-200' : 'bg-white text-slate-500 border-slate-200 hover:bg-slate-50'}`}
                                            >
                                                {v}
                                            </button>
                                        ))}
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 xl:grid-cols-3 gap-4">
                                    <div className="flex flex-col gap-3">
                                        <h3 className="font-black text-emerald-500 uppercase tracking-widest text-[11px] mb-1 border-b-2 border-emerald-200 pb-2 flex items-center gap-2">
                                            <Icon name="dumbbell" size={14}/> LUYỆN TẬP ({practiceExams.length})
                                        </h3>
                                        {practiceExams.map(e => <AdminExamCard key={e.id} e={e} showPublishSettings={setShowPublishSettings} />)}
                                        {practiceExams.length === 0 && <div className="p-6 text-center text-slate-400 text-xs font-bold border-2 border-dashed border-slate-200 rounded-2xl">Trống</div>}
                                    </div>

                                    <div className="flex flex-col gap-3">
                                        <h3 className="font-black text-blue-500 uppercase tracking-widest text-[11px] mb-1 border-b-2 border-blue-200 pb-2 flex items-center gap-2">
                                            <Icon name="file-text" size={14}/> THÔNG THƯỜNG ({normalExams.length})
                                        </h3>
                                        {normalExams.map(e => <AdminExamCard key={e.id} e={e} showPublishSettings={setShowPublishSettings} />)}
                                        {normalExams.length === 0 && <div className="p-6 text-center text-slate-400 text-xs font-bold border-2 border-dashed border-slate-200 rounded-2xl">Trống</div>}
                                    </div>

                                    <div className="flex flex-col gap-3">
                                        <h3 className="font-black text-rose-500 uppercase tracking-widest text-[11px] mb-1 border-b-2 border-rose-200 pb-2 flex items-center gap-2">
                                            <Icon name="shield-alert" size={14}/> KIỂM TRA ĐIỂM ({testExams.length})
                                        </h3>
                                        {testExams.map(e => <AdminExamCard key={e.id} e={e} showPublishSettings={setShowPublishSettings} />)}
                                        {testExams.length === 0 && <div className="p-6 text-center text-slate-400 text-xs font-bold border-2 border-dashed border-slate-200 rounded-2xl">Trống</div>}
                                    </div>
                                </div>
                            </>
                        ) : (
                            <div className="bg-white p-6 rounded-[1.5rem] shadow-sm border border-slate-100 min-h-[50vh]">
                                <div className="flex items-center gap-3 mb-6 border-b border-slate-100 pb-4">
                                    <div className="w-12 h-12 bg-amber-100 text-amber-600 rounded-2xl flex items-center justify-center shrink-0"><Icon name="archive" size={24}/></div>
                                    <div>
                                        <h2 className="font-black text-slate-800 uppercase text-xl tracking-tight">Khu Vực Lưu Trữ</h2>
                                        <p className="text-xs font-bold text-slate-500 mt-1">Các đề thi đã bị tạm ẩn khỏi hệ thống.</p>
                                    </div>
                                </div>

                                {trashState.step === 1 && (
                                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 animate-in fade-in zoom-in-95 duration-200">
                                        {[
                                            { id: 'tot_nghiep', label: 'TỐT NGHIỆP' },
                                            { id: '12', label: 'KHỐI 12' },
                                            { id: '11', label: 'KHỐI 11' },
                                            { id: '10', label: 'KHỐI 10' }
                                        ].map(g => {
                                            const count = exams.filter(e => e.isDeleted && (e.grade || '12') === g.id).length;
                                            return (
                                                <button key={g.id} onClick={() => setTrashState({ step: 2, grade: g.id })} className="bg-slate-50 border border-slate-200 hover:border-amber-400 hover:bg-amber-50 p-6 rounded-2xl text-center transition-all group shadow-sm hover:shadow-md">
                                                    <div className="w-16 h-16 mx-auto bg-white rounded-full flex items-center justify-center text-amber-500 mb-3 shadow-sm group-hover:scale-110 transition-transform">
                                                        <Icon name="folder" size={28}/>
                                                    </div>
                                                    <h3 className="font-black text-slate-700 uppercase tracking-widest group-hover:text-amber-600">{g.label}</h3>
                                                    <p className="text-xs font-bold text-slate-400 mt-1">{count} file lưu trữ</p>
                                                </button>
                                            );
                                        })}
                                    </div>
                                )}

                                {trashState.step === 2 && (
                                    <div className="animate-in fade-in slide-in-from-right-4 duration-300">
                                        <button onClick={() => setTrashState({ step: 1, grade: null })} className="mb-4 text-[11px] font-black uppercase tracking-widest text-slate-500 hover:text-amber-600 flex items-center gap-1.5 transition-colors bg-slate-100 hover:bg-amber-100 px-4 py-2 rounded-lg">
                                            <Icon name="arrow-left" size={14}/> Quay lại chọn Khối
                                        </button>
                                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                                            {getCategoriesForGrade(trashState.grade).map(cat => {
                                                const count = exams.filter(e => e.isDeleted && (e.grade || '12') === trashState.grade && (e.category || 'khac') === cat.id).length;
                                                return (
                                                    <button key={cat.id} onClick={() => setTrashModal({ isOpen: true, grade: trashState.grade, category: cat.id })} className="bg-white border border-slate-200 hover:border-blue-400 hover:bg-blue-50 p-5 rounded-2xl text-left transition-all shadow-sm flex flex-col justify-between group h-32 relative overflow-hidden">
                                                        <div className="absolute -right-4 -bottom-4 opacity-5 group-hover:opacity-10 transition-opacity">
                                                            <Icon name="tags" size={80}/>
                                                        </div>
                                                        <div>
                                                            <span className="text-[10px] font-black uppercase tracking-widest text-blue-500 mb-1 block">{cat.parent}</span>
                                                            <h3 className="font-black text-slate-700 text-lg group-hover:text-blue-700">{cat.label}</h3>
                                                        </div>
                                                        <span className="inline-block bg-slate-100 text-slate-500 px-3 py-1 rounded-lg text-xs font-bold self-start">{count} đề thi</span>
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}

                        {trashModal.isOpen && (
                            <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
                                <div className="bg-slate-50 w-full max-w-6xl h-[85vh] rounded-[2rem] shadow-2xl flex flex-col overflow-hidden animate-in zoom-in duration-300">
                                    <header className="p-5 border-b flex justify-between items-center bg-white shrink-0">
                                        <div className="flex items-center gap-3">
                                            <div className="p-2 bg-amber-100 text-amber-600 rounded-lg"><Icon name="archive" size={18}/></div>
                                            <div>
                                                <h2 className="text-base font-black uppercase tracking-widest text-slate-800">Danh sách đề lưu trữ</h2>
                                                <p className="text-[10px] font-bold text-slate-400 mt-0.5">Khối {trashModal.grade === 'tot_nghiep' ? 'Tốt Nghiệp' : trashModal.grade} - {getCategoriesForGrade(trashModal.grade).find(c=>c.id === trashModal.category)?.label}</p>
                                            </div>
                                        </div>
                                        <button type="button" onClick={() => setTrashModal({ isOpen: false, grade: null, category: null })} className="w-8 h-8 flex items-center justify-center bg-rose-500 text-white hover:bg-rose-600 rounded-full transition-colors shadow-md"><Icon name="x" size={18}/></button>
                                    </header>
                                    <div className="flex-1 p-4 md:p-6 overflow-y-auto custom-scrollbar">
                                        <div className="flex flex-col gap-2.5">
                                            {exams.filter(e => e.isDeleted && (e.grade || '12') === trashModal.grade && (e.category || 'khac') === trashModal.category).map(e => (
                                                <AdminExamCard key={e.id} e={e} showPublishSettings={setShowPublishSettings} />
                                            ))}
                                            {exams.filter(e => e.isDeleted && (e.grade || '12') === trashModal.grade && (e.category || 'khac') === trashModal.category).length === 0 && (
                                                <div className="py-16 text-center flex flex-col items-center gap-2 text-slate-400 border-2 border-dashed border-slate-200 rounded-2xl bg-white">
                                                    <Icon name="inbox" size={40} className="opacity-30"/>
                                                    <p className="text-sm font-bold uppercase tracking-widest mt-2">Không có dữ liệu</p>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}

                        {aiContext && <AiGeneratorModal exam={aiContext} onClose={() => setAiContext(null)} loadExams={load} showAlert={showAlert} />}

                        {showClassManager && (
                            <ClassManagementModal 
                                onClose={() => setShowClassManager(false)} 
                                loadExams={load} 
                                showAlert={showAlert} 
                                showDangerConfirm={showDangerConfirm}
                                showConfirm={showConfirm}
                                globalClasses={globalClasses}
                                setGlobalClasses={setGlobalClasses}
                                openGradebookForClass={(className) => {
                                    setShowClassManager(false);
                                    setGradebookInitialClass(className);
                                    setShowGradebook(true);
                                }}
                            />
                        )}
                        {showUserManager && (
                            <UserManagerModal 
                                onClose={() => setShowUserManager(false)} 
                                showAlert={showAlert} 
                                showDangerConfirm={showDangerConfirm}
                            />
                        )}


                        {showDocManager && (
                            <DocumentManagerModal
                                onClose={() => setShowDocManager(false)}
                                showAlert={showAlert}
                                showDangerConfirm={showDangerConfirm}
                                showConfirm={showConfirm}
                            />
                        )}
                        {showVideoManager && (
                            <VideoManagerModal
                                onClose={() => setShowVideoManager(false)}
                                showAlert={showAlert}
                                showDangerConfirm={showDangerConfirm}
                                showConfirm={showConfirm}
                            />
                        )}
                        {showNotifManager && (
                            <NotificationManagerModal
                                onClose={() => setShowNotifManager(false)}
                                showAlert={showAlert}
                                showDangerConfirm={showDangerConfirm}
                            />
                        )}

                        {editingTex && (
                            <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4 lg:p-10">
                                <div className="bg-slate-900 w-full max-w-6xl h-full rounded-[2rem] shadow-2xl flex flex-col overflow-hidden border border-slate-700">
                                    <div className="p-4 lg:p-5 border-b border-white/10 flex justify-between items-center bg-slate-950 text-white shrink-0">
                                        <div className="flex items-center gap-3">
                                            <div className="p-2 bg-emerald-500/20 text-emerald-400 rounded-lg"><Icon name="terminal" size={18}/></div>
                                            <div>
                                                <h2 className="text-sm font-black uppercase tracking-widest text-emerald-400">Trình soạn thảo LaTeX</h2>
                                                <p className="text-[10px] font-bold text-slate-500 mt-0.5 uppercase tracking-widest truncate max-w-[200px] md:max-w-md">{editingTex.title}</p>
                                            </div>
                                        </div>
                                        
                                        <div className="flex items-center gap-3">
                                            <button onClick={() => setShowTikzModal(true)} className="bg-indigo-500/20 border border-indigo-500/30 text-indigo-300 hover:bg-indigo-50 hover:text-white px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest flex items-center gap-1.5 transition-colors shadow-sm" title="Vẽ hình TikZ trực tiếp">
                                                <Icon name="pen-tool" size={14}/>
                                                <span className="hidden sm:inline">Vẽ TikZ</span>
                                            </button>
                                            <label className="bg-blue-500/20 border border-blue-500/30 text-blue-300 hover:bg-blue-500 hover:text-white px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest flex items-center gap-1.5 cursor-pointer transition-colors shadow-sm" title="Tải ảnh lên để lấy lệnh chèn vào đề">
                                                {isUploadingImg ? <Icon name="loader" className="animate-spin" size={14}/> : <Icon name="image" size={14}/>}
                                                <span className="hidden sm:inline">{isUploadingImg ? 'Đang tải...' : 'Tải Ảnh'}</span>
                                                <input type="file" accept="image/*" className="hidden" onChange={async (e) => {
                                                    const file = e.target.files[0];
                                                    if(!file) return;
                                                    setIsUploadingImg(true);
                                                    const formData = new FormData();
                                                    formData.append('image', file);
                                                    try {
                                                        const r = await fetch('?action=upload_image', {method: 'POST', body: formData});
                                                        const res = await r.json();
                                                        if(res.success) {
                                                            setImgCodeModal(res.fileName);
                                                        } else showAlert(res.message || "Lỗi tải ảnh!");
                                                    } catch(err) { showAlert("Lỗi kết nối!"); }
                                                    setIsUploadingImg(false);
                                                    e.target.value = null; 
                                                }} />
                                            </label>
                                            <button onClick={() => setEditingTex(null)} className="w-8 h-8 flex items-center justify-center bg-rose-500 text-white hover:bg-rose-600 rounded-lg transition-colors shadow-md"><Icon name="x" size={18}/></button>
                                        </div>
                                    </div>
                                    
                                    <div className="flex-1 p-0 bg-white relative border-y border-slate-200">
                                        <LatexCodeEditor value={texContent} onChange={setTexContent} />
                                    </div>
                                    
                                    <div className="p-4 lg:p-5 border-t border-white/10 flex flex-col-reverse sm:flex-row justify-end gap-3 bg-slate-950 shrink-0">
                                        <button onClick={() => setEditingTex(null)} className="px-6 py-2.5 font-bold text-xs uppercase tracking-widest text-slate-400 hover:text-white transition-colors">HỦY BỎ</button>
                                        <button onClick={saveTex} disabled={isSavingTex} className="bg-emerald-600 text-white px-8 py-2.5 rounded-xl font-black uppercase tracking-widest hover:bg-emerald-500 transition-colors flex items-center justify-center gap-2 text-xs">
                                            {isSavingTex ? <Icon name="loader" className="animate-spin" size={16}/> : <Icon name="save" size={16}/>} 
                                            {isSavingTex ? 'ĐANG LƯU...' : 'LƯU & CẬP NHẬT'}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        )}

                        {imgCodeModal && (
                            <div className="fixed inset-0 z-[60] bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
                                <div className="bg-white w-full max-w-xl rounded-[2rem] shadow-2xl flex flex-col overflow-hidden animate-in zoom-in duration-300">
                                    <header className="p-5 border-b flex justify-between items-center bg-emerald-50 shrink-0">
                                        <div className="flex items-center gap-3">
                                            <div className="p-2 bg-emerald-500 text-white rounded-lg"><Icon name="check-circle" size={18}/></div>
                                            <h2 className="text-base font-black uppercase tracking-widest text-emerald-800">Tải ảnh thành công!</h2>
                                        </div>
                                        <button type="button" onClick={() => setImgCodeModal(null)} className="w-8 h-8 flex items-center justify-center bg-white border border-slate-200 text-slate-600 hover:bg-rose-500 hover:border-rose-500 hover:text-white rounded-full transition-colors shadow-sm"><Icon name="x" size={18}/></button>
                                    </header>
                                    <div className="p-6 space-y-6">
                                        <p className="text-sm font-bold text-slate-600">Ảnh <b className="text-blue-600">{imgCodeModal}</b> đã được lưu. Hãy chọn cấu trúc chèn (tỷ lệ 0.7) phù hợp để copy:</p>
                                        
                                        <div className="space-y-3">
                                            <div className="flex justify-between items-center">
                                                <span className="text-[11px] font-black text-emerald-600 uppercase tracking-widest flex items-center gap-1.5"><Icon name="layout-template" size={14}/> 1. Chữ 1 bên - Ảnh 1 bên (\immini)</span>
                                                <button onClick={() => { 
                                                    const code = `\\immini{\n\t% Nội dung câu hỏi ở đây\n}{\n\t\\includegraphics[width=0.7\\linewidth]{${imgCodeModal}}\n}`;
                                                    setImgCodeModal(null); 
                                                    copyToClipboard(code, 'Đã copy cấu trúc \\immini vào bộ nhớ tạm. Bấm Ctrl+V để dán!'); 
                                                }} className="bg-emerald-50 border border-emerald-200 text-emerald-600 px-4 py-2 rounded-xl text-[10px] font-black uppercase hover:bg-emerald-500 hover:text-white transition-all shadow-sm flex items-center gap-1.5"><Icon name="copy" size={12}/> Copy Code</button>
                                            </div>
                                            <pre className="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm text-slate-700 font-mono overflow-x-auto whitespace-pre">
{`\\immini{
    % Nội dung câu hỏi ở đây
}{
    \\includegraphics[width=0.7\\linewidth]{${imgCodeModal}}
}`}
                                            </pre>
                                        </div>

                                        <div className="space-y-3 pt-4 border-t border-slate-100">
                                            <div className="flex justify-between items-center">
                                                <span className="text-[11px] font-black text-cyan-600 uppercase tracking-widest flex items-center gap-1.5"><Icon name="align-center" size={14}/> 2. Ảnh đứng độc lập ở giữa (\\center)</span>
                                                <button onClick={() => { 
                                                    const code = `\\begin{center}\n\t\\includegraphics[width=0.7\\linewidth]{${imgCodeModal}}\n\\end{center}`;
                                                    setImgCodeModal(null); 
                                                    copyToClipboard(code, 'Đã copy cấu trúc \\center vào bộ nhớ tạm. Bấm Ctrl+V để dán!'); 
                                                }} className="bg-cyan-50 border border-cyan-200 text-cyan-600 px-4 py-2 rounded-xl text-[10px] font-black uppercase hover:bg-cyan-500 hover:text-white transition-all shadow-sm flex items-center gap-1.5"><Icon name="copy" size={12}/> Copy Code</button>
                                            </div>
                                            <pre className="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm text-slate-700 font-mono overflow-x-auto whitespace-pre">
{`\\begin{center}
    \\includegraphics[width=0.7\\linewidth]{${imgCodeModal}}
\\end{center}`}
                                            </pre>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}

                        {uploadingList && (
                            <StudentListModal 
                                exam={uploadingList} 
                                onClose={() => setUploadingList(null)} 
                                loadExams={load} 
                                showAlert={showAlert} 
                                showDangerConfirm={showDangerConfirm} 
                                globalClasses={globalClasses}
                            />
                        )}

                        {editingSettings && (
                            <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
                                <form onSubmit={saveSettings} className="bg-white w-full max-w-2xl rounded-[2rem] shadow-2xl flex flex-col overflow-hidden animate-in zoom-in duration-300">
                                    <header className="p-6 border-b flex justify-between items-center bg-slate-50 shrink-0">
                                        <div className="flex items-center gap-4">
                                            <div className="p-3 bg-blue-100 text-blue-600 rounded-xl shadow-sm"><Icon name="settings-2" size={24}/></div>
                                            <div>
                                                <h2 className="text-xl font-black uppercase tracking-tight text-slate-800">Cài đặt đề thi</h2>
                                                <p className="text-xs font-bold text-slate-400 mt-0.5">Tùy chỉnh thông số và quy chế thi</p>
                                            </div>
                                        </div>
                                        <button type="button" onClick={() => setEditingSettings(null)} className="w-10 h-10 flex items-center justify-center bg-rose-500 text-white hover:bg-rose-600 rounded-full transition-all shadow-md"><Icon name="x" size={20}/></button>
                                    </header>

                                    <div className="p-6 md:p-8 space-y-8 overflow-y-auto custom-scrollbar max-h-[70vh]">
                                        
                                        <div>
                                            <h3 className="text-xs font-black text-slate-400 uppercase tracking-widest mb-4 flex items-center gap-2"><Icon name="info" size={14}/> Thông tin chung</h3>
                                            <div className="space-y-4">
                                                <div>
                                                    <label className="block text-[11px] font-bold text-slate-500 uppercase mb-1.5 ml-1">Tên đề thi</label>
                                                    <input value={editForm.title} onChange={e=>setEditForm({...editForm, title: e.target.value})} className="w-full bg-slate-50 border border-slate-200 p-3.5 rounded-xl font-bold text-slate-700 outline-none focus:ring-2 focus:ring-blue-500 focus:bg-white transition-all text-sm" required />
                                                </div>
                                                <div className="flex flex-col sm:flex-row gap-4">
                                                    <div className="flex-1">
                                                        <label className="block text-[11px] font-bold text-slate-500 uppercase mb-1.5 ml-1">Khối lớp/Cấp</label>
                                                        <select value={editForm.grade} onChange={e => {
                                                            const newGrade = e.target.value;
                                                            const newCat = newGrade === 'tot_nghiep' ? 'chinh_thuc' : 'ghk1';
                                                            setEditForm({...editForm, grade: newGrade, category: newCat});
                                                        }} className="w-full bg-slate-50 border border-slate-200 p-3.5 rounded-xl font-bold text-slate-700 outline-none focus:ring-2 focus:ring-blue-500 focus:bg-white cursor-pointer text-sm transition-all">
                                                            <option value="tot_nghiep">Tốt Nghiệp</option>
                                                            <option value="12">Khối 12</option>
                                                            <option value="11">Khối 11</option>
                                                            <option value="10">Khối 10</option>
                                                        </select>
                                                    </div>
                                                    <div className="flex-[2]">
                                                        <label className="block text-[11px] font-bold text-slate-500 uppercase mb-1.5 ml-1">Danh mục</label>
                                                        <select value={editForm.category} onChange={e=>setEditForm({...editForm, category: e.target.value})} className="w-full bg-slate-50 border border-slate-200 p-3.5 rounded-xl font-bold text-slate-700 outline-none focus:ring-2 focus:ring-blue-500 focus:bg-white cursor-pointer text-sm transition-all">
                                                            {Object.entries(CATEGORY_TREE)
                                                                .filter(([k, v]) => editForm.grade === 'tot_nghiep' ? k === 'on_thi_tn' : k !== 'on_thi_tn')
                                                                .map(([parentKey, parentVal]) => (
                                                                <optgroup key={parentKey} label={parentVal.label}>
                                                                    {Object.entries(parentVal.items).map(([childKey, childName]) => (
                                                                        <option key={childKey} value={childKey}>{childName}</option>
                                                                    ))}
                                                                </optgroup>
                                                            ))}
                                                        </select>
                                                    </div>
                                                </div>
                                                <div>
                                                    <label className="block text-[11px] font-bold text-slate-500 uppercase mb-1.5 ml-1 flex items-center justify-between">
                                                        <span className="flex items-center gap-1.5"><Icon name="users" size={13}/> Giao bài cho đối tượng</span>
                                                        <span className="text-[10px] font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded-md">
                                                            {(!editForm.assignedClass || editForm.assignedClass === 'all') 
                                                                ? `Chung cho cả Khối ${editForm.grade === 'tot_nghiep' ? '12 (Ôn TN)' : editForm.grade}` 
                                                                : `Riêng cho lớp: ${editForm.assignedClass}`}
                                                        </span>
                                                    </label>
                                                    <select 
                                                        value={editForm.assignedClass || ''} 
                                                        onChange={e => setEditForm({...editForm, assignedClass: e.target.value})} 
                                                        className="w-full bg-slate-50 border border-slate-200 p-3.5 rounded-xl font-bold text-slate-700 outline-none focus:ring-2 focus:ring-blue-500 focus:bg-white cursor-pointer text-sm transition-all"
                                                    >
                                                        <option value="">-- Dành chung cho tất cả học sinh Khối {editForm.grade === 'tot_nghiep' ? '12 (Ôn TN)' : editForm.grade} --</option>
                                                        {(globalClasses || []).map(c => c.className).filter(Boolean).sort().map(cName => (
                                                            <option key={cName} value={cName}>Chỉ giao riêng cho lớp: {cName}</option>
                                                        ))}
                                                    </select>
                                                    <p className="text-[10px] text-slate-400 mt-1.5 ml-1 leading-relaxed">
                                                        * Nếu chọn <b>Dành chung</b>: Tất cả các học sinh thuộc khối {editForm.grade === 'tot_nghiep' ? '12' : editForm.grade} sẽ nhìn thấy và được làm bài. Nếu chọn <b>Giao riêng</b> (VD: 12A1), chỉ học sinh thuộc đúng lớp đó mới nhìn thấy.
                                                    </p>
                                                </div>
                                            </div>
                                        </div>

                                        <div className="pt-6 border-t border-slate-100">
                                            <h3 className="text-xs font-black text-slate-400 uppercase tracking-widest mb-4 flex items-center gap-2"><Icon name="shield-alert" size={14}/> Quy chế thi</h3>
                                            
                                            <div className="mb-4">
                                                <label className="block text-[11px] font-black text-blue-500 uppercase tracking-widest mb-2 flex items-center justify-between gap-1.5">
                                                    <span className="flex items-center gap-1.5"><Icon name="settings" size={14}/> Chế độ thi</span>
                                                </label>
                                                <div className="grid grid-cols-3 gap-2">
                                                    <button type="button" onClick={() => setEditForm({...editForm, examMode: 'practice'})} className={`py-3 flex flex-col items-center justify-center gap-1.5 rounded-xl border-2 transition-all ${editForm.examMode === 'practice' ? 'border-emerald-500 bg-emerald-50 text-emerald-700 shadow-sm' : 'border-slate-200 bg-white text-slate-400 hover:border-emerald-300 hover:bg-emerald-50/30'}`}>
                                                        <Icon name="dumbbell" size={20}/>
                                                        <span className="text-[9px] font-black uppercase">Luyện Tập</span>
                                                    </button>
                                                    <button type="button" onClick={() => setEditForm({...editForm, examMode: 'normal'})} className={`py-3 flex flex-col items-center justify-center gap-1.5 rounded-xl border-2 transition-all ${editForm.examMode === 'normal' ? 'border-blue-500 bg-blue-50 text-blue-700 shadow-sm' : 'border-slate-200 bg-white text-slate-400 hover:border-blue-300 hover:bg-blue-50/30'}`}>
                                                        <Icon name="file-text" size={20}/>
                                                        <span className="text-[9px] font-black uppercase">Thông Thường</span>
                                                    </button>
                                                    <button type="button" onClick={() => setEditForm({...editForm, examMode: 'test'})} className={`py-3 flex flex-col items-center justify-center gap-1.5 rounded-xl border-2 transition-all ${editForm.examMode === 'test' ? 'border-rose-500 bg-rose-50 text-rose-700 shadow-sm' : 'border-slate-200 bg-white text-slate-400 hover:border-rose-300 hover:bg-rose-50/30'}`}>
                                                        <Icon name="shield-alert" size={20}/>
                                                        <span className="text-[9px] font-black uppercase">Kiểm Tra</span>
                                                    </button>
                                                </div>
                                            </div>

                                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                                                <div className="col-span-2 bg-emerald-50/50 p-4 rounded-2xl border border-emerald-100">
                                                    <label className="block text-[11px] font-black text-emerald-500 uppercase tracking-widest mb-2 flex items-center justify-between gap-1.5"><span className="flex items-center gap-1.5"><Icon name="clock" size={14}/> Thời gian làm bài (Phút)</span></label>
                                                    <input type="number" value={editForm.duration} onChange={e=>setEditForm({...editForm, duration: e.target.value})} className="w-full bg-white border border-emerald-200 p-3 rounded-xl font-black text-emerald-700 outline-none focus:ring-2 focus:ring-emerald-500 text-center text-lg shadow-sm" required />
                                                </div>
                                                <div className="col-span-1 bg-blue-50/50 p-4 rounded-2xl border border-blue-100">
                                                    <label className="block text-[11px] font-black text-blue-500 uppercase tracking-widest mb-2 flex items-center gap-1.5" title="Số lần tối đa HS được làm (0 là vô hạn)"><Icon name="refresh-cw" size={14}/> Số lần (0=∞)</label>
                                                    <input type="number" value={editForm.maxAttempts} onChange={e=>setEditForm({...editForm, maxAttempts: e.target.value})} className="w-full bg-white border border-blue-200 p-3 rounded-xl font-black text-blue-700 outline-none focus:ring-2 focus:ring-blue-500 text-center text-lg shadow-sm" required />
                                                </div>
                                                <div className="col-span-1 bg-rose-50/50 p-4 rounded-2xl border border-rose-100">
                                                    <label className="block text-[11px] font-black text-rose-500 uppercase tracking-widest mb-2 flex items-center justify-between gap-1.5" title="Giới hạn số lần học sinh được phép thoát cửa sổ làm bài"><span className="flex items-center gap-1.5"><Icon name="alert-triangle" size={14}/> Lỗi (0=Tắt)</span></label>
                                                    <input type="number" value={editForm.maxViolations} onChange={e=>setEditForm({...editForm, maxViolations: e.target.value})} className="w-full bg-white border border-rose-200 p-3 rounded-xl font-black text-rose-700 outline-none focus:ring-2 focus:ring-rose-500 text-center text-lg shadow-sm" required />
                                                </div>
                                            </div>
                                        </div>

                                        <div className="pt-6 border-t border-slate-100">
                                            <h3 className="text-xs font-black text-slate-400 uppercase tracking-widest mb-4 flex items-center gap-2"><Icon name="check-square" size={14}/> Sau khi nộp bài</h3>
                                            <div className="flex items-center justify-between bg-emerald-50/50 p-4 md:p-5 rounded-2xl border border-emerald-100">
                                                <div>
                                                    <p className="text-sm font-black text-emerald-800 uppercase tracking-tight">Cho phép xem đáp án</p>
                                                    <p className="text-[11px] font-medium text-emerald-600 mt-0.5">Học sinh có thể xem lại chi tiết bài làm và lời giải sau khi nộp (Chỉ áp dụng Thông Thường).</p>
                                                </div>
                                                <button type="button" onClick={() => setEditForm({...editForm, allowReview: !editForm.allowReview})} className={`relative inline-flex h-7 w-12 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 shadow-inner ${editForm.allowReview ? 'bg-emerald-500' : 'bg-slate-300'}`}>
                                                    <span className={`pointer-events-none inline-block h-6 w-6 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${editForm.allowReview ? 'translate-x-5' : 'translate-x-0'}`}/>
                                                </button>
                                            </div>
                                        </div>

                                    </div>

                                    <div className="p-6 border-t flex flex-col-reverse sm:flex-row justify-end gap-3 bg-slate-50 shrink-0">
                                        <button type="button" onClick={() => setEditingSettings(null)} className="px-8 py-3 font-bold text-xs uppercase tracking-widest text-slate-500 hover:text-slate-800 hover:bg-slate-200 rounded-xl transition-colors">HỦY BỎ</button>
                                        <button type="submit" disabled={isSavingSettings} className="bg-blue-600 text-white px-10 py-3 rounded-xl font-black uppercase tracking-widest shadow-lg shadow-blue-600/30 hover:bg-blue-500 hover:shadow-blue-600/50 transition-all flex items-center justify-center gap-2 text-xs hover:-translate-y-0.5">
                                            {isSavingSettings ? <Icon name="loader" className="animate-spin" size={16}/> : <Icon name="save" size={16}/>} 
                                            {isSavingSettings ? 'ĐANG LƯU...' : 'LƯU CÀI ĐẶT'}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        )}

                        {editingScoring && <ScoringModal exam={editingScoring} onClose={() => setEditingScoring(null)} loadExams={load} showAlert={showAlert} />}
                        
                        {sharingExam && (() => {
                            const studentLink = `${window.location.origin}${window.location.pathname.replace('admin.php', 'index.php')}?teacher=${encodeURIComponent(adminUser.username)}&exam_id=${sharingExam.id}`;
                            const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=${encodeURIComponent(studentLink)}`;
                            return (
                                <div className="fixed inset-0 z-[60] bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
                                    <div className="bg-white w-full max-w-md rounded-[2rem] shadow-2xl flex flex-col overflow-hidden border border-slate-100 animate-in zoom-in duration-300">
                                        <header className="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50 shrink-0">
                                            <div className="flex items-center gap-3">
                                                <div className="p-2 bg-teal-50 text-teal-600 rounded-lg"><Icon name="share-2" size={18}/></div>
                                                <div>
                                                    <h2 className="text-sm font-black uppercase tracking-widest text-teal-700">Chia sẻ đề thi</h2>
                                                    <p className="text-[10px] font-bold text-slate-400 mt-0.5 uppercase tracking-widest">Đường dẫn và mã QR cho học sinh</p>
                                                </div>
                                            </div>
                                            <button onClick={() => setSharingExam(null)} className="w-8 h-8 flex items-center justify-center bg-slate-200 hover:bg-rose-600 hover:text-white rounded-lg transition-colors"><Icon name="x" size={18}/></button>
                                        </header>
                                        
                                        <div className="p-6 flex flex-col items-center gap-5 text-center">
                                            <b className="text-slate-800 text-sm font-black uppercase tracking-tight">{sharingExam.title}</b>
                                            
                                            <div className="w-full">
                                                <label className="block text-[10px] font-black text-slate-400 uppercase tracking-widest text-left mb-1.5 ml-1">Đường dẫn làm bài</label>
                                                <div className="flex gap-2">
                                                    <input 
                                                        id="share-link-input"
                                                        value={studentLink} 
                                                        readOnly 
                                                        className="flex-1 bg-slate-50 border border-slate-200 text-slate-600 p-3 rounded-xl font-bold outline-none text-xs" 
                                                    />
                                                    <button 
                                                        onClick={() => copyToClipboard(studentLink, "Đã sao chép liên kết vào bộ nhớ tạm!")}
                                                        className="px-4 bg-teal-600 hover:bg-teal-700 text-white rounded-xl font-bold text-xs transition-colors flex items-center gap-1 shrink-0"
                                                    >
                                                        <Icon name="copy" size={14}/> Sao chép
                                                    </button>
                                                </div>
                                            </div>
                                            
                                            <div className="bg-slate-50 p-4 rounded-2xl border border-slate-100 flex flex-col items-center gap-2">
                                                <img 
                                                    src={qrUrl} 
                                                    alt="Mã QR làm bài" 
                                                    className="w-[200px] h-[200px] bg-white rounded-xl shadow-sm border border-slate-100" 
                                                />
                                                <a 
                                                    href={qrUrl} 
                                                    target="_blank" 
                                                    rel="noopener noreferrer"
                                                    className="text-[10px] font-bold text-teal-600 hover:text-teal-800 transition-colors uppercase tracking-widest flex items-center gap-1 mt-1"
                                                >
                                                    <Icon name="download" size={12}/> Tải hình ảnh mã QR
                                                </a>
                                            </div>
                                        </div>
                                        
                                        <footer className="p-4 bg-slate-50 border-t border-slate-100 flex justify-end shrink-0">
                                            <button onClick={() => setSharingExam(null)} className="px-5 py-2.5 bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold rounded-xl text-xs transition-colors uppercase tracking-widest">Đóng</button>
                                        </footer>
                                    </div>
                                </div>
                            );
                        })()}
                        
                        {editingSchedule && <ScheduleModal exam={editingSchedule} onClose={() => setEditingSchedule(null)} loadExams={load} showAlert={showAlert} />}

                        {viewLive && <LiveMonitorModal exam={viewLive} onClose={() => setViewLive(null)} />}
                        {viewHistory && <HistoryModal exam={viewHistory} onClose={() => setViewHistory(null)} showConfirm={showDangerConfirm} showAlert={showAlert} />}
                        
                        {showStudentManager && (
                            <StudentAccountModal 
                                onClose={() => setShowStudentManager(false)} 
                                showAlert={showAlert} 
                                showConfirm={showConfirm} 
                                showDangerConfirm={showDangerConfirm}
                                globalClasses={globalClasses}
                            />
                        )}
                        
                        {showGradebook && (
                            <GradebookModal 
                                onClose={() => {
                                    setShowGradebook(false);
                                    setGradebookInitialClass(null);
                                }} 
                                showAlert={showAlert} 
                                showConfirm={showConfirm} 
                                showDangerConfirm={showDangerConfirm}
                                globalClasses={globalClasses}
                                initialClass={gradebookInitialClass}
                            />
                        )}
                        {showPublishSettings && (
                            <PublishSettingsModal 
                                exam={showPublishSettings}
                                onClose={() => setShowPublishSettings(null)}
                                loadExams={load}
                                showAlert={showAlert}
                            />
                        )}
                        
                        {showTikzModal && (
                            <div className="fixed inset-0 z-[60] bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
                                <div className="bg-slate-900 w-full max-w-2xl rounded-[2rem] shadow-2xl flex flex-col overflow-hidden border border-slate-700 animate-in zoom-in duration-300">
                                    <header className="p-5 border-b border-white/10 flex justify-between items-center bg-slate-950 text-white shrink-0">
                                        <div className="flex items-center gap-3">
                                            <div className="p-2 bg-blue-500/20 text-blue-400 rounded-lg"><Icon name="pen-tool" size={18}/></div>
                                            <div>
                                                <h2 className="text-sm font-black uppercase tracking-widest text-blue-400">Trình vẽ hình TikZ trực tiếp</h2>
                                                <p className="text-[10px] font-bold text-slate-500 mt-0.5 uppercase tracking-widest">Biên dịch mã TikZ sang ảnh PNG</p>
                                            </div>
                                        </div>
                                        <button type="button" onClick={() => setShowTikzModal(false)} className="w-8 h-8 flex items-center justify-center bg-slate-800 text-white hover:bg-rose-600 rounded-lg transition-colors shadow-md"><Icon name="x" size={18}/></button>
                                    </header>
                                    <div className="p-6 space-y-4 flex-1 flex flex-col">
                                        <div className="flex-1 flex flex-col">
                                            <label className="block text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Nhập mã nguồn TikZ (Không cần khai báo thư viện/preamble):</label>
                                            <textarea 
                                                value={tikzCode} 
                                                onChange={(e) => setTikzCode(e.target.value)} 
                                                rows={10}
                                                className="w-full flex-1 bg-slate-950 border border-slate-800 p-4 rounded-2xl font-mono text-xs text-slate-300 outline-none focus:ring-2 focus:ring-blue-500 transition-all resize-none custom-scrollbar min-h-[250px]"
                                                placeholder="\begin{tikzpicture} ... \end{tikzpicture}"
                                            />
                                        </div>
                                        <p className="text-[11px] font-bold text-slate-500 leading-relaxed bg-slate-950/40 p-3.5 rounded-xl border border-slate-850">
                                            💡 **Mẹo:** Bạn chỉ cần nhập môi trường <code>\begin{"{tikzpicture}"} ... \end{"{tikzpicture}"}</code>. Các gói lệnh và thư viện hình học thông dụng (<code>tkz-tab</code>, <code>tkz-euclide</code>, <code>pgfplots</code>,...) đã được tự động liên kết sẵn trên máy chủ.
                                        </p>
                                    </div>
                                    <div className="p-5 border-t border-white/10 flex justify-end gap-3 bg-slate-950 shrink-0">
                                        <button type="button" onClick={() => setShowTikzModal(false)} className="px-6 py-2.5 font-bold text-xs uppercase tracking-widest text-slate-400 hover:text-white transition-colors">HỦY BỎ</button>
                                        <button 
                                            type="button" 
                                            disabled={isCompilingTikz} 
                                            onClick={async () => {
                                                if (!tikzCode.trim()) return showAlert("Vui lòng nhập mã TikZ!");
                                                setIsCompilingTikz(true);
                                                try {
                                                    const r = await fetch('?action=compile_tikz', {
                                                        method: 'POST',
                                                        headers: { 'Content-Type': 'application/json' },
                                                        body: JSON.stringify({ tikz: tikzCode })
                                                    });
                                                    const res = await r.json();
                                                    if (res.success) {
                                                        setImgCodeModal(res.fileName);
                                                        setShowTikzModal(false);
                                                    } else {
                                                        showAlert(res.message || "Lỗi biên dịch TikZ!");
                                                    }
                                                } catch (err) {
                                                    showAlert("Lỗi kết nối máy chủ khi biên dịch TikZ!");
                                                }
                                                setIsCompilingTikz(false);
                                            }} 
                                            className="bg-blue-600 text-white px-8 py-2.5 rounded-xl font-black uppercase tracking-widest hover:bg-blue-500 transition-colors flex items-center justify-center gap-2 text-xs"
                                        >
                                            {isCompilingTikz ? <Icon name="loader" className="animate-spin" size={16}/> : <Icon name="play" size={16}/>} 
                                            {isCompilingTikz ? 'ĐANG BIÊN DỊCH...' : 'VẼ HÌNH'}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        )}
                        <CustomDialog dialog={dialog} onClose={closeDialog} />
                    </div>
                    
                    {/* Phần Footer chuẩn Liquid Glass của T-app theo Ledger */}
                    <div className="absolute bottom-4 left-0 right-0 flex justify-center pointer-events-none">
                        <div className="bg-white/40 backdrop-blur-md border border-white/60 px-6 py-2 rounded-full shadow-sm">
                            <p className="text-slate-500 font-bold text-[10px] uppercase tracking-widest">&copy; Copyright Phạm Minh Tuấn GV Toán TT GDNN - GDTX Quận 12</p>
                        </div>
                    </div>
                </div>
            );
        }
        ReactDOM.createRoot(document.getElementById('root')).render(<App />);
    </script>
    <script>
        (function() {
            function hashCode(str) {
                let hash = 0;
                for (let i = 0; i < str.length; i++) {
                    const char = str.charCodeAt(i);
                    hash = (hash << 5) - hash + char;
                    hash |= 0;
                }
                return hash;
            }
            const sourceCode = document.getElementById('react-app-source').textContent;
            const sourceHash = sourceCode.length + '_' + hashCode(sourceCode);
            const cacheKey = 'react_compiled_admin_' + sourceHash;
            const cachedCode = localStorage.getItem(cacheKey);

            if (cachedCode) {
                const script = document.createElement('script');
                script.textContent = cachedCode;
                document.body.appendChild(script);
            } else {
                const loadingEl = document.createElement('div');
                loadingEl.id = 'babel-loading-overlay';
                loadingEl.style.cssText = 'position:fixed;inset:0;background:#ffffff;display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:99999;font-family:system-ui,-apple-system,sans-serif;color:#1e293b;';
                loadingEl.innerHTML = '<div style="width:40px;height:40px;border:3px solid #cbd5e1;border-top-color:#4f46e5;border-radius:50%;animation:spin 1s linear infinite;"></div><div style="margin-top:16px;font-size:13px;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;">Đang tối ưu hóa hệ thống...</div><style>@keyframes spin{to{transform:rotate(360deg)}}</style>';
                document.body.appendChild(loadingEl);

                const scriptBabel = document.createElement('script');
                scriptBabel.src = "https://cdn.jsdelivr.net/npm/@babel/standalone@7.23.5/babel.min.js";
                scriptBabel.crossOrigin = "anonymous";
                scriptBabel.onload = function() {
                    try {
                        const compiled = Babel.transform(sourceCode, {
                            presets: ['react']
                        }).code;
                        
                        try {
                            for (let i = localStorage.length - 1; i >= 0; i--) {
                                const key = localStorage.key(i);
                                if (key && key.startsWith('react_compiled_admin_')) {
                                    localStorage.removeItem(key);
                                }
                            }
                            localStorage.setItem(cacheKey, compiled);
                        } catch (e) {
                            console.error("Local storage error:", e);
                        }

                        const scriptEl = document.createElement('script');
                        scriptEl.textContent = compiled;
                        document.body.appendChild(scriptEl);
                        const overlay = document.getElementById('babel-loading-overlay');
                        if (overlay) overlay.remove();
                    } catch (err) {
                        console.error("Babel compilation error:", err);
                    }
                };
                document.body.appendChild(scriptBabel);
            }
        })();
    </script>
</body>
</html>