<?php
require_once 'config.php';

if (isLoggedIn()) {
    if ($_SESSION['role'] == 'admin') header("Location: admin/verify_payment.php");
    elseif ($_SESSION['role'] == 'coach') header("Location: coach/schedule.php");
    else header("Location: student/booking.php");
    exit;
}

$error = '';

// reCAPTCHA 金鑰
$site_key = defined('RECAPTCHA_SITE_KEY') ? RECAPTCHA_SITE_KEY : '';
$secret_key = defined('RECAPTCHA_SECRET_KEY') ? RECAPTCHA_SECRET_KEY : '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    // 驗證 reCAPTCHA
    $captcha_ok = true;
    if (!empty($site_key) && !empty($secret_key)) {
        $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
        $verify_url = 'https://www.google.com/recaptcha/api/siteverify';
        $data = ['secret' => $secret_key, 'response' => $recaptcha_response];
        $options = ['http' => ['header' => "Content-type: application/x-www-form-urlencoded\r\n", 'method' => 'POST', 'content' => http_build_query($data)]];
        $context = stream_context_create($options);
        $result = @file_get_contents($verify_url, false, $context);
        if ($result) {
            $json = json_decode($result);
            if (!$json || !$json->success) $captcha_ok = false;
        }
    }

    if (!$captcha_ok) {
        $error = '請完成機器人驗證';
    } elseif (empty($username) || empty($password)) {
        $error = '請輸入帳號與密碼';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                if (!$user['is_active']) {
                    $error = '此帳號已被停用';
                } elseif ($user['role'] == 'coach' && empty($user['is_approved'])) {
                    $error = '您的教練申請尚在審核中，請耐心等待管理員通知。';
                } else {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['avatar'] = $user['avatar'];
                    
                    if ($user['role'] == 'admin') {
                        header("Location: admin/settings.php");
                    } elseif ($user['role'] == 'coach') {
                        header("Location: coach/schedule.php");
                    } else {
                        header("Location: student/booking.php");
                    }
                    exit;
                }
            } else {
                $error = '帳號或密碼錯誤';
            }
        } catch(PDOException $e) { $error = '系統錯誤'; }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登入 - 風行網球 Fashion Tennis</title>
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
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <?php if ($site_key): ?>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php endif; ?>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="bg-primary text-slate-200 min-h-screen">

<div class="min-h-screen flex">
    <div class="flex-1 flex flex-col justify-center px-8 py-12 lg:px-24">
        <a href="/" class="inline-flex items-center gap-2 text-slate-400 hover:text-white transition-colors mb-12 w-fit">
            <i class="fas fa-arrow-left"></i>
            <span class="text-sm font-medium">返回首頁</span>
        </a>

        <div class="w-full max-w-md">
            <img src="https://www.mit-machining.com/store_image/jtzstennis/L173494272836.png" 
                 alt="風行網球" class="h-14 mb-8">
            
            <h1 class="text-3xl font-bold text-white mb-2">歡迎回來</h1>
            <p class="text-slate-400 mb-8">登入您的帳戶以繼續使用服務</p>

            <?php if ($error): ?>
            <div class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 flex items-center gap-3">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" class="space-y-6" x-data="{ showPass: false }">
                <div class="form-group">
                    <label>帳號</label>
                    <div class="relative">
                        <i class="fas fa-user absolute left-4 top-1/2 -translate-y-1/2 text-slate-500"></i>
                        <input type="text" name="username" placeholder="請輸入帳號" required 
                               class="pl-12">
                    </div>
                </div>

                <div class="form-group">
                    <label>密碼</label>
                    <div class="relative">
                        <i class="fas fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-slate-500"></i>
                        <input :type="showPass ? 'text' : 'password'" name="password" placeholder="請輸入密碼" required 
                               class="pl-12 pr-12">
                        <button type="button" @click="showPass = !showPass" 
                                class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 hover:text-white">
                            <i class="fas" :class="showPass ? 'fa-eye-slash' : 'fa-eye'"></i>
                        </button>
                    </div>
                </div>

                <?php if ($site_key): ?>
                <div class="flex justify-center">
                    <div class="g-recaptcha" data-sitekey="<?php echo htmlspecialchars($site_key); ?>" data-theme="dark"></div>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary w-full btn-lg">
                    <i class="fas fa-sign-in-alt"></i>
                    登入
                </button>
            </form>

            <p class="mt-8 text-center text-slate-400">
                還沒有帳號？
                <a href="register.php" class="text-accent font-medium hover:underline">立即註冊</a>
            </p>

            <div class="flex items-center gap-4 my-8">
                <div class="flex-1 h-px bg-slate-700"></div>
                <span class="text-slate-500 text-sm">或</span>
                <div class="flex-1 h-px bg-slate-700"></div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <a href="https://forms.gle/GmogtCUekBRXB6cL9" target="_blank" 
                   class="btn btn-secondary text-sm">
                    <i class="fab fa-google"></i>
                    線上報名
                </a>
                <a href="tel:0981881799" class="btn btn-secondary text-sm">
                    <i class="fas fa-phone"></i>
                    電話諮詢
                </a>
            </div>
        </div>
    </div>

    <div class="hidden lg:block flex-1 relative">
        <img src="https://images.unsplash.com/photo-1554068865-24cecd4e34b8?ixlib=rb-4.0.3&auto=format&fit=crop&w=1000&q=80" 
             class="absolute inset-0 w-full h-full object-cover">
        <div class="absolute inset-0 bg-gradient-to-r from-primary via-primary/80 to-transparent"></div>
        
        <div class="absolute bottom-16 left-16 right-16 max-w-lg">
            <div class="bg-slate-900/80 backdrop-blur-xl border border-white/10 p-8 rounded-2xl">
                <div class="flex items-center gap-4 mb-4">
                    <div class="w-12 h-12 rounded-full bg-green-500 flex items-center justify-center text-white text-xl">
                        🎾
                    </div>
                    <div>
                        <div class="text-white font-bold">風行網球</div>
                        <div class="text-slate-400 text-sm">Fashion Tennis</div>
                    </div>
                </div>
                <p class="text-slate-300 text-lg leading-relaxed">
                    「讓網球風行，是多少網球愛好者的嚮往。我們由國立師範大學選手組成，讓教育結合專長，學以致用。」
                </p>
            </div>
        </div>
    </div>
</div>

</body>
</html>
