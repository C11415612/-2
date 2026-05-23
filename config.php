<?php
// ============================================
// 環境設定
// ============================================
define('DEBUG_MODE', true); // 正式上線請改為 false
if (DEBUG_MODE) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}

// ============================================
// 資料庫連線
// ============================================
define('DB_SERVER', 'localhost'); 
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'tennis_coach');

try {
    $pdo = new PDO("mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch(PDOException $e) {
    die('a
    <div style="font-family:sans-serif; text-align:center; padding:50px;">
        <h1 style="color:#e74c3c;">系統錯誤</h1>
        <p>無法連線至資料庫，請聯繫管理員。</p>
        <small style="color:#999;">錯誤訊息: ' . htmlspecialchars($e->getMessage()) . '</small>
    </div>
    ');
}

// ============================================
// 時區 & Session
// ============================================
date_default_timezone_set('Asia/Taipei');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// 共用常數 — 區域 / 教練等級 / 預約狀態 / 訂單狀態
// ============================================
$REGIONS = [
    'north'   => '北部',
    'central' => '中部',
    'south'   => '南部',
    'east'    => '東部',
];

$COACH_LEVELS = [
    'beginner'     => '初階教練',
    'intermediate' => '中階教練',
    'advanced'     => '高階教練',
    'elite'        => '菁英教練',
];

$BOOKING_STATUS_LABELS = [
    'pending'     => ['label' => '待確認', 'class' => 'bg-amber-500/20 text-amber-400',   'icon' => 'fa-hourglass-half'],
    'confirmed'   => ['label' => '已確認', 'class' => 'bg-emerald-500/20 text-emerald-400','icon' => 'fa-check-circle'],
    'reserved'    => ['label' => '已預約', 'class' => 'bg-blue-500/20 text-blue-400',     'icon' => 'fa-clock'],
    'in_progress' => ['label' => '進行中', 'class' => 'bg-yellow-500/20 text-yellow-400', 'icon' => 'fa-play'],
    'completed'   => ['label' => '已完成', 'class' => 'bg-indigo-500/20 text-indigo-400', 'icon' => 'fa-check'],
    'cancelled'   => ['label' => '已取消', 'class' => 'bg-red-500/20 text-red-400',       'icon' => 'fa-times'],
    'rejected'    => ['label' => '已拒絕', 'class' => 'bg-red-500/20 text-red-400',       'icon' => 'fa-ban'],
    'noshow'      => ['label' => '未出席', 'class' => 'bg-slate-500/20 text-slate-400',   'icon' => 'fa-user-slash'],
];

$ORDER_STATUS_LABELS = [
    'pending'   => ['label' => '待付款', 'class' => 'bg-yellow-500/20 text-yellow-400', 'icon' => 'fa-clock'],
    'paid'      => ['label' => '待審核', 'class' => 'bg-amber-500/20 text-amber-400',   'icon' => 'fa-hourglass-half'],
    'verified'  => ['label' => '已完成', 'class' => 'bg-green-500/20 text-green-400',   'icon' => 'fa-check-circle'],
    'cancelled' => ['label' => '已取消', 'class' => 'bg-red-500/20 text-red-400',       'icon' => 'fa-times-circle'],
];

// ============================================
// 認證 & 權限
// ============================================
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * 懶性觸發：將已過期的待簽到補單自動標記為已完成
 * 每次頁面載入時檢查一次（輕量查詢）
 */
function autoCompleteExpiredSignins() {
    global $pdo;
    try {
        $stmt = $pdo->prepare("UPDATE bookings SET status = 'completed', completed_at = NOW() WHERE status = 'pending_signin' AND signin_expires_at IS NOT NULL AND signin_expires_at < NOW()");
        $stmt->execute();
    } catch (Exception $e) {
        error_log("autoCompleteExpiredSignins error: " . $e->getMessage());
    }
}
// 自動執行（每次頁面載入）
if (isset($pdo)) {
    autoCompleteExpiredSignins();
}

function redirect($url) {
    if (!headers_sent()) {
        header("Location: $url");
    } else {
        echo '<script>window.location.href="' . $url . '";</script>';
    }
    exit;
}

function requireRole($role) {
    if (!isLoggedIn()) { redirect('/login.php'); }
    $allowed_roles = is_array($role) ? $role : [$role];
    if (!in_array($_SESSION['role'], $allowed_roles)) { redirect('/login.php'); }
}

function requireLogin() {
    if (!isLoggedIn()) { redirect('/login.php'); }
}

// ============================================
// 系統設定（從 DB 讀取，帶快取）
// ============================================
$SYSTEM_SETTINGS = [];
function getSetting($key, $default = '') {
    global $pdo, $SYSTEM_SETTINGS;
    
    if (isset($SYSTEM_SETTINGS[$key])) {
        return $SYSTEM_SETTINGS[$key];
    }

    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $res = $stmt->fetch();
        $val = $res ? $res['setting_value'] : $default;
        $SYSTEM_SETTINGS[$key] = $val;
        return $val;
    } catch (Exception $e) {
        return $default;
    }
}

// Google reCAPTCHA v2 金鑰（到 https://www.google.com/recaptcha/admin 申請）
define('RECAPTCHA_SITE_KEY', '');      // 網站金鑰（公鑰）
define('RECAPTCHA_SECRET_KEY', '');    // 密鑰（私鑰）

/**
 * 計算場地費用（根據尖峰/離峰時段，以半小時為單位）
 * @param int $venue_id 場地ID
 * @param string $start_time 開始時間 (YYYY-MM-DD HH:MM:SS 格式)
 * @param float $duration_hours 時數
 * @return array ['venue_fee' => 場地費, 'light_fee' => 燈光費, 'total' => 總計, 'breakdown' => 明細]
 */
function calculateVenueFee($venue_id, $start_time, $duration_hours) {
    global $pdo;
    
    try {
        // 取得場地基本費率
        $stmt = $pdo->prepare("SELECT hourly_rate, light_fee FROM venues WHERE id = ?");
        $stmt->execute([$venue_id]);
        $base_venue = $stmt->fetch();
        $base_hourly = floatval($base_venue['hourly_rate'] ?? 0);
        $base_light = floatval($base_venue['light_fee'] ?? 0);
        
        // 讀取尖峰時段（用於加收尖峰使用費）
        $stmt = $pdo->prepare("
            SELECT * FROM venue_peak_periods 
            WHERE venue_id = ? AND is_active = 1 
            ORDER BY start_time
        ");
        $stmt->execute([$venue_id]);
        $peak_periods = $stmt->fetchAll();
        
        // 以半小時為單位計算
        $half_hours = intval($duration_hours * 2);
        $venue_fee = 0;
        $light_fee = 0;
        $peak_surcharge = 0;
        $breakdown = [];
        
        // 取得課程開始時間
        $start_datetime = new DateTime($start_time);
        
        // 嘗試日間/夜間費率（舊版相容）
        $rates = null;
        if (count($peak_periods) == 0) {
            $stmt = $pdo->prepare("
                SELECT day_start, day_end, day_hourly_rate, day_light_fee, night_hourly_rate, night_light_fee
                FROM venue_time_rates WHERE venue_id = ?
            ");
            $stmt->execute([$venue_id]);
            $rates = $stmt->fetch();
        }
        
        for ($i = 0; $i < $half_hours; $i++) {
            $current_time = clone $start_datetime;
            $current_time->modify('+' . ($i * 30) . ' minutes');
            $check_time = $current_time->format('H:i:s');
            
            // 基本場地費（半小時）
            $slot_venue = $base_hourly / 2;
            $slot_light = $base_light / 2;
            $slot_surcharge = 0;
            $slot_type = 'default';
            $slot_period_name = '';
            
            // 日間/夜間費率覆蓋（舊版相容，無尖峰時段時使用）
            if ($rates && count($peak_periods) == 0) {
                $day_start_time = date('H:i:s', strtotime($rates['day_start']));
                $day_end_time = date('H:i:s', strtotime($rates['day_end']));
                $is_day = ($check_time >= $day_start_time && $check_time < $day_end_time);
                if ($is_day) {
                    $slot_venue = floatval($rates['day_hourly_rate']) / 2;
                    $slot_light = floatval($rates['day_light_fee']) / 2;
                } else {
                    $slot_venue = floatval($rates['night_hourly_rate']) / 2;
                    $slot_light = floatval($rates['night_light_fee']) / 2;
                }
            }
            
            // 尖峰時段加收（額外加在基本費上面）
            if (count($peak_periods) > 0) {
                foreach ($peak_periods as $period) {
                    $period_start = date('H:i:s', strtotime($period['start_time']));
                    $period_end = date('H:i:s', strtotime($period['end_time']));
                    
                    if ($check_time >= $period_start && $check_time < $period_end) {
                        if ($period['is_peak'] && floatval($period['peak_surcharge']) > 0) {
                            $slot_surcharge = floatval($period['peak_surcharge']) / 2;
                            $slot_type = 'peak';
                            $slot_period_name = $period['period_name'] ?? '';
                        } else {
                            $slot_type = 'off-peak';
                            $slot_period_name = $period['period_name'] ?? '';
                        }
                        break;
                    }
                }
            }
            
            $venue_fee += $slot_venue;
            $light_fee += $slot_light;
            $peak_surcharge += $slot_surcharge;
            
            if ($slot_surcharge > 0 || $slot_type !== 'default') {
                $breakdown[] = [
                    'time' => substr($check_time, 0, 5),
                    'type' => $slot_type,
                    'period_name' => $slot_period_name,
                    'venue' => $slot_venue,
                    'light' => $slot_light,
                    'surcharge' => $slot_surcharge
                ];
            }
        }
        
        return [
            'venue_fee' => $venue_fee,
            'light_fee' => $light_fee,
            'peak_surcharge' => $peak_surcharge,
            'total' => $venue_fee + $light_fee + $peak_surcharge,
            'breakdown' => $breakdown
        ];
        
    } catch (Exception $e) {
        error_log("calculateVenueFee error: " . $e->getMessage());
        return ['venue_fee' => 0, 'light_fee' => 0, 'peak_surcharge' => 0, 'total' => 0, 'breakdown' => []];
    }
}

define('SITE_NAME', '風行網球');

/**
 * 發送系統通知
 * @param int $user_id 接收者用戶ID
 * @param string $type 通知類型 (booking_cancelled, booking_rejected, booking_confirmed, system)
 * @param string $title 通知標題
 * @param string $message 通知內容
 * @param string|null $link 點擊導向連結
 */
function sendNotification($user_id, $type, $title, $message, $link = null) {
    global $pdo;
    try {
        $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)")
            ->execute([$user_id, $type, $title, $message, $link]);
    } catch (Exception $e) {
        error_log("sendNotification error: " . $e->getMessage());
    }
}

/**
 * 發送郵件通知（優先使用 SMTP，回退到 PHP mail()）
 * @param string $to 收件人郵箱
 * @param string $subject 郵件主題
 * @param string $body 郵件內容（純文字或 HTML）
 * @param string $from 寄件人郵箱 (僅 fallback 用)
 * @return bool 是否發送成功
 */
function sendEmail($to, $subject, $body, $from = 'noreply@tennis.com') {
    if (empty($to)) {
        error_log("sendEmail: 收件人郵箱為空");
        return false;
    }
    
    // 檢查郵件格式
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("sendEmail: 無效的收件人郵箱格式: $to");
        return false;
    }
    
    error_log("sendEmail attempting: to=$to, subject=$subject");
    
    // 優先嘗試 SMTP 發送
    require_once __DIR__ . '/includes/SmtpMailer.php';
    
    $mailer = SmtpMailer::fromConfig();
    if ($mailer) {
        $site_name = defined('SITE_NAME') ? SITE_NAME : '風行網球';
        $htmlBody = SmtpMailer::wrapHtml(nl2br(htmlspecialchars($body)), $site_name);
        $result = $mailer->send($to, $subject, $htmlBody);
        
        if ($result) {
            error_log("sendEmail SMTP SUCCESS: to=$to, subject=$subject");
        } else {
            error_log("sendEmail SMTP FAILED: to=$to | " . implode(' | ', $mailer->getLog()));
        }
        return $result;
    }
    
    // SMTP 未設定，回退到 PHP mail()
    error_log("sendEmail 警告: SMTP 未設定，使用 PHP mail()");
    $headers = "From: $from\r\n";
    $headers .= "Reply-To: $from\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    
    $result = mail($to, $subject, $body, $headers);
    
    if (!$result) {
        error_log("sendEmail FAILED: to=$to, subject=$subject");
    } else {
        error_log("sendEmail SUCCESS: to=$to, subject=$subject");
    }
    
    return $result;
}

/**
 * 取得用戶未讀通知數量
 */
function getUnreadNotificationCount($user_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * 取得用戶最近通知
 */
function getRecentNotifications($user_id, $limit = 10) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$user_id, $limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

// ============================================
// 共用工具函式
// ============================================

/**
 * 安全處理檔案上傳（僅允許圖片）
 * @return string|null 成功回傳檔名，失敗回傳 null
 */
function handleImageUpload($field_name, $prefix = 'img') {
    if (!isset($_FILES[$field_name]) || $_FILES[$field_name]['error'] !== 0) {
        return null;
    }
    $ext = strtolower(pathinfo($_FILES[$field_name]['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowed)) {
        return null;
    }
    $filename = $prefix . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    $upload_dir = __DIR__ . '/assets/images/';
    if (move_uploaded_file($_FILES[$field_name]['tmp_name'], $upload_dir . $filename)) {
        return $filename;
    }
    return null;
}

/**
 * 格式化電話號碼 (0912345678 → 0912-345-678)
 */
function formatPhone($phone) {
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) === 10) {
        return substr($digits, 0, 4) . '-' . substr($digits, 4, 3) . '-' . substr($digits, 7, 3);
    }
    return $phone;
}
