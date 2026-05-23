<?php
/**
 * SMTP 郵件設定
 * 
 * Gmail SMTP 設定範例：
 *   SMTP_HOST = smtp.gmail.com
 *   SMTP_PORT = 587
 *   SMTP_USER = your-email@gmail.com
 *   SMTP_PASS = 16位應用程式密碼（非登入密碼）
 *
 * 取得 Gmail 應用程式密碼：
 *   1. https://myaccount.google.com/security → 啟用兩步驟驗證
 *   2. https://myaccount.google.com/apppasswords → 產生應用程式密碼
 */

// SMTP 伺服器
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);         // 587 (TLS) 或 465 (SSL)
define('SMTP_USER', 'zyans0626.p@gmail.com');
define('SMTP_PASS', 'qqsq avon evbh hksj');

// 寄件人資訊
define('SMTP_FROM_EMAIL', '');    // 留空則使用 SMTP_USER
define('SMTP_FROM_NAME', '風行網球');
