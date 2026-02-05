<?php
session_start();
$erro = '';

$users = require __DIR__ . '/users.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['usuario'] ?? '';
    $pass = $_POST['senha'] ?? '';

    if (isset($users[$user]) && password_verify($pass, $users[$user]['password'])) {
        $_SESSION['usuario'] = $user;
        header('Location: index.php');
        exit;
    } else {
        $erro = 'Usuário ou senha inválidos';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>Login – Bot WhatsApp</title>
<style>
body {
    background:#111827;
    font-family: Inter, Arial, sans-serif;
    display:flex;
    align-items:center;
    justify-content:center;
    height:100vh;
}
.box {
    background:#fff;
    padding:30px;
    border-radius:14px;
    width:320px;
    box-shadow:0 10px 25px rgba(0,0,0,.4);
}
h2 {
    margin-top:0;
    text-align:center;
}
input {
    width:100%;
    padding:10px;
    margin-top:10px;
    border-radius:8px;
    border:1px solid #d1d5db;
}
button {
    width:100%;
    margin-top:20px;
    padding:12px;
    background:#2563eb;
    color:#fff;
    border:none;
    border-radius:10px;
    font-weight:600;
    cursor:pointer;
}
.error {
    background:#fee2e2;
    color:#991b1b;
    padding:10px;
    border-radius:8px;
    margin-bottom:10px;
    text-align:center;
}
</style>
</head>
<body>

<div class="box">
    <h2>🔐 Acesso ao Painel</h2>

    <?php if ($erro): ?>
        <div class="error"><?= $erro ?></div>
    <?php endif; ?>

    <form method="post">
        <input name="usuario" placeholder="Usuário" required>
        <input name="senha" type="password" placeholder="Senha" required>
        <button type="submit">Entrar</button>
    </form>
</div>

</body>
</html>
