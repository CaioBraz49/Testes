<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

try {
    // Verifica se o método é POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método não permitido');
    }

    // Obtém os dados do corpo da requisição
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validações básicas
    if (empty($data['senhaAtual']) || empty($data['novaSenha'])) {
        throw new Exception('Dados incompletos');
    }

    $senhaAtual = $data['senhaAtual'];
    $novaSenha = $data['novaSenha'];

    // Valida formato da nova senha
    if (strlen($novaSenha) !== 6 || !ctype_digit($novaSenha)) {
        throw new Exception('A nova senha deve conter exatamente 6 dígitos numéricos');
    }

    // Obtém usuário do banco de dados
    $stmt = $pdo->prepare("SELECT id, senha FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new Exception('Usuário não encontrado');
    }

    // Verifica senha atual (substitua por password_verify() se usar hash)
    if ($user['senha'] !== $senhaAtual) { // ATENÇÃO: futuramente usar password_hash() em produção
        throw new Exception('Senha atual incorreta');
    }

    // Atualiza a senha (em produção, use password_hash())
    $stmt = $pdo->prepare("UPDATE users SET senha = ? WHERE id = ?");
    $success = $stmt->execute([$novaSenha, $_SESSION['user_id']]);

    if (!$success) {
        throw new Exception('Erro ao atualizar senha no banco de dados');
    }

    $response['success'] = true;
    $response['message'] = 'Senha alterada com sucesso';

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);