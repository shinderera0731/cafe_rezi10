<?php
ini_set('display_errors', 1); // エラー表示を有効にする
error_reporting(E_ALL);     // 全てのエラーを表示する

// 共通設定ファイルを読み込み
include 'config.php';

$error_message = '';
$success_message = '';

// from_settingsパラメータがあるかチェック (GETとPOST両方を確認)
$redirect_to_settings_get = isset($_GET['from_settings']) && $_GET['from_settings'] === 'true';
$redirect_to_settings_post = isset($_POST['from_settings']) && $_POST['from_settings'] === 'true';
$from_settings = $redirect_to_settings_get || $redirect_to_settings_post;

// 既にログインしている場合の処理
if (isLoggedIn()) {
    // ログイン中のユーザーが管理者であり、かつ「設定」からのアクセスである場合のみ、登録を許可
    if ($from_settings && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
        // 管理者からのスタッフ登録なので、リダイレクトせずに処理を続行
    } else {
        // それ以外のログイン済みユーザーはホームにリダイレクト
        header('Location: index.php');
        exit();
    }
}

// 登録フォームが送信された場合
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    // from_settingsの値は既に$from_settings変数で処理済み

    if (empty($username) || empty($password) || empty($confirm_password)) {
        $error_message = '全ての項目を入力してください。';
    } elseif ($password !== $confirm_password) {
        $error_message = 'パスワードが一致しません。';
    } elseif (strlen($password) < 6) {
        $error_message = 'パスワードは6文字以上である必要があります。';
    } else {
        try {
            // ユーザー名の重複チェック
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error_message = 'このユーザー名は既に存在します。別のユーザー名をお試しください。';
            } else {
                // トランザクション開始
                $pdo->beginTransaction();

                // パスワードをハッシュ化してusersテーブルに保存
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt_user = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'staff')"); // デフォルトで'staff'ロール

                if ($stmt_user->execute([$username, $hashed_password])) {
                    // 新規登録されたユーザーIDを取得
                    $new_user_id = $pdo->lastInsertId();

                    // staff_details テーブルにも関連データを挿入
                    // INSERT IGNORE を使用して、もし何らかの理由でuser_idが既に存在してもエラーにならないようにする
                    $stmt_details = $pdo->prepare("INSERT IGNORE INTO staff_details (user_id) VALUES (?)");
                    $stmt_details->execute([$new_user_id]);

                    // staff_commissions テーブルにも関連データを挿入
                    $stmt_commission = $pdo->prepare("INSERT IGNORE INTO staff_commissions (user_id, commission_rate) VALUES (?, 0.00)");
                    $stmt_commission->execute([$new_user_id]);

                    // 全ての操作が成功したらコミット
                    $pdo->commit();

                    $success_message = 'アカウントが正常に登録されました。';
                    // 登録後、リダイレクト先を決定
                    if ($from_settings) { // 「設定」からのアクセスであればスタッフ管理タブにリダイレクト
                        $_SESSION['message'] = $success_message;
                        header('Location: select.php?tab=staff_management');
                        exit();
                    } else { // 通常の登録フローであればログインページにリダイレクト
                        $_SESSION['message'] = $success_message . 'ログインしてください。';
                        header('Location: login.php');
                        exit();
                    }
                } else {
                    // usersテーブルへの挿入が失敗した場合
                    $pdo->rollBack(); // ロールバック
                    $error_message = 'アカウント登録に失敗しました。';
                }
            }
        } catch (PDOException $e) {
            $pdo->rollBack(); // エラー発生時はロールバック
            error_log("Registration Error: " . $e->getMessage());
            $error_message = 'データベースエラーが発生しました。';
            // デバッグ用: エラーメッセージを画面に表示
            echo '<div class="register-error">PDO Exception: ' . htmlspecialchars($e->getMessage()) . '<br>SQLSTATE: ' . htmlspecialchars($e->getCode()) . '</div>';
            // var_dump($e); // より詳細な情報が必要な場合
        } catch (Exception $e) {
            $pdo->rollBack(); // その他のエラーもロールバック
            error_log("General Registration Error: " . $e->getMessage());
            $error_message = 'システムエラーが発生しました。';
            // デバッグ用: エラーメッセージを画面に表示
            echo '<div class="register-error">General Exception: ' . htmlspecialchars($e->getMessage()) . '</div>';
            // var_dump($e); // より詳細な情報が必要な場合
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>新規登録 - 🏰 Cinderella cafe</title>
    <?php echo getCommonCSS(); ?>
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background-color: #f4f5f7;
            padding: 0;
        }
        .register-container {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            text-align: center;
            width: 100%;
            max-width: 450px;
            border: 1px solid #e0e0e0;
        }
        .register-container h1 {
            color: #00a499;
            margin-bottom: 25px;
            font-size: 2em;
            font-weight: 600;
        }
        .register-container .form-group {
            margin-bottom: 20px;
            text-align: left;
        }
        .register-container .form-group label {
            font-weight: 600;
            color: #555;
            margin-bottom: 8px;
            display: block;
            font-size: 14px;
        }
        .register-container .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
            transition: border-color 0.2s;
            background-color: #f9f9f9;
        }
        .register-container .form-group input:focus {
            outline: none;
            border-color: #00a499;
            background-color: #fff;
        }
        .register-container .btn {
            width: 100%;
            padding: 15px;
            font-size: 1.1em;
            margin-top: 15px;
            font-weight: 600;
        }
        .register-error {
            color: #b33939;
            margin-bottom: 15px;
            font-weight: 500;
            background-color: #fdecec;
            padding: 12px;
            border-radius: 4px;
            border: 1px solid #f2c7c7;
        }
        .register-success {
            color: #1a6d2f;
            margin-bottom: 15px;
            font-weight: 500;
            background-color: #e6f7e9;
            padding: 12px;
            border-radius: 4px;
            border: 1px solid #b7e0c4;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <h1>📝 新規登録</h1>
        <?php if ($error_message): ?>
            <div class="register-error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="register-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        <form method="POST" action="register.php">
            <?php if ($from_settings): // from_settingsがtrueならhiddenフィールドを追加 ?>
                <input type="hidden" name="from_settings" value="true">
            <?php endif; ?>
            <div class="form-group">
                <label for="username">ユーザー名:</label>
                <input type="text" id="username" name="username" required autocomplete="username">
            </div>
            <div class="form-group">
                <label for="password">パスワード:</label>
                <input type="password" id="password" name="password" required autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="confirm_password">パスワード（確認）:</label>
                <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
            </div>
            <button type="submit" class="btn">登録</button>
        </form>
        <p style="margin-top: 20px; font-size: 0.9em; color: #666;">
            既にアカウントをお持ちですか？ <a href="login.php" style="color: #00a499; text-decoration: none; font-weight: bold;">ログインはこちら</a>
        </p>
    </div>
</body>
</html>
