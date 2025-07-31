<?php
// 共通設定ファイルを読み込み
include 'config.php';

// ログイン必須 (テーブル作成アクションを除く)
// create_tablesアクションはログインしていなくても実行できるようにする
if (!isset($_POST['action']) || $_POST['action'] !== 'create_tables') {
    requireLogin();
}

// POSTデータが送信されているかチェック
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = '不正なアクセスです。';
    // リダイレクト先を input.php?tab=pos に修正
    header('Location: input.php?tab=pos');
    exit;
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {

        // データベーステーブル作成
        case 'create_tables':
            if (createTables($pdo)) {
                $_SESSION['message'] = '✅ データベーステーブルが正常に作成されました。システムの準備が完了しました！';
            } else {
                $_SESSION['error'] = '❌ テーブル作成に失敗しました。';
            }
            header('Location: index.php');
            exit;

        // 新商品追加 (inventoryテーブル用)
        case 'add_item':
            // 入力値の検証
            $name = trim($_POST['name'] ?? '');
            $category_id = (int)($_POST['category_id'] ?? 0);
            $quantity = (int)($_POST['quantity'] ?? 0);
            $unit = trim($_POST['unit'] ?? '');
            $cost_price = (float)($_POST['cost_price'] ?? 0);
            $selling_price = (float)($_POST['selling_price'] ?? 0);
            // --- 変更点: commission_type と fixed_commission_amount を取得 ---
            $commission_type = $_POST['commission_type'] ?? 'percentage';
            $commission_rate = (float)($_POST['commission_rate'] ?? 0);
            $fixed_commission_amount = (float)($_POST['fixed_commission_amount'] ?? 0);
            // -----------------------------------------------------------
            $reorder_level = (int)($_POST['reorder_level'] ?? 10);
            $supplier = trim($_POST['supplier'] ?? '') ?: null;
            $expiry_date = $_POST['expiry_date'] ?: null;

            // 必須項目チェック
            if (empty($name) || $category_id <= 0 || empty($unit) || $cost_price < 0 || $selling_price < 0) {
                $_SESSION['error'] = '❌ 必須項目が入力されていないか、不正な値が含まれています。';
                header('Location: input.php?tab=inventory_ops'); // タブ指定を追加
                exit;
            }
            // 歩合率のバリデーション
            if ($commission_type === 'percentage' && ($commission_rate < 0 || $commission_rate > 100)) {
                $_SESSION['error'] = '❌ 歩合率は0から100の間の数値を入力してください。';
                header('Location: input.php?tab=inventory_ops');
                exit;
            }
            if ($commission_type === 'fixed_amount' && $fixed_commission_amount < 0) {
                $_SESSION['error'] = '❌ 固定額歩合は0以上の数値を入力してください。';
                header('Location: input.php?tab=inventory_ops');
                exit;
            }


            // 同名商品の重複チェック
            $stmt = $pdo->prepare("SELECT id FROM inventory WHERE name = ?");
            $stmt->execute([$name]);
            if ($stmt->fetch()) {
                $_SESSION['error'] = "❌ 商品「{$name}」は既に登録されています。";
                header('Location: input.php?tab=inventory_ops'); // タブ指定を追加
                exit;
            }

            // 商品追加 (commission_type と fixed_commission_amount を追加)
            $stmt = $pdo->prepare("INSERT INTO inventory (name, category_id, quantity, unit, cost_price, selling_price, commission_type, commission_rate, fixed_commission_amount, reorder_level, supplier, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $name,
                $category_id,
                $quantity,
                $unit,
                $cost_price,
                $selling_price,
                $commission_type,         // 新規
                $commission_rate,
                $fixed_commission_amount, // 新規
                $reorder_level,
                $supplier,
                $expiry_date
            ]);

            $item_id = $pdo->lastInsertId();

            // 初期在庫の履歴記録
            if ($quantity > 0) {
                $stmt = $pdo->prepare("INSERT INTO stock_movements (item_id, movement_type, quantity, reason, created_by) VALUES (?, '入庫', ?, '新商品登録', 'システム')");
                $stmt->execute([$item_id, $quantity]);
            }

            $_SESSION['message'] = "✅ 商品「{$name}」が正常に追加されました。初期在庫: {$quantity}{$unit}";
            header('Location: input.php?tab=inventory_ops'); // タブ指定を追加
            exit;

        // 在庫更新（入出庫処理）(inventoryテーブル用)
        case 'update_stock':
            $item_id = (int)($_POST['item_id'] ?? 0);
            $new_quantity = (int)($_POST['new_quantity'] ?? 0);
            $movement_type = $_POST['movement_type'] ?? '';
            $reason = trim($_POST['reason'] ?? '') ?: null;

            // 入力値の検証
            if ($item_id <= 0 || $new_quantity <= 0 || empty($movement_type)) {
                $_SESSION['error'] = '❌ 必須項目が入力されていないか、不正な値が含まれています。';
                header('Location: input.php?tab=inventory_ops'); // タブ指定を追加
                exit;
            }

            // 有効な処理種別かチェック
            $valid_types = ['入庫', '出庫', '廃棄', '調整'];
            if (!in_array($movement_type, $valid_types)) {
                $_SESSION['error'] = '❌ 無効な処理種別です。';
                header('Location: input.php?tab=inventory_ops'); // タブ指定を追加
                exit;
            }

            // 現在の在庫数取得
            $stmt = $pdo->prepare("SELECT name, quantity, unit FROM inventory WHERE id = ?");
            $stmt->execute([$item_id]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$current) {
                $_SESSION['error'] = '❌ 指定された商品が見つかりません。';
                header('Location: input.php?tab=inventory_ops'); // タブ指定を追加
                exit;
            }

            $old_quantity = $current['quantity'];
            $item_name = $current['name'];
            $unit = $current['unit'];

            // 新しい在庫数計算
            switch ($movement_type) {
                case '入庫':
                    $final_quantity = $old_quantity + $new_quantity;
                    $change_amount = $new_quantity;
                    break;

                case '出庫':
                case '廃棄':
                    if ($new_quantity > $old_quantity) {
                        $_SESSION['error'] = "❌ {$movement_type}数量（{$new_quantity}）が現在の在庫数（{$old_quantity}）を超えています。";
                        header('Location: input.php?tab=inventory_ops'); // タブ指定を追加
                        exit;
                    }
                    $final_quantity = $old_quantity - $new_quantity;
                    $change_amount = $new_quantity;
                    break;

                case '調整':
                    // 調整の場合は、入力値を最終在庫数として扱う
                    $final_quantity = $new_quantity;
                    $change_amount = abs($new_quantity - $old_quantity); // 履歴には増減量を記録
                    // 調整による入庫/出庫タイプを決定（履歴用）
                    $log_type_for_adjustment = ($new_quantity > $old_quantity) ? '入庫' : '出庫';
                    break;
            }

            // 在庫数更新
            $stmt = $pdo->prepare("UPDATE inventory SET quantity = ? WHERE id = ?");
            $stmt->execute([$final_quantity, $item_id]);

            // 履歴記録
            // 「調整」の場合のみ、履歴のmovement_typeを調整量に応じて「入庫」または「出庫」にする
            $log_movement_type = ($movement_type === '調整') ? $log_type_for_adjustment : $movement_type;
            $log_reason = $reason ?: ($movement_type === '調整' ? '棚卸調整' : $movement_type);

            $stmt = $pdo->prepare("INSERT INTO stock_movements (item_id, movement_type, quantity, reason, created_by) VALUES (?, ?, ?, ?, 'システム')");
            $stmt->execute([$item_id, $log_movement_type, $change_amount, $log_reason]);

            // 成功メッセージ
            $operation_desc = [
                '入庫' => '入庫しました',
                '出庫' => '出庫しました',
                '廃棄' => '廃棄しました',
                '調整' => '調整しました'
            ];

            $_SESSION['message'] = "✅ 「{$item_name}」を{$operation_desc[$movement_type]}。" .
                                 " 変更: {$old_quantity}{$unit} → {$final_quantity}{$unit}";

            // 在庫不足警告
            $stmt = $pdo->prepare("SELECT reorder_level FROM inventory WHERE id = ?");
            $stmt->execute([$item_id]);
            $reorder_level = $stmt->fetchColumn();

            if ($final_quantity <= $reorder_level) {
                $_SESSION['message'] .= " ⚠️ 発注点を下回りました！";
            }

            header('Location: input.php?tab=inventory_ops'); // タブ指定を追加
            exit;

        // 商品削除 (inventoryテーブル用)
        case 'delete_item':
            $item_id = (int)($_POST['item_id'] ?? 0);

            if ($item_id <= 0) {
                $_SESSION['error'] = '❌ 無効な商品IDです。';
                header('Location: select.php?tab=inventory'); // タブ指定を追加
                exit;
            }

            // 商品名を取得
            $stmt = $pdo->prepare("SELECT name FROM inventory WHERE id = ?");
            $stmt->execute([$item_id]);
            $item_name = $stmt->fetchColumn();

            if (!$item_name) {
                $_SESSION['error'] = '❌ 指定された商品が見つかりません。';
                header('Location: select.php?tab=inventory'); // タブ指定を追加
                exit;
            }

            $pdo->beginTransaction(); // トランザクション開始

            try {
                // 関連する transaction_items データも削除
                $stmt = $pdo->prepare("DELETE FROM transaction_items WHERE item_id = ?");
                $stmt->execute([$item_id]);

                // 関連する stock_movements データも削除
                $stmt = $pdo->prepare("DELETE FROM stock_movements WHERE item_id = ?");
                $stmt->execute([$item_id]);

                // 商品削除
                $stmt = $pdo->prepare("DELETE FROM inventory WHERE id = ?");
                $stmt->execute([$item_id]);

                $pdo->commit(); // コミット
                $_SESSION['message'] = "✅ 商品「{$item_name}」とその関連データを削除しました。";
            } catch (PDOException $e) {
                $pdo->rollBack(); // ロールバック
                $_SESSION['error'] = '❌ 商品の削除中にデータベースエラーが発生しました。' . $e->getMessage();
            }

            header('Location: select.php?tab=inventory'); // タブ指定を追加
            exit;

        // 商品更新 (inventoryテーブル用)
        case 'update_item':
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $category_id = (int)($_POST['category_id'] ?? 0);
            $quantity = (int)($_POST['quantity'] ?? 0); // quantityはstockに相当
            $unit = trim($_POST['unit'] ?? '');
            $cost_price = (float)($_POST['cost_price'] ?? 0);
            $selling_price = (float)($_POST['selling_price'] ?? 0);
            // --- 変更点: commission_type と fixed_commission_amount を取得 ---
            $commission_type = $_POST['commission_type'] ?? 'percentage';
            $commission_rate = (float)($_POST['commission_rate'] ?? 0);
            $fixed_commission_amount = (float)($_POST['fixed_commission_amount'] ?? 0);
            // -----------------------------------------------------------
            $reorder_level = (int)($_POST['reorder_level'] ?? 10);
            $supplier = trim($_POST['supplier'] ?? '') ?: null;
            $expiry_date = $_POST['expiry_date'] ?: null;

            if ($id <= 0 || empty($name) || $category_id <= 0 || empty($unit) || $cost_price < 0 || $selling_price < 0 || $quantity < 0) {
                $_SESSION['error'] = '❌ 必須項目が入力されていないか、不正な値が含まれています。';
                // 編集モードに戻るためのリダイレクトURLを構築
                header('Location: select.php?tab=inventory'); // select.phpの在庫一覧タブに戻す
                exit;
            }
            // 歩合率のバリデーション
            if ($commission_type === 'percentage' && ($commission_rate < 0 || $commission_rate > 100)) {
                $_SESSION['error'] = '❌ 歩合率は0から100の間の数値を入力してください。';
                header('Location: select.php?tab=inventory');
                exit;
            }
            if ($commission_type === 'fixed_amount' && $fixed_commission_amount < 0) {
                $_SESSION['error'] = '❌ 固定額歩合は0以上の数値を入力してください。';
                header('Location: select.php?tab=inventory');
                exit;
            }


            // 同名商品の重複チェック (自身を除く)
            $stmt = $pdo->prepare("SELECT id FROM inventory WHERE name = ? AND id != ?");
            $stmt->execute([$name, $id]);
            if ($stmt->fetch()) {
                $_SESSION['error'] = "❌ 商品「{$name}」は既に登録されています。別の商品名を使用してください。";
                // 編集モードに戻るためのリダイレクトURLを構築
                header('Location: select.php?tab=inventory'); // select.phpの在庫一覧タブに戻す
                exit;
            }

            // 商品更新 (commission_type と fixed_commission_amount を追加)
            $stmt = $pdo->prepare("UPDATE inventory SET name = ?, category_id = ?, quantity = ?, unit = ?, cost_price = ?, selling_price = ?, commission_type = ?, commission_rate = ?, fixed_commission_amount = ?, reorder_level = ?, supplier = ?, expiry_date = ? WHERE id = ?");
            $stmt->execute([
                $name,
                $category_id,
                $quantity,
                $unit,
                $cost_price,
                $selling_price,
                $commission_type,         // 新規
                $commission_rate,
                $fixed_commission_amount, // 新規
                $reorder_level,
                $supplier,
                $expiry_date,
                $id
            ]);

            $_SESSION['message'] = "✅ 商品「{$name}」が正常に更新されました。";
            header('Location: select.php?tab=inventory'); // 在庫一覧に戻る
            exit;

        // レジ会計処理 (transactionsテーブルとinventoryテーブル用)
        case 'checkout':
            // セッションからカート情報を取得
            if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
                $_SESSION['error'] = '❌ カートが空です。';
                header('Location: input.php?tab=pos'); // タブ指定を追加
                exit;
            }

            // 税率の読み込み
            $tax_rate = (float)getSetting($pdo, 'tax_rate', 10); // config.phpのgetSetting関数を使用

            $subtotal_amount = 0;
            $total_commission_amount = 0; // 新規追加: この取引の総歩合額
            foreach ($_SESSION['cart'] as $item) {
                $subtotal_amount += $item['price'] * $item['quantity'];
                // --- 変更点: 歩合タイプに応じて歩合を計算 ---
                if ($item['commission_type'] === 'percentage') {
                    $total_commission_amount += ($item['price'] * $item['quantity']) * ($item['commission_rate'] / 100);
                } elseif ($item['commission_type'] === 'fixed_amount') {
                    $total_commission_amount += $item['fixed_commission_amount'] * $item['quantity'];
                }
                // -----------------------------------------------------------
            }

            $tax_amount = $subtotal_amount * ($tax_rate / 100);
            $total_amount = $subtotal_amount + $tax_amount;

            $cash_received = (float)($_POST['cash_received'] ?? 0);
            $transaction_user_id = (int)($_POST['transaction_user_id'] ?? 0); // 追加: 選択されたスタッフID

            if ($cash_received < $total_amount) {
                $_SESSION['error'] = '❌ 受取金額が合計金額より少ないです。';
                header('Location: input.php?tab=pos'); // タブ指定を追加
                exit;
            }

            // 選択されたスタッフIDの検証
            if ($transaction_user_id <= 0) {
                $_SESSION['error'] = '❌ 売上計上スタッフが正しく選択されていません。';
                header('Location: input.php?tab=pos'); // タブ指定を追加
                exit;
            }

            // --- 新規追加: 商品ごとの担当スタッフの検証 ---
            $item_staff_assignments = $_POST['item_staff'] ?? [];
            foreach ($_SESSION['cart'] as $item_id => $item) {
                if (!isset($item_staff_assignments[$item_id]) || (int)$item_staff_assignments[$item_id] <= 0) {
                    $_SESSION['error'] = '❌ 商品「' . htmlspecialchars($item['name']) . '」の担当スタッフが選択されていません。';
                    header('Location: input.php?tab=pos');
                    exit;
                }
            }
            // --------------------------------------------------------

            $pdo->beginTransaction(); // トランザクション開始
            $stock_update_success = true;

            // inventoryテーブルの在庫を減らす
            foreach ($_SESSION['cart'] as $item) {
                // ここで再度在庫チェックを行い、レースコンディションを防ぐ
                $stmt_check_stock = $pdo->prepare("SELECT quantity, name FROM inventory WHERE id = ? FOR UPDATE"); // FOR UPDATEでロック
                $stmt_check_stock->execute([$item['id']]);
                $current_stock_data = $stmt_check_stock->fetch(PDO::FETCH_ASSOC);

                if (!$current_stock_data || $current_stock_data['quantity'] < $item['quantity']) {
                    $_SESSION['error'] = '❌ 「' . htmlspecialchars($current_stock_data['name'] ?? $item['name']) . '」の在庫が不足しています。会計を中断しました。';
                    $stock_update_success = false;
                    break;
                }

                $stmt_update_stock = $pdo->prepare("UPDATE inventory SET quantity = quantity - ? WHERE id = ?");
                $stmt_update_stock->execute([$item['quantity'], $item['id']]);

                // 在庫移動履歴を記録
                $stmt = $pdo->prepare("INSERT INTO stock_movements (item_id, movement_type, quantity, reason, created_by) VALUES (?, '出庫', ?, 'レジ販売', 'POS')");
                $stmt->execute([$item['id'], $item['quantity']]);
            }

            if ($stock_update_success) {
                $change_given = $cash_received - $total_amount;

                // transactionsテーブルに記録 (user_id と total_commission_amount を追加)
                $stmt_insert_transaction = $pdo->prepare("INSERT INTO transactions (total_amount, cash_received, change_given, user_id, total_commission_amount) VALUES (?, ?, ?, ?, ?)");
                $stmt_insert_transaction->execute([$total_amount, $cash_received, $change_given, $transaction_user_id, $total_commission_amount]);
                $transaction_id = $pdo->lastInsertId(); // 挿入された取引IDを取得

                // transaction_items テーブルに各商品詳細を記録
                // --- 変更点: assigned_staff_id を追加 ---
                $stmt_insert_transaction_item = $pdo->prepare("INSERT INTO transaction_items (transaction_id, item_id, item_name, item_price, quantity, item_commission_type, item_commission_rate, item_fixed_commission_amount, assigned_staff_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($_SESSION['cart'] as $item_id => $item) {
                    $assigned_staff_id = (int)$item_staff_assignments[$item_id];
                    $stmt_insert_transaction_item->execute([
                        $transaction_id,
                        $item['id'],
                        $item['name'],
                        $item['price'],
                        $item['quantity'],
                        $item['commission_type'],         // 既存
                        $item['commission_rate'],         // 既存
                        $item['fixed_commission_amount'], // 既存
                        $assigned_staff_id                // 新規追加: 担当スタッフID
                    ]);
                }
                // -----------------------------------------------------------

                $pdo->commit(); // 全ての操作が成功したらコミット
                $_SESSION['message'] = "✅ 会計が完了しました！お釣り: ¥" . number_format($change_given, 0);

                // 会計後の在庫アラートチェック (inventoryテーブル)
                $low_stock_threshold = (int)getSetting($pdo, 'low_stock_threshold', 5); // config.phpのgetSetting関数を使用

                // カート内の各商品について在庫アラートをチェック
                foreach ($_SESSION['cart'] as $item) {
                    $stmt_check_stock = $pdo->prepare("SELECT name, quantity FROM inventory WHERE id = ?");
                    $stmt_check_stock->execute([$item['id']]);
                    $current_stock_data = $stmt_check_stock->fetch(PDO::FETCH_ASSOC);

                    if ($current_stock_data && $current_stock_data['quantity'] <= $low_stock_threshold) {
                        // 既存のメッセージに追加する形に変更
                        if (!isset($_SESSION['warning'])) {
                            $_SESSION['warning'] = '';
                        }
                        $_SESSION['warning'] .= "<br>⚠️ **在庫アラート:** " . htmlspecialchars($current_stock_data['name']) . " の在庫が残り " . htmlspecialchars($current_stock_data['quantity']) . " 個です。閾値: " . htmlspecialchars($low_stock_threshold) . "個";
                    }
                }
                $_SESSION['cart'] = []; // カートをクリア

            } else {
                $pdo->rollBack(); // 失敗したらロールバック
                // 在庫不足のエラーメッセージは既に設定されているので、ここでは追加しない
            }
            // リダイレクト先を input.php?tab=pos に修正
            header('Location: input.php?tab=pos');
            exit;

        // 釣銭準備金の設定/更新 (daily_settlementテーブル用)
        case 'set_cash_float':
            $new_cash_float = (float)($_POST['initial_cash_float'] ?? 0);
            $settlement_date = date('Y-m-d');

            if ($new_cash_float < 0) {
                $_SESSION['error'] = '❌ 釣銭準備金は0以上で入力してください。';
                header('Location: select.php?tab=settlement'); // タブ指定を追加
                exit;
            }

            $pdo->beginTransaction(); // トランザクション開始
            try {
                // 今日の売上合計を取得
                $stmt_sales = $pdo->query("SELECT SUM(total_amount) FROM transactions WHERE DATE(transaction_date) = CURDATE()");
                $total_sales_cash = $stmt_sales->fetchColumn() ?? 0;
                $expected_cash_on_hand = $new_cash_float + $total_sales_cash;

                // 既存レコードの確認と更新/挿入
                $stmt_check = $pdo->prepare("SELECT id FROM daily_settlement WHERE settlement_date = ?");
                $stmt_check->execute([$settlement_date]);
                $existing_settlement = $stmt_check->fetch(PDO::FETCH_ASSOC);

                if ($existing_settlement) {
                    // 更新
                    $stmt_update = $pdo->prepare("UPDATE daily_settlement SET initial_cash_float = ?, total_sales_cash = ?, expected_cash_on_hand = ? WHERE settlement_date = ?");
                    $stmt_update->execute([$new_cash_float, $total_sales_cash, $expected_cash_on_hand, $settlement_date]);
                } else {
                    // 挿入
                    $stmt_insert = $pdo->prepare("INSERT INTO daily_settlement (settlement_date, initial_cash_float, total_sales_cash, expected_cash_on_hand) VALUES (?, ?, ?, ?)");
                    $stmt_insert->execute([$settlement_date, $new_cash_float, $total_sales_cash, $expected_cash_on_hand]);
                }
                $pdo->commit(); // コミット

                $_SESSION['message'] = '✅ 釣銭準備金が正常に設定されました。';
            } catch (PDOException $e) {
                $pdo->rollBack(); // ロールバック
                $_SESSION['error'] = '❌ 釣銭準備金の設定中にデータベースエラーが発生しました: ' . $e->getMessage();
            }
            header('Location: select.php?tab=settlement'); // タブ指定を追加
            exit;

        // 精算処理 (daily_settlementテーブル用)
        case 'settle_up':
            $actual_cash_on_hand = (float)($_POST['actual_cash_on_hand'] ?? 0);
            $settlement_date = date('Y-m-d');

            // 今日の精算データを取得
            $stmt_data = $pdo->prepare("SELECT initial_cash_float, total_sales_cash FROM daily_settlement WHERE settlement_date = ? FOR UPDATE"); // FOR UPDATEでロック
            $stmt_data->execute([$settlement_date]);
            $daily_data = $stmt_data->fetch(PDO::FETCH_ASSOC);

            if (!$daily_data) {
                $_SESSION['error'] = '❌ 今日の釣銭準備金が設定されていません。先に設定してください。';
                header('Location: select.php?tab=settlement'); // タブ指定を追加
                exit;
            }

            $expected_cash_on_hand = $daily_data['initial_cash_float'] + $daily_data['total_sales_cash'];
            $discrepancy = $actual_cash_on_hand - $expected_cash_on_hand;

            $pdo->beginTransaction(); // トランザクション開始
            try {
                $stmt_update = $pdo->prepare("UPDATE daily_settlement SET actual_cash_on_hand = ?, discrepancy = ? WHERE settlement_date = ?");
                $stmt_update->execute([$actual_cash_on_hand, $discrepancy, $settlement_date]);
                $pdo->commit(); // コミット

                $_SESSION['message'] = '✅ 精算が完了しました！差異: ¥' . number_format($discrepancy, 0);
                if (abs($discrepancy) > 0) {
                    $_SESSION['warning'] = '⚠️ 精算に差異があります。確認してください。';
                }
            } catch (PDOException $e) {
                $pdo->rollBack(); // ロールバック
                $_SESSION['error'] = '❌ 精算中にデータベースエラーが発生しました: ' . $e->getMessage();
            }
            header('Location: select.php?tab=settlement'); // タブ指定を追加
            exit;

        // アプリケーション設定保存 (app_settingsテーブル用)
        case 'save_app_settings':
            $has_error = false;
            $has_message = false;

            if (isset($_POST['tax_rate'])) {
                $new_tax_rate = (float)($_POST['tax_rate'] ?? 10);
                if ($new_tax_rate >= 0 && $new_tax_rate <= 100) {
                    $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('tax_rate', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$new_tax_rate, $new_tax_rate]);
                    $_SESSION['message'] = '✅ 税率が正常に保存されました。';
                    $has_message = true;
                } else {
                    $_SESSION['error'] = '❌ 税率は0から100の間の数値を入力してください。';
                    $has_error = true;
                }
            }
            if (isset($_POST['low_stock_threshold'])) {
                $new_low_stock_threshold = (int)($_POST['low_stock_threshold'] ?? 5);
                if ($new_low_stock_threshold >= 0) {
                    $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('low_stock_threshold', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$new_low_stock_threshold, $new_low_stock_threshold]);
                    if (!$has_message) { // 税率メッセージがなければ
                        $_SESSION['message'] = '✅ 低在庫アラート閾値が正常に保存されました。';
                    } else if (!$has_error) { // 税率メッセージがあり、かつエラーがなければ追加メッセージ
                         $_SESSION['message'] .= '<br>✅ 低在庫アラート閾値も正常に保存されました。';
                    }
                    $has_message = true;
                } else {
                    if (!$has_error) { // 税率エラーがなければ
                        $_SESSION['error'] = '❌ 低在庫アラート閾値も不正な値です。';
                    } else { // 税率エラーもあれば追加
                        $_SESSION['error'] .= '<br>❌ 低在庫アラート閾値も不正な値です。';
                    }
                    $has_error = true;
                }
            }
            header('Location: select.php?tab=settings'); // 設定タブにリダイレクト
            exit;

        // スタッフ歩合率の更新 (select.phpのスタッフ管理タブから呼び出される)
        case 'update_staff_commissions':
            // 管理者ロールのチェック
            if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
                $_SESSION['error'] = '❌ この操作を行う権限がありません。';
                header('Location: select.php?tab=staff_management'); // リダイレクト先をスタッフ管理タブに変更
                exit;
            }

            $has_error = false;
            $updated_count = 0;

            // 特定のユーザーIDが送信された場合のみ処理
            if (isset($_POST['user_id_to_update']) && isset($_POST['commission_rate'][$_POST['user_id_to_update']])) {
                $user_id = (int)$_POST['user_id_to_update'];
                $rate = $_POST['commission_rate'][$user_id];
                $commission_rate = (float)$rate;

                if ($user_id <= 0 || $commission_rate < 0 || $commission_rate > 100) {
                    $_SESSION['error'] = '❌ 無効なスタッフIDまたは歩合率です。歩合率は0から100の間で設定してください。';
                    $has_error = true;
                } else {
                    // staff_commissionsテーブルに挿入または更新
                    $stmt = $pdo->prepare("INSERT INTO staff_commissions (user_id, commission_rate) VALUES (?, ?) ON DUPLICATE KEY UPDATE commission_rate = ?");
                    $stmt->execute([$user_id, $commission_rate, $commission_rate]);
                    $updated_count++;
                }
            } else {
                $_SESSION['warning'] = "⚠️ 更新する歩合率の変更がありませんでした。";
            }

            if (!$has_error) {
                if ($updated_count > 0) {
                    $_SESSION['message'] = "✅ スタッフの歩合率を正常に更新しました。";
                } else {
                    // 警告メッセージは既に上で設定されている
                }
            }
            header('Location: select.php?tab=staff_management'); // リダイレクト先をスタッフ管理タブに変更
            exit;

        // スタッフ情報の更新 (新規追加)
        case 'update_staff_details':
            // 管理者ロールのチェック
            if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
                $_SESSION['error'] = '❌ この操作を行う権限がありません。';
                header('Location: select.php?tab=staff_management');
                exit;
            }

            $user_id = (int)($_POST['user_id'] ?? 0);
            $username = trim($_POST['username'] ?? '');
            $role = $_POST['role'] ?? 'staff';
            $employee_id = trim($_POST['employee_id'] ?? '') ?: null;
            $hire_date = $_POST['hire_date'] ?: null;
            $phone_number = trim($_POST['phone_number'] ?? '') ?: null;
            $address = trim($_POST['address'] ?? '') ?: null;
            $emergency_contact = trim($_POST['emergency_contact'] ?? '') ?: null;
            $commission_rate = (float)($_POST['commission_rate'] ?? 0);

            if ($user_id <= 0 || empty($username) || ($role !== 'admin' && $role !== 'staff') || $commission_rate < 0 || $commission_rate > 100) {
                $_SESSION['error'] = '❌ 無効な入力値が含まれています。';
                header('Location: select.php?tab=staff_management');
                exit;
            }

            try {
                $pdo->beginTransaction();

                // usersテーブルの更新
                $stmt_user = $pdo->prepare("UPDATE users SET username = ?, role = ? WHERE id = ?");
                $stmt_user->execute([$username, $role, $user_id]);

                // staff_detailsテーブルの更新 (存在しない場合は挿入)
                $stmt_details_check = $pdo->prepare("SELECT id FROM staff_details WHERE user_id = ?");
                $stmt_details_check->execute([$user_id]);
                if ($stmt_details_check->fetch()) {
                    $stmt_details = $pdo->prepare("UPDATE staff_details SET employee_id = ?, hire_date = ?, phone_number = ?, address = ?, emergency_contact = ? WHERE user_id = ?");
                    $stmt_details->execute([$employee_id, $hire_date, $phone_number, $address, $emergency_contact, $user_id]);
                } else {
                    $stmt_details = $pdo->prepare("INSERT INTO staff_details (user_id, employee_id, hire_date, phone_number, address, emergency_contact) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt_details->execute([$user_id, $employee_id, $hire_date, $phone_number, $address, $emergency_contact]);
                }

                // staff_commissionsテーブルの更新 (存在しない場合は挿入)
                $stmt_commission = $pdo->prepare("INSERT INTO staff_commissions (user_id, commission_rate) VALUES (?, ?) ON DUPLICATE KEY UPDATE commission_rate = ?");
                $stmt_commission->execute([$user_id, $commission_rate, $commission_rate]);

                $pdo->commit();
                $_SESSION['message'] = "✅ スタッフ「{$username}」の情報を正常に更新しました。";

            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Staff Update Error: " . $e->getMessage());
                $_SESSION['error'] = '❌ スタッフ情報の更新中にデータベースエラーが発生しました。' . $e->getMessage();
            }
            header('Location: select.php?tab=staff_management');
            exit;

        // スタッフの削除 (新規追加)
        case 'delete_staff':
            // 管理者ロールのチェック
            if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
                $_SESSION['error'] = '❌ この操作を行う権限がありません。';
                header('Location: select.php?tab=staff_management');
                exit;
            }

            $user_id = (int)($_POST['user_id'] ?? 0);

            if ($user_id <= 0) {
                $_SESSION['error'] = '❌ 無効なユーザーIDです。';
                header('Location: select.php?tab=staff_management');
                exit;
            }

            // 削除対象が自分自身ではないかチェック
            if ($user_id === $_SESSION['user_id']) {
                $_SESSION['error'] = '❌ ログイン中のアカウントは削除できません。';
                header('Location: select.php?tab=staff_management');
                exit;
            }

            try {
                $pdo->beginTransaction();

                // ユーザー名を取得（メッセージ表示用）
                $stmt_username = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                $stmt_username->execute([$user_id]);
                $username_to_delete = $stmt_username->fetchColumn();

                if (!$username_to_delete) {
                    $_SESSION['error'] = '❌ 指定されたユーザーが見つかりません。';
                    header('Location: select.php?tab=staff_management');
                    exit;
                }

                // usersテーブルから削除 (CASCADEによりstaff_details, staff_commissionsも削除される)
                $stmt_delete = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt_delete->execute([$user_id]);

                $pdo->commit();
                $_SESSION['message'] = "✅ ユーザー「{$username_to_delete}」を正常に削除しました。";

            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Staff Deletion Error: " . $e->getMessage());
                $_SESSION['error'] = '❌ スタッフの削除中にデータベースエラーが発生しました。' . $e->getMessage();
            }
            header('Location: select.php?tab=staff_management');
            exit;

// データベースリセット処理
        case 'reset_database':
            // 管理者権限のチェック
            if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
                $_SESSION['error'] = '❌ データベースのリセットは管理者のみ実行できます。';
                header('Location: select.php?tab=settings');
                exit;
            }

            // 確認キーのチェック
            $confirmation_key = $_POST['confirmation_key'] ?? '';
            if ($confirmation_key !== 'RESET_DATABASE') {
                $_SESSION['error'] = '❌ 確認キーが正しくありません。「RESET_DATABASE」と正確に入力してください。';
                header('Location: select.php?tab=settings');
                exit;
            }

            try {
                // 既存のcreateTablesを使用してデータベースをリセット
                if (createTables($pdo)) {
                    $_SESSION['message'] = '✅ データベースが正常にリセットされました。全てのデータが初期化され、デフォルトアカウント（admin/password, staff/password）が再作成されています。';
                } else {
                    $_SESSION['error'] = '❌ データベースのリセットに失敗しました。';
                }
            } catch (Exception $e) {
                error_log("Database Reset Error: " . $e->getMessage());
                $_SESSION['error'] = '❌ データベースのリセット中にエラーが発生しました: ' . $e->getMessage();
            }

            header('Location: select.php?tab=settings');
            exit;
        default:
            $_SESSION['error'] = '❌ 無効な操作です。';
            header('Location: index.php');
            exit;
    }

} catch (PDOException $e) {
    // データベースエラー
    error_log("Database Error: " . $e->getMessage());
    $_SESSION['error'] = '❌ データベースエラーが発生しました。しばらく待ってから再度お試しください。' . $e->getMessage();

    // エラーの種類に応じてリダイレクト先を決定
    if (in_array($action, ['add_item', 'update_stock'])) {
        header('Location: input.php?tab=inventory_ops');
    } elseif ($action === 'checkout') {
        header('Location: input.php?tab=pos');
    } elseif (in_array($action, ['update_item', 'delete_item'])) {
        header('Location: select.php?tab=inventory');
    } elseif (in_array($action, ['set_cash_float', 'settle_up'])) {
        header('Location: select.php?tab=settlement');
    } elseif ($action === 'save_app_settings') {
        header('Location: select.php?tab=settings');
    } elseif (in_array($action, ['update_staff_commissions', 'update_staff_details', 'delete_staff'])) { // 新しいアクションのリダイレクト先
        header('Location: select.php?tab=staff_management');
    } else {
        header('Location: index.php');
    }
    exit;

} catch (Exception $e) {
    // その他のエラー
    error_log("General Error: " . $e->getMessage());
    $_SESSION['error'] = '❌ システムエラーが発生しました。管理者にお問い合わせください。';
    header('Location: index.php');
    exit;
}
?>