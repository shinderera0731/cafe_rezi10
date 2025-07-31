<?php
// 共通設定ファイルを読み込み
include 'config.php';

// ログイン必須
requireLogin();

// データ取得
try {
    // カテゴリ一覧 (inventory用)
    $categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    // 在庫一覧（入出庫用）(inventory用)
    $inventory_items = $pdo->query("
        SELECT i.*, c.name as category_name
        FROM inventory i
        LEFT JOIN categories c ON i.category_id = c.id
        ORDER BY c.name, i.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    // レジ用商品リスト (inventoryテーブルから取得し、selling_priceをpriceとしてエイリアス)
    // 要件: 2.1.1 商品登録・会計処理 - 商品一覧表示
    // commission_rate, commission_type, fixed_commission_amount も取得
    $products = $pdo->query("SELECT id, name, selling_price AS price, quantity AS stock, unit, commission_type, commission_rate, fixed_commission_amount FROM inventory ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // 税率の読み込み
    // 要件: 2.6.1 店舗基本設定 - 標準税率設定
    $tax_rate = (float)getSetting($pdo, 'tax_rate', 10); // config.phpのgetSetting関数を使用

    // 低在庫アラート閾値の読み込み
    // 要件: 2.3.3 在庫アラート機能 - アラート設定
    $low_stock_threshold = (int)getSetting($pdo, 'low_stock_threshold', 5); // config.phpのgetSetting関数を使用

    // 全ユーザー（スタッフ）のリストを取得
    // 売上を紐づけるスタッフを選択するために使用
    $users = $pdo->query("SELECT id, username FROM users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $categories = [];
    $inventory_items = [];
    $products = [];
    $tax_rate = 10;
    $low_stock_threshold = 5;
    $users = []; // エラー時は空に
    $_SESSION['error'] = '❌ データベースエラーが発生しました。システムを初期化するか、管理者にお問い合わせください。' . $e->getMessage();
}

// カートの初期化
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// カートへの追加/更新 (inventoryテーブルを使用)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_to_cart'])) {
    $product_id = (int)($_POST['product_id'] ?? 0);
    $quantity_to_add = (int)($_POST['quantity'] ?? 0); // カートに追加しようとしている数量

    if ($quantity_to_add <= 0) {
        $_SESSION['error'] = '数量は1以上で入力してください。';
    } else {
        $found_product_in_db = false;
        // データベースから最新の商品情報を取得（在庫数を再チェック）
        try {
            // commission_rate, commission_type, fixed_commission_amount も取得
            $stmt = $pdo->prepare("SELECT id, name, selling_price AS price, quantity AS stock, unit, commission_type, commission_rate, fixed_commission_amount FROM inventory WHERE id = ?");
            $stmt->execute([$product_id]);
            $product_data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($product_data) {
                $found_product_in_db = true;
                $product_current_stock = $product_data['stock'];
                $current_cart_quantity = isset($_SESSION['cart'][$product_id]) ? $_SESSION['cart'][$product_id]['quantity'] : 0;
                $total_requested_quantity = $current_cart_quantity + $quantity_to_add;

                if ($total_requested_quantity > $product_current_stock) {
                    $_SESSION['error'] = '❌ 「' . htmlspecialchars($product_data['name']) . '」の在庫が不足しています。現在の在庫: ' . htmlspecialchars($product_current_stock) . $product_data['unit'] . ' です。';
                } else {
                    // カートに商品が存在するか確認し、存在すれば数量を更新、なければ追加
                    if (isset($_SESSION['cart'][$product_id])) {
                        $_SESSION['cart'][$product_id]['quantity'] += $quantity_to_add;
                    } else {
                        $_SESSION['cart'][$product_id] = [
                            'id' => $product_data['id'],
                            'name' => $product_data['name'],
                            'price' => $product_data['price'],
                            'quantity' => $quantity_to_add,
                            // 'stock' => $product_current_stock, // カートに在庫情報を保持 (表示用) - 削除
                            'commission_type' => $product_data['commission_type'],           // 新規追加
                            'commission_rate' => $product_data['commission_rate'],           // 新規追加
                            'fixed_commission_amount' => $product_data['fixed_commission_amount'] // 新規追加
                        ];
                    }
                    $_SESSION['message'] = '✅ カートに商品「' . htmlspecialchars($product_data['name']) . '」を' . $quantity_to_add . '個追加しました。';
                }
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = '❌ 商品情報の取得中にデータベースエラーが発生しました。';
            error_log("Cart Add Error: " . $e->getMessage());
        }

        if (!$found_product_in_db && !isset($_SESSION['error'])) { // エラーが既に設定されていなければ
            $_SESSION['error'] = '❌ 指定された商品が見つかりませんでした。';
        }
    }
    // リダイレクトしてPOSTデータをクリアし、メッセージを表示
    header('Location: input.php?tab=pos');
    exit;
}

// カートからの削除
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['remove_from_cart'])) {
    $product_id = (int)($_POST['product_id'] ?? 0);
    if (isset($_SESSION['cart'][$product_id])) {
        $item_name = $_SESSION['cart'][$product_id]['name'];
        unset($_SESSION['cart'][$product_id]);
        $_SESSION['message'] = '✅ カートから商品「' . htmlspecialchars($item_name) . '」を削除しました。';
    }
    header('Location: input.php?tab=pos');
    exit;
}

// 合計金額の計算 (表示用)
$current_subtotal = 0;
foreach ($_SESSION['cart'] as $item) {
    $current_subtotal += $item['price'] * $item['quantity'];
}
$current_tax_amount = $current_subtotal * ($tax_rate / 100);
$current_total = $current_subtotal + $current_tax_amount;

// 現在アクティブなタブ
$active_tab = $_GET['tab'] ?? 'pos'; // デフォルトはレジ画面

// 編集モードの商品データ取得
$edit_item_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$item_to_edit = null;
if ($active_tab === 'inventory_ops' && $edit_item_id > 0) {
    try {
        // commission_rate, commission_type, fixed_commission_amount も取得
        $stmt = $pdo->prepare("SELECT i.*, c.name as category_name FROM inventory i LEFT JOIN categories c ON i.category_id = c.id WHERE i.id = ?");
        $stmt->execute([$edit_item_id]);
        $item_to_edit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item_to_edit) {
            $_SESSION['error'] = '❌ 編集対象の商品が見つかりませんでした。';
            header('Location: input.php?tab=inventory_ops'); // 無効なIDなら編集モードを解除
            exit;
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = '❌ 商品情報の取得中にデータベースエラーが発生しました。';
        header('Location: input.php?tab=inventory_ops');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>レジ・商品管理 - 🏰 Cinderella cafe</title>
    <?php echo getCommonCSS(); ?>
    <style>
        .tab-buttons {
            display: flex;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 20px;
            overflow: hidden;
        }
        .tab-button {
            flex: 1;
            padding: 15px;
            background: #e9ecef;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 16px;
            white-space: nowrap; /* ボタン内のテキストを折り返さない */
        }
        .tab-button.active {
            background: #667eea;
            color: white;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 1rem;
        }
        .product-item {
            background-color: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            padding: 1rem;
            text-align: center;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }
        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px dashed #e5e7eb;
        }
        .cart-item:last-child {
            border-bottom: none;
        }
        .stock-info {
            font-size: 0.875rem;
            color: #6b7280;
            margin-top: 0.25rem;
        }
        .stock-low {
            color: #dc3545; /* Red for low stock */
            font-weight: 600;
        }
        .stock-warning {
            color: #ffc107; /* Orange for warning stock */
            font-weight: 600;
        }
        .form-input {
            width: 100%;
            padding: 10px;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        .form-input:focus {
            outline: none;
            border-color: #667eea;
        }
        .btn-small {
            padding: 5px 10px;
            font-size: 12px;
            border-radius: 4px;
        }
        .section-split {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }
        .section-left {
            flex: 2;
            min-width: 300px;
        }
        .section-right {
            flex: 1;
            min-width: 280px;
        }
        @media (max-width: 768px) {
            .section-split {
                flex-direction: column;
            }
        }
        /* 歩合率設定の表示/非表示用 */
        .commission-field {
            display: none;
        }
        .commission-field.active {
            display: block;
        }
        /* 商品別担当スタッフ選択用のスタイル */
        .cart-item-details {
            flex: 1;
        }
        .cart-item-staff {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid #f0f0f0;
        }
        .cart-item-staff label {
            font-size: 12px;
            color: #666;
            display: block;
            margin-bottom: 4px;
        }
        .cart-item-staff select {
            width: 100%;
            padding: 4px 8px;
            font-size: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #fff;
        }
        .cart-item-staff select:focus {
            outline: none;
            border-color: #00a499;
        }
        .cart-actions {
            margin-left: 10px;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🏰 Cinderella cafe</h1>
            <p>レジ・商品管理画面</p>
        </div>

        <div class="content">
            <!-- ナビゲーション -->
            <?php echo getNavigation('input'); ?>

            <!-- メッセージ表示 -->
            <?php showMessage(); ?>

            <!-- システム初期化（テーブルが存在しない場合） -->
            <?php if (empty($categories) && empty($inventory_items) && empty($products)): ?>
                <div class="card">
                    <h3>🔧 システム初期化が必要です</h3>
                    <p>最初にデータベーステーブルを作成してください。</p>
                    <form method="POST" action="create.php" style="margin-top: 15px;">
                        <input type="hidden" name="action" value="create_tables">
                        <button type="submit" class="btn success">データベーステーブルを作成</button>
                    </form>
                </div>
            <?php else: ?>

            <!-- タブナビゲーション -->
            <div class="tab-buttons">
                <button class="tab-button <?php echo $active_tab === 'pos' ? 'active' : ''; ?>" onclick="switchTab('pos')">🛒 注文入力・会計</button>
                <button class="tab-button <?php echo $active_tab === 'inventory_ops' ? 'active' : ''; ?>" onclick="switchTab('inventory_ops')">📦 商品追加・入出庫</button>
            </div>

            <!-- 注文入力・会計タブ -->
            <div id="pos" class="tab-content <?php echo $active_tab === 'pos' ? 'active' : ''; ?>">
                <div class="section-split">
                    <div class="card section-left">
                        <h3>🧾 商品選択</h3>
                        <?php if (empty($products)): ?>
                            <p class="alert warning">レジで販売できる商品が登録されていません。<a href="input.php?tab=inventory_ops" style="text-decoration: underline;">商品追加画面</a>から追加してください。</p>
                        <?php else: ?>
                            <div class="product-grid">
                                <?php foreach ($products as $product): ?>
                                    <div class="product-item">
                                        <h4 class="font-bold"><?php echo htmlspecialchars($product['name']); ?></h4>
                                        <p>¥<?php echo number_format(htmlspecialchars($product['price']), 0); ?></p>
                                        <p class="stock-info <?php echo ($product['stock'] <= $low_stock_threshold && $product['stock'] > 0) ? 'stock-warning' : ''; ?> <?php echo ($product['stock'] == 0) ? 'stock-low' : ''; ?>">
                                            在庫: <?php echo htmlspecialchars($product['stock']); ?>
                                        </p>
                                        <form method="POST" action="input.php" style="margin-top: 10px;">
                                            <input type="hidden" name="add_to_cart" value="1">
                                            <input type="hidden" name="product_id" value="<?php echo htmlspecialchars($product['id']); ?>">
                                            <input type="number" name="quantity" value="1" min="1" class="form-input" style="width: 80px; text-align: center; margin-bottom: 5px;" <?php echo ($product['stock'] == 0) ? 'disabled' : ''; ?>>
                                            <button type="submit" class="btn btn-primary btn-small" <?php echo ($product['stock'] == 0) ? 'disabled' : ''; ?>>カートに追加</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="card section-right">
                        <h3>🛒 カート</h3>
                        <?php if (empty($_SESSION['cart'])): ?>
                            <p>カートは空です。</p>
                        <?php else: ?>
                            <div style="margin-bottom: 15px;">
                                <?php foreach ($_SESSION['cart'] as $item_id => $item): ?>
                                    <div class="cart-item">
                                        <div class="cart-item-details">
                                            <div>
                                                <span class="font-bold"><?php echo htmlspecialchars($item['name']); ?></span>
                                                <span style="font-size: 0.9em; color: #666;"> x <?php echo htmlspecialchars($item['quantity']); ?></span>
                                                <br>¥<?php echo number_format(htmlspecialchars($item['price']), 0); ?>
                                            </div>
                                            <!-- 新規追加: 商品別担当スタッフ選択 -->
                                            <div class="cart-item-staff">
                                                <label for="item_staff_<?php echo $item_id; ?>">担当スタッフ:</label>
                                                <select name="item_staff[<?php echo $item_id; ?>]" id="item_staff_<?php echo $item_id; ?>" form="checkout-form" required>
                                                    <option value="">選択してください</option>
                                                    <?php foreach ($users as $user): ?>
                                                        <option value="<?php echo htmlspecialchars($user['id']); ?>">
                                                            <?php echo htmlspecialchars($user['username']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="cart-actions">
                                            <form method="POST" action="input.php">
                                                <input type="hidden" name="remove_from_cart" value="1">
                                                <input type="hidden" name="product_id" value="<?php echo htmlspecialchars($item['id']); ?>">
                                                <button type="submit" class="btn danger btn-small">削除</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div style="text-align: right; border-top: 1px solid #eee; padding-top: 10px; margin-top: 10px;">
                                <p style="font-size: 1.1em; color: #333;">小計: ¥<?php echo number_format($current_subtotal, 0); ?></p>
                                <p style="font-size: 1.1em; color: #333;">税率 (<?php echo htmlspecialchars($tax_rate); ?>%): ¥<?php echo number_format($current_tax_amount, 0); ?></p>
                                <p style="font-size: 1.5em; font-weight: bold; color: #667eea; margin-top: 5px;">合計: ¥<?php echo number_format($current_total, 0); ?></p>
                            </div>

                            <form id="checkout-form" method="POST" action="create.php" style="margin-top: 20px;">
                                <input type="hidden" name="action" value="checkout">
                                <!-- スタッフ選択ドロップダウンの追加 -->
                                <div class="form-group">
                                    <label for="transaction_user_id">売上計上スタッフ:</label>
                                    <select id="transaction_user_id" name="transaction_user_id" class="form-input" required>
                                        <?php foreach ($users as $user): ?>
                                            <option value="<?php echo htmlspecialchars($user['id']); ?>"
                                                <?php echo (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($user['username']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="cash_received">受取金額 (現金):</label>
                                    <input type="number" id="cash_received" name="cash_received" step="1" min="<?php echo floor($current_total); ?>" required placeholder="例: 1000" inputmode="numeric" pattern="\d*">
                                </div>
                                <button type="submit" class="btn success" style="width: 100%; font-size: 1.2em; padding: 15px;">会計する</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 商品追加・入出庫タブ -->
            <div id="inventory_ops" class="tab-content <?php echo $active_tab === 'inventory_ops' ? 'active' : ''; ?>">
                <!-- 商品追加/編集フォーム -->
                <div class="card">
                    <h3><?php echo $item_to_edit ? '📝 商品編集' : '➕ 新商品追加'; ?></h3>
                    <form method="POST" action="create.php">
                        <input type="hidden" name="action" value="<?php echo $item_to_edit ? 'update_item' : 'add_item'; ?>">
                        <?php if ($item_to_edit): ?>
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($item_to_edit['id']); ?>">
                        <?php endif; ?>
                        <div class="form-grid">
                            <div>
                                <div class="form-group">
                                    <label>商品名 <span style="color: red;">*</span></label>
                                    <input type="text" name="name" required placeholder="例：ブラジル産コーヒー豆" value="<?php echo htmlspecialchars($item_to_edit['name'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label>カテゴリ <span style="color: red;">*</span></label>
                                    <select name="category_id" required>
                                        <option value="">選択してください</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?php echo $category['id']; ?>"
                                                <?php echo (isset($item_to_edit['category_id']) && $item_to_edit['category_id'] == $category['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($category['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>在庫数 <span style="color: red;">*</span></label>
                                    <input type="number" name="quantity" min="0" required placeholder="例：50" value="<?php echo htmlspecialchars($item_to_edit['quantity'] ?? 0); ?>">
                                </div>
                                <div class="form-group">
                                    <label>単位 <span style="color: red;">*</span></label>
                                    <input type="text" name="unit" placeholder="例：kg, 個, L, 袋" required value="<?php echo htmlspecialchars($item_to_edit['unit'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label>発注点（この数値以下で警告表示）</label>
                                    <input type="number" name="reorder_level" min="0" value="<?php echo htmlspecialchars($item_to_edit['reorder_level'] ?? 10); ?>" placeholder="例：10">
                                </div>
                            </div>
                            <div>
                                <div class="form-group">
                                    <label>仕入価格（円） <span style="color: red;">*</span></label>
                                    <input type="number" name="cost_price" step="0.01" min="0" required placeholder="例：1200.00" value="<?php echo htmlspecialchars($item_to_edit['cost_price'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label>販売価格（円） <span style="color: red;">*</span></label>
                                    <input type="number" name="selling_price" step="0.01" min="0" required placeholder="例：1800.00" value="<?php echo htmlspecialchars($item_to_edit['selling_price'] ?? ''); ?>">
                                </div>
                                <!-- 新規追加: 歩合率タイプ選択 -->
                                <div class="form-group">
                                    <label>歩合タイプ</label>
                                    <select name="commission_type" id="commission_type_ops" class="form-input" onchange="toggleCommissionFields('ops')">
                                        <option value="percentage" <?php echo (isset($item_to_edit['commission_type']) && $item_to_edit['commission_type'] === 'percentage') ? 'selected' : ''; ?>>パーセンテージ (%)</option>
                                        <option value="fixed_amount" <?php echo (isset($item_to_edit['commission_type']) && $item_to_edit['commission_type'] === 'fixed_amount') ? 'selected' : ''; ?>>固定額 (円)</option>
                                    </select>
                                </div>
                                <!-- 新規追加: 商品ごとの歩合率 (パーセンテージ) -->
                                <div class="form-group commission-field" id="commission_rate_field_ops">
                                    <label>歩合率 (%)</label>
                                    <input type="number" name="commission_rate" step="0.01" min="0" max="100" value="<?php echo htmlspecialchars($item_to_edit['commission_rate'] ?? 0); ?>" placeholder="例：5.00">
                                </div>
                                <!-- 新規追加: 商品ごとの歩合率 (固定額) -->
                                <div class="form-group commission-field" id="fixed_commission_amount_field_ops">
                                    <label>固定額歩合 (円)</label>
                                    <input type="number" name="fixed_commission_amount" step="0.01" min="0" value="<?php echo htmlspecialchars($item_to_edit['fixed_commission_amount'] ?? 0); ?>" placeholder="例：100.00">
                                </div>
                                <div class="form-group">
                                    <label>仕入先</label>
                                    <input type="text" name="supplier" placeholder="例：○○商事" value="<?php echo htmlspecialchars($item_to_edit['supplier'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label>賞味期限</label>
                                    <input type="date" name="expiry_date" value="<?php echo htmlspecialchars($item_to_edit['expiry_date'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        <div style="text-align: center; margin-top: 20px;">
                            <button type="submit" class="btn success">
                                <?php echo $item_to_edit ? '💾 商品を更新' : '💾 商品を追加'; ?>
                            </button>
                            <?php if ($item_to_edit): ?>
                                <button type="button" onclick="location.href='input.php?tab=inventory_ops'" class="btn" style="background: #6c757d;">🔄 キャンセル</button>
                            <?php else: ?>
                                <button type="reset" class="btn" style="background: #6c757d;">🔄 リセット</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- 入出庫フォーム -->
                <div class="card" id="movement">
                    <h3>🔄 入出庫処理</h3>
                    <?php if (count($inventory_items) > 0): ?>
                        <form method="POST" action="create.php">
                            <input type="hidden" name="action" value="update_stock">
                            <div class="form-grid">
                                <div>
                                    <div class="form-group">
                                        <label>商品選択 <span style="color: red;">*</span></label>
                                        <select name="item_id" required>
                                            <option value="">選択してください</option>
                                            <?php foreach ($inventory_items as $item): ?>
                                                <option value="<?php echo $item['id']; ?>">
                                                    <?php echo htmlspecialchars($item['name']); ?>
                                                    (現在: <?php echo $item['quantity']; ?><?php echo $item['unit']; ?>)
                                                    <?php if ($item['quantity'] <= $item['reorder_level']): ?>
                                                        ⚠️
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>処理種別 <span style="color: red;">*</span></label>
                                        <select name="movement_type" required>
                                            <option value="">選択してください</option>
                                            <option value="入庫">📦 入庫（仕入・補充）</option>
                                            <option value="出庫">📤 出庫（販売・使用）</option>
                                            <option value="廃棄">🗑️ 廃棄</option>
                                            <option value="調整">⚖️ 棚卸調整</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <div class="form-group">
                                        <label>数量 <span style="color: red;">*</span></label>
                                        <input type="number" name="new_quantity" min="1" required placeholder="例：5">
                                    </div>
                                    <div class="form-group">
                                        <label>理由・メモ</label>
                                        <input type="text" name="reason" placeholder="例：朝の仕入、ランチ販売、期限切れ廃棄">
                                    </div>
                                </div>
                            </div>
                            <div style="text-align: center; margin-top: 20px;">
                                <button type="submit" class="btn">🔄 在庫を更新</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="alert warning">
                            <strong>⚠️ 注意:</strong> 商品が登録されていません。先に商品を追加してください。
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 使い方ガイド -->
                <div class="card">
                    <h3>📖 使い方ガイド</h3>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 6px;">
                        <h4>📝 商品追加の手順</h4>
                        <ol style="margin-left: 20px;">
                            <li>商品名、カテゴリ、初期在庫数を入力</li>
                            <li>単位、仕入価格、販売価格を設定</li>
                            <li>発注点を設定（この数値以下で警告表示）</li>
                            <li>「商品を追加」ボタンをクリック</li>
                        </ol>

                        <h4>🔄 入出庫処理の手順</h4>
                        <ol style="margin-left: 20px;">
                            <li>処理したい商品を選択</li>
                            <li>処理種別を選択（入庫、出庫、廃棄、調整）</li>
                            <li>数量を入力</li>
                            <li>理由やメモを記入（任意）</li>
                            <li>「在庫を更新」ボタンをクリック</li>
                        </ol>

                        <h4>🛒 商品別担当スタッフ機能</h4>
                        <ul style="margin-left: 20px;">
                            <li><strong>カート内での設定:</strong> 各商品ごとに担当スタッフを選択</li>
                            <li><strong>歩合計算:</strong> 商品の歩合は担当スタッフに紐づけて記録</li>
                            <li><strong>レポート:</strong> スタッフ別の販売実績を確認可能</li>
                        </ul>

                        <h4>💡 便利な機能</h4>
                        <ul style="margin-left: 20px;">
                            <li><strong>自動警告:</strong> 発注点を下回ると⚠️マークが表示</li>
                            <li><strong>履歴記録:</strong> すべての入出庫は自動で記録</li>
                            <li><strong>在庫価値:</strong> 仕入価格×在庫数で自動計算</li>
                        </ul>
                    </div>
                </div>
            </div>

            <?php endif; ?>

            <!-- クイックリンク -->
            <div style="text-align: center; margin-top: 30px;">
                <a href="index.php" class="btn" style="background: #6c757d;">🏠 ホームに戻る</a>
            </div>
        </div>
    </div>

    <script>
        // タブ切り替え機能
        function switchTab(tabName) {
            document.querySelectorAll('.tab-button').forEach(button => button.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

            document.querySelector(`.tab-button[onclick="switchTab(\'${tabName}\')"]`).classList.add('active');
            document.getElementById(tabName).classList.add('active');

            // URLのハッシュを更新 (ページリロードなしでタブ状態を維持)
            history.replaceState(null, null, '?tab=' + tabName);

            // タブ切り替え時に歩合率フィールドの表示を更新
            if (tabName === 'inventory_ops') {
                toggleCommissionFields('ops');
            }
        }

        // ページロード時にURLのタブパラメータをチェックして表示
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const initialTab = urlParams.get('tab');
            if (initialTab) {
                switchTab(initialTab);
            } else {
                // デフォルトタブをアクティブにする
                switchTab('pos');
            }
            // ページロード時にも歩合率フィールドの表示を初期化
            toggleCommissionFields('ops');
        });

        // フォーム送信時の確認
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const actionInput = this.querySelector('input[name="action"]');
                if (!actionInput) return; // actionがないフォームはスキップ

                const action = actionInput.value;

                if (action === 'add_item') {
                    const name = this.querySelector('input[name="name"]').value;
                    if (!confirm(`商品「${name}」を追加しますか？`)) {
                        e.preventDefault();
                    }
                }
                if (action === 'update_item') {
                    const name = this.querySelector('input[name="name"]').value;
                    if (!confirm(`商品「${name}」を更新しますか？`)) {
                        e.preventDefault();
                    }
                }

                if (action === 'update_stock') {
                    const movementType = this.querySelector('select[name="movement_type"]').value;
                    const quantity = this.querySelector('input[name="new_quantity"]').value;
                    if (!confirm(`${movementType}処理（数量: ${quantity}）を実行しますか？`)) {
                        e.preventDefault();
                    }
                }
                if (action === 'checkout') {
                    // 商品別担当スタッフの選択確認
                    const itemStaffSelects = this.querySelectorAll('select[name^="item_staff"]');
                    let allStaffSelected = true;
                    let unassignedItems = [];

                    itemStaffSelects.forEach(select => {
                        if (!select.value) {
                            allStaffSelected = false;
                            // 商品名を取得
                            const cartItem = select.closest('.cart-item');
                            const itemName = cartItem.querySelector('.font-bold').textContent;
                            unassignedItems.push(itemName);
                        }
                    });

                    if (!allStaffSelected) {
                        alert('以下の商品の担当スタッフが選択されていません：\n' + unassignedItems.join('\n'));
                        e.preventDefault();
                        return;
                    }

                    const totalElement = document.querySelector('#pos .section-right p:last-child'); // Adjusted selector
                    const total = totalElement ? totalElement.textContent : '不明';
                    const cashReceivedInput = this.querySelector('input[name="cash_received"]');
                    const cashReceived = cashReceivedInput ? cashReceivedInput.value : '不明';

                    // 追加: 選択されたスタッフ名を取得
                    const selectedStaffElement = document.getElementById('transaction_user_id');
                    const selectedStaffName = selectedStaffElement ? selectedStaffElement.options[selectedStaffElement.selectedIndex].text : '不明';

                    if (!confirm(`売上をスタッフ「${selectedStaffName}」に計上し、合計 ${total} を受取金額 ¥${cashReceived} で会計しますか？\n\n各商品の担当スタッフも記録されます。`)) {
                        e.preventDefault();
                    }
                }
            });
        });

        // 処理種別に応じて説明文を表示 (商品管理タブ内)
        const movementTypeSelect = document.querySelector('#inventory_ops select[name="movement_type"]');
        if (movementTypeSelect) {
            movementTypeSelect.addEventListener('change', function() {
                const infoDiv = document.getElementById('movement-info');
                if (infoDiv) infoDiv.remove();

                const info = {
                    '入庫': '在庫数が増加します（仕入、補充など）',
                    '出庫': '在庫数が減少します（販売、使用など）',
                    '廃棄': '在庫数が減少します（期限切れ、破損など）',
                    '調整': '棚卸結果に基づいて在庫数を調整します'
                };

                if (this.value && info[this.value]) {
                    const div = document.createElement('div');
                    div.id = 'movement-info';
                    div.style.cssText = 'background: #e7f3ff; padding: 8px; border-radius: 4px; font-size: 14px; margin-top: 5px; color: #0066cc;';
                    div.textContent = '💡 ' + info[this.value];
                    this.parentNode.appendChild(div);
                }
            });
        }

        // リアルタイム利益計算 (商品管理タブ内)
        const costPriceInput = document.querySelector('#inventory_ops input[name="cost_price"]');
        const sellingPriceInput = document.querySelector('#inventory_ops input[name="selling_price"]');

        function calculateProfit() {
            const costPrice = parseFloat(costPriceInput.value) || 0;
            const sellingPrice = parseFloat(sellingPriceInput.value) || 0;
            const profit = sellingPrice - costPrice;
            const profitMargin = costPrice > 0 ? ((profit / costPrice) * 100).toFixed(1) : 0;

            let profitDiv = document.getElementById('profit-info');
            if (!profitDiv) {
                profitDiv = document.createElement('div');
                profitDiv.id = 'profit-info';
                profitDiv.style.cssText = 'background: #f8f9fa; padding: 10px; border-radius: 4px; font-size: 14px; margin-top: 10px; border-left: 4px solid #667eea;';
                sellingPriceInput.parentNode.appendChild(profitDiv);
            }

            if (costPrice > 0 && sellingPrice > 0) {
                profitDiv.innerHTML = `
                    <strong>💰 利益計算:</strong><br>
                    利益: ¥${profit.toLocaleString()}
                    (利益率: ${profitMargin}%)
                `;
                profitDiv.style.color = profit > 0 ? '#28a745' : '#dc3545';
            } else {
                profitDiv.innerHTML = '';
            }
        }

        if (costPriceInput && sellingPriceInput) {
            costPriceInput.addEventListener('input', calculateProfit);
            sellingPriceInput.addEventListener('input', calculateProfit);
        }

        // 歩合率フィールドの表示/非表示を切り替える関数
        function toggleCommissionFields(prefix) {
            const commissionTypeSelect = document.getElementById(`commission_type_${prefix}`);
            const percentageField = document.getElementById(`commission_rate_field_${prefix}`);
            const fixedAmountField = document.getElementById(`fixed_commission_amount_field_${prefix}`);

            if (commissionTypeSelect && percentageField && fixedAmountField) {
                if (commissionTypeSelect.value === 'percentage') {
                    percentageField.classList.add('active');
                    fixedAmountField.classList.remove('active');
                    // 固定額フィールドの値をクリア (フォーム送信時に誤って送られないように)
                    fixedAmountField.querySelector('input').value = '0.00';
                } else {
                    percentageField.classList.remove('active');
                    fixedAmountField.classList.add('active');
                    // パーセンテージフィールドの値をクリア
                    percentageField.querySelector('input').value = '0.00';
                }
            }
        }

        // 担当スタッフ選択の一括設定機能
        function setAllStaffToSame() {
            const transactionStaffSelect = document.getElementById('transaction_user_id');
            const itemStaffSelects = document.querySelectorAll('select[name^="item_staff"]');
            
            if (transactionStaffSelect && transactionStaffSelect.value) {
                itemStaffSelects.forEach(select => {
                    select.value = transactionStaffSelect.value;
                });
            }
        }

        // 売上計上スタッフが変更されたときに、各商品の担当スタッフも同じに設定するオプション
        document.addEventListener('DOMContentLoaded', function() {
            const transactionStaffSelect = document.getElementById('transaction_user_id');
            if (transactionStaffSelect) {
                // 一括設定ボタンを追加
                const bulkSetButton = document.createElement('button');
                bulkSetButton.type = 'button';
                bulkSetButton.className = 'btn btn-small';
                bulkSetButton.style.cssText = 'background: #6c757d; color: white; margin-top: 5px; font-size: 12px; padding: 5px 10px;';
                bulkSetButton.textContent = '全商品に適用';
                bulkSetButton.onclick = setAllStaffToSame;
                
                transactionStaffSelect.parentNode.appendChild(bulkSetButton);
            }
        });
    </script>
</body>
</html>