<?php

/*
 * آیدی عددی مالک ربات را اینجا وارد کن.
 * مثال:
 * define('OWNER_ID', 123456789);
 *
 * config.php همچنان فقط TOKEN را نگه می‌دارد.
 */
if (!defined('OWNER_ID')) {
    define('OWNER_ID', 5920041509);
}

class Database {
    public $connection = null;

    public function __construct() {
        $this->connection = new PDO("sqlite:database.db");
        $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->connection->exec("PRAGMA busy_timeout = 5000");
    }

    public function init() {
        $this->connection->exec("CREATE TABLE IF NOT EXISTS users (
            id TEXT NOT NULL PRIMARY KEY,
            step LONGTEXT NOT NULL DEFAULT 'main'
        )");

        $this->connection->exec("CREATE TABLE IF NOT EXISTS questions (
            type TEXT NOT NULL,
            question LONGTEXT NOT NULL
        )");

        $this->connection->exec("CREATE TABLE IF NOT EXISTS games (
            id TEXT NOT NULL PRIMARY KEY,
            data LONGTEXT NOT NULL
        )");

        $this->connection->exec("CREATE TABLE IF NOT EXISTS admins (
            user_id TEXT NOT NULL PRIMARY KEY
        )");

        $this->connection->exec("CREATE TABLE IF NOT EXISTS channels (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            channel TEXT NOT NULL UNIQUE
        )");

        $this->connection->exec("CREATE TABLE IF NOT EXISTS banned_users (
            user_id TEXT NOT NULL PRIMARY KEY
        )");

        $this->connection->exec("CREATE TABLE IF NOT EXISTS settings (
            name TEXT NOT NULL PRIMARY KEY,
            value TEXT NOT NULL
        )");

        $this->connection->exec("CREATE TABLE IF NOT EXISTS admin_states (
            user_id TEXT NOT NULL PRIMARY KEY,
            action TEXT NOT NULL
        )");

        $this->ensure_telegram_id_column();
        if ($this->get_setting('bot_enabled', null) === null) {
            $this->set_setting('bot_enabled', '1');
        }

        if (defined('OWNER_ID') && (int)OWNER_ID > 0) {
            $this->add_admin((int)OWNER_ID);
        }
    }

    private function hash_id($chat_id) {
        return md5((string)$chat_id);
    }

    private function ensure_telegram_id_column() {
        $columns = $this->connection->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $column) {
            if (($column['name'] ?? '') === 'telegram_id') return;
        }
        $this->connection->exec("ALTER TABLE users ADD COLUMN telegram_id TEXT");
    }

    public function user_exists($chat_id) {
        $stmt = $this->connection->prepare("SELECT 1 FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id'=>$this->hash_id($chat_id)]);
        return (bool)$stmt->fetchColumn();
    }

    public function add_user($chat_id) {
        if ($this->user_exists($chat_id)) return false;

        $stmt = $this->connection->prepare(
            "INSERT INTO users (id, step, telegram_id) VALUES (:id, 'main', :telegram_id)"
        );
        return $stmt->execute([
            ':id'=>$this->hash_id($chat_id),
            ':telegram_id'=>(string)$chat_id
        ]);
    }

    public function sync_telegram_id($chat_id) {
        $stmt = $this->connection->prepare(
            "UPDATE users SET telegram_id = :telegram_id WHERE id = :id"
        );
        return $stmt->execute([
            ':telegram_id'=>(string)$chat_id,
            ':id'=>$this->hash_id($chat_id)
        ]);
    }

    public function get_user($chat_id) {
        $stmt = $this->connection->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id'=>$this->hash_id($chat_id)]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: false;
    }

    public function change_user($chat_id, $key, $value) {
        if ($key !== 'step') return false;
        $stmt = $this->connection->prepare(
            "UPDATE users SET step = :value WHERE id = :id"
        );
        return $stmt->execute([
            ':value'=>$value,
            ':id'=>$this->hash_id($chat_id)
        ]);
    }

    public function get_all_user_ids() {
        $stmt = $this->connection->query(
            "SELECT telegram_id FROM users
             WHERE telegram_id IS NOT NULL AND telegram_id <> ''
             ORDER BY rowid ASC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_unique(array_map('intval', $rows)));
    }

    public function add_question($type, $question) {
        $stmt = $this->connection->prepare(
            "INSERT INTO questions (type, question) VALUES (:type, :question)"
        );
        return $stmt->execute([':type'=>$type, ':question'=>$question]);
    }

    public function random_question($type) {
        $stmt = $this->connection->prepare(
            "SELECT question FROM questions WHERE type = :type ORDER BY RANDOM() LIMIT 1"
        );
        $stmt->execute([':type'=>$type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? base64_decode($row['question']) : '';
    }

    public function game_exists($pid) {
        $stmt = $this->connection->prepare("SELECT 1 FROM games WHERE id = :id LIMIT 1");
        $stmt->execute([':id'=>$pid]);
        return (bool)$stmt->fetchColumn();
    }

    public function game_start($pid, $data) {
        if ($this->game_exists($pid)) return false;
        $stmt = $this->connection->prepare(
            "INSERT INTO games (id, data) VALUES (:id, :data)"
        );
        return $stmt->execute([
            ':id'=>$pid,
            ':data'=>json_encode($data, JSON_UNESCAPED_UNICODE)
        ]);
    }

    public function get_game($pid) {
        $stmt = $this->connection->prepare("SELECT data FROM games WHERE id = :id LIMIT 1");
        $stmt->execute([':id'=>$pid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? json_decode($row['data'], true) : false;
    }

    public function game_update($pid, $data) {
        $stmt = $this->connection->prepare(
            "UPDATE games SET data = :data WHERE id = :id"
        );
        return $stmt->execute([
            ':data'=>json_encode($data, JSON_UNESCAPED_UNICODE),
            ':id'=>$pid
        ]);
    }

    public function game_end($pid) {
        $stmt = $this->connection->prepare("DELETE FROM games WHERE id = :id");
        return $stmt->execute([':id'=>$pid]);
    }

    public function add_admin($user_id) {
        $stmt = $this->connection->prepare(
            "INSERT OR IGNORE INTO admins (user_id) VALUES (:id)"
        );
        $stmt->execute([':id'=>(string)$user_id]);
        return $stmt->rowCount() > 0;
    }

    public function delete_admin($user_id) {
        $stmt = $this->connection->prepare("DELETE FROM admins WHERE user_id = :id");
        $stmt->execute([':id'=>(string)$user_id]);
        return $stmt->rowCount() > 0;
    }

    public function is_admin($user_id) {
        if (defined('OWNER_ID') && (int)OWNER_ID > 0 && (int)$user_id === (int)OWNER_ID) {
            return true;
        }

        $stmt = $this->connection->prepare(
            "SELECT 1 FROM admins WHERE user_id = :id LIMIT 1"
        );
        $stmt->execute([':id'=>(string)$user_id]);
        return (bool)$stmt->fetchColumn();
    }

    public function get_admins() {
        return $this->connection->query(
            "SELECT user_id FROM admins ORDER BY rowid ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function add_channel($channel) {
        $channel = trim($channel);
        if ($channel === '') return false;

        if (strpos($channel, 'https://t.me/') === 0) {
            $channel = '@' . ltrim(substr($channel, 13), '@');
        } elseif ($channel[0] !== '@') {
            $channel = '@' . $channel;
        }

        $stmt = $this->connection->prepare(
            "INSERT OR IGNORE INTO channels (channel) VALUES (:channel)"
        );
        $stmt->execute([':channel'=>$channel]);
        return $stmt->rowCount() > 0;
    }

    public function delete_channel($channel) {
        $channel = trim($channel);
        $channel = ltrim($channel, '@');

        $stmt = $this->connection->prepare(
            "DELETE FROM channels WHERE REPLACE(channel, '@', '') = :channel"
        );
        $stmt->execute([':channel'=>$channel]);
        return $stmt->rowCount() > 0;
    }

    public function get_channels() {
        return $this->connection->query(
            "SELECT id, channel FROM channels ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function is_joined_all($bot, $user_id) {
        $channels = $this->get_channels();
        if (!$channels) return true;

        foreach ($channels as $channel) {
            try {
                $res = $bot->getChatMember([
                    'chat_id'=>$channel['channel'],
                    'user_id'=>$user_id
                ]);
            } catch (Throwable $e) {
                return false;
            }

            if (!($res['ok'] ?? false)) return false;

            $status = $res['result']['status'] ?? 'left';
            if (in_array($status, ['left','kicked'], true)) return false;
        }

        return true;
    }

    public function ban_user($user_id) {
        $stmt = $this->connection->prepare(
            "INSERT OR IGNORE INTO banned_users (user_id) VALUES (:id)"
        );
        return $stmt->execute([':id'=>(string)$user_id]);
    }

    public function unban_user($user_id) {
        $stmt = $this->connection->prepare(
            "DELETE FROM banned_users WHERE user_id = :id"
        );
        return $stmt->execute([':id'=>(string)$user_id]);
    }

    public function is_banned($user_id) {
        $stmt = $this->connection->prepare(
            "SELECT 1 FROM banned_users WHERE user_id = :id LIMIT 1"
        );
        $stmt->execute([':id'=>(string)$user_id]);
        return (bool)$stmt->fetchColumn();
    }

    public function set_setting($name, $value) {
        $stmt = $this->connection->prepare(
            "INSERT OR REPLACE INTO settings (name, value) VALUES (:name, :value)"
        );
        return $stmt->execute([
            ':name'=>$name,
            ':value'=>(string)$value
        ]);
    }

    public function get_setting($name, $default = null) {
        $stmt = $this->connection->prepare(
            "SELECT value FROM settings WHERE name = :name LIMIT 1"
        );
        $stmt->execute([':name'=>$name]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : $value;
    }

    public function set_admin_state($user_id, $action) {
        $stmt = $this->connection->prepare(
            "INSERT OR REPLACE INTO admin_states (user_id, action) VALUES (:id, :action)"
        );
        return $stmt->execute([
            ':id'=>(string)$user_id,
            ':action'=>$action
        ]);
    }

    public function get_admin_state($user_id) {
        $stmt = $this->connection->prepare(
            "SELECT action FROM admin_states WHERE user_id = :id LIMIT 1"
        );
        $stmt->execute([':id'=>(string)$user_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: false;
    }

    public function clear_admin_state($user_id) {
        $stmt = $this->connection->prepare(
            "DELETE FROM admin_states WHERE user_id = :id"
        );
        return $stmt->execute([':id'=>(string)$user_id]);
    }

    public function get_stats() {
        return [
            'users'=>(int)$this->connection->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'banned'=>(int)$this->connection->query("SELECT COUNT(*) FROM banned_users")->fetchColumn(),
            'admins'=>(int)$this->connection->query("SELECT COUNT(*) FROM admins")->fetchColumn(),
            'channels'=>(int)$this->connection->query("SELECT COUNT(*) FROM channels")->fetchColumn(),
            'questions'=>(int)$this->connection->query("SELECT COUNT(*) FROM questions")->fetchColumn(),
            'games'=>(int)$this->connection->query("SELECT COUNT(*) FROM games")->fetchColumn(),
            'enabled'=>$this->get_setting('bot_enabled','1') === '1'
        ];
    }

    public function create_members_file() {
        $path = __DIR__ . DIRECTORY_SEPARATOR . 'members_' . date('Ymd_His') . '.txt';
        $fh = fopen($path, 'w');

        if (!$fh) return false;

        fwrite($fh, "Telegram Members\n");
        fwrite($fh, "Generated: " . date('Y-m-d H:i:s') . "\n\n");

        $stmt = $this->connection->query(
            "SELECT telegram_id FROM users
             WHERE telegram_id IS NOT NULL AND telegram_id <> ''
             ORDER BY rowid ASC"
        );

        while ($id = $stmt->fetchColumn()) {
            fwrite($fh, $id . PHP_EOL);
        }

        fclose($fh);
        return $path;
    }
}
