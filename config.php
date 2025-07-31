<?php
// データベース接続設定
if ($_SERVER['SERVER_NAME'] === 'localhost') {
    // ローカル環境
    $db_name = 'cafe_management';
    $db_host = 'localhost';
    $db_id   = 'root';
    $db_pw   = '';
} else {
    // さくらサーバー
    $db_name = 'gs-cinderella_pos_system'; // データベース名
    $db_host = 'mysql3109.db.sakura.ne.jp'; // ホスト名
    $db_id   = 'gs-cinderella_pos_system';   // ユーザー名
    $db_pw   = '';                           // パスワード
}

// データベース接続
try {
    $server_info = 'mysql:dbname=' . $db_name . ';charset=utf8;host=' . $db_host;
    $pdo = new PDO($server_info, $db_id, $db_pw);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // エラーモードを例外に設定
} catch (PDOException $e) {
    die('データベース接続エラー: ' . $e->getMessage());
}

/**
 * データベーステーブルを初期化（作成および初期データ投入）します。
 * 要件:
 * - 商品、在庫、取引、日次精算、アプリケーション設定の各情報を管理できること。
 * - カテゴリは事前定義されたもの（ドリンク、フードなど）を初期データとして持つこと。
 * - 初期商品データが登録され、レジでの販売に利用できること。
 * - ユーザー認証のためのユーザーテーブルを持つこと。
 *
 * @param PDO $pdo データベース接続オブジェクト
 * @return bool テーブル作成と初期データ投入が成功した場合はtrue、失敗した場合はfalse
 */
function createTables($pdo) {
    try {
        // 既存のテーブルを削除 (クリーンな状態から始めるため)
        // 外部キー制約の順序に注意して削除
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;"); // 外部キー制約を一時的に無効化

        $pdo->exec("DROP TABLE IF EXISTS staff_commissions;");
        $pdo->exec("DROP TABLE IF EXISTS staff_details;");
        $pdo->exec("DROP TABLE IF EXISTS app_settings;");
        $pdo->exec("DROP TABLE IF EXISTS daily_settlement;");
        $pdo->exec("DROP TABLE IF EXISTS transaction_items;"); // transactionsより先に削除
        $pdo->exec("DROP TABLE IF EXISTS transactions;");
        $pdo->exec("DROP TABLE IF EXISTS stock_movements;");
        $pdo->exec("DROP TABLE IF EXISTS inventory;");
        $pdo->exec("DROP TABLE IF EXISTS categories;");
        $pdo->exec("DROP TABLE IF EXISTS users;"); // usersはtransactionsより先に削除

        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;"); // 外部キー制約を再度有効化


        // 商品カテゴリテーブル
        // 要件: 商品を分類するためのカテゴリ情報を保持する。重複するカテゴリ名は許可しない。
        $sql = "CREATE TABLE IF NOT EXISTS categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $pdo->exec($sql);

        // 在庫テーブル (productsテーブルの役割も兼ねる)
        // 要件:
        // - 販売する商品および原材料の在庫情報を一元的に管理する。
        // - 各商品のコスト価格と販売価格を保持し、利益計算の基礎とする。
        // - 発注点を設定し、在庫不足を自動的に検知する。
        // - 賞味期限のある商品に対し、期限情報を管理し、期限切れを警告する。
        // - commission_rate カラムを追加
        // --- 変更点: commission_type と fixed_commission_amount を追加 ---
        $sql = "CREATE TABLE IF NOT EXISTS inventory (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            category_id INT,
            quantity INT NOT NULL DEFAULT 0,
            unit VARCHAR(20) NOT NULL,
            cost_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            selling_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            commission_type ENUM('percentage', 'fixed_amount') NOT NULL DEFAULT 'percentage', -- 新規追加: 歩合タイプ
            commission_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00, -- パーセンテージ歩合率
            fixed_commission_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00, -- 新規追加: 固定額歩合
            reorder_level INT DEFAULT 10,
            supplier VARCHAR(100),
            expiry_date DATE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES categories(id)
        )";
        $pdo->exec($sql);

        // 入出庫履歴テーブル
        // 要件:
        // - 在庫の変動（入庫、出庫、廃棄、調整）に関する履歴を記録する。
        // - 各移動の数量、理由、担当者を記録し、トレーサビリティを確保する。
        $sql = "CREATE TABLE IF NOT EXISTS stock_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_id INT,
            movement_type ENUM('入庫', '出庫', '廃棄', '調整') NOT NULL,
            quantity INT NOT NULL,
            reason VARCHAR(200),
            reference_no VARCHAR(50),
            created_by VARCHAR(50) DEFAULT 'システム',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (item_id) REFERENCES inventory(id)
        )";
        $pdo->exec($sql);

        // ユーザーテーブル
        // 要件: ログイン認証のためのユーザー情報を管理する。パスワードはハッシュ化して保存する。
        $sql = "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM('admin', 'staff') DEFAULT 'staff', -- 管理者、一般スタッフなどの役割
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $pdo->exec($sql);

        // 取引履歴テーブル (レジ会計用) - items_json を削除
        // 要件:
        // - 各会計取引の詳細（合計金額、受取金額、お釣り）を記録する。
        // - 日次精算の基礎データとして利用する。
        // - total_commission_amount カラムを追加
        $sql = "CREATE TABLE IF NOT EXISTS transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            total_amount DECIMAL(10, 2) NOT NULL,
            cash_received DECIMAL(10, 2) NOT NULL,
            change_given DECIMAL(10, 2) NOT NULL,
            user_id INT NULL,
            total_commission_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00, -- この取引で発生した総歩合額
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        )";
        $pdo->exec($sql);

        // 新規追加: 取引商品詳細テーブル
        // transactionsとinventoryの中間テーブル
        // --- 変更点: assigned_staff_id カラムを追加 ---
        $sql = "CREATE TABLE IF NOT EXISTS transaction_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id INT NOT NULL,
            item_id INT NOT NULL,
            item_name VARCHAR(100) NOT NULL,    -- 取引時点の商品名を保持 (マスター変更の影響を受けないため)
            item_price DECIMAL(10,2) NOT NULL,  -- 取引時点の単価を保持 (マスター変更の影響を受けないため)
            quantity INT NOT NULL,
            item_commission_type ENUM('percentage', 'fixed_amount') NOT NULL DEFAULT 'percentage', -- 取引時点の歩合タイプ
            item_commission_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00, -- 取引時点のパーセンテージ歩合率
            item_fixed_commission_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00, -- 取引時点の固定額歩合
            assigned_staff_id INT NULL, -- 新規追加: この商品の担当スタッフID
            FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
            FOREIGN KEY (item_id) REFERENCES inventory(id) ON DELETE RESTRICT, -- 商品を削除するには関連する取引項目を先に削除する必要がある
            FOREIGN KEY (assigned_staff_id) REFERENCES users(id) ON DELETE SET NULL -- 担当スタッフが削除された場合はNULLに設定
        )";
        $pdo->exec($sql);


        // 日次精算テーブル
        // 要件:
        // - 日ごとの釣銭準備金、総売上、予想手元金額、実際手元金額、差異を記録する。
        // - 日次業務の締め処理をサポートする。
        $sql = "CREATE TABLE IF NOT EXISTS daily_settlement (
            id INT AUTO_INCREMENT PRIMARY KEY,
            settlement_date DATE NOT NULL UNIQUE,
            initial_cash_float DECIMAL(10, 2) NOT NULL,
            total_sales_cash DECIMAL(10, 2) NOT NULL,
            expected_cash_on_hand DECIMAL(10, 2) NOT NULL,
            actual_cash_on_hand DECIMAL(10, 2) NULL,
            discrepancy DECIMAL(10, 2) NULL
        )";
        $pdo->exec($sql);

        // アプリケーション設定テーブル
        // 要件:
        // - システム全体の動作に影響する設定値（例：税率、低在庫閾値）を動的に管理する。
        $sql = "CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(255) PRIMARY KEY,
            setting_value TEXT
        )";
        $pdo->exec($sql);

        // 新しいテーブル: staff_details (スタッフの詳細情報)
        $sql = "CREATE TABLE IF NOT EXISTS staff_details (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE, -- usersテーブルへの外部キー
            employee_id VARCHAR(50) UNIQUE NULL, -- 従業員番号（任意）
            hire_date DATE NULL, -- 入社日
            phone_number VARCHAR(20) NULL, -- 電話番号
            address TEXT NULL, -- 住所
            emergency_contact VARCHAR(100) NULL, -- 緊急連絡先
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )";
        $pdo->exec($sql);

        // staff_commissions テーブルは users.id に紐づくため変更なし
        $sql = "CREATE TABLE IF NOT EXISTS staff_commissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            commission_rate DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )";
        $pdo->exec($sql);


        // デフォルトカテゴリ挿入
        // 要件: 初期システム稼働時に必要なカテゴリをあらかじめ登録する。
        $categories_data = ['ドリンク', 'お酒','フード', '原材料', '包装資材', 'その他'];
        foreach ($categories_data as $category_name) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO categories (name) VALUES (?)");
            $stmt->execute([$category_name]);
        }

        // レジ用初期商品挿入 (inventoryテーブル用)
        // 要件: システム稼働後すぐにレジで販売できる初期商品を登録する。
        $stmt_cat_id = $pdo->prepare("SELECT id FROM categories WHERE name = ?");

        // --- 変更点: commission_type と fixed_commission_amount を追加した初期商品データ ---
        $initial_products_data = [
            ['コーヒー', 'ドリンク', 50, '個', 150.00, 300.00, 'percentage', 2.50, 0.00, 10, 'A社', null], // 歩合率2.5%
            ['紅茶', 'ドリンク', 60, '個', 100.00, 250.00, 'percentage', 1.00, 0.00, 10, 'B社', null],   // 歩合率1.0%
            ['サンドイッチ', 'フード', 30, '個', 250.00, 450.00, 'fixed_amount', 0.00, 50.00, 5, 'C社', date('Y-m-d', strtotime('+3 days'))], // 固定額歩合50円
            ['ショートケーキ', 'フード', 20, '個', 300.00, 500.00, 'percentage', 7.50, 0.00, 5, 'D社', date('Y-m-d', strtotime('+5 days'))], // 歩合率7.5%
            ['オレンジジュース', 'ドリンク', 70, '本', 100.00, 200.00, 'fixed_amount', 0.00, 20.00, 15, 'E社', null] // 固定額歩合20円
        ];

        foreach ($initial_products_data as $product_data) {
            $stmt_cat_id->execute([$product_data[1]]); // カテゴリ名からIDを取得
            $category_id = $stmt_cat_id->fetchColumn();

            if ($category_id) {
                // INSERT IGNORE を使用して、既存の商品があればスキップ
                // --- 変更点: commission_type と fixed_commission_amount を挿入 ---
                $stmt = $pdo->prepare("INSERT IGNORE INTO inventory (name, category_id, quantity, unit, cost_price, selling_price, commission_type, commission_rate, fixed_commission_amount, reorder_level, supplier, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $product_data[0],  // name
                    $category_id,      // category_id
                    $product_data[2],  // quantity
                    $product_data[3],  // unit
                    $product_data[4],  // cost_price
                    $product_data[5],  // selling_price
                    $product_data[6],  // commission_type (新規)
                    $product_data[7],  // commission_rate
                    $product_data[8],  // fixed_commission_amount (新規)
                    $product_data[9],  // reorder_level
                    $product_data[10], // supplier
                    $product_data[11]  // expiry_date
                ]);
            }
        }

        // デフォルト設定値挿入 (app_settingsテーブル用)
        // 要件: システムが利用するデフォルト設定値を保持する。
        $default_settings = [
            'tax_rate' => '10', // デフォルト税率
            'low_stock_threshold' => '5' // デフォルト低在庫アラート閾値
        ];
        foreach ($default_settings as $key => $value) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES (?, ?)");
            $stmt->execute([$key, $value]);
        }

        // 初期ユーザーの挿入 (パスワードはハッシュ化)
        // デフォルトの管理者ユーザー: username='admin', password='password'
        $admin_username = 'admin';
        $admin_password_hash = password_hash('password', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT IGNORE INTO users (username, password, role) VALUES (?, ?, 'admin')");
        $stmt->execute([$admin_username, $admin_password_hash]);

        // adminユーザーのstaff_detailsも追加
        $stmt_admin_id = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt_admin_id->execute(['admin']);
        $admin_id = $stmt_admin_id->fetchColumn();
        if ($admin_id) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO staff_details (user_id, employee_id, hire_date) VALUES (?, ?, ?)");
            $stmt->execute([$admin_id, 'EMP001', date('Y-m-d')]); // 仮の従業員IDと今日の日付

            // adminユーザーのstaff_commissionsも追加
            $stmt = $pdo->prepare("INSERT IGNORE INTO staff_commissions (user_id, commission_rate) VALUES (?, 0.00)");
            $stmt->execute([$admin_id]);
        }

        // デフォルトの一般スタッフユーザー: username='staff', password='password'
        $staff_username = 'staff';
        $staff_password_hash = password_hash('password', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT IGNORE INTO users (username, password, role) VALUES (?, ?, 'staff')");
        $stmt->execute([$staff_username, $staff_password_hash]);

        // staffユーザーのstaff_detailsも追加
        $stmt_staff_id = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt_staff_id->execute(['staff']);
        $staff_id = $stmt_staff_id->fetchColumn();
        if ($staff_id) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO staff_details (user_id, employee_id, hire_date) VALUES (?, ?, ?)");
            $stmt->execute([$staff_id, 'EMP002', date('Y-m-d')]); // 仮の従業員IDと今日の日付

            // staffユーザーのstaff_commissionsも追加
            $stmt = $pdo->prepare("INSERT IGNORE INTO staff_commissions (user_id, commission_rate) VALUES (?, 0.00)");
            $stmt->execute([$staff_id]);
        }


        return true;
    } catch (PDOException $e) {
        // エラーログ出力
        error_log("Database Table Creation Error: " . $e->getMessage());
        return false;
    }
}

/**
 * アプリケーション設定値を取得します。
 * @param PDO $pdo データベース接続オブジェクト
 * @param string $key 設定キー
 * @param mixed $default デフォルト値
 * @return mixed 設定値またはデフォルト値
 */
function getSetting($pdo, $key, $default = null) {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['setting_value'] : $default;
    } catch (PDOException $e) {
        error_log("Failed to get setting '{$key}': " . $e->getMessage());
        return $default;
    }
}

// 現在の税率を取得
$current_tax_rate = (float)getSetting($pdo, 'tax_rate', 10); // デフォルト10%

// セッション開始
// セッションIDの固定化攻撃対策
ini_set('session.use_strict_mode', 1);
session_start();
session_regenerate_id(true); // セッション固定攻撃対策

// メッセージ表示関数
function showMessage() {
    if (isset($_SESSION['message'])) {
        echo '<div class="alert success">' . htmlspecialchars($_SESSION['message']) . '</div>';
        unset($_SESSION['message']);
    }
    if (isset($_SESSION['error'])) {
        echo '<div class="alert error">' . htmlspecialchars($_SESSION['error']) . '</div>';
        unset($_SESSION['error']);
    }
    if (isset($_SESSION['warning'])) { // Warningメッセージを追加
        echo '<div class="alert warning">' . htmlspecialchars($_SESSION['warning']) . '</div>';
        unset($_SESSION['warning']);
    }
}

/**
 * ユーザーがログインしているかチェックする関数
 * @return bool ログインしていればtrue、そうでなければfalse
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * ログインしていない場合に指定されたページにリダイレクトする関数
 * @param string $redirect_to リダイレクト先のURL
 */
function requireLogin($redirect_to = 'login.php') {
    if (!isLoggedIn()) {
        $_SESSION['error'] = 'ログインが必要です。';
        header('Location: ' . $redirect_to);
        exit();
    }
}

/**
 * 共通CSSスタイル
 */
function getCommonCSS() {
    return '
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        body {
            background-color: #f4f5f7;
            color: #333;
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            overflow: hidden;
            border: 1px solid #e0e0e0;
        }
        .header {
            background-color: #00a499;
            color: white;
            padding: 20px 30px;
            text-align: left;
        }
        .header h1 {
            font-size: 2em;
            margin: 0;
            font-weight: 600;
        }
        .header p {
            font-size: 0.9em;
            opacity: 0.8;
            margin-top: 5px;
        }
        .content {
            padding: 30px;
        }
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        .alert.success {
            background: #e6f7e9;
            color: #1a6d2f;
            border: 1px solid #b7e0c4;
        }
        .alert.error {
            background: #fdecec;
            color: #b33939;
            border: 1px solid #f2c7c7;
        }
        .alert.warning {
            background: #fff8e6;
            color: #8c6a0c;
            border: 1px solid #f2e2be;
        }
        .nav {
            background: #fff;
            padding: 10px 15px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .nav-left a {
            display: inline-block;
            margin-right: 15px;
            padding: 10px 15px;
            color: #555;
            text-decoration: none;
            border-radius: 4px;
            transition: background 0.2s;
            font-size: 14px;
            font-weight: 500;
        }
        .nav-left a.active {
            background-color: #e0e0e0;
            font-weight: 600;
            color: #333;
        }
        .nav-left a:not(.active):hover {
            background-color: #f0f0f0;
        }
        .nav .btn.danger {
            background: #d9534f;
            color: white;
            border: none;
            padding: 8px 16px;
            font-size: 14px;
            font-weight: 500;
            border-radius: 4px;
        }
        .nav .btn.danger:hover {
            background: #c9302c;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
            font-size: 14px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
            transition: border-color 0.2s;
            background-color: #f9f9f9;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #00a499;
            background-color: #fff;
        }
        .btn {
            background: #00a499;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: background 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover {
            background: #008c82;
        }
        .btn.danger {
            background: #d9534f;
        }
        .btn.danger:hover {
            background: #c9302c;
        }
        .btn.success {
            background: #5cb85c;
        }
        .btn.success:hover {
            background: #4cae4c;
        }
        .table-container {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 4px;
            border: 1px solid #e0e0e0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
            font-size: 14px;
        }
        th {
            background: #f5f5f5;
            font-weight: 600;
            color: #555;
        }
        tr:hover {
            background: #fcfcfc;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            min-width: 60px;
            text-align: center;
        }
        .status-low {
            background: #fdecec;
            color: #b33939;
        }
        .status-normal {
            background: #e6f7e9;
            color: #1a6d2f;
        }
        .status-warning {
            background: #fff8e6;
            color: #8c6a0c;
        }
        .card {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            border: 1px solid #e0e0e0;
        }
        .card h3 {
            color: #00a499;
            margin-bottom: 15px;
            font-size: 1.5em;
        }
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            .nav {
                flex-direction: column;
                align-items: flex-start;
            }
            .nav-left {
                margin-bottom: 10px;
            }
            .nav a {
                margin-right: 10px;
                margin-bottom: 5px;
            }
        }
    </style>';
}

// 共通ナビゲーション
function getNavigation($current_page = '') {
    // ログイン状態に応じてナビゲーションを調整
    $nav_html = '
    <div class="nav">
        <div class="nav-left">
            <a href="index.php"' . ($current_page === 'index' ? ' class="active"' : '') . '>ホーム</a>';

    if (isLoggedIn()) {
        $nav_html .= '
            <a href="input.php"' . ($current_page === 'input' ? ' class="active"' : '') . '>レジ・入出庫</a>
            <a href="select.php"' . ($current_page === 'select' ? ' class="active"' : '') . '>在庫・精算</a>
        </div>
        <div>
            <a href="logout.php" class="btn danger">ログアウト</a>
        </div>';
    } else {
        $nav_html .= '
        </div>
        <div>
            <a href="login.php" class="btn">ログイン</a>
        </div>';
    }
    $nav_html .= '</div>';
    return $nav_html;
}
?>