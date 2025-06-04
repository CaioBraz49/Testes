<?php
// iniciaaliza a sessão
session_start();

include 'config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'];
    $senha = $_POST['senha'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        // Email não existe no banco
        $_SESSION['error'] = "E-mail não cadastrado";
        header("Location: ../index.php");
        exit();
    }
    
    // Verifica a senha em texto puro (NÃO RECOMENDADO)
    if ($user['senha'] === $senha) { // Comparação direta (insegura)
        // Senha correta, inicia a sessão
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_nome'] = $user['nome'];
        $_SESSION['user_tipo'] = $user['tipo'];
        $_SESSION['user_categoria'] = $user['categoria'];
        
        if ($user['tipo'] == 'admin') {
            header('Location: ../admin/index.php');
        } else {
            header('Location: ../avaliador/index.php');
        }
        exit();
    } else {
        // Senha incorreta
        $_SESSION['error'] = 'Email ou senha incorretos';
        header('Location: ../index.php');
        exit();
    }
}
?>