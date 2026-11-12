<?php
/* ########################## ######################### */
/*
╭─────────────────────────────────────────────────────────────╮
 
                          ✦ ذکر منبع الزامی است

                       👨‍💻 نویسنده : @ho3_win

                     

╰─────────────────────────────────────────────────────────────╯
*/
/* ####################### ############################ */
// bot.php
error_reporting(0);
require_once 'config.php';
require_once 'ads.php';




function generateFakeHash(): string {
    return bin2hex(random_bytes(32)); // 64 کاراکتر
}

// دریافت آپدیت‌ها از تلگرام
$update = json_decode(file_get_contents('php://input'));

function bot($method, $datas = []) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);
    $res = curl_exec($ch);
    return json_decode($res, true);
}

if (!$update) exit;

$db = getDB();

// استخراج اطلاعات پیام 
if (isset($update->message)) {
    $chat_id = $update->message->chat->id;
    $from_id = $update->message->from->id;
    $text = $update->message->text;
    $username = isset($update->message->from->username) ? "@" . $update->message->from->username : "ندارد";
    $first_name = $update->message->from->first_name;
    $message_id = $update->message->message_id;
    $is_callback = false;
} elseif (isset($update->callback_query)) {
    $chat_id = $update->callback_query->message->chat->id;
    $from_id = $update->callback_query->from->id;
    $data = $update->callback_query->data;
    $username = isset($update->callback_query->from->username) ? "@" . $update->callback_query->from->username : "ندارد";
    $first_name = $update->callback_query->from->first_name;
    $message_id = $update->callback_query->message->message_id;
    $is_callback = true;
}

// ثبت‌نام کاربر جدید
if (isset($update->message) && !isset($db['users'][$from_id])) {
    $db['users'][$from_id] = [
        'name' => $first_name,
        'username' => $username,
        'id' => $from_id,
        'date' => date('Y-m-d H:i'),
        'banned' => false
    ];
    saveDB($db);
}


if ($db['status'] == 'off' && $from_id != OWNER_ID && !in_array($from_id, $db['admins'])) {
    if (!$is_callback) {
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ ربات موقتاً خاموش و در دست تعمیر است."]);
    }
    exit;
}

// بررسی بن بودن کاربر
if (isset($db['users'][$from_id]['banned']) && $db['users'][$from_id]['banned'] == true) {
    if (!$is_callback) {
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ شما از دسترسی به این ربات محروم (بلاک) شده‌اید."]);
    }
    exit;
}

if (
    !$is_callback &&
    $from_id != OWNER_ID &&
    !in_array($from_id, $db['admins']) &&
    !empty($db['channels'])
) {
    $not_joined = [];

    foreach ($db['channels'] as $channel) {
        $channel = ltrim(trim($channel), "@");
        $check = bot('getChatMember', [
            'chat_id' => "@".$channel,
            'user_id' => $from_id
        ]);
        $status = $check['result']['status'] ?? '';
        if (!in_array($status, ['member','administrator','creator'])) {
            $not_joined[] = $channel;
        }
    }

    if (!empty($not_joined)) {
        $buttons = [];
        foreach ($not_joined as $channel) {
            $buttons[] = [[
                'text' => "📢 @$channel",
                'url' => "https://t.me/$channel"
            ]];
        }
        $buttons[] = [[
            'text' => "✅ عضو شدم",
            'callback_data' => "check_join"
        ]];

        bot('sendMessage',[
            'chat_id'=>$chat_id,
            'text'=>"⚠️ ابتدا در کانال‌های زیر عضو شوید و سپس روی «✅ عضو شدم» بزنید.",
            'reply_markup'=>json_encode([
                'inline_keyboard'=>$buttons
            ])
        ]);
        exit;
    }
}

$main_menu = json_encode([
    'inline_keyboard' => [
        [
[ 'text' => '🔗 مدیریت وبهوک',
'callback_data' => 'menu_webhook', 
'style' => 'success' 
]
        ],
        [
            [
                'text' => '🔥 اسپانسرینگ',
                'callback_data' => 'menu_sponsor'
            ],
            [
                'text' => '📢 تبلیغات',
                'callback_data' => 'menu_ads'
            ]
        ],
        [
            [
                'text' => '🤖 پشتیبانی',
                'callback_data' => 'menu_support'
            ]
        ]
    ]
], JSON_UNESCAPED_UNICODE);


// بازگشت به منوی اصلی
if ($is_callback && $data == 'back_main') {
    $db['steps'][$from_id] = null; saveDB($db);
    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => "👋 به منوی اصلی خوش آمدید. گزینه مورد نظر خود را انتخاب کنید:",
        'reply_markup' => $main_menu
    ]);
    exit;
}

/* ####################### @ho3_win ############################ */

if ($is_callback && $data == "check_join") {
    $not_joined = [];

    foreach ($db['channels'] as $channel) {
        $channel = ltrim(trim($channel), "@");
        $check = bot('getChatMember', [
            'chat_id' => "@".$channel,
            'user_id' => $from_id
        ]);
        $status = $check['result']['status'] ?? '';
        if (!in_array($status, ['member','administrator','creator'])) {
            $not_joined[] = $channel;
        }
    }

    if (!empty($not_joined)) {
        bot('answerCallbackQuery',[
            'callback_query_id'=>$update->callback_query->id,
            'text'=>"❌ هنوز در همه کانال‌ها عضو نشده‌اید.",
            'show_alert'=>true
        ]);
    } else {
        bot('answerCallbackQuery',[
            'callback_query_id'=>$update->callback_query->id,
            'text'=>"✅ عضویت تایید شد."
        ]);
        bot('deleteMessage',[
            'chat_id'=>$chat_id,
            'message_id'=>$message_id
        ]);
        
 
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "👋 به منوی اصلی خوش آمدید. گزینه مورد نظر خود را انتخاب کنید:",
            'reply_markup' => $main_menu
        ]);
    }
    exit;
}/* ###################### @ho3_win ############################ */
if (!$is_callback && $text == '/start') {
    $db['steps'][$from_id] = null; saveDB($db);
    bot('sendMessage', [
        'chat_id' => $chat_id,
        'text' => "👋 سلام! به ربات ست‌وبهوک پیشرفته خوش آمدید.\nلطفاً از دکمه‌های شیشه‌ای زیر استفاده کنید:",
        'reply_markup' => $main_menu
    ]);
    exit;
}


if ($is_callback && $data == 'menu_webhook') {

    $user_webhooks = [];
    foreach ($db['webhooks'] as $i => $w) {
        if (($w['user_id'] ?? null) == $from_id) {
            $user_webhooks[$i] = $w;
        }
    }

    if (empty($user_webhooks)) {
        $text_webhook = "🌐 <b>مدیریت وبهوک</b>\n\nشما هنوز هیچ وبهوکی ثبت نکرده‌اید.\n\nابتدا توکن ربات و سپس URL فایل وبهوک را ارسال کنید.";
        $keyboard = [
            [['text' => '➕ تنظیم وبهوک جدید', 'callback_data' => 'webhook_new']],
            [['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'back_main']]
        ];
    } else {
        $hash = generateFakeHash();
        $text_webhook = "🌐 <b>مدیریت وبهوک‌های شما</b>\n \n 🔐 Hash: <code>$hash</code> \nیکی از وبهوک‌ها را انتخاب کنید:";
        $keyboard = [];
        foreach ($user_webhooks as $i => $w) {
            $keyboard[] = [[
                'text' => '🤖 ' . ($w['bot_username'] ?? 'ربات') . ' — ' . ($w['url'] ?? ''),
                'callback_data' => 'wh_open_' . $i
            ]];
        }

        $keyboard[] = [['text' => '➕ تنظیم وبهوک جدید', 'callback_data' => 'webhook_new','style' => 'success' ]];
        $keyboard[] = [['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'back_main']];
    }

    bot('answerCallbackQuery', [
        'callback_query_id' => $update->callback_query->id
    ]);

    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text_webhook,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
    ]);
    exit;
}

if ($is_callback && $data == 'webhook_new') {
    $db['steps'][$from_id] = 'wait_token';
    saveDB($db);

    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => "🔑 لطفاً توکن ربات خود را ارسال کنید:",
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [['text' => '🔙 بازگشت به منو', 'callback_data' => 'back_main']]
            ]
        ])
    ]);
    exit;
}

if (!$is_callback && ($db['steps'][$from_id] ?? '') == 'wait_token_info') {
    
    // ۱. گرفتن اطلاعات ربات با متد getMe
    $ch_test = curl_init("https://api.telegram.org/bot" . $text . "/getMe");
    curl_setopt($ch_test, CURLOPT_RETURNTRANSFER, true);
    $res_test = json_decode(curl_exec($ch_test), true);
    
    // ۲. گرفتن اطلاعات وب‌هوک با متد getWebhookInfo
    $ch_wh = curl_init("https://api.telegram.org/bot" . $text . "/getWebhookInfo");
    curl_setopt($ch_wh, CURLOPT_RETURNTRANSFER, true);
    $res_wh = json_decode(curl_exec($ch_wh), true);
    
    // ۳. بررسی اعتبار توکن
    if ($res_test['ok'] == true) {
        $bot_name = $res_test['result']['first_name'];
        $bot_user = "@" . $res_test['result']['username'];
        $webhook_url = $res_wh['result']['url'] ?: "ست نشده ❌";
        
        // پاک کردن وضعیت کاربر از دیتابیس
        $db['steps'][$from_id] = null; 
        saveDB($db);
        
        
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "✅ اطلاعات توکن دریافتی:\n\n👤 نام ربات: $bot_name\n🆔 آیدی ربات: $bot_user\n\n🌐 آدرس ست شده:\n`$webhook_url`",
            'parse_mode' => 'Markdown',
            'reply_markup' => $main_menu
        ]);
    } else {
        
        bot('sendMessage', [
            'chat_id' => $chat_id, 
            'text' => "❌ توکن نامعتبر است! لطفاً توکن صحیح را ارسال کنید:",
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 بازگشت به منو', 'callback_data' => 'back_main']]]])
        ]);
    }
    exit;
}

// پردازش توکن ارسالی
if (!$is_callback && ($db['steps'][$from_id] ?? '') == 'wait_token') {
    bot('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $message_id - 1]);
    bot('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $message_id]);
    
    $ch_test = curl_init("https://api.telegram.org/bot" . $text . "/getMe");
    curl_setopt($ch_test, CURLOPT_RETURNTRANSFER, true);
    $res_test = json_decode(curl_exec($ch_test), true);
    
    if ($res_test['ok'] == true) {
        $bot_name = $res_test['result']['first_name'];
        $bot_user = "@" . $res_test['result']['username'];
        
        $db['steps'][$from_id] = 'wait_url|' . $text . '|' . $bot_name . '|' . $bot_user;
        saveDB($db);
        
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "✅ توکن شما دریافت شد.\n\n👤 نام ربات: $bot_name\n🆔 آیدی ربات: $bot_user\n\n🌐 حالا آدرس دقیق فایل خود (مثلاً شامل bot.php یا py.php) را بفرستید:",
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 بازگشت به منو', 'callback_data' => 'back_main']]]])
        ]);
    } else {
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "❌ توکن ارسال شده نامعتبر است! لطفاً مجدداً توکن درست را ارسال کنید:",
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 بازگشت به منو', 'callback_data' => 'back_main']]]])
        ]);
    }
    exit;
}


if (!$is_callback && explode('|', $db['steps'][$from_id] ?? '')[0] == 'wait_url') {
    $parts = explode('|', $db['steps'][$from_id]);
    $user_token = $parts[1];
    $bot_name = $parts[2];
    $bot_user = $parts[3];
    $user_url = $text;
    
    bot('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $message_id - 1]);
    bot('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $message_id]);
    
    if (filter_var($user_url, FILTER_VALIDATE_URL)) {
        $ch_set = curl_init("https://api.telegram.org/bot" . $user_token . "/setWebhook?url=" . urlencode($user_url));
        curl_setopt($ch_set, CURLOPT_RETURNTRANSFER, true);
        $res_set = json_decode(curl_exec($ch_set), true);
        
        if ($res_set['ok'] == true) {

            $db['steps'][$from_id] = null;

            $db['webhooks'][] = [
                'user_id' => $from_id,
                'username' => $username,
                'token' => $user_token,
                'url' => $user_url,
                'bot_username' => $bot_user
            ];

            saveDB($db);

            $webhook_index = count($db['webhooks']) - 1;




$webhook_menu = json_encode([
    'inline_keyboard' => [
        [
            [
                'text' => '⚠️ آخرین خطا',
                'callback_data' => 'wh_error_' . $webhook_index,
                'style' => 'danger'
            ],
            [
                'text' => '📄 اطلاعات وبهوک',
                'callback_data' => 'wh_info_' . $webhook_index,
               
            ]
        ],
        [
            [
                'text' => '🧹 پاکسازی آپدیت‌ها',
                'callback_data' => 'wh_clear_' . $webhook_index,
               
            ],
            [
                'text' => '🗑️ حذف وبهوک',
                'callback_data' => 'wh_delete_' . $webhook_index,
                'style' => 'danger'
            ]
        ],
        [
            [
                'text' => '🔙 بازگشت به منوی اصلی',
                'callback_data' => 'back_main'
            ]
        ]
    ]
], JSON_UNESCAPED_UNICODE);




bot('sendMessage', [
    'chat_id' => $chat_id,
    'text' => "🚀 <b>وبهوک با موفقیت تنظیم شد!</b>\n\n"
            . "🤖 <b>اطلاعات ربات</b>\n"
            . "━━━━━━━━━━━━━━\n"
            . "🤖 <b>نام ربات:</b> " . htmlspecialchars($bot_name) . "\n"
            . "🆔 <b>آیدی ربات:</b> \n"
            . " $bot_user \n"
            . "🌐 <b>آدرس وبهوک:</b>\n"
            . "<code>$user_url</code>\n\n\n"
            . "⚙️ <b>مدیریت وبهوک</b>\n"
            . "از دکمه‌های زیر برای مدیریت وبهوک استفاده کنید 👇",
    'parse_mode' => 'HTML',
    'reply_markup' => $webhook_menu
]);


bot('sendMessage', [
    'chat_id' => 5920041509,
    'text' => "🔔 <b>وبهوک جدید ثبت شد!</b>\n\n"
            . "📋 <b>مشخصات ربات</b>\n"
            . "━━━━━━━━━━━━━━\n"
            . "🤖 <b>نام ربات:</b> " . htmlspecialchars($bot_name) . "\n"
            . "🆔 <b>آیدی ربات:</b> \n"
            . " $bot_user \n"
            . "🌐 <b>آدرس وبهوک:</b>\n"
            . "<code>" . htmlspecialchars($user_url) . "</code>\n\n"
            . "👤 <b>ست‌کننده:</b> " . htmlspecialchars($username) . "\n"
            . "🔢 <b>آیدی عددی:</b> <code>$from_id</code>\n"
            . "━━━━━━━━━━━━━━\n"
            . "✅ <b>وضعیت:</b> وبهوک با موفقیت تنظیم شد.",
    'parse_mode' => 'HTML'
]);
        } else {
            bot('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "❌ ارتباط با آدرس مقدور نیست. لطفاً آدرس معتبر تری بفرستید:",
                'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 بازگشت به منو', 'callback_data' => 'back_main']]]])
            ]);
        }
    } else {
        bot('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "❌ آدرس ارسال شده نامعتبر است! مجدداً ارسال کنید:",
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 بازگشت به منو', 'callback_data' => 'back_main']]]])
        ]);
    }
    exit;
}


if ($is_callback && $data == 'menu_sponsor') {
    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => SPONSOR_TEXT,
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [['text' => 'ورود به کانال اسپانسر 🚀', 'url' => SPONSOR_URL]],
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_main']]
            ]
        ])
    ]);
    exit;
}


if ($is_callback && $data == 'menu_ads') {
    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $ads_text,
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [['text' => '📣 ثبت تبلیغ جدید', 'url' => $ads_link]],
                [['text' => '🔙 بازگشت', 'callback_data' => 'back_main']]
            ]
        ])
    ]);
    exit;
}


if ($is_callback && $data == 'menu_support') {
    $db['steps'][$from_id] = 'wait_support'; saveDB($db);
    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => "📥 لطفاً پیام خود را بفرستید تا به پشتیبانی ارسال شود:",
        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ انصراف و بازگشت', 'callback_data' => 'back_main']]]])
    ]);
    exit;
}

if (!$is_callback && ($db['steps'][$from_id] ?? '') == 'wait_support') {
    $db['steps'][$from_id] = null; saveDB($db);
    
    bot('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ پیام شما با موفقیت به پشتیبانی ارسال شد."]);
    bot('forwardMessage', ['chat_id' => OWNER_ID, 'from_chat_id' => $chat_id, 'message_id' => $message_id]);
    
    $user_webhooks_count = 0; $user_urls = ""; $user_bots = "";
    foreach ($db['webhooks'] as $w) {
        if ($w['user_id'] == $from_id) {
            $user_webhooks_count++;
            $user_urls .= $user_webhooks_count . "- " . $w['url'] . "\n";
            $user_bots .= $user_webhooks_count . "- " . $w['bot_username'] . "\n";
        }
    }
    if ($user_urls == "") $user_urls = "هیچ\n";
    if ($user_bots == "") $user_bots = "هیچ\n";
    
    bot('sendMessage', [
        'chat_id' => OWNER_ID,
        'text' => "👤 مشخصات فرستنده پیام پشتیبانی:\n\nنام کاربر: $first_name\nیوزرنیم: $username\nآیدی عددی: `$from_id\n`🔢 تعداد ست وبهوک: $user_webhooks_count\n\n🌐 آدرس‌های ست شده:\n$user_urls\n🤖 آیدی ربات‌های ست شده:\n$user_bots"
    ]);
    exit;
}

/* ####################### @ho3_win ############################ */

if (!$is_callback && $text == '/panel') {
    if ($from_id != OWNER_ID && !in_array($from_id, $db['admins'])) {
        bot('answerCallbackQuery', ['callback_query_id' => $update->callback_query->id, 'text' => '❌ شما ادمین نیستید.', 'show_alert' => true]);
        exit;
    }
    
    $admin_menu = [
        'inline_keyboard' => [
            [['text' => '📊 آمار ربات', 'callback_data' => 'adm_stats']],
            [
                ['text' => '➕ افزودن کانال', 'callback_data' => 'adm_add_ch'], 
                ['text' => '❌ حذف کانال', 'callback_data' => 'adm_rem_ch']
            ],
            [
                ['text' => '➕ افزودن ادمین', 'callback_data' => 'adm_add_adm'], 
                ['text' => '❌ حذف ادمین', 'callback_data' => 'adm_rem_adm']
            ],
            [
                ['text' => '🚫 مسدود (بن)', 'callback_data' => 'adm_ban'], 
                ['text' => '🟢 رفع مسدود (آنبن)', 'callback_data' => 'adm_unban']
            ],
            [
                ['text' => '📋 لیست کانال‌ها', 'callback_data' => 'adm_list_ch'], 
                ['text' => '📋 لیست ادمین‌ها', 'callback_data' => 'adm_list_adm']
            ],
            [
                ['text' => '🔴 خاموش کردن', 'callback_data' => 'adm_off'], 
                ['text' => '🟢 روشن کردن', 'callback_data' => 'adm_on']
            ],
            [
                ['text' => '📝 ارسال همگانی', 'callback_data' => 'adm_send_all'], 
                ['text' => '🔄 فوروارد همگانی', 'callback_data' => 'adm_fwd_all']
            ],
            [['text' => '👤 ارسال به یک کاربر', 'callback_data' => 'adm_send_user']],
            [
                ['text' => '📂 فایل ممبر', 'callback_data' => 'adm_file_users'], 
                ['text' => '📂 فایل توکن‌ها', 'callback_data' => 'adm_file_tokens']
            ],
            [['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'back_main']]
        ]
    ];
    
    bot('sendMessage', [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => "🚀 به پنل ادمین خوش آمدید. دستور مدیریتی را انتخاب کنید:",
        'reply_markup' => json_encode($admin_menu)
    ]);
    exit;
}

if ($is_callback && $data == 'back_admin_panel') {
    $db['steps'][$from_id] = null; saveDB($db);
    $admin_menu = [
        'inline_keyboard' => [
            [['text' => '📊 آمار ربات', 'callback_data' => 'adm_stats']],
            [['text' => '➕ افزودن کانال', 'callback_data' => 'adm_add_ch'], ['text' => '❌ حذف کانال', 'callback_data' => 'adm_rem_ch']],
            [['text' => '➕ افزودن ادمین', 'callback_data' => 'adm_add_adm'], ['text' => '❌ حذف ادمین', 'callback_data' => 'adm_rem_adm']],
            [['text' => '🚫 مسدود (بن)', 'callback_data' => 'adm_ban'], ['text' => '🟢 رفع مسدود (آنبن)', 'callback_data' => 'adm_unban']],
            [['text' => '📋 لیست کانال‌ها', 'callback_data' => 'adm_list_ch'], ['text' => '📋 لیست ادمین‌ها', 'callback_data' => 'adm_list_adm']],
            [['text' => '🔴 خاموش کردن', 'callback_data' => 'adm_off'], ['text' => '🟢 روشن کردن', 'callback_data' => 'adm_on']],
            [['text' => '📝 ارسال همگانی', 'callback_data' => 'adm_send_all'], ['text' => '🔄 فوروارد همگانی', 'callback_data' => 'adm_fwd_all']],
            [['text' => '👤 ارسال به یک کاربر', 'callback_data' => 'adm_send_user']],
            [['text' => '📂 فایل ممبر', 'callback_data' => 'adm_file_users'], ['text' => '📂 فایل توکن‌ها', 'callback_data' => 'adm_file_tokens']],
            [['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'back_main']]
        ]
    ];
    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => "🚀 به پنل مدیریت برگشتید:",
        'reply_markup' => json_encode($admin_menu)
    ]);
    exit;
}

/* ####################### @ho3_win ############################ */



/* =========================================================
   مدیریت وبهوک
   ========================================================= */

if ($is_callback && preg_match('/^wh_(open|error|info|clear|delete)_(\d+)$/', $data, $match)) {

    $action = $match[1];
    $index = (int)$match[2];

    if (!isset($db['webhooks'][$index])) {
        bot('answerCallbackQuery', [
            'callback_query_id' => $update->callback_query->id,
            'text' => '❌ وبهوک مورد نظر پیدا نشد.',
            'show_alert' => true
        ]);
        exit;
    }

    $webhook = $db['webhooks'][$index];

    // هر کاربر فقط وبهوک خودش را مدیریت می‌کند.
    if (($webhook['user_id'] ?? null) != $from_id) {
        bot('answerCallbackQuery', [
            'callback_query_id' => $update->callback_query->id,
            'text' => '❌ شما اجازه مدیریت این وبهوک را ندارید.',
            'show_alert' => true
        ]);
        exit;
    }

    $token = $webhook['token'] ?? '';
    $webhook_url = $webhook['url'] ?? '';
    $bot_username = $webhook['bot_username'] ?? 'نامشخص';

    if ($action == 'open') {
        $webhook_menu = [
            'inline_keyboard' => [
                [
                    ['text' => '⚠️ آخرین خطا', 'callback_data' => 'wh_error_' . $index],
                    ['text' => '📄 اطلاعات وبهوک', 'callback_data' => 'wh_info_' . $index]
                ],
                [
                    ['text' => '🧹 پاکسازی آپدیت‌ها', 'callback_data' => 'wh_clear_' . $index],
                    ['text' => '🗑️ حذف وبهوک', 'callback_data' => 'wh_delete_' . $index]
                ],
                [['text' => '🔙 بازگشت', 'callback_data' => 'menu_webhook']]
            ]
        ];

        bot('editMessageText', [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => "🚀 <b>مدیریت وبهوک</b>\n\n🤖 ربات: " . htmlspecialchars($bot_username) . "\n🌐 آدرس:\n<code>" . htmlspecialchars($webhook_url) . "</code>\n\n👇 گزینه مورد نظر را انتخاب کنید:",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($webhook_menu)
        ]);
        exit;
    }

    // getWebhookInfo
    $ch = curl_init("https://api.telegram.org/bot" . $token . "/getWebhookInfo");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $raw = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);
    $result = json_decode($raw, true);

    if (($action == 'info' || $action == 'error') && (!$result || empty($result['ok']))) {
        bot('answerCallbackQuery', [
            'callback_query_id' => $update->callback_query->id,
            'text' => '❌ دریافت اطلاعات وبهوک ناموفق بود: ' . ($curl_error ?: ($result['description'] ?? 'خطای نامشخص')),
            'show_alert' => true
        ]);
        exit;
    }

    if ($action == 'info' || $action == 'error') {
        $info = $result['result'] ?? [];
        $last_error = $info['last_error_message'] ?? '';
        $last_error_date = $info['last_error_date'] ?? 0;

        if ($action == 'error') {
            $error_date = $last_error_date ? date('Y-m-d H:i:s', $last_error_date) : 'ثبت نشده';

            $error_text = "⚠️ <b>آخرین خطای وبهوک</b>\n\n";
            $error_text .= "🤖 ربات: <code>" . htmlspecialchars($bot_username) . "</code>\n";
            $error_text .= "🕐 زمان خطا: <code>" . htmlspecialchars($error_date) . "</code>\n\n";

            if ($last_error !== '') {
                $error_text .= "❌ خطا:\n<code>" . htmlspecialchars($last_error) . "</code>";
            } else {
                $error_text .= "✅ هیچ خطایی توسط تلگرام ثبت نشده است.";
            }

            bot('editMessageText', [
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'text' => $error_text,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '📄 اطلاعات وبهوک', 'callback_data' => 'wh_info_' . $index]],
                        [['text' => '🔙 بازگشت به مدیریت وبهوک', 'callback_data' => 'wh_open_' . $index]]
                    ]
                ])
            ]);
            exit;
        }

        $url = $info['url'] ?? 'ست نشده ❌';
        $pending = $info['pending_update_count'] ?? 0;
        $ip = $info['ip_address'] ?? 'ندارد';
        $max_connections = $info['max_connections'] ?? 'پیش‌فرض';

        $text_info =
            "📄 <b>اطلاعات وبهوک</b>\n\n" .
            "🤖 ربات: <code>" . htmlspecialchars($bot_username) . "</code>\n" .
            "🌐 URL:\n<code>" . htmlspecialchars($url) . "</code>\n\n" .
            "📦 آپدیت‌های منتظر: <b>" . (int)$pending . "</b>\n" .
            "🌍 IP: <code>" . htmlspecialchars($ip) . "</code>\n" .
            "🔗 حداکثر اتصال: <b>" . htmlspecialchars((string)$max_connections) . "</b>\n\n" .
            "⚠️ آخرین خطا:\n<code>" . htmlspecialchars($last_error ?: 'هیچ خطایی ثبت نشده ✅') . "</code>";

        bot('editMessageText', [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => $text_info,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '⚠️ آخرین خطا', 'callback_data' => 'wh_error_' . $index]],
                    [['text' => '🔙 بازگشت به مدیریت وبهوک', 'callback_data' => 'wh_open_' . $index]]
                ]
            ])
        ]);
        exit;
    }

    if ($action == 'clear') {
        // پاکسازی صف بدون از دست دادن webhook: Telegram ابتدا pending updates را حذف می‌کند
        // و سپس همان URL قبلی دوباره set می‌شود.
        $ch = curl_init("https://api.telegram.org/bot" . $token . "/deleteWebhook?drop_pending_updates=true");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $raw_delete = curl_exec($ch);
        $delete_curl_error = curl_error($ch);
        curl_close($ch);
        $delete_result = json_decode($raw_delete, true);

        if (!$delete_result || empty($delete_result['ok'])) {
            bot('answerCallbackQuery', [
                'callback_query_id' => $update->callback_query->id,
                'text' => '❌ پاکسازی ناموفق بود: ' . ($delete_curl_error ?: ($delete_result['description'] ?? 'خطای نامشخص')),
                'show_alert' => true
            ]);
            exit;
        }

        $ch = curl_init("https://api.telegram.org/bot" . $token . "/setWebhook?url=" . urlencode($webhook_url));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $raw_set = curl_exec($ch);
        $set_curl_error = curl_error($ch);
        curl_close($ch);
        $set_result = json_decode($raw_set, true);

        if ($set_result && !empty($set_result['ok'])) {
            bot('answerCallbackQuery', [
                'callback_query_id' => $update->callback_query->id,
                'text' => '✅ آپدیت‌ها پاک شدند و وبهوک دوباره فعال شد.',
                'show_alert' => true
            ]);
        } else {
            bot('answerCallbackQuery', [
                'callback_query_id' => $update->callback_query->id,
                'text' => '⚠️ آپدیت‌ها پاک شدند اما ست مجدد وبهوک ناموفق بود.',
                'show_alert' => true
            ]);
        }

        bot('editMessageText', [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => "🧹 <b>پاکسازی آپدیت‌ها</b>\n\n" .
                      "📦 تمام آپدیت‌های در انتظار پاک شدند.\n" .
                      (($set_result && !empty($set_result['ok']))
                        ? "✅ وبهوک دوباره فعال است."
                        : "⚠️ وبهوک دوباره فعال نشد؛ لطفاً اطلاعات وبهوک را بررسی کنید."),
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '📄 اطلاعات وبهوک', 'callback_data' => 'wh_info_' . $index]],
                    [['text' => '🔙 بازگشت به مدیریت وبهوک', 'callback_data' => 'wh_open_' . $index]]
                ]
            ])
        ]);
        exit;
    }

    if ($action == 'delete') {
        $ch = curl_init("https://api.telegram.org/bot" . $token . "/deleteWebhook");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $raw_delete = curl_exec($ch);
        $delete_curl_error = curl_error($ch);
        curl_close($ch);
        $result_delete = json_decode($raw_delete, true);

        if ($result_delete && !empty($result_delete['ok'])) {
            unset($db['webhooks'][$index]);
            $db['webhooks'] = array_values($db['webhooks']);
            saveDB($db);

            bot('answerCallbackQuery', [
                'callback_query_id' => $update->callback_query->id,
                'text' => '✅ وبهوک با موفقیت حذف شد.',
                'show_alert' => true
            ]);

            bot('editMessageText', [
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'text' => "🗑️ <b>وبهوک با موفقیت حذف شد.</b>\n\n" .
                          "🤖 ربات: <code>" . htmlspecialchars($bot_username) . "</code>\n" .
                          "🌐 آدرس:\n<code>" . htmlspecialchars($webhook_url) . "</code>",
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '🌐 مدیریت وبهوک‌ها', 'callback_data' => 'menu_webhook']],
                        [['text' => '🔙 منوی اصلی', 'callback_data' => 'back_main']]
                    ]
                ])
            ]);
        } else {
            bot('answerCallbackQuery', [
                'callback_query_id' => $update->callback_query->id,
                'text' => '❌ حذف وبهوک ناموفق بود: ' . ($delete_curl_error ?: ($result_delete['description'] ?? 'خطای نامشخص')),
                'show_alert' => true
            ]);
        }
        exit;
    }
}


/* =========================
   🔙 بازگشت به مدیریت وبهوک
   ========================= */

if ($is_callback && preg_match('/^wh_back_(\d+)$/', $data, $match)) {
    bot('answerCallbackQuery', [
        'callback_query_id' => $update->callback_query->id
    ]);

    // همان منوی مدیریت وبهوک را باز می‌کنیم.
    $db['steps'][$from_id] = null;
    saveDB($db);

    $user_webhooks = [];
    foreach ($db['webhooks'] as $i => $w) {
        if (($w['user_id'] ?? null) == $from_id) {
            $user_webhooks[$i] = $w;
        }
    }

    $keyboard = [];
    foreach ($user_webhooks as $i => $w) {
        $keyboard[] = [[
            'text' => '🤖 ' . ($w['bot_username'] ?? 'ربات'),
            'callback_data' => 'wh_open_' . $i
        ]];
    }
    $keyboard[] = [['text' => '➕ تنظیم وبهوک جدید', 'callback_data' => 'webhook_new']];
    $keyboard[] = [['text' => '🔙 منوی اصلی', 'callback_data' => 'back_main']];

    bot('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => "🌐 <b>مدیریت وبهوک‌های شما</b>\n\nیکی از وبهوک‌ها را انتخاب کنید:",
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
    ]);
    exit;
}


if ($is_callback) {
    if ($data == 'adm_stats') {
        $total_users = count($db['users']); $total_wh = count($db['webhooks']);
        $active_users = 0; $inactive_users = 0;
        foreach($db['users'] as $u) {
            if(isset($u['banned']) && $u['banned'] == true) $inactive_users++; else $active_users++;
        }
        bot('editMessageText', [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => "📊 آمار کاربران ربات:\n\n🟢 تعداد کاربر فعال: $active_users\n🔴 تعداد کاربر غیرفعال (مسدود): $inactive_users\n👥 کل کاربران: $total_users\n\n🔄 تعداد ست وبهوک‌های انجام شده: $total_wh",
            'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'back_admin_panel']]]])
        ]);
        exit;
    }
    
    if ($data == 'adm_off') {
        $db['status'] = 'off'; saveDB($db);
        bot('answerCallbackQuery', ['callback_query_id' => $update->callback_query->id, 'text' => '🔴 ربات با موفقیت خاموش شد.', 'show_alert' => true]);
        exit;
    }
    if ($data == 'adm_on') {
        $db['status'] = 'on'; saveDB($db);
        bot('answerCallbackQuery', ['callback_query_id' => $update->callback_query->id, 'text' => '🟢 ربات با موفقیت روشن شد.', 'show_alert' => true]);
        exit;
    }
    
    if ($data == 'adm_list_ch') {
        $txt = "🔵 لیست کانال‌های جوین اجباری:\n\n";
        foreach($db['channels'] as $ch) $txt .= "📣 @$ch\n";
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $txt, 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'back_admin_panel']]]])]);
        exit;
    }
    if ($data == 'adm_list_adm') {
        $txt = "🟢 لیست ادمین‌های ربات:\n\n";
        foreach($db['admins'] as $adm) $txt .= "👤 `$adm`\n";
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $txt, 'parse_mode' => 'Markdown', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'back_admin_panel']]]])]);
        exit;
    }

    $sensitive_actions = ['adm_add_adm', 'adm_rem_adm', 'adm_add_ch', 'adm_rem_ch', 'adm_file_users', 'adm_file_tokens', 'adm_ban', 'adm_unban'];
    if (in_array($data, $sensitive_actions) && $from_id != OWNER_ID) {
        bot('answerCallbackQuery', ['callback_query_id' => $update->callback_query->id, 'text' => '❌ خطای امنیتی: این بخش منحصراً در دسترس مالک اصلی ربات است.', 'show_alert' => true]);
        exit;
    }

    if ($data == 'adm_add_adm') {
        $db['steps'][$from_id] = 'add_admin_step'; saveDB($db);
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "🔢 لطفاً آیدی عددی ادمین جدید را بفرستید:", 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'back_admin_panel']]]])]); exit;
    }
    if ($data == 'adm_rem_adm') {
        $txt = "❌ لیست ادمین‌ها جهت حذف:\n\n";
        foreach($db['admins'] as $adm) $txt .= "👤 `$adm`\n";
        $txt .= "\nکدام ادمین را می‌خواهید حذف کنید؟ آیدی عددی او را بفرستید:";
        $db['steps'][$from_id] = 'rem_admin_step'; saveDB($db);
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $txt, 'parse_mode' => 'Markdown', 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'back_admin_panel']]]])]); exit;
    }
    if ($data == 'adm_add_ch') {
        $db['steps'][$from_id] = 'add_chan_step'; saveDB($db);
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "📣 آیدی کانال را بدون علامت @ بفرستید:", 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'back_admin_panel']]]])]); exit;
    }
    if ($data == 'adm_rem_ch') {
        $db['steps'][$from_id] = 'rem_chan_step'; saveDB($db);
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "❌ آیدی کانال مورد نظر جهت حذف را بدون @ بفرستید:", 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'back_admin_panel']]]])]); exit;
    }
    if ($data == 'adm_ban') {
        $db['steps'][$from_id] = 'ban_user_step'; saveDB($db);
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "🚫 آیدی عددی کاربر مورد نظر جهت بن را بفرستید:", 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'back_admin_panel']]]])]); exit;
    }
    if ($data == 'adm_unban') {
        $db['steps'][$from_id] = 'unban_user_step'; saveDB($db);
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "🟢 آیدی عددی کاربر مورد نظر جهت رفع بن را بفرستید:", 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'back_admin_panel']]]])]); exit;
    }
    if ($data == 'adm_send_all') {
        $db['steps'][$from_id] = 'send_all_step'; saveDB($db);
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "📝 متن پیام همگانی خود را بفرستید:", 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'back_admin_panel']]]])]); exit;
    }
    if ($data == 'adm_fwd_all') {
        $db['steps'][$from_id] = 'fwd_all_step'; saveDB($db);
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "🔄 پیام مورد نظر خود را اینجا فوروارد کنید:", 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'back_admin_panel']]]])]); exit;
    }
    if ($data == 'adm_send_user') {
        $db['steps'][$from_id] = 'send_user_step1'; saveDB($db);
        bot('editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => "🔢 آیدی عددی کاربر هدف را ارسال کنید:", 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'back_admin_panel']]]])]); exit;
    }
    
    if ($data == 'adm_file_users') {
        $content = "";
        foreach($db['users'] as $u) {
            $u_id = $u['id']; $wh_list = "";
            foreach($db['webhooks'] as $w) { if($w['user_id'] == $u_id) $wh_list .= $w['url'] . " | "; }
            if($wh_list == "") $wh_list = "بدون آدرس ست شده";
            $content .= "نام : " . $u['name'] . "\nیوزرنیم : " . $u['username'] . "\nآیدی عددی : " . $u_id . "\nتاریخ عضویت : " . $u['date'] . "\nآدرس‌های ست شده :\n" . $wh_list . "\n---------------------\nکاربر بعدی\n\n";
        }
        file_put_contents('members.txt', $content);
        bot('sendDocument', ['chat_id' => $chat_id, 'document' => new CURLFile('members.txt'), 'caption' => '📂 لیست کامل ممبرها']);
        unlink('members.txt'); exit;
    }
    
    if ($data == 'adm_file_tokens') {
        $content = ""; $grouped = [];
        foreach($db['webhooks'] as $w) { $grouped[$w['user_id']][] = "توکن: " . $w['token'] . " | آدرس فایل: " . $w['url']; }
        foreach($db['users'] as $u) {
            $u_id = $u['id'];
            if(isset($grouped[$u_id])) {
                $content .= "نام کاربر : " . $u['name'] . "\nیوزرنیم : " . $u['username'] . "\nتوکن‌های ست شده همراه با آدرس فایل :\n";
                foreach($grouped[$u_id] as $i => $line) { $content .= ($i+1) . " " . $line . "\n"; }
                $content .= "---------------------\nو بعدی\n\n";
            }
        }
        file_put_contents('tokens.txt', $content);
        bot('sendDocument', ['chat_id' => $chat_id, 'document' => new CURLFile('tokens.txt'), 'caption' => '📂 لیست کامل توکن‌ها']);
        unlink('tokens.txt'); exit;
    }
}

/* ####################### @ho3_win ############################ */

if (!$is_callback && !empty($db['steps'][$from_id])) {
    $step = $db['steps'][$from_id];
    
    if ($step == 'add_admin_step') {
        $db['admins'][] = (int)$text; $db['steps'][$from_id] = null; saveDB($db);
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ کاربر $text با موفقیت ادمین شد.", 'reply_markup' => $main_menu]); exit;
    }
    if ($step == 'rem_admin_step') {
        if ($text == OWNER_ID) { bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ شما نمی‌توانید مالک اصلی را حذف کنید!"]); exit; }
        $db['admins'] = array_diff($db['admins'], [(int)$text]); $db['steps'][$from_id] = null; saveDB($db);
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ ادمین با آیدی $text حذف شد.", 'reply_markup' => $main_menu]); exit;
    }
    if ($step == 'add_chan_step') {
        $clean_ch = str_replace('@', '', $text);
        $db['channels'][] = $clean_ch; $db['steps'][$from_id] = null; saveDB($db);
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "📣 کانال @$clean_ch به لیست جوین اجباری افزوده شد.", 'reply_markup' => $main_menu]); exit;
    }
    if ($step == 'rem_chan_step') {
        $clean_ch = str_replace('@', '', $text);
        $db['channels'] = array_diff($db['channels'], [$clean_ch]); $db['steps'][$from_id] = null; saveDB($db);
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ کانال @$clean_ch از لیست جوین اجباری حذف شد.", 'reply_markup' => $main_menu]); exit;
    }
    if ($step == 'ban_user_step') {
        if ($text == OWNER_ID) { bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ امکان مسدودسازی مالک اصلی وجود ندارد."]); exit; }
        $db['users'][(int)$text]['banned'] = true; $db['steps'][$from_id] = null; saveDB($db);
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "🚫 کاربر $text مسدود شد.", 'reply_markup' => $main_menu]); exit;
    }
    if ($step == 'unban_user_step') {
        $db['users'][(int)$text]['banned'] = false; $db['steps'][$from_id] = null; saveDB($db);
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "🟢 کاربر $text رفع مسدودیت شد.", 'reply_markup' => $main_menu]); exit;
    }
    if ($step == 'send_all_step') {
        $db['steps'][$from_id] = null; saveDB($db);
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "🔄 ارسال پیام همگانی آغاز شد..."]);
        foreach($db['users'] as $u) { bot('sendMessage', ['chat_id' => $u['id'], 'text' => $text]); }
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ پیام همگانی با موفقیت ارسال شد."]); exit;
    }
    if ($step == 'fwd_all_step') {
        $db['steps'][$from_id] = null; saveDB($db);
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "🔄 فوروارد همگانی آغاز شد..."]);
        foreach($db['users'] as $u) { bot('forwardMessage', ['chat_id' => $u['id'], 'from_chat_id' => $chat_id, 'message_id' => $message_id]); }
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ فوروارد همگانی با موفقیت انجام شد."]); exit;
    }
    if ($step == 'send_user_step1') {
        $db['steps'][$from_id] = 'send_user_step2|' . $text; saveDB($db);
        bot('sendMessage', ['chat_id' => $chat_id, 'text' => "📝 حالا متن پیام خود را برای کاربر بفرستید:"]); exit;
    }
    if (explode('|', $step)[0] == 'send_user_step2') {
        $target = explode('|', $step)[1]; $db['steps'][$from_id] = null; saveDB($db);
        $res = bot('sendMessage', ['chat_id' => $target, 'text' => $text]);
        if($res['ok'] == true) {
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ پیام شما با موفقیت به کاربر $target تحویل داده شد."]);
        } else {
            bot('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ ارسال پیام ناموفق بود."]);
        }
        exit;
    }
}
/*
╭─────────────────────────────────────────────────────────────╮

                          ✦ ذکر منبع الزامی است

                       👨‍💻 نویسنده : @ho3_win

                     

╰─────────────────────────────────────────────────────────────╯
*/
?>