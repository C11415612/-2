<?php
require_once 'config.php';
require_once 'includes/Mailer.php';

$error = '';
$success = '';

// 保留表單輸入值（錯誤時不會清空）
function old($key, $default = '') {
    return isset($_POST[$key]) ? htmlspecialchars($_POST[$key]) : $default;
}

// 優先從 Config 讀取，若無則從 DB 讀取
$site_key = defined('RECAPTCHA_SITE_KEY') && !empty(RECAPTCHA_SITE_KEY) ? RECAPTCHA_SITE_KEY : '';
if (empty($site_key)) {
    $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'recaptcha_site_key'");
    $stmt->execute();
    $row = $stmt->fetch();
    $site_key = $row ? $row['setting_value'] : '';
}

$secret_key = defined('RECAPTCHA_SECRET_KEY') && !empty(RECAPTCHA_SECRET_KEY) ? RECAPTCHA_SECRET_KEY : '';
if (empty($secret_key)) {
    $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'recaptcha_secret_key'");
    $stmt->execute();
    $row = $stmt->fetch();
    $secret_key = $row ? $row['setting_value'] : '';
}

// 取得如何認識我們選項
$referral_options = [];
try {
    $referral_options = $pdo->query("SELECT name FROM referral_sources WHERE is_active = 1 ORDER BY sort_order")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $name = trim($_POST['name']);
    $name_en = trim($_POST['name_en'] ?? '');
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone'] ?? '');
    $birthday = $_POST['birthday'] ?? null;
    $id_number = trim($_POST['id_number'] ?? '');
    $tennis_level_custom = trim($_POST['tennis_level_custom'] ?? '');
    $register_role = $_POST['register_role'] ?? 'student';
    $referral_source = trim($_POST['referral_source'] ?? '');
    $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
    
    // 教練申請資料
    $coach_bio = trim($_POST['coach_bio'] ?? '');
    $coach_specialty = trim($_POST['coach_specialty'] ?? '');
    $coach_experience = intval($_POST['coach_experience'] ?? 0);
    $coach_certifications = trim($_POST['coach_certifications'] ?? '');
    
    // 1. 驗證 reCAPTCHA
    $captcha_success = true;
    if (!empty($site_key) && !empty($secret_key)) {
        $verify_url = 'https://www.google.com/recaptcha/api/siteverify';
        $data = ['secret' => $secret_key, 'response' => $recaptcha_response];
        $options = ['http' => ['header' => "Content-type: application/x-www-form-urlencoded\r\n", 'method' => 'POST', 'content' => http_build_query($data)]];
        $context = stream_context_create($options);
        $verify_result = file_get_contents($verify_url, false, $context);
        $json_result = json_decode($verify_result);
        if (!$json_result->success) $captcha_success = false;
    }
    
    // 密碼複雜度驗證
    $pw_errors = [];
    if (strlen($password) < 8) $pw_errors[] = '至少 8 個字元';
    if (!preg_match('/[A-Z]/', $password)) $pw_errors[] = '至少 1 個大寫英文';
    if (!preg_match('/[a-z]/', $password)) $pw_errors[] = '至少 1 個小寫英文';
    if (!preg_match('/[0-9]/', $password)) $pw_errors[] = '至少 1 個數字';
    if (!preg_match('/[^A-Za-z0-9]/', $password)) $pw_errors[] = '至少 1 個特殊符號';
    
    // 帳號複雜度驗證
    $un_errors = [];
    if (strlen($username) < 4) $un_errors[] = '至少 4 個字元';
    if (!preg_match('/^[a-zA-Z0-9_.\-@]+$/', $username)) $un_errors[] = '只能包含英數字、底線、點、@、-';
    
    if (!$captcha_success) {
        $error = '機器人驗證失敗，請重新勾選。';
    } elseif (!empty($un_errors)) {
        $error = '帳號不符合要求：' . implode('、', $un_errors);
    } elseif ($password !== $confirm_password) {
        $error = '兩次密碼輸入不一致';
    } elseif (!empty($pw_errors)) {
        $error = '密碼不符合要求：' . implode('、', $pw_errors);
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->rowCount() > 0) {
                $error = '此帳號已被註冊';
            } else {
                // 2. 註冊用戶
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $code = sprintf("%06d", mt_rand(0, 999999));
                $expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                
                $role = in_array($register_role, ['student', 'coach']) ? $register_role : 'student';
                $is_approved = ($role == 'coach') ? 0 : 1;
                
                $stmt = $pdo->prepare("INSERT INTO users (username, password, name, name_en, email, phone, birthday, id_last4, tennis_level_custom, referral_source, role, verification_code, verification_expiry, is_verified, is_approved) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)");
                
                $id_last4 = strlen($id_number) >= 4 ? substr($id_number, -4) : $id_number;
                if ($stmt->execute([$username, $hash, $name, $name_en, $email, $phone, $birthday ?: null, $id_last4, $tennis_level_custom ?: null, $referral_source, $role, $code, $expiry, $is_approved])) {
                    $new_user_id = $pdo->lastInsertId();
                    
                    // 如果是教練，建立 coach_profiles
                    if ($role == 'coach') {
                        $pdo->prepare("INSERT INTO coach_profiles (user_id, bio, specialty, years_experience, certifications) VALUES (?, ?, ?, ?, ?)")
                            ->execute([$new_user_id, $coach_bio, $coach_specialty, $coach_experience, $coach_certifications]);
                    }
                    
                    // 3. 檢查 SMTP 是否已設定（從 smtp_config.php）
                    require_once __DIR__ . '/smtp_config.php';
                    
                    if (defined('SMTP_HOST') && SMTP_HOST !== '') {
                        // 發送驗證信
                        $mailer = new Mailer($pdo);
                        $subject = "網球教練平台 註冊驗證";
                        $body = "<h3>歡迎加入！</h3><p>驗證碼：<b style='color:#ff6b35;'>{$code}</b></p>";
                        
                        if ($mailer->send($email, $subject, $body)) {
                            header("Location: verify_code.php?type=register&user_id=" . $new_user_id);
                            exit;
                        } else {
                            // 郵件發送失敗，但仍允許註冊，直接驗證通過
                            $pdo->prepare("UPDATE users SET is_verified = 1 WHERE id = ?")->execute([$new_user_id]);
                            $success = ($role == 'coach') ? '教練申請已送出！請等待管理員審核。' : '註冊成功！請使用帳號密碼登入。';
                        }
                    } else {
                        // SMTP 未設定，直接驗證通過
                        $pdo->prepare("UPDATE users SET is_verified = 1 WHERE id = ?")->execute([$new_user_id]);
                        $success = ($role == 'coach') ? '教練申請已送出！請等待管理員審核。' : '註冊成功！請使用帳號密碼登入。';
                    }
                } else {
                    $error = '註冊失敗';
                }
            }
        } catch(PDOException $e) { $error = $e->getMessage(); }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>註冊 - 風行網球 Fashion Tennis</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🎾</text></svg>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&family=Noto+Sans+TC:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#0f172a',
                        'primary-light': '#1e293b',
                        accent: '#10b981',
                    },
                    fontFamily: { sans: ['Inter', 'Noto Sans TC', 'sans-serif'] }
                }
            }
        }
    </script>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="bg-primary text-slate-200 min-h-screen">

<div class="min-h-screen flex">
    <!-- Left: Image -->
    <div class="hidden lg:block flex-1 relative">
        <img src="https://images.unsplash.com/photo-1622279457486-62dcc4a431d6?ixlib=rb-4.0.3&auto=format&fit=crop&w=1000&q=80" 
             class="absolute inset-0 w-full h-full object-cover">
        <div class="absolute inset-0 bg-gradient-to-l from-primary via-primary/80 to-transparent"></div>
        
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 text-center">
            <div class="w-20 h-20 bg-green-500 rounded-2xl flex items-center justify-center mx-auto mb-6 shadow-lg shadow-green-500/30">
                <span class="text-4xl">🎾</span>
            </div>
            <h2 class="text-3xl font-bold text-white mb-3">加入風行網球</h2>
            <p class="text-slate-300">開始您的專業網球學習之旅</p>
        </div>
    </div>

    <!-- Right: Form -->
    <div class="flex-1 flex flex-col justify-center px-8 py-12 lg:px-16 overflow-y-auto">
        <a href="/" class="inline-flex items-center gap-2 text-slate-400 hover:text-white transition-colors mb-8 w-fit">
            <i class="fas fa-arrow-left"></i>
            <span class="text-sm font-medium">返回首頁</span>
        </a>

        <div class="w-full max-w-lg">
            <!-- Logo -->
            <img src="https://www.mit-machining.com/store_image/jtzstennis/L173494272836.png" 
                 alt="風行網球" class="h-12 mb-6">
            
            <h1 class="text-2xl font-bold text-white mb-2">建立新帳戶</h1>
            <p class="text-slate-400 mb-6">填寫以下資訊開始學習網球</p>

            <?php if ($error): ?>
                <div class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 flex items-center gap-3">
                    <i class="fas fa-exclamation-circle text-xl"></i> 
                    <span class="font-bold"><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4 max-h-[60vh] overflow-y-auto pr-2" 
                  x-data="registerForm('<?php echo old('register_role', 'student'); ?>', '<?php echo old('username'); ?>')" x-init="checkUsername()">
                <!-- 身份選擇 -->
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-2 uppercase">註冊身份 *</label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="relative cursor-pointer">
                            <input type="radio" name="register_role" value="student" x-model="role" class="peer sr-only">
                            <div class="p-4 rounded-xl border-2 border-slate-700 peer-checked:border-accent peer-checked:bg-accent/10 transition-all text-center">
                                <i class="fas fa-user-graduate text-2xl mb-2 text-slate-400 peer-checked:text-accent"></i>
                                <div class="text-white font-bold">我是學員</div>
                                <div class="text-slate-500 text-xs mt-1">預約上課學習網球</div>
                            </div>
                        </label>
                        <label class="relative cursor-pointer">
                            <input type="radio" name="register_role" value="coach" x-model="role" class="peer sr-only">
                            <div class="p-4 rounded-xl border-2 border-slate-700 peer-checked:border-blue-500 peer-checked:bg-blue-500/10 transition-all text-center">
                                <i class="fas fa-chalkboard-teacher text-2xl mb-2 text-slate-400 peer-checked:text-blue-400"></i>
                                <div class="text-white font-bold">我是教練</div>
                                <div class="text-slate-500 text-xs mt-1">申請成為合作教練</div>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">中文姓名 *</label>
                        <input type="text" name="name" placeholder="王小明" required value="<?php echo old('name'); ?>"
                               class="w-full bg-slate-800/50 border border-slate-700 rounded-lg py-3 px-4 text-white focus:border-accent focus:ring-1 focus:ring-accent transition-all">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">英文全名</label>
                        <input type="text" name="name_en" placeholder="請輸入英文全名" value="<?php echo old('name_en'); ?>"
                               class="w-full bg-slate-800/50 border border-slate-700 rounded-lg py-3 px-4 text-white focus:border-accent focus:ring-1 focus:ring-accent transition-all">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">電子信箱 *</label>
                    <input type="email" name="email" placeholder="請輸入電子信箱" required value="<?php echo old('email'); ?>"
                           class="w-full bg-slate-800/50 border border-slate-700 rounded-lg py-3 px-4 text-white focus:border-accent focus:ring-1 focus:ring-accent transition-all">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">電話</label>
                    <input type="tel" name="phone" placeholder="0912-345-678" value="<?php echo old('phone'); ?>"
                           class="w-full bg-slate-800/50 border border-slate-700 rounded-lg py-3 px-4 text-white focus:border-accent focus:ring-1 focus:ring-accent transition-all">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">生日</label>
                        <input type="date" name="birthday" value="<?php echo old('birthday'); ?>"
                               class="w-full bg-slate-800/50 border border-slate-700 rounded-lg py-3 px-4 text-white focus:border-accent focus:ring-1 focus:ring-accent transition-all">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">網球程度</label>
                        <input type="text" name="tennis_level_custom" placeholder="例如：初學者、打過2年" value="<?php echo old('tennis_level_custom'); ?>"
                               class="w-full bg-slate-800/50 border border-slate-700 rounded-lg py-3 px-4 text-white focus:border-accent focus:ring-1 focus:ring-accent transition-all">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">身分證後4碼 (選填)</label>
                    <input type="text" name="id_number" placeholder="1234" maxlength="4" value="<?php echo old('id_number'); ?>"
                           class="w-full bg-slate-800/50 border border-slate-700 rounded-lg py-3 px-4 text-white focus:border-accent focus:ring-1 focus:ring-accent transition-all">
                </div>

                <?php if (!empty($referral_options)): ?>
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">如何認識我們</label>
                    <select name="referral_source" class="w-full bg-slate-800/50 border border-slate-700 rounded-lg py-3 px-4 text-white focus:border-accent focus:ring-1 focus:ring-accent transition-all">
                        <option value="">請選擇</option>
                        <?php foreach ($referral_options as $opt): ?>
                        <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo old('referral_source') === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- 教練專屬欄位 -->
                <div x-show="role === 'coach'" x-transition class="space-y-4 p-4 rounded-xl bg-blue-500/5 border border-blue-500/20">
                    <div class="text-blue-400 text-sm font-bold mb-2"><i class="fas fa-info-circle mr-1"></i> 教練申請資料（審核後才能開始接課）</div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">專長領域 *</label>
                        <input type="text" name="coach_specialty" placeholder="例如：底線型、網前技術、兒童教學" value="<?php echo old('coach_specialty'); ?>"
                               class="w-full bg-slate-800/50 border border-slate-700 rounded-lg py-3 px-4 text-white focus:border-accent focus:ring-1 focus:ring-accent transition-all">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">教學年資</label>
                            <input type="number" name="coach_experience" min="0" placeholder="0" value="<?php echo old('coach_experience'); ?>"
                                   class="w-full bg-slate-800/50 border border-slate-700 rounded-lg py-3 px-4 text-white focus:border-accent focus:ring-1 focus:ring-accent transition-all">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">專業證照</label>
                            <input type="text" name="coach_certifications" placeholder="例如：C級教練證" value="<?php echo old('coach_certifications'); ?>"
                                   class="w-full bg-slate-800/50 border border-slate-700 rounded-lg py-3 px-4 text-white focus:border-accent focus:ring-1 focus:ring-accent transition-all">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">自我介紹</label>
                        <textarea name="coach_bio" rows="3" placeholder="簡述您的教學理念和經歷" 
                                  class="w-full bg-slate-800/50 border border-slate-700 rounded-lg py-3 px-4 text-white focus:border-accent focus:ring-1 focus:ring-accent transition-all"><?php echo old('coach_bio'); ?></textarea>
                    </div>
                </div>

                <div class="border-t border-slate-700 pt-4 mt-4">
                    <div class="mb-4">
                        <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">帳號 *</label>
                        <input type="text" name="username" placeholder="至少4字元，英數字、底線、點" required 
                               x-model="username" @input="checkUsername()"
                               class="w-full bg-slate-800/50 border border-slate-700 rounded-lg py-3 px-4 text-white focus:border-accent focus:ring-1 focus:ring-accent transition-all">
                        <div x-show="username.length > 0" class="mt-2 text-xs space-y-1">
                            <div :class="usernameLen ? 'text-emerald-400' : 'text-red-400'">
                                <i class="fas" :class="usernameLen ? 'fa-check-circle' : 'fa-times-circle'"></i> 至少 4 個字元
                            </div>
                            <div :class="usernameChars ? 'text-emerald-400' : 'text-red-400'">
                                <i class="fas" :class="usernameChars ? 'fa-check-circle' : 'fa-times-circle'"></i> 只能包含英數字、底線、點、@、-
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">密碼 *</label>
                            <input type="password" name="password" placeholder="至少8字元" required 
                                   x-model="pw" @input="checkPw()"
                                   class="w-full bg-slate-800/50 border border-slate-700 rounded-lg py-3 px-4 text-white focus:border-accent focus:ring-1 focus:ring-accent transition-all">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">確認密碼 *</label>
                            <input type="password" name="confirm_password" placeholder="再次輸入密碼" required 
                                   x-model="pw2"
                                   class="w-full bg-slate-800/50 border border-slate-700 rounded-lg py-3 px-4 text-white focus:border-accent focus:ring-1 focus:ring-accent transition-all">
                        </div>
                    </div>
                    <!-- 密碼強度指示 -->
                    <div x-show="pw.length > 0" class="mt-3 space-y-2">
                        <div class="flex gap-1 h-1.5">
                            <div class="flex-1 rounded-full transition-all" :class="pwScore >= 1 ? (pwScore <= 2 ? 'bg-red-500' : pwScore <= 3 ? 'bg-amber-500' : 'bg-emerald-500') : 'bg-slate-700'"></div>
                            <div class="flex-1 rounded-full transition-all" :class="pwScore >= 2 ? (pwScore <= 2 ? 'bg-red-500' : pwScore <= 3 ? 'bg-amber-500' : 'bg-emerald-500') : 'bg-slate-700'"></div>
                            <div class="flex-1 rounded-full transition-all" :class="pwScore >= 3 ? (pwScore <= 3 ? 'bg-amber-500' : 'bg-emerald-500') : 'bg-slate-700'"></div>
                            <div class="flex-1 rounded-full transition-all" :class="pwScore >= 4 ? 'bg-emerald-500' : 'bg-slate-700'"></div>
                            <div class="flex-1 rounded-full transition-all" :class="pwScore >= 5 ? 'bg-emerald-500' : 'bg-slate-700'"></div>
                        </div>
                        <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                            <div :class="hasLen ? 'text-emerald-400' : 'text-red-400'">
                                <i class="fas" :class="hasLen ? 'fa-check-circle' : 'fa-times-circle'"></i> 至少 8 字元
                            </div>
                            <div :class="hasUpper ? 'text-emerald-400' : 'text-red-400'">
                                <i class="fas" :class="hasUpper ? 'fa-check-circle' : 'fa-times-circle'"></i> 大寫英文
                            </div>
                            <div :class="hasLower ? 'text-emerald-400' : 'text-red-400'">
                                <i class="fas" :class="hasLower ? 'fa-check-circle' : 'fa-times-circle'"></i> 小寫英文
                            </div>
                            <div :class="hasNum ? 'text-emerald-400' : 'text-red-400'">
                                <i class="fas" :class="hasNum ? 'fa-check-circle' : 'fa-times-circle'"></i> 數字
                            </div>
                            <div :class="hasSpecial ? 'text-emerald-400' : 'text-red-400'">
                                <i class="fas" :class="hasSpecial ? 'fa-check-circle' : 'fa-times-circle'"></i> 特殊符號
                            </div>
                            <div x-show="pw2.length > 0" :class="pw === pw2 ? 'text-emerald-400' : 'text-red-400'">
                                <i class="fas" :class="pw === pw2 ? 'fa-check-circle' : 'fa-times-circle'"></i> 密碼一致
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if ($site_key): ?>
                <div class="flex justify-center pt-2">
                    <div class="g-recaptcha" data-sitekey="<?php echo $site_key; ?>" data-theme="dark"></div>
                </div>
                <?php endif; ?>
                
                <button type="submit" class="btn btn-primary w-full btn-lg mt-4 disabled:opacity-40 disabled:cursor-not-allowed"
                        :disabled="!formValid()" :title="formValid() ? '' : '請先完成所有必填項目'">
                    <i class="fas fa-user-plus"></i>
                    <span x-text="role === 'coach' ? '提交教練申請' : '註冊帳戶'">註冊帳戶</span>
                </button>
            </form>

            <p class="mt-6 text-center text-slate-400">
                已經有帳號？
                <a href="login.php" class="text-accent font-medium hover:underline">直接登入</a>
            </p>
        </div>
    </div>
</div>

<script>
function registerForm(initialRole, initialUsername) {
    return {
        role: initialRole || 'student',
        username: initialUsername || '', pw: '', pw2: '',
        usernameLen: false, usernameChars: false,
        hasLen: false, hasUpper: false, hasLower: false, hasNum: false, hasSpecial: false, pwScore: 0,
        checkUsername() {
            this.usernameLen = this.username.length >= 4;
            this.usernameChars = /^[a-zA-Z0-9_.@\-]*$/.test(this.username);
        },
        checkPw() {
            this.hasLen = this.pw.length >= 8;
            this.hasUpper = /[A-Z]/.test(this.pw);
            this.hasLower = /[a-z]/.test(this.pw);
            this.hasNum = /[0-9]/.test(this.pw);
            this.hasSpecial = /[^A-Za-z0-9]/.test(this.pw);
            this.pwScore = [this.hasLen, this.hasUpper, this.hasLower, this.hasNum, this.hasSpecial].filter(Boolean).length;
        },
        formValid() {
            return this.usernameLen && this.usernameChars
                && this.hasLen && this.hasUpper && this.hasLower && this.hasNum && this.hasSpecial
                && this.pw.length > 0 && this.pw === this.pw2;
        }
    }
}
</script>
</body>
</html>
