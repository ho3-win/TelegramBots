<?php
// config.php


define('BOT_TOKEN', ' '); // توکن ربات 


define('OWNER_ID',  ); // آیدی عددی ادمین اصلی


define('DB_FILE', 'database.json'); // نام فایل ذخیره کاربران

// دکمه اسپانسر
define('SPONSOR_TEXT', 'بهترین موزیک های رپی تلگرام🎧🔥');
define('SPONSOR_URL', 'https://t.me/qolabsang');

// توابع کمکی برای دیتابیس 
function getDB() {
    if (!file_exists(DB_FILE)) {
        $initial = [
            'users' => [],
            'admins' => [OWNER_ID],
            'channels' => [],
            'webhooks' => [],
            'status' => 'on',
            'steps' => []
        ];
        file_put_contents(DB_FILE, json_encode($initial, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    return json_decode(file_get_contents(DB_FILE), true);
}

function saveDB($data) {
    file_put_contents(DB_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

?>
