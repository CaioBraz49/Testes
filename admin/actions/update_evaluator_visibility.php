```php
<?php
session_start();
// Ajuste o caminho se sua estrutura de pastas for diferente
require_once '../../includes/auth.php'; 
// Não é necessário config.php aqui, a menos que auth.php dependa dele de forma não inclusiva

// Apenas admins podem mudar esta configuração de sessão
if (!isset($_SESSION['user_id']) || $_SESSION['user_tipo'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Acesso não autorizado.']);
    exit();
}

header('Content-Type: application/json');

if (isset($_POST['show_evaluators'])) {
    $_SESSION['show_evaluators'] = ($_POST['show_evaluators'] === '1');
    echo json_encode(['success' => true, 'newState' => $_SESSION['show_evaluators']]);
} else {
    echo json_encode(['success' => false, 'message' => 'Parâmetro ausente.']);
}
?>
```
