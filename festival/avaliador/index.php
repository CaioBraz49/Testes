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

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die("Erro: Conexão com o banco de dados não estabelecida");
}

$user_id = $_SESSION['user_id'];
$sql = "SELECT categoria FROM users WHERE id = :id";
$stmt = $pdo->prepare($sql);
$stmt->execute([':id' => $user_id]);
$user = $stmt->fetch();

// Buscar vídeos pendentes de avaliação

$sql_videos = "
    SELECT v.*
    FROM videos v
    WHERE v.status = 'pendente'
    AND v.id NOT IN (
        SELECT id_video 
        FROM avaliacoes 
        WHERE id_user = ?
    )
    AND (
        SELECT COUNT(*) 
        FROM avaliacoes 
        WHERE id_video = v.id
    ) <= 1
    ORDER BY v.created_at ASC
";

$stmt_videos = $pdo->prepare($sql_videos);
$stmt_videos->execute([$_SESSION['user_id']]);

$videos_pendentes = $stmt_videos->fetchAll(PDO::FETCH_ASSOC) ?: []; // Retorna array vazio se falhar

$user_id = $_SESSION['user_id'];

// 1. Buscar as categorias do usuário logado
$sql_user = "SELECT categoria FROM users WHERE id = :id";
$stmt_user = $pdo->prepare($sql_user);
$stmt_user->execute([':id' => $user_id]);
$user = $stmt_user->fetch();

if (!$user || empty($user['categoria'])) {
    die("
            Nenhuma categoria encontrada para este usuário
            <script>
            // Exibe a mensagem
            alert('Nenhuma categoria definida para este usuário. Se isso for um erro comunique-se com o administrador.');
            
            // Espera 10 segundos (10000 milissegundos)
            setTimeout(function() {
                // Redireciona para logout.php
                window.location.href = '../includes/logout.php';
            }, 5000);
        </script>
    ");
    
}

// 2. Mapear códigos para nomes completos
$mapa_categorias = [
    'comunidade_geral' => 'Comunidade em Geral',
    'professores_acao' => 'Professores em Ação',
    'ensino_fundamental' => 'Ensino Fundamental',
    'ensino_medio' => 'Ensino Médio',
    'graduandos_matematica' => 'Graduandos em Matemática',
    'povos_originarios' => 'Povos Originários'
];

// Converter códigos para nomes completos
$categorias_convertidas = [];
$categorias_codigo = explode(',', $user['categoria']);

foreach ($categorias_codigo as $codigo) {
    $codigo = trim($codigo);
    if (isset($mapa_categorias[$codigo])) {
        $categorias_convertidas[] = $mapa_categorias[$codigo];
    }
}

// 3. Se não houver categorias válidas, encerrar
if (empty($categorias_convertidas)) {
    die("Nenhuma categoria válida encontrada para este usuário");
}

// 4. Buscar vídeos que correspondam às categorias convertidas
$placeholders = implode(',', array_fill(0, count($categorias_convertidas), '?'));

$sql_videos = "
    SELECT v.*
    FROM videos v
    WHERE v.status = 'pendente'
    AND v.categoria IN ($placeholders)
    AND v.id NOT IN (
        SELECT id_video 
        FROM avaliacoes 
        WHERE id_user = ?
    )
    AND (
        SELECT COUNT(*) 
        FROM avaliacoes 
        WHERE id_video = v.id
    ) <= 1
    ORDER BY v.created_at ASC
";

$stmt_videos = $pdo->prepare($sql_videos);

// Combinar parâmetros (categorias primeiro, depois user_id)
$params = array_merge($categorias_convertidas, [$user_id]);
$stmt_videos->execute($params);

$videos_pendentes = $stmt_videos->fetchAll(PDO::FETCH_ASSOC);

// Processar avaliação se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['avaliar'])) {
    $id_video = intval($_POST['id_video']);
    $id_user = $_SESSION['user_id'];

    $avaliacao = [
        'id_user' => $id_user,
        'id_video' => $id_video,
        'conceitos_corretos' => isset($_POST['conceitos_corretos']) ? 1 : 0,
        'comentario_conceitos' => $_POST['comentario_conceitos'] ?? '',
        'tempo_respeitado' => isset($_POST['tempo_respeitado']) ? 1 : 0,
        'comentario_tempo' => $_POST['comentario_tempo'] ?? '',
        'possui_titulo' => isset($_POST['possui_titulo']) ? 1 : 0,
        'comentario_titulo' => $_POST['comentario_titulo'] ?? '',
        'possui_creditos' => isset($_POST['possui_creditos']) ? 1 : 0,
        'comentario_creditos' => $_POST['comentario_creditos'] ?? '',
        'discurso_adequado' => isset($_POST['discurso_adequado']) ? 1 : 0,
        'comentario_discurso' => $_POST['comentario_discurso'] ?? '',
        'audio_qualidade' => isset($_POST['audio_qualidade']) ? 1 : 0,
        'comentario_audio' => $_POST['comentario_audio'] ?? '',
        'imagem_qualidade' => isset($_POST['imagem_qualidade']) ? 1 : 0,
        'comentario_imagem' => $_POST['comentario_imagem'] ?? '',
        'edicao_correta' => isset($_POST['edicao_correta']) ? 1 : 0,
        'comentario_edicao' => $_POST['comentario_edicao'] ?? '',
        'portugues_correto' => isset($_POST['portugues_correto']) ? 1 : 0,
        'comentario_portugues' => $_POST['comentario_portugues'] ?? '',
        'parecer' => $_POST['parecer'],
        'justificativa' => $_POST['justificativa'] ?? ''
    ];

    try {
        // Inicia transação
        $pdo->beginTransaction();

        // Inserir avaliação
        $sql_avaliacao = "INSERT INTO avaliacoes (
            id_user, id_video, conceitos_corretos, comentario_conceitos, tempo_respeitado, comentario_tempo,
            possui_titulo, comentario_titulo, possui_creditos, comentario_creditos, discurso_adequado,
            comentario_discurso, audio_qualidade, comentario_audio, imagem_qualidade, comentario_imagem,
            edicao_correta, comentario_edicao, portugues_correto, comentario_portugues, parecer, justificativa
        ) VALUES (
            :id_user, :id_video, :conceitos_corretos, :comentario_conceitos, :tempo_respeitado, :comentario_tempo,
            :possui_titulo, :comentario_titulo, :possui_creditos, :comentario_creditos, :discurso_adequado,
            :comentario_discurso, :audio_qualidade, :comentario_audio, :imagem_qualidade, :comentario_imagem,
            :edicao_correta, :comentario_edicao, :portugues_correto, :comentario_portugues, :parecer, :justificativa
        )";

        $stmt = $pdo->prepare($sql_avaliacao);
        $stmt->execute($avaliacao);

        // Atualizar status do vídeo
        //$novo_status = ($avaliacao['parecer'] === 'aprovado') ? 'avaliado' : 'correcao';
        //$stmt_status = $pdo->prepare("UPDATE videos SET status = :status WHERE id = :id");
        //$stmt_status->execute(['status' => $novo_status, 'id' => $id_video]);

        // Atualizar status do vídeo apenas se já existir avaliação com o mesmo parecer
        $novo_status = ($avaliacao['parecer'] === 'aprovado') ? 'avaliado' : 'correcao';

        // Verifica se existe alguma avaliação com o mesmo parecer para este vídeo
        $sql_verifica_parecer = "SELECT COUNT(*) as total 
                                FROM avaliacoes 
                                WHERE id_video = :id_video 
                                AND parecer = :parecer
                                AND id_user != :user_id"; // Exclui a própria avaliação atual

        $stmt_verifica = $pdo->prepare($sql_verifica_parecer);
        $stmt_verifica->execute([
            'id_video' => $id_video,
            'parecer' => $avaliacao['parecer'],
            'user_id' => $_SESSION['user_id']
        ]);
        $resultado = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

        // Só atualiza o status se já existir outra avaliação com o mesmo parecer
        if ($resultado['total'] > 0) {
            $stmt_status = $pdo->prepare("UPDATE videos SET status = :status WHERE id = :id");
            $stmt_status->execute(['status' => $novo_status, 'id' => $id_video]);
            
            $_SESSION['info'] = "Status do vídeo atualizado para " . $novo_status;
        } else {
            $_SESSION['info'] = "Aguardando outra avaliação com o mesmo parecer para atualizar status";
        }

        // Commit da transação
        $pdo->commit();

        $_SESSION['success'] = "Avaliação enviada com sucesso!";
        header("Location: dashboard.php");
        exit();

    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Erro ao enviar avaliação: " . $e->getMessage();
    }
}

// Se um vídeo específico foi selecionado para avaliação
$video_atual = null;
if (isset($_GET['avaliar'])) {
    try {
        $video_id = intval($_GET['avaliar']);
        $stmt = $pdo->prepare("SELECT * FROM videos WHERE id = :id");
        $stmt->execute(['id' => $video_id]);
        $video_atual = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("Erro ao buscar vídeo: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel do Avaliador - Festival de Vídeos</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
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
                            <a class="nav-link active" href="index.php">
                                <i class="fas fa-home mr-2"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="">
                                <i class="fas fa-video mr-2"></i> Atualizar Vídeo
                            </a>
                    </ul>
                </div>
            </nav>

            <!-- Main Content -->
            <main role="main" class="col-md-9 ml-sm-auto col-lg-10 px-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Avaliação de Vídeos</h1>
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
                        <div class="card-header bg-primary text-white yt">
                            <h5 class="mb-0">Avaliando: 
                                <?= htmlspecialchars($video_atual['titulo']) ?> - 
                                <a href="<?= htmlspecialchars($video_atual['link_youtube']) ?>" class="text-white" target="_blank" rel="noopener noreferrer">
                                    <?= htmlspecialchars($video_atual['link_youtube']) ?>
                                </a>
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="video-container">
                                <?php
                                // Função para converter URL padrão do YouTube em URL embed
                                function convertToEmbedUrl($url) {
                                    return str_replace('watch?v=', 'embed/', $url);
                                }
                                ?>

                                <iframe width="420" height="315"
                                    src="<?= htmlspecialchars(convertToEmbedUrl($video_atual['link_youtube'])) ?>">
                                </iframe>

                            </div>

                            <form action="dashboard.php" method="post">
                                <input type="hidden" name="id_video" value="<?= $video_atual['id'] ?>">
                                
                                <!-- Critérios de Avaliação -->
                                <div class="criteria-box">
                                    <div class="criteria-title">1. Conteúdo Matemático</div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="conceitos_corretos" name="conceitos_corretos" value="1">
                                        <label class="form-check-label" for="conceitos_corretos">
                                            Os conceitos matemáticos apresentados estão corretos
                                        </label>
                                    </div>
                                    <div class="form-group mt-2">
                                        <label for="comentario_conceitos">Comentários:</label>
                                        <textarea class="form-control" id="comentario_conceitos" name="comentario_conceitos" rows="2"></textarea>
                                    </div>
                                </div>

                                <div class="criteria-box">
                                    <div class="criteria-title">2. Tempo</div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="tempo_respeitado" name="tempo_respeitado" value="1">
                                        <label class="form-check-label" for="tempo_respeitado">
                                            O vídeo respeita o tempo máximo estabelecido
                                        </label>
                                    </div>
                                    <div class="form-group mt-2">
                                        <label for="comentario_tempo">Comentários:</label>
                                        <textarea class="form-control" id="comentario_tempo" name="comentario_tempo" rows="2"></textarea>
                                    </div>
                                </div>

                                <!-- Adicione os outros critérios seguindo o mesmo padrão -->
                                <div class="criteria-box">
                                    <div class="criteria-title">3. Elementos Obrigatórios</div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="possui_titulo" name="possui_titulo" value="1">
                                        <label class="form-check-label" for="possui_titulo">
                                            O vídeo possui título adequado
                                        </label>
                                    </div>
                                    <div class="form-group mt-2">
                                        <label for="comentario_titulo">Comentários sobre o título:</label>
                                        <textarea class="form-control" id="comentario_titulo" name="comentario_titulo" rows="2"></textarea>
                                    </div>

                                    <div class="form-check mt-3">
                                        <input class="form-check-input" type="checkbox" id="possui_creditos" name="possui_creditos" value="1">
                                        <label class="form-check-label" for="possui_creditos">
                                            O vídeo possui créditos dos participantes
                                        </label>
                                    </div>
                                    <div class="form-group mt-2">
                                        <label for="comentario_creditos">Comentários sobre os créditos:</label>
                                        <textarea class="form-control" id="comentario_creditos" name="comentario_creditos" rows="2"></textarea>
                                    </div>
                                </div>

                                <div class="criteria-box">
                                    <div class="criteria-title">4. Qualidade do Discurso</div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="discurso_adequado" name="discurso_adequado" value="1">
                                        <label class="form-check-label" for="discurso_adequado">
                                            O discurso/narração é adequado e claro
                                        </label>
                                    </div>
                                    <div class="form-group mt-2">
                                        <label for="comentario_discurso">Comentários sobre o discurso:</label>
                                        <textarea class="form-control" id="comentario_discurso" name="comentario_discurso" rows="2"></textarea>
                                    </div>

                                    <div class="form-check mt-3">
                                        <input class="form-check-input" type="checkbox" id="portugues_correto" name="portugues_correto" value="1">
                                        <label class="form-check-label" for="portugues_correto">
                                            O português está correto (gramática e pronúncia)
                                        </label>
                                    </div>
                                    <div class="form-group mt-2">
                                        <label for="comentario_portugues">Comentários sobre o português:</label>
                                        <textarea class="form-control" id="comentario_portugues" name="comentario_portugues" rows="2"></textarea>
                                    </div>
                                </div>

                                <div class="criteria-box">
                                    <div class="criteria-title">5. Qualidade Técnica</div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="audio_qualidade" name="audio_qualidade" value="1">
                                        <label class="form-check-label" for="audio_qualidade">
                                            O áudio está claro e com boa qualidade
                                        </label>
                                    </div>
                                    <div class="form-group mt-2">
                                        <label for="comentario_audio">Comentários sobre o áudio:</label>
                                        <textarea class="form-control" id="comentario_audio" name="comentario_audio" rows="2"></textarea>
                                    </div>

                                    <div class="form-check mt-3">
                                        <input class="form-check-input" type="checkbox" id="imagem_qualidade" name="imagem_qualidade" value="1">
                                        <label class="form-check-label" for="imagem_qualidade">
                                            A imagem está nítida e com boa qualidade
                                        </label>
                                    </div>
                                    <div class="form-group mt-2">
                                        <label for="comentario_imagem">Comentários sobre a imagem:</label>
                                        <textarea class="form-control" id="comentario_imagem" name="comentario_imagem" rows="2"></textarea>
                                    </div>

                                    <div class="form-check mt-3">
                                        <input class="form-check-input" type="checkbox" id="edicao_correta" name="edicao_correta" value="1">
                                        <label class="form-check-label" for="edicao_correta">
                                            A edição está bem feita (transições, cortes, etc.)
                                        </label>
                                    </div>
                                    <div class="form-group mt-2">
                                        <label for="comentario_edicao">Comentários sobre a edição:</label>
                                        <textarea class="form-control" id="comentario_edicao" name="comentario_edicao" rows="2"></textarea>
                                    </div>
                                </div>
                                
                                <div class="criteria-box">
                                    <div class="criteria-title">Parecer Final</div>
                                    <div class="form-group">
                                        <label for="parecer">Decisão:</label>
                                        <select class="form-control" id="parecer" name="parecer" required>
                                            <option value="">Selecione...</option>
                                            <option value="aprovado">Aprovado</option>
                                            <option value="aprovado_classificado">Aprovado e classificado</option>
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
                                <a href="dashboard.php" class="btn btn-secondary btn-lg">Cancelar</a>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Lista de Vídeos Pendentes -->
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">Vídeos Pendentes de Avaliação</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($videos_pendentes)): ?>
                                <div class="list-group">
                                    <?php foreach ($videos_pendentes as $video): ?>
                                        <a href="?avaliar=<?= $video['id'] ?>" class="list-group-item list-group-item-action">
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

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <!--script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script!-->

    <script>
    // Mostrar/ocultar campos de comentário conforme necessário
    document.querySelectorAll('.form-check-input').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const commentId = this.id.replace('_corretos', '').replace('_respeitado', '').replace('_titulo', '')
                                   .replace('_creditos', '').replace('_adequado', '').replace('_correto', '')
                                   .replace('_qualidade', '').replace('_correta', '') + '_comentario';
            const commentField = document.getElementById(commentId);
            if (commentField) {
                commentField.disabled = this.checked;
                commentField.required = !this.checked;
            }
        });
    });
    </script>
</div>
    <!-- Footer -->
    <?php include '../includes/footer.php'; ?>
</body>
</html>