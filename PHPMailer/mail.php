<?php
// Início da sessão e verificação de autenticação
session_start();

// Recebe Post e validando a URL de retorno
// Verifica se o usuário está logado
if (isset($_POST['url_retorno'])) {
    // Página de retorno
    $url_retorno = $_POST['url_retorno'];
}
else {
    // Página de retorno padrão
    //$url_retorno = '../index.php';
    //exit;
}


// Receber Post e validando o email de recuperação ou Enviar mensagem avaliação
if (isset($_POST['email_recuperacao'])) {
    $email = $_POST['email_recuperacao'];
    include '../includes/config.php';

    // Consultar banco de dados do email
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    $nome = $user['nome'];
    $assunto = "Recuperação de Senha - Sistema de Avaliação Festival de Vídeos";
    $mensagem = "<p>Olá " . $user['nome'] . ",</p>" .
                "<p>Sua senha para acessar o Sistema de Avaliação Festival de Vídeos é igual a " . $user['senha'] . "</p>" .
                "<br>" .
                "<p>Atenciosamente,</p>" .
                "<p>Equipe do Festival de Vídeos Digitais e Educação Matemática</p>";
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nome = $_POST['nome'] ?? '';
        $email = $_POST['email'] ?? '';
        $mensagem = $_POST['mensagem'] ?? '';
        $assunto = $_POST['assunto'] ?? '';

        // Validação simples
        if (empty($nome) || empty($email) || empty($mensagem) || empty($assunto)) {
            die('Erro: Todos os campos são obrigatórios.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            die('Erro: Email inválido.');
        }

        // Sanitização
        $nome = htmlspecialchars(strip_tags(trim($nome)));
        $email = htmlspecialchars(strip_tags(trim($email)));
        //$mensagem = htmlspecialchars(strip_tags(trim($mensagem)));
        $assunto = htmlspecialchars(strip_tags(trim($assunto)));
    }
}

// Certifique-se de que o encoding do arquivo é UTF-8 sem BOM
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Caminhos corretos para os arquivos
require '../PHPMailer/src/Exception.php';
require '../PHPMailer/src/PHPMailer.php';
require '../PHPMailer/src/SMTP.php';

// Configuração segura para carregar Variáveis de Ambiente .env
$envFile = $_SERVER['DOCUMENT_ROOT'] . '/.env'; // Assume que o .env está no nível raiz

if (!file_exists($envFile) || !is_readable($envFile)) {
    die('Erro: Arquivo .env não encontrado ou sem permissão de leitura');
}

$env = parse_ini_file($envFile);
if ($env === false) {
    die('Erro: Falha ao analisar o arquivo .env (formato inválido)');
}

// Configuração segura para carregar Variáveis de Ambiente .env
$requiredVars = ['FEST_GMAIL_USER', 'FEST_GMAIL_PASS', 'FEST_GMAIL_EMAIL_FROM', 'FEST_GMAIL_NAME_FROM'];
foreach ($requiredVars as $var) {
    if (!isset($env[$var])) {
        die("Erro: Variável $var não definida no .env");
    }
}

// Atribuições seguras
$gmail_user = $env['FEST_GMAIL_USER'] ?? '';
$gmail_pass = $env['FEST_GMAIL_PASS'] ?? '';
$gmail_host = $env['FEST_GMAIL_HOST'] ?? '';
$gmail_email_from = $env['FEST_GMAIL_EMAIL_FROM'] ?? '';
$gmail_name_from = $env['FEST_GMAIL_NAME_FROM'] ?? '';

$mail = new PHPMailer(true);

try {
    // Configurações do servidor
    $mail->isSMTP();
    // $mail->SMTPDebug = 2; // Debug detalhado
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';
    $mail->addCustomHeader('Content-Language: pt-BR');
    $mail->addCustomHeader('X-Google-Translate: no');
    $mail->Host = $gmail_host ?: 'smtp.gmail.com'; // Host SMTP padrão Gmail
    $mail->SMTPAuth = true;
    $mail->Username = $gmail_user;
    $mail->Password = $gmail_pass;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    // Configurações adicionais de segurança
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];

    // Remetente
    $mail->setFrom($gmail_email_from, $gmail_name_from);

    // Destinatário
    $mail->addAddress($email, $nome);

    // Cópia oculta (BCC) para o remetente original
    if (!isset($_POST['email_recuperacao'])) {
        $mail->addBCC($gmail_email_from, $gmail_name_from);
    }

    // Conteúdo
    $mail->isHTML(true);
    $mail->Subject = $assunto;
    $mail->Body = $mensagem;
    $mail->AltBody = strip_tags($mensagem);

    $mail->send();
    // Redireciona para a URL de retorno com sucesso
    if ($url_retorno == '../avaliador/index.php') {
        $_SESSION['success'] = "Email enviado com sucesso!";
        header("Location: ../avaliador/index.php");
        exit;
    }

} catch (Exception $e) {
    error_log('Erro ao enviar email: ' . $e->getMessage());
    echo 'Ocorreu um erro ao enviar o email. Tente novamente mais tarde.';
    // Redireciona para a URL de retorno com erro
    if ($url_retorno == '../avaliador/index.php') {
        $_SESSION['error'] = "Erro ao enviar email";
        header("Location: ../avaliador/index.php");
        exit;
    }

}
?>
