<?php
// 共通設定ファイルを読み込み
include 'config.php';

// セッションを破棄
$_SESSION = array(); // セッション変数を全て解除
if (isset($_COOKIE[session_name()])) { // セッションクッキーを削除
    setcookie(session_name(), '', time()-42000, '/');
}
session_destroy(); // セッションを破棄

$_SESSION['message'] = 'ログアウトしました。';
header('Location: index.php'); // ログアウト後、ホームにリダイレクト
exit();
?>
