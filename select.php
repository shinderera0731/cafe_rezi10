<?php
// 共通設定ファイルを読み込み
include 'config.php';

// ログイン必須
requireLogin();

// データ取得
try {
    // カテゴリ一覧
    $categories_data = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    // 絞り込みフォーム用にカテゴリ名を抽出
    $categories = array_column($categories_data, 'name');

    // 在庫一覧取得の基本クエリ
    $sql_inventory = "
        SELECT i.*, c.name AS category_name
        FROM inventory i
        LEFT JOIN categories c ON i.category_id = c.id
        WHERE 1=1
    ";
    $params = [];

    // 絞り込み条件の追加
    $filter_category = $_GET['category'] ?? '';
    $filter_status = $_GET['status'] ?? '';
    $search_keyword = trim($_GET['search_keyword'] ?? '');

    if (!empty($filter_category)) {
        $sql_inventory .= " AND c.name = ?";
        $params[] = $filter_category;
    }
    if (!empty($search_keyword)) {
        $sql_inventory .= " AND i.name LIKE ?";
        $params[] = '%' . $search_keyword . '%';
    }

    $sql_inventory .= " ORDER BY c.name, i.name";

    $stmt_inventory = $pdo->prepare($sql_inventory);
    $stmt_inventory->execute($params);
    $inventory = $stmt_inventory->fetchAll(PDO::FETCH_ASSOC);

    // 要件: 2.3.3 在庫アラート機能 - アラート設定（低在庫、賞味期限間近）
    // 低在庫アラート閾値の取得
    $low_stock_threshold = (int)getSetting($pdo, 'low_stock_threshold', 5);

    // 在庫不足商品
    $low_stock = array_filter($inventory, function($item) use ($low_stock_threshold) {
        return $item['quantity'] <= $item['reorder_level'] || $item['quantity'] <= $low_stock_threshold;
    });

    // 賞味期限間近商品（7日以内）
    $expiring_soon = array_filter($inventory, function($item) {
        if (empty($item['expiry_date'])) return false;
        $today = new DateTime();
        $expiry_date = new DateTime($item['expiry_date']);
        $interval = $today->diff($expiry_date);
        // 賞味期限が今日から7日以内、または既に期限切れ
        return ($interval->days <= 7 && !$interval->invert) || $interval->invert;
    });

    // 最近の入出庫履歴 (10件に制限)
    // 要件: 3.3 入出庫履歴管理機能 - 履歴表示
    $recent_movements = $pdo->query("
        SELECT sm.*, i.name as item_name, i.unit
        FROM stock_movements sm
        JOIN inventory i ON sm.item_id = i.id
        ORDER BY sm.created_at DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 統計情報計算
    $total_items = count($inventory);
    $total_value = array_sum(array_map(function($item) {
        return $item['quantity'] * $item['cost_price'];
    }, $inventory));

    $low_stock_count = count($low_stock);
    $expiring_count = count($expiring_soon);

    // レジ関連データ取得
    $today = date('Y-m-d');
    $initial_cash_float = 0;
    $total_sales_cash = 0;
    $expected_cash_on_hand = 0;
    $actual_cash_on_hand_display = '';
    $discrepancy_display = '';
    $settlement_exists = false;

    // 今日の精算データを取得 (daily_settlementテーブル)
    $stmt_settlement = $pdo->prepare("SELECT * FROM daily_settlement WHERE settlement_date = ?");
    $stmt_settlement->execute([$today]);
    $settlement_data = $stmt_settlement->fetch(PDO::FETCH_ASSOC);
    if ($settlement_data) {
        $initial_cash_float = $settlement_data['initial_cash_float'];
        $actual_cash_on_hand_display = $settlement_data['actual_cash_on_hand'] !== null ? number_format($settlement_data['actual_cash_on_hand'], 0) : '';
        $discrepancy_display = $settlement_data['discrepancy'] !== null ? number_format($settlement_data['discrepancy'], 0) : '';
        $settlement_exists = true;
    }

    // 今日の売上合計を取得 (transactionsテーブル)
    $stmt_sales = $pdo->prepare("SELECT SUM(total_amount) AS total_sales FROM transactions WHERE DATE(transaction_date) = ?");
    $stmt_sales->execute([$today]);
    $result_sales = $stmt_sales->fetch(PDO::FETCH_ASSOC);
    if ($result_sales && $result_sales['total_sales'] !== null) {
        $total_sales_cash = $result_sales['total_sales'];
    }
    $expected_cash_on_hand = $initial_cash_float + $total_sales_cash;

    // アプリケーション設定取得 (app_settingsテーブル)
    $current_tax_rate = (float)getSetting($pdo, 'tax_rate', 10);
    // $current_low_stock_threshold は既に上で取得済み

    // 全ユーザーのリストとスタッフ詳細情報、歩合率を取得 (スタッフ管理用)
    $all_users = $pdo->query("
        SELECT u.id, u.username, u.role,
               sd.employee_id, sd.hire_date, sd.phone_number, sd.address, sd.emergency_contact,
               sc.commission_rate
        FROM users u
        LEFT JOIN staff_details sd ON u.id = sd.user_id
        LEFT JOIN staff_commissions sc ON u.id = sc.user_id
        ORDER BY u.username
    ")->fetchAll(PDO::FETCH_ASSOC);

    // staff_list は all_users と同じデータになるが、既存のコードとの互換性のため残す
    $staff_list = $all_users;

    // レポートタブ用のデータ
    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-d');

    // 売上概要
    $stmt_sales_summary = $pdo->prepare("
        SELECT
            SUM(t.total_amount) AS total_sales_amount,
            SUM(t.total_commission_amount) AS total_commissions,
            COUNT(t.id) AS total_transactions
        FROM transactions t
        WHERE DATE(t.transaction_date) BETWEEN ? AND ?
    ");
    $stmt_sales_summary->execute([$start_date, $end_date]);
    $sales_summary = $stmt_sales_summary->fetch(PDO::FETCH_ASSOC);

    // 商品別売上ランキング
    $stmt_product_sales_ranking = $pdo->prepare("
        SELECT
            ti.item_name,
            SUM(ti.quantity) AS total_quantity_sold,
            SUM(ti.item_price * ti.quantity) AS total_item_sales_amount
        FROM transaction_items ti
        JOIN transactions t ON ti.transaction_id = t.id
        WHERE DATE(t.transaction_date) BETWEEN ? AND ?
        GROUP BY ti.item_name
        ORDER BY total_item_sales_amount DESC
        LIMIT 10
    ");
    $stmt_product_sales_ranking->execute([$start_date, $end_date]);
    $product_sales_ranking = $stmt_product_sales_ranking->fetchAll(PDO::FETCH_ASSOC);

    // スタッフ別売上・歩合 (既存の売上集計)
    $stmt_staff_sales_commission = $pdo->prepare("
        SELECT
            u.username,
            SUM(t.total_amount) AS staff_total_sales,
            SUM(t.total_commission_amount) AS staff_total_commission
        FROM transactions t
        JOIN users u ON t.user_id = u.id
        WHERE DATE(t.transaction_date) BETWEEN ? AND ?
        GROUP BY u.username
        ORDER BY staff_total_sales DESC
    ");
    $stmt_staff_sales_commission->execute([$start_date, $end_date]);
    $staff_sales_commission = $stmt_staff_sales_commission->fetchAll(PDO::FETCH_ASSOC);

    // スタッフ別歩合レポート用のデータ取得
    $stmt_staff_commission_report = $pdo->prepare("
        SELECT
            u.username,
            SUM(t.total_commission_amount) AS total_commission_amount_for_report,
            COUNT(DISTINCT t.id) AS total_transactions_for_commission
        FROM transactions t
        JOIN users u ON t.user_id = u.id
        WHERE DATE(t.transaction_date) BETWEEN ? AND ?
        GROUP BY u.username
        ORDER BY total_commission_amount_for_report DESC
    ");
    $stmt_staff_commission_report->execute([$start_date, $end_date]);
    $staff_commission_report = $stmt_staff_commission_report->fetchAll(PDO::FETCH_ASSOC);

    // --- 変更点: スタッフ別商品販売詳細レポート用のデータ取得に歩合額を追加 ---
    $stmt_staff_item_sales_detail = $pdo->prepare("
        SELECT
            u.username,
            ti.item_name,
            SUM(ti.quantity) AS total_quantity_sold,
            SUM(ti.item_price * ti.quantity) AS total_item_sales_amount,
            SUM(
                CASE ti.item_commission_type
                    WHEN 'percentage' THEN ti.item_price * ti.quantity * (ti.item_commission_rate / 100)
                    WHEN 'fixed_amount' THEN ti.item_fixed_commission_amount * ti.quantity
                    ELSE 0
                END
            ) AS total_item_commission_amount
        FROM
            transactions t
        JOIN
            users u ON t.user_id = u.id
        JOIN
            transaction_items ti ON t.id = ti.transaction_id
        WHERE
            DATE(t.transaction_date) BETWEEN ? AND ?
        GROUP BY
            u.username, ti.item_name
        ORDER BY
            u.username ASC, total_item_sales_amount DESC
    ");
    $stmt_staff_item_sales_detail->execute([$start_date, $end_date]);
    $staff_item_sales_detail = $stmt_staff_item_sales_detail->fetchAll(PDO::FETCH_ASSOC);
    // ----------------------------------------------------------------------

    // 全取引履歴（レシート詳細も表示）
    $transactions_history = $pdo->query("
        SELECT
            t.id AS transaction_id,
            t.transaction_date,
            t.total_amount,
            t.cash_received,
            t.change_given,
            u.username AS staff_username,
            GROUP_CONCAT(CONCAT(ti.item_name, ' x ', ti.quantity, ' (', ti.item_price, '円)') SEPARATOR '<br>') AS items_list,
            t.total_commission_amount
        FROM transactions t
        LEFT JOIN users u ON t.user_id = u.id
        LEFT JOIN transaction_items ti ON t.id = ti.transaction_id
        GROUP BY t.id, t.transaction_date, t.total_amount, t.cash_received, t.change_given, u.username, t.total_commission_amount
        ORDER BY t.transaction_date DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);


} catch (PDOException $e) {
    // データベースエラー時のフォールバックとエラーメッセージ
    $categories_data = [];
    $categories = [];
    $inventory = [];
    $low_stock = [];
    $expiring_soon = [];
    $recent_movements = [];
    $total_items = 0;
    $total_value = 0;
    $low_stock_count = 0;
    $expiring_count = 0;
    $initial_cash_float = 0;
    $total_sales_cash = 0;
    $expected_cash_on_hand = 0;
    $actual_cash_on_hand_display = '';
    $discrepancy_display = '';
    $settlement_exists = false;
    $current_tax_rate = 10;
    $low_stock_threshold = 5;
    $all_users = []; // エラー時は空に
    $staff_list = []; // エラー時は空に
    $sales_summary = ['total_sales_amount' => 0, 'total_commissions' => 0, 'total_transactions' => 0];
    $product_sales_ranking = [];
    $staff_sales_commission = [];
    $staff_commission_report = [];
    $staff_item_sales_detail = []; // 追加
    $transactions_history = [];
    $_SESSION['error'] = '❌ データベースエラーが発生しました。システムを初期化するか、管理者にお問い合わせください。' . $e->getMessage();
}

// 現在アクティブなタブ
$active_tab = $_GET['tab'] ?? 'inventory'; // デフォルトは在庫一覧
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>在庫・精算 - 🏰 Cinderella cafe</title>
    <?php echo getCommonCSS(); ?>
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: #fcfcfc;
            color: #333;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border-left: 5px solid #00a499;
            border: 1px solid #e0e0e0;
        }
        .stat-number {
            font-size: 2em;
            font-weight: 700;
            color: #00a499;
            margin-bottom: 5px;
        }
        .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }
        .filter-form {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #e0e0e0;
        }
        .filter-form .form-group {
            display: inline-block;
            margin-right: 15px;
            margin-bottom: 10px;
            vertical-align: top;
        }
        .filter-form .form-group label {
            font-size: 14px;
            font-weight: 600;
        }
        .filter-form .form-group select,
        .filter-form .form-group input {
            width: auto;
            min-width: 150px;
        }
        .tab-buttons {
            display: flex;
            background: #e9ecef;
            border-radius: 6px;
            margin-bottom: 20px;
            overflow: hidden;
            flex-wrap: wrap; /* ボタンが多すぎる場合に折り返す */
        }
        .tab-button {
            flex: 1;
            min-width: 120px; /* 小さな画面でボタンが小さくなりすぎないように */
            padding: 12px;
            background: #e0e0e0;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 14px;
            font-weight: 600;
            white-space: nowrap;
            color: #555;
        }
        .tab-button.active {
            background: #00a499;
            color: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .tab-button:not(.active):hover {
            background-color: #d0d0d0;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .filter-form .form-group {
                display: block;
                margin-right: 0;
            }
            .tab-buttons {
                flex-direction: column;
            }
        }
        /* 精算画面のスタイル */
        .info-box {
            background-color: #e6f7e9;
            border: 1px solid #b7e0c4;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .info-box h2 {
            font-size: 1.5em;
            font-weight: 600;
            color: #1a6d2f;
            margin-bottom: 1em;
            text-align: center;
        }
        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 1em;
        }
        .info-item:last-child {
            margin-bottom: 0;
        }
        .info-label {
            font-weight: 500;
            color: #333;
        }
        .info-value {
            font-weight: 700;
            color: #1a6d2f;
        }
        .discrepancy-positive {
            color: #d9534f;
        }
        .discrepancy-negative {
            color: #5cb85c;
        }
        .denomination-input-group {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
        }
        .denomination-input-group label {
            flex: 1;
            text-align: right;
            padding-right: 15px;
            font-size: 14px;
            font-weight: 500;
        }
        .denomination-input-group input {
            flex: 2;
            padding: 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            text-align: right;
            font-size: 14px;
        }
        /* モーダルスタイル */
        .modal {
            display: none; /* Hidden by default */
            position: fixed; /* Stay in place */
            z-index: 1000; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%; /* Full width */
            height: 100%; /* Full height */
            overflow: auto; /* Enable scroll if needed */
            background-color: rgba(0,0,0,0.4); /* Black w/ opacity */
        }
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto; /* 5% from the top and centered */
            padding: 20px;
            border: 1px solid #888;
            width: 90%; /* Could be more or less, depending on screen size */
            max-width: 600px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            position: relative;
        }
        .close-button {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
        }
        .close-button:hover,
        .close-button:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }
        .modal-body .form-group {
            margin-bottom: 15px;
        }
        .modal-body .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #555;
            font-size: 14px;
        }
        .modal-body .form-group input,
        .modal-body .form-group select,
        .modal-body .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
            transition: border-color 0.2s;
            background-color: #f9f9f9;
        }
        .modal-body .form-group input:focus,
        .modal-body .form-group select:focus {
            outline: none;
            border-color: #00a499;
            background-color: #fff;
        }
        .modal-footer {
            padding-top: 15px;
            text-align: right;
        }
        /* 新しい状態表示のためのCSS */
        .status-danger {
            background: #fdecec;
            color: #b33939;
        }
        /* レポートタブのスタイル */
        .report-section {
            margin-bottom: 30px;
        }
        .report-section h4 {
            font-size: 1.3em;
            color: #00a499;
            margin-bottom: 15px;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 5px;
        }
        .report-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .report-summary-card {
            background: #fcfcfc;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid #e0e0e0;
            text-align: center;
        }
        .report-summary-value {
            font-size: 1.8em;
            font-weight: 700;
            color: #00a499;
            margin-bottom: 5px;
        }
        .report-summary-label {
            font-size: 0.9em;
            color: #666;
        }
        /* 歩合率設定の表示/非表示用 */
        .commission-field {
            display: none;
        }
        .commission-field.active {
            display: block;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Cinderella cafe</h1>
            <p>在庫・精算管理システム</p>
        </div>

        <div class="content">
            <?php echo getNavigation('select'); ?>

            <?php showMessage(); ?>

            <?php if (empty($categories_data) && empty($inventory)): ?>
                <div class="card">
                    <h3>🔧 システム初期化が必要です</h3>
                    <p>データベーステーブルが作成されていません。</p>
                    <div style="text-align: center; margin-top: 20px;">
                        <a href="index.php" class="btn success">🏠 ホームに戻る</a>
                    </div>
                </div>
            <?php else: ?>

            <div class="tab-buttons">
                <button class="tab-button <?php echo $active_tab === 'inventory' ? 'active' : ''; ?>" onclick="switchTab('inventory')">📦 在庫一覧</button>
                <button class="tab-button <?php echo $active_tab === 'alerts' ? 'active' : ''; ?>" onclick="switchTab('alerts')">⚠️ 警告一覧</button>
                <button class="tab-button <?php echo $active_tab === 'history' ? 'active' : ''; ?>" onclick="switchTab('history')">📋 入出庫履歴</button>
                <button class="tab-button <?php echo $active_tab === 'transactions' ? 'active' : ''; ?>" onclick="switchTab('transactions')">🧾 取引履歴</button>
                <button class="tab-button <?php echo $active_tab === 'settlement' ? 'active' : ''; ?>" onclick="switchTab('settlement')">💰 点検・精算</button>
                <button class="tab-button <?php echo $active_tab === 'reports' ? 'active' : ''; ?>" onclick="switchTab('reports')">📈 レポート</button>
                <button class="tab-button <?php echo $active_tab === 'staff_management' ? 'active' : ''; ?>" onclick="switchTab('staff_management')">🧑‍💻 スタッフ管理</button>
                <button class="tab-button <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" onclick="switchTab('settings')">⚙️ アプリ設定</button>
            </div>

            <div id="inventory" class="tab-content <?php echo $active_tab === 'inventory' ? 'active' : ''; ?>">
                <div class="filter-form">
                    <h4>� 絞り込み検索</h4>
                    <form method="GET">
                        <input type="hidden" name="tab" value="inventory">
                        <div class="form-group">
                            <label>カテゴリ</label>
                            <select name="category" onchange="this.form.submit()">
                                <option value="">全て</option>
                                <?php foreach ($categories_data as $category): ?>
                                    <option value="<?php echo htmlspecialchars($category['name']); ?>"
                                        <?php echo $filter_category === $category['name'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>状態</label>
                            <select name="status" onchange="this.form.submit()">
                                <option value="">全て</option>
                                <option value="normal" <?php echo $filter_status === 'normal' ? 'selected' : ''; ?>>正常在庫</option>
                                <option value="low_stock" <?php echo $filter_status === 'low_stock' ? 'selected' : ''; ?>>在庫不足</option>
                                <option value="expiring" <?php echo $filter_status === 'expiring' ? 'selected' : ''; ?>>期限間近/切れ</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>キーワード検索</label>
                            <input type="text" name="search_keyword" value="<?php echo htmlspecialchars($search_keyword); ?>" placeholder="商品名で検索">
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn" style="margin-top: 24px;">🔍 検索</button>
                            <a href="?tab=inventory" class="btn" style="background: #ccc; color: #333; margin-top: 24px;">🔄 リセット</a>
                        </div>
                    </form>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>商品名</th>
                                <th>カテゴリ</th>
                                <th>在庫数</th>
                                <th>単位</th>
                                <th>仕入価格</th>
                                <th>販売価格</th>
                                <th>発注点</th>
                                <th>状態</th>
                                <th>賞味期限</th>
                                <th>在庫価値</th>
                                <th>歩合設定</th> <!-- ヘッダーを「歩合設定」に変更 -->
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($inventory) > 0): ?>
                                <?php foreach ($inventory as $item):
                                    $item_status_class = 'status-normal';
                                    $item_status_text = '正常';
                                    $is_expiring_soon_or_expired = false;

                                    if ($item['quantity'] <= $item['reorder_level'] || $item['quantity'] <= $low_stock_threshold) {
                                        $item_status_class = 'status-low';
                                        $item_status_text = '要発注';
                                    }

                                    $expiry_display_text = '-';
                                    if ($item['expiry_date']) {
                                        $today_dt = new DateTime(date('Y-m-d'));
                                        $expiry_dt = new DateTime($item['expiry_date']);
                                        $interval = $today_dt->diff($expiry_dt);
                                        $days_until_expiry = (int)$interval->format('%r%a');

                                        $expiry_display_text = htmlspecialchars($item['expiry_date']);

                                        if ($days_until_expiry < 0) {
                                            $item_status_class = 'status-danger';
                                            $item_status_text = '期限切れ';
                                            $expiry_display_text .= ' (切れ)';
                                            $is_expiring_soon_or_expired = true;
                                        } elseif ($days_until_expiry <= 7) {
                                            if ($item_status_class === 'status-normal') {
                                                $item_status_class = 'status-warning';
                                                $item_status_text = '期限間近';
                                            } else {
                                                $item_status_text .= ' & 期限間近';
                                            }
                                            $expiry_display_text .= " ({$days_until_expiry}日)";
                                            $is_expiring_soon_or_expired = true;
                                        }
                                    }

                                    $display_row = true;
                                    if ($filter_status === 'normal' && ($item_status_class !== 'status-normal' || $is_expiring_soon_or_expired)) {
                                        $display_row = false;
                                    } elseif ($filter_status === 'low_stock' && $item_status_class !== 'status-low') {
                                        $display_row = false;
                                    } elseif ($filter_status === 'expiring' && !$is_expiring_soon_or_expired) {
                                        $display_row = false;
                                    }

                                    if (!$display_row) continue;

                                    // 商品の歩合設定表示
                                    $commission_display = '';
                                    if ($item['commission_type'] === 'percentage') {
                                        $commission_display = number_format($item['commission_rate'], 0) . '%';
                                    } elseif ($item['commission_type'] === 'fixed_amount') {
                                        $commission_display = number_format($item['fixed_commission_amount'], 0) . '円';
                                    }
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($item['name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($item['category_name'] ?? '未分類'); ?></td>
                                        <td><?php echo $item['quantity']; ?></td>
                                        <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                        <td>¥<?php echo number_format($item['cost_price'], 0); ?></td>
                                        <td>¥<?php echo number_format($item['selling_price'], 0); ?></td>
                                        <td><?php echo number_format($item['reorder_level'], 0); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $item_status_class; ?>"><?php echo $item_status_text; ?></span>
                                        </td>
                                        <td><?php echo $expiry_display_text; ?></td>
                                        <td>¥<?php echo number_format($item['quantity'] * $item['cost_price'], 0); ?></td>
                                        <td><?php echo htmlspecialchars($commission_display); ?></td> <!-- 歩合設定を表示 -->
                                        <td>
                                            <button type="button" class="btn btn-small" style="background: #007bff; margin-right: 5px;" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($item)); ?>)">編集</button>
                                            <form method="POST" action="create.php" style="display: inline;">
                                                <input type="hidden" name="action" value="delete_item">
                                                <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                <button type="submit" class="btn danger btn-small" onclick="return confirm('商品「<?php echo htmlspecialchars($item['name']); ?>」を削除しますか？\n※この操作は元に戻せません。')">🗑️</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="12" style="text-align: center; padding: 40px; color: #666;"> <!-- colspanを12に増やす -->
                                        <?php if ($filter_category || $filter_status || $search_keyword): ?>
                                            🔍 検索条件に一致する商品がありません
                                        <?php else: ?>
                                            📦 登録されている商品がありません
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="alerts" class="tab-content <?php echo $active_tab === 'alerts' ? 'active' : ''; ?>">
                <div class="card">
                    <h3>⚠️ 在庫不足商品</h3>
                    <?php if (count($low_stock) > 0): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>商品名</th>
                                        <th>現在庫数</th>
                                        <th>発注点</th>
                                        <th>不足数</th>
                                        <th>仕入先</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($low_stock as $item): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                                            <td><?php echo $item['quantity']; ?><?php echo htmlspecialchars($item['unit']); ?></td>
                                            <td><?php echo number_format($item['reorder_level'], 0); ?><?php echo htmlspecialchars($item['unit']); ?></td>
                                            <td class="status-badge status-low">
                                                <?php echo number_format(max(0, $item['reorder_level'] - $item['quantity']), 0); ?><?php echo htmlspecialchars($item['unit']); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($item['supplier'] ?? '未設定'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; color: #1a6d2f; font-weight: bold;">✅ 在庫不足の商品はありません</p>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <h3>📅 賞味期限間近商品</h3>
                    <?php if (count($expiring_soon) > 0): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>商品名</th>
                                        <th>在庫数</th>
                                        <th>賞味期限</th>
                                        <th>残り日数</th>
                                        <th>状態</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($expiring_soon as $item): ?>
                                        <?php
                                        $today_dt = new DateTime(date('Y-m-d'));
                                        $expiry_dt = new DateTime($item['expiry_date']);
                                        $interval = $today_dt->diff($expiry_dt);
                                        $days_until_expiry = (int)$interval->format('%r%a');
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                                            <td><?php echo $item['quantity']; ?><?php echo htmlspecialchars($item['unit']); ?></td>
                                            <td><?php echo htmlspecialchars($item['expiry_date']); ?></td>
                                            <td>
                                                <?php if ($days_until_expiry < 0): ?>
                                                    <span class="status-badge status-low">期限切れ</span>
                                                <?php elseif ($days_until_expiry == 0): ?>
                                                    <span class="status-badge status-warning">本日</span>
                                                <?php else: ?>
                                                    <span class="status-badge status-warning"><?php echo $days_until_expiry > 0 ? $days_until_expiry . '日' : '本日'; ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($days_until_expiry < 0): ?>
                                                    <span style="color: #d9534f;">🗑️ 廃棄推奨</span>
                                                <?php elseif ($days_until_expiry <= 3): ?>
                                                    <span style="color: #f0ad4e;">⚡ 早期販売推奨</span>
                                                <?php else: ?>
                                                    <span style="color: #00a499;">⚠️ 注意</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; color: #1a6d2f; font-weight: bold;">✅ 期限間近の商品はありません</p>
                    <?php endif; ?>
                </div>
            </div>

            <div id="history" class="tab-content <?php echo $active_tab === 'history' ? 'active' : ''; ?>">
                <div class="card">
                    <h3>📋 最近の入出庫履歴</h3>
                    <?php if (count($recent_movements) > 0): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>日時</th>
                                        <th>商品名</th>
                                        <th>処理</th>
                                        <th>数量</th>
                                        <th>理由</th>
                                        <th>担当者</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_movements as $movement): ?>
                                        <tr>
                                            <td><?php echo date('m/d H:i', strtotime($movement['created_at'])); ?></td>
                                            <td><?php echo htmlspecialchars($movement['item_name']); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo $movement['movement_type'] === '入庫' ? 'status-normal' : 'status-warning'; ?>">
                                                    <?php echo htmlspecialchars($movement['movement_type']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $movement['quantity']; ?><?php echo htmlspecialchars($movement['unit'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($movement['reason'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($movement['created_by']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; color: #666;">📝 履歴データがありません</p>
                    <?php endif; ?>
                </div>
            </div>

            <div id="transactions" class="tab-content <?php echo $active_tab === 'transactions' ? 'active' : ''; ?>">
                <div class="card">
                    <h3>🧾 最近の取引履歴</h3>
                    <?php if (count($transactions_history) > 0): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>取引ID</th>
                                        <th>日時</th>
                                        <th>スタッフ</th>
                                        <th>合計金額</th>
                                        <th>受取金額</th>
                                        <th>お釣り</th>
                                        <th>販売商品</th>
                                        <th>総歩合額</th> <!-- 追加: 総歩合額列 -->
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transactions_history as $transaction): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($transaction['transaction_id']); ?></td>
                                            <td><?php echo date('m/d H:i', strtotime($transaction['transaction_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($transaction['staff_username'] ?? '不明'); ?></td>
                                            <td>¥<?php echo number_format($transaction['total_amount']); ?></td>
                                            <td>¥<?php echo number_format($transaction['cash_received']); ?></td>
                                            <td>¥<?php echo number_format($transaction['change_given']); ?></td>
                                            <td><?php echo $transaction['items_list']; ?></td>
                                            <td>¥<?php echo number_format($transaction['total_commission_amount'], 0); ?></td> <!-- 総歩合額を表示 -->
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; color: #666;">取引履歴データがありません。</p>
                    <?php endif; ?>
                </div>
            </div>

            <div id="settlement" class="tab-content <?php echo $active_tab === 'settlement' ? 'active' : ''; ?>">
                <div class="info-box">
                    <h2 style="font-size: 1.5em; font-weight: 600; color: #1a6d2f; margin-bottom: 1em; text-align: center;">本日のサマリー (<?php echo htmlspecialchars($today); ?>)</h2>
                    <div class="info-item">
                        <span class="info-label">釣銭準備金:</span>
                        <span class="info-value">¥<?php echo number_format($initial_cash_float, 0); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">本日の売上 (現金):</span>
                        <span class="info-value">¥<?php echo number_format($total_sales_cash, 0); ?></span>
                    </div>
                    <div class="info-item" style="border-top: 1px solid #b7e0c4; padding-top: 10px; margin-top: 10px;">
                        <span class="info-label" style="font-size: 1.1em; font-weight: 600;">予想手元金額:</span>
                        <span class="info-value" style="font-size: 1.1em;">¥<?php echo number_format($expected_cash_on_hand, 0); ?></span>
                    </div>
                    <?php if ($settlement_exists && $settlement_data['actual_cash_on_hand'] !== null): ?>
                        <div class="info-item">
                            <span class="info-label">実際手元金額:</span>
                            <span class="info-value">¥<?php echo $actual_cash_on_hand_display; ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">差異:</span>
                            <span class="info-value <?php echo ($settlement_data['discrepancy'] > 0) ? 'discrepancy-positive' : (($settlement_data['discrepancy'] < 0) ? 'discrepancy-negative' : ''); ?>">
                                ¥<?php echo $discrepancy_display; ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <h3>💰 釣銭準備金の設定</h3>
                    <form method="POST" action="create.php">
                        <input type="hidden" name="action" value="set_cash_float">
                        <div class="form-group">
                            <label for="initial_cash_float">金額:</label>
                            <input type="number" id="initial_cash_float" name="initial_cash_float" step="1" min="0" class="form-input" value="<?php echo htmlspecialchars($initial_cash_float); ?>" required>
                        </div>
                        <button type="submit" class="btn success">釣銭準備金を設定/更新</button>
                    </form>
                </div>

                <div class="card">
                    <h3>✅ 精算</h3>
                    <?php if (!$settlement_exists || $initial_cash_float == 0): ?>
                        <p class="alert error">※ 精算を行う前に、まず釣銭準備金を設定してください。</p>
                    <?php endif; ?>
                    <form method="POST" action="create.php">
                        <input type="hidden" name="action" value="settle_up">
                        <div class="form-group">
                            <h4 style="font-size: 1.1em; margin-bottom: 0.8em; color: #333;">実際手元金額の内訳</h4>
                            <div class="denomination-input-group">
                                <label for="bill_10000">10,000円札:</label>
                                <input type="number" id="bill_10000" name="bill_10000" min="0" value="0" class="denomination-input" oninput="calculateActualCash()">
                            </div>
                            <div class="denomination-input-group">
                                <label for="bill_5000">5,000円札:</label>
                                <input type="number" id="bill_5000" name="bill_5000" min="0" value="0" class="denomination-input" oninput="calculateActualCash()">
                            </div>
                            <div class="denomination-input-group">
                                <label for="bill_1000">1,000円札:</label>
                                <input type="number" id="bill_1000" name="bill_1000" min="0" value="0" class="denomination-input" oninput="calculateActualCash()">
                            </div>
                            <div class="denomination-input-group">
                                <label for="coin_500">500円玉:</label>
                                <input type="number" id="coin_500" name="coin_500" min="0" value="0" class="denomination-input" oninput="calculateActualCash()">
                            </div>
                            <div class="denomination-input-group">
                                <label for="coin_100">100円玉:</label>
                                <input type="number" id="coin_100" name="coin_100" min="0" value="0" class="denomination-input" oninput="calculateActualCash()">
                            </div>
                            <div class="denomination-input-group">
                                <label for="coin_50">50円玉:</label>
                                <input type="number" id="coin_50" name="coin_50" min="0" value="0" class="denomination-input" oninput="calculateActualCash()">
                            </div>
                            <div class="denomination-input-group">
                                <label for="coin_10">10円玉:</label>
                                <input type="number" id="coin_10" name="coin_10" min="0" value="0" class="denomination-input" oninput="calculateActualCash()">
                            </div>
                            <div class="denomination-input-group">
                                <label for="coin_5">5円玉:</label>
                                <input type="number" id="coin_5" name="coin_5" min="0" value="0" class="denomination-input" oninput="calculateActualCash()">
                            </div>
                            <div class="denomination-input-group">
                                <label for="coin_1">1円玉:</label>
                                <input type="number" id="coin_1" name="coin_1" min="0" value="0" class="denomination-input" oninput="calculateActualCash()">
                            </div>
                        </div>

                        <div style="text-align: right; font-size: 1.2em; font-weight: bold; color: #00a499; border-top: 1px solid #e0e0e0; padding-top: 10px; margin-top: 10px;">
                            実際手元金額合計: ¥<span id="actual_cash_total_display">0</span>
                        </div>

                        <input type="hidden" id="actual_cash_on_hand" name="actual_cash_on_hand" value="0">
                        <button type="submit" class="btn success" style="width: 100%; font-size: 1.2em; padding: 15px; margin-top: 20px;" <?php echo (!$settlement_exists || $initial_cash_float == 0) ? 'disabled' : ''; ?>>精算する</button>
                    </form>
                </div>
            </div>

            <div id="reports" class="tab-content <?php echo $active_tab === 'reports' ? 'active' : ''; ?>">
                <div class="card">
                    <h3>📈 売上レポート</h3>
                    <form method="GET" class="filter-form" style="margin-bottom: 20px;">
                        <input type="hidden" name="tab" value="reports">
                        <div class="form-group">
                            <label for="start_date">開始日:</label>
                            <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="end_date">終了日:</label>
                            <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" required>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn" style="margin-top: 24px;">📅 レポート表示</button>
                        </div>
                    </form>

                    <div class="report-section">
                        <h4>概要</h4>
                        <div class="report-summary-grid">
                            <div class="report-summary-card">
                                <div class="report-summary-value">¥<?php echo number_format($sales_summary['total_sales_amount'] ?? 0); ?></div>
                                <div class="report-summary-label">総売上金額</div>
                            </div>
                            <div class="report-summary-card">
                                <div class="report-summary-value">¥<?php echo number_format($sales_summary['total_commissions'] ?? 0); ?></div>
                                <div class="report-summary-label">総歩合額</div>
                            </div>
                            <div class="report-summary-card">
                                <div class="report-summary-value"><?php echo number_format($sales_summary['total_transactions'] ?? 0); ?></div>
                                <div class="report-summary-label">総取引数</div>
                            </div>
                        </div>
                    </div>

                    <div class="report-section">
                        <h4>商品別売上ランキング (TOP 10)</h4>
                        <?php if (!empty($product_sales_ranking)): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>順位</th>
                                            <th>商品名</th>
                                            <th>販売数量</th>
                                            <th>売上金額</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $rank = 1; foreach ($product_sales_ranking as $product): ?>
                                            <tr>
                                                <td><?php echo $rank++; ?></td>
                                                <td><?php echo htmlspecialchars($product['item_name']); ?></td>
                                                <td><?php echo number_format($product['total_quantity_sold']); ?></td>
                                                <td>¥<?php echo number_format($product['total_item_sales_amount']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p style="text-align: center; color: #666;">期間内に販売された商品がありません。</p>
                        <?php endif; ?>
                    </div>

                    <div class="report-section">
                        <h4>スタッフ別売上・歩合 (売上集計)</h4>
                        <?php if (!empty($staff_sales_commission)): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>スタッフ名</th>
                                            <th>総売上</th>
                                            <th>総歩合</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($staff_sales_commission as $staff): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($staff['username']); ?></td>
                                                <td>¥<?php echo number_format($staff['staff_total_sales']); ?></td>
                                                <td>¥<?php echo number_format($staff['staff_total_commission']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p style="text-align: center; color: #666;">期間内に売上を計上したスタッフはいません。</p>
                        <?php endif; ?>
                    </div>

                    <!-- 追加: スタッフ別歩合レポート -->
                    <div class="report-section">
                        <h4>スタッフ別歩合レポート</h4>
                        <?php if (!empty($staff_commission_report)): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>スタッフ名</th>
                                            <th>総歩合額</th>
                                            <th>担当取引数</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($staff_commission_report as $staff): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($staff['username']); ?></td>
                                                <td>¥<?php echo number_format($staff['total_commission_amount_for_report']); ?></td>
                                                <td><?php echo number_format($staff['total_transactions_for_commission']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p style="text-align: center; color: #666;">期間内に歩合が発生したスタッフはいません。</p>
                        <?php endif; ?>
                    </div>
                    <!-- /追加: スタッフ別歩合レポート -->

                    <!-- 追加: スタッフ別商品販売詳細レポート -->
                    <div class="report-section">
                        <h4>スタッフ別商品販売詳細</h4>
                        <?php if (!empty($staff_item_sales_detail)): ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>スタッフ名</th>
                                            <th>商品名</th>
                                            <th>販売数量</th>
                                            <th>売上金額</th>
                                            <th>総歩合額</th> <!-- 新しいヘッダー -->
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $current_staff = null;
                                        foreach ($staff_item_sales_detail as $detail):
                                            if ($current_staff !== $detail['username']):
                                                if ($current_staff !== null): ?>
                                                    <tr><td colspan="5" style="height: 10px; background-color: #f0f0f0;"></td></tr> <!-- colspanを5に調整 -->
                                                <?php endif;
                                                $current_staff = $detail['username']; ?>
                                                <tr>
                                                    <td colspan="5" style="font-weight: bold; background-color: #e9ecef; padding: 8px;"> <!-- colspanを5に調整 -->
                                                        <?php echo htmlspecialchars($current_staff); ?>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                            <tr>
                                                <td></td> <!-- スタッフ名が重複しないように空欄 -->
                                                <td><?php echo htmlspecialchars($detail['item_name']); ?></td>
                                                <td><?php echo number_format($detail['total_quantity_sold']); ?></td>
                                                <td>¥<?php echo number_format($detail['total_item_sales_amount']); ?></td>
                                                <td>¥<?php echo number_format($detail['total_item_commission_amount']); ?></td> <!-- 総歩合額を表示 -->
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p style="text-align: center; color: #666;">期間内に販売詳細データがありません。</p>
                        <?php endif; ?>
                    </div>
                    <!-- /追加: スタッフ別商品販売詳細レポート -->

                </div>
            </div>

            <div id="staff_management" class="tab-content <?php echo $active_tab === 'staff_management' ? 'active' : ''; ?>">
                <div class="card">
                    <h3>🧑‍💻 スタッフ管理</h3>
                    <p style="margin-bottom: 15px;">新規スタッフの登録や、既存スタッフの情報を確認・設定できます。</p>
                    <div style="text-align: center; margin-bottom: 20px;">
                        <a href="register.php?from_settings=true" class="btn success">➕ 新規スタッフ登録</a>
                    </div>

                    <h4>登録済みスタッフ一覧</h4>
                    <?php if (count($all_users) > 0): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ユーザーID</th>
                                        <th>ユーザー名</th>
                                        <th>役割</th>
                                        <th>従業員ID</th>
                                        <th>入社日</th>
                                        <th>電話番号</th>
                                        <th>住所</th>
                                        <th>歩合率</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_users as $user): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($user['id']); ?></td>
                                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                                            <td><?php echo htmlspecialchars($user['role']); ?></td>
                                            <td><?php echo htmlspecialchars($user['employee_id'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($user['hire_date'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($user['phone_number'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($user['address'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($user['commission_rate'] ?? '0.00'); ?>%</td>
                                            <td>
                                                <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
                                                    <button type="button" class="btn btn-small" style="background: #007bff; margin-right: 5px;" onclick="openEditStaffModal(<?php echo htmlspecialchars(json_encode($user)); ?>)">編集</button>
                                                    <?php if ($user['id'] !== $_SESSION['user_id']): // ログイン中のユーザーは削除不可 ?>
                                                    <form method="POST" action="create.php" style="display: inline;">
                                                        <input type="hidden" name="action" value="delete_staff">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <button type="submit" class="btn danger btn-small" onclick="return confirm('ユーザー「<?php echo htmlspecialchars($user['username']); ?>」を削除しますか？\n※この操作は元に戻せません。')">🗑️</button>
                                                    </form>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span style="color: #666;">権限なし</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; color: #666;">登録されているスタッフがいません。</p>
                    <?php endif; ?>
                </div>
            </div>

            <div id="settings" class="tab-content <?php echo $active_tab === 'settings' ? 'active' : ''; ?>">
                <div class="card">
                    <h3>⚙️ アプリケーション設定</h3>
                    <form method="POST" action="create.php">
                        <input type="hidden" name="action" value="save_app_settings">
                        <div class="form-group">
                            <label for="tax_rate">税率 (%) :</label>
                            <input type="number" id="tax_rate" name="tax_rate" step="0.01" min="0" max="100" value="<?php echo htmlspecialchars($current_tax_rate); ?>" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label for="low_stock_threshold_setting">低在庫アラート閾値 (個) :</label>
                            <input type="number" id="low_stock_threshold_setting" name="low_stock_threshold" step="1" min="0" value="<?php echo htmlspecialchars($low_stock_threshold); ?>" class="form-input" required>
                        </div>
                        <button type="submit" class="btn success">設定を保存</button>
                    </form>
                </div>
                <div class="card" style="opacity: 0.7;">
                    <h3>店舗情報設定</h3>
                    <p>店舗名や住所、連絡先などの情報を設定します。</p>
                    <button class="btn" style="background: #ccc; color: #333;" disabled>編集 (未実装)</button>
                </div>
                <div class="card" style="opacity: 0.7;">
                    <h3>レシート設定</h3>
                    <p>レシートに表示するメッセージやロゴなどを設定します。</p>
                    <button class="btn" style="background: #ccc; color: #333;" disabled>編集 (未実装)</button>
                </div>
                <div class="card" style="opacity: 0.7;">
                    <h3>データ管理</h3>
                    <p>データベースのバックアップやデータのインポート/エクスポートなどを行います。</p>
                    <button class="btn" style="background: #ccc; color: #333;" disabled>実行 (未実装)</button>
                </div>
                <div class="card" style="border: 2px solid #dc3545; background-color: #fff5f5;">
                    <h3 style="color: #dc3545;">🗑️ データベースリセット</h3>
                    <div class="alert" style="background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; margin-bottom: 15px; padding: 15px; border-radius: 6px;">
                        <strong>⚠️ 重要な警告:</strong> この操作を実行すると、以下のデータが完全に削除されます：
                        <ul style="margin: 10px 0; padding-left: 20px;">
                            <li>すべての商品データ</li>
                            <li>すべての在庫データ</li>
                            <li>すべての取引履歴</li>
                            <li>すべての入出庫履歴</li>
                            <li>すべての精算データ</li>
                            <li>すべてのスタッフ情報（管理者とスタッフアカウントは再作成されます）</li>
                        </ul>
                        <strong style="color: #dc3545;">この操作は元に戻せません。</strong>
                    </div>
                    
                    <form method="POST" action="create.php" onsubmit="return confirmReset()">
                        <input type="hidden" name="action" value="reset_database">
                        <div class="form-group">
                            <label for="confirmation_key" style="color: #dc3545; font-weight: bold;">
                                確認キー入力 (「RESET_DATABASE」と入力してください):
                            </label>
                            <input 
                                type="text" 
                                id="confirmation_key" 
                                name="confirmation_key" 
                                placeholder="RESET_DATABASE" 
                                required 
                                style="border: 2px solid #dc3545; font-family: monospace;"
                                autocomplete="off"
                            >
                        </div>
                        <div style="text-align: center; margin-top: 20px;">
                            <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
                                <button type="submit" class="btn danger" style="font-size: 16px; padding: 12px 25px;">
                                    🗑️ データベースをリセットする
                                </button>
                            <?php else: ?>
                                <p style="color: #dc3545; font-weight: bold;">※ この機能は管理者のみ利用できます</p>
                                <button type="button" class="btn" style="background: #ccc; color: #333;" disabled>
                                    🗑️ データベースをリセットする (権限なし)
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <?php endif; ?>
        </div>
    </div>

    <!-- 商品編集モーダル (既存) -->
    <div id="editItemModal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeEditModal()">&times;</span>
            <h3 style="color: #00a499; margin-bottom: 15px;">📝 商品編集</h3>
            <div class="modal-body">
                <form id="editItemForm" method="POST" action="create.php">
                    <input type="hidden" name="action" value="update_item">
                    <input type="hidden" name="id" id="modal_edit_id">
                    <div class="form-group">
                        <label for="modal_name">商品名 <span style="color: #d9534f;">*</span></label>
                        <input type="text" name="name" id="modal_name" required>
                    </div>
                    <div class="form-group">
                        <label for="modal_category_id">カテゴリ <span style="color: #d9534f;">*</span></label>
                        <select name="category_id" id="modal_category_id" required>
                            <option value="">選択してください</option>
                            <?php foreach ($categories_data as $category): ?>
                                <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="modal_quantity">在庫数 <span style="color: #d9534f;">*</span></label>
                        <input type="number" name="quantity" id="modal_quantity" min="0" required>
                    </div>
                    <div class="form-group">
                        <label for="modal_unit">単位 <span style="color: #d9534f;">*</span></label>
                        <input type="text" name="unit" id="modal_unit" required>
                    </div>
                    <div class="form-group">
                        <label for="modal_reorder_level">発注点</label>
                        <input type="number" name="reorder_level" id="modal_reorder_level" min="0">
                    </div>
                    <div class="form-group">
                        <label for="modal_cost_price">仕入価格（円） <span style="color: #d9534f;">*</span></label>
                        <input type="number" name="cost_price" id="modal_cost_price" step="1" min="0" required>
                    </div>
                    <div class="form-group">
                        <label for="modal_selling_price">販売価格（円） <span style="color: #d9534f;">*</span></label>
                        <input type="number" name="selling_price" id="modal_selling_price" step="1" min="0" required>
                    </div>
                    <!-- 追加: 歩合率タイプ選択 -->
                    <div class="form-group">
                        <label>歩合タイプ</label>
                        <select name="commission_type" id="modal_commission_type" class="form-input" onchange="toggleCommissionFields('modal')">
                            <option value="percentage">パーセンテージ (%)</option>
                            <option value="fixed_amount">固定額 (円)</option>
                        </select>
                    </div>
                    <!-- 追加: 歩合率設定フィールド (パーセンテージ) -->
                    <div class="form-group commission-field" id="commission_rate_field_modal">
                        <label for="modal_commission_rate">歩合率 (%)</label>
                        <input type="number" name="commission_rate" id="modal_commission_rate" step="1" min="0" max="100">
                    </div>
                    <!-- 追加: 歩合率設定フィールド (固定額) -->
                    <div class="form-group commission-field" id="fixed_commission_amount_field_modal">
                        <label for="modal_fixed_commission_amount">固定額歩合 (円)</label>
                        <input type="number" name="fixed_commission_amount" id="modal_fixed_commission_amount" step="1" min="0">
                    </div>
                    <div class="form-group">
                        <label for="modal_supplier">仕入先</label>
                        <input type="text" name="supplier" id="modal_supplier">
                    </div>
                    <div class="form-group">
                        <label for="modal_expiry_date">賞味期限</label>
                        <input type="date" name="expiry_date" id="modal_expiry_date">
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn success">💾 更新</button>
                        <button type="button" class="btn" style="background: #ccc; color: #333;" onclick="closeEditModal()">キャンセル</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- スタッフ編集モーダル (新規追加) -->
    <div id="editStaffModal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeEditStaffModal()">&times;</span>
            <h3 style="color: #00a499; margin-bottom: 15px;">📝 スタッフ情報編集</h3>
            <div class="modal-body">
                <form id="editStaffForm" method="POST" action="create.php">
                    <input type="hidden" name="action" value="update_staff_details">
                    <input type="hidden" name="user_id" id="modal_staff_user_id">
                    <div class="form-group">
                        <label for="modal_staff_username">ユーザー名:</label>
                        <input type="text" name="username" id="modal_staff_username" required>
                    </div>
                    <div class="form-group">
                        <label for="modal_staff_role">役割:</label>
                        <select name="role" id="modal_staff_role" required <?php echo (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') ? '' : 'disabled'; ?>>
                            <option value="staff">スタッフ</option>
                            <option value="admin">管理者</option>
                        </select>
                        <?php if (!(isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin')): ?>
                            <p style="font-size:0.8em; color:#d9534f;">※ 役割の変更は管理者のみ可能です。</p>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="modal_staff_employee_id">従業員ID:</label>
                        <input type="text" name="employee_id" id="modal_staff_employee_id">
                    </div>
                    <div class="form-group">
                        <label for="modal_staff_hire_date">入社日:</label>
                        <input type="date" name="hire_date" id="modal_staff_hire_date">
                    </div>
                    <div class="form-group">
                        <label for="modal_staff_phone_number">電話番号:</label>
                        <input type="text" name="phone_number" id="modal_staff_phone_number">
                    </div>
                    <div class="form-group">
                        <label for="modal_staff_address">住所:</label>
                        <textarea name="address" id="modal_staff_address" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="modal_staff_emergency_contact">緊急連絡先:</label>
                        <input type="text" name="emergency_contact" id="modal_staff_emergency_contact">
                    </div>
                    <div class="form-group">
                        <label for="modal_staff_commission_rate">歩合率 (%):</label>
                        <input type="number" name="commission_rate" id="modal_staff_commission_rate" step="1" min="0" max="100" required disabled>
                        <p style="font-size:0.8em; color:#666;">※ 現在、スタッフの歩合率は個別商品歩合率とは別に計算されていません。</p>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn success" <?php echo (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') ? '' : 'disabled'; ?>>💾 更新</button>
                        <button type="button" class="btn" style="background: #ccc; color: #333;" onclick="closeEditStaffModal()">キャンセル</button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <script>
        const allInventoryItems = <?php echo json_encode($inventory); ?>;
        const allCategories = <?php echo json_encode($categories_data); ?>;
        const allUsersData = <?php echo json_encode($all_users); ?>; // スタッフ管理用の全ユーザーデータ

        function switchTab(tabName) {
            document.querySelectorAll('.tab-button').forEach(button => button.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

            document.querySelector(`.tab-button[onclick="switchTab(\'${tabName}\')"]`).classList.add('active');
            document.getElementById(tabName).classList.add('active');
            history.replaceState(null, null, '?tab=' + tabName);

            // タブ切り替え時に歩合率フィールドの表示を更新
            if (tabName === 'inventory') {
                // 在庫一覧タブに切り替わった場合、モーダルが非表示であれば何もしない
                // モーダルが表示されている場合はopenEditModal内でtoggleCommissionFieldsが呼ばれる
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const initialTab = urlParams.get('tab');
            if (initialTab) {
                switchTab(initialTab);
            } else {
                switchTab('inventory');
            }
            // input.phpのinventory_opsタブのフォーム用には必要なので、こちらで呼び出す。
            // select.phpのモーダルはopenEditModal内で呼び出される。
            if (document.getElementById('commission_type_ops')) { // input.phpの要素が存在する場合
                toggleCommissionFields('ops');
            }
        });

        function openEditModal(itemData) {
            document.getElementById('modal_edit_id').value = itemData.id;
            document.getElementById('modal_name').value = itemData.name;
            document.getElementById('modal_category_id').value = itemData.category_id;
            // 数値フィールドの小数点以下を非表示にする
            document.getElementById('modal_quantity').value = parseInt(itemData.quantity);
            document.getElementById('modal_unit').value = itemData.unit;
            document.getElementById('modal_cost_price').value = parseInt(itemData.cost_price); // 小数点以下を非表示
            document.getElementById('modal_selling_price').value = parseInt(itemData.selling_price); // 小数点以下を非表示
            document.getElementById('modal_reorder_level').value = parseInt(itemData.reorder_level); // 小数点以下を非表示
            document.getElementById('modal_supplier').value = itemData.supplier;
            document.getElementById('modal_expiry_date').value = itemData.expiry_date;

            // 追加: 歩合率タイプと値をモーダルに設定
            document.getElementById('modal_commission_type').value = itemData.commission_type;
            document.getElementById('modal_commission_rate').value = parseInt(itemData.commission_rate); // 小数点以下を非表示
            document.getElementById('modal_fixed_commission_amount').value = parseInt(itemData.fixed_commission_amount); // 小数点以下を非表示

            // 歩合率フィールドの表示/非表示を初期化
            toggleCommissionFields('modal');

            document.getElementById('editItemModal').style.display = 'block';
        }

        function closeEditModal() {
            document.getElementById('editItemModal').style.display = 'none';
        }

        // スタッフ編集モーダル関連の関数
        function openEditStaffModal(staffData) {
            document.getElementById('modal_staff_user_id').value = staffData.id;
            document.getElementById('modal_staff_username').value = staffData.username;
            document.getElementById('modal_staff_role').value = staffData.role;
            document.getElementById('modal_staff_employee_id').value = staffData.employee_id || '';
            document.getElementById('modal_staff_hire_date').value = staffData.hire_date || '';
            document.getElementById('modal_staff_phone_number').value = staffData.phone_number || '';
            document.getElementById('modal_staff_address').value = staffData.address || '';
            document.getElementById('modal_staff_emergency_contact').value = staffData.emergency_contact || '';
            // スタッフ歩合率も小数点以下を非表示に
            document.getElementById('modal_staff_commission_rate').value = parseInt(staffData.commission_rate || '0');

            // 役割と歩合率の入力フィールドは、セッションのuser_roleに応じてdisabledを設定
            const currentUserRole = "<?php echo $_SESSION['user_role'] ?? ''; ?>";
            if (currentUserRole !== 'admin') {
                document.getElementById('modal_staff_role').disabled = true;
                // スタッフ歩合率は個別商品歩合率と併用しないため、常にdisabledにする
                document.getElementById('modal_staff_commission_rate').disabled = true;
                document.querySelector('#editStaffForm button[type="submit"]').disabled = true; // 更新ボタンも無効化
            } else {
                document.getElementById('modal_staff_role').disabled = false;
                // 管理者でもスタッフ歩合率は個別商品歩合率と併用しないため、常にdisabledにする
                document.getElementById('modal_staff_commission_rate').disabled = true;
                document.querySelector('#editStaffForm button[type="submit"]').disabled = false;
            }

            document.getElementById('editStaffModal').style.display = 'block';
        }

        function closeEditStaffModal() {
            document.getElementById('editStaffModal').style.display = 'none';
        }

        window.onclick = function(event) {
            const itemModal = document.getElementById('editItemModal');
            const staffModal = document.getElementById('editStaffModal');
            if (event.target == itemModal) {
                itemModal.style.display = 'none';
            }
            if (event.target == staffModal) {
                staffModal.style.display = 'none';
            }
        }

        const denominations = {
            'bill_10000': 10000,
            'bill_5000': 5000,
            'bill_1000': 1000,
            'coin_500': 500,
            'coin_100': 100,
            'coin_50': 50,
            'coin_10': 10,
            'coin_5': 5,
            'coin_1': 1
        };

        function calculateActualCash() {
            let totalActualCash = 0;
            for (const id in denominations) {
                const inputElement = document.getElementById(id);
                if (!inputElement || inputElement.type === 'hidden') continue;
                const count = parseInt(inputElement.value) || 0;
                totalActualCash += count * denominations[id];
            }
            document.getElementById('actual_cash_on_hand').value = totalActualCash;
            document.getElementById('actual_cash_total_display').textContent = totalActualCash.toLocaleString();
        }

        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('settlement') && document.getElementById('settlement').classList.contains('active')) {
                calculateActualCash();
            }
            document.querySelectorAll('.tab-button').forEach(button => {
                button.addEventListener('click', function() {
                    const onclickAttr = this.getAttribute('onclick');
                    const match = onclickAttr.match(/switchTab\\('([^']+)'\\)/);
                    if (match && match[1] === 'settlement') {
                        setTimeout(calculateActualCash, 100);
                    }
                });
            });
        });

        document.querySelectorAll('.denomination-input').forEach(input => {
            input.addEventListener('input', calculateActualCash);
        });

        document.querySelectorAll('#inventory form button.danger').forEach(button => {
            button.addEventListener('click', function(e) {
                if (!confirm('本当に削除しますか？\n※この操作は元に戻せません。')) {
                    e.preventDefault();
                }
            });
        });

        // 歩合率フィールドの表示/非表示を切り替える関数
        function toggleCommissionFields(prefix) {
            const commissionTypeSelect = document.getElementById(`${prefix}_commission_type`);
            const percentageField = document.getElementById(`commission_rate_field_${prefix}`);
            const fixedAmountField = document.getElementById(`fixed_commission_amount_field_${prefix}`);

            if (!commissionTypeSelect || !percentageField || !fixedAmountField) {
                // 要素が存在しない場合は何もしない (別のページやタブの場合など)
                return;
            }

            if (commissionTypeSelect.value === 'percentage') {
                percentageField.classList.add('active');
                fixedAmountField.classList.remove('active');
                // 固定額フィールドの値をクリア (フォーム送信時に誤って送られないように)
                fixedAmountField.querySelector('input').value = '0'; // 0.00ではなく0に
            } else {
                percentageField.classList.remove('active');
                fixedAmountField.classList.add('active');
                // パーセンテージフィールドの値をクリア
                percentageField.querySelector('input').value = '0'; // 0.00ではなく0に
            }
            // データベースリセット確認関数
        function confirmReset() {
            const confirmationKey = document.getElementById('confirmation_key').value;
            
            if (confirmationKey !== 'RESET_DATABASE') {
                alert('確認キーが正しくありません。「RESET_DATABASE」と正確に入力してください。');
                document.getElementById('confirmation_key').focus();
                return false;
            }
            
            const confirmed = confirm(
                '本当にデータベースをリセットしますか？\n\n' +
                'この操作により以下が実行されます：\n' +
                '• 全てのデータが削除されます\n' +
                '• システムが初期状態に戻ります\n' +
                '• デフォルトアカウント（admin/password, staff/password）が再作成されます\n\n' +
                'この操作は元に戻せません。'
            );
            
            if (!confirmed) {
                return false;
            }
            
            const doubleConfirmed = confirm(
                '最終確認：\n\n' +
                'データベースを完全にリセットして\n' +
                '全てのデータを削除しますか？\n\n' +
                'この操作は取り消せません。'
            );
            
            if (doubleConfirmed) {
                // 処理中の表示
                const submitButton = document.querySelector('button[type="submit"]');
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.innerHTML = '🔄 リセット中...';
                }
            }
            
            return doubleConfirmed;
        }
        }
    </script>
</body>
</html>
�