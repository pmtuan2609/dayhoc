<?php
/**
 * TRANG HỌC SINH - TƯƠNG TÁC TRỰC TIẾP
 * FINAL PRO: TỐI ƯU MÀN 14 INCH + HIỂN THỊ TIKZ + XỬ LÝ ARRAY/TABULAR CHUẨN + TÀI LIỆU PDF
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
    
    // Nếu đề thi dành chung cho cả khối (K12/K11/K10)
    if (!empty($student_grade)) {
        if ($exam_grade === 'tot_nghiep' && $student_grade === '12') {
            return true;
        }
        return $student_grade === $exam_grade;
    }
    
    return true;
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


$usersFile = __DIR__ . DIRECTORY_SEPARATOR . 'users_data.json';
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

// Check teacher database context
$teacher = isset($_GET['teacher']) ? trim($_GET['teacher']) : 'thaytuan@admin';
$is_owner = (strtolower($teacher) === 'thaytuan@admin');
$updated = false;
if (is_array($users)) {
    foreach ($users as &$u) {
        if ($u['username'] === 'thaytuan@admin') {
            if (!isset($u['fullName']) || $u['fullName'] !== 'Ths.Phạm Minh Tuấn') {
                $u['fullName'] = 'Ths.Phạm Minh Tuấn';
                $updated = true;
            }
        }
        if (strtolower($u['username']) === strtolower($teacher)) {
            $is_owner = !empty($u['is_owner']);
        }
    }
}
if ($updated) {
    safe_file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
}

// Suffix for other admins
$suffix = ($is_owner) ? '' : '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $teacher);

$dataFile = __DIR__ . DIRECTORY_SEPARATOR . 'exams_data' . $suffix . '.json';
$historyFile = __DIR__ . DIRECTORY_SEPARATOR . 'history_data' . $suffix . '.json';
$liveFile = __DIR__ . DIRECTORY_SEPARATOR . 'live_data' . $suffix . '.json';
$docFile = __DIR__ . DIRECTORY_SEPARATOR . 'documents_data' . $suffix . '.json';
$videoFile = __DIR__ . DIRECTORY_SEPARATOR . 'videos_data' . $suffix . '.json';

if (!file_exists($docFile)) { safe_file_put_contents($docFile, json_encode(array())); }
if (!file_exists($videoFile)) { safe_file_put_contents($videoFile, json_encode(array())); }
if (!file_exists($dataFile)) { safe_file_put_contents($dataFile, json_encode(array())); }
if (!file_exists($historyFile)) { safe_file_put_contents($historyFile, json_encode(array())); }
if (!file_exists($liveFile)) { safe_file_put_contents($liveFile, json_encode(array())); }

if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    $action = $_GET['action'];

    if ($action === 'get_teachers') {
        $users_list = [];
        if (is_array($users)) {
            foreach ($users as $u) {
                $users_list[] = [
                    'username' => $u['username'],
                    'fullName' => isset($u['fullName']) ? $u['fullName'] : $u['username'],
                    'is_owner' => !empty($u['is_owner'])
                ];
            }
        }
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($users_list);
        exit;
    }

    if ($action === 'list') { 
        $data = json_decode(safe_file_get_contents($dataFile), true);
        if (!is_array($data)) $data = [];
        
        $publishedData = [];
        $now = time();
        foreach ($data as $exam) {
            if (!empty($exam['isDeleted'])) continue;
            if (isset($exam['isPublished']) && $exam['isPublished'] === true) {
                $isValidTime = true;
                if (!empty($exam['publishStartTime']) && $now < strtotime($exam['publishStartTime'])) $isValidTime = false;
                if (!empty($exam['publishEndTime']) && $now > strtotime($exam['publishEndTime'])) $isValidTime = false;

                if ($isValidTime) {
                    $publishedData[] = $exam;
                }
            }
        }
        while (ob_get_level() > 0) ob_end_clean();
        echo json_encode($publishedData); 
        exit; 
    }

    if ($action === 'list_documents') { 
        $docs = json_decode(safe_file_get_contents($docFile), true);
        if (!is_array($docs)) $docs = [];
        
        $publishedData = [];
        foreach ($docs as $d) {
            if (isset($d['isPublished']) && $d['isPublished'] === true) {
                $publishedData[] = $d;
            }
        }
        while (ob_get_level() > 0) ob_end_clean();
        echo json_encode($publishedData); 
        exit; 
    }

    if ($action === 'list_videos') { 
        $vids = json_decode(safe_file_get_contents($videoFile), true);
        if (!is_array($vids)) $vids = [];
        
        $publishedData = [];
        foreach ($vids as $v) {
            if (isset($v['isPublished']) && $v['isPublished'] === true) {
                $publishedData[] = $v;
            }
        }
        while (ob_get_level() > 0) ob_end_clean();
        echo json_encode($publishedData); 
        exit; 
    }
    if ($action === 'list_notifications') {
        $notifFile = __DIR__ . DIRECTORY_SEPARATOR . 'notifications_data.json';
        $notifs = json_decode(safe_file_get_contents($notifFile), true);
        if (!is_array($notifs)) $notifs = [];
        
        $publishedData = [];
        foreach ($notifs as $n) {
            if (isset($n['isPublished']) && $n['isPublished'] === true) {
                $publishedData[] = $n;
            }
        }
        while (ob_get_level() > 0) ob_end_clean();
        echo json_encode($publishedData);
        exit;
    }

    if ($action === 'verify_student_exam_permission') {
        $examId = $_GET['examId'] ?? '';
        $name = trim(mb_strtoupper($_GET['name'] ?? '', 'UTF-8'));
        $class = trim(mb_strtoupper($_GET['class'] ?? '', 'UTF-8'));
        $enteredPass = trim($_GET['password'] ?? '');

        $teacher = $_GET['teacher'] ?? '';
        $t_suffix = (empty($teacher) || strtolower($teacher) === 'thaytuan@admin') ? '' : '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $teacher);
        $examsFile = __DIR__ . DIRECTORY_SEPARATOR . 'exams_data' . $t_suffix . '.json';
        $exams = file_exists($examsFile) ? json_decode(safe_file_get_contents($examsFile), true) : [];
        if (!is_array($exams)) $exams = [];
        
        $exam = null;
        foreach ($exams as $e) {
            if ($e['id'] === $examId) {
                $exam = $e;
                break;
            }
        }
        
        if (!$exam || !empty($exam['isDeleted']) || empty($exam['isPublished']) || $exam['isPublished'] !== true) {
            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Bài kiểm tra không tồn tại, chưa công bố hoặc đã được lưu trữ!']);
            exit;
        }

        // Kiểm tra đối tượng được giao bài: Khối lớp hoặc Lớp cụ thể
        if (!exam_matches_student($exam, $class)) {
            $assignedClass = trim($exam['assignedClass'] ?? '');
            $msg = 'Bài kiểm tra này không dành cho lớp của bạn.';
            if (!empty($assignedClass) && $assignedClass !== 'all') {
                $msg = 'Bài kiểm tra này chỉ dành riêng cho học sinh lớp ' . $assignedClass . '.';
            } else {
                $gradeName = ($exam['grade'] ?? '12') === 'tot_nghiep' ? '12' : ($exam['grade'] ?? '12');
                $msg = 'Bài kiểm tra này chỉ dành riêng cho học sinh Khối ' . $gradeName . '.';
            }
            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }

        if (empty($exam['requireLoginList'])) {
            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success' => true]);
            exit;
        }

        $studentsFile = __DIR__ . DIRECTORY_SEPARATOR . 'students_accounts.json';
        $students = file_exists($studentsFile) ? json_decode(safe_file_get_contents($studentsFile), true) : [];
        if (!is_array($students)) $students = [];

        $accountMatched = null;
        foreach ($students as $s) {
            if (clean_compare_name($s['fullName'] ?? '') === clean_compare_name($name) && clean_compare_name($s['class'] ?? '') === clean_compare_name($class)) {
                $accountMatched = $s;
                break;
            }
        }

        $isPermitted = false;
        
        $allowedList = $exam['allowedStudents'] ?? [];
        foreach ($allowedList as $s) {
            $sName = ($s['lastName'] ?? '') . ' ' . ($s['firstName'] ?? '');
            if (clean_compare_name($sName) === clean_compare_name($name) && clean_compare_name($s['class'] ?? '') === clean_compare_name($class)) {
                $isPermitted = true;
                if (!empty($s['password'])) {
                    if ($enteredPass !== trim($s['password'])) {
                        while (ob_get_level() > 0) ob_end_clean();
                        echo json_encode(['success' => false, 'message' => 'Mật khẩu học sinh không chính xác!']);
                        exit;
                    }
                }
                break;
            }
        }

        if (!$isPermitted && $accountMatched) {
            $selectedClasses = $exam['selectedClasses'] ?? [];
            foreach ($selectedClasses as $c) {
                if (clean_compare_name($c['className'] ?? '') === clean_compare_name($class)) {
                    $isPermitted = true;
                    if ($enteredPass !== trim($accountMatched['password'])) {
                        while (ob_get_level() > 0) ob_end_clean();
                        echo json_encode(['success' => false, 'message' => 'Mật khẩu học sinh không chính xác!']);
                        exit;
                    }
                    break;
                }
            }
        }

        if (!$isPermitted) {
            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Bạn không có quyền truy cập làm bài tập/bài kiểm tra này.']);
            exit;
        }

        while (ob_get_level() > 0) ob_end_clean();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'check_attempts') {
        $examId = $_GET['examId'];
        $name = trim(mb_strtoupper($_GET['name'], 'UTF-8'));
        $class = trim(mb_strtoupper($_GET['class'], 'UTF-8'));
        $history = json_decode(safe_file_get_contents($historyFile), true) ?: [];
        $count = 0;
        foreach($history as $h) {
            if((string)$h['examId'] === (string)$examId) {
                if (clean_compare_name($h['name']) === clean_compare_name($name) && clean_compare_name($h['class']) === clean_compare_name($class)) $count++;
            }
        }
        while (ob_get_level() > 0) ob_end_clean();
        echo json_encode(['count' => $count]); exit;
    }

    if ($action === 'sync_live' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if ($data) {
            $liveSessionsDir = getLiveSessionsDir();
            
            $sessionId = md5($data['examId'] . '_' . clean_compare_name($data['name']) . '_' . clean_compare_name($data['class']));
            $data['lastPing'] = time();
            
            $sessionFile = $liveSessionsDir . DIRECTORY_SEPARATOR . 'live_session_' . $sessionId . '.json';
            safe_file_put_contents($sessionFile, json_encode($data));
            
            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success' => true]);
        }
        exit;
    }

    if ($action === 'submit_attempt' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $rawInput = file_get_contents('php://input');
        $attempt = json_decode($rawInput, true);
        
        if ($attempt) {
            $history = json_decode(safe_file_get_contents($historyFile), true);
            if (!is_array($history)) $history = [];
            
            if(!isset($attempt['endTime'])) $attempt['endTime'] = time() * 1000;
            
            $attempt['name'] = trim(mb_strtoupper($attempt['name'], 'UTF-8'));
            $attempt['class'] = trim(mb_strtoupper($attempt['class'], 'UTF-8'));
            
            if (isset($attempt['clientScore'])) {
                $attempt['score'] = $attempt['clientScore'];
            }

            unset($attempt['correctAnswers']);
            unset($attempt['clientScore']);
            
            $history[] = $attempt;
            safe_file_put_contents($historyFile, json_encode($history));

            // Xóa file giám sát trực tiếp khi học sinh đã nộp bài
            $sessionId = md5($attempt['examId'] . '_' . clean_compare_name($attempt['name']) . '_' . clean_compare_name($attempt['class']));
            $liveSessionsDir = getLiveSessionsDir();
            $sessionFile = $liveSessionsDir . DIRECTORY_SEPARATOR . 'live_session_' . $sessionId . '.json';
            if (file_exists($sessionFile)) {
                @unlink($sessionFile);
            }
            
            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success' => true]);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Học tập trực tuyến - tMath</title>
    
    <!-- Hệ thống phát hiện & hiển thị lỗi JS trực tiếp trên màn hình -->
    <script>
        window.onerror = function(message, source, lineno, colno, error) {
            // Ignore generic external script errors, adblocker errors, or hosting injection errors
            if (message === 'Script error.' && !source) {
                return false; 
            }
            if (source && !source.includes('index.php') && !source.includes('localhost')) {
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

    <link rel="stylesheet" type="text/css" href="https://tikzjax.com/v1/fonts.css">
    <script src="https://tikzjax.com/v1/tikzjax.js"></script>

    <!-- Phông chữ Toán học chuẩn sách giáo khoa -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@1,500;1,600;1,700&family=Playfair+Display:ital,wght@1,600;1,700&display=swap" rel="stylesheet">
    <style>
        .font-math {
            font-family: 'Lora', 'Playfair Display', Georgia, serif;
            font-style: italic;
        }
    </style>

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
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;700;800;900&display=swap');
        html, body, #root { background-color: #fafaf9 !important; background: radial-gradient(circle at 50% 120%, #f5f3ff 0%, #fafaf9 100%) !important; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; color: #1e293b; overflow-x: hidden; }
        
        @keyframes alternate-hot {
            0%, 40% { opacity: 1; transform: scale(1); z-index: 10; visibility: visible; }
            45%, 95% { opacity: 0; transform: scale(0.95); z-index: 0; visibility: hidden; }
            100% { opacity: 1; transform: scale(1); z-index: 10; visibility: visible; }
        }
        @keyframes alternate-grade {
            0%, 40% { opacity: 0; transform: scale(0.95); z-index: 0; visibility: hidden; }
            45%, 95% { opacity: 1; transform: scale(1); z-index: 10; visibility: visible; }
            100% { opacity: 0; transform: scale(0.95); z-index: 0; visibility: hidden; }
        }
        .anim-alternate-hot {
            animation: alternate-hot 5s infinite ease-in-out;
        }
        .anim-alternate-grade {
            animation: alternate-grade 5s infinite ease-in-out;
        }

        .custom-scrollbar::-webkit-scrollbar { width: 5px; height: 5px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        @media (min-width: 1024px) {
            .custom-scrollbar::-webkit-scrollbar { width: 8px; height: 8px; }
        }
        
        .tex2jax_process { line-height: 2.2; font-size: 16px; overflow: visible !important; padding: 4px 0; }
        mjx-container { overflow-x: auto !important; overflow-y: hidden !important; max-width: 100%; }
        mjx-math { padding-top: 0.4em; padding-bottom: 0.4em; }
        
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
        
        .tikz-auto-render {
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 15px 0;
            width: 100%;
            overflow: hidden;
        }
        
        .tikz-auto-render svg, .tex2jax_process img { 
            max-width: 100% !important; 
            height: auto; 
            max-height: 250px; 
            object-fit: contain; 
            display: block; 
            margin: 0 auto; 
            border-radius: 12px;
        }

        @media (min-width: 768px) {
            .tikz-auto-render svg, .tex2jax_process img {
                max-width: 60% !important; 
                max-height: 320px; 
            }
            
            .flex-row > .shrink-0 img, 
            .flex-row > .shrink-0 svg,
            .flex-row > .shrink-0 .tikz-auto-render svg {
                max-width: 100% !important; 
                max-height: 280px;
            }
        }
        
        .shape-circle { border-radius: 9999px; }
        .shape-square { border-radius: 1.5rem; }

        .q-card { background: white; border-radius: 24px; padding: 20px; border: 2px solid transparent; box-shadow: 0 10px 30px rgba(0,0,0,0.03); transition: all 0.3s; margin-bottom: 25px; overflow: visible !important; }
        @media (min-width: 768px) { .q-card { padding: 30px; } }
        @media (min-width: 1024px) { .q-card { padding: 35px; } }
        
        .q-card button, .q-card .space-y-3 > div { overflow: visible !important; }
        
        .q-card.answered { border-color: #10b981; background: #f0fdf4; box-shadow: 0 10px 30px rgba(16, 185, 129, 0.08); }
        .nav-btn { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12px; border: 2px solid #e2e8f0; color: #64748b; cursor: pointer; transition: all 0.2s;}
        @media (min-width: 768px) { .nav-btn { width: 40px; height: 40px; font-size: 13px; border-radius: 12px; } }
        
        .nav-btn.done { background: #10b981; border-color: #10b981; color: white; }
        .nav-btn:hover { border-color: #10b981; color: #10b981;}
        .nav-btn.done:hover { color: white; opacity: 0.9; }

        @keyframes shimmer { 100% { transform: translateX(100%); } }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }
        .animate-shake { animation: shake 0.3s ease-in-out; }
        
        /* Hiệu ứng to lên nhỏ xuống cho nhãn MỚI */
        @keyframes pop-badge {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.15); }
        }
        .animate-pop-badge { animation: pop-badge 1.2s ease-in-out infinite; }
    </style>
</head>
<body style="background-color: #ffffff !important; background: #ffffff !important;">
    <div id="root"></div>
    <script>
        window.ADMIN_SESSION = <?php echo json_encode(array(
            'loggedIn' => !empty($_SESSION['admin_logged_in']),
            'username' => isset($_SESSION['admin_username']) ? $_SESSION['admin_username'] : '',
            'fullName' => isset($_SESSION['admin_fullname']) ? $_SESSION['admin_fullname'] : '',
            'isOwner' => isset($_SESSION['is_owner']) ? $_SESSION['is_owner'] : false
        )); ?>;
    </script>
    <script id="react-app-source" type="text/plain">
        const { useState, useEffect, useRef } = React;

        const CATEGORY_TREE = {
            'on_giua_ki': {
                label: 'Ôn tập giữa kì',
                items: { 'ghk1': 'Ôn Giữa HK1', 'ghk2': 'Ôn Giữa HK2' }
            },
            'on_hoc_ki': {
                label: 'Ôn tập học kì',
                items: { 'hk1': 'Ôn HK1', 'hk2': 'Ôn HK2' }
            },
            'on_thi_tn': {
                label: 'Ôn thi TN',
                items: { 'thi_thu': 'Đề thi thử', 'chinh_thuc': 'Đề chính thức', 'truong_sgd': 'Đề Sở / Trường' }
            }
        };

        const GRADIENTS = [
            'from-emerald-400 to-teal-500',
            'from-blue-400 to-indigo-500',
            'from-orange-400 to-rose-500',
            'from-purple-400 to-fuchsia-500'
        ];

        const Icon = ({ name, size = 18, strokeWidth = 2, className = "" }) => {
            const ref = useRef(null);
            useEffect(() => { if (window.lucide) window.lucide.createIcons({ root: ref.current, attrs: { width: size, height: size, "stroke-width": strokeWidth } }); }, [name, size, strokeWidth]);
            return <span ref={ref} className={`inline-flex items-center justify-center ${className}`}><i data-lucide={name}></i></span>;
        };

        const Timer = ({ mins, onTimeUp, mode }) => {
            const [sec, setSec] = useState((mins || 0) * 60);
            useEffect(() => {
                if (mode === 'practice' || mins <= 0) return; 
                const t = setInterval(() => setSec(s => { 
                    if(s <= 1){
                        clearInterval(t); 
                        onTimeUp(); 
                        return 0;
                    } 
                    return s - 1; 
                }), 1000);
                return () => clearInterval(t);
            }, [mins, mode, onTimeUp]);

            if (mode === 'practice') return (
                <div className="flex items-center gap-2 px-4 py-2 rounded-xl bg-emerald-50 text-emerald-600 font-black text-sm border border-emerald-100 shadow-sm"><Icon name="infinity" size={16}/> KHÔNG GIỚI HẠN</div>
            );

            return (
                <div className={`flex items-center gap-1.5 md:gap-2 px-3 md:px-5 py-2 md:py-2.5 rounded-xl md:rounded-2xl font-mono text-sm md:text-xl font-black shadow-inner ${sec<300 ? 'bg-rose-500 text-white animate-pulse' : 'bg-slate-100 text-slate-700'}`}>
                    <Icon name="timer" size={22}/> {Math.floor(sec/60).toString().padStart(2,'0')}:{(sec%60).toString().padStart(2,'0')}
                </div>
            );
        };

        const SafeHtml = React.memo(({ html, className }) => {
            const ref = useRef(null);
            useEffect(() => {
                if (!ref.current) return;
                
                let processedHtml = html || '';

                // Sửa lỗi hiển thị MathJax do thừa dấu gạch chéo ngược (double backslash)
                processedHtml = processedHtml.replace(/\\\\(begin|end)\{(array|tabular|matrix|bmatrix|pmatrix|cases|align|aligned)\}/g, '\\$1{$2}');

                processedHtml = processedHtml.replace(/\$\$\$\$/g, () => '$$');

                processedHtml = processedHtml.replace(/\\begin\{table\}(?:\[.*?\])?/g, '');
                processedHtml = processedHtml.replace(/\\end\{table\}/g, '');

                processedHtml = processedHtml.replace(/(?:<div[^>]*>|<\/div>|\$|<br\s*\/?>|\s)*\\begin\{tabular\}\s*\{([^{}]*)\}([\s\S]*?)\\end\{tabular\}(?:<div[^>]*>|<\/div>|\$|<br\s*\/?>|\s)*/g, (match, align, content) => {
                    let cleanContent = content.replace(/<br\s*\/?>/gi, '\\\\');
                    cleanContent = cleanContent.replace(/\\toprule|\\midrule|\\bottomrule/g, '\\hline');
                    return `<div class="overflow-x-auto flex justify-center w-full my-5 table-wrapper"><div class="bg-white p-3 md:p-5 rounded-xl border border-slate-200 shadow-sm min-w-max text-base text-slate-800">$$ \\begin{array}{${align}}${cleanContent}\\end{array} $$</div></div>`;
                });
                
                processedHtml = processedHtml.replace(/\\begin\{tikzpicture\}([\s\S]*?)\\end\{tikzpicture\}/g, (match) => {
                    return `<div class="tikz-auto-render" data-tikz="${encodeURIComponent(match)}"></div>`;
                });
                
                processedHtml = processedHtml.replace(/<script\b[^>]*type="text\/tikz"[^>]*>([\s\S]*?)<\/script>/gi, (match, p1) => {
                    let content = p1.trim();
                    if(!content.includes('\\begin{tikzpicture}')) {
                        content = '\\begin{tikzpicture}\n' + content + '\n\\end{tikzpicture}';
                    }
                    return `<div class="tikz-auto-render" data-tikz="${encodeURIComponent(content)}"></div>`;
                });

                ref.current.innerHTML = processedHtml;

                const renderTikz = () => {
                    const tikzElements = ref.current.querySelectorAll('.tikz-auto-render');
                    if (tikzElements.length > 0) {
                        let hasNew = false;
                        tikzElements.forEach(el => {
                            if (!el.hasAttribute('data-rendered')) {
                                const code = decodeURIComponent(el.getAttribute('data-tikz') || '');
                                if (code) {
                                    el.innerHTML = ''; 
                                    const script = document.createElement('script');
                                    script.type = 'text/tikz';
                                    script.textContent = code;
                                    el.appendChild(script);
                                    el.setAttribute('data-rendered', 'true');
                                    hasNew = true;
                                }
                            }
                        });
                        
                        if (hasNew) {
                            setTimeout(() => {
                                const event = new Event('DOMContentLoaded', { bubbles: true, cancelable: true });
                                document.dispatchEvent(event);
                            }, 50);
                        }
                    }
                };

                if (window.MathJax && window.MathJax.typesetPromise) {
                    window.MathJax.typesetPromise([ref.current]).then(() => {
                        renderTikz();
                    }).catch(e => {
                        console.error("Lỗi MathJax:", e);
                        renderTikz();
                    });
                } else {
                    renderTikz();
                }

            }, [html]);
            return <div ref={ref} className={`tex2jax_process overflow-visible w-full ${className}`}></div>;
        }, (prev, next) => prev.html === next.html);

        const shuffleArray = (array) => {
            const newArr = [...array];
            for (let i = newArr.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [newArr[i], newArr[j]] = [newArr[j], newArr[i]];
            }
            return newArr;
        };

        const SquareRootX = ({ className = "w-4 h-4 text-indigo-600" }) => (
            <svg className={`inline-block shrink-0 align-middle ${className}`} viewBox="0 0 32 32" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                <path d="M3 18 L7 24 L13 8 L29 8" />
                <text x="15" y="22" fontSize="14" fontFamily="serif" fontStyle="italic" fontWeight="700" fill="currentColor" stroke="none">x</text>
            </svg>
        );

        const CustomDialog = ({ dialog, onClose }) => {
            if (!dialog.isOpen) return null;
            return (
                <div className="fixed inset-0 z-[100] flex items-center justify-center p-4">
                    <div className="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onClick={dialog.type === 'alert' ? onClose : undefined}></div>
                    <div className="relative bg-white rounded-[2rem] shadow-2xl w-full max-w-sm overflow-hidden animate-in zoom-in-95 duration-200 border-2 border-white/20">
                        <div className="p-6 text-center">
                            <div className={`w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-5 shadow-xl ${dialog.type === 'confirm' ? 'bg-gradient-to-br from-amber-400 to-orange-500 text-white animate-bounce' : 'bg-gradient-to-br from-blue-400 to-indigo-500 text-white'}`}>
                                <Icon name={dialog.type === 'confirm' ? "help-circle" : "info"} size={40} strokeWidth={2.5} />
                            </div>
                            <h3 className={`text-xl font-black mb-2 ${dialog.type === 'confirm' ? 'text-orange-600' : 'text-slate-800'}`}>{dialog.type === 'confirm' ? 'XÁC NHẬN NỘP BÀI' : 'THÔNG BÁO'}</h3>
                            <p className="text-[15px] font-bold text-slate-600 leading-relaxed whitespace-pre-wrap">{dialog.message}</p>
                        </div>
                        <div className="p-4 bg-slate-50 flex gap-3 border-t border-slate-100">
                            {dialog.type === 'confirm' && (
                                <button onClick={onClose} className="flex-1 py-3.5 rounded-xl font-bold text-sm text-slate-500 bg-white border border-slate-200 hover:bg-slate-100 transition-colors shadow-sm">Chưa Nộp</button>
                            )}
                            <button onClick={() => { dialog.onConfirm(); onClose(); }} className={`flex-1 py-3.5 rounded-xl font-black text-sm text-white shadow-lg transition-transform hover:-translate-y-1 ${dialog.type === 'confirm' ? 'bg-gradient-to-r from-orange-500 to-amber-500 shadow-orange-500/40 hover:from-orange-600 hover:to-amber-600' : 'bg-gradient-to-r from-blue-500 to-indigo-500 shadow-blue-500/30 hover:from-blue-600 hover:to-indigo-600'}`}>
                                {dialog.type === 'confirm' ? 'Nộp Ngay' : 'Đã Hiểu'}
                            </button>
                        </div>
                    </div>
                </div>
            );
        };

        const App = () => {
            const urlParams = new URLSearchParams(window.location.search);
            const teacherParam = urlParams.get('teacher') || '';
            const teacherQuery = teacherParam ? '&teacher=' + encodeURIComponent(teacherParam) : '';

            const [teachers, setTeachers] = useState([]);
            const [currentTeacher, setCurrentTeacher] = useState('thaytuan@admin');
            const [adminFormMode, setAdminFormMode] = useState('login');
            const [adminSession, setAdminSession] = useState(window.ADMIN_SESSION || { loggedIn: false });
            const [showProfileModal, setShowProfileModal] = useState(false);
            const [profileTab, setProfileTab] = useState('info');

            const [view, setView] = useState('landing');
            const [exams, setExams] = useState([]);
            const [documents, setDocuments] = useState([]);
            const [videos, setVideos] = useState([]);
            const [selectedPdf, setSelectedPdf] = useState(null);
            const [selectedVideo, setSelectedVideo] = useState(null);
            const [mainMode, setMainMode] = useState('exams'); // 'exams', 'docs', hoặc 'videos'
            
            const [activeGrade, setActiveGrade] = useState('12');
            const [activeParentCategory, setActiveParentCategory] = useState('on_giua_ki');
            const [activeChildCategory, setActiveChildCategory] = useState('ghk1');
            const [activeModeFilter, setActiveModeFilter] = useState('all');

            const [currentExam, setCurrentExam] = useState(null);
            const [shuffledExam, setShuffledExam] = useState(null);
            const [shuffleMapping, setShuffleMapping] = useState(null); 
            const [resultCorrect, setResultCorrect] = useState(null); 
            
            const [student, setStudent] = useState({ name: '', class: '', password: '' });
            const [notifications, setNotifications] = useState([]);
            const [docNotifGradeFilter, setDocNotifGradeFilter] = useState('all');
            const [videoNotifGradeFilter, setVideoNotifGradeFilter] = useState('all');
            const [docNotifPage, setDocNotifPage] = useState(1);
            const [videoNotifPage, setVideoNotifPage] = useState(1);
            const [guideModalType, setGuideModalType] = useState(null);
            const [guideStep, setGuideStep] = useState(1);
            const [answers, setAnswers] = useState({ p1: {}, p2: {}, p3: {} });
            const [startTime, setStartTime] = useState(null);
            const [finalScore, setFinalScore] = useState(null); 
            
            const [tabSwitches, setTabSwitches] = useState(0);
            const [isNavOpen, setIsNavOpen] = useState(false);
            const [isChecking, setIsChecking] = useState(false);
            const [isSubmitting, setIsSubmitting] = useState(false); 
            
            const [showExplain, setShowExplain] = useState({});

            const [practiceList, setPracticeList] = useState([]);
            const [practiceIndex, setPracticeIndex] = useState(0);
            const [practiceStatus, setPracticeStatus] = useState('typing'); 
            const [qTimer, setQTimer] = useState(0); 

            const [dialog, setDialog] = useState({ isOpen: false, type: 'alert', message: '', onConfirm: () => {} });
            const [rulesData, setRulesData] = useState(null);
            const [showAdminLogin, setShowAdminLogin] = useState(false);
            const [showMenu, setShowMenu] = useState(false);
            const [openDropdown, setOpenDropdown] = useState(null); // 'category' | 'guide' | null

            useEffect(() => {
                const handleGlobalClick = () => {
                    setOpenDropdown(null);
                };
                window.addEventListener('click', handleGlobalClick);
                return () => window.removeEventListener('click', handleGlobalClick);
            }, []);

            useEffect(() => {
                setQTimer(0);
            }, [practiceIndex]);

            useEffect(() => {
                if (view !== 'quiz' || currentExam?.examMode !== 'practice' || practiceStatus === 'correct' || practiceStatus === 'skipped') {
                    return;
                }
                const interval = setInterval(() => {
                    setQTimer(t => t + 1);
                }, 1000);
                return () => clearInterval(interval);
            }, [view, currentExam?.examMode, practiceStatus, practiceIndex]);

            // Khóa cuộn trang chính khi bất kỳ popup nào đang mở
            useEffect(() => {
                const isAnyModalOpen = !!(guideModalType || showAdminLogin || showProfileModal || selectedVideo || selectedPdf || dialog.isOpen);
                if (isAnyModalOpen) {
                    document.documentElement.style.overflow = 'hidden';
                    document.body.style.overflow = 'hidden';
                } else {
                    document.documentElement.style.overflow = '';
                    document.body.style.overflow = '';
                }
                return () => {
                    document.documentElement.style.overflow = '';
                    document.body.style.overflow = '';
                };
            }, [guideModalType, showAdminLogin, showProfileModal, selectedVideo, selectedPdf, dialog.isOpen]);

            // Reset bước hướng dẫn khi thay đổi loại hướng dẫn
            useEffect(() => {
                if (guideModalType) {
                    setGuideStep(1);
                }
            }, [guideModalType]);

            const showAlert = (message) => setDialog({ isOpen: true, type: 'alert', message, onConfirm: () => setDialog(prev => ({ ...prev, isOpen: false })) });
            const showConfirm = (message, onConfirm) => setDialog({ isOpen: true, type: 'confirm', message, onConfirm });
            const closeDialog = () => setDialog(prev => ({ ...prev, isOpen: false }));

            const submitRef = useRef(null);
            const answersRef = useRef(answers);
            const tabSwitchesRef = useRef(tabSwitches);
            const lastCheatTime = useRef(0);
            
            useEffect(() => { answersRef.current = answers; }, [answers]);
            useEffect(() => { tabSwitchesRef.current = tabSwitches; }, [tabSwitches]);

            useEffect(() => {
                // Get teachers list
                fetch('?action=get_teachers').then(r => r.json()).then(data => {
                    if (Array.isArray(data)) setTeachers(data);
                }).catch(() => {});

                const tParam = urlParams.get('teacher') || 'thaytuan@admin';
                setCurrentTeacher(tParam);
            }, []);

            useEffect(() => {
                document.querySelectorAll('script[type="text/babel"]').forEach(script => {
                    script.type = 'text/babel-executed';
                });

                // Gom việc tải data lại để xử lý link chia sẻ mượt mà hơn
                Promise.all([
                    fetch('?action=list&t=' + Date.now() + teacherQuery).then(r => r.json()),
                    fetch('?action=list_documents&t=' + Date.now() + teacherQuery).then(r => r.json()),
                    fetch('?action=list_videos&t=' + Date.now() + teacherQuery).then(r => r.json()),
                    fetch('?action=list_notifications&t=' + Date.now()).then(r => r.json()).catch(() => [])
                ]).then(([examsData, docsData, vidsData, notifsData]) => {
                    setExams(examsData);
                    setDocuments(docsData);
                    setVideos(vidsData);
                    const sortedNotifs = (Array.isArray(notifsData) ? notifsData : [])
                        .sort((a, b) => b.id.localeCompare(a.id));
                    setNotifications(sortedNotifs);

        const urlParams = new URLSearchParams(window.location.search);
        const docId = urlParams.get('doc_id');
        const examId = urlParams.get('exam_id');
        const videoId = urlParams.get('video_id');
        
        if (examId) {
            const targetExam = examsData.find(e => String(e.id) === String(examId));
            if (targetExam) {
                setCurrentExam(targetExam);
                const nameParam = urlParams.get('name');
                const classParam = urlParams.get('class');
                if (nameParam || classParam) {
                    setStudent(prev => ({
                        ...prev,
                        name: nameParam ? decodeURIComponent(nameParam).toUpperCase() : prev.name,
                        class: classParam ? decodeURIComponent(classParam).toUpperCase() : prev.class
                    }));
                }
                setView('form');
            } else {
                showAlert("Đề thi không tồn tại hoặc đã bị đóng!");
            }
        } else if (docId && (teacherParam === '' || teacherParam === 'thaytuan@admin')) {
            const targetDoc = docsData.find(d => d.id === docId);
            if (targetDoc) {
                // 1. Chuyển sang Tab Tài Liệu
                setMainMode('docs');
                setView('doc_list');
                
                // 2. Chỉnh đúng Khối
                setActiveGrade(targetDoc.grade || '12');
                
                // 3. Chỉnh đúng Giai đoạn (Tìm Parent từ Child Category)
                let foundParent = 'on_giua_ki';
                for (const [pKey, pVal] of Object.entries(CATEGORY_TREE)) {
                    if (pVal.items[targetDoc.category]) {
                        foundParent = pKey;
                        break;
                    }
                }
                setActiveParentCategory(foundParent);
                setActiveChildCategory(targetDoc.category);
                
                // 4. Bật Modal xem PDF ngay lập tức
                setTimeout(() => {
                    setSelectedPdf(targetDoc.filePath);
                }, 300); // Đợi giao diện chuyển tab xong thì pop-up hiện lên
            } else {
                showAlert("Tài liệu không tồn tại hoặc đã bị giáo viên ẩn đi!");
            }
        } else if (videoId && (teacherParam === '' || teacherParam === 'thaytuan@admin')) {
            const targetVid = vidsData.find(v => v.id === videoId);
            if (targetVid) {
                // 1. Chuyển sang Tab Video
                setMainMode('videos');
                setView('video_list');
                
                // 2. Chỉnh đúng Khối
                setActiveGrade(targetVid.grade || '12');
                
                // 3. Chỉnh đúng Giai đoạn
                let foundParent = 'on_giua_ki';
                for (const [pKey, pVal] of Object.entries(CATEGORY_TREE)) {
                    if (pVal.items[targetVid.category]) {
                        foundParent = pKey;
                        break;
                    }
                }
                setActiveParentCategory(foundParent);
                setActiveChildCategory(targetVid.category);
                
                // 4. Bật Modal xem Video ngay lập tức
                setTimeout(() => {
                    setSelectedVideo(targetVid);
                }, 300);
            } else {
                showAlert("Video bài giảng không tồn tại hoặc đã bị giáo viên ẩn đi!");
            }
        }
    }).catch(e => console.error("Lỗi hệ thống:", e));
}, []);

            // Lấy 3 tài liệu mới nhất để gắn tag MỚI
            const newestDocIds = React.useMemo(() => {
                const sorted = [...documents].sort((a, b) => b.id.localeCompare(a.id));
                return sorted.slice(0, 3).map(d => d.id);
            }, [documents]);

            const NOTIFS_PER_PAGE = 3;

            const docNotifs = React.useMemo(() => {
                const baseDocs = notifications.filter(n => !n.videoId);
                if (docNotifGradeFilter === 'all') return baseDocs;
                return baseDocs.filter(n => {
                    let grade = '';
                    if (n.documentId) {
                        const doc = documents.find(d => String(d.id) === String(n.documentId));
                        if (doc) grade = doc.grade;
                    }
                    return grade === docNotifGradeFilter;
                });
            }, [notifications, docNotifGradeFilter, documents]);

            const totalDocPages = React.useMemo(() => {
                return Math.ceil(docNotifs.length / NOTIFS_PER_PAGE) || 1;
            }, [docNotifs]);

            const currentDocNotifs = React.useMemo(() => {
                return docNotifs.slice((docNotifPage - 1) * NOTIFS_PER_PAGE, docNotifPage * NOTIFS_PER_PAGE);
            }, [docNotifs, docNotifPage]);

            const videoNotifs = React.useMemo(() => {
                const baseVideos = notifications.filter(n => n.videoId);
                if (videoNotifGradeFilter === 'all') return baseVideos;
                return baseVideos.filter(n => {
                    let grade = '';
                    if (n.videoId) {
                        const vid = videos.find(v => String(v.id) === String(n.videoId));
                        if (vid) grade = vid.grade;
                    }
                    return grade === videoNotifGradeFilter;
                });
            }, [notifications, videoNotifGradeFilter, videos]);

            const totalVideoPages = React.useMemo(() => {
                return Math.ceil(videoNotifs.length / NOTIFS_PER_PAGE) || 1;
            }, [videoNotifs]);

            const currentVideoNotifs = React.useMemo(() => {
                return videoNotifs.slice((videoNotifPage - 1) * NOTIFS_PER_PAGE, videoNotifPage * NOTIFS_PER_PAGE);
            }, [videoNotifs, videoNotifPage]);

            useEffect(() => {
                if (docNotifPage > totalDocPages) {
                    setDocNotifPage(totalDocPages);
                }
            }, [totalDocPages, docNotifPage]);

            useEffect(() => {
                if (videoNotifPage > totalVideoPages) {
                    setVideoNotifPage(totalVideoPages);
                }
            }, [totalVideoPages, videoNotifPage]);

            useEffect(() => {
                setDocNotifPage(1);
            }, [docNotifGradeFilter]);

            useEffect(() => {
                setVideoNotifPage(1);
            }, [videoNotifGradeFilter]);

            const getNotificationGradeBadge = (n) => {
                let grade = '';
                if (n.documentId) {
                    const doc = documents.find(d => String(d.id) === String(n.documentId));
                    if (doc) grade = doc.grade;
                } else if (n.videoId) {
                    const vid = videos.find(v => String(v.id) === String(n.videoId));
                    if (vid) grade = vid.grade;
                }
                
                if (!grade) return null;
                
                let label = '';
                let colorClass = '';
                if (grade === '12') {
                    label = 'Lớp 12';
                    colorClass = 'bg-blue-50 text-blue-600 border-blue-100';
                } else if (grade === '11') {
                    label = 'Lớp 11';
                    colorClass = 'bg-emerald-50 text-emerald-600 border-emerald-100';
                } else if (grade === '10') {
                    label = 'Lớp 10';
                    colorClass = 'bg-amber-50 text-amber-600 border-amber-100';
                } else if (grade === 'tot_nghiep') {
                    label = 'TN THPT';
                    colorClass = 'bg-purple-50 text-purple-600 border-purple-100';
                } else {
                    return null;
                }
                
                return (
                    <span className={`text-[8px] font-black uppercase px-1 py-0.5 rounded border shrink-0 ${colorClass}`}>
                        {label}
                    </span>
                );
            };

            const renderGradeOrHotBadge = (n, isNewest) => {
                const gradeBadge = getNotificationGradeBadge(n);
                if (!isNewest) return gradeBadge;
                if (!gradeBadge) {
                    return (
                        <span className="text-[8px] font-black uppercase px-1 py-0.5 bg-rose-500 text-white border border-rose-600 rounded shrink-0 animate-pulse flex items-center gap-0.5">
                            <Icon name="flame" size={7} className="fill-white"/> HOT
                        </span>
                    );
                }
                
                return (
                    <div className="relative w-[54px] h-[18px] shrink-0 select-none">
                        <div className="absolute inset-0 flex items-center justify-center anim-alternate-hot">
                            <span className="text-[8px] font-black uppercase px-1 py-0.5 bg-rose-500 text-white border border-rose-600 rounded shrink-0 flex items-center gap-0.5 w-full justify-center">
                                <Icon name="flame" size={7} className="fill-white"/> HOT
                            </span>
                        </div>
                        <div className="absolute inset-0 flex items-center justify-center anim-alternate-grade">
                            {React.cloneElement(gradeBadge, { className: gradeBadge.props.className + " w-full text-center px-0 text-[8px] py-0.5" })}
                        </div>
                    </div>
                );
            };

            useEffect(() => {
                const handleCheat = () => { 
                    if (view !== 'quiz' || !currentExam || currentExam.examMode === 'practice') return;
                    
                    const maxViolations = currentExam.maxViolations !== undefined ? parseInt(currentExam.maxViolations) : 2;
                    if (maxViolations === 0) return; 
                    
                    const now = Date.now();
                    if (startTime && (now - startTime < 5000)) return;
                    if (now - lastCheatTime.current < 2000) return; 
                    lastCheatTime.current = now;

                    setTabSwitches(prev => {
                        const newCount = prev + 1;
                        if (newCount > maxViolations) {
                            showAlert(`🚨 ĐÌNH CHỈ THI!\nBạn đã vi phạm quá ${maxViolations} lần cho phép. Hệ thống tự động nộp bài ngay lập tức!`);
                            setTimeout(() => { if(submitRef.current) submitRef.current(newCount); }, 100);
                        } else {
                            showAlert(`⚠️ CẢNH BÁO VI PHẠM (${newCount}/${maxViolations}):\nPhát hiện rời khỏi màn hình. Xin hãy tập trung!`); 
                        }
                        return newCount;
                    });
                };

                const onVisibilityChange = () => { if (document.hidden) handleCheat(); };
                document.addEventListener("visibilitychange", onVisibilityChange);
                return () => { document.removeEventListener("visibilitychange", onVisibilityChange); };
            }, [view, currentExam, startTime]);

            const getUnmappedAnswers = () => {
                try {
                    if (!shuffleMapping) return answersRef.current || {p1:{}, p2:{}, p3:{}};
                    const unmapped = { p1: {}, p2: {}, p3: {} };
                    const ans = answersRef.current || {p1:{}, p2:{}, p3:{}};
                    
                    Object.keys(ans.p1 || {}).forEach(newQNum => {
                        const mapInfo = shuffleMapping?.p1?.[newQNum];
                        if (mapInfo && mapInfo.optMap) unmapped.p1[mapInfo.oldQNum] = mapInfo.optMap[ans.p1[newQNum]];
                        else unmapped.p1[newQNum] = ans.p1[newQNum];
                    });
                    Object.keys(ans.p2 || {}).forEach(newQNum => {
                        const mapInfo = shuffleMapping?.p2?.[newQNum];
                        if (mapInfo && mapInfo.optMap) {
                            unmapped.p2[mapInfo.oldQNum] = {};
                            Object.keys(ans.p2[newQNum] || {}).forEach(newSub => {
                                unmapped.p2[mapInfo.oldQNum][mapInfo.optMap[newSub]] = ans.p2[newQNum][newSub];
                            });
                        } else {
                            unmapped.p2[newQNum] = ans.p2[newQNum];
                        }
                    });
                    Object.keys(ans.p3 || {}).forEach(newQNum => {
                        const mapInfo = shuffleMapping?.p3?.[newQNum];
                        if (mapInfo) unmapped.p3[mapInfo.oldQNum] = ans.p3[newQNum];
                        else unmapped.p3[newQNum] = ans.p3[newQNum];
                    });
                    return unmapped;
                } catch (e) {
                    console.error("Lỗi gỡ xáo trộn đáp án:", e);
                    return answersRef.current || {p1:{}, p2:{}, p3:{}};
                }
            };

            useEffect(() => {
                if (view !== 'quiz' || !currentExam) return;
                const syncLive = () => {
                    const payload = { examId: currentExam.id, name: student.name.toUpperCase(), class: student.class.toUpperCase(), userAnswers: getUnmappedAnswers(), startTime: startTime, tabSwitches: tabSwitchesRef.current };
                    fetch('?action=sync_live' + teacherQuery, { method: 'POST', body: JSON.stringify(payload) }).catch(()=>{});
                };
                syncLive();
                const intervalId = setInterval(syncLive, 1000); 
                return () => clearInterval(intervalId);
            }, [view, currentExam, student, startTime, shuffleMapping]);

            const handleParentChange = (parentKey) => {
                setActiveParentCategory(parentKey);
                setActiveChildCategory(Object.keys(CATEGORY_TREE[parentKey].items)[0]);
            };

            const availableParentCategories = Object.entries(CATEGORY_TREE).filter(([k,v]) => {
                if (activeGrade === 'tot_nghiep') return k === 'on_thi_tn';
                return k !== 'on_thi_tn';
            });

            const filteredExams = React.useMemo(() => {
                return exams.filter(e => {
                    const eGrade = e.grade || '12';
                    const eCategory = e.category || 'khac';
                    const matchGrade = (eGrade === activeGrade);
                    const matchCategory = eCategory === activeChildCategory;
                    const eMode = e.examMode || 'normal';
                    const matchMode = activeModeFilter === 'all' || eMode === activeModeFilter;
                    return matchGrade && matchCategory && matchMode;
                });
            }, [exams, activeChildCategory, activeGrade, activeModeFilter]);

            const handleAns = (part, qNum, val) => {
                setAnswers(p => ({...p, [part]: {...p[part], [qNum]: val}}));
                if(currentExam?.examMode === 'practice') setPracticeStatus('typing'); 
            };
            
            const stats = React.useMemo(() => {
                if (!currentExam) return { done: 0, total: 0 };
                const d1 = Object.keys(answers.p1 || {}).length;
                const d2 = Object.keys(answers.p2 || {}).filter(k => Object.keys(answers.p2[k] || {}).length === 4).length;
                const d3 = Object.keys(answers.p3 || {}).filter(k => (answers.p3[k] || '').trim() !== '').length;
                
                const p1Total = currentExam?.config?.p1 || 0;
                const p2Total = currentExam?.config?.p2 || 0;
                const p3Total = currentExam?.config?.p3 || 0;

                return { done: d1 + d2 + d3, total: p1Total + p2Total + p3Total };
            }, [answers, currentExam]);

            const calculateScore = () => {
                let score = 0;
                try {
                    const conf = currentExam?.config || { p1: 0, p2: 0, p3: 0 };
                    const scoring = currentExam?.scoring || { p1: 0.25, p2: { '4': 1.0, '3': 0.5, '2': 0.25, '1': 0.1 }, p3: 0.5 };
                    const uAns = answersRef.current || {};
                    const cAns = resultCorrect || {};

                    const ansP1 = uAns.p1 || {}; const ansP2 = uAns.p2 || {}; const ansP3 = uAns.p3 || {};
                    const corP1 = cAns.p1 || {}; const corP2 = cAns.p2 || {}; const corP3 = cAns.p3 || {};

                    const sP1 = parseFloat(scoring.p1) || 0.25;
                    const sP2_4 = parseFloat(scoring.p2?.['4']) || 1.0;
                    const sP2_3 = parseFloat(scoring.p2?.['3']) || 0.5;
                    const sP2_2 = parseFloat(scoring.p2?.['2']) || 0.25;
                    const sP2_1 = parseFloat(scoring.p2?.['1']) || 0.1;
                    const sP3 = parseFloat(scoring.p3) || 0.5;

                    for (let i = 1; i <= (conf.p1 || 0); i++) {
                        if (ansP1[i] && ansP1[i] === corP1[i]) score += sP1;
                    }

                    for (let i = 1; i <= (conf.p2 || 0); i++) {
                        let correctCount = 0;
                        ['a', 'b', 'c', 'd'].forEach(sub => {
                            if (ansP2[i]?.[sub] !== undefined && corP2[i]?.[sub] !== undefined && ansP2[i][sub] === corP2[i][sub]) correctCount++;
                        });
                        if (correctCount === 4) score += sP2_4;
                        else if (correctCount === 3) score += sP2_3;
                        else if (correctCount === 2) score += sP2_2;
                        else if (correctCount === 1) score += sP2_1;
                    }

                    for (let i = 1; i <= (conf.p3 || 0); i++) {
                        const userText = ansP3[i] ? String(ansP3[i]) : '';
                        const correctText = corP3[i] ? String(corP3[i]) : '';
                        const uVal = userText.trim().toLowerCase().replace(/[ ,$]/g, '').replace(',', '.');
                        const cVal = correctText.trim().toLowerCase().replace(/[ ,$]/g, '').replace(',', '.');
                        if (uVal !== '' && cVal !== '' && uVal === cVal) score += sP3;
                    }
                    return score;
                } catch(e) {
                    console.error("Lỗi khi tính điểm:", e); return 0; 
                }
            };

            const submit = (forceTabSwitches = null) => {
                if (isSubmitting) return; 
                setIsSubmitting(true);

                try {
                    const scoreNumber = calculateScore();
                    let finalDisplayScore = scoreNumber.toFixed(2);
                    
                    const conf = currentExam?.config || { p1: 0, p2: 0, p3: 0 };
                    const scoring = currentExam?.scoring || { p1: 0.25, p2: { '4': 1.0, '3': 0.5, '2': 0.25, '1': 0.1 }, p3: 0.5 };
                    if (currentExam?.examMode === 'practice') {
                        const maxScore = ((conf.p1 || 0) * (parseFloat(scoring.p1) || 0.25)) + ((conf.p2 || 0) * (parseFloat(scoring.p2?.['4']) || 1.0)) + ((conf.p3 || 0) * (parseFloat(scoring.p3) || 0.5));
                        finalDisplayScore = maxScore.toFixed(2);
                    }

                    setFinalScore(finalDisplayScore); 
                    setView('result'); 

                    const finalTabSwitches = forceTabSwitches !== null ? forceTabSwitches : tabSwitchesRef.current;
                    let unmappedAnswers = answersRef.current; 
                    try { unmappedAnswers = getUnmappedAnswers(); } catch(e) {}

                    const payload = { 
                        examId: currentExam.id, 
                        name: student.name.toUpperCase(), 
                        class: student.class.toUpperCase(), 
                        userAnswers: unmappedAnswers, 
                        clientScore: finalDisplayScore, 
                        startTime, 
                        endTime: Date.now(), 
                        tabSwitches: finalTabSwitches
                    };

                    fetch('?action=submit_attempt' + teacherQuery, { 
                        method: 'POST', 
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload) 
                    }).catch(e => console.log("Lưu nền thất bại:", e));

                } catch (err) {
                    console.error(err); showAlert("Có lỗi khi tính điểm trên trình duyệt. Vui lòng thử lại.");
                } finally {
                    setIsSubmitting(false);
                }
            };

            const handleSubmitClick = () => {
                if (isSubmitting) return;
                showConfirm("Hệ thống sẽ chấm điểm và lưu kết quả.\nBạn có chắc chắn muốn nộp bài ngay?", () => { submit(null); });
            };

            useEffect(() => { submitRef.current = submit; }, [currentExam, student, startTime, shuffleMapping, resultCorrect]);

            const handleNotificationClick = (n) => {
                if (n.documentId) {
                    const doc = documents.find(d => d.id === n.documentId);
                    if (doc) {
                        setMainMode('docs');
                        setActiveGrade(doc.grade || '12');
                        if (doc.grade === 'tot_nghiep' || doc.category === 'on_thi_tn') {
                            setActiveParentCategory('on_thi_tn');
                            setActiveChildCategory('on_thi_tn');
                        } else {
                            if (doc.category === 'ghk1' || doc.category === 'ghk2') {
                                setActiveParentCategory('on_giua_ki');
                            } else {
                                setActiveParentCategory('on_cuoi_ki');
                            }
                            setActiveChildCategory(doc.category);
                        }
                        setSelectedPdf(doc.filePath);
                    } else {
                        showAlert("Tài liệu đính kèm không tồn tại hoặc đã bị xóa!");
                    }
                } else if (n.videoId) {
                    const vid = videos.find(v => v.id === n.videoId);
                    if (vid) {
                        setMainMode('videos');
                        setActiveGrade(vid.grade || '12');
                        if (vid.grade === 'tot_nghiep' || vid.category === 'on_thi_tn') {
                            setActiveParentCategory('on_thi_tn');
                            setActiveChildCategory('on_thi_tn');
                        } else {
                            if (vid.category === 'ghk1' || vid.category === 'ghk2') {
                                setActiveParentCategory('on_giua_ki');
                            } else {
                                setActiveParentCategory('on_cuoi_ki');
                            }
                            setActiveChildCategory(vid.category);
                        }
                        setSelectedVideo(vid);
                    } else {
                        showAlert("Video đính kèm không tồn tại hoặc đã bị xóa!");
                    }
                } else if (n.link) {
                    window.open(n.link, '_blank');
                }
            };

            const startQuiz = async () => {
                if(student.name && student.class){ 
                    setIsChecking(true);
                    const nameUC = student.name.trim().toUpperCase().replace(/\s+/g, ' ');
                    const classUC = student.class.trim().toUpperCase();

                    // Kiểm tra quyền truy cập và liên kết tài khoản học sinh lớp học
                    try {
                        const permUrl = `?action=verify_student_exam_permission&examId=${currentExam.id}&name=${encodeURIComponent(nameUC)}&class=${encodeURIComponent(classUC)}&password=${encodeURIComponent((student.password || '').trim())}${teacherQuery}`;
                        const permR = await fetch(permUrl);
                        const permRes = await permR.json();
                        if (!permRes.success) {
                            showAlert(permRes.message || "Bạn không có quyền truy cập làm bài tập/bài kiểm tra này.");
                            setIsChecking(false);
                            return;
                        }
                    } catch (err) {
                        showAlert("Lỗi kiểm tra quyền truy cập bài kiểm tra!");
                        setIsChecking(false);
                        return;
                    }

                    // Luôn lấy số lần đã làm bài để hiển thị cho học sinh trong popup
                    let attemptCount = 0;
                    try {
                        const url = `?action=check_attempts&examId=${currentExam.id}&name=${encodeURIComponent(nameUC)}&class=${encodeURIComponent(classUC)}&t=${Date.now()}${teacherQuery}`;
                        const r = await fetch(url);
                        const res = await r.json();
                        attemptCount = res.count;
                        
                        const maxAttempts = currentExam?.maxAttempts !== undefined ? currentExam.maxAttempts : 0;
                        if (maxAttempts > 0 && attemptCount >= maxAttempts) {
                            showAlert(`🚨 TỪ CHỐI TRUY CẬP!\nBạn đã làm bài ${attemptCount}/${maxAttempts} lần cho phép. Không thể thi tiếp!`);
                            setIsChecking(false); return;
                        }
                    } catch(e) {
                        console.error("Lỗi khi kiểm tra số lần làm bài:", e);
                    }

                    const mode = currentExam?.examMode || 'normal';
                    const qsRaw = currentExam?.questions || {};
                    const correctRaw = currentExam?.correctAnswers || {};
                    
                    const qs = {
                        p1: Array.isArray(qsRaw.p1) ? qsRaw.p1 : Object.values(qsRaw.p1 || {}),
                        p2: Array.isArray(qsRaw.p2) ? qsRaw.p2 : Object.values(qsRaw.p2 || {}),
                        p3: Array.isArray(qsRaw.p3) ? qsRaw.p3 : Object.values(qsRaw.p3 || {})
                    };

                    const mapping = { p1: {}, p2: {}, p3: {} };
                    const newQs = { p1: [], p2: [], p3: [] };
                    const newCorrect = { p1: {}, p2: {}, p3: {} };

                    if (qs.p1 && qs.p1.length > 0) {
                        const p1Indices = shuffleArray(qs.p1.map((_, i) => i));
                        p1Indices.forEach((oldIdx, newIdx) => {
                            const q = JSON.parse(JSON.stringify(qs.p1[oldIdx]));
                            const rawOpts = q.opts || [];
                            const optsArr = Array.isArray(rawOpts) ? rawOpts : Object.values(rawOpts);
                            const optsWithIndex = optsArr.map((text, i) => ({ text, oldChar: String.fromCharCode(65+i) }));
                            const shuffledOpts = shuffleArray(optsWithIndex);
                            
                            q.opts = shuffledOpts.map(o => o.text);
                            const optMap = {};
                            shuffledOpts.forEach((o, i) => { optMap[String.fromCharCode(65+i)] = o.oldChar; });
                            mapping.p1[newIdx+1] = { oldQNum: oldIdx+1, optMap };
                            newQs.p1.push(q);
                            
                            if (correctRaw?.p1 && correctRaw.p1[oldIdx + 1]) {
                                const oldCorrectChar = correctRaw.p1[oldIdx + 1];
                                const newCorrectIdx = shuffledOpts.findIndex(o => o.oldChar === oldCorrectChar);
                                if (newCorrectIdx >= 0) newCorrect.p1[newIdx+1] = String.fromCharCode(65 + newCorrectIdx);
                            }
                        });
                    }

                    if (qs.p2 && qs.p2.length > 0) {
                        const p2Indices = shuffleArray(qs.p2.map((_, i) => i));
                        p2Indices.forEach((oldIdx, newIdx) => {
                            const q = JSON.parse(JSON.stringify(qs.p2[oldIdx]));
                            const rawOpts = q.opts || [];
                            const optsArr = Array.isArray(rawOpts) ? rawOpts : Object.values(rawOpts);
                            const optsWithIndex = optsArr.map((text, i) => ({ text, oldSub: String.fromCharCode(97+i) }));
                            const shuffledOpts = shuffleArray(optsWithIndex);
                            
                            q.opts = shuffledOpts.map(o => o.text);
                            const optMap = {};
                            shuffledOpts.forEach((o, i) => { optMap[String.fromCharCode(97+i)] = o.oldSub; });
                            mapping.p2[newIdx+1] = { oldQNum: oldIdx+1, optMap };
                            newQs.p2.push(q);
                            
                            if (correctRaw?.p2 && correctRaw.p2[oldIdx + 1]) {
                                const oldAnsObj = correctRaw.p2[oldIdx + 1];
                                const newAnsObj = {};
                                shuffledOpts.forEach((o, i) => { newAnsObj[String.fromCharCode(97+i)] = oldAnsObj[o.oldSub]; });
                                newCorrect.p2[newIdx+1] = newAnsObj;
                            }
                        });
                    }

                    if (qs.p3 && qs.p3.length > 0) {
                        const p3Indices = shuffleArray(qs.p3.map((_, i) => i));
                        p3Indices.forEach((oldIdx, newIdx) => {
                            mapping.p3[newIdx+1] = { oldQNum: oldIdx+1 };
                            newQs.p3.push(JSON.parse(JSON.stringify(qs.p3[oldIdx])));
                            if (correctRaw?.p3 && correctRaw.p3[oldIdx + 1] !== undefined) {
                                newCorrect.p3[newIdx+1] = correctRaw.p3[oldIdx + 1];
                            }
                        });
                    }

                    setShuffleMapping(mapping); setShuffledExam(newQs); setResultCorrect(newCorrect); 
                    
                    if (mode === 'practice') {
                        let flat = [];
                        if (newQs.p1) newQs.p1.forEach((q, i) => flat.push({ part: 'p1', idx: i+1, q, correct: newCorrect?.p1?.[i+1] }));
                        if (newQs.p2) newQs.p2.forEach((q, i) => flat.push({ part: 'p2', idx: i+1, q, correct: newCorrect?.p2?.[i+1] }));
                        if (newQs.p3) newQs.p3.forEach((q, i) => flat.push({ part: 'p3', idx: i+1, q, correct: newCorrect?.p3?.[i+1] }));
                        setPracticeList(flat); setPracticeIndex(0); setPracticeStatus('typing');
                    }

                    setShowExplain({}); 
                    setStudent({ name: nameUC, class: classUC });

                    // Lưu thông số vào rulesData để hiển thị popup hướng dẫn thi
                    setRulesData({
                        attemptCount,
                        maxAttempts: currentExam?.maxAttempts !== undefined ? currentExam.maxAttempts : 0,
                        duration: currentExam?.duration || 0,
                        maxViolations: currentExam?.maxViolations !== undefined ? parseInt(currentExam.maxViolations) : 2,
                        examMode: mode,
                        title: currentExam?.title || '',
                        config: currentExam?.config || { p1: 0, p2: 0, p3: 0 },
                        scoring: currentExam?.scoring || { p1: 0.25, p2: { '4': 1.0, '3': 0.5, '2': 0.25, '1': 0.1 }, p3: 0.5 },
                        isPreviewOnly: false
                    });

                    setIsChecking(false);
                }
            };

            const openExamInstructions = () => {
                if (!currentExam) return;
                const mode = currentExam.examMode || 'normal';
                setRulesData({
                    attemptCount: 0,
                    maxAttempts: currentExam.maxAttempts !== undefined ? currentExam.maxAttempts : 0,
                    duration: currentExam.duration || 0,
                    maxViolations: currentExam.maxViolations !== undefined ? parseInt(currentExam.maxViolations) : 2,
                    examMode: mode,
                    title: currentExam.title || '',
                    config: currentExam.config || { p1: 0, p2: 0, p3: 0 },
                    scoring: currentExam.scoring || { p1: 0.25, p2: { '4': 1.0, '3': 0.5, '2': 0.25, '1': 0.1 }, p3: 0.5 },
                    isPreviewOnly: true
                });
            };

            const confirmStartQuiz = () => {
                if (rulesData?.isPreviewOnly) {
                    setRulesData(null);
                    return;
                }
                setStartTime(Date.now()); 
                setAnswers({p1:{},p2:{},p3:{}}); 
                setTabSwitches(0); 
                setView('quiz'); 
                setRulesData(null);
            };

            const toggleExplain = (part, qNum) => setShowExplain(prev => ({...prev, [`${part}-${qNum}`]: !prev[`${part}-${qNum}`]}));

            const checkPracticeAnswer = () => {
                const current = practiceList[practiceIndex];
                if (!current) return;
                let isCorrect = false;

                if (current.part === 'p1') {
                    if (!answers?.p1?.[current.idx]) return showAlert("Vui lòng chọn đáp án!");
                    isCorrect = answers.p1[current.idx] === current.correct;
                } 
                else if (current.part === 'p2') {
                    const userAns = answers?.p2?.[current.idx] || {};
                    if (Object.keys(userAns).length < 4) return showAlert("Vui lòng chọn ĐÚNG/SAI cho cả 4 ý!");
                    isCorrect = true;
                    ['a','b','c','d'].forEach(sub => { if (userAns[sub] !== current?.correct?.[sub]) isCorrect = false; });
                } 
                else if (current.part === 'p3') {
                    if (!answers?.p3?.[current.idx] || answers.p3[current.idx].trim() === '') return showAlert("Vui lòng nhập đáp án!");
                    const uA = answers.p3[current.idx].trim().toLowerCase().replace(/[ ,$]/g, '').replace(',', '.');
                    const cA = (current.correct||'').trim().toLowerCase().replace(/[ ,$]/g, '').replace(',', '.');
                    isCorrect = (uA === cA);
                }

                if (isCorrect) { setPracticeStatus('correct'); setShowExplain({[`${current.part}-${current.idx}`]: true}); } 
                else { setPracticeStatus('wrong'); }
            };

            const scrollTo = (id) => {
                const el = document.getElementById(id);
                if(el) {
                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    el.classList.add('ring-4', 'ring-emerald-300');
                    setTimeout(() => el.classList.remove('ring-4', 'ring-emerald-300'), 1500);
                    if(window.innerWidth < 1024) setIsNavOpen(false); 
                }
            };

            const renderContent = () => {
                if (view === 'landing') return (
                    <div className="min-h-screen flex flex-col items-center justify-center p-4 md:p-6 relative overflow-hidden font-sans selection:bg-emerald-500 selection:text-white bg-white">
                        
                        {/* Logo tMath ở góc trên bên trái */}
                        <div 
                            onClick={() => window.location.reload()} 
                            className="absolute top-4 left-4 md:top-6 md:left-6 flex items-center gap-2 z-40 select-none cursor-pointer hover:opacity-90 active:scale-95 transition-all"
                        >
                            <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-500 text-white flex items-center justify-center font-black text-sm shadow-md shadow-emerald-500/20">
                                tM
                            </div>
                            <span className="font-black text-slate-800 tracking-tighter text-sm uppercase">tMath</span>
                        </div>

                        <div className="absolute top-[-20%] left-[-10%] w-[80vw] h-[80vw] max-w-[600px] max-h-[600px] bg-emerald-500/10 rounded-full blur-[100px] md:blur-[120px] animate-pulse" style={{animationDuration: '10s'}}></div>
                        <div className="absolute bottom-[-20%] right-[-10%] w-[80vw] h-[80vw] max-w-[600px] max-h-[600px] bg-indigo-500/10 rounded-full blur-[100px] md:blur-[120px] animate-pulse" style={{animationDuration: '12s', animationDelay: '2s'}}></div>
                        <div className="absolute top-[20%] right-[20%] w-[250px] h-[250px] bg-teal-500/5 rounded-full blur-[80px] animate-pulse" style={{animationDuration: '8s'}}></div>


                        {/* Ký hiệu toán học in chìm dưới nền */}
                        <div className="absolute inset-0 overflow-hidden pointer-events-none select-none z-0">
                            <div className="absolute top-[8%] left-[6%] text-[10vw] font-serif text-emerald-500/[0.05] rotate-[-15deg] font-light">∫ dx</div>
                            <div className="absolute top-[18%] right-[8%] text-[8vw] font-serif text-indigo-500/[0.05] rotate-[12deg] font-light">√x</div>
                            <div className="absolute bottom-[22%] left-[8%] text-[5vw] font-mono text-cyan-500/[0.05] rotate-[20deg] font-bold">xⁿ</div>
                            <div className="absolute bottom-[10%] right-[10%] text-[8vw] font-serif text-emerald-500/[0.05] rotate-[-10deg] font-light">lim</div>
                            <div className="absolute top-[45%] left-[50%] -translate-x-1/2 text-[14vw] font-serif text-pink-500/[0.03] font-light">π</div>
                            <div className="absolute bottom-[35%] right-[22%] text-[6vw] font-serif text-indigo-500/[0.05] rotate-[-18deg] font-light">∑</div>
                            <div className="absolute top-[32%] left-[22%] text-[5vw] font-mono text-teal-500/[0.05] rotate-[15deg] font-light">f(x)</div>

                            {/* Hình học không gian in chìm */}
                            <div className="absolute top-[12%] left-[12%] rotate-[15deg] opacity-60">
                                <svg width="100" height="100" viewBox="0 0 120 120" fill="none" stroke="currentColor" strokeWidth="1" strokeLinecap="round" strokeLinejoin="round" className="text-emerald-500/[0.05]">
                                    <line x1="60" y1="15" x2="15" y2="85" />
                                    <line x1="60" y1="15" x2="65" y2="95" />
                                    <line x1="60" y1="15" x2="105" y2="80" />
                                    <line x1="60" y1="15" x2="45" y2="60" strokeDasharray="3,3" />
                                    <line x1="15" y1="85" x2="65" y2="95" />
                                    <line x1="65" y1="95" x2="105" y2="80" />
                                    <line x1="105" y1="80" x2="45" y2="60" strokeDasharray="3,3" />
                                    <line x1="45" y1="60" x2="15" y2="85" strokeDasharray="3,3" />
                                    <line x1="60" y1="15" x2="57" y2="80" strokeDasharray="3,3" />
                                </svg>
                            </div>
                            <div className="absolute top-[48%] right-[5%] rotate-[-12deg] opacity-60">
                                <svg width="90" height="90" viewBox="0 0 100 100" fill="none" stroke="currentColor" strokeWidth="1" strokeLinecap="round" strokeLinejoin="round" className="text-indigo-500/[0.05]">
                                    <rect x="15" y="35" width="50" height="50" />
                                    <line x1="65" y1="35" x2="85" y2="15" />
                                    <line x1="65" y1="85" x2="85" y2="65" />
                                    <line x1="85" y1="15" x2="85" y2="65" />
                                    <line x1="35" y1="15" x2="85" y2="15" />
                                    <line x1="15" y1="35" x2="35" y2="15" />
                                    <line x1="35" y1="15" x2="35" y2="65" strokeDasharray="3,3" />
                                    <line x1="35" y1="65" x2="85" y2="65" strokeDasharray="3,3" />
                                    <line x1="15" y1="85" x2="35" y2="65" strokeDasharray="3,3" />
                                </svg>
                            </div>
                            <div className="absolute bottom-[18%] left-[4%] rotate-[8deg] opacity-60">
                                <svg width="80" height="110" viewBox="0 0 90 130" fill="none" stroke="currentColor" strokeWidth="1" strokeLinecap="round" strokeLinejoin="round" className="text-pink-500/[0.05]">
                                    <ellipse cx="45" cy="25" rx="30" ry="12" />
                                    <line x1="15" y1="25" x2="15" y2="105" />
                                    <line x1="75" y1="25" x2="75" y2="105" />
                                    <path d="M 15 105 A 30 12 0 0 0 75 105" />
                                    <path d="M 15 105 A 30 12 0 0 1 75 105" strokeDasharray="3,3" />
                                    <line x1="45" y1="25" x2="45" y2="105" strokeDasharray="3,3" />
                                </svg>
                            </div>
                            <div className="absolute top-[28%] right-[15%] rotate-[-20deg] opacity-60">
                                <svg width="80" height="100" viewBox="0 0 90 120" fill="none" stroke="currentColor" strokeWidth="1" strokeLinecap="round" strokeLinejoin="round" className="text-teal-500/[0.05]">
                                    <line x1="45" y1="15" x2="15" y2="95" />
                                    <line x1="45" y1="15" x2="75" y2="95" />
                                    <path d="M 15 95 A 30 10 0 0 0 75 95" />
                                    <path d="M 15 95 A 30 10 0 0 1 75 95" strokeDasharray="3,3" />
                                    <line x1="45" y1="15" x2="45" y2="95" strokeDasharray="3,3" />
                                    <line x1="45" y1="95" x2="75" y2="95" strokeDasharray="3,3" />
                                </svg>
                            </div>
                        </div>

                        <div className="relative z-10 w-full max-w-5xl mx-auto flex flex-col items-center mt-6 md:mt-4 pt-4 md:pt-2">
                            {/* Nền tảng học tập tương tác badge */}
                            <div className="inline-flex items-center gap-2 px-3 py-2 md:px-4 md:py-2.5 rounded-full bg-emerald-50 border border-emerald-100 text-emerald-700 text-[10px] md:text-xs font-bold tracking-widest uppercase mb-4 md:mb-5 shadow-[0_4px_12px_rgba(16,185,129,0.08)] backdrop-blur-md">
                                <span className="relative flex h-2 w-2 md:h-2.5 md:w-2.5">
                                <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                <span className="relative inline-flex rounded-full h-2 w-2 md:h-2.5 md:w-2.5 bg-emerald-500"></span>
                                </span>
                                NỀN TẢNG HỌC TẬP TƯƠNG TÁC
                            </div>
                            <div className="text-center mb-4 md:mb-5 w-full px-4 overflow-visible">
                                <h1 className="text-3xl sm:text-5xl md:text-6xl lg:text-7xl font-black text-transparent bg-clip-text bg-gradient-to-br from-slate-900 via-slate-800 to-slate-500 tracking-tight py-2 md:py-3 leading-snug">
                                    <span className="font-serif font-light mr-1">∫</span>HỆ THỐNG HỌC TẬP
                                </h1>
                                <h2 className="text-2xl sm:text-4xl md:text-5xl lg:text-6xl font-black text-transparent bg-clip-text bg-gradient-to-r from-emerald-600 via-teal-500 to-cyan-600 tracking-tighter uppercase drop-shadow-[0_4px_12px_rgba(20,184,166,0.15)] py-2 md:py-3 leading-snug mt-1 md:mt-2 flex items-center justify-center gap-1.5">
                                    <span>MÔN TOÁN THPT</span>
                                    <SquareRootX className="w-7 h-7 sm:w-10 sm:h-10 md:w-12 md:h-12 text-teal-500" />
                                </h2>
                            </div>

                            {/* Dãy nút điều hướng kiểu phẳng */}
                            <div className="w-full max-w-5xl mx-auto py-2 my-2 flex flex-row flex-wrap items-center justify-center gap-3 md:gap-5 z-30">
                                {/* Dropdown Hướng dẫn tách riêng biệt */}
                                <div 
                                    className="relative group"
                                    onMouseEnter={() => setOpenDropdown('guide')}
                                    onMouseLeave={() => setOpenDropdown(null)}
                                >
                                    <button 
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            setOpenDropdown(prev => prev === 'guide' ? null : 'guide');
                                        }}
                                        className="bg-transparent hover:bg-slate-100/70 text-slate-600 hover:text-slate-900 px-3.5 py-2 rounded-xl text-[11px] md:text-xs font-black uppercase tracking-widest flex items-center gap-1.5 transition-all border border-transparent cursor-pointer select-none whitespace-nowrap"
                                    >
                                        <Icon name="help-circle" size={12}/>
                                        <span>Hướng dẫn</span>
                                        <Icon name="chevron-down" size={10} className={`transition-transform duration-200 ${openDropdown === 'guide' ? 'rotate-180' : 'group-hover:rotate-180'}`}/>
                                    </button>
                                    
                                    <div className={`absolute right-0 top-[100%] pt-2 w-48 transition-all duration-200 transform z-50 ${openDropdown === 'guide' ? 'opacity-100 pointer-events-auto translate-y-0' : 'opacity-0 pointer-events-none translate-y-1 group-hover:opacity-100 group-hover:pointer-events-auto group-hover:translate-y-0'}`}>
                                        <div className="bg-white border border-slate-200/80 rounded-2xl shadow-xl p-2 flex flex-col gap-1 backdrop-blur-md">
                                            <button onClick={(e) => { e.stopPropagation(); setGuideModalType('teacher'); setOpenDropdown(null); }} className="flex items-center gap-2 px-3 py-2 rounded-lg text-left font-bold text-[11px] md:text-xs text-slate-600 hover:bg-slate-50 hover:text-indigo-600 transition-all">
                                                <Icon name="graduation-cap" size={14} className="text-indigo-500"/>
                                                Dành cho Giáo viên
                                            </button>
                                            <button onClick={(e) => { e.stopPropagation(); setGuideModalType('student'); setOpenDropdown(null); }} className="flex items-center gap-2 px-3 py-2 rounded-lg text-left font-bold text-[11px] md:text-xs text-slate-600 hover:bg-slate-50 hover:text-emerald-600 transition-all">
                                                <Icon name="user" size={14} className="text-emerald-500"/>
                                                Dành cho Học sinh
                                            </button>
                                            <button onClick={(e) => { e.stopPropagation(); setGuideModalType('contact'); setOpenDropdown(null); }} className="flex items-center gap-2 px-3 py-2 rounded-lg text-left font-bold text-[11px] md:text-xs text-slate-600 hover:bg-slate-50 hover:text-amber-600 transition-all">
                                                <Icon name="mail" size={14} className="text-amber-500"/>
                                                Thông tin liên hệ
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                {/* Dropdown Danh mục kích hoạt khi hover */}
                                <div 
                                    className="relative group"
                                    onMouseEnter={() => setOpenDropdown('category')}
                                    onMouseLeave={() => setOpenDropdown(null)}
                                >
                                    <button 
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            setOpenDropdown(prev => prev === 'category' ? null : 'category');
                                        }}
                                        className="bg-transparent hover:bg-slate-100/70 text-slate-600 hover:text-slate-900 px-3.5 py-2 rounded-xl text-[11px] md:text-xs font-black uppercase tracking-widest flex items-center gap-1.5 transition-all border border-transparent cursor-pointer select-none whitespace-nowrap"
                                    >
                                        <Icon name="compass" size={12}/>
                                        <span>Danh mục</span>
                                        <Icon name="chevron-down" size={10} className={`transition-transform duration-200 ${openDropdown === 'category' ? 'rotate-180' : 'group-hover:rotate-180'}`}/>
                                    </button>
                                    
                                    <div className={`absolute right-0 top-[100%] pt-2 w-48 transition-all duration-200 transform z-50 ${openDropdown === 'category' ? 'opacity-100 pointer-events-auto translate-y-0' : 'opacity-0 pointer-events-none translate-y-1 group-hover:opacity-100 group-hover:pointer-events-auto group-hover:translate-y-0'}`}>
                                        <div className="bg-white border border-slate-200/80 rounded-2xl shadow-xl p-2 flex flex-col gap-1 backdrop-blur-md">
                                            <button onClick={(e) => { e.stopPropagation(); setMainMode('exams'); setShowMenu(true); setOpenDropdown(null); }} className="flex items-center gap-2 px-3 py-2 rounded-lg text-left font-bold text-[11px] md:text-xs text-slate-600 hover:bg-emerald-50 hover:text-emerald-700 transition-all">
                                                <Icon name="file-text" size={14} className="text-emerald-500"/>
                                                Đề thi trực tuyến
                                            </button>
                                            <button onClick={(e) => { e.stopPropagation(); setMainMode('docs'); setShowMenu(true); setOpenDropdown(null); }} className="flex items-center gap-2 px-3 py-2 rounded-lg text-left font-bold text-[11px] md:text-xs text-slate-600 hover:bg-indigo-50 hover:text-indigo-700 transition-all">
                                                <Icon name="book-open" size={14} className="text-indigo-500"/>
                                                Tài liệu học tập
                                            </button>
                                            <button onClick={(e) => { e.stopPropagation(); setMainMode('videos'); setShowMenu(true); setOpenDropdown(null); }} className="flex items-center gap-2 px-3 py-2 rounded-lg text-left font-bold text-[11px] md:text-xs text-slate-600 hover:bg-pink-50 hover:text-pink-700 transition-all">
                                                <Icon name="play-circle" size={14} className="text-pink-500"/>
                                                Video bài giảng
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                {/* Nút Đăng nhập hoặc Xin chào */}
                                {!adminSession.loggedIn ? (
                                    <button onClick={() => { setAdminFormMode('login'); setShowAdminLogin(true); }} className="bg-transparent hover:bg-slate-100/70 text-slate-600 hover:text-slate-900 px-3.5 py-2 rounded-xl text-[11px] md:text-xs font-black uppercase tracking-widest flex items-center gap-1.5 transition-all border border-transparent cursor-pointer select-none whitespace-nowrap">
                                        <Icon name="lock" size={12}/>
                                        <span className="hidden sm:inline">Đăng nhập</span>
                                    </button>
                                ) : (
                                    <div className="relative group">
                                        <button className="bg-emerald-50 text-emerald-700 hover:bg-emerald-100/80 px-3.5 py-2 rounded-xl text-[11px] md:text-xs font-black uppercase tracking-widest flex items-center gap-1.5 transition-all border border-transparent cursor-pointer select-none whitespace-nowrap">
                                            <span>👋</span>
                                            <span>Xin chào, {adminSession.fullName || adminSession.username}</span>
                                            <Icon name="chevron-down" size={10} className="group-hover:rotate-180 transition-transform duration-200"/>
                                        </button>
                                        
                                        <div className="absolute right-0 top-[100%] pt-2 w-48 opacity-0 pointer-events-none group-hover:opacity-100 group-hover:pointer-events-auto transition-all duration-200 transform translate-y-1 group-hover:translate-y-0 z-50">
                                            <div className="bg-white border border-slate-200/80 rounded-2xl shadow-xl p-2 flex flex-col gap-1 backdrop-blur-md">
                                                <a href="admin.php" className="flex items-center gap-2 px-3 py-2 rounded-lg text-left font-bold text-[11px] md:text-xs text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition-all">
                                                    <Icon name="layout-dashboard" size={14} className="text-slate-500"/>
                                                    Trang quản trị
                                                </a>
                                                <button onClick={() => { setShowProfileModal(true); setProfileTab('info'); }} className="flex items-center gap-2 px-3 py-2 rounded-lg text-left font-bold text-[11px] md:text-xs text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition-all">
                                                    <Icon name="user-cog" size={14} className="text-slate-500"/>
                                                    Thông tin
                                                </button>
                                                <div className="border-t border-slate-100 my-1"></div>
                                                <button onClick={async () => {
                                                    try {
                                                        const r = await fetch('admin.php?action=logout');
                                                        const res = await r.json();
                                                        if (res.success) {
                                                            setAdminSession({ loggedIn: false });
                                                            showAlert("Đã đăng xuất!");
                                                        }
                                                    } catch(e) {
                                                        showAlert("Lỗi đăng xuất!");
                                                    }
                                                }} className="flex items-center gap-2 px-3 py-2 rounded-lg text-left font-bold text-[11px] md:text-xs text-rose-600 hover:bg-rose-50 hover:text-rose-700 transition-all">
                                                    <Icon name="log-out" size={14} className="text-rose-500"/>
                                                    Đăng xuất
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </div>

                            {showMenu && (
                                <div className="w-full mt-3 animate-in fade-in slide-in-from-bottom-5 duration-500 z-20">
                                    <div className="flex justify-between items-center max-w-5xl mx-auto px-4 mb-4">
                                        <h3 className="text-xs md:text-sm font-black text-slate-500 uppercase tracking-widest flex items-center gap-1.5">
                                            <Icon name="layers" size={14}/>
                                            Chọn khối lớp ({mainMode === 'exams' ? 'Đề thi' : (mainMode === 'docs' ? 'Tài liệu' : 'Video')})
                                        </h3>
                                        <div className="flex items-center gap-2">
                                            <button onClick={() => setGuideModalType(mainMode)} className="text-[10px] md:text-xs font-black text-indigo-600 hover:text-indigo-800 transition-colors uppercase tracking-widest flex items-center gap-1 border border-indigo-100 hover:border-indigo-200 px-3 py-1.5 rounded-xl bg-indigo-50 shadow-sm hover:bg-indigo-100/50">
                                                <Icon name="help-circle" size={12}/> Hướng dẫn
                                            </button>
                                            <button onClick={() => setShowMenu(false)} className="text-[10px] md:text-xs font-bold text-slate-400 hover:text-slate-800 transition-colors uppercase tracking-widest flex items-center gap-1 border border-slate-200 px-3 py-1.5 rounded-xl bg-white shadow-sm hover:bg-slate-50">
                                                <Icon name="x" size={12}/> Đóng
                                            </button>
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6 lg:gap-8 w-full px-2 md:px-4 max-w-6xl mx-auto">
                                        {[
                                            { id: 'tot_nghiep', label: 'TN THPT', desc: 'Ôn thi THPT Quốc Gia', color: 'from-rose-400 to-red-500', shape: 'shape-circle', dropShadow: 'rgba(244,63,94,0.3)', bgChar: 'T', fgChar: 'N' },
                                            { id: '12', label: 'KHỐI 12', desc: 'Chương trình lớp 12', color: 'from-emerald-400 to-teal-500', shape: 'shape-square', dropShadow: 'rgba(16,185,129,0.3)', bgChar: 'K', fgChar: '12' },
                                            { id: '11', label: 'KHỐI 11', desc: 'Chương trình GDPT mới', color: 'from-indigo-400 to-blue-500', shape: 'shape-circle', dropShadow: 'rgba(99,102,241,0.3)', bgChar: 'K', fgChar: '11' },
                                            { id: '10', label: 'KHỐI 10', desc: 'Xây dựng nền tảng', color: 'from-orange-400 to-amber-500', shape: 'shape-square', dropShadow: 'rgba(249,115,22,0.3)', bgChar: 'K', fgChar: '10' },
                                        ].map(g => (
                                            <button 
                                                key={g.id} 
                                                onClick={() => { 
                                                    setActiveGrade(g.id); 
                                                    if (g.id === 'tot_nghiep') {
                                                        setActiveParentCategory('on_thi_tn');
                                                        setActiveChildCategory('chinh_thuc');
                                                    } else {
                                                        setActiveParentCategory('on_giua_ki');
                                                        setActiveChildCategory('ghk1');
                                                    }
                                                    if (mainMode === 'docs') {
                                                        setView('doc_list');
                                                    } else if (mainMode === 'videos') {
                                                        setView('video_list');
                                                    } else {
                                                        setView('list');
                                                    } 
                                                }} 
                                                className="group relative flex flex-col items-center justify-center p-6 md:p-8 rounded-[2rem] md:rounded-[2.5rem] bg-white border border-slate-200/80 hover:bg-slate-50 hover:border-slate-300 transition-all duration-500 hover:-translate-y-2 backdrop-blur-md overflow-visible text-center shadow-md shadow-slate-100/50 hover:shadow-lg hover:shadow-slate-200/50"
                                            >
                                                <div className={`absolute inset-0 opacity-0 group-hover:opacity-10 bg-gradient-to-br ${g.color} transition-opacity duration-500 blur-xl rounded-[2.5rem]`}></div>
                                                <div className={`shrink-0 mb-4 md:mb-6 group-hover:scale-110 transition-transform duration-500`} style={{ filter: `drop-shadow(0 15px 20px ${g.dropShadow})` }}>
                                                    <div className={`w-16 h-16 md:w-20 md:h-20 lg:w-24 lg:h-24 bg-gradient-to-br ${g.color} text-white flex items-center justify-center ${g.shape}`}>
                                                        <div className="relative flex items-center justify-center w-full h-full font-black tracking-tighter">
                                                            <span className="text-[40px] md:text-[50px] lg:text-[60px] text-white/30 absolute -translate-x-2 -translate-y-1 md:-translate-y-2">{g.bgChar}</span>
                                                            <span className="text-[28px] md:text-[34px] lg:text-[40px] text-white absolute drop-shadow-md z-10 translate-x-2 md:translate-x-3 translate-y-2 md:translate-y-3">{g.fgChar}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="flex flex-col relative z-10">
                                                    <span className={`font-black text-slate-800 mb-1 md:mb-2 uppercase tracking-widest ${g.id === 'tot_nghiep' ? 'text-xs sm:text-lg md:text-xl' : 'text-sm sm:text-xl md:text-2xl'}`}>{g.label}</span>
                                                    <span className="hidden sm:block text-[11px] md:text-sm font-medium text-slate-500 group-hover:text-slate-700 transition-colors">{g.desc}</span>
                                                </div>
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Khối thông báo chia làm 2 bảng tài liệu và video */}
                            {!showMenu && (
                                <div className="w-full max-w-5xl mt-3.5 px-4 z-20 animate-in fade-in slide-in-from-bottom-5 duration-500 text-center">
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6 lg:gap-8 w-full">
                                        
                                        {/* Bảng Thông Báo Tài Liệu Mới */}
                                        <div className="bg-white/70 border border-slate-200/80 rounded-3xl p-6 shadow-md shadow-slate-100/50 flex flex-col text-left backdrop-blur-md hover:shadow-lg transition-shadow duration-300">
                                            <div className="flex flex-col gap-3.5 mb-4 border-b border-slate-100 pb-3">
                                                <div className="flex items-center gap-2">
                                                    <div className="p-1.5 bg-indigo-500/10 text-indigo-500 rounded-lg relative shrink-0">
                                                        <span className="relative inline-flex rounded-full h-2 w-2 bg-indigo-500"></span>
                                                        <Icon name="book-open" size={16}/>
                                                    </div>
                                                    <h3 className="text-xs md:text-sm font-black text-indigo-600 uppercase tracking-widest whitespace-nowrap">Tài liệu</h3>
                                                </div>
                                                
                                                {/* Bộ lọc khối lớp trong bảng tài liệu */}
                                                <div className="flex items-center gap-1 overflow-x-auto pb-1 scrollbar-none shrink-0">
                                                    {[
                                                        { id: 'all', label: 'Tất cả' },
                                                        { id: '10', label: 'Lớp 10' },
                                                        { id: '11', label: 'Lớp 11' },
                                                        { id: '12', label: 'Lớp 12' },
                                                        { id: 'tot_nghiep', label: 'Tốt nghiệp' }
                                                    ].map(tab => (
                                                        <button
                                                            key={tab.id}
                                                            onClick={() => setDocNotifGradeFilter(tab.id)}
                                                            className={`px-2 py-1 rounded-lg text-[9px] font-black uppercase tracking-wider whitespace-nowrap transition-all duration-300 ${
                                                                docNotifGradeFilter === tab.id
                                                                    ? 'bg-indigo-600 text-white shadow-sm'
                                                                    : 'bg-slate-50 text-slate-500 hover:text-slate-800 hover:bg-slate-100 border border-slate-200/40'
                                                            }`}
                                                        >
                                                            {tab.label}
                                                        </button>
                                                    ))}
                                                </div>
                                            </div>
                                            <div className="space-y-3.5 flex-1 flex flex-col justify-between min-h-[150px]">
                                                <div className="space-y-3">
                                                    {currentDocNotifs.map((n, idx) => {
                                                        const datePart = n.createdAt ? n.createdAt.substring(0, 5) : '';
                                                        const isHot = docNotifGradeFilter === 'all'
                                                            ? (docNotifs[0] && docNotifs[0].id === n.id) || (docNotifs[1] && docNotifs[1].id === n.id)
                                                            : (docNotifs[0] && docNotifs[0].id === n.id);
                                                        return (
                                                            <div key={n.id || idx} onClick={() => handleNotificationClick(n)} className="flex gap-2.5 items-start text-xs font-medium leading-relaxed group cursor-pointer hover:bg-slate-50/50 p-1.5 rounded-xl transition-all">
                                                                <span className="text-[10px] font-bold text-indigo-500 bg-indigo-50 border border-indigo-100 px-1.5 py-0.5 rounded shrink-0">{datePart}</span>
                                                                {renderGradeOrHotBadge(n, isHot)}
                                                                <div className="flex-1 text-slate-600 group-hover:text-slate-900 transition-colors">
                                                                    <span className="font-bold">{n.title}</span>
                                                                </div>
                                                            </div>
                                                        );
                                                    })}
                                                    {docNotifs.length === 0 && (
                                                        <div className="flex flex-col justify-center items-center h-[126px] text-slate-400 font-bold text-xs">
                                                            Chưa có thông báo tài liệu nào mới.
                                                        </div>
                                                    )}
                                                    {docNotifs.length > 0 && currentDocNotifs.length < 3 && 
                                                        Array(3 - currentDocNotifs.length).fill(null).map((_, idx) => (
                                                            <div key={`doc-spacer-${idx}`} className="flex gap-2.5 items-start text-xs p-1.5 select-none pointer-events-none opacity-0">
                                                                <span className="text-[10px] px-1.5 py-0.5 shrink-0">00:00</span>
                                                                <span className="text-[9px] px-1.5 py-0.5 shrink-0">BADGE</span>
                                                                <div className="flex-1 font-bold">SPACER</div>
                                                            </div>
                                                        ))
                                                    }
                                                </div>

                                                {totalDocPages > 1 ? (
                                                    <div className="flex justify-center items-center gap-4 mt-3 pt-2 border-t border-slate-100">
                                                        <button 
                                                            onClick={() => setDocNotifPage(prev => Math.max(prev - 1, 1))} 
                                                            disabled={docNotifPage === 1}
                                                            className={`p-1 rounded-lg border transition-all ${docNotifPage === 1 ? 'text-slate-300 border-slate-100 cursor-not-allowed' : 'text-indigo-600 border-indigo-100 hover:bg-indigo-50 active:scale-95'}`}
                                                        >
                                                            <Icon name="chevron-left" size={14}/>
                                                        </button>
                                                        <span className="text-[10px] font-extrabold text-slate-500 uppercase tracking-widest">
                                                            Trang {docNotifPage} / {totalDocPages}
                                                        </span>
                                                        <button 
                                                            onClick={() => setDocNotifPage(prev => Math.min(prev + 1, totalDocPages))} 
                                                            disabled={docNotifPage === totalDocPages}
                                                            className={`p-1 rounded-lg border transition-all ${docNotifPage === totalDocPages ? 'text-slate-300 border-slate-100 cursor-not-allowed' : 'text-indigo-600 border-indigo-100 hover:bg-indigo-50 active:scale-95'}`}
                                                        >
                                                            <Icon name="chevron-right" size={14}/>
                                                        </button>
                                                    </div>
                                                ) : (
                                                    <div className="h-[38px] mt-3 pt-2 border-t border-transparent" />
                                                )}
                                            </div>
                                        </div>

                                        {/* Bảng Thông Báo Video Mới */}
                                        <div className="bg-white/70 border border-slate-200/80 rounded-3xl p-6 shadow-md shadow-slate-100/50 flex flex-col text-left backdrop-blur-md hover:shadow-lg transition-shadow duration-300">
                                            <div className="flex flex-col gap-3.5 mb-4 border-b border-slate-100 pb-3">
                                                <div className="flex items-center gap-2">
                                                    <div className="p-1.5 bg-pink-500/10 text-pink-500 rounded-lg relative shrink-0">
                                                        <span className="relative inline-flex rounded-full h-2 w-2 bg-pink-500"></span>
                                                        <Icon name="play-circle" size={16}/>
                                                    </div>
                                                    <h3 className="text-xs md:text-sm font-black text-pink-600 uppercase tracking-widest whitespace-nowrap">Video bài giảng</h3>
                                                </div>

                                                {/* Bộ lọc khối lớp trong bảng video */}
                                                <div className="flex items-center gap-1 overflow-x-auto pb-1 scrollbar-none shrink-0">
                                                    {[
                                                        { id: 'all', label: 'Tất cả' },
                                                        { id: '10', label: 'Lớp 10' },
                                                        { id: '11', label: 'Lớp 11' },
                                                        { id: '12', label: 'Lớp 12' },
                                                        { id: 'tot_nghiep', label: 'Tốt nghiệp' }
                                                    ].map(tab => (
                                                        <button
                                                            key={tab.id}
                                                            onClick={() => setVideoNotifGradeFilter(tab.id)}
                                                            className={`px-2 py-1 rounded-lg text-[9px] font-black uppercase tracking-wider whitespace-nowrap transition-all duration-300 ${
                                                                videoNotifGradeFilter === tab.id
                                                                    ? 'bg-pink-600 text-white shadow-sm'
                                                                    : 'bg-slate-50 text-slate-500 hover:text-slate-800 hover:bg-slate-100 border border-slate-200/40'
                                                            }`}
                                                        >
                                                            {tab.label}
                                                        </button>
                                                    ))}
                                                </div>
                                            </div>
                                            <div className="space-y-3.5 flex-1 flex flex-col justify-between min-h-[150px]">
                                                <div className="space-y-3">
                                                    {currentVideoNotifs.map((n, idx) => {
                                                        const datePart = n.createdAt ? n.createdAt.substring(0, 5) : '';
                                                        const isHot = videoNotifGradeFilter === 'all'
                                                            ? (videoNotifs[0] && videoNotifs[0].id === n.id) || (videoNotifs[1] && videoNotifs[1].id === n.id)
                                                            : (videoNotifs[0] && videoNotifs[0].id === n.id);
                                                        return (
                                                            <div key={n.id || idx} onClick={() => handleNotificationClick(n)} className="flex gap-2.5 items-start text-xs font-medium leading-relaxed group cursor-pointer hover:bg-slate-50/50 p-1.5 rounded-xl transition-all">
                                                                <span className="text-[10px] font-bold text-pink-500 bg-pink-50 border border-pink-100 px-1.5 py-0.5 rounded shrink-0">{datePart}</span>
                                                                {renderGradeOrHotBadge(n, isHot)}
                                                                <div className="flex-1 text-slate-600 group-hover:text-slate-900 transition-colors">
                                                                    <span className="font-bold">{n.title}</span>
                                                                </div>
                                                            </div>
                                                        );
                                                    })}
                                                    {videoNotifs.length === 0 && (
                                                        <div className="flex flex-col justify-center items-center h-[126px] text-slate-400 font-bold text-xs">
                                                            Chưa có thông báo video nào mới.
                                                        </div>
                                                    )}
                                                    {videoNotifs.length > 0 && currentVideoNotifs.length < 3 && 
                                                        Array(3 - currentVideoNotifs.length).fill(null).map((_, idx) => (
                                                            <div key={`video-spacer-${idx}`} className="flex gap-2.5 items-start text-xs p-1.5 select-none pointer-events-none opacity-0">
                                                                <span className="text-[10px] px-1.5 py-0.5 shrink-0">00:00</span>
                                                                <span className="text-[9px] px-1.5 py-0.5 shrink-0">BADGE</span>
                                                                <div className="flex-1 font-bold">SPACER</div>
                                                            </div>
                                                        ))
                                                    }
                                                </div>

                                                {totalVideoPages > 1 ? (
                                                    <div className="flex justify-center items-center gap-4 mt-3 pt-2 border-t border-slate-100">
                                                        <button 
                                                            onClick={() => setVideoNotifPage(prev => Math.max(prev - 1, 1))} 
                                                            disabled={videoNotifPage === 1}
                                                            className={`p-1 rounded-lg border transition-all ${videoNotifPage === 1 ? 'text-slate-300 border-slate-100 cursor-not-allowed' : 'text-pink-600 border-pink-100 hover:bg-pink-50 active:scale-95'}`}
                                                        >
                                                            <Icon name="chevron-left" size={14}/>
                                                        </button>
                                                        <span className="text-[10px] font-extrabold text-slate-500 uppercase tracking-widest">
                                                            Trang {videoNotifPage} / {totalVideoPages}
                                                        </span>
                                                        <button 
                                                            onClick={() => setVideoNotifPage(prev => Math.min(prev + 1, totalVideoPages))} 
                                                            disabled={videoNotifPage === totalVideoPages}
                                                            className={`p-1 rounded-lg border transition-all ${videoNotifPage === totalVideoPages ? 'text-slate-300 border-slate-100 cursor-not-allowed' : 'text-pink-600 border-pink-100 hover:bg-pink-50 active:scale-95'}`}
                                                        >
                                                            <Icon name="chevron-right" size={14}/>
                                                        </button>
                                                    </div>
                                                ) : (
                                                    <div className="h-[38px] mt-3 pt-2 border-t border-transparent" />
                                                )}
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            )}


                        </div>
                    </div>
                );

                if (view === 'list') return (
                    <div className="min-h-screen bg-white">
                        <div className="max-w-6xl xl:max-w-7xl mx-auto p-4 md:p-8 overflow-y-auto custom-scrollbar pb-20">
                            <div className="flex justify-between items-center mb-6 md:mb-8 pt-2 md:pt-0 w-full">
                                <button onClick={() => setView('landing')} className="text-white font-black text-[10px] md:text-xs uppercase tracking-widest flex items-center gap-1.5 md:gap-2 bg-rose-500 rounded-lg px-4 py-2 hover:bg-rose-600 transition-colors shadow-md">
                                    <Icon name="arrow-left" size={14}/> Quay lại trang chủ
                                </button>
                                
                                <button onClick={() => setGuideModalType('exams')} className="text-slate-600 border border-slate-200 bg-white font-black text-[10px] md:text-xs uppercase tracking-widest flex items-center gap-1.5 md:gap-2 rounded-lg px-4 py-2 hover:bg-slate-50 transition-colors shadow-sm">
                                    <Icon name="help-circle" size={14}/> Hướng dẫn sử dụng
                                </button>
                            </div>

                            <div className="bg-white p-5 md:p-8 rounded-[2rem] shadow-sm border border-slate-100 mb-6 md:mb-8">
                                <div className="flex items-center gap-3 mb-6">
                                    <h2 className="text-xl md:text-3xl font-black text-slate-800 uppercase tracking-tighter flex items-center flex-wrap gap-2 md:gap-3">
                                        Đề ôn tập
                                        <span className="bg-gradient-to-r from-emerald-400 to-teal-500 text-white px-3 py-1 md:px-4 md:py-1.5 rounded-lg md:rounded-xl text-sm md:text-lg shadow-md shadow-emerald-500/20">
                                            {activeGrade === 'tot_nghiep' ? 'TỐT NGHIỆP' : `KHỐI ${activeGrade}`}
                                        </span>
                                    </h2>
                                </div>

                                <div className="flex overflow-x-auto gap-2 md:gap-3 mb-4 pb-3 custom-scrollbar border-b-2 border-slate-50">
                                    {availableParentCategories.map(([k, v]) => (
                                        <button 
                                            key={k} 
                                            onClick={() => handleParentChange(k)} 
                                            className={`shrink-0 whitespace-nowrap px-4 py-2 md:px-6 md:py-3 rounded-full font-black text-[10px] md:text-xs tracking-widest uppercase transition-all ${activeParentCategory === k ? 'bg-gradient-to-r from-slate-800 to-slate-900 text-white shadow-lg shadow-slate-900/20' : 'bg-slate-50 text-slate-500 hover:bg-slate-100 hover:text-slate-800'}`}
                                        >
                                            {v.label}
                                        </button>
                                    ))}
                                </div>

                                <div className="flex overflow-x-auto gap-2 md:gap-3 pb-4 custom-scrollbar">
                                    {Object.entries(CATEGORY_TREE[activeParentCategory].items).map(([k, v]) => (
                                        <button 
                                            key={k} 
                                            onClick={() => setActiveChildCategory(k)} 
                                            className={`shrink-0 whitespace-nowrap px-3 py-1.5 md:px-5 md:py-2.5 rounded-xl font-bold text-[10px] md:text-xs tracking-widest uppercase transition-all border-2 ${activeChildCategory === k ? 'bg-gradient-to-r from-emerald-50 to-teal-50 text-emerald-700 border-emerald-200 shadow-sm' : 'bg-white text-slate-500 border-slate-100 hover:border-slate-300 hover:bg-slate-50 hover:text-slate-700'}`}
                                        >
                                            {v}
                                        </button>
                                    ))}
                                </div>

                                <div className="mt-2 pt-5 border-t border-slate-100">
                                    <div className="flex gap-2 md:gap-3 w-full overflow-x-auto custom-scrollbar pb-2">
                                        <button onClick={() => setActiveModeFilter('all')} className={`px-4 py-2 rounded-xl font-black text-[10px] md:text-xs tracking-widest uppercase transition-all border-2 shrink-0 ${activeModeFilter === 'all' ? 'bg-slate-800 text-white border-slate-800 shadow-md' : 'bg-white text-slate-500 border-slate-200 hover:bg-slate-50'}`}>
                                            <Icon name="layers" size={14} className="mr-1.5 inline-block"/> Tất Cả
                                        </button>
                                        <button onClick={() => setActiveModeFilter('practice')} className={`px-4 py-2 rounded-xl font-black text-[10px] md:text-xs tracking-widest uppercase transition-all border-2 shrink-0 ${activeModeFilter === 'practice' ? 'bg-emerald-500 text-white border-emerald-500 shadow-md shadow-emerald-500/30' : 'bg-white text-emerald-500 border-emerald-100 hover:bg-emerald-50'}`}>
                                            <Icon name="dumbbell" size={14} className="mr-1.5 inline-block"/> Luyện Tập
                                        </button>
                                        <button onClick={() => setActiveModeFilter('normal')} className={`px-4 py-2 rounded-xl font-black text-[10px] md:text-xs tracking-widest uppercase transition-all border-2 shrink-0 ${activeModeFilter === 'normal' ? 'bg-blue-500 text-white border-blue-500 shadow-md shadow-blue-500/30' : 'bg-white text-blue-500 border-blue-100 hover:bg-blue-50'}`}>
                                            <Icon name="file-text" size={14} className="mr-1.5 inline-block"/> Bài Tập
                                        </button>
                                        <button onClick={() => setActiveModeFilter('test')} className={`px-4 py-2 rounded-xl font-black text-[10px] md:text-xs tracking-widest uppercase transition-all border-2 shrink-0 ${activeModeFilter === 'test' ? 'bg-rose-500 text-white border-rose-500 shadow-md shadow-rose-500/30' : 'bg-white text-rose-500 border-rose-100 hover:bg-rose-50'}`}>
                                            <Icon name="shield-alert" size={14} className="mr-1.5 inline-block"/> Kiểm Tra
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-5">
                                {filteredExams.map((e, index) => {
                                    const color = GRADIENTS[index % GRADIENTS.length];
                                    const mode = e.examMode || 'normal';
                                    let modeInstruction = "";
                                    let modeBadgeColor = "";
                                    let modeBadgeText = "";
                                    let modeIcon = "file-text";
                                    
                                    if (mode === 'practice') {
                                        modeInstruction = "Xem đáp án & giải thích ngay sau mỗi câu.";
                                        modeBadgeColor = "text-emerald-600 bg-emerald-50 border-emerald-100";
                                        modeBadgeText = "Luyện tập";
                                        modeIcon = "dumbbell";
                                    } else if (mode === 'test') {
                                        modeInstruction = "Tự nộp nếu rời tab. Cần mật khẩu (nếu có).";
                                        modeBadgeColor = "text-rose-600 bg-rose-50 border-rose-100";
                                        modeBadgeText = "Kiểm tra";
                                        modeIcon = "shield-alert";
                                    } else {
                                        modeInstruction = "Tính giờ làm bài, xem kết quả sau khi nộp.";
                                        modeBadgeColor = "text-blue-600 bg-blue-50 border-blue-100";
                                        modeBadgeText = "Bài tập";
                                        modeIcon = "file-text";
                                    }

                                    return (
                                        <button key={e.id} onClick={() => { setCurrentExam(e); setView('form'); }} className="relative overflow-hidden bg-white p-4 rounded-2xl shadow-sm border border-slate-100 hover:shadow-md hover:border-slate-200 hover:-translate-y-0.5 transition-all flex items-center justify-between group text-left">
                                            <div className={`absolute left-0 top-0 bottom-0 w-1 bg-gradient-to-b ${color} opacity-80 group-hover:opacity-100`}></div>
                                            <div className="flex items-start gap-3 pl-1 overflow-hidden flex-1 mr-2">
                                                <div className={`w-10 h-10 md:w-11 md:h-11 shrink-0 rounded-xl bg-gradient-to-br ${color} text-white flex items-center justify-center shadow-sm transform group-hover:scale-105 transition-all duration-300 mt-0.5`}>
                                                    <Icon name={modeIcon} size={18} className="md:w-5 md:h-5" strokeWidth={2.5}/>
                                                </div>
                                                <div className="overflow-hidden flex-1">
                                                    <h3 className="font-extrabold text-slate-800 text-sm md:text-base group-hover:text-indigo-600 transition-colors truncate mb-1">{e.title}</h3>
                                                    
                                                    <div className="flex flex-wrap items-center gap-1.5 text-[9px] md:text-[10px] font-bold text-slate-500 uppercase">
                                                        <span className={`px-1.5 py-0.5 rounded border tracking-wider shrink-0 ${modeBadgeColor}`}>{modeBadgeText}</span>
                                                        <span className="shrink-0">•</span>
                                                        <span className="shrink-0">{mode === 'practice' ? 'TỰ DO' : (e.duration||0) + ' PHÚT'}</span>
                                                        <span className="shrink-0">•</span>
                                                        <span className="shrink-0">{(e.config?.p1||0)+(e.config?.p2||0)+(e.config?.p3||0)} CÂU</span>
                                                    </div>
                                                    
                                                    {/* Hướng dẫn làm bài ngắn gọn trên thanh đề */}
                                                    <p className="text-[10px] md:text-[11px] font-medium text-slate-400 group-hover:text-slate-500 transition-colors mt-1.5 flex items-center gap-1 italic">
                                                        <Icon name="info" size={10} className="shrink-0 text-slate-400 group-hover:text-indigo-500 transition-colors" /> {modeInstruction}
                                                    </p>
                                                </div>
                                            </div>
                                            
                                            <div className="shrink-0 flex items-center gap-1.5 pl-2 border-l border-slate-50">
                                                <span className="hidden sm:block text-[10px] font-black text-emerald-500 uppercase tracking-widest group-hover:text-emerald-600 transition-colors">VÀO LÀM</span>
                                                <div className="w-8 h-8 rounded-full bg-emerald-50 flex items-center justify-center group-hover:bg-emerald-500 group-hover:text-white text-emerald-500 transition-colors shadow-sm shrink-0">
                                                    <Icon name="arrow-right" size={14} />
                                                </div>
                                            </div>
                                        </button>
                                    )
                                })}
                                {filteredExams.length === 0 && (
                                    <div className="col-span-full flex flex-col items-center justify-center py-16 md:py-24 bg-white rounded-[2rem] border border-slate-100 border-dashed">
                                        <div className="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center text-slate-300 mb-4"><Icon name="folder-open" size={32}/></div>
                                        <p className="text-slate-400 font-bold text-sm md:text-base">Chưa có đề thi nào trong danh mục này.</p>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                );

                if (view === 'doc_list') return (
                    <div className="min-h-screen bg-white">
                        <div className="max-w-6xl xl:max-w-7xl mx-auto p-4 md:p-8 overflow-y-auto custom-scrollbar pb-20">
                            <div className="flex justify-between items-center mb-6 md:mb-8 pt-2 md:pt-0 w-full">
                                <button onClick={() => setView('landing')} className="text-white font-black text-[10px] md:text-xs uppercase tracking-widest flex items-center gap-1.5 md:gap-2 bg-rose-500 rounded-lg px-4 py-2 hover:bg-rose-600 transition-colors shadow-md">
                                    <Icon name="arrow-left" size={14}/> Quay lại trang chủ
                                </button>
                                
                                <button onClick={() => setGuideModalType('docs')} className="text-slate-600 border border-slate-200 bg-white font-black text-[10px] md:text-xs uppercase tracking-widest flex items-center gap-1.5 md:gap-2 rounded-lg px-4 py-2 hover:bg-slate-50 transition-colors shadow-sm">
                                    <Icon name="help-circle" size={14}/> Hướng dẫn sử dụng
                                </button>
                            </div>

                            <div className="bg-white p-5 md:p-8 rounded-[2rem] shadow-sm border border-slate-100 mb-6 md:mb-8">
                                <div className="flex items-center gap-3 mb-6">
                                    <div className="w-10 h-10 md:w-12 md:h-12 bg-gradient-to-br from-indigo-500 to-purple-500 rounded-xl flex items-center justify-center text-white shadow-lg">
                                        <Icon name="folder-open" size={24} />
                                    </div>
                                    <h2 className="text-xl md:text-3xl font-black text-slate-800 uppercase tracking-tighter flex items-center flex-wrap gap-2 md:gap-3">
                                        Tài Liệu Ôn Tập
                                        <span className="bg-gradient-to-r from-indigo-400 to-purple-500 text-white px-3 py-1 md:px-4 md:py-1.5 rounded-lg md:rounded-xl text-sm md:text-lg shadow-md shadow-indigo-500/20">
                                            {activeGrade === 'tot_nghiep' ? 'TỐT NGHIỆP' : `KHỐI ${activeGrade}`}
                                        </span>
                                    </h2>
                                </div>

                                <div className="flex overflow-x-auto gap-2 md:gap-3 mb-4 pb-3 custom-scrollbar border-b-2 border-slate-50">
                                    {availableParentCategories.map(([k, v]) => (
                                        <button key={k} onClick={() => handleParentChange(k)} className={`shrink-0 whitespace-nowrap px-4 py-2 md:px-6 md:py-3 rounded-full font-black text-[10px] md:text-xs tracking-widest uppercase transition-all ${activeParentCategory === k ? 'bg-gradient-to-r from-slate-800 to-slate-900 text-white shadow-lg shadow-slate-900/20' : 'bg-slate-50 text-slate-500 hover:bg-slate-100 hover:text-slate-800'}`}>
                                            {v.label}
                                        </button>
                                    ))}
                                </div>

                                <div className="flex overflow-x-auto gap-2 md:gap-3 pb-4 custom-scrollbar border-b border-slate-100">
                                    {Object.entries(CATEGORY_TREE[activeParentCategory].items).map(([k, v]) => (
                                        <button key={k} onClick={() => setActiveChildCategory(k)} className={`shrink-0 whitespace-nowrap px-3 py-1.5 md:px-5 md:py-2.5 rounded-xl font-bold text-[10px] md:text-xs tracking-widest uppercase transition-all border-2 ${activeChildCategory === k ? 'bg-gradient-to-r from-indigo-50 to-purple-50 text-indigo-700 border-indigo-200 shadow-sm' : 'bg-white text-slate-500 border-slate-100 hover:border-slate-300 hover:bg-slate-50 hover:text-slate-700'}`}>
                                            {v}
                                        </button>
                                    ))}
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 md:gap-5 mt-6">
    {documents.filter(doc => (doc.grade || '12') === activeGrade && doc.category === activeChildCategory)
        .sort((a, b) => {
            // Lấy dãy số timestamp từ ID (ví dụ 'doc_1715421234' -> 1715421234)
            const timeA = parseInt(a.id.replace('doc_', '')) || 0;
            const timeB = parseInt(b.id.replace('doc_', '')) || 0;
            return timeB - timeA; // Sắp xếp giảm dần (Mới nhất lên đầu)
        })
        .map(doc => {
            const isNew = newestDocIds.includes(doc.id);
            return (
                                        <div key={doc.id} className="bg-white p-4 md:p-5 rounded-2xl shadow-sm border border-slate-100 hover:shadow-lg hover:border-indigo-200 hover:-translate-y-1 transition-all flex flex-col justify-between group relative mt-2">
                                            {isNew && (
                                                <div className="absolute -top-3 -right-2 bg-rose-500 text-white text-[9px] font-black uppercase px-2.5 py-1 rounded-full shadow-lg border-2 border-white animate-pop-badge z-10 flex items-center gap-1">
                                                    <Icon name="sparkles" size={10}/> MỚI
                                                </div>
                                            )}
                                            <div className="flex items-start gap-3 mb-4 cursor-pointer" onClick={() => setSelectedPdf(doc.filePath)}>
                                                <div className="p-3 bg-rose-50 text-rose-500 rounded-xl shrink-0 group-hover:bg-rose-500 group-hover:text-white transition-colors"><Icon name="file-text" size={24}/></div>
                                                <div className="min-w-0">
                                                    <h3 className="font-black text-slate-800 text-sm md:text-base truncate group-hover:text-indigo-600 transition-colors" title={doc.title}>{doc.title}</h3>
                                                    <div className="flex items-center gap-2 mt-1.5">
                                                        <span className="bg-indigo-50 text-indigo-600 px-2 py-0.5 rounded text-[9px] md:text-[10px] font-black uppercase tracking-widest">{doc.size}</span>
                                                        <span className="text-[10px] font-bold text-slate-400">{doc.createdAt}</span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="border-t border-slate-100 pt-3">
                                                <a href={doc.filePath} download className="flex items-center justify-center gap-1.5 bg-emerald-50 text-emerald-600 py-2.5 rounded-xl text-[10px] md:text-xs font-black uppercase tracking-widest hover:bg-emerald-600 hover:text-white transition-colors shadow-sm border border-emerald-100 w-full">
                                                    <Icon name="download" size={14}/> Tải Về
                                                </a>
                                            </div>
                                        </div>
                                    )})}
                                    {documents.filter(doc => (doc.grade || '12') === activeGrade && doc.category === activeChildCategory).length === 0 && (
                                        <div className="col-span-full py-12 text-center flex flex-col items-center justify-center text-slate-400">
                                            <Icon name="file-x" size={40} className="mb-3 opacity-30"/>
                                            <p className="font-bold text-sm">Giáo viên chưa cập nhật tài liệu cho phần này.</p>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                );

                if (view === 'video_list') return (
                    <div className="min-h-screen bg-white">
                        <div className="max-w-6xl xl:max-w-7xl mx-auto p-4 md:p-8 overflow-y-auto custom-scrollbar pb-20">
                            <div className="flex justify-between items-center mb-6 md:mb-8 pt-2 md:pt-0 w-full">
                                <button onClick={() => setView('landing')} className="text-white font-black text-[10px] md:text-xs uppercase tracking-widest flex items-center gap-1.5 md:gap-2 bg-rose-500 rounded-lg px-4 py-2 hover:bg-rose-600 transition-colors shadow-md">
                                    <Icon name="arrow-left" size={14}/> Quay lại trang chủ
                                </button>
                                
                                <button onClick={() => setGuideModalType('videos')} className="text-slate-600 border border-slate-200 bg-white font-black text-[10px] md:text-xs uppercase tracking-widest flex items-center gap-1.5 md:gap-2 rounded-lg px-4 py-2 hover:bg-slate-50 transition-colors shadow-sm">
                                    <Icon name="help-circle" size={14}/> Hướng dẫn sử dụng
                                </button>
                            </div>

                            <div className="bg-white p-5 md:p-8 rounded-[2rem] shadow-sm border border-slate-100 mb-6 md:mb-8">
                                <div className="flex items-center gap-3 mb-6">
                                    <div className="w-10 h-10 md:w-12 md:h-12 bg-gradient-to-br from-pink-500 to-rose-500 rounded-xl flex items-center justify-center text-white shadow-lg">
                                        <Icon name="play-circle" size={24} />
                                    </div>
                                    <h2 className="text-xl md:text-3xl font-black text-slate-800 uppercase tracking-tighter flex items-center flex-wrap gap-2 md:gap-3">
                                        Video Bài Giảng
                                        <span className="bg-gradient-to-r from-pink-400 to-rose-500 text-white px-3 py-1 md:px-4 md:py-1.5 rounded-lg md:rounded-xl text-sm md:text-lg shadow-md shadow-pink-500/20">
                                            {activeGrade === 'tot_nghiep' ? 'TỐT NGHIỆP' : `KHỐI ${activeGrade}`}
                                        </span>
                                    </h2>
                                </div>

                                <div className="flex overflow-x-auto gap-2 md:gap-3 mb-4 pb-3 custom-scrollbar border-b-2 border-slate-50">
                                    {availableParentCategories.map(([k, v]) => (
                                        <button key={k} onClick={() => handleParentChange(k)} className={`shrink-0 whitespace-nowrap px-4 py-2 md:px-6 md:py-3 rounded-full font-black text-[10px] md:text-xs tracking-widest uppercase transition-all ${activeParentCategory === k ? 'bg-gradient-to-r from-slate-800 to-slate-900 text-white shadow-lg shadow-slate-900/20' : 'bg-slate-50 text-slate-500 hover:bg-slate-100 hover:text-slate-800'}`}>
                                            {v.label}
                                        </button>
                                    ))}
                                </div>

                                <div className="flex overflow-x-auto gap-2 md:gap-3 pb-4 custom-scrollbar border-b border-slate-100">
                                    {Object.entries(CATEGORY_TREE[activeParentCategory].items).map(([k, v]) => (
                                        <button key={k} onClick={() => setActiveChildCategory(k)} className={`shrink-0 whitespace-nowrap px-3 py-1.5 md:px-5 md:py-2.5 rounded-xl font-bold text-[10px] md:text-xs tracking-widest uppercase transition-all border-2 ${activeChildCategory === k ? 'bg-gradient-to-r from-pink-50 to-rose-50 text-pink-700 border-pink-200 shadow-sm' : 'bg-white text-slate-500 border-slate-100 hover:border-slate-300 hover:bg-slate-50 hover:text-slate-700'}`}>
                                            {v}
                                        </button>
                                    ))}
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 md:gap-5 mt-6">
                                    {videos.filter(vid => (vid.grade || '12') === activeGrade && vid.category === activeChildCategory && vid.isPublished !== false)
                                        .sort((a, b) => {
                                            const timeA = parseInt(a.id.replace('vid_', '')) || 0;
                                            const timeB = parseInt(b.id.replace('vid_', '')) || 0;
                                            return timeB - timeA;
                                        })
                                        .map(vid => {
                                            const getYoutubeId = (url) => {
                                                const regExp = /^.*(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=|\&v=)([^#\&\?]*).*/;
                                                const match = url.match(regExp);
                                                return (match && match[2].length === 11) ? match[2] : null;
                                            };
                                            const vidId = getYoutubeId(vid.videoUrl);
                                            const thumbUrl = vidId ? `https://img.youtube.com/vi/${vidId}/mqdefault.jpg` : null;

                                            return (
                                                <div key={vid.id} className="bg-white rounded-2xl shadow-sm border border-slate-100 hover:shadow-lg hover:border-pink-200 hover:-translate-y-1 transition-all overflow-hidden flex flex-col group relative">
                                                    <div className="relative aspect-video bg-slate-900 cursor-pointer overflow-hidden shrink-0" onClick={() => setSelectedVideo(vid)}>
                                                        {thumbUrl ? (
                                                            <img src={thumbUrl} alt={vid.title} className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"/>
                                                        ) : (
                                                            <div className="w-full h-full flex items-center justify-center text-slate-500"><Icon name="video" size={32}/></div>
                                                        )}
                                                        <div className="absolute inset-0 bg-slate-950/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                                                            <div className="w-12 h-12 rounded-full bg-pink-600 text-white flex items-center justify-center shadow-lg transform scale-90 group-hover:scale-100 transition-transform duration-300">
                                                                <Icon name="play" size={20} fill="currentColor"/>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <div className="p-4 md:p-5 flex-1 flex flex-col justify-between">
                                                        <div className="mb-4">
                                                            <h3 onClick={() => setSelectedVideo(vid)} className="font-black text-slate-800 text-sm md:text-base line-clamp-2 hover:text-pink-600 transition-colors cursor-pointer" title={vid.title}>{vid.title}</h3>
                                                            <p className="text-[10px] font-bold text-slate-400 mt-2 flex items-center gap-1"><Icon name="calendar" size={10}/> {vid.createdAt}</p>
                                                        </div>
                                                        <div className="border-t border-slate-100 pt-3">
                                                            <a href={vid.videoUrl} target="_blank" rel="noopener noreferrer" className="flex items-center justify-center gap-1.5 bg-slate-50 border border-slate-200 text-slate-600 py-2.5 rounded-xl text-[10px] md:text-xs font-black uppercase tracking-widest hover:bg-slate-200 transition-colors w-full">
                                                                <Icon name="external-link" size={14}/> Xem trên YouTube
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    {videos.filter(vid => (vid.grade || '12') === activeGrade && vid.category === activeChildCategory && vid.isPublished !== false).length === 0 && (
                                        <div className="col-span-full py-12 text-center flex flex-col items-center justify-center text-slate-400">
                                            <Icon name="video-off" size={40} className="mb-3 opacity-30"/>
                                            <p className="font-bold text-sm">Giáo viên chưa cập nhật video bài giảng cho phần này.</p>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                );


                if (view === 'form') return (
                    <div className="min-h-screen flex items-center justify-center p-4 md:p-6 bg-slate-50 relative overflow-hidden font-sans">
                        <div className="absolute top-[-20%] right-[-10%] w-[70vw] h-[70vw] max-w-[600px] max-h-[600px] bg-indigo-100 rounded-full blur-[100px] animate-pulse" style={{animationDuration: '12s'}}></div>
                        <div className="absolute bottom-[-20%] left-[-10%] w-[70vw] h-[70vw] max-w-[600px] max-h-[600px] bg-emerald-100 rounded-full blur-[100px] animate-pulse" style={{animationDuration: '10s', animationDelay: '2s'}}></div>


                        <div className="relative z-10 bg-white p-6 md:p-8 rounded-[2.5rem] md:rounded-[3rem] shadow-2xl max-w-3xl w-full border border-slate-100">
                            <button onClick={() => setView('list')} className="absolute top-5 right-5 w-9 h-9 flex items-center justify-center bg-slate-100 text-slate-600 hover:bg-slate-200 rounded-full transition-colors shadow-sm z-20"><Icon name="x" size={18}/></button>
                            
                            <div className="grid grid-cols-1 md:grid-cols-12 gap-6 md:gap-8 items-center">
                                {/* Cột trái: Thông tin bài thi */}
                                <div className="md:col-span-5 flex flex-col items-center text-center border-b md:border-b-0 md:border-r border-slate-100 pb-5 md:pb-0 md:pr-6">
                                    <button 
                                        type="button"
                                        onClick={openExamInstructions} 
                                        title="Bấm để xem hướng dẫn làm bài thi" 
                                        className="w-16 h-16 md:w-20 md:h-20 bg-gradient-to-br from-emerald-500 to-teal-500 text-white rounded-2xl flex items-center justify-center mb-3.5 shadow-[0_10px_25px_rgba(16,185,129,0.25)] hover:scale-105 active:scale-95 hover:rotate-3 transition-all duration-200 cursor-pointer relative group/btn"
                                    >
                                        <Icon name="badge-check" size={32} className="md:w-10 md:h-10 transition-transform group-hover/btn:scale-110"/>
                                        <span className="absolute -bottom-1 -right-1 w-5 h-5 bg-white text-emerald-600 rounded-full flex items-center justify-center shadow-md text-[10px] font-black border border-emerald-100">
                                            <Icon name="info" size={11} strokeWidth={3}/>
                                        </span>
                                    </button>
                                    
                                    <h2 className="text-xl md:text-2xl font-black text-slate-800 uppercase tracking-tight mb-1">Thẻ Dự Thi</h2>
                                    <p className="text-emerald-600 font-extrabold text-sm leading-snug line-clamp-2 mb-3.5 px-2" title={currentExam?.title}>{currentExam?.title}</p>
                                    
                                    <div className="w-full max-w-[260px] flex flex-col gap-2">
                                        <div className="grid grid-cols-2 gap-2">
                                            <div className="bg-slate-50 border border-slate-100 text-slate-600 py-1.5 px-2 rounded-xl text-[10px] font-black uppercase tracking-wider flex items-center justify-center gap-1.5 shadow-sm">
                                                <Icon name="clock" size={12} className="text-slate-400"/> {currentExam?.examMode === 'practice' ? 'Tự Do' : (currentExam?.duration||0) + 'P'}
                                            </div>
                                            <div className="bg-slate-50 border border-slate-100 text-slate-600 py-1.5 px-2 rounded-xl text-[10px] font-black uppercase tracking-wider flex items-center justify-center gap-1.5 shadow-sm">
                                                <Icon name="list-checks" size={12} className="text-slate-400"/> {(currentExam?.config?.p1||0) + (currentExam?.config?.p2||0) + (currentExam?.config?.p3||0)} Câu
                                            </div>
                                        </div>

                                        <div className="bg-rose-50/80 border border-rose-100 text-rose-600 py-1.5 px-3 rounded-xl text-[10px] font-black uppercase tracking-wider flex items-center justify-center gap-1.5 shadow-sm">
                                            <Icon name="repeat" size={12} className="text-rose-400"/> {currentExam?.maxAttempts > 0 ? `Tối đa: ${currentExam.maxAttempts} lần` : 'Thi: Tự do'}
                                        </div>
                                    </div>
                                </div>

                                {/* Cột phải: Form nhập thông tin thí sinh & Bắt đầu */}
                                <div className="md:col-span-7 flex flex-col justify-center space-y-3.5">
                                    <div className="relative group">
                                        <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <Icon name="user" size={18} className="text-slate-400 group-focus-within:text-emerald-500 transition-colors"/>
                                        </div>
                                        <input placeholder="Họ và tên thí sinh..." value={student.name} onChange={e=>setStudent({...student, name: e.target.value.toUpperCase()})} style={{textTransform: 'uppercase'}} onKeyDown={e => {if(e.key === 'Enter' && student.name && student.class) startQuiz()}} className="w-full pl-11 pr-4 py-3.5 rounded-xl border border-slate-200 bg-white text-slate-800 font-bold outline-none focus:ring-2 focus:ring-emerald-500 text-sm md:text-base transition-all placeholder:text-slate-400 placeholder:font-medium uppercase shadow-sm" />
                                    </div>
                                    <div className="relative group">
                                        <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <Icon name="users" size={18} className="text-slate-400 group-focus-within:text-teal-500 transition-colors"/>
                                        </div>
                                        <input placeholder="Lớp..." value={student.class} onChange={e=>setStudent({...student, class: e.target.value.toUpperCase()})} style={{textTransform: 'uppercase'}} onKeyDown={e => {if(e.key === 'Enter' && student.name && student.class) startQuiz()}} className="w-full pl-11 pr-4 py-3.5 rounded-xl border border-slate-200 bg-white text-slate-800 font-bold outline-none focus:ring-2 focus:ring-teal-500 text-sm md:text-base transition-all placeholder:text-slate-400 placeholder:font-medium uppercase shadow-sm" />
                                    </div>
                                    <div className="relative group">
                                        <div className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <Icon name="lock" size={18} className="text-slate-400 group-focus-within:text-blue-500 transition-colors"/>
                                        </div>
                                        <input type="password" placeholder="Mật khẩu (nếu có)..." value={student.password || ''} onChange={e=>setStudent({...student, password: e.target.value})} onKeyDown={e => {if(e.key === 'Enter' && student.name && student.class) startQuiz()}} className="w-full pl-11 pr-4 py-3.5 rounded-xl border border-slate-200 bg-white text-slate-800 font-bold outline-none focus:ring-2 focus:ring-blue-500 text-sm md:text-base transition-all placeholder:text-slate-400 placeholder:font-medium shadow-sm" />
                                    </div>

                                    <button onClick={startQuiz} disabled={!student.name || !student.class || isChecking} className="w-full bg-gradient-to-r from-emerald-500 to-teal-500 text-white py-3.5 rounded-xl font-black shadow-md shadow-emerald-500/20 disabled:opacity-40 disabled:grayscale disabled:cursor-not-allowed hover:shadow-lg hover:shadow-emerald-500/30 hover:-translate-y-0.5 transition-all uppercase tracking-widest text-xs md:text-sm flex justify-center items-center gap-2 mt-1 active:scale-[0.98]">
                                        {isChecking ? <Icon name="loader" size={18} className="animate-spin" /> : <Icon name="play" size={18} />} 
                                        {isChecking ? 'ĐANG KIỂM TRA...' : 'BẮT ĐẦU LÀM BÀI'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                );

                if (view === 'quiz' && currentExam?.examMode === 'practice') {
                    if (practiceList.length === 0) return <div className="min-h-screen flex items-center justify-center font-bold text-slate-500">Đang tải câu hỏi...</div>;
                    const currentQ = practiceList[practiceIndex];
                    
                    return (
                        <div className="min-h-screen flex flex-col bg-slate-100 relative">
                            <header className="bg-white border-b border-slate-200 px-4 md:px-8 py-3 lg:py-2.5 flex justify-between items-center shrink-0 z-20 shadow-sm">
                                <div className="flex items-center gap-3">
                                    <button onClick={() => showConfirm("Bạn có chắc chắn muốn thoát khỏi chế độ Luyện tập?", () => window.location.reload())} className="w-10 h-10 shrink-0 flex items-center justify-center bg-rose-500 text-white rounded-full hover:bg-rose-600 shadow-md"><Icon name="x" size={16}/></button>
                                    <div>
                                        <h2 className="font-black uppercase text-base text-emerald-900 tracking-tight flex items-center gap-2">Luyện tập <span className="bg-emerald-100 text-emerald-600 px-2 py-0.5 rounded-md text-[10px] tracking-widest">{currentExam.title}</span></h2>
                                        <p className="text-[11px] font-bold text-slate-500 uppercase tracking-widest">{student.name} - {student.class}</p>
                                    </div>
                                </div>
                                <Timer mode="practice" />
                            </header>
                            
                            <main className="flex-1 overflow-y-auto p-4 md:p-6 lg:p-10 custom-scrollbar pb-32 md:pb-40">
                                <div className="max-w-3xl xl:max-w-4xl mx-auto animate-in slide-in-from-right-8 duration-300" key={`p-${currentQ.part}-${currentQ.idx}`}>
                                    <div className="flex justify-between items-center mb-6">
                                        <h3 className="text-xl font-black uppercase text-slate-800">
                                            {currentQ.part === 'p1' ? 'Phần I. Trắc nghiệm' : currentQ.part === 'p2' ? 'Phần II. Đúng/Sai' : 'Phần III. Trả lời ngắn'}
                                        </h3>
                                        <span className="bg-white border-2 border-slate-200 text-slate-500 font-black px-4 py-2 rounded-xl text-sm shadow-sm">
                                            CÂU {practiceIndex + 1} / {practiceList.length}
                                        </span>
                                    </div>

                                    <div className={`q-card !border-2 transition-all duration-300 ${practiceStatus === 'correct' ? '!border-emerald-500 !bg-emerald-50/50 !shadow-[0_0_30px_rgba(16,185,129,0.15)]' : practiceStatus === 'wrong' ? '!border-rose-500 animate-shake' : '!border-white'}`}>
                                        <div className="flex flex-col sm:flex-row sm:items-start gap-4 mb-8">
                                            <span className="self-start px-5 py-2 rounded-xl font-black text-sm shrink-0 shadow-sm bg-indigo-100 text-indigo-700">CÂU {currentQ.idx}</span>
                                            <SafeHtml html={currentQ?.q?.q} className="text-[16px] font-semibold text-slate-800 mt-1 w-full" />
                                        </div>

                                        {currentQ.part === 'p1' && (
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                                {(Array.isArray(currentQ?.q?.opts) ? currentQ.q.opts : Object.values(currentQ?.q?.opts || {})).map((opt, k) => {
                                                    const char = String.fromCharCode(65+k);
                                                    const isSel = answers?.p1?.[currentQ.idx] === char;
                                                    const btnCls = isSel ? 'border-emerald-500 bg-emerald-50 shadow-md' : 'border-slate-200 bg-slate-50 hover:bg-white hover:border-emerald-300';
                                                    const charCls = isSel ? 'bg-emerald-500 text-white' : 'bg-white border-2 border-slate-200 text-slate-400';
                                                    return (
                                                        <button key={char} disabled={practiceStatus === 'correct' || practiceStatus === 'skipped'} onClick={() => handleAns('p1', currentQ.idx, char)} className={`flex items-start gap-3 md:gap-4 p-4 md:p-5 rounded-xl md:rounded-2xl border-2 text-left ${btnCls}`}>
                                                            <span className={`w-8 h-8 md:w-10 md:h-10 rounded-full flex items-center justify-center font-black shrink-0 text-xs md:text-sm ${charCls}`}>{char}</span>
                                                            <SafeHtml html={opt} className="font-medium text-sm md:text-base mt-1 md:mt-1.5" />
                                                        </button>
                                                    )
                                                })}
                                            </div>
                                        )}

                                        {currentQ.part === 'p2' && (
                                            <div className="space-y-3">
                                                {(Array.isArray(currentQ?.q?.opts) ? currentQ.q.opts : Object.values(currentQ?.q?.opts || {})).map((opt, k) => {
                                                    const sub = String.fromCharCode(97+k);
                                                    const val = answers?.p2?.[currentQ.idx]?.[sub];
                                                    return (
                                                        <div key={sub} className={`flex flex-col lg:flex-row justify-between p-4 md:p-5 rounded-xl md:rounded-2xl border-2 gap-4 lg:gap-6 lg:items-center transition-all ${val !== undefined ? 'bg-white border-teal-200 shadow-sm' : 'bg-slate-50 border-slate-100'}`}>
                                                            <div className="flex gap-3 md:gap-4 text-slate-700 flex-1">
                                                                <span className="font-black text-slate-400 text-base md:text-lg pt-1 md:pt-1.5">{sub})</span> 
                                                                <SafeHtml html={opt} className="font-medium text-sm md:text-base py-0.5 md:py-1" />
                                                            </div>
                                                            <div className="flex gap-2 md:gap-3 shrink-0 bg-slate-100 p-1 md:p-1.5 rounded-lg md:rounded-[1rem] w-full lg:w-auto mt-2 lg:mt-0">
                                                                <button disabled={practiceStatus === 'correct' || practiceStatus === 'skipped'} onClick={() => handleAns('p2', currentQ.idx, {...(answers?.p2?.[currentQ.idx]||{}), [sub]: true})} className={`flex-1 lg:w-24 py-2.5 md:py-3 rounded-md md:rounded-xl font-black text-xs border-2 transition-all ${val === true ? 'bg-white border-emerald-500 text-emerald-600 shadow-md' : 'bg-transparent border-transparent text-slate-400 hover:text-emerald-500'}`}>ĐÚNG</button>
                                                                <button disabled={practiceStatus === 'correct' || practiceStatus === 'skipped'} onClick={() => handleAns('p2', currentQ.idx, {...(answers?.p2?.[currentQ.idx]||{}), [sub]: false})} className={`flex-1 lg:w-24 py-2.5 md:py-3 rounded-md md:rounded-xl font-black text-xs border-2 transition-all ${val === false ? 'bg-white border-rose-500 text-rose-600 shadow-md' : 'bg-transparent border-transparent text-slate-400 hover:text-rose-500'}`}>SAI</button>
                                                            </div>
                                                        </div>
                                                    )
                                                })}
                                            </div>
                                        )}

                                        {currentQ.part === 'p3' && (
                                            <div className="sm:pl-[3.5rem] md:pl-[4.5rem]">
                                                <input type="text" disabled={practiceStatus === 'correct' || practiceStatus === 'skipped'} placeholder="Nhập đáp số của bạn..." className="w-full max-w-sm bg-slate-50 p-4 md:p-5 rounded-xl md:rounded-2xl border-2 border-slate-200 font-bold text-emerald-600 text-base md:text-lg outline-none focus:border-emerald-500 focus:bg-white shadow-inner uppercase transition-all placeholder:text-sm placeholder:font-medium" value={answers?.p3?.[currentQ.idx] || ''} onChange={e => handleAns('p3', currentQ.idx, e.target.value)} />
                                            </div>
                                        )}

                                        {practiceStatus === 'wrong' && (
                                            <div className="mt-6 bg-rose-100 text-rose-700 p-4 rounded-2xl border border-rose-200 font-black text-sm flex items-center justify-center gap-2 animate-pulse">
                                                <Icon name="x-circle"/> Sai rồi, bạn hãy thử lại nhé!
                                            </div>
                                        )}

                                        {practiceStatus === 'skipped' && (
                                            <div className="mt-6 bg-amber-50 text-amber-950 p-5 rounded-2xl border border-amber-200 text-left space-y-2 animate-in fade-in zoom-in-95 duration-200">
                                                <p className="font-black text-amber-800 text-xs md:text-sm uppercase tracking-wider flex items-center gap-1.5">
                                                    <Icon name="help-circle" size={16}/> ĐÃ BỎ QUA CÂU HỎI NÀY
                                                </p>
                                                <p className="text-xs md:text-[13px] font-medium text-slate-600">
                                                    Bạn đã chọn bỏ qua câu hỏi này. Dưới đây là đáp án chính xác:
                                                </p>
                                                <div className="bg-white/80 p-3 rounded-xl border border-amber-100/60 mt-1.5 space-y-1 text-xs md:text-[13px] font-bold">
                                                    {currentQ.part === 'p1' && (
                                                        <span className="text-emerald-700">Đáp án đúng: <span className="bg-emerald-50 px-2 py-0.5 rounded border border-emerald-100">{currentQ.correct}</span></span>
                                                    )}
                                                    {currentQ.part === 'p2' && (
                                                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                                            {['a', 'b', 'c', 'd'].map(sub => (
                                                                <span key={sub} className="flex items-center gap-1">
                                                                    {sub}): <span className={currentQ.correct?.[sub] ? 'text-emerald-600 bg-emerald-50 px-1.5 py-0.5 rounded' : 'text-rose-600 bg-rose-50 px-1.5 py-0.5 rounded'}>{currentQ.correct?.[sub] ? 'ĐÚNG' : 'SAI'}</span>
                                                                </span>
                                                            ))}
                                                        </div>
                                                    )}
                                                    {currentQ.part === 'p3' && (
                                                        <span className="text-emerald-700">Đáp số đúng: <span className="bg-emerald-50 px-2 py-0.5 rounded border border-emerald-100">{currentQ.correct}</span></span>
                                                    )}
                                                </div>
                                            </div>
                                        )}

                                        {(practiceStatus === 'correct' || practiceStatus === 'skipped') && currentQ?.q?.explain && (
                                            <div className="mt-8 pt-6 border-t-2 border-dashed border-emerald-200 animate-in fade-in slide-in-from-bottom-4">
                                                <div className="text-sm font-black text-emerald-600 tracking-widest mb-4 flex items-center gap-2">
                                                    <Icon name="check-circle" size={18}/> {practiceStatus === 'correct' ? 'CHÍNH XÁC! LỜI GIẢI CHI TIẾT' : 'LỜI GIẢI CHI TIẾT'}
                                                </div>
                                                <SafeHtml html={currentQ.q.explain} className="text-[15px] text-slate-700 bg-white p-6 rounded-2xl border border-emerald-100 shadow-sm" />
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </main>

                            <div className="fixed bottom-0 left-0 w-full bg-white border-t border-slate-200 p-4 md:p-6 shadow-[0_-10px_30px_rgba(0,0,0,0.05)] z-30">
                                <div className="max-w-3xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-3">
                                    <div className="text-xs font-bold text-slate-400 select-none w-full sm:w-auto text-left sm:text-right">
                                        {practiceStatus === 'typing' && (
                                            <span className="flex items-center gap-1.5 bg-slate-50 border border-slate-200/50 px-3 py-1.5 rounded-lg w-fit">
                                                <Icon name="clock" size={13} className="text-slate-400 animate-spin" style={{ animationDuration: '6s' }} />
                                                Thời gian làm câu này: {Math.floor(qTimer/60)}p {(qTimer%60).toString().padStart(2,'0')}s
                                            </span>
                                        )}
                                    </div>
                                    <div className="flex gap-3 w-full sm:w-auto justify-end">
                                        {practiceStatus !== 'correct' && practiceStatus !== 'skipped' && qTimer >= 180 && (
                                            <button 
                                                onClick={() => {
                                                    setPracticeStatus('skipped');
                                                    setShowExplain(prev => ({...prev, [`${currentQ.part}-${currentQ.idx}`]: true}));
                                                }} 
                                                className="bg-amber-500 hover:bg-amber-600 text-white px-5 md:px-7 py-3.5 md:py-4 rounded-xl md:rounded-2xl font-black uppercase tracking-widest transition-all hover:-translate-y-0.5 text-xs md:text-sm flex items-center justify-center gap-2"
                                            >
                                                <Icon name="help-circle"/> Bỏ qua câu này
                                            </button>
                                        )}

                                        {practiceStatus !== 'correct' && practiceStatus !== 'skipped' ? (
                                            <button onClick={checkPracticeAnswer} className="bg-indigo-600 text-white px-6 md:px-10 py-3.5 md:py-4 rounded-xl md:rounded-2xl font-black uppercase tracking-widest shadow-lg hover:bg-indigo-500 transition-transform hover:-translate-y-1 w-full sm:w-auto flex items-center justify-center gap-2 text-sm md:text-base">
                                                <Icon name="check-circle-2"/> Kiểm tra đáp án
                                            </button>
                                        ) : (
                                            practiceIndex < practiceList.length - 1 ? (
                                                <button onClick={() => { setPracticeIndex(p => p+1); setPracticeStatus('typing'); window.scrollTo(0,0); }} className="bg-emerald-500 text-white px-6 md:px-10 py-3.5 md:py-4 rounded-xl md:rounded-2xl font-black uppercase tracking-widest shadow-lg shadow-emerald-500/30 hover:bg-emerald-400 transition-transform hover:-translate-y-1 w-full sm:w-auto flex items-center justify-center gap-2 animate-bounce text-sm md:text-base">
                                                    Câu tiếp theo <Icon name="arrow-right"/>
                                                </button>
                                            ) : (
                                                <button onClick={() => submitRef.current(0)} className="bg-gradient-to-r from-emerald-500 to-teal-500 text-white px-6 md:px-10 py-3.5 md:py-4 rounded-xl md:rounded-2xl font-black uppercase tracking-widest shadow-lg hover:scale-105 transition-transform w-full sm:w-auto flex items-center justify-center gap-2 text-sm md:text-base">
                                                    <Icon name="flag"/> Hoàn thành bài luyện tập
                                                </button>
                                            )
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>
                    )
                }

                if (view === 'quiz' || view === 'review') {
                    const isReview = view === 'review';
                    const qsToRender = shuffledExam; 

                    if (!qsToRender) return <div className="h-screen flex items-center justify-center font-bold text-slate-400">Đang tải dữ liệu bài làm...</div>;

                    return (
                        <div className="h-screen flex flex-col bg-slate-100 relative">
                            <header className="bg-white border-b border-slate-200 px-4 md:px-8 py-3 lg:py-2.5 flex justify-between items-center shrink-0 z-20 shadow-sm">
                                <div className="flex items-center gap-3 md:gap-6 flex-1 overflow-hidden">
                                    <button onClick={() => { 
                                        if(isReview) window.location.reload(); 
                                        else showConfirm("Dữ liệu sẽ không được lưu.\nBạn có chắc chắn muốn thoát?", () => window.location.reload());
                                    }} className="w-8 h-8 md:w-10 md:h-10 shrink-0 flex items-center justify-center bg-rose-500 text-white rounded-full hover:bg-rose-600 transition-colors shadow-md"><Icon name="x" size={16} className="md:w-5 md:h-5"/></button>
                                    <div className="overflow-hidden">
                                        <h2 className="font-black uppercase text-sm md:text-base text-emerald-900 tracking-tight truncate">{currentExam?.title} {isReview && <span className="bg-emerald-100 text-emerald-600 px-2 py-0.5 rounded-md text-[8px] md:text-[10px] ml-2 tracking-widest align-middle">XEM LẠI</span>}</h2>
                                        <p className="text-[9px] md:text-[11px] font-bold text-slate-500 mt-0.5 md:mt-1 uppercase tracking-widest flex items-center gap-2 truncate">
                                            {student.name} - {student.class}
                                            {tabSwitches > 0 && <span className="bg-rose-500 text-white px-1.5 py-0.5 rounded shadow-sm tracking-widest animate-pulse ml-2">VI PHẠM: {tabSwitches}/{currentExam?.maxViolations !== undefined ? currentExam.maxViolations : 2}</span>}
                                        </p>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2 md:gap-4 shrink-0 pl-2">
                                    <span className="hidden sm:inline text-[9px] font-black text-slate-400/60 mr-2 uppercase tracking-widest select-none">
                                        &copy; Thầy Phạm Minh Tuấn
                                    </span>
                                    {!isReview && <Timer mins={currentExam?.duration} onTimeUp={() => { if(submitRef.current) submitRef.current(0); }} mode="normal" />}
                                    {!isReview && (
                                        <button onClick={() => setIsNavOpen(true)} className="p-2 md:p-3 bg-emerald-50 text-emerald-600 rounded-lg md:rounded-xl lg:hidden flex items-center shadow-sm">
                                            <Icon name="menu" size={20} className="md:w-6 md:h-6"/>
                                        </button>
                                    )}
                                </div>
                            </header>

                            <div className="flex-1 flex flex-col lg:flex-row overflow-hidden relative">
                                <div className="flex-1 overflow-y-auto p-4 md:p-6 lg:p-10 custom-scrollbar bg-white">
                                    <div className="max-w-4xl xl:max-w-5xl mx-auto pb-24 lg:pb-12 space-y-8 md:space-y-12">
                                        
                                        {(currentExam?.config?.p1||0) > 0 && qsToRender?.p1 && (
                                            <section>
                                                <div className="flex items-center gap-3 mb-6 md:mb-8">
                                                    <div className="w-2.5 h-8 md:w-3 md:h-10 bg-emerald-600 rounded-full shadow-lg shadow-emerald-200"></div>
                                                    <div>
                                                        <h3 className="font-black text-lg md:text-2xl text-slate-800 uppercase tracking-tighter">Phần I. Trắc nghiệm</h3>
                                                    </div>
                                                </div>
                                                {(Array.isArray(qsToRender.p1) ? qsToRender.p1 : Object.values(qsToRender.p1 || {})).map((q, rawIdx) => {
                                                    const qNum = rawIdx + 1;
                                                    const isC = isReview && answers?.p1?.[qNum] !== undefined && answers?.p1?.[qNum] === resultCorrect?.p1?.[qNum];
                                                    return (
                                                    <div key={`p1-${qNum}`} id={`q-p1-${qNum}`} className={`q-card overflow-visible ${answers?.p1?.[qNum] && !isReview ? 'answered' : ''} ${isReview ? (isC?'border-emerald-500 bg-emerald-50/30':'border-rose-500 bg-rose-50/30') : ''}`}>
                                                        <div className="flex flex-col sm:flex-row sm:items-start gap-3 sm:gap-5 mb-6 md:mb-8">
                                                            <span className={`self-start px-4 py-1.5 md:px-5 md:py-2 rounded-lg md:rounded-xl font-black text-xs md:text-sm shrink-0 shadow-sm ${isReview ? (isC?'bg-emerald-500 text-white':'bg-rose-500 text-white') : 'bg-emerald-100 text-emerald-700'}`}>CÂU {qNum}</span>
                                                            <SafeHtml html={q?.q} className="text-sm md:text-[16px] font-semibold text-slate-800 mt-1 w-full overflow-visible" />
                                                        </div>
                                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 md:gap-4">
                                                            {(Array.isArray(q?.opts) ? q.opts : Object.values(q?.opts || {})).map((opt, k) => {
                                                                const char = String.fromCharCode(65+k);
                                                                const isSelected = answers?.p1?.[qNum] === char;
                                                                const isCorrectAns = isReview && resultCorrect?.p1?.[qNum] === char;
                                                                
                                                                let btnClass = isSelected ? 'border-emerald-500 bg-emerald-50 shadow-md' : 'border-slate-200 bg-slate-50 hover:bg-white hover:border-emerald-300';
                                                                let charClass = isSelected ? 'bg-emerald-500 text-white' : 'bg-white border-2 border-slate-200 text-slate-400';
                                                                
                                                                if (isReview) {
                                                                    if (isCorrectAns) { btnClass = 'border-emerald-500 bg-emerald-50 text-emerald-800 shadow-md'; charClass = 'bg-emerald-500 text-white'; }
                                                                    else if (isSelected && !isC) { btnClass = 'border-rose-500 bg-rose-50 text-rose-800'; charClass = 'bg-rose-500 text-white'; }
                                                                    else { btnClass = 'border-slate-100 opacity-40'; charClass = 'bg-slate-100 text-slate-400'; }
                                                                }
                                                                return (
                                                                    <button key={char} disabled={isReview} onClick={() => handleAns('p1', qNum, char)} className={`flex items-start gap-4 md:gap-5 p-4 md:p-5 rounded-xl md:rounded-2xl border-2 transition-all text-left overflow-visible ${btnClass}`}>
                                                                        <span className={`w-8 h-8 md:w-10 md:h-10 rounded-full flex items-center justify-center font-black shrink-0 text-xs md:text-sm ${charClass}`}>{char}</span>
                                                                        <SafeHtml html={opt} className="font-medium text-sm md:text-base mt-1 md:mt-1.5 overflow-visible py-0.5 md:py-1" />
                                                                    </button>
                                                                );
                                                            })}
                                                        </div>
                                                        
                                                        {isReview && q?.explain && (
                                                            <div className="mt-4 pt-4 md:pt-5 border-t-2 border-dashed border-slate-200">
                                                                <button onClick={() => toggleExplain('p1', qNum)} className="flex items-center gap-2 text-[10px] md:text-xs font-black text-indigo-600 bg-indigo-50 px-3 py-1.5 md:px-4 md:py-2 rounded-lg hover:bg-indigo-100 transition-colors uppercase tracking-widest">
                                                                    <Icon name={showExplain[`p1-${qNum}`] ? 'eye-off' : 'eye'} size={14}/>
                                                                    {showExplain[`p1-${qNum}`] ? 'Ẩn lời giải' : 'Xem lời giải chi tiết'}
                                                                </button>
                                                                {showExplain[`p1-${qNum}`] && (
                                                                    <div className="mt-3 animate-in fade-in duration-300">
                                                                        <SafeHtml html={q.explain} className="text-xs md:text-[15px] text-slate-700 bg-emerald-50/50 p-4 md:p-5 rounded-xl md:rounded-2xl border border-emerald-100 shadow-inner overflow-visible" />
                                                                    </div>
                                                                )}
                                                            </div>
                                                        )}
                                                    </div>
                                                )})}
                                            </section>
                                        )}

                                        {(currentExam?.config?.p2||0) > 0 && qsToRender?.p2 && (
                                            <section>
                                                <div className="flex items-center gap-3 mb-6 md:mb-8">
                                                    <div className="w-2.5 h-8 md:w-3 md:h-10 bg-teal-500 rounded-full shadow-lg shadow-teal-200"></div>
                                                    <div>
                                                        <h3 className="font-black text-lg md:text-2xl text-slate-800 uppercase tracking-tighter">Phần II. Đúng / Sai</h3>
                                                    </div>
                                                </div>
                                                {(Array.isArray(qsToRender.p2) ? qsToRender.p2 : Object.values(qsToRender.p2 || {})).map((q, rawIdx) => {
                                                    const qNum = rawIdx + 1; 
                                                    const isDone = Object.keys(answers?.p2?.[qNum]||{}).length===4;
                                                    return (
                                                    <div key={`p2-${qNum}`} id={`q-p2-${qNum}`} className={`q-card overflow-visible ${isDone && !isReview ? 'answered' : ''}`}>
                                                        <div className="flex flex-col sm:flex-row sm:items-start gap-3 sm:gap-5 mb-6 md:mb-8">
                                                            <span className="self-start bg-teal-100 text-teal-700 px-4 py-1.5 md:px-5 md:py-2 rounded-lg md:rounded-xl font-black text-xs md:text-sm shrink-0 shadow-sm">CÂU {qNum}</span>
                                                            <SafeHtml html={q?.q} className="text-sm md:text-[16px] font-semibold text-slate-800 mt-1 w-full overflow-visible" />
                                                        </div>
                                                        <div className="space-y-3 overflow-visible">
                                                            {(Array.isArray(q?.opts) ? q.opts : Object.values(q?.opts || {})).map((opt, k) => {
                                                                const sub = String.fromCharCode(97+k);
                                                                const val = answers?.p2?.[qNum]?.[sub];
                                                                const cVal = isReview ? resultCorrect?.p2?.[qNum]?.[sub] : null;
                                                                const isC = isReview && val !== undefined && val === cVal;
                                                                return (
                                                                    <div key={sub} className={`flex flex-col lg:flex-row justify-between p-4 md:p-5 rounded-xl md:rounded-2xl border-2 gap-4 lg:gap-6 lg:items-center transition-all overflow-visible ${isReview ? (isC?'bg-emerald-50 border-emerald-200':'bg-rose-50 border-rose-200') : (val !== undefined ? 'bg-white border-teal-200 shadow-sm' : 'bg-slate-50 border-slate-100')}`}>
                                                                        <div className="flex gap-3 md:gap-4 text-slate-700 flex-1 overflow-visible">
                                                                            <span className="font-black text-slate-400 text-base md:text-lg pt-1 md:pt-1.5">{sub})</span> 
                                                                            <SafeHtml html={opt} className="font-medium text-sm md:text-base overflow-visible py-0.5 md:py-1" />
                                                                        </div>
                                                                        <div className="flex gap-2 md:gap-3 shrink-0 bg-slate-100 p-1 md:p-1.5 rounded-lg md:rounded-[1rem] w-full lg:w-auto mt-2 lg:mt-0">
                                                                            <button disabled={isReview} onClick={() => handleAns('p2', qNum, {...(answers?.p2?.[qNum]||{}), [sub]: true})} className={`flex-1 lg:w-24 py-2.5 md:py-3 rounded-md md:rounded-xl font-black text-xs border-2 transition-all ${val === true ? 'bg-white border-emerald-500 text-emerald-600 shadow-md' : 'bg-transparent border-transparent text-slate-400 hover:text-emerald-500'} ${isReview && cVal===true?'!bg-emerald-500 !border-emerald-500 !text-white':''} ${isReview && cVal!==true && val===true?'!bg-rose-500 !border-rose-500 !text-white':''}`}>ĐÚNG</button>
                                                                            <button disabled={isReview} onClick={() => handleAns('p2', qNum, {...(answers?.p2?.[qNum]||{}), [sub]: false})} className={`flex-1 lg:w-24 py-2.5 md:py-3 rounded-md md:rounded-xl font-black text-xs border-2 transition-all ${val === false ? 'bg-white border-rose-500 text-rose-600 shadow-md' : 'bg-transparent border-transparent text-slate-400 hover:text-rose-500'} ${isReview && cVal===false?'!bg-emerald-500 !border-emerald-500 !text-white':''} ${isReview && cVal!==false && val===false?'!bg-rose-500 !border-rose-500 !text-white':''}`}>SAI</button>
                                                                        </div>
                                                                    </div>
                                                                )
                                                            })}
                                                        </div>
                                                        
                                                        {isReview && q?.explain && (
                                                            <div className="mt-4 pt-4 md:pt-5 border-t-2 border-dashed border-slate-200">
                                                                <button onClick={() => toggleExplain('p2', qNum)} className="flex items-center gap-2 text-[10px] md:text-xs font-black text-indigo-600 bg-indigo-50 px-3 py-1.5 md:px-4 md:py-2 rounded-lg hover:bg-indigo-100 transition-colors uppercase tracking-widest">
                                                                    <Icon name={showExplain[`p2-${qNum}`] ? 'eye-off' : 'eye'} size={14}/>
                                                                    {showExplain[`p2-${qNum}`] ? 'Ẩn lời giải' : 'Xem lời giải chi tiết'}
                                                                </button>
                                                                {showExplain[`p2-${qNum}`] && (
                                                                    <div className="mt-3 animate-in fade-in duration-300">
                                                                        <SafeHtml html={q.explain} className="text-xs md:text-[15px] text-slate-700 bg-emerald-50/50 p-4 md:p-5 rounded-xl md:rounded-2xl border border-emerald-100 shadow-inner overflow-visible" />
                                                                    </div>
                                                                )}
                                                            </div>
                                                        )}
                                                    </div>
                                                )})}
                                            </section>
                                        )}

                                        {(currentExam?.config?.p3||0) > 0 && qsToRender?.p3 && (
                                            <section>
                                                <div className="flex items-center gap-3 mb-6 md:mb-8">
                                                    <div className="w-2.5 h-8 md:w-3 md:h-10 bg-orange-500 rounded-full shadow-lg shadow-orange-200"></div>
                                                    <div>
                                                        <h3 className="font-black text-lg md:text-2xl text-slate-800 uppercase tracking-tighter">Phần III. Trả lời ngắn</h3>
                                                    </div>
                                                </div>
                                                {(Array.isArray(qsToRender.p3) ? qsToRender.p3 : Object.values(qsToRender.p3 || {})).map((q, rawIdx) => {
                                                    const qNum = rawIdx + 1;
                                                    let uA = (answers?.p3?.[qNum]||'').trim().toLowerCase().replace(/[ ,$]/g, '').replace(',', '.');
                                                    let cA = isReview ? (resultCorrect?.p3?.[qNum]||'').trim().toLowerCase().replace(/[ ,$]/g, '').replace(',', '.') : '';
                                                    const isC = isReview && uA !== '' && uA === cA;
                                                    return (
                                                    <div key={`p3-${qNum}`} id={`q-p3-${qNum}`} className={`q-card overflow-visible ${answers?.p3?.[qNum] && !isReview ? 'answered' : ''} ${isReview ? (isC?'border-emerald-500 bg-emerald-50/30':'border-rose-500 bg-rose-50/30') : ''}`}>
                                                        <div className="flex flex-col sm:flex-row sm:items-start gap-3 sm:gap-5 mb-6 md:mb-8">
                                                            <span className={`self-start px-4 py-1.5 md:px-5 md:py-2 rounded-lg md:rounded-xl font-black text-xs md:text-sm shrink-0 shadow-sm ${isReview ? (isC?'bg-emerald-500 text-white':'bg-rose-500 text-white') : 'bg-orange-100 text-orange-700'}`}>CÂU {qNum}</span>
                                                            <SafeHtml html={q?.q} className="text-sm md:text-[16px] font-semibold text-slate-800 mt-1 w-full overflow-visible" />
                                                        </div>
                                                        <div className="sm:pl-[4.5rem] md:pl-[5.5rem]">
                                                            <input type="text" disabled={isReview} placeholder="Nhập đáp số của bạn vào đây..." className="w-full max-w-xs md:max-w-sm bg-slate-50 p-4 md:p-5 rounded-xl md:rounded-2xl border-2 border-slate-200 font-bold text-emerald-600 text-base md:text-lg outline-none focus:border-emerald-500 focus:bg-white transition-all shadow-inner uppercase placeholder:text-xs md:placeholder:text-sm placeholder:font-medium" value={answers?.p3?.[qNum] || ''} onChange={e => handleAns('p3', qNum, e.target.value)} />
                                                        </div>

                                                        {isReview && (!isC || q?.explain) && (
                                                            <div className="mt-4 pt-4 md:pt-5 border-t-2 border-dashed border-slate-200 sm:ml-[4.5rem] md:ml-[5.5rem]">
                                                                <button onClick={() => toggleExplain('p3', qNum)} className="flex items-center gap-2 text-[10px] md:text-xs font-black text-indigo-600 bg-indigo-50 px-3 py-1.5 md:px-4 md:py-2 rounded-lg hover:bg-indigo-100 transition-colors uppercase tracking-widest">
                                                                    <Icon name={showExplain[`p3-${qNum}`] ? 'eye-off' : 'eye'} size={14}/>
                                                                    {showExplain[`p3-${qNum}`] ? 'Ẩn đáp án chi tiết' : 'Xem đáp án chi tiết'}
                                                                </button>
                                                                {showExplain[`p3-${qNum}`] && (
                                                                    <div className="mt-3 animate-in fade-in duration-300 space-y-3">
                                                                        {!isC && resultCorrect?.p3?.[qNum] && <div className="bg-rose-100 text-rose-700 px-3 py-2 md:px-4 md:py-2.5 rounded-lg md:rounded-xl font-black text-xs md:text-sm inline-block shadow-sm border border-rose-200">ĐÁP ÁN CHUẨN: {resultCorrect.p3[qNum]}</div>}
                                                                        {q.explain && <SafeHtml html={q.explain} className="text-xs md:text-[15px] text-slate-700 bg-emerald-50/50 p-4 md:p-5 rounded-xl md:rounded-2xl border border-emerald-100 shadow-inner overflow-visible" />}
                                                                    </div>
                                                                )}
                                                            </div>
                                                        )}
                                                    </div>
                                                )})}
                                            </section>
                                        )}
                                    </div>
                                </div>

                                {/* MOBILE MENU OVERLAY */}
                                {isNavOpen && !isReview && (
                                    <div className="fixed inset-0 bg-slate-900/60 z-30 lg:hidden backdrop-blur-sm" onClick={() => setIsNavOpen(false)}></div>
                                )}

                                {/* SIDEBAR ĐIỀU HƯỚNG MÀN HÌNH LỚN HƠN MỘT CHÚT CHO 14 INCH */}
                                {!isReview && (
                                    <div className={`fixed inset-y-0 right-0 z-40 w-[80vw] max-w-[320px] bg-white flex flex-col shadow-2xl transition-transform duration-300 ease-in-out lg:relative lg:translate-x-0 lg:w-[320px] xl:w-[340px] lg:shadow-[-20px_0_40px_rgba(0,0,0,0.03)] lg:border-l border-slate-200 shrink-0 ${isNavOpen ? 'translate-x-0' : 'translate-x-full'}`}>
                                        <div className="p-5 lg:p-6 border-b bg-slate-50 flex flex-col items-center justify-center text-center shrink-0 relative">
                                            <button onClick={() => setIsNavOpen(false)} className="absolute top-4 right-4 p-2 bg-slate-200 text-slate-600 rounded-full lg:hidden hover:bg-rose-500 hover:text-white transition-colors"><Icon name="x" size={16}/></button>
                                            <div className="w-20 h-20 lg:w-20 lg:h-20 xl:w-24 xl:h-24 rounded-full border-[6px] md:border-8 border-slate-200 flex items-center justify-center relative mb-3 md:mb-4">
                                                <svg className="absolute inset-0 w-full h-full transform -rotate-90">
                                                    <circle cx="50%" cy="50%" r="42%" fill="none" stroke="#10b981" strokeWidth="8%" strokeDasharray="264" strokeDashoffset={264 - (264 * stats.done / stats.total)} className="transition-all duration-1000 ease-out" />
                                                </svg>
                                                <div className="text-xl md:text-2xl font-black text-emerald-900 tracking-tighter">{stats.done}<span className="text-sm md:text-base text-slate-400">/{stats.total}</span></div>
                                            </div>
                                            <div className="text-[10px] md:text-xs font-black uppercase tracking-widest text-slate-500">Tiến độ làm bài</div>
                                        </div>
                                        <div className="flex-1 overflow-y-auto p-5 md:p-6 custom-scrollbar space-y-6 md:space-y-8">
                                            {(currentExam?.config?.p1||0) > 0 && (
                                                <div>
                                                    <div className="text-[10px] md:text-[11px] font-black text-slate-800 uppercase tracking-widest mb-3 border-b pb-2">Phần I</div>
                                                    <div className="flex flex-wrap gap-2">{Array.from({length: currentExam?.config?.p1||0}, (_, i) => i+1).map(n => <button key={n} onClick={()=>scrollTo(`q-p1-${n}`)} className={`nav-btn ${answers?.p1?.[n] ? 'done' : ''}`}>{n}</button>)}</div>
                                                </div>
                                            )}
                                            {(currentExam?.config?.p2||0) > 0 && (
                                                <div>
                                                    <div className="text-[10px] md:text-[11px] font-black text-slate-800 uppercase tracking-widest mb-3 border-b pb-2">Phần II</div>
                                                    <div className="flex flex-wrap gap-2">{Array.from({length: currentExam?.config?.p2||0}, (_, i) => i+1).map(n => <button key={n} onClick={()=>scrollTo(`q-p2-${n}`)} className={`nav-btn ${Object.keys(answers?.p2?.[n]||{}).length===4 ? 'done' : ''}`}>{n}</button>)}</div>
                                                </div>
                                            )}
                                            {(currentExam?.config?.p3||0) > 0 && (
                                                <div>
                                                    <div className="text-[10px] md:text-[11px] font-black text-slate-800 uppercase tracking-widest mb-3 border-b pb-2">Phần III</div>
                                                    <div className="flex flex-wrap gap-2">{Array.from({length: currentExam?.config?.p3||0}, (_, i) => i+1).map(n => <button key={n} onClick={()=>scrollTo(`q-p3-${n}`)} className={`nav-btn ${answers?.p3?.[n] && answers.p3[n].trim()!=='' ? 'done' : ''}`}>{n}</button>)}</div>
                                                </div>
                                            )}
                                        </div>
                                        
                                        <div className="p-4 md:p-6 border-t bg-white shrink-0">
                                            <button onClick={handleSubmitClick} disabled={isSubmitting} className="w-full py-4 md:py-5 bg-slate-900 text-white rounded-xl md:rounded-2xl font-black uppercase tracking-widest shadow-xl shadow-slate-300 hover:bg-black hover:scale-[1.02] transition-all text-sm md:text-base flex justify-center items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                                                {isSubmitting ? <Icon name="loader" size={18} className="animate-spin" /> : <Icon name="send" size={18} />}
                                                NỘP BÀI NGAY
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </div>
                            
                            {/* NÚT GỌI MENU NỔI TRÊN MOBILE */}
                            {!isReview && !isNavOpen && (
                                <button onClick={() => setIsNavOpen(true)} className="lg:hidden fixed bottom-6 right-6 w-14 h-14 bg-emerald-600 text-white rounded-full shadow-2xl flex items-center justify-center z-20 animate-bounce hover:animate-none">
                                    <Icon name="menu" size={24}/>
                                </button>
                            )}
                        </div>
                    );
                }

                if (view === 'result') return (
                    <div className="h-screen flex items-center justify-center p-4 md:p-6 bg-slate-100 text-center relative overflow-hidden">
                        <div className="absolute top-0 left-0 w-full h-full overflow-hidden pointer-events-none z-0">
                            {Array.from({length: 20}).map((_, i) => (
                                <div key={i} className="absolute bg-emerald-500 w-2 h-2 rounded-full animate-ping" style={{top: `${Math.random()*100}%`, left: `${Math.random()*100}%`, animationDelay: `${Math.random()}s`, animationDuration: `${1+Math.random()}s`}}></div>
                            ))}
                        </div>

                        <div className="bg-white p-8 md:p-12 rounded-[2.5rem] md:rounded-[3.5rem] shadow-2xl max-w-sm w-full animate-in zoom-in duration-500 relative z-10 border border-white">
                            <div className="w-24 h-24 md:w-28 md:h-28 bg-gradient-to-br from-emerald-400 to-emerald-600 text-white rounded-[1.5rem] md:rounded-[2rem] flex items-center justify-center mx-auto mb-6 md:mb-8 shadow-2xl shadow-emerald-200">
                                <Icon name={currentExam?.examMode === 'practice' ? "flag" : "trophy"} size={48} className="md:w-14 md:h-14"/>
                            </div>
                            <h2 className="text-xl md:text-2xl font-black text-slate-800 uppercase tracking-tighter mb-2">
                                {currentExam?.examMode === 'practice' ? 'HOÀN THÀNH LUYỆN TẬP!' : 'ĐÃ NỘP BÀI!'}
                            </h2>
                            
                            {/* CHỈ BÁO GHI NHẬN NẾU LÀ KIỂM TRA (GIẤU ĐIỂM) */}
                            {currentExam?.examMode === 'test' ? (
                                <p className="text-rose-500 font-bold mb-6 md:mb-8 text-xs md:text-sm uppercase tracking-widest bg-rose-50 px-4 py-2 rounded-xl border border-rose-100">Dữ liệu đã được ghi nhận</p>
                            ) : (
                                <p className="text-slate-500 font-medium mb-6 md:mb-8 text-xs md:text-sm">Tuyệt vời, bạn đã hoàn thành bài thi.</p>
                            )}
                            
                            {/* CHỈ HIỆN ĐIỂM NẾU LÀ BÀI TẬP HOẶC LUYỆN TẬP */}
                            {currentExam?.examMode !== 'test' && (
                                <div className="bg-gradient-to-br from-emerald-50 to-teal-50 border-2 border-emerald-100 rounded-[1.5rem] md:rounded-[2rem] p-6 md:p-8 mb-8 md:mb-10 shadow-inner">
                                    <p className="text-[10px] md:text-xs font-black text-emerald-500 uppercase tracking-widest mb-2 md:mb-3">TỔNG ĐIỂM CỦA BẠN</p>
                                    <p className="text-5xl md:text-6xl font-black text-emerald-600 drop-shadow-md">{finalScore}</p>
                                </div>
                            )}

                            <div className="space-y-3 md:space-y-4">
                                {/* CHỈ CÓ BÀI TẬP (NORMAL) MỚI ĐƯỢC XEM LẠI, KIỂM TRA (TEST) BỊ GIẤU HẾT */}
                                {currentExam?.examMode === 'practice' || currentExam?.examMode === 'test' ? null : (
                                    currentExam?.allowReview !== false ? (
                                        <button onClick={() => setView('review')} className="w-full bg-emerald-600 text-white py-4 md:py-5 rounded-xl md:rounded-2xl font-black uppercase tracking-widest shadow-xl shadow-emerald-200 hover:scale-105 transition-transform text-xs md:text-base">XEM LẠI BÀI LÀM</button>
                                    ) : (
                                        <div className="bg-rose-50 border border-rose-100 text-rose-500 py-3 rounded-xl font-bold text-xs mb-4 shadow-sm">Giáo viên đã khóa tính năng xem đáp án</div>
                                    )
                                )}
                                <button onClick={() => window.location.reload()} className="w-full bg-slate-100 text-slate-600 py-4 md:py-5 rounded-xl md:rounded-2xl font-black uppercase tracking-widest hover:bg-slate-200 transition-colors text-xs md:text-base">VỀ TRANG CHỦ</button>
                            </div>
                        </div>
                    </div>
                );

                return null;
            };

            return (
                <React.Fragment>
                    <div className="relative min-h-screen flex flex-col">
                        {renderContent()}
                        
                        {view !== 'quiz' && view !== 'review' && (
                            <div className="w-full flex justify-center py-6 mt-auto z-40 relative">
                                <div className="px-5 py-1.5 bg-slate-100/60 border border-slate-200/40 rounded-full backdrop-blur-md shadow-sm pointer-events-auto">
                                    <p className="text-slate-500 font-extrabold text-[9px] md:text-[10px] uppercase tracking-widest whitespace-nowrap drop-shadow-sm">
                                        &copy; Copyright Thầy Phạm Minh Tuấn - GV Toán TT GDNN - GDTX Quận 12
                                    </p>
                                </div>
                            </div>
                        )}

                        {rulesData && (
                            <div className="fixed inset-0 z-[100] flex items-center justify-center p-4">
                                <div className="absolute inset-0 bg-slate-950/80 backdrop-blur-md"></div>
                                <div className="relative bg-white rounded-[2rem] shadow-2xl w-full max-w-2xl overflow-hidden border border-slate-200/50 animate-in zoom-in-95 duration-200 max-h-[90vh] flex flex-col font-sans">
                                    
                                    {/* Header */}
                                    <div className="p-6 md:p-8 bg-gradient-to-r from-emerald-500 to-teal-500 text-white shrink-0 relative">
                                        <div className="flex items-center gap-4">
                                            <div className="w-14 h-14 rounded-2xl bg-white/20 flex items-center justify-center shadow-inner">
                                                <Icon name="info" size={32} strokeWidth={2.5} />
                                            </div>
                                            <div className="text-left">
                                                <h3 className="text-xl md:text-2xl font-black uppercase tracking-tight">HƯỚNG DẪN LÀM BÀI</h3>
                                                <p className="text-xs md:text-sm font-bold text-emerald-100 uppercase tracking-widest mt-0.5">
                                                    {student.name && student.class 
                                                        ? `Thí sinh: ${student.name} | Lớp: ${student.class}`
                                                        : `Quy chế & Lưu ý phòng thi`}
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Content */}
                                    <div className="p-6 md:p-8 overflow-y-auto space-y-6 flex-1 custom-scrollbar text-slate-700 text-left">
                                        
                                        {/* Exam Info Header Card */}
                                        <div className="bg-slate-50 p-4 md:p-5 rounded-2xl border border-slate-100">
                                            <h4 className="font-extrabold text-slate-800 text-base md:text-lg mb-1">{rulesData.title}</h4>
                                            <div className="grid grid-cols-2 gap-3 mt-3">
                                                <div className="flex items-center gap-2">
                                                    <div className="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-600 flex items-center justify-center"><Icon name="clock" size={16}/></div>
                                                    <div>
                                                        <p className="text-[10px] font-black text-slate-400 uppercase tracking-wider">Thời gian</p>
                                                        <p className="text-xs md:text-sm font-bold text-slate-700">
                                                            {rulesData.duration > 0 ? `${rulesData.duration} phút` : 'Tự do (Không giới hạn)'}
                                                        </p>
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <div className="w-8 h-8 rounded-lg bg-blue-100 text-blue-600 flex items-center justify-center"><Icon name="refresh-cw" size={16}/></div>
                                                    <div>
                                                        <p className="text-[10px] font-black text-slate-400 uppercase tracking-wider">Số lần làm bài</p>
                                                        <p className="text-xs md:text-sm font-bold text-slate-700">
                                                            {rulesData.maxAttempts > 0 ? `Lần thứ ${rulesData.attemptCount + 1} / ${rulesData.maxAttempts}` : `Lần thứ ${rulesData.attemptCount + 1} (Không giới hạn)`}
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        {/* Warnings/Notices */}
                                        <div className="space-y-4">
                                            <h5 className="font-black text-slate-800 text-sm uppercase tracking-wider flex items-center gap-2">
                                                <Icon name="alert-triangle" size={18} className="text-amber-500"/> LƯU Ý QUAN TRỌNG:
                                            </h5>
                                            <div className="space-y-3 pl-1">
                                                {rulesData.examMode !== 'practice' && rulesData.maxViolations > 0 ? (
                                                    <div className="p-4 bg-rose-50 border border-rose-200 text-slate-800 rounded-xl flex gap-3 text-xs md:text-sm font-bold leading-relaxed shadow-sm">
                                                        <div className="shrink-0 mt-0.5 text-rose-500"><Icon name="shield-alert" size={18}/></div>
                                                        <p>
                                                            <span className="text-rose-600 font-extrabold text-sm block mb-1">⚠️ PHÁT HIỆN GIÁM SÁT THI ĐANG BẬT:</span> Bạn <span className="text-rose-600 font-extrabold">KHÔNG ĐƯỢC PHÉP rời khỏi màn hình hoặc chuyển sang tab khác</span> trong suốt quá trình làm bài. Nếu vi phạm quá <span className="text-rose-600 font-extrabold">{rulesData.maxViolations} lần</span>, hệ thống sẽ <span className="text-rose-600 font-extrabold">TỰ ĐỘNG NỘP BÀI và KHÓA BÀI THI ngay lập tức</span>.
                                                        </p>
                                                    </div>
                                                ) : (
                                                    <div className="p-4 bg-emerald-50 border border-emerald-200 text-slate-800 rounded-xl flex gap-3 text-xs md:text-sm font-bold leading-relaxed shadow-sm">
                                                        <div className="shrink-0 mt-0.5 text-emerald-500"><Icon name="shield" size={18}/></div>
                                                        <p>
                                                            <span className="text-emerald-600 font-extrabold text-sm block mb-1">✅ CHẾ ĐỘ TỰ DO / LUYỆN TẬP:</span> Bài tập ở chế độ tự do, <span className="text-emerald-600 font-extrabold">không giới hạn thời gian làm bài đếm ngược</span> và <span className="text-emerald-600 font-extrabold">không bắt lỗi chuyển tab / rời màn hình</span>.
                                                        </p>
                                                    </div>
                                                )}

                                                {/* Mode Specific Submit Instruction */}
                                                <div className="p-4 bg-blue-50 border border-blue-200 text-slate-800 rounded-xl space-y-3 text-xs md:text-sm font-semibold leading-relaxed shadow-sm">
                                                    <div className="flex items-center gap-2 font-black text-blue-700 text-xs uppercase tracking-wider mb-1">
                                                        <Icon name="book-open" size={16}/> HƯỚNG DẪN LÀM BÀI & NỘP BÀI:
                                                    </div>
                                                    {rulesData.examMode === 'practice' && (
                                                        <ul className="list-disc list-inside space-y-1.5 pl-1 text-[11px] md:text-xs text-slate-600">
                                                            <li><b>Chế độ Luyện Tập:</b> Học sinh làm bài tự do, không tính thời gian. Có thể nhấn nộp bài bất cứ khi nào làm xong.</li>
                                                            <li><b>Kết quả làm bài:</b> Sau khi nộp bài thành công, hệ thống sẽ <span className="text-emerald-600 font-bold">hiển thị Điểm số ngay lập tức</span> để bạn tự chấm điểm.</li>
                                                        </ul>
                                                    )}
                                                    {rulesData.examMode === 'test' && (
                                                        <ul className="list-disc list-inside space-y-1.5 pl-1 text-[11px] md:text-xs text-slate-600">
                                                            <li><b>Chế độ Kiểm Tra:</b> Tính thời gian nghiêm ngặt. Khi hết giờ, hệ thống sẽ <span className="text-rose-600 font-bold">tự động thu bài và nộp kết quả</span>.</li>
                                                            <li><b>Kết quả làm bài:</b> Để đảm bảo tính bảo mật và công bằng của bài kiểm tra chính thức, hệ thống sẽ <span className="text-rose-600 font-bold">GIẤU hoàn toàn điểm số và không cho xem lại đáp án</span> sau khi nộp. Điểm sẽ được Giáo viên công bố sau.</li>
                                                        </ul>
                                                    )}
                                                    {(rulesData.examMode !== 'practice' && rulesData.examMode !== 'test') && (
                                                        <ul className="list-disc list-inside space-y-1.5 pl-1 text-[11px] md:text-xs text-slate-600">
                                                            <li><b>Chế độ Bài Tập Thông Thường:</b> Thời gian làm bài đếm ngược. Hệ thống sẽ tự động nộp bài khi hết giờ đếm ngược.</li>
                                                            <li><b>Kết quả làm bài:</b> Sau khi nộp, hệ thống <span className="text-emerald-600 font-bold">hiển thị Điểm số ngay lập tức</span> và mở nút <span className="text-blue-600 font-bold">"Xem lại bài làm"</span> để bạn xem lời giải chi tiết của từng câu hỏi.</li>
                                                        </ul>
                                                    )}
                                                </div>

                                                {/* Structural instructions */}
                                                <div className="p-4 bg-slate-50 border border-slate-200 text-slate-800 rounded-xl space-y-3 text-xs md:text-sm font-medium leading-relaxed">
                                                    <div className="flex items-center gap-2 font-black text-slate-700 text-xs uppercase tracking-wider mb-2 border-b border-slate-200 pb-1.5"><Icon name="list" size={16}/> CẤU TRÚC ĐỀ THI & THANG ĐIỂM:</div>
                                                    <div className="space-y-4">
                                                        {(rulesData.config?.p1 > 0) && (
                                                            <div>
                                                                <p className="font-extrabold text-slate-800 text-xs md:text-sm uppercase tracking-wide">📍 Phần I: Trắc nghiệm nhiều lựa chọn ({rulesData.config.p1} câu)</p>
                                                                <ul className="list-disc list-inside pl-3 text-[11px] md:text-xs text-slate-600 space-y-0.5 mt-1">
                                                                    <li>Chọn <b>1 đáp án đúng duy nhất</b> trong 4 phương án (A, B, C, D).</li>
                                                                    <li>Mỗi câu trả lời đúng được cộng <span className="text-emerald-600 font-bold">{rulesData.scoring?.p1} điểm</span>.</li>
                                                                </ul>
                                                            </div>
                                                        )}
                                                        {(rulesData.config?.p2 > 0) && (
                                                            <div>
                                                                <p className="font-extrabold text-slate-800 text-xs md:text-sm uppercase tracking-wide">📍 Phần II: Trắc nghiệm Đúng/Sai ({rulesData.config.p2} câu)</p>
                                                                <ul className="list-disc list-inside pl-3 text-[11px] md:text-xs text-slate-600 space-y-0.5 mt-1">
                                                                    <li>Mỗi câu hỏi có 4 ý lựa chọn (a, b, c, d). Bạn phải trả lời <b>Đúng</b> hoặc <b>Sai</b> cho từng ý.</li>
                                                                    <li>Điểm số tính lũy tiến theo số ý trả lời đúng trên một câu hỏi:</li>
                                                                    <div className="pl-4 text-[10px] md:text-[11px] text-slate-500 font-semibold grid grid-cols-2 gap-x-2 gap-y-0.5 mt-1">
                                                                        <span>• Đúng 1 ý: <b className="text-blue-600">{rulesData.scoring?.p2?.['1']}đ</b></span>
                                                                        <span>• Đúng 3 ý: <b className="text-blue-600">{rulesData.scoring?.p2?.['3']}đ</b></span>
                                                                        <span>• Đúng 2 ý: <b className="text-blue-600">{rulesData.scoring?.p2?.['2']}đ</b></span>
                                                                        <span>• Đúng 4 ý: <b className="text-blue-600">{rulesData.scoring?.p2?.['4']}đ (Tối đa)</b></span>
                                                                    </div>
                                                                </ul>
                                                            </div>
                                                        )}
                                                        {(rulesData.config?.p3 > 0) && (
                                                            <div>
                                                                <p className="font-extrabold text-slate-800 text-xs md:text-sm uppercase tracking-wide">📍 Phần III: Trắc nghiệm trả lời ngắn ({rulesData.config.p3} câu)</p>
                                                                <ul className="list-disc list-inside pl-3 text-[11px] md:text-xs text-slate-600 space-y-0.5 mt-1">
                                                                    <li>Tính toán và nhập đáp số chính xác dưới dạng <b>số nguyên hoặc số thập phân</b> (ví dụ: <code className="bg-slate-100 px-1 py-0.5 rounded font-bold text-indigo-600">3</code> hoặc <code className="bg-slate-100 px-1 py-0.5 rounded font-bold text-indigo-600">-1.25</code>).</li>
                                                                    <li><span className="text-rose-600 font-bold">Lưu ý cực kỳ quan trọng:</span> <b>KHÔNG</b> nhập phân số, chữ cái hay biểu thức khác.</li>
                                                                    <li>Mỗi câu trả lời đúng được cộng <span className="text-emerald-600 font-bold">{rulesData.scoring?.p3} điểm</span>.</li>
                                                                </ul>
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Footer Actions */}
                                    <div className="p-4 md:p-6 bg-slate-50 border-t border-slate-100 flex gap-3 shrink-0">
                                        {rulesData.isPreviewOnly ? (
                                            <button onClick={() => setRulesData(null)} className="w-full py-3.5 px-6 rounded-xl font-black text-xs md:text-sm text-white bg-gradient-to-r from-emerald-500 to-teal-500 hover:from-emerald-600 hover:to-teal-600 shadow-lg shadow-emerald-500/20 hover:-translate-y-0.5 transition-all flex items-center justify-center gap-2">
                                                <Icon name="check" size={18}/> ĐÃ HIỂU & ĐÓNG HƯỚNG DẪN
                                            </button>
                                        ) : (
                                            <>
                                                <button onClick={() => setRulesData(null)} className="flex-1 py-3.5 rounded-xl font-bold text-xs md:text-sm text-slate-500 bg-white border border-slate-200 hover:bg-slate-100 transition-colors">Hủy bỏ</button>
                                                <button onClick={confirmStartQuiz} className="flex-2 py-3.5 px-6 rounded-xl font-black text-xs md:text-sm text-white bg-gradient-to-r from-emerald-500 to-teal-500 hover:from-emerald-600 hover:to-teal-600 shadow-lg shadow-emerald-500/20 hover:-translate-y-0.5 transition-all flex items-center justify-center gap-2">
                                                    <Icon name="check-circle" size={18}/> ĐÃ HIỂU & BẮT ĐẦU LÀM BÀI
                                                </button>
                                            </>
                                        )}
                                    </div>
                                </div>
                            </div>
                        )}

                        {showAdminLogin && (
                            <div className="fixed inset-0 z-[100] flex items-center justify-center p-4">
                                <div className="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onClick={() => setShowAdminLogin(false)}></div>
                                <div className="relative bg-white w-full max-w-sm rounded-[2.5rem] shadow-2xl flex flex-col overflow-hidden border border-slate-100 animate-in zoom-in-95 duration-200">
                                    <header className="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/80 text-slate-800 shrink-0">
                                        <div className="flex items-center gap-3 flex-1 min-w-0 pr-2">
                                            <div className="p-2.5 bg-indigo-50 text-indigo-600 rounded-xl shrink-0">
                                                <Icon name={adminFormMode === 'login' ? 'shield-check' : 'user-plus'} size={18}/>
                                            </div>
                                            <h2 className="text-xs md:text-sm font-black uppercase tracking-widest text-slate-800 truncate flex items-center gap-1.5">
                                                <span>{adminFormMode === 'login' ? 'Đăng nhập' : 'Đăng ký'}</span>
                                                <SquareRootX className="w-4 h-4 md:w-5 md:h-5 text-indigo-600 mb-0.5" />
                                            </h2>
                                        </div>
                                        <button type="button" onClick={() => setShowAdminLogin(false)} className="w-8 h-8 flex items-center justify-center bg-slate-100 text-slate-400 hover:bg-rose-50 hover:text-rose-600 rounded-full transition-colors shrink-0" title="Đóng"><Icon name="x" size={16}/></button>
                                    </header>
                                    
                                    {adminFormMode === 'login' ? (
                                        <form className="p-6 md:p-8 space-y-5 text-center animate-in fade-in duration-200" onSubmit={async (e) => {
                                            e.preventDefault();
                                            const username = e.target.username.value;
                                            const password = e.target.password.value;
                                            try {
                                                const r = await fetch('admin.php?action=login', {
                                                    method: 'POST',
                                                    headers: { 'Content-Type': 'application/json' },
                                                    body: JSON.stringify({ username, password })
                                                });
                                                const res = await r.json();
                                                if (res.success) {
                                                    setShowAdminLogin(false);
                                                    window.location.href = 'admin.php';
                                                } else {
                                                    showAlert(res.message || "Tên đăng nhập hoặc mật khẩu không đúng!");
                                                }
                                            } catch (err) {
                                                showAlert("Lỗi kết nối máy chủ quản trị!");
                                            }
                                        }}>
                                            <div className="space-y-4">
                                                <div>
                                                    <label className="block text-[10px] font-black text-slate-400 uppercase tracking-widest text-left mb-1.5 ml-1">Tài khoản</label>
                                                    <input name="username" placeholder="Nhập tài khoản..." className="w-full bg-slate-50 border border-slate-200 text-slate-800 placeholder:text-slate-400 p-3.5 rounded-2xl font-bold outline-none focus:bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 transition-all text-center text-sm" required />
                                                </div>
                                                <div>
                                                    <label className="block text-[10px] font-black text-slate-400 uppercase tracking-widest text-left mb-1.5 ml-1">Mật khẩu</label>
                                                    <input name="password" type="password" placeholder="••••••••" className="w-full bg-slate-50 border border-slate-200 text-slate-800 placeholder:text-slate-400 p-3.5 rounded-2xl font-bold outline-none focus:bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 transition-all text-center text-sm" required />
                                                </div>
                                            </div>
                                            
                                            <button type="submit" className="w-full bg-gradient-to-r from-indigo-600 to-emerald-600 text-white py-3.5 rounded-2xl font-black uppercase tracking-widest shadow-md hover:shadow-lg hover:shadow-indigo-500/20 active:scale-[0.99] transition-all text-xs">
                                                ĐĂNG NHẬP HỆ THỐNG
                                            </button>
                                            
                                            <button type="button" onClick={() => setAdminFormMode('register')} className="text-[10px] font-bold text-slate-400 hover:text-indigo-600 transition-colors uppercase tracking-widest block mx-auto mt-2">
                                                Đăng ký tài khoản mới
                                            </button>
                                        </form>
                                    ) : (
                                        <form className="p-6 md:p-8 space-y-4 text-center animate-in fade-in duration-200" onSubmit={async (e) => {
                                            e.preventDefault();
                                            const fullName = e.target.fullName.value;
                                            const username = e.target.username.value;
                                            const password = e.target.password.value;
                                            try {
                                                const r = await fetch('admin.php?action=register', {
                                                    method: 'POST',
                                                    headers: { 'Content-Type': 'application/json' },
                                                    body: JSON.stringify({ fullName, username, password })
                                                });
                                                const res = await r.json();
                                                if (res.success) {
                                                    showAlert("Đăng ký tài khoản thành công! Bây giờ bạn có thể đăng nhập.");
                                                    setAdminFormMode('login');
                                                } else {
                                                    showAlert(res.message || "Đăng ký không thành công!");
                                                }
                                            } catch (err) {
                                                showAlert("Lỗi kết nối máy chủ quản trị!");
                                            }
                                        }}>
                                            <div className="space-y-3">
                                                <div>
                                                    <label className="block text-[10px] font-black text-slate-400 uppercase tracking-widest text-left mb-1 ml-1">Họ và Tên Giáo viên</label>
                                                    <input name="fullName" placeholder="Nhập họ và tên..." className="w-full bg-slate-50 border border-slate-200 text-slate-800 placeholder:text-slate-400 p-3 rounded-xl font-bold outline-none focus:bg-white focus:border-indigo-500 transition-all text-center text-sm" required />
                                                </div>
                                                <div>
                                                    <label className="block text-[10px] font-black text-slate-400 uppercase tracking-widest text-left mb-1 ml-1">Tên đăng nhập mới</label>
                                                    <input name="username" placeholder="Nhập tài khoản đăng ký..." className="w-full bg-slate-50 border border-slate-200 text-slate-800 placeholder:text-slate-400 p-3 rounded-xl font-bold outline-none focus:bg-white focus:border-indigo-500 transition-all text-center text-sm" required />
                                                </div>
                                                <div>
                                                    <label className="block text-[10px] font-black text-slate-400 uppercase tracking-widest text-left mb-1 ml-1">Mật khẩu mới</label>
                                                    <input name="password" type="password" placeholder="••••••••" className="w-full bg-slate-50 border border-slate-200 text-slate-800 placeholder:text-slate-400 p-3 rounded-xl font-bold outline-none focus:bg-white focus:border-indigo-500 transition-all text-center text-sm" required />
                                                </div>
                                            </div>
                                            
                                            <button type="submit" className="w-full bg-gradient-to-r from-indigo-600 to-emerald-600 text-white py-3.5 rounded-2xl font-black uppercase tracking-widest shadow-md hover:shadow-lg hover:shadow-indigo-500/20 active:scale-[0.99] transition-all text-xs">
                                                ĐĂNG KÝ TÀI KHOẢN
                                            </button>
                                            
                                            <button type="button" onClick={() => setAdminFormMode('login')} className="text-[10px] font-bold text-slate-400 hover:text-indigo-600 transition-colors uppercase tracking-widest block mx-auto mt-2">
                                                Quay lại Đăng nhập
                                            </button>
                                        </form>
                                    )}
                                </div>
                            </div>
                        )}

                        {showProfileModal && (
                            <div className="fixed inset-0 z-[100] flex items-center justify-center p-4">
                                <div className="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onClick={() => setShowProfileModal(false)}></div>
                                <div className="relative bg-white w-full max-w-sm rounded-[2.5rem] shadow-2xl flex flex-col overflow-hidden border border-slate-100 animate-in zoom-in-95 duration-200">
                                    <header className="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/80 text-slate-800 shrink-0">
                                        <div className="flex items-center gap-3 flex-1 min-w-0 pr-2">
                                            <div className="p-2.5 bg-indigo-50 text-indigo-600 rounded-xl shrink-0">
                                                <Icon name="user-cog" size={18}/>
                                            </div>
                                            <div className="min-w-0">
                                                <h2 className="text-xs md:text-sm font-black uppercase tracking-widest text-slate-800 truncate flex items-center gap-1.5">
                                                    <span>Thông tin tài khoản</span>
                                                    <SquareRootX className="w-4 h-4 md:w-5 md:h-5 text-indigo-600 mb-0.5" />
                                                </h2>
                                                <p className="text-[10px] text-slate-400 font-bold truncate">{adminSession.fullName || adminSession.username}</p>
                                            </div>
                                        </div>
                                        <button type="button" onClick={() => setShowProfileModal(false)} className="w-8 h-8 flex items-center justify-center bg-slate-100 text-slate-400 hover:bg-rose-50 hover:text-rose-600 rounded-full transition-colors shrink-0" title="Đóng"><Icon name="x" size={16}/></button>
                                    </header>

                                    {/* Tabs */}
                                    <div className="flex border-b border-slate-100 p-1.5 gap-1.5 bg-slate-50/50">
                                        <button 
                                            onClick={() => setProfileTab('info')}
                                            className={`flex-1 py-2 rounded-xl text-[11px] font-black uppercase tracking-wider transition-all flex items-center justify-center gap-1.5 ${profileTab === 'info' ? 'bg-white text-indigo-600 shadow-sm border border-slate-200/60' : 'text-slate-500 hover:text-slate-800'}`}
                                        >
                                            <Icon name="user" size={14}/>
                                            <span>Thông tin</span>
                                        </button>
                                        <button 
                                            onClick={() => setProfileTab('password')}
                                            className={`flex-1 py-2 rounded-xl text-[11px] font-black uppercase tracking-wider transition-all flex items-center justify-center gap-1.5 ${profileTab === 'password' ? 'bg-white text-indigo-600 shadow-sm border border-slate-200/60' : 'text-slate-500 hover:text-slate-800'}`}
                                        >
                                            <Icon name="key" size={14}/>
                                            <span>Đổi mật khẩu</span>
                                        </button>
                                    </div>
                                    
                                    <div className="p-6 md:p-8">
                                        {profileTab === 'info' ? (
                                            <form className="space-y-4 animate-in fade-in duration-200" onSubmit={async (e) => {
                                                e.preventDefault();
                                                const fullName = e.target.fullName.value;
                                                try {
                                                    const r = await fetch('admin.php?action=update_profile', {
                                                        method: 'POST',
                                                        headers: { 'Content-Type': 'application/json' },
                                                        body: JSON.stringify({ fullName })
                                                    });
                                                    const res = await r.json();
                                                    if (res.success) {
                                                        setShowProfileModal(false);
                                                        setAdminSession(prev => ({ ...prev, fullName: res.fullName }));
                                                        showAlert("Cập nhật thông tin thành công!");
                                                    } else {
                                                        showAlert(res.message || "Lỗi khi cập nhật!");
                                                    }
                                                } catch (err) {
                                                    showAlert("Lỗi kết nối máy chủ!");
                                                }
                                            }}>
                                                <div>
                                                    <label className="block text-[10px] font-black text-slate-400 uppercase tracking-widest text-left mb-1.5 ml-1">Tên đăng nhập</label>
                                                    <input value={adminSession.username || ''} disabled className="w-full bg-slate-100 border border-slate-200 text-slate-500 p-3.5 rounded-2xl font-bold text-center text-sm cursor-not-allowed" />
                                                </div>
                                                <div>
                                                    <label className="block text-[10px] font-black text-slate-400 uppercase tracking-widest text-left mb-1.5 ml-1">Họ và tên hiển thị</label>
                                                    <input name="fullName" placeholder="Nhập họ và tên..." defaultValue={adminSession.fullName} className="w-full bg-slate-50 border border-slate-200 text-slate-800 placeholder:text-slate-400 p-3.5 rounded-2xl font-bold outline-none focus:bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 transition-all text-center text-sm" required />
                                                </div>
                                                
                                                <button type="submit" className="w-full bg-gradient-to-r from-indigo-600 to-emerald-600 text-white py-3.5 rounded-2xl font-black uppercase tracking-widest shadow-md hover:shadow-lg hover:shadow-indigo-500/20 active:scale-[0.99] transition-all text-xs mt-2">
                                                    LƯU THAY ĐỔI
                                                </button>
                                            </form>
                                        ) : (
                                            <form className="space-y-3.5 animate-in fade-in duration-200" onSubmit={async (e) => {
                                                e.preventDefault();
                                                const oldPassword = e.target.oldPassword.value;
                                                const newPassword = e.target.newPassword.value;
                                                const confirmPassword = e.target.confirmPassword.value;
                                                if (newPassword !== confirmPassword) {
                                                    return showAlert("Mật khẩu xác nhận không khớp!");
                                                }
                                                try {
                                                    const r = await fetch('admin.php?action=change_password', {
                                                        method: 'POST',
                                                        headers: { 'Content-Type': 'application/json' },
                                                        body: JSON.stringify({ oldPassword, newPassword })
                                                    });
                                                    const res = await r.json();
                                                    if (res.success) {
                                                        setShowProfileModal(false);
                                                        showAlert("Đổi mật khẩu thành công!");
                                                    } else {
                                                        showAlert(res.message || "Lỗi khi đổi mật khẩu!");
                                                    }
                                                } catch (err) {
                                                    showAlert("Lỗi kết nối máy chủ!");
                                                }
                                            }}>
                                                <div>
                                                    <label className="block text-[10px] font-black text-slate-400 uppercase tracking-widest text-left mb-1 ml-1">Mật khẩu cũ</label>
                                                    <input name="oldPassword" type="password" placeholder="••••••••" className="w-full bg-slate-50 border border-slate-200 text-slate-800 placeholder:text-slate-400 p-3 rounded-xl font-bold outline-none focus:bg-white focus:border-indigo-500 transition-all text-center text-sm" required />
                                                </div>
                                                <div>
                                                    <label className="block text-[10px] font-black text-slate-400 uppercase tracking-widest text-left mb-1 ml-1">Mật khẩu mới</label>
                                                    <input name="newPassword" type="password" placeholder="••••••••" className="w-full bg-slate-50 border border-slate-200 text-slate-800 placeholder:text-slate-400 p-3 rounded-xl font-bold outline-none focus:bg-white focus:border-indigo-500 transition-all text-center text-sm" required />
                                                </div>
                                                <div>
                                                    <label className="block text-[10px] font-black text-slate-400 uppercase tracking-widest text-left mb-1 ml-1">Xác nhận mật khẩu mới</label>
                                                    <input name="confirmPassword" type="password" placeholder="••••••••" className="w-full bg-slate-50 border border-slate-200 text-slate-800 placeholder:text-slate-400 p-3 rounded-xl font-bold outline-none focus:bg-white focus:border-indigo-500 transition-all text-center text-sm" required />
                                                </div>

                                                <button type="submit" className="w-full bg-gradient-to-r from-indigo-600 to-emerald-600 text-white py-3.5 rounded-2xl font-black uppercase tracking-widest shadow-md hover:shadow-lg hover:shadow-indigo-500/20 active:scale-[0.99] transition-all text-xs mt-2">
                                                    CẬP NHẬT MẬT KHẨU
                                                </button>
                                            </form>
                                        )}
                                    </div>
                                </div>
                            </div>
                        )}
                        <CustomDialog dialog={dialog} onClose={closeDialog} />
                        
                        {/* CỬA SỔ HIỂN THỊ TÀI LIỆU PDF CHUẨN */}
                        {selectedPdf && (
                            <div className="fixed inset-0 z-[100] bg-slate-900/40 backdrop-blur-md flex items-center justify-center p-2 md:p-6 animate-in fade-in duration-300">
                                <div className="bg-white w-full max-w-6xl h-[95vh] rounded-[2rem] shadow-2xl flex flex-col overflow-hidden relative">
                                    <header className="p-3 md:p-4 border-b bg-slate-50 flex justify-between items-center shrink-0">
                                        <div className="flex items-center gap-2 overflow-hidden">
                                            <Icon name="file-text" size={18} className="text-rose-500 shrink-0"/>
                                            <h3 className="font-black text-xs md:text-sm uppercase text-slate-700 tracking-widest truncate">Trình xem tài liệu</h3>
                                        </div>
                                        <div className="flex gap-1.5 md:gap-3 shrink-0 pl-2">
                                            <a href={selectedPdf} target="_blank" rel="noreferrer" className="px-3 md:px-4 py-2 md:py-2.5 bg-emerald-50 text-emerald-600 rounded-xl hover:bg-emerald-600 hover:text-white transition-all flex items-center gap-1.5 text-[10px] md:text-xs font-black uppercase tracking-widest shadow-sm border border-emerald-100" title="Mở sang Tab mới nếu bị lỗi trang">
                                                <Icon name="external-link" size={14}/> <span className="hidden sm:inline">Mở Rộng</span>
                                            </a>
                                            
                                            <a href={selectedPdf} download className="px-3 md:px-4 py-2 md:py-2.5 bg-blue-50 text-blue-600 rounded-xl hover:bg-blue-600 hover:text-white transition-all flex items-center gap-1.5 text-[10px] md:text-xs font-black uppercase tracking-widest shadow-sm border border-blue-100">
                                                <Icon name="download" size={14}/> <span className="hidden sm:inline">Tải Về</span>
                                            </a>
                                            
                                            <button onClick={() => setSelectedPdf(null)} className="px-3 md:px-4 py-2 md:py-2.5 bg-rose-500 text-white rounded-xl hover:bg-rose-600 transition-all flex items-center gap-1.5 text-[10px] md:text-xs font-black uppercase tracking-widest shadow-sm">
                                                <Icon name="x" size={14}/> <span className="hidden sm:inline">Đóng</span>
                                            </button>
                                        </div>
                                    </header>
                                    
                                    <div className="flex-1 bg-slate-200 overflow-y-auto relative" style={{ WebkitOverflowScrolling: 'touch' }}>
                                        <object data={selectedPdf} type="application/pdf" className="absolute top-0 left-0 w-full h-full min-h-[100vh]">
                                            <iframe src={selectedPdf} className="w-full h-full min-h-[100vh] border-none" title="PDF Viewer">
                                                <div className="p-10 text-center flex flex-col items-center">
                                                    <Icon name="alert-circle" size={40} className="text-slate-400 mb-3"/>
                                                    <p className="text-slate-600 font-bold text-sm">Trình duyệt không hỗ trợ xem trực tiếp.</p>
                                                    <p className="text-slate-500 text-xs mt-1">Vui lòng bấm "MỞ RỘNG" hoặc "TẢI VỀ" ở phía trên.</p>
                                                </div>
                                            </iframe>
                                        </object>
                                    </div>

                                    <div className="bg-amber-50 text-amber-700 p-2.5 text-center text-[10px] font-bold sm:hidden border-t border-amber-200 shrink-0">
                                        Nếu tài liệu bị phóng to hoặc mất trang, vui lòng bấm <b className="uppercase"><Icon name="external-link" size={10} className="inline mb-0.5"/> Mở Rộng</b> để xem chuẩn nhất.
                                    </div>
                                </div>
                            </div>
                        )}
                        
                        {/* CỬA SỔ HIỂN THỊ CHƠI VIDEO YOUTUBE */}
                        {selectedVideo && (() => {
                            const getYoutubeId = (url) => {
                                const regExp = /^.*(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=|\&v=)([^#\&\?]*).*/;
                                const match = url.match(regExp);
                                return (match && match[2].length === 11) ? match[2] : null;
                            };
                            const vidId = getYoutubeId(selectedVideo.videoUrl);
                            const embedUrl = vidId ? `https://www.youtube.com/embed/${vidId}?autoplay=1&rel=0` : null;

                            return (
                                <div className="fixed inset-0 z-[100] bg-slate-900/40 backdrop-blur-md flex items-center justify-center p-2 md:p-6 animate-in fade-in duration-300">
                                    <div className="bg-white w-full max-w-4xl rounded-[2.5rem] shadow-2xl flex flex-col overflow-hidden relative border border-slate-100">
                                        <header className="p-3.5 md:p-4 border-b border-slate-100 bg-slate-50 flex justify-between items-center shrink-0">
                                            <div className="flex items-center gap-2 overflow-hidden text-slate-800">
                                                <Icon name="video" size={18} className="text-pink-500 shrink-0"/>
                                                <h3 className="font-black text-xs md:text-sm uppercase tracking-widest truncate">{selectedVideo.title}</h3>
                                            </div>
                                            <button onClick={() => setSelectedVideo(null)} className="px-3.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl transition-all text-xs font-black uppercase tracking-widest flex items-center gap-1">
                                                <Icon name="x" size={14}/> Đóng
                                            </button>
                                        </header>
                                        
                                        <div className="flex-1 aspect-video bg-black relative">
                                            {embedUrl ? (
                                                <iframe 
                                                    src={embedUrl} 
                                                    className="absolute top-0 left-0 w-full h-full border-none" 
                                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                                                    allowFullScreen
                                                    title={selectedVideo.title}
                                                />
                                            ) : (
                                                <div className="w-full h-full flex flex-col items-center justify-center text-slate-400 p-10 text-center">
                                                    <Icon name="alert-triangle" size={40} className="mb-2 text-rose-500"/>
                                                    <p className="font-bold text-sm">Không thể tải trình phát video.</p>
                                                    <a href={selectedVideo.videoUrl} target="_blank" rel="noopener noreferrer" className="text-xs text-blue-400 underline mt-2">Bấm vào đây để xem trực tiếp trên YouTube</a>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            );
                        })()}

                        {/* CỬA SỔ HƯỚNG DẪN SỬ DỤNG */}
                        {guideModalType && (
                            <div className="fixed inset-0 z-[100] bg-slate-950/60 backdrop-blur-md flex items-center justify-center p-4 md:p-6 animate-in fade-in duration-300">
                                <div className="bg-white w-full max-w-xl md:max-w-2xl rounded-[2rem] shadow-2xl p-6 md:p-8 flex flex-col max-h-[85vh] overflow-y-auto custom-scrollbar relative border border-slate-100 animate-in zoom-in-95 duration-200">
                                    <button onClick={() => setGuideModalType(null)} className="absolute top-6 right-6 w-8 h-8 flex items-center justify-center bg-slate-100 text-slate-500 hover:bg-slate-200 rounded-full transition-colors"><Icon name="x" size={16}/></button>
                                    
                                    <div className="flex items-center gap-3 pb-4 border-b border-slate-100 mb-6">
                                        <div className="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 flex items-center justify-center shrink-0">
                                            <Icon name={
                                                guideModalType === 'teacher' ? 'graduation-cap' :
                                                guideModalType === 'student' ? 'user' :
                                                guideModalType === 'contact' ? 'mail' : 'help-circle'
                                            } size={22} />
                                        </div>
                                        <div>
                                            <h3 className="text-base md:text-lg font-black text-slate-800 uppercase tracking-widest text-left">
                                                {guideModalType === 'teacher' ? 'Hướng dẫn Giáo viên' :
                                                 guideModalType === 'student' ? 'Hướng dẫn Học sinh' :
                                                 guideModalType === 'contact' ? 'Thông tin liên hệ' : 'Hướng dẫn sử dụng'}
                                            </h3>
                                        </div>
                                    </div>
                                    
                                    <div className="space-y-5 text-slate-600 text-left">
                                        {/* HƯỚNG DẪN GIÁO VIÊN */}
                                        {guideModalType === 'teacher' && (
                                            <div className="space-y-5 animate-in fade-in duration-200">
                                                <div className="flex items-center justify-between gap-1.5 md:gap-3 bg-slate-50 p-2 rounded-2xl border border-slate-100/80">
                                                    {[1, 2, 3, 4].map((stepNum) => {
                                                        const isActive = guideStep === stepNum;
                                                        const title = stepNum === 1 ? "Tài khoản" : stepNum === 2 ? "Đề ôn tập" : stepNum === 3 ? "Lớp học" : "Xuất điểm";
                                                        return (
                                                            <button 
                                                                key={stepNum} 
                                                                type="button"
                                                                onClick={() => setGuideStep(stepNum)}
                                                                className={`flex-1 flex items-center justify-center gap-1.5 py-2 px-1 rounded-xl transition-all cursor-pointer select-none ${isActive ? 'bg-indigo-600 text-white font-bold shadow-md shadow-indigo-600/10' : 'hover:bg-slate-200/50 text-slate-500 font-bold'}`}
                                                            >
                                                                <div className={`w-5 h-5 rounded-full flex items-center justify-center text-[10px] ${isActive ? 'bg-white text-indigo-600 font-black' : 'bg-slate-200 text-slate-600 font-extrabold'}`}>
                                                                    {stepNum}
                                                                </div>
                                                                <span className="text-[10px] md:text-[11px] font-black uppercase tracking-wider hidden sm:inline">{title}</span>
                                                            </button>
                                                        );
                                                    })}
                                                </div>

                                                <div className="h-[290px] md:h-[230px]">
                                                    {guideStep === 1 && (
                                                        <div className="h-full bg-indigo-50/50 p-5 rounded-3xl border border-indigo-100/50 space-y-1.5 animate-in fade-in zoom-in-95 duration-200 text-left overflow-y-auto custom-scrollbar pr-1">
                                                            <p className="font-extrabold text-indigo-800 text-xs md:text-sm uppercase tracking-wider flex items-center gap-1.5"><Icon name="key" size={15}/> 1. Đăng ký & Đăng nhập tài khoản</p>
                                                            <p className="text-[12px] md:text-[13px] text-slate-600 font-medium leading-relaxed">
                                                                - <b>Đăng ký:</b> Để bảo mật dữ liệu thi cử và thông tin học sinh, tài khoản Giáo viên được cấp trực tiếp bởi Admin thông qua email hoặc số điện thoại hỗ trợ kỹ thuật của hệ thống.<br/>
                                                                - <b>Đăng nhập:</b> Nhấp vào nút <b>Đăng Nhập</b> (biểu tượng chiếc khóa) ở góc trên bên phải màn hình trang chủ. Nhập chính xác tên tài khoản, mật khẩu được cấp, sau đó nhấn <b>Đăng nhập</b> để chuyển hướng sang Cổng Quản trị dành cho Giáo viên.
                                                            </p>
                                                        </div>
                                                    )}
                                                    {guideStep === 2 && (
                                                        <div className="h-full bg-emerald-50/50 p-5 rounded-3xl border border-emerald-100/50 space-y-1.5 animate-in fade-in zoom-in-95 duration-200 text-left overflow-y-auto custom-scrollbar pr-1">
                                                            <p className="font-extrabold text-emerald-800 text-xs md:text-sm uppercase tracking-wider flex items-center gap-1.5"><Icon name="plus-circle" size={15}/> 2. Quản lý & Tạo đề ôn tập</p>
                                                            <p className="text-[12px] md:text-[13px] text-slate-600 font-medium leading-relaxed">
                                                                - <b>Tạo đề thi mới:</b> Truy cập menu <b>"Quản lý đề thi"</b> -&gt; Bấm nút <b>"Thêm đề thi mới"</b>.<br/>
                                                                - <b>Nhập nội dung đề:</b> Hệ thống hỗ trợ định dạng Rich Text soạn thảo chuyên nghiệp. Bạn có thể chèn các công thức toán học phức tạp bằng ký hiệu <b>LaTeX</b> (bao bọc bởi dấu đô-la như <code>$x^2$</code>), chèn hình vẽ minh họa, sơ đồ hình học trực tiếp từ máy tính.<br/>
                                                                - <b>Cấu hình thi cử:</b> Thiết lập phân loại (Khối lớp, Kỳ thi), cấu hình 3 phần thi (Trắc nghiệm nhiều lựa chọn, Trắc nghiệm đúng/sai, Trắc nghiệm trả lời ngắn) và đặt thời gian làm bài, mật khẩu truy cập (nếu có).
                                                            </p>
                                                        </div>
                                                    )}
                                                    {guideStep === 3 && (
                                                        <div className="h-full bg-blue-50/50 p-5 rounded-3xl border border-blue-100/50 space-y-1.5 animate-in fade-in zoom-in-95 duration-200 text-left overflow-y-auto custom-scrollbar pr-1">
                                                            <p className="font-extrabold text-blue-800 text-xs md:text-sm uppercase tracking-wider flex items-center gap-1.5"><Icon name="users" size={15}/> 3. Quản lý học sinh & Phân lớp</p>
                                                            <p className="text-[12px] md:text-[13px] text-slate-600 font-medium leading-relaxed">
                                                                - <b>Thêm học sinh:</b> Vào menu <b>"Quản lý học sinh"</b>. Bạn có thể thêm từng học sinh thủ công hoặc tải lên tệp danh sách Excel định dạng <code>.xlsx</code> (hệ thống cung cấp sẵn file mẫu để bạn tải xuống và điền thông tin).<br/>
                                                                - <b>Cấp mật khẩu thi:</b> Mỗi học sinh sẽ được cấp một tài khoản/mã số thi. Khi học sinh làm bài thi chính thức, hệ thống yêu cầu xác thực đúng mật khẩu và lớp học để tránh làm bài hộ.
                                                            </p>
                                                        </div>
                                                    )}
                                                    {guideStep === 4 && (
                                                        <div className="h-full bg-amber-50/50 p-5 rounded-3xl border border-amber-100/50 space-y-1.5 animate-in fade-in zoom-in-95 duration-200 text-left overflow-y-auto custom-scrollbar pr-1">
                                                            <p className="font-extrabold text-amber-800 text-xs md:text-sm uppercase tracking-wider flex items-center gap-1.5"><Icon name="book-open" size={15}/> 4. Theo dõi trực tiếp & Xuất sổ điểm</p>
                                                            <p className="text-[12px] md:text-[13px] text-slate-600 font-medium leading-relaxed">
                                                                - <b>Giám sát thời gian thực:</b> Trong thời gian kiểm tra, hệ thống ghi nhận trạng thái làm bài của từng học sinh, ghi nhận số lần học sinh chuyển tab hoặc rời khỏi trình duyệt (chống gian lận).<br/>
                                                                - <b>Xem chi tiết bài làm:</b> Sau khi học sinh nộp bài, giáo viên có thể vào xem chi tiết từng câu trả lời đúng/sai của học sinh, bảng phân tích điểm số.<br/>
                                                                - <b>Xuất dữ liệu:</b> Bấm chọn nút <b>"Xuất Excel"</b> trong phần thống kê lớp học để tải về bảng điểm hoàn chỉnh, hỗ trợ nhập điểm vào sổ điểm chính thức của trường.
                                                            </p>
                                                        </div>
                                                    )}
                                                </div>

                                                <div className="flex justify-between items-center pt-2 border-t border-slate-100">
                                                    <button type="button" disabled={guideStep === 1} onClick={() => setGuideStep(p => Math.max(1, p - 1))} className="px-4 py-2 text-xs font-bold rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 transition-all disabled:opacity-40 disabled:cursor-not-allowed select-none">Quay lại</button>
                                                    <div className="flex gap-2">
                                                        {guideStep < 4 ? (
                                                            <button type="button" onClick={() => setGuideStep(p => Math.min(4, p + 1))} className="px-4 py-2 text-xs font-black uppercase tracking-widest rounded-xl bg-indigo-600 text-white hover:bg-indigo-700 transition-all select-none">Tiếp theo</button>
                                                        ) : (
                                                            <button type="button" onClick={() => setGuideModalType(null)} className="px-4 py-2 text-xs font-black uppercase tracking-widest rounded-xl bg-emerald-600 text-white hover:bg-emerald-700 transition-all select-none">Hoàn tất</button>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        )}

                                        {/* HƯỚNG DẪN HỌC SINH GENERAL */}
                                        {guideModalType === 'student' && (
                                            <div className="space-y-5 animate-in fade-in duration-200">
                                                {/* Stepper bar */}
                                                <div className="flex items-center justify-between gap-1.5 md:gap-3 bg-slate-50 p-2 rounded-2xl border border-slate-100/80">
                                                    {[1, 2, 3].map((stepNum) => {
                                                        const isActive = guideStep === stepNum;
                                                        const title = stepNum === 1 ? "Sơ lược" : stepNum === 2 ? "Làm bài" : "Kết quả";
                                                        return (
                                                            <button 
                                                                key={stepNum} 
                                                                type="button"
                                                                onClick={() => setGuideStep(stepNum)}
                                                                className={`flex-1 flex items-center justify-center gap-1.5 py-2 px-1 rounded-xl transition-all cursor-pointer select-none ${isActive ? 'bg-indigo-600 text-white font-bold shadow-md shadow-indigo-600/10' : 'hover:bg-slate-200/50 text-slate-500 font-bold'}`}
                                                            >
                                                                <div className={`w-5 h-5 rounded-full flex items-center justify-center text-[10px] ${isActive ? 'bg-white text-indigo-600 font-black' : 'bg-slate-200 text-slate-600 font-extrabold'}`}>
                                                                    {stepNum}
                                                                </div>
                                                                <span className="text-[10px] md:text-[11px] font-black uppercase tracking-wider hidden sm:inline">{title}</span>
                                                            </button>
                                                        );
                                                    })}
                                                </div>

                                                {/* Active step card */}
                                                <div className="h-[290px] md:h-[230px]">
                                                    {guideStep === 1 && (
                                                        <div className="h-full bg-indigo-50/30 p-5 rounded-3xl border border-indigo-100/40 space-y-1.5 animate-in fade-in zoom-in-95 duration-200 text-left overflow-y-auto custom-scrollbar pr-1">
                                                            <p className="font-black text-indigo-900 text-xs md:text-sm uppercase tracking-wider flex items-center gap-1.5"><Icon name="compass" size={15}/> 1. Sơ lược & Làm quen hệ thống</p>
                                                            <p className="text-[12px] md:text-[13px] text-slate-600 font-medium leading-relaxed">
                                                                Đây là nền tảng luyện đề và học tập Toán THPT thông minh. Bạn có thể tự học qua <b>Tài liệu học tập PDF</b> (bài tập tự luyện, đề thi thử có giải chi tiết) và <b>Video bài giảng</b> trực quan từ thầy Phạm Minh Tuấn.
                                                            </p>
                                                        </div>
                                                    )}
                                                    {guideStep === 2 && (
                                                        <div className="h-full bg-emerald-50/30 p-5 rounded-3xl border border-emerald-100/40 space-y-1.5 animate-in fade-in zoom-in-95 duration-200 text-left overflow-y-auto custom-scrollbar pr-1">
                                                    <p className="font-black text-emerald-900 text-xs md:text-sm uppercase tracking-wider flex items-center gap-1.5"><Icon name="layers" size={15}/> 2. Quy trình làm bài & Luyện tập</p>
                                                            <p className="text-[12px] md:text-[13px] text-slate-600 font-medium leading-relaxed">
                                                                - <b>Bước 1 - Chọn chương trình:</b> Từ trang chủ, chọn khối lớp bạn đang học (Khối 10, 11, 12 hoặc Ôn thi Tốt nghiệp).<br/>
                                                                - <b>Bước 2 - Lọc kỳ thi:</b> Sử dụng thanh danh mục trên cùng để chọn giai đoạn ôn tập (Giữa kỳ, Cuối kỳ, Ôn Chuyên đề).<br/>
                                                                - <b>Bước 3 - Chọn chế độ làm bài:</b><br/>
                                                                &nbsp;&nbsp;+ <i>Luyện tập:</i> Không giới hạn thời gian, có thể bấm xem ngay đáp án đúng và lời giải chi tiết của từng câu ngay sau khi trả lời để tự tích lũy kiến thức.<br/>
                                                                &nbsp;&nbsp;+ <i>Bài tập:</i> Phù hợp làm bài tập về nhà. Hệ thống tính giờ, bạn làm bài và chỉ xem được điểm cùng lời giải sau khi nhấn <b>Nộp bài</b>.<br/>
                                                                &nbsp;&nbsp;+ <i>Kiểm tra:</i> Đây là chế độ thi nghiêm ngặt. Đồng hồ đếm ngược chạy liên tục, bạn <b>không được rời khỏi màn hình hoặc chuyển sang tab khác</b>. Nếu số lần chuyển tab vượt quá giới hạn, bài thi sẽ tự động khóa và nộp điểm ngay lập tức.
                                                            </p>
                                                        </div>
                                                    )}
                                                    {guideStep === 3 && (
                                                        <div className="h-full bg-blue-50/30 p-5 rounded-3xl border border-blue-100/40 space-y-1.5 animate-in fade-in zoom-in-95 duration-200 text-left overflow-y-auto custom-scrollbar pr-1">
                                                            <p className="font-black text-blue-900 text-xs md:text-sm uppercase tracking-wider flex items-center gap-1.5"><Icon name="dumbbell" size={15}/> 3. Xem kết quả & Phân tích lời giải</p>
                                                            <p className="text-[12px] md:text-[13px] text-slate-600 font-medium leading-relaxed">
                                                                Sau khi hoàn thành và nộp bài, bạn sẽ nhận được bảng điểm chi tiết kèm biểu đồ thống kê mức độ hoàn thành. Nhấp vào từng câu hỏi để xem đáp án đúng, phương pháp giải toán và các lưu ý ghi chú quan trọng từ thầy giáo để rút kinh nghiệm cho lần thi tới.
                                                            </p>
                                                        </div>
                                                    )}
                                                </div>

                                                {/* Stepper controls */}
                                                <div className="flex justify-between items-center pt-2 border-t border-slate-100">
                                                    <button type="button" disabled={guideStep === 1} onClick={() => setGuideStep(p => Math.max(1, p - 1))} className="px-4 py-2 text-xs font-bold rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 transition-all disabled:opacity-40 disabled:cursor-not-allowed select-none">Quay lại</button>
                                                    <div className="flex gap-2">
                                                        {guideStep < 3 ? (
                                                            <button type="button" onClick={() => setGuideStep(p => Math.min(3, p + 1))} className="px-4 py-2 text-xs font-black uppercase tracking-widest rounded-xl bg-indigo-600 text-white hover:bg-indigo-700 transition-all select-none">Tiếp theo</button>
                                                        ) : (
                                                            <button type="button" onClick={() => setGuideModalType(null)} className="px-4 py-2 text-xs font-black uppercase tracking-widest rounded-xl bg-emerald-600 text-white hover:bg-emerald-700 transition-all select-none">Hoàn tất</button>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        )}

                                        {/* THÔNG TIN LIÊN HỆ */}
                                        {guideModalType === 'contact' && (
                                            <div className="space-y-4 animate-in fade-in duration-200">
                                                <div className="bg-gradient-to-br from-indigo-500/5 to-purple-500/5 p-5 rounded-2xl border border-indigo-100/50 space-y-4">
                                                    <div className="text-center pb-2 border-b border-indigo-100/30">
                                                        <p className="font-black text-indigo-900 text-sm md:text-base">Website học tập thầy Phạm Minh Tuấn</p>
                                                        <p className="text-[11px] text-slate-400 font-bold uppercase tracking-widest mt-0.5">Thông tin tác giả & Hỗ trợ</p>
                                                    </div>
                                                    
                                                    <div className="space-y-2.5 text-[12px] md:text-[13px] text-slate-600 font-medium">
                                                        <div className="flex items-center gap-3">
                                                            <div className="w-8 h-8 rounded-lg bg-indigo-500/10 text-indigo-600 flex items-center justify-center shrink-0"><Icon name="user" size={14}/></div>
                                                            <div>
                                                                <p className="text-[10px] font-bold text-slate-400 uppercase leading-none mb-0.5">Giáo viên phụ trách</p>
                                                                <p className="font-bold text-slate-800">Thầy Phạm Minh Tuấn</p>
                                                            </div>
                                                        </div>
                                                        
                                                        <div className="flex items-center gap-3">
                                                            <div className="w-8 h-8 rounded-lg bg-emerald-50/50 text-emerald-600 flex items-center justify-center shrink-0 border border-emerald-100/50"><Icon name="mail" size={14}/></div>
                                                            <div>
                                                                <p className="text-[10px] font-bold text-slate-400 uppercase leading-none mb-0.5">Email Hỗ Trợ</p>
                                                                <a href="mailto:pmtuan2609@gmail.com" className="font-bold text-indigo-600 hover:underline">pmtuan2609@gmail.com</a>
                                                            </div>
                                                        </div>
                                                        
                                                        <div className="flex items-center gap-3">
                                                            <div className="w-8 h-8 rounded-lg bg-blue-50/50 text-blue-600 flex items-center justify-center shrink-0 border border-blue-100/50"><Icon name="facebook" size={14}/></div>
                                                            <div>
                                                                <p className="text-[10px] font-bold text-slate-400 uppercase leading-none mb-0.5">Trang cá nhân</p>
                                                                <a href="https://facebook.com/minhtuanq12" target="_blank" rel="noopener noreferrer" className="font-bold text-indigo-600 hover:underline">facebook.com/minhtuanq12</a>
                                                            </div>
                                                        </div>

                                                        <div className="flex items-center gap-3">
                                                            <div className="w-8 h-8 rounded-lg bg-teal-50/50 text-teal-600 flex items-center justify-center shrink-0 border border-teal-100/50"><Icon name="message-square" size={14}/></div>
                                                            <div>
                                                                <p className="text-[10px] font-bold text-slate-400 uppercase leading-none mb-0.5">Zalo tác giả</p>
                                                                <a href="https://zalo.me/0979410414" target="_blank" rel="noopener noreferrer" className="font-bold text-indigo-600 hover:underline">0979410414 (Minh Tuấn)</a>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        )}
                                        
                                        {/* HƯỚNG DẪN EXAMS, DOCS, VIDEOS */}
                                        {['exams', 'docs', 'videos'].includes(guideModalType) && (
                                            <div className="space-y-5 animate-in fade-in duration-200">
                                                {/* Stepper bar */}
                                                <div className="flex items-center justify-between gap-1.5 md:gap-3 bg-slate-50 p-2 rounded-2xl border border-slate-100/80">
                                                    {Array.from({ length: guideModalType === 'exams' ? 4 : 3 }, (_, i) => i + 1).map((stepNum) => {
                                                        const isActive = guideStep === stepNum;
                                                        const title = stepNum === 1 ? "Khối lớp" 
                                                                    : stepNum === 2 ? "Kỳ thi" 
                                                                    : stepNum === 3 
                                                                        ? (guideModalType === 'exams' ? "Chế độ" : guideModalType === 'docs' ? "Tài liệu" : "Video")
                                                                        : "Vào thi";
                                                        return (
                                                            <button 
                                                                key={stepNum} 
                                                                type="button"
                                                                onClick={() => setGuideStep(stepNum)}
                                                                className={`flex-1 flex items-center justify-center gap-1.5 py-2 px-1 rounded-xl transition-all cursor-pointer select-none ${isActive ? 'bg-indigo-600 text-white font-bold shadow-md shadow-indigo-600/10' : 'hover:bg-slate-200/50 text-slate-500 font-bold'}`}
                                                            >
                                                                <div className={`w-5 h-5 rounded-full flex items-center justify-center text-[10px] ${isActive ? 'bg-white text-indigo-600 font-black' : 'bg-slate-200 text-slate-600 font-extrabold'}`}>
                                                                    {stepNum}
                                                                </div>
                                                                <span className="text-[10px] md:text-[11px] font-black uppercase tracking-wider hidden sm:inline">{title}</span>
                                                            </button>
                                                        );
                                                    })}
                                                </div>

                                                {/* Active step card */}
                                                <div className="h-[290px] md:h-[230px]">
                                                    {guideStep === 1 && (
                                                        <div className="h-full bg-indigo-50/30 p-5 rounded-3xl border border-indigo-100/40 space-y-1.5 animate-in fade-in zoom-in-95 duration-200 text-left overflow-y-auto custom-scrollbar pr-1">
                                                            <p className="font-black text-indigo-900 text-xs md:text-sm uppercase tracking-wider flex items-center gap-1.5"><Icon name="layers" size={15}/> 1. Chọn Khối Lớp Học</p>
                                                            <p className="text-[12px] md:text-[13px] text-slate-600 font-medium leading-relaxed">
                                                                Bấm chọn khối lớp mà bạn muốn học ở màn hình chính (bao gồm Khối 10, Khối 11, Khối 12 hoặc Ôn thi Tốt Nghiệp THPT).
                                                            </p>
                                                        </div>
                                                    )}
                                                    {guideStep === 2 && (
                                                        <div className="h-full bg-indigo-50/30 p-5 rounded-3xl border border-indigo-100/40 space-y-1.5 animate-in fade-in zoom-in-95 duration-200 text-left overflow-y-auto custom-scrollbar pr-1">
                                                            <p className="font-black text-indigo-900 text-xs md:text-sm uppercase tracking-wider flex items-center gap-1.5"><Icon name="calendar" size={15}/> 2. Chọn Kỳ Thi / Giai Đoạn Ôn Tập</p>
                                                            <p className="text-[12px] md:text-[13px] text-slate-600 font-medium leading-relaxed">
                                                                Sử dụng thanh danh mục nằm ở phía trên cùng để chọn giai đoạn ôn tập tương ứng (ví dụ: Ôn Giữa Kỳ, Ôn Cuối Kỳ, Chuyên Đề, hoặc Ôn Thi Tốt Nghiệp).
                                                            </p>
                                                        </div>
                                                    )}
                                                    {guideStep === 3 && guideModalType === 'exams' && (
                                                        <div className="h-full bg-emerald-50/30 p-5 rounded-3xl border border-emerald-100/40 space-y-1.5 animate-in fade-in zoom-in-95 duration-200 text-left overflow-y-auto custom-scrollbar pr-1">
                                                            <p className="font-black text-emerald-900 text-xs md:text-sm uppercase tracking-wider flex items-center gap-1.5"><Icon name="sliders" size={15}/> 3. Chọn Chế Độ Ôn Tập Phù Hợp</p>
                                                            <p className="text-[12px] md:text-[13px] text-slate-600 font-medium leading-relaxed mb-2">
                                                                Sử dụng bộ lọc chế độ để chọn cách làm bài thi theo ý muốn của bạn:
                                                            </p>
                                                            <div className="space-y-2 mt-1.5">
                                                                <div className="flex gap-2.5 items-start text-[11px] md:text-[12px] text-emerald-800 font-bold bg-emerald-50 px-2.5 py-2 rounded-lg border border-emerald-100/50 leading-relaxed">
                                                                    <Icon name="dumbbell" size={12} className="shrink-0 mt-0.5 text-emerald-600"/>
                                                                    <span>Luyện tập: Làm bài tự do, xem ngay đáp án & lời giải chi tiết sau mỗi câu hỏi.</span>
                                                                </div>
                                                                <div className="flex gap-2.5 items-start text-[11px] md:text-[12px] text-blue-800 font-bold bg-blue-50 px-2.5 py-2 rounded-lg border border-blue-100/50 leading-relaxed">
                                                                    <Icon name="file-text" size={12} className="shrink-0 mt-0.5 text-blue-600"/>
                                                                    <span>Bài tập: Tính giờ bình thường, nộp xong mới xem đáp án & lời giải chi tiết.</span>
                                                                </div>
                                                                <div className="flex gap-2.5 items-start text-[11px] md:text-[12px] text-rose-800 font-bold bg-rose-50 px-2.5 py-2 rounded-lg border border-rose-100/50 leading-relaxed">
                                                                    <Icon name="shield-alert" size={12} className="shrink-0 mt-0.5 text-rose-600"/>
                                                                    <span>Kiểm tra: Bấm giờ nghiêm ngặt, tự nộp nếu chuyển màn hình (chống gian lận).</span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    )}
                                                    {guideStep === 3 && guideModalType === 'docs' && (
                                                        <div className="h-full bg-emerald-50/30 p-5 rounded-3xl border border-emerald-100/40 space-y-1.5 animate-in fade-in zoom-in-95 duration-200 text-left overflow-y-auto custom-scrollbar pr-1">
                                                            <p className="font-black text-emerald-900 text-xs md:text-sm uppercase tracking-wider flex items-center gap-1.5"><Icon name="file-text" size={15}/> 3. Đọc Tài Liệu & Tải Về Máy</p>
                                                            <p className="text-[12px] md:text-[13px] text-slate-600 font-medium leading-relaxed">
                                                                Bấm vào tài liệu muốn học để xem trực tiếp. Bạn hoàn toàn có thể nhấn nút <b>Tải Về</b> để lưu tài liệu PDF về máy học tập ngoại tuyến, hoặc nhấn <b>Mở Rộng</b> để xem ở tab mới rộng hơn.
                                                            </p>
                                                        </div>
                                                    )}
                                                    {guideStep === 3 && guideModalType === 'videos' && (
                                                        <div className="h-full bg-emerald-50/30 p-5 rounded-3xl border border-emerald-100/40 space-y-1.5 animate-in fade-in zoom-in-95 duration-200 text-left overflow-y-auto custom-scrollbar pr-1">
                                                            <p className="font-black text-emerald-900 text-xs md:text-sm uppercase tracking-wider flex items-center gap-1.5"><Icon name="play-circle" size={15}/> 3. Xem Video Bài Giảng Trực Tiếp</p>
                                                            <p className="text-[12px] md:text-[13px] text-slate-600 font-medium leading-relaxed">
                                                                Bấm chọn video để phát trực tiếp bài giảng của thầy Phạm Minh Tuấn ngay trên hệ thống. Đồng thời có thể mở file tài liệu bài tập tương ứng đính kèm ngay dưới tiêu đề video để làm bài.
                                                            </p>
                                                        </div>
                                                    )}
                                                    {guideStep === 4 && guideModalType === 'exams' && (
                                                        <div className="h-full bg-blue-50/30 p-5 rounded-3xl border border-blue-100/40 space-y-1.5 animate-in fade-in zoom-in-95 duration-200 text-left overflow-y-auto custom-scrollbar pr-1">
                                                            <p className="font-black text-blue-900 text-xs md:text-sm uppercase tracking-wider flex items-center gap-1.5"><Icon name="edit-3" size={15}/> 4. Điền Thông Tin Để Bắt Đầu</p>
                                                            <p className="text-[12px] md:text-[13px] text-slate-600 font-medium leading-relaxed">
                                                                Nhấn vào đề thi, sau đó điền Họ Tên, Tên Lớp và Mật khẩu (nếu giáo viên yêu cầu) để bắt đầu làm bài kiểm tra.
                                                            </p>
                                                        </div>
                                                    )}
                                                </div>

                                                {/* Stepper controls */}
                                                <div className="flex justify-between items-center pt-2 border-t border-slate-100">
                                                    <button type="button" disabled={guideStep === 1} onClick={() => setGuideStep(p => Math.max(1, p - 1))} className="px-4 py-2 text-xs font-bold rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 transition-all disabled:opacity-40 disabled:cursor-not-allowed select-none">Quay lại</button>
                                                    <div className="flex gap-2">
                                                        {guideStep < (guideModalType === 'exams' ? 4 : 3) ? (
                                                            <button type="button" onClick={() => setGuideStep(p => Math.min(guideModalType === 'exams' ? 4 : 3, p + 1))} className="px-4 py-2 text-xs font-black uppercase tracking-widest rounded-xl bg-indigo-600 text-white hover:bg-indigo-700 transition-all select-none">Tiếp theo</button>
                                                        ) : (
                                                            <button type="button" onClick={() => setGuideModalType(null)} className="px-4 py-2 text-xs font-black uppercase tracking-widest rounded-xl bg-emerald-600 text-white hover:bg-emerald-700 transition-all select-none">Hoàn tất</button>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        )}

                                        <div className="bg-slate-50 border border-slate-200 p-3.5 rounded-xl flex gap-2.5 items-center">
                                            <Icon name="info" size={14} className="text-slate-500 shrink-0"/>
                                            <p className="text-[11.5px] md:text-[12.5px] text-slate-500 font-medium leading-normal">
                                                {guideModalType === 'teacher' 
                                                    ? 'Chúc quý Thầy/Cô sử dụng hệ thống thuận tiện và đạt hiệu quả cao trong công tác giảng dạy!'
                                                    : 'Chúc các em ôn tập thật tốt và đạt kết quả cao trong các kỳ thi sắp tới!'}
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <button onClick={() => setGuideModalType(null)} className="w-full bg-slate-800 hover:bg-slate-900 text-white py-3 rounded-xl font-black mt-6 transition-all uppercase tracking-widest text-[11px] md:text-xs">
                                        Đã hiểu
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>
                </React.Fragment>
            );
        };

        const root = ReactDOM.createRoot(document.getElementById('root'));
        root.render(<App />);
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
            const cacheKey = 'react_compiled_index_' + sourceHash;
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
                                if (key && key.startsWith('react_compiled_index_')) {
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