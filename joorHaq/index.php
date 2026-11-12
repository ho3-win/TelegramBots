<?php

/********************** Requirements & Debug **********************/

// خطاهای PHP در همین پوشه ذخیره می‌شوند.
// بعد از رفع مشکل می‌توانی این بخش را حذف کنی.
ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('error_log', __DIR__ . '/error.log');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e) {
    error_log("UNCAUGHT EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
});

set_error_handler(function ($severity, $message, $file, $line) {
    error_log("PHP ERROR [$severity]: $message in $file:$line");
    return false;
});

$base = __DIR__;
$telebot_path = $base . '/telebot@2.1.php';

if (!file_exists($telebot_path)) {
    error_log("FATAL: telebot@2.1.php not found: " . $telebot_path);
    http_response_code(500);
    exit("Telebot library not found. Upload telebot@2.1.php beside index.php.");
}

require_once $base . '/config.php';
require_once $base . '/database.php';
require_once $telebot_path;

if (!defined('TOKEN') || trim((string)TOKEN) === '') {
    error_log("FATAL: TOKEN is not defined in config.php");
    http_response_code(500);
    exit("TOKEN is not configured.");
}

$bot = new Telebot(TOKEN, false);

try {
    $db = new Database();
    $db->init();
} catch (Throwable $e) {
    error_log("DATABASE INIT ERROR: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    exit("Database error. Check error.log");
}

/********************** Helpers **********************/



function is_admin($db, $user_id) {
    return $db->is_admin($user_id);
}

function is_owner($user_id) {
    return defined('OWNER_ID') && (int)OWNER_ID > 0 && (int)$user_id === (int)OWNER_ID;
}

function has_admin_access($db, $user_id) {
    return is_owner($user_id) || is_admin($db, $user_id);
}

function admin_keyboard() {
    return Telebot::inline_keyboard("
[📊 آمار ربات|admin_stats]
[➕ افزودن کانال|admin_add_channel][🗑 حذف کانال|admin_del_channel]
[📋 لیست کانال‌ها|admin_channels]
[➕ افزودن ادمین|admin_add_admin][🗑 حذف ادمین|admin_del_admin]
[👥 لیست ادمین‌ها|admin_admins]
[🚫 مسدود کردن|admin_ban][🟢 رفع مسدودی|admin_unban]
[📝 ارسال همگانی|admin_broadcast][🔄 فوروارد همگانی|admin_forward]
[👤 پیام به کاربر|admin_message_user]
[📂 فایل ممبرها|admin_members_file]
[🔴 خاموش کردن ربات|admin_off][🟢 روشن کردن ربات|admin_on]
");
}

function main_keyboard() {
    return Telebot::inline_keyboard(
        "[ثبت سوال 📥|send_question][بازی با دوستان 🔥|switch_inline_query:]\n" .
        "[📢 تبلیغات|advertise]"
    );
}

function send_home($bot, $chat_id, $name, $msg_id = -1) {
    $text = trim("
سلام کاربر «{$name}» گرامی، به ربات جرات و حقیقت خوش آمدی 😈

• اگه میخوای بازی رو شروع کنی روی دکمه «بازی با دوستان» بزن 😉👊🏻

راستی اگه سوالی به ذهنت رسید و خواستی اضافه کنی، میتونی از دکمه ثبت سوال استفاده کنی 😍✌🏻
");
    $params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'reply_markup' => main_keyboard()
    ];
    if ($msg_id > 0) $params['reply_to_message_id'] = $msg_id;
    $bot->sendMessage($params);
}

function send_admin_panel($bot, $chat_id, $msg_id = -1) {
    $params = [
        'chat_id' => $chat_id,
        'text' => "🛠 پنل مدیریت\n\nیکی از گزینه‌های زیر را انتخاب کنید:",
        'reply_markup' => admin_keyboard()
    ];
    if ($msg_id > 0) $params['reply_to_message_id'] = $msg_id;
    $bot->sendMessage($params);
}

function get_name($bot, $user_id) {
    try {
        $chat = $bot->getChat(['chat_id' => $user_id]);
        $chat = $chat['result'] ?? [];
        $name = $chat['first_name'] ?? '';
        if (isset($chat['last_name'])) $name .= ' ' . $chat['last_name'];
        return trim($name) ?: (string)$user_id;
    } catch (Throwable $e) {
        return (string)$user_id;
    }
}

/********************** Text Handler **********************/

$bot->on('text', function($data) use ($bot, $db) {
    $chat_type = $data['chat']['type'] ?? $data['chat_type'] ?? '';
    $chat_id = (int)($data['chat']['id'] ?? $data['from']['id'] ?? 0);
    $from_id = (int)($data['from']['id'] ?? $chat_id);
    $name = $data['from']['first_name'] ?? $data['chat']['first_name'] ?? '';
    if (isset($data['from']['last_name'])) $name .= ' ' . $data['from']['last_name'];
    elseif (isset($data['chat']['last_name'])) $name .= ' ' . $data['chat']['last_name'];

    $msg_id = $data['message_id'] ?? -1;
    $text = trim($data['text'] ?? '');

    if ($chat_type !== 'private') return;

    $db->add_user($from_id);
    $db->sync_telegram_id($from_id);

    /******** Admin commands ********/

    if ($text === '/panel') {
        if (!has_admin_access($db, $from_id)) {
            $bot->sendMessage(['chat_id'=>$chat_id, 'text'=>'⛔️ شما دسترسی مدیریت ندارید.']);
            return;
        }
        send_admin_panel($bot, $chat_id, $msg_id);
        return;
    }

    if ($text === '/backup') {
        if (!has_admin_access($db, $from_id)) return;
        if (file_exists('database.db')) {
            $bot->sendDocument(['chat_id'=>$chat_id, 'document'=>'database.db', 'reply_to_message_id'=>$msg_id]);
        }
        return;
    }

    /******** Admin pending actions ********/

    $admin_state = $db->get_admin_state($from_id);
    if ($admin_state) {
        if (!has_admin_access($db, $from_id)) {
            $db->clear_admin_state($from_id);
        } else {
            $action = $admin_state['action'];
            $value = $text;

            if ($action === 'add_channel') {
                $channel = trim($value);
                if ($channel === '') return;
                if ($db->add_channel($channel)) {
                    $bot->sendMessage(['chat_id'=>$chat_id, 'text'=>"✅ کانال با موفقیت اضافه شد:\n{$channel}", 'reply_markup'=>admin_keyboard()]);
                } else {
                    $bot->sendMessage(['chat_id'=>$chat_id, 'text'=>"⚠️ این کانال قبلاً ثبت شده است.", 'reply_markup'=>admin_keyboard()]);
                }
                $db->clear_admin_state($from_id);
                return;
            }

            if ($action === 'del_channel') {
                $channel = trim($value);
                if ($db->delete_channel($channel)) {
                    $bot->sendMessage(['chat_id'=>$chat_id, 'text'=>"✅ کانال حذف شد.", 'reply_markup'=>admin_keyboard()]);
                } else {
                    $bot->sendMessage(['chat_id'=>$chat_id, 'text'=>"❌ کانال پیدا نشد.", 'reply_markup'=>admin_keyboard()]);
                }
                $db->clear_admin_state($from_id);
                return;
            }

            if ($action === 'add_admin') {
                if (!ctype_digit($value)) {
                    $bot->sendMessage(['chat_id'=>$chat_id, 'text'=>"❌ آیدی عددی تلگرام را ارسال کنید."]);
                    return;
                }
                $target = (int)$value;
                if ($db->add_admin($target)) {
                    $bot->sendMessage(['chat_id'=>$chat_id, 'text'=>"✅ کاربر {$target} به لیست ادمین‌ها اضافه شد.", 'reply_markup'=>admin_keyboard()]);
                } else {
                    $bot->sendMessage(['chat_id'=>$chat_id, 'text'=>"⚠️ این کاربر قبلاً ادمین است.", 'reply_markup'=>admin_keyboard()]);
                }
                $db->clear_admin_state($from_id);
                return;
            }

            if ($action === 'del_admin') {
                if (!ctype_digit($value)) {
                    $bot->sendMessage(['chat_id'=>$chat_id, 'text'=>"❌ آیدی عددی تلگرام را ارسال کنید."]);
                    return;
                }
                $target = (int)$value;
                if ($target === (int)OWNER_ID) {
                    $bot->sendMessage(['chat_id'=>$chat_id, 'text'=>"❌ مالک اصلی قابل حذف نیست."]);
                } elseif ($db->delete_admin($target)) {
                    $bot->sendMessage(['chat_id'=>$chat_id, 'text'=>"✅ ادمین حذف شد.", 'reply_markup'=>admin_keyboard()]);
                } else {
                    $bot->sendMessage(['chat_id'=>$chat_id, 'text'=>"❌ این کاربر ادمین نیست.", 'reply_markup'=>admin_keyboard()]);
                }
                $db->clear_admin_state($from_id);
                return;
            }

            if ($action === 'ban') {
                if (!ctype_digit($value)) {
                    $bot->sendMessage(['chat_id'=>$chat_id, 'text'=>"❌ آیدی عددی تلگرام را ارسال کنید."]);
                    return;
                }
                $target = (int)$value;
                $db->ban_user($target);
                $bot->sendMessage(['chat_id'=>$chat_id, 'text'=>"🚫 کاربر {$target} مسدود شد.", 'reply_markup'=>admin_keyboard()]);
                $db->clear_admin_state($from_id);
                return;
            }

            if ($action === 'unban') {
                if (!ctype_digit($value)) {
                    $bot->sendMessage(['chat_id'=>$chat_id, 'text'=>"❌ آیدی عددی تلگرام را ارسال کنید."]);
                    return;
                }
                $target = (int)$value;
                $db->unban_user($target);
                $bot->sendMessage(['chat_id'=>$chat_id, 'text'=>"🟢 مسدودی کاربر {$target} برداشته شد.", 'reply_markup'=>admin_keyboard()]);
                $db->clear_admin_state($from_id);
                return;
            }

            if ($action === 'message_user') {
                $parts = preg_split('/\s+/', $value, 2);
                if (count($parts) < 2 || !ctype_digit($parts[0])) {
                    $bot->sendMessage(['chat_id'=>$chat_id, 'text'=>"فرمت صحیح:\nآیدی_عددی متن پیام"]);
                    return;
                }
                $target = (int)$parts[0];
                $message = $parts[1];
                $res = $bot->sendMessage(['chat_id'=>$target, 'text'=>$message]);
                if (($res['ok'] ?? false) === true) {
                    $bot->sendMessage(['chat_id'=>$chat_id, 'text'=>"✅ پیام ارسال شد.", 'reply_markup'=>admin_keyboard()]);
                } else {
                    $bot->sendMessage(['chat_id'=>$chat_id, 'text'=>"❌ ارسال پیام ناموفق بود.", 'reply_markup'=>admin_keyboard()]);
                }
                $db->clear_admin_state($from_id);
                return;
            }

            if ($action === 'broadcast') {
                $users = $db->get_all_user_ids();
                $ok = 0; $fail = 0;
                foreach ($users as $target) {
                    $res = $bot->sendMessage(['chat_id'=>$target, 'text'=>$value]);
                    if (($res['ok'] ?? false) === true) $ok++; else $fail++;
                }
                $bot->sendMessage(['chat_id'=>$chat_id, 'text'=>"📢 ارسال همگانی تمام شد.\n\n✅ موفق: {$ok}\n❌ ناموفق: {$fail}", 'reply_markup'=>admin_keyboard()]);
                $db->clear_admin_state($from_id);
                return;
            }
        }
    }

    /******** Bot state / forced join ********/

    if (!$db->get_setting('bot_enabled', '1') && !has_admin_access($db, $from_id)) {
        $bot->sendMessage(['chat_id'=>$chat_id, 'text'=>"🔴 ربات در حال حاضر خاموش است.\nلطفاً بعداً دوباره تلاش کنید."]);
        return;
    }

    if ($db->is_banned($from_id) && !has_admin_access($db, $from_id)) {
        $bot->sendMessage(['chat_id'=>$chat_id, 'text'=>"🚫 شما از استفاده از این ربات مسدود شده‌اید."]);
        return;
    }

    if (!$db->is_joined_all($bot, $from_id)) {
        $channels = $db->get_channels();
        $buttons = [];
        foreach ($channels as $channel) {
            $username = ltrim($channel['channel'], '@');
            $buttons[] = "[📢 عضویت در @{$username}|url:https://t.me/{$username}]";
        }
        $buttons[] = "[✅ عضو شدم|check_join]";
        $bot->sendMessage([
            'chat_id'=>$chat_id,
            'text'=>"برای استفاده از ربات ابتدا در کانال‌های زیر عضو شوید 👇🏻",
            'reply_markup'=>Telebot::inline_keyboard(implode("\n", $buttons))
        ]);
        return;
    }

    /******** Question workflow ********/

    $user = $db->get_user($from_id);

    if ($user && startsWith('get_question_', $user['step'])) {
        if (mb_strlen($text) > 200 || mb_strlen($text) < 5) {
            $bot->sendMessage(['chat_id'=>$chat_id, 'text'=>"طول سوال باید بین ۵ تا ۲۰۰ کاراکتر باشه!"]);
            return;
        }

        $type = substr($user['step'], strlen('get_question_'));
        $db->change_user($from_id, 'step', 'main');

        if (is_owner($from_id)) {
            $db->add_question($type, base64_encode($text));
            $bot->sendMessage([
                'chat_id'=>$chat_id,
                'text'=>"در دیتابیس ثبت شد.",
                'reply_markup'=>Telebot::inline_keyboard("[برگشت ➡️|back_to_home]")
            ]);
        } else {
            $bot->sendMessage([
                'chat_id'=>$chat_id,
                'text'=>"سوال شما ثبت شد و پس از تایید مدیران ربات به ربات اضافه خواهد شد.",
                'reply_markup'=>Telebot::inline_keyboard("[برگشت ➡️|back_to_home]")
            ]);

            $genre = [
                "truth_normal_boy"=>"حقیقت عادی (پسر)",
                "truth_normal_girl"=>"حقیقت عادی (دختر)",
                "truth_sexy_boy"=>"حقیقت +18 (پسر)",
                "truth_sexy_girl"=>"حقیقت +18 (دختر)",
                "dare_normal_boy"=>"جرأت عادی (پسر)",
                "dare_normal_girl"=>"جرأت عادی (دختر)",
                "dare_sexy_boy"=>"جرأت +18 (پسر)",
                "dare_sexy_girl"=>"جرأت +18 (دختر)"
            ][$type] ?? $type;

            $bot->sendMessage([
                'chat_id'=>OWNER_ID,
                'text'=>"سوال جدید از کاربر {$from_id}\n\nدسته: {$genre}\n\n{$text}",
                'reply_markup'=>Telebot::inline_keyboard("[لغو ❌|reject][تایید ✅|add_{$type}]")
            ]);
        }
        return;
    }

    send_home($bot, $chat_id, $name, $msg_id);
});


/********************** Inline Query Handler **********************/
/*
 * شروع بازی با دوستان از طریق Inline Mode انجام می‌شود.
 * کاربر روی «بازی با دوستان» می‌زند، سپس پیام بازی را از نتیجه Inline
 * انتخاب می‌کند و آن را برای دوست/گروه ارسال می‌کند.
 */
$bot->on('inline_query', function($inline_query) use ($bot, $db) {
    $query_id = (string)($inline_query['id'] ?? '');
    $starter = (int)($inline_query['from']['id'] ?? 0);

    if ($query_id === '' || $starter <= 0) {
        return;
    }

    // اگر کاربر در کانال‌های اجباری عضو نباشد، نتیجه بازی نشان داده نشود.
    if (!$db->is_joined_all($bot, $starter)) {
        $bot->answerInlineQuery([
            'inline_query_id' => $query_id,
            'cache_time' => 0,
            'is_personal' => true,
            'results' => json_encode([], JSON_UNESCAPED_UNICODE)
        ]);
        try {
            $bot->sendMessage([
                'chat_id' => $starter,
                'text' => "⚠️ برای ساخت بازی ابتدا باید در کانال‌های اجباری عضو شوید.\n\nبعد از عضویت دوباره «بازی با دوستان» را بزنید."
            ]);
        } catch (Throwable $e) {
            error_log('INLINE JOIN MESSAGE ERROR: ' . $e->getMessage());
        }
        return;
    }

    $name = $inline_query['from']['first_name'] ?? 'کاربر';
    $result_id = 'truth_dare_' . $starter . '_' . substr(md5(uniqid('', true)), 0, 10);

    $message_text =
        "سلام سلام 😃👐🏻\n" .
        "بیاید جرأت حقیقت بازی کنیم 🤤\n\n" .
        "🙋🏻 کی پایست بازی کنیم؟ 🙋🏻‍♂️\n\n" .
        "اگه پایه‌ای روی دکمه زیر بزن تا به بازی اضافه بشی 🤫\n\n" .
        "👤 سازنده بازی: {$name}\n\n" .
        "اعضای چالش:\n" .
        "1. {$name}\n" .
        "\n👇🏻 برای ورود به بازی روی «من پایه‌ام» بزنید.";

    $reply_markup = Telebot::inline_keyboard(
        "[من پایه‌ام 🖐🏻|new_player_{$starter}_0]\n"
    );

    $results = [[
        'type' => 'article',
        'id' => $result_id,
        'title' => '🎮 شروع بازی جرأت یا حقیقت',
        'description' => 'پیام بازی را برای دوستت یا داخل گروه ارسال کن.',
        'input_message_content' => [
            'message_text' => $message_text
        ],
        'reply_markup' => json_decode($reply_markup, true)
    ]];

    $response = $bot->answerInlineQuery([
        'inline_query_id' => $query_id,
        'cache_time' => 0,
        'is_personal' => true,
        'results' => json_encode($results, JSON_UNESCAPED_UNICODE)
    ]);

    if (!($response['ok'] ?? false)) {
        error_log('ANSWER INLINE QUERY FAILED: ' . json_encode($response, JSON_UNESCAPED_UNICODE));
    }
});


/********************** Callback Handler **********************/

$bot->on('callback_query', function($callback_query) use ($bot, $db) {
    $query_id = $callback_query['id'];
    $query_data = $callback_query['data'] ?? '';
    $chat_id = (int)($callback_query['from']['id'] ?? $callback_query['chat']['id'] ?? 0);
    $msg_id = $callback_query['message']['message_id'] ?? -1;
    $name = $callback_query['from']['first_name'] ?? '';
    if (isset($callback_query['from']['last_name'])) $name .= ' ' . $callback_query['from']['last_name'];

    if ($query_data === 'advertise') {
        $bot->answerCallbackQuery(['callback_query_id'=>$query_id]);
        $bot->sendMessage([
            'chat_id'=>$chat_id,
            'text'=>"📢 برای تبلیغات و همکاری به آیدی زیر پیام بده:\n\n@HO3_win"
        ]);
        return;
    }

    if ($query_data === 'check_join') {
        if ($db->is_joined_all($bot, $chat_id)) {
            $bot->answerCallbackQuery(['callback_query_id'=>$query_id, 'text'=>"✅ عضویت شما تایید شد.", 'show_alert'=>true]);
            send_home($bot, $chat_id, $name);
        } else {
            $bot->answerCallbackQuery(['callback_query_id'=>$query_id, 'text'=>"❌ هنوز در همه کانال‌ها عضو نشده‌اید.", 'show_alert'=>true]);
        }
        return;
    }

    /******** Admin callbacks ********/

    if (startsWith('admin_', $query_data)) {
        if (!has_admin_access($db, $chat_id)) {
            $bot->answerCallbackQuery(['callback_query_id'=>$query_id, 'text'=>"⛔️ دسترسی ندارید.", 'show_alert'=>true]);
            return;
        }

        if ($query_data === 'admin_panel') {
            $bot->editMessageText(['chat_id'=>$chat_id, 'message_id'=>$msg_id, 'text'=>"🛠 پنل مدیریت", 'reply_markup'=>admin_keyboard()]);
            return;
        }

        if ($query_data === 'admin_stats') {
            $s = $db->get_stats();
            $text = "📊 آمار ربات\n\n" .
                    "👥 کل کاربران: {$s['users']}\n" .
                    "🚫 کاربران مسدود: {$s['banned']}\n" .
                    "👮 ادمین‌ها: {$s['admins']}\n" .
                    "📢 کانال‌های جوین اجباری: {$s['channels']}\n" .
                    "❓ تعداد سوالات: {$s['questions']}\n" .
                    "🎮 بازی‌های فعال: {$s['games']}\n" .
                    "⚙️ وضعیت ربات: " . ($s['enabled'] ? "🟢 روشن" : "🔴 خاموش");
            $bot->editMessageText(['chat_id'=>$chat_id,'message_id'=>$msg_id,'text'=>$text,'reply_markup'=>admin_keyboard()]);
            return;
        }

        if ($query_data === 'admin_channels') {
            $channels = $db->get_channels();
            if (!$channels) {
                $text = "📋 هیچ کانالی ثبت نشده است.";
            } else {
                $text = "📋 کانال‌های جوین اجباری:\n\n";
                foreach ($channels as $i=>$ch) {
                    $text .= ($i+1) . ". " . $ch['channel'] . "\n";
                }
            }
            $bot->editMessageText(['chat_id'=>$chat_id,'message_id'=>$msg_id,'text'=>$text,'reply_markup'=>admin_keyboard()]);
            return;
        }

        if ($query_data === 'admin_admins') {
            $admins = $db->get_admins();
            $text = "👥 لیست ادمین‌ها:\n\n👑 مالک: " . OWNER_ID . "\n";
            foreach ($admins as $i=>$a) $text .= ($i+1) . ". " . $a['user_id'] . "\n";
            $bot->editMessageText(['chat_id'=>$chat_id,'message_id'=>$msg_id,'text'=>$text,'reply_markup'=>admin_keyboard()]);
            return;
        }

        if ($query_data === 'admin_add_channel') {
            $db->set_admin_state($chat_id, 'add_channel');
            $bot->sendMessage(['chat_id'=>$chat_id,'text'=>"➕ آیدی یا یوزرنیم کانال را ارسال کنید.\nمثال:\n@mychannel"]);
            return;
        }

        if ($query_data === 'admin_del_channel') {
            $db->set_admin_state($chat_id, 'del_channel');
            $bot->sendMessage(['chat_id'=>$chat_id,'text'=>"🗑 یوزرنیم کانالی که می‌خواهید حذف شود را ارسال کنید.\nمثال:\n@mychannel"]);
            return;
        }

        if ($query_data === 'admin_add_admin') {
            $db->set_admin_state($chat_id, 'add_admin');
            $bot->sendMessage(['chat_id'=>$chat_id,'text'=>"➕ آیدی عددی کاربر را ارسال کنید."]);
            return;
        }

        if ($query_data === 'admin_del_admin') {
            if (!is_owner($chat_id)) {
                $bot->answerCallbackQuery(['callback_query_id'=>$query_id,'text'=>"فقط مالک می‌تواند ادمین حذف کند.",'show_alert'=>true]);
                return;
            }
            $db->set_admin_state($chat_id, 'del_admin');
            $bot->sendMessage(['chat_id'=>$chat_id,'text'=>"🗑 آیدی عددی ادمینی که می‌خواهید حذف کنید را ارسال کنید."]);
            return;
        }

        if ($query_data === 'admin_ban') {
            $db->set_admin_state($chat_id, 'ban');
            $bot->sendMessage(['chat_id'=>$chat_id,'text'=>"🚫 آیدی عددی کاربر را ارسال کنید."]);
            return;
        }

        if ($query_data === 'admin_unban') {
            $db->set_admin_state($chat_id, 'unban');
            $bot->sendMessage(['chat_id'=>$chat_id,'text'=>"🟢 آیدی عددی کاربر را ارسال کنید."]);
            return;
        }

        if ($query_data === 'admin_message_user') {
            $db->set_admin_state($chat_id, 'message_user');
            $bot->sendMessage(['chat_id'=>$chat_id,'text'=>"👤 در یک پیام بفرستید:\nآیدی_عددی متن پیام\n\nمثال:\n123456789 سلام، حالت چطوره؟"]);
            return;
        }

        if ($query_data === 'admin_broadcast') {
            $db->set_admin_state($chat_id, 'broadcast');
            $bot->sendMessage(['chat_id'=>$chat_id,'text'=>"📝 متن پیام همگانی را ارسال کنید."]);
            return;
        }

        if ($query_data === 'admin_forward') {
            $db->set_admin_state($chat_id, 'forward');
            $bot->sendMessage(['chat_id'=>$chat_id,'text'=>"🔄 پیام موردنظر برای فوروارد همگانی را همین‌جا فوروارد کنید."]);
            return;
        }

        if ($query_data === 'admin_members_file') {
            $file = $db->create_members_file();
            $bot->sendDocument(['chat_id'=>$chat_id,'document'=>$file]);
            return;
        }

        if ($query_data === 'admin_off') {
            if (!is_owner($chat_id)) {
                $bot->answerCallbackQuery(['callback_query_id'=>$query_id,'text'=>"فقط مالک می‌تواند ربات را خاموش کند.",'show_alert'=>true]);
                return;
            }
            $db->set_setting('bot_enabled', '0');
            $bot->editMessageText(['chat_id'=>$chat_id,'message_id'=>$msg_id,'text'=>"🔴 ربات خاموش شد.",'reply_markup'=>admin_keyboard()]);
            return;
        }

        if ($query_data === 'admin_on') {
            if (!is_owner($chat_id)) {
                $bot->answerCallbackQuery(['callback_query_id'=>$query_id,'text'=>"فقط مالک می‌تواند ربات را روشن کند.",'show_alert'=>true]);
                return;
            }
            $db->set_setting('bot_enabled', '1');
            $bot->editMessageText(['chat_id'=>$chat_id,'message_id'=>$msg_id,'text'=>"🟢 ربات روشن شد.",'reply_markup'=>admin_keyboard()]);
            return;
        }
    }

    /******** Forward state ********/

    if ($query_data === 'send_question') {
        $db->change_user($chat_id, 'step', 'main');
        $bot->editMessageText([
            'chat_id'=>$chat_id,
            'text'=>"قصد دارید برای کدام یک از دسته‌های زیر سوال ارسال کنید؟",
            'reply_markup'=>Telebot::inline_keyboard('
[حقیقت عادی (پسر)|truth_normal_boy][حقیقت عادی (دختر)|truth_normal_girl]
[حقیقت +18 (پسر)|truth_sexy_boy][حقیقت +18 (دختر)|truth_sexy_girl]
[جرأت عادی (پسر)|dare_normal_boy][جرأت عادی (دختر)|dare_normal_girl]
[جرأت +18 (پسر)|dare_sexy_boy][جرأت +18 (دختر)|dare_sexy_girl]
[برگشت ➡️|back_to_home]'),
            'message_id'=>$msg_id
        ]);
        return;
    }

    if (in_array($query_data, [
        "truth_normal_boy","truth_normal_girl","truth_sexy_boy","truth_sexy_girl",
        "dare_normal_boy","dare_normal_girl","dare_sexy_boy","dare_sexy_girl"
    ])) {
        $db->change_user($chat_id, 'step', "get_question_{$query_data}");
        $bot->editMessageText([
            'chat_id'=>$chat_id,
            'text'=>"سوال خود را ارسال کنید👇🏻",
            'reply_markup'=>Telebot::inline_keyboard('[برگشت ➡️|send_question]'),
            'message_id'=>$msg_id
        ]);
        return;
    }

    if ($query_data === 'back_to_home') {
        $db->change_user($chat_id, 'step', 'main');
        $bot->editMessageText([
            'chat_id'=>$chat_id,
            'text'=>trim("سلام کاربر «{$name}» گرامی، به ربات جرات و حقیقت خوش آمدی 😈\n\n• اگه میخوای بازی رو شروع کنی روی دکمه «بازی با دوستان» بزن 😉👊🏻\n\nراستی اگه سوالی به ذهنت رسید و خواستی اضافه کنی، میتونی از دکمه ثبت سوال استفاده کنی 😍✌🏻"),
            'reply_markup'=>main_keyboard(),
            'message_id'=>$msg_id
        ]);
        return;
    }

    if (startsWith('add_', $query_data)) {
        $type = substr($query_data, 4);
        $message_text = $callback_query['message']['text'] ?? '';
        $lines = explode("\n", $message_text);
        $question = trim(implode("\n", array_slice($lines, 3)));
        if ($question !== '') $db->add_question($type, base64_encode($question));
        $bot->answerCallbackQuery(['callback_query_id'=>$query_id,'text'=>"سوال به دیتابیس اضافه شد 🎈",'show_alert'=>true]);
        return;
    }

    if ($query_data === 'reject') {
        $bot->deleteMessage(['chat_id'=>$chat_id,'message_id'=>$msg_id]);
        return;
    }

    /******** Existing game callbacks ********/

    if (startsWith('new_player_', $query_data)) {
        $metadata = explode('_', substr($query_data, strlen('new_player_')));
        $game_starter = $metadata[0];
        $is_game_new = $metadata[1] == '0';

        if ($is_game_new) {
            $pid = md5(microtime());
            $data = ['players'=>[$game_starter]];
            $db->game_start($pid, $data);
        } else {
            $pid = $metadata[2];
            $data = $db->get_game($pid);
        }

        if (in_array($chat_id, $data['players'])) {
            $bot->answerCallbackQuery(['callback_query_id'=>$query_id,'text'=>"شما از قبل در بازی حضور داشتید، لطفاً منتظر شروع بازی بمانید.",'show_alert'=>true]);
            return;
        }

        $data['players'][] = $chat_id;
        $db->game_update($pid, $data);

        $names = '';
        foreach ($data['players'] as $i=>$player) {
            $names .= ($i+1) . ". " . get_name($bot,$player) . "\n";
        }

        $bot->editMessageText([
            'text'=>"سلام سلام 😃👐🏻\nبیاید جرأت حقیقت بازی کنیم 🤤\n\n🙋🏻 کی پایست بازی کنیم 🙋🏻‍♂️\n\nاگه پایه ای بزن رو دکمه زیر تا به بازی اضافتون کنم 🤫\n\nاعضای چالش :\n{$names}",
            'reply_markup'=>Telebot::inline_keyboard("[من پایه ام 🖐🏻|new_player_{$game_starter}_1_{$pid}][شروع بازی 🔥|play_{$game_starter}_1_{$pid}]"),
            'inline_message_id'=>$callback_query['inline_message_id']
        ]);
        return;
    }

    if (startsWith('play_', $query_data)) {
        $metadata = explode('_', substr($query_data, 5));
        $game_starter = $metadata[0];
        $is_game_new = $metadata[1] == '0';

        if ($chat_id != $game_starter) {
            $bot->answerCallbackQuery(['callback_query_id'=>$query_id,'text'=>"شما سازنده این بازی نیستید.",'show_alert'=>true]);
            return;
        }

        if ($is_game_new) {
            $bot->answerCallbackQuery(['callback_query_id'=>$query_id,'text'=>"برای شروع بازی باید حداقل دو نفر داخل بازی باشن.",'show_alert'=>true]);
            return;
        }

        $pid = $metadata[2];
        $data = $db->get_game($pid);
        $round = 0;
        $current_player = get_name($bot,$data['players'][$round]);
        $data['round']=$round;
        $data['starter']=$game_starter;
        $db->game_update($pid,$data);

        $bot->editMessageText([
            'text'=>"نوبت «{$current_player}» هست 😙\n\nجرأت یا حقیقت ؟ 🙄",
            'reply_markup'=>Telebot::inline_keyboard("[حقیقت 😇|q_truth_{$pid}][جرأت 😈|q_dare_{$pid}]\n[👇🏻 - دستورات مخصوص سازنده بازی - 👇🏻|null]\n[رد کردن این شخص ♻️|skip_{$pid}][اتمام بازی ❗️|end_game_{$pid}]"),
            'inline_message_id'=>$callback_query['inline_message_id']
        ]);
        return;
    }

    $random_type = ["normal","sexy"][random_int(0,1)];

    if (startsWith('q_truth_', $query_data) || startsWith('q_dare_', $query_data)) {
        $prefix = startsWith('q_truth_', $query_data) ? 'q_truth_' : 'q_dare_';
        $pid = substr($query_data, strlen($prefix));
        $data = $db->get_game($pid);

        if ($chat_id != $data['players'][$data['round']]) {
            $bot->answerCallbackQuery(['callback_query_id'=>$query_id,'text'=>"نوبت شما نیست.",'show_alert'=>true]);
            return;
        }

        $current_player = get_name($bot,$data['players'][$data['round']]);
        $is_truth = $prefix === 'q_truth_';
        $label = $is_truth ? 'حقیقت' : 'جرأت';

        $markup = $is_truth
            ? "[حقیقت عادی (🙍🏻‍♂️)|run_{$pid},truth_normal_boy][حقیقت +18 (🙍🏻‍♂️)|run_{$pid},truth_sexy_boy]\n[حقیقت عادی (🙎🏻‍♀️)|run_{$pid},truth_normal_girl][حقیقت +18 (🙎🏻‍♀️)|run_{$pid},truth_sexy_girl]\n[حقیقت شانسی (🙎🏻‍♀️)|run_{$pid},truth_{$random_type}_girl][حقیقت شانسی (🙍🏻‍♂️)|run_{$pid},truth_{$random_type}_boy]"
            : "[جرأت عادی (🙍🏻‍♂️)|run_{$pid},dare_normal_boy][جرأت +18 (🙍🏻‍♂️)|run_{$pid},dare_sexy_boy]\n[جرأت عادی (🙎🏻‍♀️)|run_{$pid},dare_normal_girl][جرأت +18 (🙎🏻‍♀️)|run_{$pid},dare_sexy_girl]\n[جرأت شانسی (🙎🏻‍♀️)|run_{$pid},dare_{$random_type}_girl][جرأت شانسی (🙍🏻‍♂️)|run_{$pid},dare_{$random_type}_boy]";

        $markup .= "\n[برگشت|back_to_game_{$pid}]\n[👇🏻 - دستورات مخصوص سازنده بازی - 👇🏻|null]\n[رد کردن این شخص ♻️|skip_{$pid}][اتمام بازی ❗️|end_game_{$pid}]";

        $bot->editMessageText([
            'text'=>"\n> نوبت : {$current_player}\n📍 نوع سوال و جنسیت خودت رو انتخاب کن :",
            'reply_markup'=>Telebot::inline_keyboard($markup),
            'inline_message_id'=>$callback_query['inline_message_id']
        ]);
        return;
    }

    if (startsWith('back_to_game_', $query_data)) {
        $pid = substr($query_data, strlen('back_to_game_'));
        $data = $db->get_game($pid);
        if ($chat_id != $data['players'][$data['round']]) {
            $bot->answerCallbackQuery(['callback_query_id'=>$query_id,'text'=>"نوبت شما نیست.",'show_alert'=>true]);
            return;
        }
        $current_player = get_name($bot,$data['players'][$data['round']]);
        $bot->editMessageText([
            'text'=>"نوبت «{$current_player}» هست 😙\n\nجرأت یا حقیقت ؟ 🙄",
            'reply_markup'=>Telebot::inline_keyboard("[حقیقت 😇|q_truth_{$pid}][جرأت 😈|q_dare_{$pid}]\n[👇🏻 - دستورات مخصوص سازنده بازی - 👇🏻|null]\n[رد کردن این شخص ♻️|skip_{$pid}][اتمام بازی ❗️|end_game_{$pid}]"),
            'inline_message_id'=>$callback_query['inline_message_id']
        ]);
        return;
    }

    if (startsWith('run_', $query_data)) {
        $metadata = explode(',', substr($query_data, 4));
        $pid = $metadata[0];
        $data = $db->get_game($pid);

        if ($chat_id != $data['players'][$data['round']]) {
            $bot->answerCallbackQuery(['callback_query_id'=>$query_id,'text'=>"نوبت شما نیست.",'show_alert'=>true]);
            return;
        }

        $type = $metadata[1];
        $genre = [
            "truth_normal_boy"=>"حقیقت عادی (پسر)","truth_normal_girl"=>"حقیقت عادی (دختر)",
            "truth_sexy_boy"=>"حقیقت +18 (پسر)","truth_sexy_girl"=>"حقیقت +18 (دختر)",
            "dare_normal_boy"=>"جرأت عادی (پسر)","dare_normal_girl"=>"جرأت عادی (دختر)",
            "dare_sexy_boy"=>"جرأت +18 (پسر)","dare_sexy_girl"=>"جرأت +18 (دختر)"
        ][$type] ?? $type;

        $current_player = get_name($bot,$data['players'][$data['round']]);
        $question = trim((string)$db->random_question($type));
        if ($question === '') $question = "شانس آوردی پوچ شد، رد کن 😁";

        $bot->editMessageText([
            'text'=>"👤 نوبت : {$current_player}\n🎭 نوع سوال : {$genre}\n📝 سوال :\n{$question}\n\n--------------------\nبعد از اینکه به سوال بالا جواب دادی روی گزینه [پاسخ دادم ✅] کلیک کن.",
            'reply_markup'=>Telebot::inline_keyboard("[پاسخ دادم ✅|answered_{$pid}][تغییر سوال ♻️|{$query_data}]\n[👇🏻 - دستورات مخصوص سازنده بازی - 👇🏻|null]\n[رد کردن این شخص ♻️|skip_{$pid}][اتمام بازی ❗️|end_game_{$pid}]"),
            'inline_message_id'=>$callback_query['inline_message_id']
        ]);
        return;
    }

    if (startsWith('answered_', $query_data)) {
        $pid = substr($query_data, strlen('answered_'));
        $data = $db->get_game($pid);
        if ($chat_id != $data['players'][$data['round']]) {
            $bot->answerCallbackQuery(['callback_query_id'=>$query_id,'text'=>"نوبت شما نیست.",'show_alert'=>true]);
            return;
        }
        $data['round'] = ($data['round'] >= count($data['players'])-1) ? 0 : $data['round']+1;
        $db->game_update($pid,$data);
        $current_player = get_name($bot,$data['players'][$data['round']]);
        $bot->editMessageText([
            'text'=>"نوبت «{$current_player}» هست 😙\n\nجرأت یا حقیقت ؟ 🙄",
            'reply_markup'=>Telebot::inline_keyboard("[حقیقت 😇|q_truth_{$pid}][جرأت 😈|q_dare_{$pid}]\n[👇🏻 - دستورات مخصوص سازنده بازی - 👇🏻|null]\n[رد کردن این شخص ♻️|skip_{$pid}][اتمام بازی ❗️|end_game_{$pid}]"),
            'inline_message_id'=>$callback_query['inline_message_id']
        ]);
        return;
    }

    if (startsWith('skip_', $query_data)) {
        $pid = substr($query_data, strlen('skip_'));
        $data = $db->get_game($pid);
        if ($chat_id != $data['starter']) {
            $bot->answerCallbackQuery(['callback_query_id'=>$query_id,'text'=>"شما سازنده این بازی نیستید.",'show_alert'=>true]);
            return;
        }
        $data['round'] = ($data['round'] >= count($data['players'])-1) ? 0 : $data['round']+1;
        $db->game_update($pid,$data);
        $current_player = get_name($bot,$data['players'][$data['round']]);
        $bot->editMessageText([
            'text'=>"نوبت «{$current_player}» هست 😙\n\nجرأت یا حقیقت ؟ 🙄",
            'reply_markup'=>Telebot::inline_keyboard("[حقیقت 😇|q_truth_{$pid}][جرأت 😈|q_dare_{$pid}]\n[👇🏻 - دستورات مخصوص سازنده بازی - 👇🏻|null]\n[رد کردن این شخص ♻️|skip_{$pid}][اتمام بازی ❗️|end_game_{$pid}]"),
            'inline_message_id'=>$callback_query['inline_message_id']
        ]);
        return;
    }

    if (startsWith('end_game_', $query_data)) {
        $pid = substr($query_data, strlen('end_game_'));
        $data = $db->get_game($pid);
        if ($chat_id != $data['starter']) {
            $bot->answerCallbackQuery(['callback_query_id'=>$query_id,'text'=>"شما سازنده این بازی نیستید.",'show_alert'=>true]);
            return;
        }
        $db->game_end($pid);
        $bot->editMessageText([
            'text'=>"بازی توسط سازنده با موفقیت بسته شد.",
            'reply_markup'=>Telebot::inline_keyboard(''),
            'inline_message_id'=>$callback_query['inline_message_id']
        ]);
    }
});

/********************** Forwarded-message handler **********************/
/*
 * Forwarding cannot be handled by the text-only event above.
 * This handler is registered only when the library emits "message".
 */
$bot->on('all', function($type, $data) use ($bot, $db) {
    if ($type === 'callback_query' || $type === 'inline_query') return;
    $from_id = (int)($data['from']['id'] ?? 0);
    if (!$from_id || !has_admin_access($db, $from_id)) return;

    $state = $db->get_admin_state($from_id);
    if (!$state || $state['action'] !== 'forward') return;

    $users = $db->get_all_user_ids();
    $ok = 0; $fail = 0;

    foreach ($users as $target) {
        $res = $bot->forwardMessage([
            'chat_id'=>$target,
            'from_chat_id'=>$data['chat']['id'] ?? $from_id,
            'message_id'=>$data['message_id']
        ]);
        if (($res['ok'] ?? false) === true) $ok++; else $fail++;
    }

    $db->clear_admin_state($from_id);
    $bot->sendMessage([
        'chat_id'=>$from_id,
        'text'=>"🔄 فوروارد همگانی تمام شد.\n\n✅ موفق: {$ok}\n❌ ناموفق: {$fail}",
        'reply_markup'=>admin_keyboard()
    ]);
});

$bot->run();
