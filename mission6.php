<?php
session_start();

// --- 1. データベース接続設定 ---
$dsn = 'mysql:dbname=データベース名;host=localhost;charset=utf8';
$user = 'ユーザー名';
$password_db = 'パスワード';

try {
    $pdo = new PDO($dsn, $user, $password_db, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    exit('データベース接続失敗: ' . $e->getMessage());
}

// --- 2. 自動テーブル作成（2テーブル） ---
// ① ユーザー管理テーブル
$pdo->query("CREATE TABLE IF NOT EXISTS cosme_users_1 (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL
);");

// ② コスメ管理テーブル
$pdo->query("CREATE TABLE IF NOT EXISTS my_cosmetics_1 (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    status VARCHAR(10) NOT NULL,
    item_name VARCHAR(64) NOT NULL,
    color_number VARCHAR(64),
    price INT,
    rating INT,
    memo TEXT,
    image_name VARCHAR(255), 
    post_date DATETIME NOT NULL
);");


// --- 3. 変数の初期化とモード切り替え制御 ---
$message = '';
$error = '';
// 現在の画面表示モード (main / login / register)
// ログインしてなければデフォルトは login 画面
$mode = isset($_SESSION['user_id']) ? 'main' : 'login'; 

// 画面のリンクからモードを切り替える用 (GET)
if (isset($_GET['view'])) {
    if ($_GET['view'] === 'register' && !isset($_SESSION['user_id'])) {
        $mode = 'register';
    } elseif ($_GET['view'] === 'login' && !isset($_SESSION['user_id'])) {
        $mode = 'login';
    }
}


// --- 4. POST（ボタンが押されたとき）の処理 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 【A. ユーザー登録処理】
    if ($action === 'do_register') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!empty($username) && !empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            try {
                $stmt = $pdo->prepare("INSERT INTO cosme_users_1 (username, password, created_at) VALUES (:username, :password, NOW())");
                $stmt->execute([':username' => $username, ':password' => $hashed_password]);
                $message = "ユーザー登録が完了しました！ログインしてください。";
                $mode = 'login'; // ログイン画面へ戻す
            } catch (PDOException $e) {
                $error = "そのユーザー名は既に使われています。";
                $mode = 'register';
            }
        } else {
            $error = "すべての項目を入力してください。";
            $mode = 'register';
        }
    }

    // 【B. ログイン処理】
    if ($action === 'do_login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!empty($username) && !empty($password)) {
            $stmt = $pdo->prepare("SELECT * FROM cosme_users_1 WHERE username = :username");
            $stmt->execute([':username' => $username]);
            $user_data = $stmt->fetch();

            if ($user_data && password_verify($password, $user_data['password'])) {
                $_SESSION['user_id'] = $user_data['id'];
                $_SESSION['username'] = $user_data['username'];
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            } else {
                $error = "ユーザー名またはパスワードが間違っています。";
                $mode = 'login';
            }
        } else {
            $error = "すべての項目を入力してください。";
            $mode = 'login';
        }
    }

    // 【C. コスメ新規登録（ログイン必須）】
    if ($action === 'register_cosme' && isset($_SESSION['user_id'])) {
        $status = $_POST['status'];
        $item_name = $_POST['item_name'];
        $color_number = $_POST['color_number'];
        $price = !empty($_POST['price']) ? (int)$_POST['price'] : null;
        $rating = !empty($_POST['rating']) ? (int)$_POST['rating'] : null;
        $memo = $_POST['memo'];
        
        // 画像アップロード
        $image_name = null;
        if (!empty($_FILES['image']['name'])) {
            $upload_dir = 'images/';
            $image_name = time() . '_' . basename($_FILES['image']['name']);
            move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $image_name);
        }
        
        $sql = "INSERT INTO my_cosmetics_1 (user_id, status, item_name, color_number, price, rating, memo, image_name, post_date) 
                VALUES (:user_id, :status, :item_name, :color_number, :price, :rating, :memo, :image_name, NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':status' => $status,
            ':item_name' => $item_name,
            ':color_number' => $color_number,
            ':price' => $price,
            ':rating' => $rating,
            ':memo' => $memo,
            ':image_name' => $image_name
        ]);
        
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // 【D. 購入した！ボタン（ステータス更新）】
    if ($action === 'buy_cosme' && isset($_SESSION['user_id'])) {
        $id = (int)$_POST['id'];
        
        $sql = "UPDATE my_cosmetics_1 SET status = 'owned' WHERE id = :id AND user_id = :user_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $id, ':user_id' => $_SESSION['user_id']]);
        
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// --- 5. ログアウト処理 (GET) ---
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// --- 6. メイン画面用データの取得 ---
$owned_list = [];
$wish_list = [];
if ($mode === 'main') {
    $stmt = $pdo->prepare("SELECT * FROM my_cosmetics_1 WHERE status = 'owned' AND user_id = :user_id ORDER BY post_date DESC");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $owned_list = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT * FROM my_cosmetics_1 WHERE status = 'wish' AND user_id = :user_id ORDER BY post_date DESC");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $wish_list = $stmt->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>Cosme-Memory & WishList</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #faf0f4; color: #333; margin: 20px; }
        h1 { color: #d81b60; text-align: center; margin-bottom: 20px; }
        h2 { border-left: 5px solid #d81b60; padding-left: 10px; color: #c2185b; }
        .auth-box { background: #fff; max-width: 400px; margin: 50px auto; padding: 30px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); text-align: center; }
        .auth-box input[type="text"], .auth-box input[type="password"] { width: 90%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; }
        .btn-submit { background-color: #d81b60; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; width: 95%; }
        .header-container { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #d81b60; padding-bottom: 10px; margin-bottom: 20px; }
        .form-box { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 30px; }
        .flex-container { display: flex; gap: 20px; }
        .column { flex: 1; background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .cosme-item { border-bottom: 1px solid #eee; padding: 15px 0; }
        .cosme-item:last-child { border-bottom: none; }
        .tag-owned { background: #e91e63; color: white; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
        .tag-wish { background: #009688; color: white; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
        .btn-buy { background-color: #ff9800; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; }
        .memo-text { font-size: 13px; color: #666; white-space: pre-wrap; background: #f9f9f9; padding: 5px; border-radius: 4px; margin-top: 5px; }
        .cosme-img { max-width: 150px; max-height: 150px; display: block; margin-top: 8px; border-radius: 4px; border: 1px solid #ddd; }
        .logout-btn { background-color: #666; color: white; text-decoration: none; padding: 5px 10px; border-radius: 4px; font-size: 14px; }
        .error { color: red; font-weight: bold; } .msg { color: green; font-weight: bold; }
    </style>
</head>
<body>

    <div style="text-align: center;">
        <?php if ($error): ?><p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if ($message): ?><p class="msg"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    </div>

    <?php if ($mode === 'login'): ?>
        <div class="auth-box">
            <h1>💄 コスメ管理 ログイン</h1>
            <form action="" method="POST">
                <input type="hidden" name="action" value="do_login">
                <p><input type="text" name="username" placeholder="ユーザー名" required></p>
                <p><input type="password" name="password" placeholder="パスワード" required></p>
                <p><input type="submit" class="btn-submit" value="ログイン"></p>
            </form>
            <p><a href="?view=register" style="color: #c2185b;">新規登録</a></p>
        </div>

    <?php elseif ($mode === 'register'): ?>
        <div class="auth-box">
            <h1>新規登録</h1>
            <form action="" method="POST">
                <input type="hidden" name="action" value="do_register">
                <p><input type="text" name="username" placeholder="使いたいユーザー名" required></p>
                <p><input type="password" name="password" placeholder="パスワード" required></p>
                <p><input type="submit" class="btn-submit" value="アカウントを作成する"></p>
            </form>
            <p><a href="?view=login" style="color: #c2185b;">ログイン画面に戻る</a></p>
        </div>

    <?php elseif ($mode === 'main'): ?>
        <div class="header-container">
            <h1>💄コスメ帳</h1>
            <div>
                <span>こんにちは、<strong><?= htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') ?></strong> さん </span>
                <a href="?action=logout" class="logout-btn">ログアウト</a>
            </div>
        </div>

        <div class="form-box">
            <h2>✨ コスメを登録する</h2>
            <form action="" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="register_cosme">
                
                <p>
                    <label><input type="radio" name="status" value="owned" checked> 持っている</label>
                    <label><input type="radio" name="status" value="wish"> 欲しいものリスト</label>
                </p>
                <p>商品名: <input type="text" name="item_name" required></p>
                <p>色番・カラー: <input type="text" name="color_number"></p>
                <p>価格(円): <input type="number" name="price"></p>
                <p>評価 (1〜5): <input type="number" name="rating" min="1" max="5"></p>
                <p>コスメの写真・画像: <input type="file" name="image" accept="image/*"></p>
                <p>メモ・感想・理由:<br>
                   <textarea name="memo" rows="4" cols="50" placeholder="使い心地や、なぜ欲しくなったか等のメモ"></textarea>
                </p>
                <p><input type="submit" value="登録する" style="background-color: #d81b60; color:white; border:none; padding:10px 20px; border-radius:4px; cursor:pointer;"></p>
            </form>
        </div>

        <div class="flex-container">
            <div class="column">
                <h2>❤️ 持っているコスメ</h2>
                <?php if (empty($owned_list)): ?>
                    <p>登録されたコスメはありません。</p>
                <?php else: ?>
                    <?php foreach ($owned_list as $item): ?>
                        <div class="cosme-item">
                            <span class="tag-owned">手持ち</span>
                            <strong><?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?></strong> 
                            (<?= htmlspecialchars($item['color_number'], ENT_QUOTES, 'UTF-8') ?>)<br>
                            価格: <?= $item['price'] ?>円 / 評価: <?= $item['rating'] ? str_repeat('★', $item['rating']) : 'なし' ?><br>
                            
                            <?php if (!empty($item['image_name'])): ?>
                                <img src="images/<?= htmlspecialchars($item['image_name'], ENT_QUOTES, 'UTF-8') ?>" class="cosme-img">
                            <?php endif; ?>

                            <p class="memo-text"><?= htmlspecialchars($item['memo'], ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="column">
                <h2>📌 欲しいものリスト</h2>
                <?php if (empty($wish_list)): ?>
                    <p>リストは空っぽです。</p>
                <?php else: ?>
                    <?php foreach ($wish_list as $item): ?>
                        <div class="cosme-item">
                            <span class="tag-wish">欲しい</span>
                            <strong><?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            (<?= htmlspecialchars($item['color_number'], ENT_QUOTES, 'UTF-8') ?>)<br>
                            予想価格: <?= $item['price'] ?>円 
                            
                            <form action="" method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="buy_cosme">
                                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                <input type="submit" class="btn-buy" value="購入した！">
                            </form>
                            
                            <?php if (!empty($item['image_name'])): ?>
                                <img src="images/<?= htmlspecialchars($item['image_name'], ENT_QUOTES, 'UTF-8') ?>" class="cosme-img">
                            <?php endif; ?>

                            <p class="memo-text"><?= htmlspecialchars($item['memo'], ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

</body>
</html>