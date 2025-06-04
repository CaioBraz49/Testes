<?php
// Configurações do banco de dados

/*
// Acesso ao banco de dados local xampp
$host = 'localhost';
$dbname = 'sicalis_festival';
$username = 'root';
$password = '';
*/

// Configuração segura para carregar Variáveis de Ambiente .env
$envFile = $_SERVER['DOCUMENT_ROOT'] . '/.env'; // Assume que o .env está no nível raiz

if (!file_exists($envFile) || !is_readable($envFile)) {
    die('Erro: Arquivo .env não encontrado ou sem permissão de leitura');
}

$env = parse_ini_file($envFile);
if ($env === false) {
    die('Erro: Falha ao analisar o arquivo .env (formato inválido)');
}

// Verifica se as variáveis necessárias estão definidas
$requiredVars = ['FEST_DB_HOST', 'FEST_DB_DBNAME', 'FEST_DB_USERNAME', 'FEST_DB_PASSWORD'];
foreach ($requiredVars as $var) {
    if (!isset($env[$var])) {
        die("Erro: Variável $var não definida no .env");
    }
}

// Atribuições seguras
$host = $env['FEST_DB_HOST'] ?? '';
$dbname = $env['FEST_DB_DBNAME'] ?? '';
$username = $env['FEST_DB_USERNAME'] ?? '';
$password = $env['FEST_DB_PASSWORD'] ?? '';

// Conexão com o banco de dados
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);

} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}

?>