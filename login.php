<?php
// 共通設定ファイルを読み込み
include 'config.php';

// 既にログインしている場合はホームにリダイレクト
if (isLoggedIn()) {
    header('Location: index.php');
    exit();
}

$error_message = '';

// ログインフォームが送信された場合
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error_message = 'ユーザー名とパスワードを入力してください。';
    } else {
        try {
            // ユーザー名を元にデータベースからユーザー情報を取得
            $stmt = $pdo->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // ユーザーが存在し、パスワードが一致するか検証
            if ($user && password_verify($password, $user['password'])) {
                // ログイン成功
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_role'] = $user['role']; // ユーザーの役割をセッションに保存

                // セッション固定攻撃対策: ログイン成功時にセッションIDを再生成
                session_regenerate_id(true);

                $_SESSION['message'] = 'ログインしました。';
                header('Location: index.php'); // ログイン後、ホームにリダイレクト
                exit();
            } else {
                // ログイン失敗
                $error_message = 'ユーザー名またはパスワードが正しくありません。';
            }
        } catch (PDOException $e) {
            error_log("Login Error: " . $e->getMessage());
            $error_message = 'データベースエラーが発生しました。';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ログイン - 🏰 Cinderella cafe</title>
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
        .login-container {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            text-align: center;
            width: 100%;
            max-width: 400px;
            border: 1px solid #e0e0e0;
        }
        .login-container h1 {
            color: #00a499;
            margin-bottom: 25px;
            font-size: 2em;
            font-weight: 600;
        }
        .login-container .form-group {
            margin-bottom: 20px;
            text-align: left;
        }
        .login-container .form-group label {
            font-weight: 600;
            color: #555;
            margin-bottom: 8px;
            display: block;
            font-size: 14px;
        }
        .login-container .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
            transition: border-color 0.2s;
            background-color: #f9f9f9;
        }
        .login-container .form-group input:focus {
            outline: none;
            border-color: #00a499;
            background-color: #fff;
        }
        .login-container .btn {
            width: 100%;
            padding: 15px;
            font-size: 1.1em;
            margin-top: 15px;
            font-weight: 600;
        }
        .login-error {
            color: #b33939;
            margin-bottom: 15px;
            font-weight: 500;
            background-color: #fdecec;
            padding: 12px;
            border-radius: 4px;
            border: 1px solid #f2c7c7;
        }
        .test-accounts {
            margin-top: 20px;
            font-size: 0.9em;
            color: #666;
            background: #f9f9f9;
            padding: 15px;
            border-radius: 6px;
            border: 1px dashed #e0e0e0;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>🔑 ログイン</h1>
        <?php if ($error_message): ?>
            <div class="login-error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
        <?php showMessage(); // config.phpで定義されたメッセージ表示関数 ?>
        <form method="POST" action="login.php">
            <div class="form-group">
                <label for="username">ユーザー名:</label>
                <input type="text" id="username" name="username" required autocomplete="username">
            </div>
            <div class="form-group">
                <label for="password">パスワード:</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn">ログイン</button>
        </form>
        <p style="margin-top: 20px; font-size: 0.9em; color: #666;">
            アカウントをお持ちでないですか？ <a href="register.php" style="color: #00a499; text-decoration: none; font-weight: bold;">新規登録はこちら</a>
        </p>
        <div class="test-accounts">
            <p><strong>テスト用アカウント:</strong></p>
            <p>管理者: admin / password<br>
            スタッフ: staff / password</p>
        </div>
    </div>
</body>
</html>
