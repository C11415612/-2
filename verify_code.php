<?php
require_once 'config.php';

$error = '';
$message = '';
$type = $_GET['type'] ?? ''; 
$user_id = $_GET['user_id'] ?? 0;

if (!$user_id) die("Invalid Request");

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $code = trim($_POST['code']);
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND verification_code = ? AND verification_expiry > NOW()");
    $stmt->execute([$user_id, $code]);
    $user = $stmt->fetch();

    if ($user) {
        $pdo->prepare("UPDATE users SET verification_code = NULL, verification_expiry = NULL, is_verified = 1 WHERE id = ?")->execute([$user_id]);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['avatar'] = $user['avatar'];
        $redirect = ($user['role'] == 'admin') ? 'admin/verify_payment.php' : (($user['role'] == 'coach') ? 'coach/schedule.php' : 'student/booking.php');
        header("Location: $redirect");
        exit;
    } else {
        $error = '驗證碼無效或已過期';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>安全驗證 - <?php echo defined('SITE_NAME') ? SITE_NAME : 'Sinedepth'; ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>⚡</text></svg>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: { colors: { primary: '#0f172a', accent: '#ff6b35' } } }
        }
    </script>
    <style>
        body { background-color: #0f172a; color: white; font-family: 'Inter', sans-serif; }
        .glass-card {
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 20px 50px rgba(0,0,0,0.5);
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen relative overflow-hidden">
    
    <div class="absolute top-[-10%] left-[-10%] w-[500px] h-[500px] bg-accent/20 rounded-full blur-[120px]"></div>
    <div class="absolute bottom-[-10%] right-[-10%] w-[500px] h-[500px] bg-blue-600/20 rounded-full blur-[120px]"></div>

    <div class="glass-card w-full max-w-md p-8 rounded-2xl relative z-10 text-center">
        <div class="w-20 h-20 bg-accent/20 rounded-full flex items-center justify-center mx-auto mb-6 text-accent">
            <i class="fas fa-shield-alt text-4xl"></i>
        </div>
        
        <h2 class="text-3xl font-bold mb-2">身分驗證</h2>
        <p class="text-slate-400 mb-8">
            我們已發送 6 位數代碼至您的信箱。<br>請查收並輸入以完成登入。
        </p>

        <?php if ($error): ?>
            <div class="bg-red-500/10 border border-red-500/20 text-red-400 p-3 rounded-lg mb-6 text-sm">
                <i class="fas fa-times-circle mr-2"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-6">
            <div>
                <input type="text" name="code" placeholder="000000" maxlength="6" autofocus
                       class="w-full bg-black/20 border border-white/10 rounded-xl py-4 text-center text-3xl font-mono tracking-[0.5em] focus:outline-none focus:border-accent focus:bg-black/40 transition-all placeholder-white/10 text-white">
            </div>
            
            <button type="submit" class="w-full bg-accent hover:bg-orange-600 text-white font-bold py-4 rounded-xl transition-all shadow-lg shadow-accent/25">
                驗證並登入 <i class="fas fa-arrow-right ml-2"></i>
            </button>
        </form>
        
        <div class="mt-8 text-sm text-slate-500">
            收不到信件？ <a href="login.php" class="text-white hover:text-accent transition-colors">重新發送</a>
        </div>
    </div>

</body>
</html>
