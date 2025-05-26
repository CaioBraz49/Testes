<?php

session_start(); // Removi o comentário que estava impedindo esta linha de funcionar
require_once '../includes/config.php';

if (!isset($_SESSION['user_id'])) { // Adicionei o parêntese que faltava
    die('Acesso não autorizado');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['video_id']) && isset($_POST['avaliador_id'])) {
    $videoId = $_POST['video_id'];
    $avaliadorId = $_POST['avaliador_id'];

    // Busca os detalhes da avaliação
    $stmt = $pdo->prepare("
        SELECT 
            a.*,
            v.titulo AS video_titulo,
            u.nome AS avaliador_nome
        FROM avaliacoes a
        JOIN videos v ON a.id_video = v.id
        JOIN users u ON a.id_user = u.id
        WHERE a.id_video = ? AND a.id_user = ?
    ");
    $stmt->execute([$videoId, $avaliadorId]);
    $avaliacao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$avaliacao) {
        echo '<div class="alert alert-warning">Avaliação não encontrada. '.$_POST['video_id'].' - '.$_POST['avaliador_id'].'</div>';
        exit;
    }

    // Função para formatar campos booleanos
    function formatBoolean($value) {
        return $value ? '<span class="badge badge-success">Sim</span>' : '<span class="badge badge-danger">Não</span>';
    }

    // Exibe os detalhes da avaliação
    echo '<div class="container-fluid">';
    echo '<h6>Vídeo: ' . htmlspecialchars($avaliacao['video_titulo']) . '</h6>';
    echo '<h6>Avaliador: ' . htmlspecialchars($avaliacao['avaliador_nome']) . '</h6>';
    echo '<hr>';
    
    echo '<div class="row">';
    echo '<div class="col-md-6">';
    echo '<h5>Critérios Técnicos</h5>';
    echo '<table class="table table-sm">';
    echo '<tr><th>Conceitos Corretos:</th><td>' . formatBoolean($avaliacao['conceitos_corretos']) . '</td></tr>';
    echo '<tr><th>Tempo Respeitado:</th><td>' . formatBoolean($avaliacao['tempo_respeitado']) . '</td></tr>';
    echo '<tr><th>Possui Título:</th><td>' . formatBoolean($avaliacao['possui_titulo']) . '</td></tr>';
    echo '<tr><th>Possui Créditos:</th><td>' . formatBoolean($avaliacao['possui_creditos']) . '</td></tr>';
    echo '<tr><th>Discurso Adequado:</th><td>' . formatBoolean($avaliacao['discurso_adequado']) . '</td></tr>';
    echo '</table>';
    echo '</div>';
    
    echo '<div class="col-md-6">';
    echo '<h5>Qualidade Técnica</h5>';
    echo '<table class="table table-sm">';
    echo '<tr><th>Áudio de Qualidade:</th><td>' . formatBoolean($avaliacao['audio_qualidade']) . '</td></tr>';
    echo '<tr><th>Imagem de Qualidade:</th><td>' . formatBoolean($avaliacao['imagem_qualidade']) . '</td></tr>';
    echo '<tr><th>Edição Correta:</th><td>' . formatBoolean($avaliacao['edicao_correta']) . '</td></tr>';
    echo '<tr><th>Português Correto:</th><td>' . formatBoolean($avaliacao['portugues_correto']) . '</td></tr>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
    
    echo '<div class="row mt-3">';
    echo '<div class="col-md-12">';
    echo '<h5>Parecer Final</h5>';
    echo '<div class="alert alert-' . ($avaliacao['parecer'] === 'aprovado' ? 'success' : 'warning') . '">';
    echo '<strong>' . ucfirst($avaliacao['parecer']) . '</strong>';
    if (!empty($avaliacao['justificativa'])) {
        echo '<p class="mt-2">' . nl2br(htmlspecialchars($avaliacao['justificativa'])) . '</p>';
    }
    echo '</div>';
    echo '</div>';
    echo '</div>';
    
    echo '<div class="row">';
    echo '<div class="col-md-12">';
    echo '<h5>Comentários Detalhados</h5>';
    echo '<div class="card">';
    echo '<div class="card-body">';
    
    $campos_comentarios = [
    'conceitos' => 'Comentário sobre Conceitos Corretos',
    'tempo' => 'Comentário sobre Tempo Respeitado',
    'titulo' => 'Comentário sobre Título',
    'creditos' => 'Comentário sobre Créditos',
    'discurso' => 'Comentário sobre Discurso Adequado',
    'audio' => 'Comentário sobre Áudio de Qualidade',
    'imagem' => 'Comentário sobre Imagem de Qualidade',
    'edicao' => 'Comentário sobre Edição Correta',
    'portugues' => 'Comentário sobre Português Correto'
];
    
    foreach ($campos_comentarios as $campo => $titulo) {
        if (!empty($avaliacao['comentario_' . $campo])) {
            echo '<h6>' . $titulo . '</h6>';
            echo '<p>' . nl2br(htmlspecialchars($avaliacao['comentario_' . $campo])) . '</p>';
            echo '<hr>';
        }
    }
    
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    
    echo '<div class="row mt-3">';
    echo '<div class="col-md-12 text-muted">';
    echo '<small>Data da avaliação: ' . date('d/m/Y H:i', strtotime($avaliacao['data_avaliacao'])) . '</small>';
    echo '</div>';
    echo '</div>';
    
    echo '</div>';
}
?>