<?php
$host = 'localhost';
$dbname = 'sicalis_festival'; // Certifique-se que este é o nome exato do banco
$username = 'root';
$password = ''; // Senha vazia

echo "Tentando conectar ao MySQL...<br>";
echo "Host: " . htmlspecialchars($host) . "<br>";
echo "Database: " . htmlspecialchars($dbname) . "<br>";
echo "User: " . htmlspecialchars($username) . "<br>";
echo "Password: (vazio)<br><hr>";

try {
    // Tenta a conexão
    $pdo_teste = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);

    // Se chegou aqui, a conexão foi bem-sucedida
    echo "<h1>CONEXÃO BEM-SUCEDIDA!</h1>";
    echo "Conectado ao banco de dados: " . htmlspecialchars($dbname) . "<br>";

    // Opcional: Testar uma query simples para listar tabelas
    $stmt = $pdo_teste->query("SHOW TABLES");
    echo "<h3>Tabelas no banco de dados '$dbname':</h3><ul>";
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        echo "<li>" . htmlspecialchars($row[0]) . "</li>";
    }
    echo "</ul>";

} catch (PDOException $e) {
    // Se falhou, mostra o erro
    echo "<h1>ERRO NA CONEXÃO:</h1>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    // Informação extra sobre o erro PDO
    echo "<p>Código do Erro PDO: " . htmlspecialchars($e->getCode()) . "</p>";
    if ($e->errorInfo) {
        echo "<p>Informação do Driver MySQL:</p><pre>";
        print_r($e->errorInfo);
        echo "</pre>";
    }
}
?>