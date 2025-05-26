<?php
session_start();
// Verifica se o usuário está logado e é um administrador
if (!isset($_SESSION['user_id']) || $_SESSION['user_tipo'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}
// Inclui o arquivo de configuração do banco de dados
require_once '../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['atualizar_categorias'])) {
    $user_id = $_POST['user_id'];

    $categorias_avaliador = [];
    foreach (['comunidade_geral', 'professores_acao', 'ensino_fundamental', 'ensino_medio',  'graduandos_matematica', 'povos_originarios'] as $cat) {
        if (isset($_POST[$cat])) {
            $categorias_avaliador[] = $cat;
        }
    }

    $categorias_str = implode(',', $categorias_avaliador);

    $stmt = $pdo->prepare("UPDATE users SET categoria = :categoria WHERE id = :id");
    $stmt->bindParam(':categoria', $categorias_str);
    $stmt->bindParam(':id', $user_id);

    if ($stmt->execute()) {
        $_SESSION['success'] = "Categorias do avaliador atualizadas com sucesso!";
    } else {
        $_SESSION['error'] = "Erro ao atualizar categorias do avaliador";
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

$qtd_videos = $pdo->query("SELECT COUNT(*) FROM videos")->fetchColumn();
$qtd_avaliadores = $pdo->query("SELECT COUNT(*) FROM users WHERE tipo = 'avaliador'")->fetchColumn();
$qtd_avaliacoes = $pdo->query("SELECT COUNT(*) FROM avaliacoes")->fetchColumn();

$categoria_filtro = $_GET['categoria'] ?? 'todas';

$sql_videos = "SELECT v.* FROM videos v";
if ($categoria_filtro !== 'todas') {
    $sql_videos .= " WHERE v.categoria = :categoria";
}
$stmt_videos = $pdo->prepare($sql_videos);
if ($categoria_filtro !== 'todas') {
    $stmt_videos->bindParam(':categoria', $categoria_filtro);
}
$stmt_videos->execute();
$videos = $stmt_videos->fetchAll(PDO::FETCH_ASSOC);

$categorias_videos = $pdo->query("SELECT DISTINCT categoria FROM videos")->fetchAll(PDO::FETCH_COLUMN);

$avaliadores = $pdo->query("SELECT id, nome, email, categoria FROM users WHERE tipo = 'avaliador' ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

function categoriaAtiva($categoria_str, $categoria_busca) {
    return in_array($categoria_busca, explode(',', $categoria_str));
}

// Busca quantidade de avaliacoes por categoria para cada avaliador
$avaliacoes_por_categoria = [];
$stmt = $pdo->query("SELECT a.id_user, v.categoria, COUNT(*) as total FROM avaliacoes a JOIN videos v ON a.id_video = v.id GROUP BY a.id_user, v.categoria");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $avaliacoes_por_categoria[$row['id_user']][$row['categoria']] = $row['total'];
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Dashboard do Administrador</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="../includes/estilo.css?v=<?php echo time(); ?>">
    <style>
        .video-row {
            transition: all 0.3s;
        }
        .video-row:hover {
            background-color: #f8f9fa;
        }
        .badge-pendente {
            background-color: #dc3545;
            color: #212529;
        }
        .badge-avaliado {
            background-color: #28a745;
        }
        .badge-correcao {
            background-color: #dc3545;
        }
    </style>
</head>
<body>
<?php include '../includes/header.php'; ?>
    <div class="container mt-5">
        <h2>Bem-vindo ao Painel do Administrador</h2>
        <hr>
        <!-- Mensagens de feedback -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= $_SESSION['success'] ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php elseif (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= $_SESSION['error'] ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <div class="row text-center">
            <div class="col-md-4">
                <div class="card bg-light mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Vídeos cadastrados</h5>
                        <p class="card-text display-4"><?= $qtd_videos ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-light mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Avaliadores</h5>
                        <p class="card-text display-4"><?= $qtd_avaliadores ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-light mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Avaliações realizadas</h5>
                        <p class="card-text display-4"><?= $qtd_avaliacoes ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Lista de Avaliadores -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Lista de Avaliadores</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th>Nome</th>
                                <th>Email</th>
                                <th>Comunidade em Geral</th>
                                <th>Professores em Ação</th>
                                <th>Ensino Fundamental</th>
                                <th>Ensino Médio</th>
                                <th>Graduandos em Matemática</th>
                                <th>Povos Originários</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Busca quantidade de avaliacoes por categoria para cada avaliador
                            $avaliacoes_por_categoria = [];
                            $stmt = $pdo->query("SELECT a.id_user, v.categoria, COUNT(*) as total FROM avaliacoes a JOIN videos v ON a.id_video = v.id GROUP BY a.id_user, v.categoria");
                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                $avaliacoes_por_categoria[$row['id_user']][$row['categoria']] = $row['total'];
                            }
                            ?>

                            <?php foreach ($avaliadores as $avaliador): ?>
                                <tr>
                                    <form method="post">
                                        <td><?= htmlspecialchars($avaliador['nome']) ?></td>
                                        <td><?= htmlspecialchars($avaliador['email']) ?></td>
                                        <?php
                                        $id = $avaliador['id'];
                                        $cat = $avaliador['categoria'];
                                        ?>
                                        <td>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="comunidade_geral" id="comunidade_geral_<?= $id ?>" <?= categoriaAtiva($cat, 'comunidade_geral') ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="comunidade_geral_<?= $id ?>">
                                                    <?= $avaliacoes_por_categoria[$id]['Comunidade em Geral'] ?? 0 ?>
                                                </label>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="professores_acao" id="professores_acao_<?= $id ?>" <?= categoriaAtiva($cat, 'professores_acao') ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="professores_acao_<?= $id ?>">
                                                    <?= $avaliacoes_por_categoria[$id]['Professores em Ação'] ?? 0 ?>
                                                </label>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="ensino_fundamental" id="ensino_fundamental_<?= $id ?>" <?= categoriaAtiva($cat, 'ensino_fundamental') ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="ensino_fundamental_<?= $id ?>">
                                                    <?= $avaliacoes_por_categoria[$id]['Ensino Fundamental'] ?? 0 ?>
                                                </label>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="ensino_medio" id="ensino_medio_<?= $id ?>" <?= categoriaAtiva($cat, 'ensino_medio') ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="ensino_medio_<?= $id ?>">
                                                    <?= $avaliacoes_por_categoria[$id]['Ensino Médio'] ?? 0 ?>
                                                </label>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="graduandos_matematica" id="graduandos_matematica_<?= $id ?>" <?= categoriaAtiva($cat, 'graduandos_matematica') ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="graduandos_matematica_<?= $id ?>">
                                                    <?= $avaliacoes_por_categoria[$id]['Graduandos em Matemática'] ?? 0 ?>
                                                </label>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="povos_originarios" id="povos_originarios_<?= $id ?>" <?= categoriaAtiva($cat, 'povos_originarios') ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="povos_originarios_<?= $id ?>">
                                                    <?= $avaliacoes_por_categoria[$id]['Povos Originários'] ?? 0 ?>
                                                </label>
                                            </div>
                                        </td>
                                        <td>
                                            <input type="hidden" name="user_id" value="<?= $id ?>">
                                            <button type="submit" name="atualizar_categorias" class="btn btn-primary btn-sm">Atualizar</button>
                                        </td>
                                    </form>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Filtro por categoria -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Filtrar Vídeos</h5>
            </div>
            <div class="card-body">
                <form method="get" class="form-inline">
                    <div class="form-group mr-3">
                        <label for="categoria" class="mr-2">Categoria:</label>
                        <?php
                        // Define as categorias disponíveis
                        $categorias = [
                            "Comunidade em Geral",
                            "Professores em Ação",
                            "Ensino Fundamental",
                            "Ensino Médio",
                            "Graduandos em Matemática",
                            "Povos Originários"
                        ];
                        ?>

                        <select name="categoria" id="categoria" class="form-control">
                            <option value="todas" <?= ($categoria_filtro ?? 'todas') === 'todas' ? 'selected' : '' ?>>Todas as Categorias</option>
                            <?php foreach ($categorias as $categoria): ?>
                                <option
                                    value="<?= htmlspecialchars($categoria) ?>"
                                    <?= ($categoria_filtro ?? '') === $categoria ? 'selected' : '' ?>
                                >
                                    <?= htmlspecialchars($categoria) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Filtrar</button>
                </form>
            </div>
        </div>

        <!-- Lista de vídeos -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Lista de Vídeos</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th>Status</th>
                                <th>Título</th>
                                <th>Categoria</th>
                                <th>Link</th>
                                <th>Avaliações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($videos as $video): ?>
                                <tr class="video-row">
                                    <td>
                                        <span class="badge badge-<?= strtolower($video['status']) ?>">
                                            <?= ucfirst($video['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($video['titulo']) ?></td>
                                    <td><?= htmlspecialchars($video['categoria']) ?></td>
                                    <td>
                                        <a href="<?= htmlspecialchars($video['link_youtube']) ?>" target="_blank">
                                            Ver no YouTube
                                        </a>
                                    </td>
                                    <td>
                                        <?php
                                        // Busca as avaliações deste vídeo
                                        $stmt_avaliacoes = $pdo->prepare("
                                            SELECT a.parecer, u.nome, a.id_user
                                            FROM avaliacoes a
                                            JOIN users u ON a.id_user = u.id
                                            WHERE a.id_video = :video_id
                                            ORDER BY a.data_avaliacao DESC
                                        ");
                                        $stmt_avaliacoes->bindParam(':video_id', $video['id'], PDO::PARAM_INT);
                                        $stmt_avaliacoes->execute();
                                        $avaliacoes = $stmt_avaliacoes->fetchAll(PDO::FETCH_ASSOC);

                                        if (empty($avaliacoes)) {
                                            echo '<span class="text-muted">Nenhuma avaliação</span>';
                                        } else {
                                            foreach ($avaliacoes as $avaliacao) {
                                                echo '<div>';
                                                echo '<a href="#" class="ver-detalhes"
                                                    data-toggle="modal"
                                                    data-target="#avaliacaoModal"
                                                    data-video-id="' . ($video['id']) . '"
                                                    data-avaliador-id="' . ($avaliacao['id_user']) . '"
                                                    data-avaliador-nome="' . htmlspecialchars($avaliacao['nome']) . '">';
                                                echo htmlspecialchars($avaliacao['nome']) . ': ';
                                                echo '<span class="badge badge-' . ($avaliacao['parecer'] === 'aprovado' ? 'success' : 'warning') . '">';
                                                echo ucfirst($avaliacao['parecer']);
                                                echo '</span>';
                                                echo '</a>';
                                                echo '</div>';
                                            }
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<!-- Modal de Detalhes da Avaliação -->
<div class="modal fade" id="avaliacaoModal" tabindex="-1" role="dialog" aria-labelledby="avaliacaoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="avaliacaoModalLabel">Detalhes da Avaliação</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="modalAvaliacaoBody">
                <!-- Conteúdo será carregado via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Quando o botão "Ver Detalhes" é clicado
    $(document).on('click', '.ver-detalhes', function(e) {
        e.preventDefault();
        
        const videoId = $(this).data('video-id'); // Será 1
        const avaliadorId = $(this).data('avaliador-id'); // Será 1
        
        // Atualiza o título do modal
        $('#avaliacaoModalLabel').text('Detalhes da Avaliação');
        
        // Mostra o spinner enquanto carrega
        $('#modalAvaliacaoBody').html(`
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="sr-only">Carregando...</span>
                </div>
                <p class="mt-2">Carregando detalhes da avaliação...</p>
            </div>
        `);
        
        // Mostra o modal
        $('#avaliacaoModal').modal('show');
        
        // Faz a requisição AJAX
        $.ajax({
            url: 'get_avaliacao_details.php',
            method: 'POST',
            data: {
                video_id: videoId,
                avaliador_id: avaliadorId
            },
            success: function(response) {
                $('#modalAvaliacaoBody').html(response);
            },
            error: function(xhr, status, error) {
                console.error("Erro AJAX:", status, error);
                $('#modalAvaliacaoBody').html(`
                    <div class="alert alert-danger">
                        Erro ao carregar os detalhes da avaliação.<br>
                        ${xhr.status}: ${xhr.statusText}
                    </div>
                `);
            }
        });
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

<!-- Footer -->
<?php include '../includes/footer.php'; ?>
</body>
</html>