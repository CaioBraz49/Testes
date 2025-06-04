<?php
// Início da sessão e verificação de autenticação
session_start();

// Verifica se o usuário está logado e é um administrador
if (!isset($_SESSION['user_id']) || $_SESSION['user_tipo'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// Inclui o arquivo de configuração do banco de dados
require_once '../includes/config.php';

/**
 * Configuração central de categorias (ordem desejada)
 * Define as categorias disponíveis no sistema com seus respectivos nomes de exibição
 */
$categories_config = [
    'anos_finais_ef' => 'Anos Finais do Ensino Fundamental',
    'ensino_medio' => 'Ensino Médio',
    'grad_mat_afins' => 'Graduandos em Matemática ou áreas afins',
    'prof_acao' => 'Professores em Ação',
    'povos_orig_trad' => 'Povos Originários e Tradicionais',
    'com_geral' => 'Comunidade em Geral',
];

// --- Lógica para atualizar limite de vídeos ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['atualizar_limite_videos'])) {
    $novo_limite = filter_input(INPUT_POST, 'novo_limite_videos', FILTER_VALIDATE_INT);

    // Validação do valor inserido
    if ($novo_limite !== false && $novo_limite >= 0) {
        // Verifica se a opção já existe no banco
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM options WHERE option_name = 'limite_videos'");
        $stmt_check->execute();
        $option_exists = $stmt_check->fetchColumn();

        // Prepara a query de atualização ou inserção
        if ($option_exists) {
            $stmt_update_limit = $pdo->prepare("UPDATE options SET option_value = :novo_limite WHERE option_name = 'limite_videos'");
        } else {
            $stmt_update_limit = $pdo->prepare("INSERT INTO options (option_name, option_value) VALUES ('limite_videos', :novo_limite)");
        }
        
        // Executa a query e define mensagem de sucesso/erro
        if ($stmt_update_limit->execute([':novo_limite' => $novo_limite])) {
            $_SESSION['success'] = "Limite de vídeos por avaliador atualizado para " . htmlspecialchars($novo_limite) . " com sucesso!";
        } else {
            $_SESSION['error'] = "Erro ao atualizar o limite de vídeos.";
        }
    } else {
        $_SESSION['error'] = "Valor inválido para o limite de vídeos. Por favor, insira um número inteiro não negativo.";
    }
    
    // Recarrega a página para mostrar a mensagem
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// --- Lógica para atualizar categorias do avaliador ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['atualizar_categorias'])) {
    $user_id = $_POST['user_id'];
    $categorias_avaliador = [];

    // Coleta as categorias selecionadas
    foreach (array_keys($categories_config) as $cat_key) {
        if (isset($_POST[$cat_key])) {
            $categorias_avaliador[] = $cat_key;
        }
    }

    // Prepara e executa a atualização no banco
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

// --- Consultas para estatísticas ---
$qtd_videos = $pdo->query("SELECT COUNT(*) FROM videos")->fetchColumn();
$qtd_avaliadores = $pdo->query("SELECT COUNT(*) FROM users WHERE tipo = 'avaliador'")->fetchColumn();
$qtd_avaliacoes = $pdo->query("SELECT COUNT(*) FROM avaliacoes")->fetchColumn();

// --- Filtro de categorias para vídeos ---
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

// --- Consulta de avaliadores ---
$avaliadores = $pdo->query("SELECT id, nome, email, categoria FROM users WHERE tipo = 'avaliador' ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

/**
 * Verifica se uma categoria está ativa para um avaliador
 * @param string $categoria_str String com categorias separadas por vírgula
 * @param string $categoria_busca_key Chave da categoria a ser verificada
 * @return bool Retorna true se a categoria estiver ativa
 */
function categoriaAtiva($categoria_str, $categoria_busca_key) {
    return in_array($categoria_busca_key, explode(',', $categoria_str));
}

// --- Consulta de avaliações por categoria ---
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard do Administrador</title>
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../includes/estilo.css?v=<?php echo time(); ?>">
    
    <style>
        /* Estilos para modais */
        .modal {
            z-index: 1060;
        }
        
        #infoModal {
            z-index: 1070;
        }
        
        .modal-dialog {
            width: 600px;
            max-width: none;
        }
        
        #infoButton {
            background-color: #0dcaf0;
            border-color: #0dcaf0;
        }
        
        .modal-draggable .modal-header {
            cursor: move;
            background-color: #f8f9fa;
        }
        
        /* Estilos para linhas de vídeo */
        .video-row {
            transition: all 0.3s;
        }
        
        .video-row:hover {
            background-color: #f8f9fa;
        }
        
        /* Estilos para badges de status */
        .badge-aprovado, .badge-success {
            background-color: #28a745 !important;
            color: white !important;
        }
        
        .badge-reprovado, .badge-danger {
            background-color: #dc3545 !important;
            color: white !important;
        }
        
        .badge-pendente {
            background-color: #ff830f !important;
            color: white !important;
        }
        
        .badge-reavaliar { 
            background-color: #ffd24d;
            color: #212529; 
        }
        
        .badge-correcao, .badge-warning {
            background-color: #ffc107 !important;
            color: #212529 !important;
        }
        
        .badge-aprovado_classificado, .badge-primary {
            background-color: #007bff !important;
            color: white !important;
        }
        
        .badge-secondary {
            background-color: #6c757d !important;
            color: white !important;
        }
        
        .badge-info {
            background-color: #17a2b8 !important;
            color: white !important;
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
                <?= htmlspecialchars($_SESSION['success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php elseif (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_SESSION['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <!-- Cards de estatísticas -->
        <div class="row text-center">
            <div class="col-md-4">
                <div class="card card-custom-light-purple-bg mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Vídeos cadastrados</h5>
                        <p class="card-text display-4"><?= $qtd_videos ?></p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card card-custom-light-purple-bg mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Avaliadores</h5>
                        <p class="card-text display-4"><?= $qtd_avaliadores ?></p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card card-custom-light-purple-bg mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Avaliações realizadas</h5>
                        <p class="card-text display-4"><?= $qtd_avaliacoes ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Configuração de limite de avaliações -->
        <div class="card mb-4">
            <div class="card-header card-header-custom-light-purple">
                <h5 class="mb-0">Configurar Limite de Avaliações por Avaliador</h5>
            </div>
            
            <div class="card-body">
                <?php
                // Obtém o limite atual de vídeos
                $stmt_get_limite_atual = $pdo->query("SELECT option_value FROM options WHERE option_name = 'limite_videos'");
                $limite_atual_val = $stmt_get_limite_atual->fetchColumn();
                
                if ($limite_atual_val === false) {
                    $limite_atual_val = 8; // Valor padrão
                }
                ?>
                
                <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                    <div class="form-group">
                        <label for="limite_videos_input">Limite de Vídeos que cada Avaliador pode avaliar:</label>
                        <input type="number" class="form-control" name="novo_limite_videos" id="limite_videos_input" 
                               value="<?php echo htmlspecialchars($limite_atual_val); ?>" min="0" required>
                    </div>
                    <button type="submit" name="atualizar_limite_videos" class="btn btn-primary">Atualizar Limite</button>
                </form>
            </div>
        </div>
        
        <!-- Lista de avaliadores -->
        <div class="card mb-4">
            <div class="card-header card-header-custom-light-purple">
                <h5 class="mb-0">Lista de Avaliadores</h5>
            </div>
            
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th>Nome</th>
                                <th>Email</th>
                                <?php foreach ($categories_config as $cat_key_loop => $cat_display_name): ?>
                                    <th><?= htmlspecialchars($cat_display_name) ?></th>
                                <?php endforeach; ?>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        
                        <tbody>
                            <?php foreach ($avaliadores as $avaliador): ?>
                                <tr>
                                    <form method="post">
                                        <td><?= htmlspecialchars($avaliador['nome']) ?></td>
                                        <td><?= htmlspecialchars($avaliador['email']) ?></td>
                                        
                                        <?php
                                        $id_avaliador = $avaliador['id'];
                                        $user_categorias_str = $avaliador['categoria']; 
                                        ?>
                                        
                                        <?php foreach ($categories_config as $cat_key => $cat_display_name): ?>
                                            <td>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" 
                                                           name="<?= $cat_key ?>" 
                                                           id="<?= $cat_key ?>_<?= $id_avaliador ?>" 
                                                           <?= categoriaAtiva($user_categorias_str, $cat_key) ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="<?= $cat_key ?>_<?= $id_avaliador ?>">
                                                        <?= $avaliacoes_por_categoria[$id_avaliador][$cat_display_name] ?? 0 ?>
                                                    </label>
                                                </div>
                                            </td>
                                        <?php endforeach; ?>
                                        
                                        <td>
                                            <input type="hidden" name="user_id" value="<?= $id_avaliador ?>">
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

        <!-- Filtro de vídeos -->
        <div class="card mb-4">
            <div class="card-header card-header-custom-light-purple">
                <h5 class="mb-0">Filtrar Vídeos</h5>
            </div>
            
            <div class="card-body">
                <form method="get" class="form-inline">
                    <div class="form-group mr-3">
                        <label for="categoria_filtro" class="mr-2">Categoria:</label>
                        <?php $categorias_para_filtro = array_values($categories_config); ?>
                        
                        <select name="categoria" id="categoria_filtro" class="form-control">
                            <option value="todas" <?= ($categoria_filtro ?? 'todas') === 'todas' ? 'selected' : '' ?>>Todas as Categorias</option>
                            
                            <?php foreach ($categorias_para_filtro as $categoria_nome_exibicao): ?>
                                <option value="<?= htmlspecialchars($categoria_nome_exibicao) ?>"
                                    <?= ($categoria_filtro ?? '') === $categoria_nome_exibicao ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($categoria_nome_exibicao) ?>
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
            <div class="card-header card-header-custom-light-purple">
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
                                <th>Autores</th>
                                <th>Link</th>
                                <th>Avaliações</th>
                            </tr>
                        </thead>
                        
                        <tbody>
                            <?php foreach ($videos as $video): ?>
                                <?php
                                // Busca todas as avaliações para o vídeo atual
                                $stmt_all_evals_for_this_video = $pdo->prepare("
                                    SELECT a.parecer, u.nome, a.id_user
                                    FROM avaliacoes a
                                    JOIN users u ON a.id_user = u.id
                                    WHERE a.id_video = :video_id_param
                                    ORDER BY a.data_avaliacao DESC
                                ");
                                
                                $stmt_all_evals_for_this_video->bindParam(':video_id_param', $video['id'], PDO::PARAM_INT);
                                $stmt_all_evals_for_this_video->execute();
                                $all_evaluations_for_this_video = $stmt_all_evals_for_this_video->fetchAll(PDO::FETCH_ASSOC);

                                // Determina o status do vídeo
                                $video_status_text = ucfirst(htmlspecialchars($video['status']));
                                $status_video_key = strtolower(str_replace(' ', '-', $video['status']));
                                
                                // Mapeamento de classes para os status
                                if ($status_video_key === 'aprovado' || $status_video_key === 'avaliado') {
                                    $status_final_class = 'success';
                                } elseif ($status_video_key === 'aprovado_classificado') {
                                    $status_final_class = 'aprovado_classificado';
                                } elseif ($status_video_key === 'reprovado') {
                                    $status_final_class = 'danger';
                                } elseif ($status_video_key === 'pendente') {
                                    $status_final_class = 'pendente';
                                } elseif ($status_video_key === 'reavaliar') {
                                    $status_final_class = 'reavaliar';
                                } elseif ($status_video_key === 'correcao') {
                                    $status_final_class = 'warning';
                                } else {
                                    $status_final_class = 'secondary';
                                }

                                // Lógica adicional para status "Aprovado"
                                if ($video['status'] === 'aprovado') {
                                    $count_finalista_evals = 0;
                                    
                                    foreach ($all_evaluations_for_this_video as $eval) {
                                        if ($eval['parecer'] === 'aprovado_classificado') {
                                            $count_finalista_evals++;
                                        }
                                    }

                                    if (count($all_evaluations_for_this_video) == 2 && $count_finalista_evals == 2) {
                                        $status_final_class = 'primary';
                                    }
                                }
                                ?>
                                
                                <tr class="video-row">
                                    <td>
                                        <span class="badge badge-<?= htmlspecialchars($status_final_class) ?>">
                                            <?= htmlspecialchars($video_status_text) ?>
                                        </span>
                                    </td>
                                    
                                    <td><?= htmlspecialchars($video['titulo']) ?></td>
                                    <td><?= htmlspecialchars($video['categoria']) ?></td>
                                    
                                    <td>
                                        <button class="btn btn-primary mx-2" data-bs-toggle="modal" data-bs-target="#contactModal"
                                                data-email="<?= htmlspecialchars($video['email']) ?>"
                                                data-nome="<?= htmlspecialchars($video['nome']) ?>"
                                                data-video="<?= htmlspecialchars($video['titulo']) ?>"
                                                data-categoria="<?= htmlspecialchars($video['categoria']) ?>"

                                                data-cidade="<?= htmlspecialchars($video['cidade']) ?>"
                                                data-estado="<?= htmlspecialchars($video['estado']) ?>"
                                                data-telefone="<?= htmlspecialchars($video['telefone']) ?>"
                                                data-instituicao_ensino="<?= htmlspecialchars($video['instituicao_ensino']) ?>"
                                                data-nivel_instituicao="<?= htmlspecialchars($video['nivel_instituicao']) ?>"
                                                data-autarquia="<?= htmlspecialchars($video['autarquia']) ?>"
                                                data-tema="<?= htmlspecialchars($video['tema']) ?>"
                                                data-descricao="<?= htmlspecialchars($video['descricao']) ?>">
                                            <?= htmlspecialchars($video['email']) ?>
                                        </button>
                                    </td>
                                    
                                    <td>
                                        <a href="<?= htmlspecialchars($video['link_youtube']) ?>" target="_blank">Ver no YouTube</a>
                                    </td>
                                    
                                    <td>
                                        <?php if (empty($all_evaluations_for_this_video)): ?>
                                            <span class="text-muted">Nenhuma avaliação</span>
                                        <?php else: ?>
                                            <?php foreach ($all_evaluations_for_this_video as $avaliacao_item): ?>
                                                <?php
                                                // Determina o texto e classe para cada parecer
                                                switch ($avaliacao_item['parecer']) {
                                                    case 'aprovado':
                                                        $parecer_text = 'Aprovado'; 
                                                        $parecer_class = 'success'; 
                                                        break;
                                                    case 'aprovado_classificado': 
                                                        $parecer_text = 'Aprovado'; 
                                                        $parecer_class = 'primary'; 
                                                        break;
                                                    case 'reprovado':
                                                        $parecer_text = 'Reprovado'; 
                                                        $parecer_class = 'danger'; 
                                                        break;
                                                    case 'correcao':
                                                        $parecer_text = 'Correção'; 
                                                        $parecer_class = 'warning'; 
                                                        break;
                                                    case 'terceiro': 
                                                        $parecer_text = 'Terceiro'; 
                                                        $parecer_class = 'info'; 
                                                        break;
                                                    default:
                                                        $parecer_text = ucfirst(htmlspecialchars($avaliacao_item['parecer']));
                                                        $parecer_class = 'secondary'; 
                                                        break;
                                                }
                                                ?>
                                                
                                                <div>
                                                    <a href="#" class="ver-detalhes" 
                                                       data-bs-toggle="modal" 
                                                       data-bs-target="#avaliacaoModal" 
                                                       data-video-id="<?= htmlspecialchars($video['id']) ?>" 
                                                       data-avaliador-id="<?= htmlspecialchars($avaliacao_item['id_user']) ?>" 
                                                       data-avaliador-nome="<?= htmlspecialchars($avaliacao_item['nome']) ?>">
                                                        <?= htmlspecialchars($avaliacao_item['nome']) ?>: 
                                                        <span class="badge badge-<?= htmlspecialchars($parecer_class) ?>">
                                                            <?= htmlspecialchars($parecer_text) ?>
                                                        </span>
                                                    </a>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Detalhes da Avaliação -->
    <div class="modal fade" id="avaliacaoModal" tabindex="-1" aria-labelledby="avaliacaoModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="avaliacaoModalLabel">Detalhes da Avaliação</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body" id="modalAvaliacaoBody">
                    <!-- Conteúdo será carregado via AJAX -->
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Email/Informações -->
    <div class="modal fade modal-draggable" id="contactModal" tabindex="-1" aria-labelledby="contactModalLabel" aria-hidden="true" data-bs-backdrop="false">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="contactModalLabel">Enviar Mensagem</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <form id="contactForm" method="post" action="../PHPMailer/mail.php">
                    <div class="modal-body">
                        <!-- Destinatário visível -->
                        <div class="input-group mb-3">
                            <span class="input-group-text">Para:</span>
                            <input type="text" class="form-control" id="modalDestinatarioVisivel" readonly>
                        </div>
       
                        <!-- Email (oculto) -->
                        <div class="input-group mb-3" style="display: none;">
                            <span class="input-group-text">Email:</span>
                            <input type="text" class="form-control" id="nome"  name="nome" >
                            <input type="email" class="form-control" id="email" name="email" >
                            <input type="hidden" id="url_retorno" name="url_retorno" value="../avaliador/index.php">
                        </div>
                        
                        <!-- Assunto -->
                        <div class="input-group mb-3">
                            <span class="input-group-text">Assunto:</span>
                            <input type="text" class="form-control" id="assunto" name="assunto" value="FESTIVAL - Correção de vídeo" required>
                        </div>
                        
                        <!-- Mensagem -->
                        <div class="input-group mb-3">
                            <span class="input-group-text">Mensagem:</span>
                            <textarea class="form-control" id="mensagem" name="mensagem" rows="6" required>
    <p>Prezado(a) Participante,</p>

    <p>Agradecemos sua participação no <strong>9º Festival de Vídeos Digitais e Educação Matemática</strong>.</p>

    <p>Gostaríamos de solicitar, gentilmente, que realize correções no vídeo intitulado <em>"Nome_Vídeo"</em> até o dia <strong>30/06/2025</strong>.</p>

    <p>Desde já, agradecemos sua atenção e colaboração.</p>

    <p>Atenciosamente,</p>

    <p><strong>Equipe do Festival de Vídeos Digitais e Educação Matemática</strong></p>
                            </textarea>
                        </div>
                        
                        <input type="hidden" id="modalNome" name="modalNome">
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-info me-auto" id="infoButton">
                            <i class="bi bi-info-circle"></i> Ver Informações
                        </button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send"></i> Enviar Mensagem
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de Informações do Email -->
    <div class="modal fade" id="infoModal" tabindex="-1" aria-labelledby="infoModalLabel" aria-hidden="true" data-bs-backdrop="false">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="infoModalLabel">Mais informações</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body">
                    <div id="contactInfoContent">
                        <!-- Conteúdo será preenchido via JavaScript -->
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="backButton">Voltar</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- TinyMCE Editor -->
    <script src="../../tinymce/tinymce.min.js"></script>
    
    <!-- Scripts personalizados -->
    <script>
        // Script para manipular os modais - Versão Bootstrap 5 puro
        document.addEventListener('DOMContentLoaded', function() {
            // Elementos e variáveis
            const contactModalEl = document.getElementById('contactModal');
            const infoModalEl = document.getElementById('infoModal');
            const contactModal = new bootstrap.Modal(contactModalEl);
            const infoModal = new bootstrap.Modal(infoModalEl);
            let currentContactData = {};
            let formData = {};

// Quando o modal de contato é aberto
contactModalEl.addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget;
    const email = button.getAttribute('data-email');
    const nome = button.getAttribute('data-nome');
    const video = button.getAttribute('data-video');
    const categoria = button.getAttribute('data-categoria');

    const cidade = button.getAttribute('data-cidade');
    const estado = button.getAttribute('data-estado');
    const telefone = button.getAttribute('data-telefone');
    const instituicao_ensino = button.getAttribute('data-instituicao_ensino');
    const nivel_instituicao = button.getAttribute('data-nivel_instituicao');
    const autarquia = button.getAttribute('data-autarquia');
    const tema = button.getAttribute('data-tema');
    const descricao = button.getAttribute('data-descricao');


    currentContactData = { email, nome, video, categoria, cidade, estado, telefone, instituicao_ensino, nivel_instituicao, autarquia, tema, descricao };

    // Preenche os campos visíveis
    document.getElementById('modalDestinatarioVisivel').value = "<" + email + "> " + nome;
    
    // Preenche os campos ocultos corretamente
    document.getElementById('email').value = email;
    document.getElementById('nome').value = nome;
    document.getElementById('assunto').value = "FESTIVAL - Correção de vídeo: "+video;
    
    // Atualiza o texto da mensagem com o nome do vídeo
    //const mensagemTextarea = document.getElementById('mensagem');
    tinymce.get('mensagem').setContent( `<p>Prezado(a) ${nome},</p>

<p>Agradecemos sua participação no <strong>9º Festival de Vídeos Digitais e Educação Matemática</strong>.</p>

<p>Gostaríamos de solicitar, gentilmente, que realize correções no vídeo intitulado <em>"${video}"</em> (categoria: ${categoria}) até o dia <strong>30/06/2025</strong>.</p>

<p>Desde já, agradecemos sua atenção e colaboração.</p>

<p>Atenciosamente,</p>

<p><strong>Equipe do Festival de Vídeos Digitais e Educação Matemática</strong></p>`);
    

    setTimeout(() => {
        document.getElementById('mensagem').focus();
    }, 500);
});

            // Botão "Ver Informações"
            document.getElementById('infoButton')?.addEventListener('click', function() {
                formData = {
                    assunto: document.getElementById('assunto').value,
                    mensagem: document.getElementById('mensagem').value
                };

                const infoContent = `
                    <h6>Detalhes do Contato</h6>
                    <p><strong>Nome:</strong> ${currentContactData.nome} &lt;${currentContactData.email}&gt;</p>
                    <p><strong>Cidade:</strong> ${currentContactData.cidade} - ${currentContactData.estado}<strong> Telefone:</strong> ${currentContactData.telefone}</p>
                    <p><strong>Instituição:</strong> ${currentContactData.instituicao_ensino} - ${currentContactData.nivel_instituicao} - ${currentContactData.autarquia}</p>
                    <p><strong>Categoria:</strong> ${currentContactData.categoria} <strong>Tema:</strong> ${currentContactData.tema}</p>
                    <p><strong>Título do vídeo:</strong> ${currentContactData.video}</p>
                    <p><strong>Descrição:</strong> ${currentContactData.descricao}</p>
                    <hr>
                    <p>Mais informações sobre o autor...</p>

                `;
                document.getElementById('contactInfoContent').innerHTML = infoContent;

                contactModal.hide();
                infoModal.show();
            });

            // Botão "Voltar"
            document.getElementById('backButton')?.addEventListener('click', function() {
                infoModal.hide();
                contactModal.show();

                setTimeout(() => {
                    document.getElementById('assunto').value = formData.assunto || 'FESTIVAL - Correção de vídeo';
                    document.getElementById('mensagem').value = formData.mensagem || '';
                    document.getElementById('mensagem').focus();
                }, 500);
            });

            // Detalhes de avaliação (AJAX)
            document.querySelectorAll('.ver-detalhes').forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    const videoId = this.dataset.videoId;
                    const avaliadorId = this.dataset.avaliadorId;
                    const avaliadorNome = this.dataset.avaliadorNome;
                    
                    const avaliacaoModal = new bootstrap.Modal(document.getElementById('avaliacaoModal'));
                    const modalBody = document.getElementById('modalAvaliacaoBody');
                    
                    document.getElementById('avaliacaoModalLabel').textContent = 'Detalhes da Avaliação - ' + avaliadorNome;
                    
                    modalBody.innerHTML = `
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Carregando...</span>
                            </div>
                            <p class="mt-2">Carregando detalhes da avaliação...</p>
                        </div>
                    `;
                    
                    avaliacaoModal.show();
                    
                    fetch('get_avaliacao_details.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `video_id=${videoId}&avaliador_id=${avaliadorId}`
                    })
                    .then(response => response.text())
                    .then(data => {
                        modalBody.innerHTML = data;
                    })
                    .catch(error => {
                        console.error('Erro:', error);
                        modalBody.innerHTML = `
                            <div class="alert alert-danger">
                                Erro ao carregar os detalhes da avaliação.<br>
                                ${error}
                            </div>
                        `;
                    });
                });
            });

            // Funcionalidade de arrastar o modal
            const modalHeader = contactModalEl.querySelector('.modal-header');
            const modalDialog = contactModalEl.querySelector('.modal-dialog');
            let isDragging = false;
            let offsetX = 0;
            let offsetY = 0;

            contactModalEl.addEventListener('shown.bs.modal', function() {
                modalDialog.style.margin = '0';
                modalDialog.style.position = 'absolute';
                modalDialog.style.left = '50%';
                modalDialog.style.top = '50%';
                modalDialog.style.transform = 'translate(-50%, -50%)';
            });

            modalHeader.addEventListener('mousedown', function(e) {
                isDragging = true;
                const rect = modalDialog.getBoundingClientRect();
                offsetX = e.clientX - rect.left;
                offsetY = e.clientY - rect.top;
                modalDialog.style.cursor = 'grabbing';
                e.preventDefault();
            });

            document.addEventListener('mousemove', function(e) {
                if (!isDragging) return;
                modalDialog.style.left = (e.clientX - offsetX) + 'px';
                modalDialog.style.top = (e.clientY - offsetY) + 'px';
                modalDialog.style.transform = 'none';
            });

            document.addEventListener('mouseup', function() {
                isDragging = false;
                if (modalDialog) modalDialog.style.cursor = '';
            });
        });

        // Inicialização do Editor de email TinyMCE
        tinymce.init({
            selector: 'textarea#mensagem',
            height: 200,
            width: '100%',
            language: 'pt_BR',
            menubar: false,
            plugins: 'link lists',
            toolbar: 'undo redo | bold italic | bullist numlist | alignleft aligncenter alignright | link',
            branding: false
        });
    </script>

    <?php include '../includes/footer.php'; ?>
</body>
</html>