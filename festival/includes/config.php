<?php
// Configurações do banco de dados

// Acesso ao banco de dados na internet em servidor remoto 191.252.133.116
// Necessario liberar o IP da sua máquina no cpanel do servidor
/*
$host = '191.252.133.116';
$dbname = 'sicalis_festival';
$username = 'sicalis_teste';
$password = 'Teste.3141592';
*/

// Acesso ao banco de dados local xampp
$host = 'localhost';
$dbname = 'sicalis_festival';
$username = 'root';
$password = '';

// Conexão com o banco de dados
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);

} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}

?>