<?php
session_start();
// Verifica se o usuário está logado e é um administrador
if (!isset($_SESSION['user_id']) || $_SESSION['user_tipo'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}
require_once '../includes/config.php'; //

$categories_config = [ //
    'anos_finais_ef' => 'Anos Finais do Ensino Fundamental',
    'ensino_medio' => 'Ensino Médio',
    'grad_mat_afins' => 'Graduandos em Matemática ou áreas afins',
    'prof_acao' => 'Professores em Ação',
    'povos_orig_trad' => 'Povos Originários e Tradicionais',
    'com_geral' => 'Comunidade em Geral',
];

// --- PROCESSAMENTO DO FORMULÁRIO DE ATUALIZAÇÃO DE AVALIADOR (CATEGORIAS E LIMITES POR CATEGORIA) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_evaluator_settings'])) { //
    $user_id_to_update = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

    if ($user_id_to_update) {
        $posted_assigned_categories = $_POST['assigned_categories'] ?? [];
        $posted_category_limits = $_POST['category_limits'] ?? [];
        
        $final_assigned_cat_keys_for_db = [];
        $pdo->beginTransaction();
        try {
            $stmt_delete_quotas = $pdo->prepare("DELETE FROM evaluator_category_quotas WHERE user_id = :user_id"); //
            $stmt_delete_quotas->execute([':user_id' => $user_id_to_update]);

            foreach ($categories_config as $cat_key => $cat_display_name) {
                if (isset($posted_assigned_categories[$cat_key]) && $posted_assigned_categories[$cat_key] == '1') {
                    $final_assigned_cat_keys_for_db[] = $cat_key;
                    if (isset($posted_category_limits[$cat_key])) {
                        $limit_str = trim($posted_category_limits[$cat_key]);
                        if ($limit_str !== '') { 
                            $limit_val = filter_var($limit_str, FILTER_VALIDATE_INT);
                            if ($limit_val !== false && $limit_val >= 0) {
                                $stmt_insert_quota = $pdo->prepare("INSERT INTO evaluator_category_quotas (user_id, category_key, quota) VALUES (:uid, :ckey, :q)"); //
                                $stmt_insert_quota->execute([':uid' => $user_id_to_update, ':ckey' => $cat_key, ':q' => $limit_val]);
                            } else {
                                if (!isset($_SESSION['warning_partial'])) $_SESSION['warning_partial'] = "";
                                $_SESSION['warning_partial'] .= "Limite inválido para categoria '{$cat_display_name}' do avaliador ID {$user_id_to_update} não foi salvo. "; //
                            }
                        }
                    }
                }
            }

            $categorias_db_string = implode(',', $final_assigned_cat_keys_for_db);
            $stmt_update_user = $pdo->prepare("UPDATE users SET categoria = :categoria WHERE id = :id"); //
            $stmt_update_user->execute([':categoria' => $categorias_db_string, ':id' => $user_id_to_update]);
            
            $pdo->commit();
            if (!isset($_SESSION['warning_partial'])) {
                 $_SESSION['success'] = "Configurações do avaliador ID " . htmlspecialchars($user_id_to_update) . " atualizadas com sucesso!";
            } else {
                 $_SESSION['warning_partial'] .= " Demais configurações do avaliador ID " . htmlspecialchars($user_id_to_update) . " foram salvas.";
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Erro ao atualizar configurações do avaliador: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "ID de usuário inválido para atualizar configurações.";
    }
    
    $redirect_url = $_SERVER['PHP_SELF'];
    if (isset($_GET['categoria']) && $_GET['categoria'] !== 'todas') {
        $redirect_url .= '?categoria=' . urlencode($_GET['categoria']);
    }
    header("Location: " . $redirect_url);
    exit();
}

// --- Lógica para atualizar STATUS DO VÍDEO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_update_video_status'])) { //
    $video_id_to_update = filter_input(INPUT_POST, 'video_id_to_update_status', FILTER_VALIDATE_INT);
    $new_status = $_POST['new_video_status'] ?? ''; 
    
    // Updated based on new table structure for videos.status ENUM
    $allowed_statuses = ['pendente','correcao','aprovado','reprovado','aprovado_classificado']; 

    if ($video_id_to_update && in_array($new_status, $allowed_statuses)) {
        try {
            $stmt_update_video = $pdo->prepare("UPDATE videos SET status = :new_status WHERE id = :video_id"); //
            $stmt_update_video->execute([':new_status' => $new_status, ':video_id' => $video_id_to_update]);
            $_SESSION['success'] = "Status do vídeo ID " . htmlspecialchars($video_id_to_update) . " atualizado para '" . htmlspecialchars($new_status) . "' com sucesso!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erro de banco de dados ao atualizar status: " . $e->getMessage();
        }
    } else {
         if (!$video_id_to_update) {
            $_SESSION['error'] = "ID do vídeo inválido para atualização de status.";
        } elseif (!in_array($new_status, $allowed_statuses)) {
            $_SESSION['error'] = "Status inválido ('" . htmlspecialchars($new_status) . "') fornecido. Status permitidos: " . implode(', ', $allowed_statuses) . ".";
        } else {
             $_SESSION['error'] = "Dados inválidos para atualização de status.";
        }
    }
    
    $redirect_url = $_SERVER['PHP_SELF'];
    if (isset($_GET['categoria']) && $_GET['categoria'] !== 'todas') {
        $redirect_url .= '?categoria=' . urlencode($_GET['categoria']);
    }
    header("Location: " . $redirect_url);
    exit();
}

// --- Consultas para estatísticas ---
$qtd_videos = $pdo->query("SELECT COUNT(*) FROM videos")->fetchColumn(); //
$qtd_avaliadores = $pdo->query("SELECT COUNT(*) FROM users WHERE tipo = 'avaliador'")->fetchColumn(); //
$qtd_avaliacoes = $pdo->query("SELECT COUNT(*) FROM avaliacoes")->fetchColumn(); //

// --- Filtro de categorias para vídeos ---
$categoria_filtro = $_GET['categoria'] ?? 'todas';

// **ASSUMPTION**: Added `v.nome AS autor_nome, v.email AS autor_email` to fetch author details for contact modal.
// These columns `nome` and `email` are from the NEW video table structure the user provided.
$sql_videos = "SELECT v.id, v.titulo, v.tema, v.categoria, v.link_youtube, v.status, v.created_at, v.nome AS autor_nome, v.email AS autor_email FROM videos v"; 
if ($categoria_filtro !== 'todas') {
    $sql_videos .= " WHERE v.categoria = :categoria"; //
}
$sql_videos .= " ORDER BY v.created_at DESC";

$stmt_videos = $pdo->prepare($sql_videos);
if ($categoria_filtro !== 'todas') {
    $stmt_videos->bindParam(':categoria', $categoria_filtro);
}
$stmt_videos->execute();
$videos = $stmt_videos->fetchAll(PDO::FETCH_ASSOC); //

// --- Consulta de avaliadores ---
$avaliadores = $pdo->query("SELECT id, nome, email, categoria FROM users WHERE tipo = 'avaliador' ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC); //

function categoriaAtiva($categoria_str, $categoria_busca_key) { //
    return in_array($categoria_busca_key, explode(',', $categoria_str ?? ''));
}

$avaliacoes_por_categoria = [];
$stmt_apc = $pdo->query("SELECT a.id_user, v.categoria, COUNT(*) as total FROM avaliacoes a JOIN videos v ON a.id_video = v.id GROUP BY a.id_user, v.categoria"); //
while ($row_apc = $stmt_apc->fetch(PDO::FETCH_ASSOC)) {
    $avaliacoes_por_categoria[$row_apc['id_user']][$row_apc['categoria']] = $row_apc['total'];
}

$all_evaluator_quotas = [];
$stmt_fetch_all_quotas = $pdo->query("SELECT user_id, category_key, quota FROM evaluator_category_quotas"); //
while ($quota_row = $stmt_fetch_all_quotas->fetch(PDO::FETCH_ASSOC)) {
    $all_evaluator_quotas[$quota_row['user_id']][$quota_row['category_key']] = $quota_row['quota'];
}

// Status options for the "Mudar Status" dropdown, reflecting new enum
$video_statuses_options_dropdown = [
    'pendente' => 'Pendente', 
    'correcao' => 'Correção Solicitada',
    'aprovado' => 'Aprovado', 
    'reprovado' => 'Reprovado', 
    'aprovado_classificado' => 'Aprovado e Classificado'
    // 'avaliado' and 'reavaliar' are not in the new videos.status enum from user's DDL
];

// For display purposes, we might want more descriptive texts for statuses, including old ones for context if needed
$video_statuses_display_texts = [
    'pendente' => 'Pendente', 
    'avaliado' => 'Avaliado (1ª Rodada)', // Kept for display if some logic still uses it
    'correcao' => 'Correção Solicitada',
    'aprovado' => 'Aprovado', 
    'reprovado' => 'Reprovado', 
    'reavaliar' => 'Reavaliar (3º Parecer)', // Kept for display
    'aprovado_classificado' => 'Aprovado e Classificado'
];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard do Administrador</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    
    <link rel="stylesheet" href="../includes/estilo.css?v=<?php echo time(); ?>">
    
    <style>
        .video-row { transition: all 0.2s ease-in-out; }
        .video-row:hover { background-color: #e9ecef; }

        /* Specific Badge Styles based on "Código 1" class names */
        .badge.badge-pendente { background-color: #ff830f !important; color: white !important; }
        .badge.badge-avaliado { background-color: #20c997 !important; color: white !important; } /* Tealish for 'avaliado' if it appears */
        .badge.badge-correcao { background-color: #ffc107 !important; color: #000 !important; }
        .badge.badge-aprovado { background-color: #198754 !important; color: white !important; }
        .badge.badge-reprovado { background-color: #dc3545 !important; color: white !important; }
        .badge.badge-reavaliar { background-color: #ffd24d !important; color: #000 !important; }
        .badge.badge-primary { background-color: #0d6efd !important; color: white !important; } /* For finalist/aprovado_classificado */
        .badge.badge-secondary { background-color: #6c757d !important; color: white !important; } /* Fallback */
        .badge.badge-info { background-color: #0dcaf0 !important; color: #000 !important; }


        .status-update-form .form-select-sm { width: auto; display: inline-block; max-width: 220px; } /* Adjusted width */
        .status-update-form .btn-sm { vertical-align: baseline; }
        
        .limit-input { width: 70px !important; margin-left: 5px; }

        .modal-draggable .modal-header { cursor: move; background-color: #f8f9fa; }
        .modal-dialog-centered { display: flex; align-items: center; min-height: calc(100% - 1rem); }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container mt-4 mb-5">
        <h2 class="mb-3">Painel do Administrador</h2>
        
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
        <?php if (isset($_SESSION['warning_partial'])): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_SESSION['warning_partial']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['warning_partial']); ?>
        <?php endif; ?>

        <div class="row text-center g-3 mb-4">
            <div class="col-md-4">
                <div class="card card-custom-light-purple-bg h-100">
                    <div class="card-body d-flex flex-column justify-content-center">
                        <h5 class="card-title">Vídeos Cadastrados</h5>
                        <p class="card-text display-4"><?= $qtd_videos ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-custom-light-purple-bg h-100">
                    <div class="card-body d-flex flex-column justify-content-center">
                        <h5 class="card-title">Avaliadores</h5>
                        <p class="card-text display-4"><?= $qtd_avaliadores ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-custom-light-purple-bg h-100">
                    <div class="card-body d-flex flex-column justify-content-center">
                        <h5 class="card-title">Avaliações Realizadas</h5>
                        <p class="card-text display-4"><?= $qtd_avaliacoes ?></p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header card-header-custom-light-purple text-white">
                <h5 class="mb-0">Lista de Avaliadores e Limites por Categoria</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-light">
                            <tr>
                                <th rowspan="2" style="vertical-align: middle;">Avaliador</th>
                                <?php foreach ($categories_config as $cat_display_name): ?>
                                    <th class="text-center" style="min-width: 150px;"><?= htmlspecialchars($cat_display_name) ?></th>
                                <?php endforeach; ?>
                                <th rowspan="2" style="vertical-align: middle;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($avaliadores as $avaliador): ?>
                                <tr>
                                    <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . (isset($_GET['categoria']) ? '?categoria=' . urlencode($_GET['categoria']) : '')); ?>">
                                        <input type="hidden" name="user_id" value="<?= $avaliador['id'] ?>">
                                        <td class="text-nowrap">
                                            <?= htmlspecialchars($avaliador['nome']) ?><br>
                                            <small class="text-muted"><?= htmlspecialchars($avaliador['email']) ?></small>
                                        </td>
                                        <?php
                                        $id_avaliador_loop = $avaliador['id'];
                                        $user_categorias_str_loop = $avaliador['categoria']; 
                                        ?>
                                        <?php foreach ($categories_config as $cat_key => $cat_display_name): ?>
                                            <td class="text-center">
                                                <div class="form-check d-inline-block me-2 align-middle">
                                                    <input class="form-check-input" type="checkbox" 
                                                           name="assigned_categories[<?= $cat_key ?>]" 
                                                           id="<?= $cat_key ?>_assigned_<?= $id_avaliador_loop ?>" 
                                                           value="1" <?= categoriaAtiva($user_categorias_str_loop, $cat_key) ? 'checked' : '' ?>
                                                           title="Atribuir categoria <?= htmlspecialchars($cat_display_name) ?>">
                                                    <label class="form-check-label visually-hidden" for="<?= $cat_key ?>_assigned_<?= $id_avaliador_loop ?>">Atribuir</label>
                                                </div>
                                                <input type="number" class="form-control form-control-sm limit-input d-inline-block align-middle"
                                                       name="category_limits[<?= $cat_key ?>]"
                                                       id="limit_<?= $cat_key ?>_<?= $id_avaliador_loop ?>"
                                                       value="<?= htmlspecialchars($all_evaluator_quotas[$id_avaliador_loop][$cat_key] ?? '') ?>"
                                                       placeholder="N/A" min="0" title="Limite para <?= htmlspecialchars($cat_display_name) ?>. Vazio para ilimitado.">
                                                <div class="small text-muted mt-1">
                                                    (<?= $avaliacoes_por_categoria[$id_avaliador_loop][$cat_display_name] ?? 0 ?>)
                                                </div>
                                            </td>
                                        <?php endforeach; ?>
                                        <td class="text-nowrap align-middle">
                                            <button type="submit" name="update_evaluator_settings" class="btn btn-primary btn-sm">Salvar</button>
                                        </td>
                                    </form>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header card-header-custom-light-purple text-white">
                <h5 class="mb-0">Filtrar Vídeos</h5>
            </div>
            <div class="card-body">
                <form method="get" class="d-flex align-items-center flex-wrap">
                    <div class="me-3 mb-2 mb-md-0">
                        <label for="categoria_filtro" class="form-label me-2">Categoria:</label>
                        <select name="categoria" id="categoria_filtro" class="form-select form-select-sm d-inline-block" style="width:auto;">
                            <option value="todas" <?= ($categoria_filtro ?? 'todas') === 'todas' ? 'selected' : '' ?>>Todas as Categorias</option>
                            <?php foreach ($categories_config as $cat_key_filter => $cat_name_filter): ?>
                                <option value="<?= htmlspecialchars($cat_name_filter) ?>" <?= ($categoria_filtro ?? '') === $cat_name_filter ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat_name_filter) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header card-header-custom-light-purple text-white">
                <h5 class="mb-0">Lista de Vídeos</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Status</th>
                                <th>Título</th>
                                <th>Categoria</th>
                                <th>Autor(es)</th>
                                <th>Link</th>
                                <th>Avaliações</th>
                                <th style="min-width: 230px;">Mudar Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($videos)): ?>
                                <tr><td colspan="7" class="text-center">Nenhum vídeo encontrado.</td></tr>
                            <?php else: ?>
                                <?php foreach ($videos as $video_item): ?>
                                    <?php
                                    $stmt_evals = $pdo->prepare("SELECT a.parecer, u.nome, u.id as id_user FROM avaliacoes a JOIN users u ON a.id_user = u.id WHERE a.id_video = :vid ORDER BY a.data_avaliacao DESC");
                                    $stmt_evals->execute([':vid' => $video_item['id']]);
                                    $evaluations_for_this_video = $stmt_evals->fetchAll(PDO::FETCH_ASSOC);

                                    $status_key_raw = strtolower($video_item['status']);
                                    $status_text = $video_statuses_display_texts[$status_key_raw] ?? ucfirst(htmlspecialchars($video_item['status']));
                                    
                                    $badge_class = 'secondary'; // Default
                                    if ($status_key_raw === 'aprovado' || $status_key_raw === 'aprovado_classificado') $badge_class = 'aprovado';
                                    if ($status_key_raw === 'avaliado') $badge_class = 'avaliado';
                                    elseif ($status_key_raw === 'reprovado') $badge_class = 'reprovado';
                                    elseif ($status_key_raw === 'pendente') $badge_class = 'pendente';
                                    elseif ($status_key_raw === 'reavaliar') $badge_class = 'reavaliar';
                                    elseif ($status_key_raw === 'correcao') $badge_class = 'correcao';
                                    
                                    if ($video_item['status'] === 'aprovado' || $video_item['status'] === 'aprovado_classificado') {
                                        $count_finalista = 0;
                                        foreach ($evaluations_for_this_video as $ev) {
                                            if ($ev['parecer'] === 'aprovado_classificado') $count_finalista++;
                                        }
                                        if ($count_finalista > 0) {
                                            $is_truly_finalist = true;
                                            foreach($evaluations_for_this_video as $ev_check){
                                                if($ev_check['parecer'] === 'reprovado') { $is_truly_finalist = false; break; }
                                            }
                                            if($is_truly_finalist) $badge_class = 'primary';
                                        }
                                    }
                                    if ($video_item['status'] === 'aprovado_classificado') { // Ensure it's primary if status is directly 'aprovado_classificado'
                                        $badge_class = 'primary';
                                    }

                                    ?>
                                    <tr class="video-row">
                                        <td><span class="badge badge-<?= htmlspecialchars($badge_class) ?>"><?= htmlspecialchars($status_text) ?></span></td>
                                        <td><?= htmlspecialchars($video_item['titulo']) ?></td>
                                        <td><?= htmlspecialchars($video_item['categoria']) ?></td>
                                        <td>
                                            <?php 
                                            $autor_email_display = $video_item['autor_email'] ?? null; 
                                            $autor_nome_display = $video_item['autor_nome'] ?? 'N/A'; 
                                            if ($autor_email_display):
                                            ?>
                                            <button class="btn btn-outline-info btn-primary py-0 px-1" data-bs-toggle="modal" data-bs-target="#contactModal"
                                                    data-email="<?= htmlspecialchars($autor_email_display) ?>"
                                                    data-nome="<?= htmlspecialchars($autor_nome_display) ?>"
                                                    data-video="<?= htmlspecialchars($video_item['titulo']) ?>"
                                                    data-categoria="<?= htmlspecialchars($video_item['categoria']) ?>"
                                                    title="Contatar: <?= htmlspecialchars($autor_nome_display) ?> <<?= htmlspecialchars($autor_email_display) ?>>">
                                                <i class="bi bi-envelope"></i> <small><?= htmlspecialchars($autor_nome_display) ?></small>
                                            </button>
                                            <?php else: echo "<small class='text-muted'>" . htmlspecialchars($autor_nome_display) . "</small>"; endif; ?>
                                        </td>
                                        <td><a href="<?= htmlspecialchars($video_item['link_youtube']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-danger py-0 px-1"><i class="bi bi-youtube"></i> <small>Ver</small></a></td>
                                        <td>
                                            <?php if (empty($evaluations_for_this_video)): echo '<span class="text-muted small">Nenhuma</span>'; else: ?>
                                                <?php foreach ($evaluations_for_this_video as $eval_item): ?>
                                                    <?php
                                                    $p_status_key = strtolower($eval_item['parecer']);
                                                    $p_text = $video_statuses_display_texts[$p_status_key] ?? ucfirst(htmlspecialchars($eval_item['parecer']));
                                                    $p_badge_class = 'secondary';
                                                    if ($p_status_key === 'aprovado') $p_badge_class = 'aprovado';
                                                    elseif ($p_status_key === 'aprovado_classificado') { $p_text = 'Aprovado (Class.)'; $p_badge_class = 'primary'; }
                                                    elseif ($p_status_key === 'reprovado') $p_badge_class = 'reprovado';
                                                    elseif ($p_status_key === 'correcao') $p_badge_class = 'correcao';
                                                    elseif ($p_status_key === 'terceiro') {$p_text = '3º Parecer'; $p_badge_class = 'info';}
                                                    ?>
                                                    <div>
                                                        <a href="#" class="ver-detalhes small" 
                                                           data-bs-toggle="modal" data-bs-target="#avaliacaoModal" 
                                                           data-video-id="<?= htmlspecialchars($video_item['id']) ?>" 
                                                           data-avaliador-id="<?= htmlspecialchars($eval_item['id_user']) ?>" 
                                                           data-avaliador-nome="<?= htmlspecialchars($eval_item['nome']) ?>">
                                                            <?= htmlspecialchars($eval_item['nome']) ?>: <span class="badge badge-<?= htmlspecialchars($p_badge_class) ?>"><?= $p_text ?></span>
                                                        </a>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . (isset($_GET['categoria']) ? '?categoria=' . urlencode($_GET['categoria']) : '')); ?>" method="POST" class="d-flex status-update-form">
                                                <input type="hidden" name="video_id_to_update_status" value="<?= htmlspecialchars($video_item['id']) ?>">
                                                <select name="new_video_status" class="form-select form-select-sm me-2" aria-label="Novo status para vídeo">
                                                    <?php foreach ($video_statuses_options_dropdown as $status_val_option => $status_txt_option): ?>
                                                        <option value="<?= htmlspecialchars($status_val_option) ?>" <?= (strtolower($video_item['status']) == $status_val_option ? 'selected' : '') ?>>
                                                            <?= htmlspecialchars($status_txt_option) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" name="submit_update_video_status" class="btn btn-primary btn-sm" title="Salvar novo status"><i class="bi bi-check-lg"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="avaliacaoModal" tabindex="-1" aria-labelledby="avaliacaoModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="avaliacaoModalLabel">Detalhes da Avaliação</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="modalAvaliacaoBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade modal-draggable" id="contactModal" tabindex="-1" aria-labelledby="contactModalLabel" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-draggable-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="contactModalLabel">Enviar Mensagem</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="contactForm" method="post" action="../PHPMailer/mail.php"> 
                    <div class="modal-body">
                        <div class="input-group mb-3">
                            <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                            <input type="text" class="form-control" id="modalDestinatarioVisivel" readonly>
                        </div>
                        <div style="display: none;">
                            <input type="text" id="contactNomeHidden" name="nome">
                            <input type="email" id="contactEmailHidden" name="email">
                            <input type="hidden" name="url_retorno" value="<?php echo htmlspecialchars(basename($_SERVER['PHP_SELF']) . (isset($_GET['categoria']) ? '?categoria='.urlencode($_GET['categoria']) : '')); ?>">
                        </div>
                        <div class="input-group mb-3">
                            <span class="input-group-text"><i class="bi bi-chat-left-text-fill"></i></span>
                            <input type="text" class="form-control" id="contactAssunto" name="assunto" required>
                        </div>
                        <div class="mb-3">
                            <label for="contactMensagem" class="form-label visually-hidden">Mensagem:</label>
                            <textarea class="form-control" id="contactMensagem" name="mensagem" rows="6" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer justify-content-between">
                        <button type="button" class="btn btn-outline-info" id="infoButtonTrigger">
                            <i class="bi bi-info-circle"></i> Detalhes
                        </button>
                        <div>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-send"></i> Enviar
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="infoModal" tabindex="-1" aria-labelledby="infoModalLabel" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="infoModalLabel">Detalhes do Contato</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="contactInfoModalBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="backToContactModalButtonTrigger">Voltar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="../../tinymce/tinymce.min.js"></script> 
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.ver-detalhes').forEach(button => {
    button.addEventListener('click', function(e) {
        e.preventDefault();
        console.log("'.ver-detalhes' clicado."); // 1. O clique foi detectado?

        const videoId = this.dataset.videoId;
        const avaliadorId = this.dataset.avaliadorId;
        const avaliadorNome = this.dataset.avaliadorNome;
        console.log("Dados extraídos:", { videoId, avaliadorId, avaliadorNome }); // 2. Os dados foram extraídos corretamente?

        const avaliacaoModalElement = document.getElementById('avaliacaoModal');
        if (!avaliacaoModalElement) {
            console.error("Elemento do Modal #avaliacaoModal NÃO ENCONTRADO!");
            return;
        }
        // Tente obter a instância do modal aqui, ou reutilize se já instanciado globalmente
        const avaliacaoModal = bootstrap.Modal.getInstance(avaliacaoModalElement) || new bootstrap.Modal(avaliacaoModalElement);

        const modalBody = document.getElementById('modalAvaliacaoBody');
        if (!modalBody) {
            console.error("Corpo do Modal #modalAvaliacaoBody NÃO ENCONTRADO!");
            return;
        }

        const modalLabel = document.getElementById('avaliacaoModalLabel');
        if (modalLabel) {
            modalLabel.textContent = 'Detalhes da Avaliação - ' + avaliadorNome;
        } else {
            console.warn("Label do Modal #avaliacaoModalLabel não encontrado.");
        }
        
        modalBody.innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Carregando...</span>
                </div>
                <p class="mt-2">Carregando detalhes da avaliação...</p>
            </div>
        `;
        
        console.log("Mostrando o modal #avaliacaoModal..."); // 3. Estamos tentando mostrar o modal?
        avaliacaoModal.show();
        
        console.log("Fazendo fetch para get_avaliacao_details.php..."); // 4. A chamada AJAX está sendo iniciada?
        fetch('get_avaliacao_details.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `video_id=${videoId}&avaliador_id=${avaliadorId}`
        })
        .then(response => {
            console.log("Resposta do fetch recebida. Status:", response.status); // 5. Qual o status da resposta?
            if (!response.ok) {
                throw new Error(`Erro HTTP! Status: ${response.status}`);
            }
            return response.text();
        })
        .then(data => {
            console.log("Dados recebidos do fetch (primeiros 200 chars):", data.substring(0, 200)); // 6. Quais dados foram recebidos?
            modalBody.innerHTML = data;
        })
        .catch(error => {
            console.error('Erro durante o fetch ou processamento dos dados:', error); // 7. Ocorreu algum erro no fetch?
            modalBody.innerHTML = `
                <div class="alert alert-danger">
                    Erro ao carregar os detalhes da avaliação.<br>
                    ${error.message}
                </div>
            `;
        });
    });
});

        const contactModalEl = document.getElementById('contactModal');
    const infoModalEl = document.getElementById('infoModal');
    // Assegure-se que os modais são instanciados corretamente com new bootstrap.Modal()
    const contactModalJsInstance = contactModalEl ? new bootstrap.Modal(contactModalEl) : null;
    const infoModalJsInstance = infoModalEl ? new bootstrap.Modal(infoModalEl) : null;
    
    let currentContactData = {}; // Para armazenar os dados do contato atual
    let contactFormState = {}; // Para armazenar o estado do formulário ao trocar para o infoModal

    if(contactModalEl && contactModalJsInstance) {
        contactModalEl.addEventListener('shown.bs.modal', function(event) {
            const button = event.relatedTarget; // Botão que acionou o modal
            if (!button) return; // Sai se o modal não foi acionado por um botão

            // Coleta os dados do vídeo/autor dos atributos data-* do botão
            currentContactData = { 
                email: button.getAttribute('data-email') || '', // Email do autor do vídeo
                nome: button.getAttribute('data-nome') || 'Participante', // Nome do autor do vídeo
                video: button.getAttribute('data-video') || 'Não especificado', // Título do vídeo
                categoria: button.getAttribute('data-categoria') || 'Não especificada' // Categoria do vídeo
            };
            
            // Preenche os campos visíveis e ocultos do formulário do modal
            document.getElementById('modalDestinatarioVisivel').value = `${currentContactData.nome} <${currentContactData.email}>`;
            document.getElementById('contactEmailHidden').value = currentContactData.email;
            document.getElementById('contactNomeHidden').value = currentContactData.nome;
            document.getElementById('contactAssunto').value = `FESTIVAL - Correção de vídeo: ${currentContactData.video}`;
            
            // Define a mensagem padrão com os dados coletados
            const mensagemPadrao = 
                `<p>Prezado(a) ${currentContactData.nome},</p>` +
                `<p>Agradecemos sua participação no <strong>Festival de Vídeos Digitais e Educação Matemática</strong>.</p>` +
                `<p>Gostaríamos de solicitar, gentilmente, que realize correções no vídeo intitulado <em>"${currentContactData.video}"</em> (categoria: ${currentContactData.categoria}) até o dia <strong>[INSIRA A DATA LIMITE AQUI]</strong>.</p>` +
                `<p>Desde já, agradecemos sua atenção e colaboração.</p>` +
                `<p>Atenciosamente,</p>` +
                `<p><strong>Equipe do Festival de Vídeos Digitais e Educação Matemática</strong></p>`;
            
            // Tenta preencher o editor TinyMCE
            const editor = tinymce.get('contactMensagem');
            if (editor) {
                editor.setContent(mensagemPadrao);
            } else {
                // Fallback se o TinyMCE não estiver pronto ou não encontrado (improvável se inicializado corretamente)
                // Para um textarea simples, removemos as tags HTML para melhor visualização
                const plainTextMessage = mensagemPadrao
                    .replace(/<p>/gi, "")
                    .replace(/<\/p>/gi, "\n")
                    .replace(/<strong>/gi, "")
                    .replace(/<\/strong>/gi, "")
                    .replace(/<em>/gi, "")
                    .replace(/<\/em>/gi, "")
                    .replace(/<br\s*\/?>/gi, "\n")
                    .trim();
                document.getElementById('contactMensagem').value = plainTextMessage;
                console.warn("TinyMCE editor 'contactMensagem' não encontrado. Preenchendo textarea diretamente.");
            }
        });
    }

    const infoButtonTrigger = document.getElementById('infoButtonTrigger');
    if(infoButtonTrigger && contactModalJsInstance && infoModalJsInstance) {
        infoButtonTrigger.addEventListener('click', function() {
            // Salva o estado atual do formulário de contato antes de mudar de modal
            contactFormState = {
                assunto: document.getElementById('contactAssunto').value,
                mensagem: tinymce.get('contactMensagem') ? tinymce.get('contactMensagem').getContent() : document.getElementById('contactMensagem').value
            };

            const infoContentHTML = 
                `<h6>Detalhes do Contato</h6>` +
                `<p><strong>Nome:</strong> ${currentContactData.nome || 'N/A'}</p>` +
                `<p><strong>Email:</strong> ${currentContactData.email || 'N/A'}</p><hr>` +
                `<h6>Detalhes do Vídeo</h6>` +
                `<p><strong>Título:</strong> ${currentContactData.video || 'N/A'}</p>` +
                `<p><strong>Categoria:</strong> ${currentContactData.categoria || 'N/A'}</p>`;
            document.getElementById('contactInfoModalBody').innerHTML = infoContentHTML;
            
            contactModalJsInstance.hide();
            infoModalJsInstance.show();
        });
    }

    const backToContactBtn = document.getElementById('backToContactModalButtonTrigger');
    if(backToContactBtn && contactModalJsInstance && infoModalJsInstance) {
        backToContactBtn.addEventListener('click', function() {
            infoModalJsInstance.hide();
            contactModalJsInstance.show();
            
            // Restaura o estado do formulário de contato
            document.getElementById('contactAssunto').value = contactFormState.assunto || `FESTIVAL - Correção de vídeo: ${currentContactData.video || ''}`;
            const editor = tinymce.get('contactMensagem');
            if (editor) {
                editor.setContent(contactFormState.mensagem || '');
            } else {
                document.getElementById('contactMensagem').value = contactFormState.mensagem || '';
            }
        });
    }
    
    // Inicialização do TinyMCE (deve estar aqui ou em um local que execute após o DOM estar pronto)
    if (typeof tinymce !== 'undefined') {
        tinymce.init({
            selector: 'textarea#contactMensagem', // ID da sua textarea no modal de contato
            height: 280,
            language: 'pt_BR',
            menubar: false,
            plugins: 'link lists autoresize visualblocks code',
            toolbar: 'undo redo | styles | bold italic underline | bullist numlist | alignleft aligncenter alignright alignjustify | link | code visualblocks',
            branding: false,
            autoresize_bottom_margin: 20,
            content_style: 'body { font-family:Helvetica,Arial,sans-serif; font-size:14px }',
            setup: function (editor) {
                editor.on('init', function (e) {
                    // Se o modal já estiver aberto e o editor inicializar depois,
                    // você pode tentar preencher aqui, mas é mais confiável no 'show.bs.modal'.
                });
            }
        });
    }
});
    </script>
    <?php include '../includes/footer.php'; ?>
</body>
</html>