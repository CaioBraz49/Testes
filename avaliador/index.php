<?php
// Ativar exibição de erros para debug (remover em produção)
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Verifica se o usuário está logado e é um avaliador
if (!isset($_SESSION['user_id']) || $_SESSION['user_tipo'] !== 'avaliador') {
    header('Location: ../index.php');
    exit();
}

// Inclui o arquivo de configuração e verifica a conexão PDO
require_once '../includes/config.php';

// Variáveis locais (abra variáveis de ambiente depois de config.php)
$apikey_youtube = $env['FEST_APIKEY_YOUTUBE'] ?? '';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die("Erro: Conexão com o banco de dados não estabelecida");
}

$user_id = $_SESSION['user_id']; // Definido uma vez

// ITEM 1: Buscar o limite global e contagem do avaliador
$stmt_limite = $pdo->query("SELECT option_value FROM options WHERE option_name = 'limite_videos'");
$limite_global_row = $stmt_limite->fetch(PDO::FETCH_ASSOC);
$limite_global_avaliacoes = $limite_global_row ? (int)$limite_global_row['option_value'] : 0;

$stmt_contagem_avaliacoes = $pdo->prepare("SELECT COUNT(*) FROM avaliacoes WHERE id_user = :user_id");
$stmt_contagem_avaliacoes->execute([':user_id' => $user_id]);
$avaliacoes_realizadas_pelo_usuario = $stmt_contagem_avaliacoes->fetchColumn();

$avaliacoes_restantes = $limite_global_avaliacoes - $avaliacoes_realizadas_pelo_usuario;

// Salva em SESSÃO para fácil acesso no HTML e lógica subsequente
$_SESSION['avaliacoes_realizadas'] = $avaliacoes_realizadas_pelo_usuario;
$_SESSION['limite_global_avaliacoes'] = $limite_global_avaliacoes;
$_SESSION['avaliacoes_restantes'] = $avaliacoes_restantes;

// Buscar as categorias do usuário logado (necessário para filtrar vídeos)
$sql_user_categorias = "SELECT categoria FROM users WHERE id = :id";
$stmt_user_categorias = $pdo->prepare($sql_user_categorias);
$stmt_user_categorias->execute([':id' => $user_id]);
$user_info = $stmt_user_categorias->fetch(); // Usar $user_info para não confundir com $user de outros contextos

if (!$user_info || empty($user_info['categoria'])) {
    die("
            Nenhuma categoria encontrada para este usuário
            <script>
            alert('Nenhuma categoria definida para este usuário. Se isso for um erro comunique-se com o administrador.');
            setTimeout(function() {
                window.location.href = '../includes/logout.php';
            }, 5000);
        </script>
    "); //
}

// Mapear códigos para nomes completos das categorias do usuário
$mapa_categorias = [
    'anos_finais_ef' => 'Anos Finais do Ensino Fundamental',
    'ensino_medio' => 'Ensino Médio',
    'grad_mat_afins' => 'Graduandos em Matemática ou áreas afins',
    'prof_acao' => 'Professores em Ação',
    'povos_orig_trad' => 'Povos Originários e Tradicionais',
    'com_geral' => 'Comunidade em Geral',
]; //

$categorias_convertidas = [];
$categorias_codigo = explode(',', $user_info['categoria']);

foreach ($categorias_codigo as $codigo) {
    $codigo_trim = trim($codigo); // Renomear para evitar conflito
    if (isset($mapa_categorias[$codigo_trim])) {
        $categorias_convertidas[] = $mapa_categorias[$codigo_trim];
    }
}

if (empty($categorias_convertidas)) {
    die("Nenhuma categoria válida encontrada para este usuário"); //
}

// Buscar vídeos pendentes de avaliação (APENAS SE O USUÁRIO AINDA PODE AVALIAR)
$videos_pendentes = [];
if ($_SESSION['avaliacoes_restantes'] > 0) { // Só busca vídeos se o avaliador ainda pode avaliar
    $placeholders_sql = implode(',', array_fill(0, count($categorias_convertidas), '?'));

    // NOVA CONSULTA SQL
    $sql_videos = "
        SELECT v.*
        FROM videos v
        WHERE
        (
            (
                v.status = 'pendente'
                AND (SELECT COUNT(*) FROM avaliacoes a WHERE a.id_video = v.id) < 2
            )
            OR
            (
                v.status = 'reavaliar'
                AND (SELECT COUNT(*) FROM avaliacoes a WHERE a.id_video = v.id) < 3
            )
        )
        AND v.categoria IN ($placeholders_sql)
        AND v.id NOT IN (
            SELECT id_video
            FROM avaliacoes
            WHERE id_user = ?
        )
        ORDER BY v.created_at ASC
    ";

    $stmt_videos = $pdo->prepare($sql_videos);
    // Os parâmetros para a consulta são as categorias + o user_id
    $params_sql = array_merge($categorias_convertidas, [$user_id]);
    $stmt_videos->execute($params_sql);
    $videos_pendentes = $stmt_videos->fetchAll(PDO::FETCH_ASSOC);
}


// Processar avaliação se o formulário foi enviado 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['avaliar'])) {
    $id_video = intval($_POST['id_video']);
    $id_user = $_SESSION['user_id']; // $id_user é o mesmo que $user_id da sessão

    // Recarregar contagem e limite AQUI para garantir a verificação mais atualizada no momento do POST.
    // Isso evita que o usuário abra o formulário, outro processo altere seu limite/contagem, e ele consiga submeter.

    $stmt_limite_post = $pdo->query("SELECT option_value FROM options WHERE option_name = 'limite_videos'");
    $limite_global_post_row = $stmt_limite_post->fetch(PDO::FETCH_ASSOC);
    $limite_global_avaliacoes_post = $limite_global_post_row ? (int)$limite_global_post_row['option_value'] : 0;

    $stmt_contagem_post = $pdo->prepare("SELECT COUNT(*) FROM avaliacoes WHERE id_user = :user_id");
    $stmt_contagem_post->execute([':user_id' => $id_user]);
    $avaliacoes_realizadas_post = $stmt_contagem_post->fetchColumn();

    if ($avaliacoes_realizadas_post >= $limite_global_avaliacoes_post && $limite_global_avaliacoes_post != 0) {
        $_SESSION['error'] = "Você atingiu o limite de " . htmlspecialchars($limite_global_avaliacoes_post) . " avaliações e não pode submeter novas avaliações.";
        // Redireciona de volta para a página do avaliador (index.php ou dashboard.php dependendo da sua estrutura)
        // O action do seu formulário é "dashboard.php", então vamos usar isso.
        // Se o nome do arquivo atual for index.php, pode usar header("Location: index.php");
        header("Location: index.php"); // Ou o action do seu formulário    // AQUI ESTAVA dashboard.php
        exit();
    }

    // Se passou na verificação, continua para montar o array $avaliacao e inserir no banco
    $avaliacao = [
        'id_user' => $id_user,
        'id_video' => $id_video,
        'conceitos_corretos' => isset($_POST['conceitos_matematicos_status']) ? (int)$_POST['conceitos_matematicos_status'] : 0, // Pega o valor do radio (0 ou 1)
        'comentario_conceitos' => $_POST['comentario_conceitos'] ?? '',
        'tempo_respeitado' => isset($_POST['tempo_respeitado']) ? 1 : 0,
        'comentario_tempo' => $_POST['comentario_tempo'] ?? '',
        'possui_titulo' => isset($_POST['titulo_status']) ? (int)$_POST['titulo_status'] : 0, // 1 se sim, 0 se não
        'comentario_titulo' => $_POST['comentario_titulo'] ?? '',
        'possui_creditos' => isset($_POST['creditos_status']) ? (int)$_POST['creditos_status'] : 0, // 1 se sim, 0 se não
        'comentario_creditos' => $_POST['comentario_creditos'] ?? '',
        'discurso_adequado' => isset($_POST['discurso_status']) ? (int)$_POST['discurso_status'] : 0,
        'comentario_discurso' => $_POST['comentario_discurso'] ?? '',
        'audio_qualidade' => isset($_POST['audio_status']) ? (int)$_POST['audio_status'] : 0,
        'comentario_audio' => $_POST['comentario_audio'] ?? '',
        'imagem_qualidade' => isset($_POST['imagem_status']) ? (int)$_POST['imagem_status'] : 0,
        'comentario_imagem' => $_POST['comentario_imagem'] ?? '',
        'edicao_correta' => isset($_POST['edicao_status']) ? (int)$_POST['edicao_status'] : 0,
        'comentario_edicao' => $_POST['comentario_edicao'] ?? '',
        'portugues_correto' => isset($_POST['portugues_status']) ? (int)$_POST['portugues_status'] : 0,
        'comentario_portugues' => $_POST['comentario_portugues'] ?? '',
        'parecer' => $_POST['parecer'],
        'justificativa' => $_POST['justificativa'] ?? ''
    ]; //

    try {
        $pdo->beginTransaction();
        $sql_avaliacao = "INSERT INTO avaliacoes (
            id_user, id_video, 
            conceitos_corretos, comentario_conceitos, 
            tempo_respeitado, comentario_tempo,
            possui_titulo, comentario_titulo, 
            possui_creditos, comentario_creditos, 
            discurso_adequado, comentario_discurso, 
            audio_qualidade, comentario_audio, 
            imagem_qualidade, comentario_imagem,
            edicao_correta, comentario_edicao, 
            portugues_correto, comentario_portugues, 
            parecer, justificativa
        ) VALUES (
            :id_user, :id_video, 
            :conceitos_corretos, :comentario_conceitos, 
            :tempo_respeitado, :comentario_tempo,
            :possui_titulo, :comentario_titulo, 
            :possui_creditos, :comentario_creditos, 
            :discurso_adequado, :comentario_discurso, 
            :audio_qualidade, :comentario_audio, 
            :imagem_qualidade, :comentario_imagem,
            :edicao_correta, :comentario_edicao, 
            :portugues_correto, :comentario_portugues, 
            :parecer, :justificativa
        )"; 
        $stmt = $pdo->prepare($sql_avaliacao);
        $stmt->execute($avaliacao);

        // 2. Obter o status do vídeo ANTES desta avaliação ser considerada para decisão
        //    e o número total de avaliações AGORA (incluindo a que acabamos de inserir).
        $stmt_info_video = $pdo->prepare(
            "SELECT status, (SELECT COUNT(*) FROM avaliacoes WHERE id_video = v.id) as num_avaliacoes 
             FROM videos v WHERE v.id = :id_video"
        );
        $stmt_info_video->execute([':id_video' => $id_video]);
        $video_info_atual = $stmt_info_video->fetch(PDO::FETCH_ASSOC);

        $status_video_antes_desta_logica = $video_info_atual['status'];
        $num_avaliacoes_agora = $video_info_atual['num_avaliacoes'];
        $status_final_a_definir_no_video = null;
        $_SESSION['info'] = ''; // Limpar mensagem de info

        // ESTA É A LÓGICA DE DECISÃO CORRETA E COMPLETA
        if ($status_video_antes_desta_logica === 'reavaliar') {
            // Vídeo JÁ ESTAVA em 'reavaliar'. A avaliação atual é a 3ª.
            if ($num_avaliacoes_agora == 3) {
                $terceiro_parecer = $avaliacao['parecer']; // Parecer da avaliação atual (a 3ª)

                if ($terceiro_parecer === 'aprovado' || $terceiro_parecer === 'aprovado_classificado') {
                    $status_final_a_definir_no_video = 'aprovado';
                    $_SESSION['info'] = "Reavaliação (3º parecer) concluída. Vídeo APROVADO.";
                } elseif ($terceiro_parecer === 'reprovado') {
                    $status_final_a_definir_no_video = 'reprovado';
                    $_SESSION['info'] = "Reavaliação (3º parecer) concluída. Vídeo REPROVADO.";
                } elseif ($terceiro_parecer === 'correcao') {
                    $status_final_a_definir_no_video = 'correcao';
                    $_SESSION['info'] = "Reavaliação (3º parecer) concluída. Vídeo enviado para CORREÇÃO.";
                } else {
                    $status_final_a_definir_no_video = 'correcao'; // Fallback
                    $_SESSION['info'] = "Reavaliação (3º parecer) com parecer não padrão (".htmlspecialchars($terceiro_parecer)."). Enviado para análise administrativa.";
                }
            }
            // Não são necessárias outras condições para $num_avaliacoes_agora aqui,
            // pois a query SQL para selecionar vídeos 'reavaliar' agora limita a < 3 avaliações.
        } elseif ($num_avaliacoes_agora >= 2) {
            // Vídeo NÃO estava em 'reavaliar' e agora tem 2 ou mais avaliações (normalmente, exatamente 2 neste ponto).
            // Verifica divergência inicial ou consenso entre as duas primeiras avaliações.
            $stmt_todas_avaliacoes = $pdo->prepare("SELECT parecer FROM avaliacoes WHERE id_video = :id_video");
            $stmt_todas_avaliacoes->execute([':id_video' => $id_video]);
            $lista_pareceres_video = $stmt_todas_avaliacoes->fetchAll(PDO::FETCH_COLUMN);
            
            $pareceres_normalizados = [];
            foreach ($lista_pareceres_video as $p) {
                if ($p === 'aprovado' || $p === 'aprovado_classificado') $pareceres_normalizados[] = 'aprovado_geral';
                elseif ($p === 'reprovado') $pareceres_normalizados[] = 'reprovado_geral';
                else $pareceres_normalizados[] = $p; // 'correcao' ou outros
            }
            $pareceres_unicos_normalizados = array_unique($pareceres_normalizados);

            if (in_array('aprovado_geral', $pareceres_unicos_normalizados) && in_array('reprovado_geral', $pareceres_unicos_normalizados)) {
                // Divergência encontrada entre as duas primeiras avaliações
                $status_final_a_definir_no_video = 'reavaliar';
                $_SESSION['info'] = "Divergência de pareceres. Vídeo enviado para reavaliação (necessitará de 3º parecer).";
            } else {
                // Não houve divergência clara (ex: ambos aprovados, ambos reprovados, ou um era 'correcao')
                // Verifica se há consenso de pelo menos 2 pareceres (o que será verdade se ambos foram iguais)
                $contagem_parecer_atual = 0; // $avaliacao['parecer'] é o parecer da avaliação recém-submetida (a 2ª)
                $parecer_atual_normalizado = (in_array($avaliacao['parecer'], ['aprovado', 'aprovado_classificado'])) ? 'aprovado_geral' : (($avaliacao['parecer'] === 'reprovado') ? 'reprovado_geral' : $avaliacao['parecer']);
                
                foreach($pareceres_normalizados as $p_norm) {
                    if ($p_norm === $parecer_atual_normalizado) $contagem_parecer_atual++;
                }

                if ($contagem_parecer_atual >= 2) { // Consenso entre as duas primeiras avaliações
                    if ($parecer_atual_normalizado === 'aprovado_geral') $status_final_a_definir_no_video = 'aprovado';
                    elseif ($parecer_atual_normalizado === 'reprovado_geral') $status_final_a_definir_no_video = 'reprovado';
                    elseif ($parecer_atual_normalizado === 'correcao') $status_final_a_definir_no_video = 'correcao';
                    
                    if ($status_final_a_definir_no_video && empty($_SESSION['info'])) { 
                        $_SESSION['info'] = "Status do vídeo atualizado para " . htmlspecialchars($status_final_a_definir_no_video) . ".";
                    }
                } elseif (empty($_SESSION['info'])) { 
                    // Se não houve divergência direta (aprovado vs reprovado) e nem consenso claro de 2 ainda (ex: 1 aprovado, 1 correção)
                    // A mensagem padrão de "aguardando mais avaliações" pode não ser ideal aqui se já temos 2.
                    // Poderia ser, por exemplo, status 'correcao' se um dos pareceres for 'correcao'.
                    // Por ora, mantemos uma mensagem genérica ou ajustamos conforme a regra de negócio para 2 pareceres não divergentes e não consensuais.
                    // Se um for 'correcao', talvez o vídeo deva ir para 'correcao'.
                    if (in_array('correcao', $pareceres_unicos_normalizados)) {
                        $status_final_a_definir_no_video = 'correcao';
                        $_SESSION['info'] = "Uma das avaliações sugere correção. Vídeo marcado para CORREÇÃO.";
                    } else {
                        $_SESSION['info'] = "Avaliações registradas. Sem divergência clara ou consenso imediato."; // Ou outra mensagem
                    }
                }
            }
        } else { 
            // Esta é a primeira avaliação do vídeo.
            $_SESSION['info'] = "Avaliação registrada. Aguardando segunda avaliação.";
        }

        // 4. Atualizar o status do vídeo no banco SE um novo status foi definido
        if ($status_final_a_definir_no_video !== null) {
            $stmt_update_status_video = $pdo->prepare("UPDATE videos SET status = :status WHERE id = :id_video");
            $stmt_update_status_video->execute([':status' => $status_final_a_definir_no_video, ':id_video' => $id_video]);
        }

        $pdo->commit();
        $_SESSION['success'] = "Avaliação enviada com sucesso! " . ($_SESSION['info'] ?? '');
        unset($_SESSION['info']); // Limpa a info para a próxima
        header("Location: index.php"); 
        exit();

    } catch (PDOException $e) { // O ERRO ESTÁ NESSA LINHA
        $pdo->rollBack(); 
        $_SESSION['error'] = "Erro ao enviar avaliação: " . $e->getMessage(); 
    }
}

// Se um vídeo específico foi selecionado para avaliação
$video_atual = null;
if (isset($_GET['avaliar'])) {
    if ($_SESSION['avaliacoes_restantes'] <= 0 && $limite_global_avaliacoes != 0) {
        $_SESSION['error'] = "Você atingiu seu limite de avaliações e não pode iniciar uma nova avaliação.";
    } else {
        try {
            $video_id_get = intval($_GET['avaliar']); // Renomear para evitar conflito com $id_video do POST
            $stmt_video_atual = $pdo->prepare("SELECT * FROM videos WHERE id = :id");
            $stmt_video_atual->execute(['id' => $video_id_get]);
            $video_atual = $stmt_video_atual->fetch(PDO::FETCH_ASSOC);
            if (!$video_atual) {
                 $_SESSION['error'] = "Vídeo não encontrado.";
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Erro ao buscar vídeo: " . $e->getMessage();
            $video_atual = null;
        }
    }
}
// Função para extrair o ID do vídeo do YouTube de qualquer formato de URL
function extrairIdYouTube($url) {
    $padrao = '%
        (?:youtu\.be/|youtube\.com/
        (?:embed/|v/|watch\?v=|watch\?.+&v=))
        ([^"&?/\s]{11})
    %xi';
    
    if (preg_match($padrao, $url, $matches)) {
        return $matches[1]; // Retorna o ID de 11 caracteres
    }
    return null; // Retorna null se não encontrar
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel do Avaliador - Festival de Vídeos</title>
    <!-- CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../includes/estilo.css?v=<?php echo time(); ?>">
    <style>
        .video-container {
            position: relative;
            padding-bottom: 56.25%; /* 16:9 */
            height: 0;
            overflow: hidden;
            margin-bottom: 20px;
        }
        .video-container iframe {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }
        .criteria-box {
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .criteria-title {
            font-weight: bold;
            margin-bottom: 10px;
        }
        .form-check-label {
            cursor: pointer;
        }
        .yt a.text-white {
            color: white !important;
            text-decoration: underline;
        }
        .yt a.text-white:hover {
            color: white !important;
            opacity: 0.8;
            text-decoration: underline;
        }
    </style>
</head>
<body>
<?php include '../includes/header.php'; ?>
<div class="content-wrapper">
    <div class="container-fluid flex-grow-1 mt-0">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-2 d-none d-md-block bg-light sidebar">
                <div class="sidebar-sticky">
                    <ul class="nav flex-column">
                        <!--li class="nav-item">
                            <a class="nav-link active" href="">
                                <i class="fas fa-user mr-2"></i> User Teste
                            </a>
                        </li!-->
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link active" href="../Regulamento_FVDEM_2025.pdf" target="_blank" rel="noopener noreferrer">
                                <i class="bi bi-file-earmark-pdf"></i> Regulamento
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php">
                                <i class="fas fa-video mr-2"></i> Atualizar Lista
                            </a>
                    </ul>
                </div>
            </nav>

            <!-- Main Content -->
            <main role="main" class="col-md-9 ml-sm-auto col-lg-10 px-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Avaliação de Vídeos</h1>
                </div>

                <div class="alert alert-info">
                    Você já realizou: <strong><?php echo htmlspecialchars($_SESSION['avaliacoes_realizadas']); ?></strong> avaliação(ões).<br>
                    Seu limite de avaliações é: <strong><?php echo htmlspecialchars($_SESSION['limite_global_avaliacoes']); ?></strong>.<br>
                    <?php if ($_SESSION['avaliacoes_restantes'] > 0): ?>
                        Você ainda pode avaliar: <strong><?php echo htmlspecialchars($_SESSION['avaliacoes_restantes']); ?></strong> vídeo(s).
                    <?php else: ?>
                        <strong>Você atingiu o seu limite de avaliações. Obrigado pela sua contribuição!</strong>
                    <?php endif; ?>
                </div>

                <!-- Mensagens de Sucesso/Erro -->
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($_SESSION['success']) ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php elseif (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($_SESSION['error']) ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>


                <?php if (isset($video_atual)): ?>
                    <!-- Formulário de Avaliação para um vídeo específico -->
                    <div class="card mb-4">
                        <div class="card-header card-header-custom-purple text-white yt">
                            <h5 class="mb-0">Avaliando: 
                                <?= htmlspecialchars($video_atual['titulo']) ?> - 
                                <a href="<?= htmlspecialchars($video_atual['link_youtube']) ?>" class="text-white" target="_blank" rel="noopener noreferrer">
                                    <?= htmlspecialchars($video_atual['link_youtube']) ?>
                                </a>
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="video-container">
                                <iframe  width="420" height="315"
                                    src="https://www.youtube.com/embed/<?= extrairIdYouTube($video_atual['link_youtube']) ?>" 
                                    title="Vídeo do YouTube"
                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                                    allowfullscreen>
                                </iframe>
                            </div>
                            <form action="index.php" method="post"> <!-- AQUI ESTAVA dashboard.php -->
                                <input type="hidden" name="id_video" value="<?= $video_atual['id'] ?>">
                                
                                <!-- Critérios de Avaliação -->
                                <div class="criteria-box">
                                    <div class="criteria-title">1. Conteúdo Matemático</div>
                                    <div class="form-check">
                                        <input class="form-check-input criteria-radio" type="radio" id="conceitos_corretos_sim" name="conceitos_matematicos_status" value="1" data-comment-id="comentario_conceitos">
                                        <label class="form-check-label" for="conceitos_corretos_sim">
                                            Os conceitos matemáticos apresentados estão corretos
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input criteria-radio" type="radio" id="conceitos_corretos_nao" name="conceitos_matematicos_status" value="0" data-comment-id="comentario_conceitos"> 
                                        <label class="form-check-label" for="conceitos_corretos_nao">
                                            Os conceitos matemáticos apresentados estão INCORRETOS
                                        </label>
                                    </div>
                                    <div class="form-group mt-2">
                                        <label for="comentario_conceitos">Comentários (obrigatório se incorreto):</label>
                                        <textarea class="form-control" id="comentario_conceitos" name="comentario_conceitos" rows="2" placeholder="Escreva aqui caso não cumpra o critério"></textarea>
                                    </div>
                                </div>

                                <div class="criteria-box">
                                    <div class="criteria-title">2. Tempo</div>
                                    <div class="form-check">
                                        <input class="form-check-input criteria-radio" type="radio" id="tempo_respeitado_sim" name="tempo_status" value="1" data-comment-id="comentario_tempo">
                                        <label class="form-check-label" for="tempo_respeitado_sim">
                                            O vídeo respeita o tempo máximo estabelecido
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input criteria-radio" type="radio" id="tempo_respeitado_nao" name="tempo_status" value="0" data-comment-id="comentario_tempo">
                                        <label class="form-check-label" for="tempo_respeitado_nao">
                                            O vídeo NÃO respeita o tempo máximo estabelecido
                                        </label>
                                    </div>
                                    <div class="form-group mt-2">
                                        <label for="comentario_tempo">Comentários (obrigatório se não respeitado):</label>
                                        <textarea class="form-control" id="comentario_tempo" name="comentario_tempo" rows="2" placeholder="Escreva aqui caso não cumpra o critério"></textarea>
                                    </div>
                                </div>

                                <!-- Adicione os outros critérios seguindo o mesmo padrão -->
                                <div class="criteria-box">
                                    <div class="criteria-title">3. Elementos Obrigatórios</div>

                                    <h6 class="mt-3 mb-2">Título:</h6>
                                    <div class="form-check">
                                        <input class="form-check-input criteria-radio" type="radio" id="possui_titulo_sim" name="titulo_status" value="1" data-comment-id="comentario_titulo">
                                        <label class="form-check-label" for="possui_titulo_sim">
                                            O vídeo possui título adequado
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input criteria-radio" type="radio" id="possui_titulo_nao" name="titulo_status" value="0" data-comment-id="comentario_titulo">
                                        <label class="form-check-label" for="possui_titulo_nao">
                                            O vídeo NÃO possui título adequado
                                        </label>
                                    </div>
                                    <div class="form-group mt-2">
                                        <label for="comentario_titulo">Comentários sobre o título (obrigatório se não adequado):</label>
                                        <textarea class="form-control" id="comentario_titulo" name="comentario_titulo" rows="2" placeholder="Escreva aqui caso não cumpra o critério do título"></textarea>
                                    </div>

                                    <h6 class="mt-4 mb-2">Créditos:</h6>
                                    <div class="form-check">
                                        <input class="form-check-input criteria-radio" type="radio" id="possui_creditos_sim" name="creditos_status" value="1" data-comment-id="comentario_creditos">
                                        <label class="form-check-label" for="possui_creditos_sim">
                                            O vídeo possui créditos dos participantes
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input criteria-radio" type="radio" id="possui_creditos_nao" name="creditos_status" value="0" data-comment-id="comentario_creditos">
                                        <label class="form-check-label" for="possui_creditos_nao">
                                            O vídeo NÃO possui créditos dos participantes
                                        </label>
                                    </div>
                                    <div class="form-group mt-2">
                                        <label for="comentario_creditos">Comentários sobre os créditos (obrigatório se não possuir):</label>
                                        <textarea class="form-control" id="comentario_creditos" name="comentario_creditos" rows="2" placeholder="Escreva aqui caso não cumpra o critério dos créditos"></textarea>
                                    </div>
                                </div>

                                <div class="criteria-box">
                                    <div class="criteria-title">4. Qualidade do Discurso</div>

                                    <h6 class="mt-3 mb-2">Discurso/Narração:</h6>
                                    <div class="form-check">
                                        <input class="form-check-input criteria-radio" type="radio" id="discurso_adequado_sim" name="discurso_status" value="1" data-comment-id="comentario_discurso">
                                        <label class="form-check-label" for="discurso_adequado_sim">
                                            O discurso/narração é adequado e claro
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input criteria-radio" type="radio" id="discurso_adequado_nao" name="discurso_status" value="0" data-comment-id="comentario_discurso">
                                        <label class="form-check-label" for="discurso_adequado_nao">
                                            O discurso/narração NÃO é adequado e claro
                                        </label>
                                    </div>
                                    <div class="form-group mt-2">
                                        <label for="comentario_discurso">Comentários sobre o discurso/narração (obrigatório se não adequado/claro):</label>
                                        <textarea class="form-control" id="comentario_discurso" name="comentario_discurso" rows="2" placeholder="Escreva aqui caso não cumpra o critério do discurso"></textarea>
                                    </div>

                                    <h6 class="mt-4 mb-2">Uso do Português:</h6>
                                    <div class="form-check">
                                        <input class="form-check-input criteria-radio" type="radio" id="portugues_correto_sim" name="portugues_status" value="1" data-comment-id="comentario_portugues">
                                        <label class="form-check-label" for="portugues_correto_sim">
                                            O português está correto (gramática e pronúncia)
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input criteria-radio" type="radio" id="portugues_correto_nao" name="portugues_status" value="0" data-comment-id="comentario_portugues">
                                        <label class="form-check-label" for="portugues_correto_nao">
                                            O português NÃO está correto (gramática e pronúncia)
                                        </label>
                                    </div>
                                    <div class="form-group mt-2">
                                        <label for="comentario_portugues">Comentários sobre o português (obrigatório se incorreto):</label>
                                        <textarea class="form-control" id="comentario_portugues" name="comentario_portugues" rows="2" placeholder="Escreva aqui caso não cumpra o critério do português"></textarea>
                                    </div>
                                </div>

                                <div class="criteria-box">
                                    <div class="criteria-title">5. Qualidade Técnica</div>

                                    <h6 class="mt-3 mb-2">Qualidade do Áudio:</h6>
                                    <div class="form-check">
                                        <input class="form-check-input criteria-radio" type="radio" id="audio_qualidade_sim" name="audio_status" value="1" data-comment-id="comentario_audio">
                                        <label class="form-check-label" for="audio_qualidade_sim">
                                            O áudio está claro e com boa qualidade
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input criteria-radio" type="radio" id="audio_qualidade_nao" name="audio_status" value="0" data-comment-id="comentario_audio">
                                        <label class="form-check-label" for="audio_qualidade_nao">
                                            O áudio NÃO está claro e com boa qualidade
                                        </label>
                                    </div>
                                    <div class="form-group mt-2">
                                        <label for="comentario_audio">Comentários sobre o áudio (obrigatório se não estiver com boa qualidade):</label>
                                        <textarea class="form-control" id="comentario_audio" name="comentario_audio" rows="2" placeholder="Escreva aqui caso não cumpra o critério do áudio"></textarea>
                                    </div>

                                    <h6 class="mt-4 mb-2">Qualidade da Imagem:</h6>
                                    <div class="form-check">
                                        <input class="form-check-input criteria-radio" type="radio" id="imagem_qualidade_sim" name="imagem_status" value="1" data-comment-id="comentario_imagem">
                                        <label class="form-check-label" for="imagem_qualidade_sim">
                                            A imagem está nítida e com boa qualidade
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input criteria-radio" type="radio" id="imagem_qualidade_nao" name="imagem_status" value="0" data-comment-id="comentario_imagem">
                                        <label class="form-check-label" for="imagem_qualidade_nao">
                                            A imagem NÃO está nítida e com boa qualidade
                                        </label>
                                    </div>
                                    <div class="form-group mt-2">
                                        <label for="comentario_imagem">Comentários sobre a imagem (obrigatório se não estiver com boa qualidade):</label>
                                        <textarea class="form-control" id="comentario_imagem" name="comentario_imagem" rows="2" placeholder="Escreva aqui caso não cumpra o critério da imagem"></textarea>
                                    </div>

                                    <h6 class="mt-4 mb-2">Edição:</h6>
                                    <div class="form-check">
                                        <input class="form-check-input criteria-radio" type="radio" id="edicao_correta_sim" name="edicao_status" value="1" data-comment-id="comentario_edicao">
                                        <label class="form-check-label" for="edicao_correta_sim">
                                            A edição está bem feita (transições, cortes, etc.)
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input criteria-radio" type="radio" id="edicao_correta_nao" name="edicao_status" value="0" data-comment-id="comentario_edicao">
                                        <label class="form-check-label" for="edicao_correta_nao">
                                            A edição NÃO está bem feita (transições, cortes, etc.)
                                        </label>
                                    </div>
                                    <div class="form-group mt-2">
                                        <label for="comentario_edicao">Comentários sobre a edição (obrigatório se não estiver bem feita):</label>
                                        <textarea class="form-control" id="comentario_edicao" name="comentario_edicao" rows="2" placeholder="Escreva aqui caso não cumpra o critério da edição"></textarea>
                                    </div>
                                </div>
                                
                                <div class="criteria-box">
                                    <div class="criteria-title">Parecer Final</div>
                                    <div class="form-group">
                                        <label for="parecer">Decisão:</label>
                                        <select class="form-control" id="parecer" name="parecer" required>
                                            <option value="">Selecione...</option>
                                            <option value="aprovado">Aprovado</option>
                                            <option value="aprovado_classificado">Aprovado e sugerido como finalista</option>
                                            <option value="reprovado">Reprovado</option>
                                            <option value="correcao">Necessita correções</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="justificativa">Justificativa:</label>
                                        <textarea class="form-control" id="justificativa" name="justificativa" rows="3" required></textarea>
                                    </div>
                                </div>

                                <button type="submit" name="avaliar" class="btn btn-primary btn-lg">Enviar Avaliação</button>
                                <a href="index.php" class="btn btn-secondary btn-lg">Cancelar</a>    <!-- AQUI ESTAVA dashboard.php -->
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Lista de Vídeos Pendentes -->
                    <div class="card">
                        <div class="card-header card-header-custom-purple text-white">
                            <h5 class="mb-0">Vídeos Pendentes de Avaliação</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($videos_pendentes)): ?>
                                <div class="list-group">
                                    <?php foreach ($videos_pendentes as $video): ?>
                                        <a href="?avaliar=<?= $video['id'] ?>&ano=2025" class="list-group-item list-group-item-action">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo htmlspecialchars($video['titulo']); ?></h6>
                                                <small><?php echo $video['created_at']; ?></small>
                                            </div>
                                            <p class="mb-1">Tema: <?= htmlspecialchars($video['tema']) ?></p>
                                            <small>Categoria: <?= htmlspecialchars($video['categoria']) ?></small>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info">
                                    Nenhum vídeo pendente de avaliação no momento.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    <!-- JavaScript -->
    <script>
    // Controlar campos de comentário baseados na seleção do radio
    document.querySelectorAll('.criteria-radio').forEach(radio => {
        radio.addEventListener('change', function() {
            const commentId = this.dataset.commentId; // Pega o ID do textarea do atributo data-comment-id
            const commentField = document.getElementById(commentId);

            if (commentField) {
                if (this.value === '1') { // Se a opção "SIM" (correto) for marcada
                    commentField.disabled = true;
                    commentField.required = false; // Não é obrigatório se está correto
                    commentField.value = ''; // Opcional: Limpa o campo de comentário
                    commentField.placeholder = "Não é necessário comentar se o critério foi cumprido.";
                } else { // Se a opção "NÃO" (incorreto) for marcada (value === '0')
                    commentField.disabled = false;
                    commentField.required = true; // Obrigatório se está incorreto
                    commentField.placeholder = "Escreva aqui o motivo pelo qual não cumpre o critério.";
                }
            }
        });

        // Disparar o evento 'change' inicialmente para configurar os campos na carga da página
        // Isso garante que os textareas com radios "NÃO" marcados por padrão estejam habilitados
        if (radio.checked) {
            radio.dispatchEvent(new Event('change'));
        }
    });
    </script>

<script>
// Função para converter duração ISO 8601 para segundos
function convertYouTubeDuration(duration) {
    const match = duration.match(/PT(\d+H)?(\d+M)?(\d+S)?/);
    const hours = (parseInt(match[1]) || 0);
    const minutes = (parseInt(match[2]) || 0);
    const seconds = (parseInt(match[3]) || 0);
    return hours * 3600 + minutes * 60 + seconds;
}

// Função principal que verifica a duração
async function checkVideoDuration(videoId, apiKey) {
    try {
        const response = await fetch(`https://www.googleapis.com/youtube/v3/videos?id=${videoId}&part=contentDetails&key=${apiKey}`);
        const data = await response.json();
        
        if (data.items && data.items.length > 0) {
            const duration = data.items[0].contentDetails.duration;
            const totalSeconds = convertYouTubeDuration(duration);
            const totalMinutes = Math.floor(totalSeconds / 60); // Arredonda para 0 decimal
            const segundosRestantes = totalSeconds - Math.floor(totalSeconds / 60)*60;
            
            if (totalSeconds > 360) { // 6 minutos = 360 segundos
                // Marca o rádio "NÃO respeita"
                document.getElementById('tempo_respeitado_nao').checked = true;
                
                // Preenche o comentário automaticamente
                document.getElementById('comentario_tempo').value = 
                    `O tempo total é de ${totalMinutes} minutos e ${segundosRestantes} segundos (limite: 6 minutos)`;
                
                // Dispara evento para validação (se necessário)
                document.querySelector('input[name="tempo_status"]:checked').dispatchEvent(new Event('change'));
            } else {
                document.getElementById('tempo_respeitado_sim').checked = true;
            }
        }
    } catch (error) {
        console.error('Erro ao verificar duração:', error);
        // Pode adicionar tratamento de erro visual aqui
    }
}

// Exemplo de uso (substitua com seus valores reais)
const videoId = '<?= extrairIdYouTube($video_atual['link_youtube']) ?>'; // ID do vídeo ou variável PHP
const apiKey = '<?= $apikey_youtube ?>'; // Sua chave de API youtube

// Chama a função quando a página carregar
document.addEventListener('DOMContentLoaded', function() {
    checkVideoDuration(videoId, apiKey);
});
</script>

</div>
    <!-- Footer -->
    <?php include '../includes/footer.php'; ?>
</body>
</html>