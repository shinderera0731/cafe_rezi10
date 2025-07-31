<?php
// 共通設定ファイルを読み込み
include 'config.php';

// ログイン必須
// ホームページはログインしていなくてもアクセス可能にするため、ここでは requireLogin() をコメントアウトします。
// requireLogin();

// データ取得
try {
    // データベース接続が確立されていることを確認
    if (!isset($pdo)) {
        throw new PDOException("PDOオブジェクトがconfig.phpで初期化されていません。");
    }

    // `inventory` テーブルが存在するか確認
    $stmt_check_inventory = $pdo->query("SHOW TABLES LIKE 'inventory'");
    $inventory_table_exists = $stmt_check_inventory->rowCount() > 0;

    // `transactions` テーブルが存在するか確認
    $stmt_check_transactions = $pdo->query("SHOW TABLES LIKE 'transactions'");
    $transactions_table_exists = $stmt_check_transactions->rowCount() > 0;

    // `transaction_items` テーブルが存在するか確認 (新規追加)
    $stmt_check_transaction_items = $pdo->query("SHOW TABLES LIKE 'transaction_items'");
    $transaction_items_table_exists = $stmt_check_transaction_items->rowCount() > 0;

    // `daily_settlement` テーブルが存在するか確認
    $stmt_check_daily_settlement = $pdo->query("SHOW TABLES LIKE 'daily_settlement'");
    $daily_settlement_table_exists = $stmt_check_daily_settlement->rowCount() > 0;

    // `app_settings` テーブルが存在するか確認
    $stmt_check_app_settings = $pdo->query("SHOW TABLES LIKE 'app_settings'");
    $app_settings_table_exists = $stmt_check_app_settings->rowCount() > 0;

    // `users` テーブルが存在するか確認
    $stmt_check_users = $pdo->query("SHOW TABLES LIKE 'users'");
    $users_table_exists = $stmt_check_users->rowCount() > 0;

    // `staff_commissions` テーブルが存在するか確認
    $stmt_check_staff_commissions = $pdo->query("SHOW TABLES LIKE 'staff_commissions'");
    $staff_commissions_table_exists = $stmt_check_staff_commissions->rowCount() > 0;

    // `staff_details` テーブルが存在するか確認
    $stmt_check_staff_details = $pdo->query("SHOW TABLES LIKE 'staff_details'");
    $staff_details_table_exists = $stmt_check_staff_details->rowCount() > 0;


    // 必要なすべてのテーブルが存在するか
    $all_tables_exist = $inventory_table_exists && $transactions_table_exists && $transaction_items_table_exists && $daily_settlement_table_exists && $app_settings_table_exists && $users_table_exists && $staff_commissions_table_exists && $staff_details_table_exists;

    if (!$all_tables_exist) {
        // テーブルが存在しない場合、初期化を促す
        $_SESSION['error'] = '❌ データベーステーブルが見つかりません。システムを初期化してください。';
        $categories = []; // カテゴリも空にする
        $total_items = 0;
        $low_stock_count = 0;
        $expiring_count = 0;
        $total_value = 0;
        // スタッフ関連のデータはindex.phpではもう取得しない
    } else {
        // テーブルが存在する場合のみデータを取得
        $categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        $total_items = $pdo->query("SELECT COUNT(*) FROM inventory")->fetchColumn();
        $low_stock_count = $pdo->query("SELECT COUNT(*) FROM inventory WHERE quantity <= reorder_level")->fetchColumn();
        $expiring_count = $pdo->query("
            SELECT COUNT(*) FROM inventory
            WHERE expiry_date IS NOT NULL
            AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ")->fetchColumn();
        $total_value = $pdo->query("SELECT SUM(quantity * cost_price) FROM inventory")->fetchColumn() ?? 0;
    }

} catch (PDOException $e) {
    // データベース接続は成功したが、クエリでエラーが発生した場合
    $categories = [];
    $total_items = 0;
    $low_stock_count = 0;
    $expiring_count = 0;
    $total_value = 0;
    $_SESSION['error'] = '❌ データベース接続は成功しましたが、クエリにエラーが発生しました。テーブルが存在しない可能性があります。' . $e->getMessage();
}

// index.phpではもうタブ切り替えは行わないため、active_tabの定義は不要
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cinderella cafe レジ・在庫管理システム</title>
    <?php echo getCommonCSS(); ?>
    <style>
        .welcome-section {
            text-align: center;
            margin-bottom: 40px;
        }
        .welcome-title {
            font-size: 2.5em;
            color: #00a499;
            margin-bottom: 10px;
            font-weight: 700;
        }
        .welcome-subtitle {
            font-size: 1.2em;
            color: #666;
            margin-bottom: 30px;
        }
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        .menu-item {
            background: #fcfcfc;
            color: #333;
            padding: 25px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.2s ease;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid #e0e0e0;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .menu-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.1);
            background-color: #e6f7e9;
            border-color: #00a499;
        }
        .menu-icon {
            font-size: 2.5em;
            margin-bottom: 10px;
            display: block;
            color: #00a499;
        }
        .menu-title {
            font-size: 1.2em;
            font-weight: 600;
            margin-bottom: 5px;
        }
        .menu-description {
            font-size: 0.9em;
            color: #666;
            opacity: 0.9;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        .stat-card {
            background: #fcfcfc;
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
            color: #666;
        }
        .status-ok {
            border-left-color: #5cb85c;
        }
        .status-ok .stat-number {
            color: #5cb85c;
        }
        .status-warning {
            border-left-color: #f0ad4e;
        }
        .status-warning .stat-number {
            color: #f0ad4e;
        }
        .status-danger {
            border-left-color: #d9534f;
        }
        .status-danger .stat-number {
            color: #d9534f;
        }
        .quick-start {
            background: #fcfcfc;
            padding: 30px;
            border-radius: 8px;
            text-align: center;
            border: 1px dashed #e0e0e0;
        }
        .quick-start h3 {
            color: #00a499;
            margin-bottom: 20px;
            font-size: 1.5em;
        }
        .quick-start .btn.success {
            font-size: 16px;
            padding: 12px 25px;
        }
        .login-info {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            margin-top: 20px;
        }
        .login-info h4 {
            color: #00a499;
            margin-bottom: 10px;
        }
        /* タブ関連のスタイルはindex.phpではもう不要なので削除 */
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Cinderella cafe</h1>
            <p>レジ・在庫管理システム</p>
        </div>

        <div class="content">
            <?php echo getNavigation('index'); ?>

            <?php showMessage(); ?>

            <?php if (!$all_tables_exist): ?>
                <div class="quick-start">
                    <h3>🔧 システム初期化が必要です</h3>
                    <p style="margin-bottom: 20px;">データベーステーブルが見つからないか、不完全です。以下のボタンでシステムを初期化してください。</p>
                    <form method="POST" action="create.php">
                        <input type="hidden" name="action" value="create_tables">
                        <button type="submit" class="btn success">
                            システムを初期化する
                        </button>
                    </form>
                    <div class="login-info">
                        <h4>初期化後、以下のテストアカウントが利用できます:</h4>
                        <p>管理者: **admin / password**</p>
                        <p>スタッフ: **staff / password**</p>
                    </div>
                </div>
            <?php else: ?>

            <?php if (isLoggedIn()): ?>
                <!-- タブナビゲーションはindex.phpから削除 -->

                <!-- ダッシュボードコンテンツは直接表示 -->
                <div id="dashboard">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $total_items; ?></div>
                            <div class="stat-label">総商品数</div>
                        </div>
                        <div class="stat-card <?php echo $low_stock_count > 0 ? 'status-warning' : 'status-ok'; ?>">
                            <div class="stat-number"><?php echo $low_stock_count; ?></div>
                            <div class="stat-label">在庫不足</div>
                        </div>
                        <div class="stat-card <?php echo $expiring_count > 0 ? 'status-danger' : 'status-ok'; ?>">
                            <div class="stat-number"><?php echo $expiring_count; ?></div>
                            <div class="stat-label">期限間近</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number">¥<?php echo number_format($total_value); ?></div>
                            <div class="stat-label">総在庫価値</div>
                        </div>
                    </div>

                    <?php if ($low_stock_count > 0): ?>
                        <div class="alert warning">
                            <strong>⚠️ 在庫不足警告:</strong> <?php echo $low_stock_count; ?>件の商品が発注点を下回っています
                            <a href="select.php?tab=alerts&status=low_stock" style="margin-left: 10px; color: #8c6a0c; text-decoration: underline;">詳細を確認</a>
                        </div>
                    <?php endif; ?>

                    <?php if ($expiring_count > 0): ?>
                        <div class="alert warning">
                            <strong>📅 賞味期限警告:</strong> <?php echo $expiring_count; ?>件の商品が7日以内に期限切れになります
                            <a href="select.php?tab=alerts&status=expiring" style="margin-left: 10px; color: #8c6a0c; text-decoration: underline;">詳細を確認</a>
                        </div>
                    <?php endif; ?>

                    <div class="menu-grid">
                        <a href="input.php?tab=pos" class="menu-item">
                            <span class="menu-icon">🛒</span>
                            <div class="menu-title">注文入力・会計</div>
                            <div class="menu-description">商品の会計処理を実行</div>
                        </a>

                        <a href="select.php?tab=settlement" class="menu-item">
                            <span class="menu-icon">💰</span>
                            <div class="menu-title">点検・精算</div>
                            <div class="menu-description">日次の売上確認と精算処理</div>
                        </a>

                        <a href="input.php?tab=inventory_ops" class="menu-item">
                            <span class="menu-icon">📦</span>
                            <div class="menu-title">商品管理・入出庫</div>
                            <div class="menu-description">新商品の登録や在庫の増減</div>
                        </a>

                        <a href="select.php?tab=inventory" class="menu-item">
                            <span class="menu-icon">📊</span>
                            <div class="menu-title">在庫一覧・履歴</div>
                            <div class="menu-description">現在の在庫状況と入出庫履歴</div>
                        </a>

                        <!-- 新しく追加する「スタッフ管理」ボタン -->
                        <a href="select.php?tab=staff_management" class="menu-item">
                            <span class="menu-icon">🧑‍💻</span>
                            <div class="menu-title">スタッフ管理</div>
                            <div class="menu-description">スタッフ情報や歩合率の設定</div>
                        </a>

                        <!-- レポートボタンを追加 -->
                        <a href="select.php?tab=reports" class="menu-item">
                            <span class="menu-icon">📈</span>
                            <div class="menu-title">レポート</div>
                            <div class="menu-description">売上や商品別ランキングを確認</div>
                        </a>

                        <!-- 既存の「設定」ボタン -->
                        <a href="select.php?tab=settings" class="menu-item">
                            <span class="menu-icon">⚙️</span>
                            <div class="menu-title">設定</div>
                            <div class="menu-description">アプリケーションの各種設定</div>
                        </a>
                    </div>

                    <div class="card" style="text-align: center;">
                        <h4 style="color: #00a499; margin-bottom: 15px;">📱 システム情報</h4>
                        <p style="color: #666; margin-bottom: 10px; font-size: 0.9em;">
                            <strong>現在時刻:</strong> <?php echo date('Y年m月d日 H:i:s'); ?>
                        </p>
                        <p style="color: #666; margin-bottom: 10px; font-size: 0.9em;">
                            <strong>PHPバージョン:</strong> <?php echo phpversion(); ?>
                        </p>
                        <p style="color: #666; font-size: 0.9em;">
                            <strong>システム状態:</strong>
                            <span style="color: #5cb85c; font-weight: 600;">✅ 正常稼働中</span>
                        </p>
                        <p style="color: #666; margin-bottom: 10px; font-size: 0.9em;">
                            <strong>ログインユーザー:</strong> <?php echo htmlspecialchars($_SESSION['username']); ?> (<?php echo htmlspecialchars($_SESSION['user_role']); ?>)
                        </p>
                    </div>
                </div>
            <?php else: ?>
                <div class="card" style="text-align: center; padding: 50px;">
                    <h3>🔐 アプリケーションを使用するにはログインが必要です</h3>
                    <p style="margin-top: 20px; margin-bottom: 30px; font-size: 1.1em; color: #666;">
                        機能を利用するには、まずログインしてください。
                    </p>
                    <a href="login.php" class="btn success" style="font-size: 1.1em; padding: 15px 30px;">ログインページへ</a>
                </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // ページ読み込み時のアニメーション
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.querySelector('.container');
            container.style.opacity = '0';
            container.style.transform = 'translateY(20px)';

            setTimeout(function() {
                container.style.transition = 'all 0.8s ease';
                container.style.opacity = '1';
                container.style.transform = 'translateY(0)';
            }, 100);

            // メニューアイテムのホバーエフェクト強化 (ログイン時のみ)
            <?php if (isLoggedIn()): ?>
            const menuItems = document.querySelectorAll('.menu-item');
            menuItems.forEach(item => {
                item.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px) scale(1.02)'; // 少し控えめなアニメーション
                    this.style.boxShadow = '0 12px 25px rgba(0, 164, 153, 0.1)'; // シャドウを強調
                });

                item.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                    this.style.boxShadow = '0 2px 8px rgba(0,0,0,0.05)';
                });
            });

            // 統計カードのアニメーション (ログイン時のみ)
            function animateNumbers() {
                const statNumbers = document.querySelectorAll('.stat-number');
                statNumbers.forEach(element => {
                    const finalValue = parseInt(element.textContent.replace(/[^\d]/g, ''));
                    if (finalValue > 0) {
                        let currentValue = 0;
                        const increment = Math.ceil(finalValue / 20);
                        const timer = setInterval(() => {
                            currentValue += increment;
                            if (currentValue >= finalValue) {
                                currentValue = finalValue;
                                clearInterval(timer);
                            }

                            if (element.textContent.includes('¥')) {
                                element.textContent = '¥' + currentValue.toLocaleString();
                            } else {
                                element.textContent = currentValue;
                            }
                        }, 50);
                    }
                });
            }

            // ページ読み込み後に数字アニメーション実行 (ログイン時のみ)
            setTimeout(animateNumbers, 500);
            <?php endif; ?>
        });
    </script>
</body>
</html>
