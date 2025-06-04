<?php
// iniciaaliza a sessão
session_start();

include 'config.php';

// Esqueci minha senha
if (isset($_POST['email_recuperacao'])) {
    $email = $_POST['email_recuperacao'];
    
    // Verifica se o email existe no banco de dados
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

// Verifica se o usuário existe
if ($user) {
    // Gera token seguro (nunca envie a senha!)
    $token = bin2hex(random_bytes(32));
    $linkRedefinicao = "https://seusite.com/redefinir?token=$token";
    
    // Prepara os dados para envio
    $dados = [
        'email_recuperacao' => $email,
        'email' => $user['email'],
        'assunto' => "Recuperação de Senha - Sistema de Avaliação",
        'mensagem' => "Olá ".$user['nome'].",\n\n".
                     "Clique para redefinir sua senha:\n".
                     $linkRedefinicao."\n\n".
                     "O link expira em 1 hora.\n\n".
                     "Atenciosamente,\nEquipe do Festival",
        'nome' => $user['nome']
    ];

    // Configura a requisição POST
    $opcoes = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-type: application/x-www-form-urlencoded',
            'content' => http_build_query($dados)
        ]
    ];

    // Envia para mail.php (usando URL absoluta recomendada)
    $url = 'http://'.$_SERVER['HTTP_HOST'].'/festival/PHPMailer/mail.php';
    $resposta = file_get_contents($url, false, stream_context_create($opcoes));

    // Feedback para o usuário
    $_SESSION['success'] = "Instruções enviadas para ".$user['email'];
    
    //Redireciona de volta para a página inicial
    header("Location: ../index.php");
    exit();
} else {
    $_SESSION['error'] = "E-mail não cadastrado";
    header("Location: ../index.php"); // Redireciona para página de login
    exit();
}
}


// Login do usuário
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