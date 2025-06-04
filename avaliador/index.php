<?php
// Ativar exibição de erros para debug (remover em produção)
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// MODIFICADO: A condição original aqui era para 'avaliador', mas o nome do arquivo é admin/index.php
// Se este arquivo é de fato para AVALIADORES, a condição abaixo está correta.
// Se este arquivo for para ADMINS, a condição deve ser $_SESSION['user_tipo'] !== 'admin'
if (!isset($_SESSION['user_id']) || $_SESSION['user_tipo'] !== 'avaliador') { //
    header('Location: ../index.php');
    exit();
}

require_once '../includes/config.php'; //

if (!isset($pdo) || !($pdo instanceof PDO)) { //
    die("Erro: Conexão com o banco de dados não estabelecida");
}

$user_id = $_SESSION['user_id']; //

// 1. BUSCAR CATEGORIAS ATRIBUÍDAS DO USUÁRIO E NORMALIZAR CHAVES
$sql_user_categorias = "SELECT categoria FROM users WHERE id = :id"; //
$stmt_user_categorias = $pdo->prepare($sql_user_categorias);
$stmt_user_categorias->execute([':id' => $user_id]);
$user_info = $stmt_user_categorias->fetch(); //

if (!$user_info || empty(trim($user_info['categoria'] ?? ''))) { //
    die("
            Nenhuma categoria encontrada para este usuário
            <script>
            alert('Nenhuma categoria definida para este usuário. Se isso for um erro comunique-se com o administrador.');
            setTimeout(function() {
                window.location.href = '../includes/logout.php';
            }, 5000);
        </script>
    ");
}

// Mapa padrão de NOVAS CHAVES para NOVOS NOMES COMPLETOS (usado em todo o script)
$mapa_categorias_atualizado = [ //
    'anos_finais_ef' => 'Anos Finais do Ensino Fundamental',
    'ensino_medio' => 'Ensino Médio',
    'grad_mat_afins' => 'Graduandos em Matemática ou áreas afins',
    'prof_acao' => 'Professores em Ação',
    'povos_orig_trad' => 'Povos Originários e Tradicionais',
    'com_geral' => 'Comunidade em Geral',
];

// Mapa para converter CHAVES ANTIGAS para CHAVES NOVAS
$mapa_chaves_antigas_para_novas = [ //
    'ensino_fundamental'    => 'anos_finais_ef',
    'graduandos_matematica' => 'grad_mat_afins',
    'professores_acao'      => 'prof_acao',      
    'povos_originarios'     => 'povos_orig_trad',
    'comunidade_geral'      => 'com_geral',
];

$chaves_raw_do_banco = explode(',', $user_info['categoria']); //
$categorias_atribuidas_chaves_normalizadas = [];
$processed_keys_for_user_temp = [];

foreach ($chaves_raw_do_banco as $chave_raw) { //
    $chave_atual_trim = trim($chave_raw);
    if (empty($chave_atual_trim)) {
        continue;
    }

    $chave_final_para_uso = $chave_atual_trim; 

    if (isset($mapa_chaves_antigas_para_novas[$chave_atual_trim])) { //
        $chave_final_para_uso = $mapa_chaves_antigas_para_novas[$chave_atual_trim];
    }

    if (isset($mapa_categorias_atualizado[$chave_final_para_uso]) && !in_array($chave_final_para_uso, $processed_keys_for_user_temp)) { //
        $categorias_atribuidas_chaves_normalizadas[] = $chave_final_para_uso;
        $processed_keys_for_user_temp[] = $chave_final_para_uso; 
    }
}
$categorias_atribuidas_chaves = $categorias_atribuidas_chaves_normalizadas; //


// >>> INÍCIO DA MODIFICAÇÃO SUGERIDA <<<
// Gerar lista de NOMES COMPLETOS (NOVOS e ANTIGOS correspondentes) para a consulta SQL
$categorias_para_sql_query = [];

// Mapa de nomes de categoria NOVOS para os ANTIGOS correspondentes (para a consulta SQL)
// Estes são os NOMES DE EXIBIÇÃO COMPLETOS
$mapa_nomes_novos_para_antigos_sql = [
    'Anos Finais do Ensino Fundamental' => 'Ensino Fundamental',
    'Graduandos em Matemática ou áreas afins' => 'Graduandos em Matemática',
    'Povos Originários e Tradicionais' => 'Povos Originários'
    // Adicione outros NOMES NOVOS que tenham NOMES ANTIGOS distintos
    // Se um nome novo não tem um antigo distinto, não precisa estar aqui.
];

if (!empty($categorias_atribuidas_chaves)) {
    foreach ($categorias_atribuidas_chaves as $cat_key_nova) {
        // $cat_key_nova já é uma chave nova validada e presente em $mapa_categorias_atualizado
        $nome_categoria_nova_atual = $mapa_categorias_atualizado[$cat_key_nova];
        $categorias_para_sql_query[] = $nome_categoria_nova_atual; // Adiciona o nome novo

        // Verifica se existe um nome antigo correspondente para este nome novo e o adiciona
        if (isset($mapa_nomes_novos_para_antigos_sql[$nome_categoria_nova_atual])) {
            $nome_categoria_antiga_equivalente = $mapa_nomes_novos_para_antigos_sql[$nome_categoria_nova_atual];
            // Adiciona o nome antigo apenas se for realmente diferente do novo
            if ($nome_categoria_antiga_equivalente !== $nome_categoria_nova_atual) {
                $categorias_para_sql_query[] = $nome_categoria_antiga_equivalente;
            }
        }
    }
}
$categorias_para_sql_query = array_unique($categorias_para_sql_query); // Garante que não haja duplicatas

// Gerar lista de NOMES COMPLETOS apenas das categorias NOVAS atribuídas (para exibição e lógica de cotas)
$categorias_atribuidas_nomes_novos_apenas = [];
if (!empty($categorias_atribuidas_chaves)) {
    foreach ($categorias_atribuidas_chaves as $cat_key_nova) {
        $categorias_atribuidas_nomes_novos_apenas[] = $mapa_categorias_atualizado[$cat_key_nova];
    }
}
// >>> FIM DA MODIFICAÇÃO SUGERIDA <<<


if (empty($categorias_atribuidas_chaves) && !empty($user_info['categoria'])) { //
    die("Nenhuma categoria válida foi identificada para o seu perfil após a normalização das chaves. Por favor, contate o administrador. (Perfil: ".htmlspecialchars($user_info['categoria']).")");
}

$mapa_categorias = $mapa_categorias_atualizado; //


// Buscar cotas (limites) do usuário por categoria (USA AS NOVAS CHAVES)
$user_quotas_por_categoria = [];
if (!empty($categorias_atribuidas_chaves)) { //
    $in_placeholders_quotas = implode(',', array_fill(0, count($categorias_atribuidas_chaves), '?'));
    $sql_quotas = "SELECT category_key, quota FROM evaluator_category_quotas WHERE user_id = ? AND category_key IN ($in_placeholders_quotas)"; //
    $stmt_quotas = $pdo->prepare($sql_quotas);
    $params_quotas = array_merge([$user_id], $categorias_atribuidas_chaves); //
    $stmt_quotas->execute($params_quotas);
    while ($row_quota = $stmt_quotas->fetch(PDO::FETCH_ASSOC)) { //
        $user_quotas_por_categoria[$row_quota['category_key']] = (int)$row_quota['quota'];
    }
}

// Contar avaliações feitas pelo usuário por categoria (USA OS NOVOS NOMES COMPLETOS)
$user_eval_counts_por_categoria_nome = [];
// MODIFICADO: Usar $categorias_atribuidas_nomes_novos_apenas para esta contagem,
// ou ajustar a lógica para agrupar por chave se a contagem deve considerar vídeos de nomes antigos como parte da cota da categoria nova.
// Para simplificar e ser consistente com a lógica de cotas baseada em NOVAS CHAVES, vamos usar $categorias_atribuidas_nomes_novos_apenas.
if (!empty($categorias_atribuidas_nomes_novos_apenas)) { //
    $in_placeholders_counts = implode(',', array_fill(0, count($categorias_atribuidas_nomes_novos_apenas), '?'));
    $sql_counts = "
        SELECT v.categoria AS video_category_name, COUNT(a.id) as count
        FROM avaliacoes a
        JOIN videos v ON a.id_video = v.id
        WHERE a.id_user = ? AND v.categoria IN ($in_placeholders_counts)
        GROUP BY v.categoria
    "; //
    $stmt_counts = $pdo->prepare($sql_counts);
    $params_counts = array_merge([$user_id], $categorias_atribuidas_nomes_novos_apenas); //
    $stmt_counts->execute($params_counts);
    while ($row_count = $stmt_counts->fetch(PDO::FETCH_ASSOC)) { //
        $user_eval_counts_por_categoria_nome[$row_count['video_category_name']] = (int)$row_count['count'];
    }
}

// Mapear contagens para chaves de categoria (NOVAS CHAVES) para uso interno consistente
$user_eval_counts_por_categoria_chave = [];
foreach ($categorias_atribuidas_chaves as $cat_key_nova) { //
    $cat_nome_novo_correspondente = $mapa_categorias[$cat_key_nova];
    $user_eval_counts_por_categoria_chave[$cat_key_nova] = $user_eval_counts_por_categoria_nome[$cat_nome_novo_correspondente] ?? 0;
}


// LÓGICA PARA BUSCAR VÍDEOS PENDENTES
$videos_pendentes = [];
// MODIFICADO: Usar $categorias_para_sql_query que contém nomes novos e antigos mapeados
if (!empty($categorias_para_sql_query)) { //
    $placeholders_sql_videos = implode(',', array_fill(0, count($categorias_para_sql_query), '?'));
    $sql_videos = "
        SELECT v.*
        FROM videos v
        WHERE (
            (v.status = 'pendente' AND (SELECT COUNT(*) FROM avaliacoes a WHERE a.id_video = v.id) < 2) OR
            (v.status = 'reavaliar' AND (SELECT COUNT(*) FROM avaliacoes a WHERE a.id_video = v.id) < 3)
        )
        AND v.categoria IN ($placeholders_sql_videos) -- MODIFICADO para usar a lista expandida
        AND v.id NOT IN (SELECT id_video FROM avaliacoes WHERE id_user = ?)
        ORDER BY v.created_at ASC
    "; //
    $stmt_videos = $pdo->prepare($sql_videos);
    // MODIFICADO: $params_sql_videos agora usa $categorias_para_sql_query
    $params_sql_videos = array_merge($categorias_para_sql_query, [$user_id]); //
    $stmt_videos->execute($params_sql_videos);
    $videos_pendentes = $stmt_videos->fetchAll(PDO::FETCH_ASSOC); //
}


// PROCESSAMENTO DO FORMULÁRIO DE AVALIAÇÃO (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['avaliar'])) { //
    $id_video_avaliado = intval($_POST['id_video']); //

    $stmt_video_cat_post = $pdo->prepare("SELECT categoria FROM videos WHERE id = :id_video"); //
    $stmt_video_cat_post->execute([':id_video' => $id_video_avaliado]);
    $video_category_name_post = $stmt_video_cat_post->fetchColumn(); //

    if ($video_category_name_post) { //
        // Normaliza o nome da categoria do vídeo para sua CHAVE NOVA correspondente
        $video_category_key_post = array_search($video_category_name_post, $mapa_categorias); // Procura no mapa de nomes NOVOS
        
        // Se não encontrou, pode ser um nome ANTIGO de categoria no vídeo
        if ($video_category_key_post === false) {
            // Tenta encontrar a CHAVE NOVA correspondente ao NOME ANTIGO do vídeo
            $nome_novo_equivalente_para_video_antigo = array_search($video_category_name_post, $mapa_nomes_novos_para_antigos_sql);
            if($nome_novo_equivalente_para_video_antigo !== false) {
                 $video_category_key_post = array_search($nome_novo_equivalente_para_video_antigo, $mapa_categorias);
            }
        }


        if ($video_category_key_post !== false && in_array($video_category_key_post, $categorias_atribuidas_chaves)) { //
            $quota_para_categoria = $user_quotas_por_categoria[$video_category_key_post] ?? null; 
            $contagem_atual_na_categoria = $user_eval_counts_por_categoria_chave[$video_category_key_post] ?? 0;

            if ($quota_para_categoria !== null && $contagem_atual_na_categoria >= $quota_para_categoria) { //
                $_SESSION['error'] = "Você já atingiu seu limite de " . htmlspecialchars($quota_para_categoria) . 
                                     " avaliações para a categoria '" . htmlspecialchars($mapa_categorias[$video_category_key_post]) . "'. Sua avaliação não foi submetida."; // Exibe o nome novo
                header("Location: index.php");
                exit();
            }
        } else {
            $_SESSION['error'] = "Tentativa de avaliar vídeo de categoria não permitida ou inválida ('" . htmlspecialchars($video_category_name_post) . "'). Certifique-se que esta categoria está atribuída ao seu perfil."; //
            header("Location: index.php");
            exit();
        }
    } else {
        $_SESSION['error'] = "Vídeo (ID: ".htmlspecialchars($id_video_avaliado).") não encontrado para verificar limite de categoria."; //
        header("Location: index.php");
        exit();
    }

    $avaliacao = [ //
        'id_user' => $user_id,
        'id_video' => $id_video_avaliado,
        'conceitos_corretos' => isset($_POST['conceitos_matematicos_status']) ? (int)$_POST['conceitos_matematicos_status'] : 0,
        'comentario_conceitos' => $_POST['comentario_conceitos'] ?? '',
        'tempo_respeitado' => isset($_POST['tempo_status']) ? (int)$_POST['tempo_status'] : 0,
        'comentario_tempo' => $_POST['comentario_tempo'] ?? '',
        'possui_titulo' => isset($_POST['titulo_status']) ? (int)$_POST['titulo_status'] : 0,
        'comentario_titulo' => $_POST['comentario_titulo'] ?? '',
        'possui_creditos' => isset($_POST['creditos_status']) ? (int)$_POST['creditos_status'] : 0,
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
    ];

    try { //
        $pdo->beginTransaction();
        $sql_insert_avaliacao = "INSERT INTO avaliacoes (
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
        )"; //
        $stmt_insert = $pdo->prepare($sql_insert_avaliacao);
        $stmt_insert->execute($avaliacao);

        $stmt_info_video = $pdo->prepare(
            "SELECT status, (SELECT COUNT(*) FROM avaliacoes WHERE id_video = v.id) as num_avaliacoes 
             FROM videos v WHERE v.id = :id_video"
        ); //
        $stmt_info_video->execute([':id_video' => $id_video_avaliado]);
        $video_info_atual = $stmt_info_video->fetch(PDO::FETCH_ASSOC); //

        $status_video_antes_desta_logica = $video_info_atual['status']; 
        $num_avaliacoes_agora = $video_info_atual['num_avaliacoes'];
        
        $status_final_a_definir_no_video = null; 
        $_SESSION['info'] = ''; 

        if ($status_video_antes_desta_logica === 'reavaliar') { //
            if ($num_avaliacoes_agora == 3) { 
                $terceiro_parecer = $avaliacao['parecer'];
                if ($terceiro_parecer === 'aprovado' || $terceiro_parecer === 'aprovado_classificado') { //
                    $status_final_a_definir_no_video = 'aprovado';
                    $_SESSION['info'] = "Reavaliação (3º parecer) concluída. Vídeo APROVADO."; //
                } elseif ($terceiro_parecer === 'reprovado') { //
                    $status_final_a_definir_no_video = 'reprovado';
                    $_SESSION['info'] = "Reavaliação (3º parecer) concluída. Vídeo REPROVADO."; //
                } elseif ($terceiro_parecer === 'correcao') { //
                    $status_final_a_definir_no_video = 'correcao';
                    $_SESSION['info'] = "Reavaliação (3º parecer) concluída. Vídeo enviado para CORREÇÃO."; //
                } else {
                    $status_final_a_definir_no_video = 'correcao'; 
                    $_SESSION['info'] = "Reavaliação (3º parecer) com parecer não padrão (".htmlspecialchars($terceiro_parecer)."). Enviado para análise administrativa."; //
                }
            }
        } elseif ($num_avaliacoes_agora >= 2) { 
            $stmt_todas_avaliacoes = $pdo->prepare("SELECT parecer FROM avaliacoes WHERE id_video = :id_video"); //
            $stmt_todas_avaliacoes->execute([':id_video' => $id_video_avaliado]);
            $lista_pareceres_video = $stmt_todas_avaliacoes->fetchAll(PDO::FETCH_COLUMN); //
            
            $pareceres_normalizados = []; //
            foreach ($lista_pareceres_video as $p) { //
                if ($p === 'aprovado' || $p === 'aprovado_classificado') $pareceres_normalizados[] = 'aprovado_geral'; //
                elseif ($p === 'reprovado') $pareceres_normalizados[] = 'reprovado_geral'; //
                else $pareceres_normalizados[] = $p; 
            }
            $pareceres_unicos_normalizados = array_unique($pareceres_normalizados); //

            if (in_array('aprovado_geral', $pareceres_unicos_normalizados) && in_array('reprovado_geral', $pareceres_unicos_normalizados)) { //
                $status_final_a_definir_no_video = 'reavaliar';
                $_SESSION['info'] = "Divergência de pareceres. Vídeo enviado para reavaliação."; //
            } else {
                $contagem_parecer_atual = 0; //
                $parecer_atual_normalizado = (in_array($avaliacao['parecer'], ['aprovado', 'aprovado_classificado'])) ? 'aprovado_geral' : (($avaliacao['parecer'] === 'reprovado') ? 'reprovado_geral' : $avaliacao['parecer']); //
                
                foreach($pareceres_normalizados as $p_norm) { //
                    if ($p_norm === $parecer_atual_normalizado) $contagem_parecer_atual++;
                }

                if ($contagem_parecer_atual >= 2) { //
                    if ($parecer_atual_normalizado === 'aprovado_geral') $status_final_a_definir_no_video = 'aprovado'; //
                    elseif ($parecer_atual_normalizado === 'reprovado_geral') $status_final_a_definir_no_video = 'reprovado'; //
                    elseif ($parecer_atual_normalizado === 'correcao') $status_final_a_definir_no_video = 'correcao'; //
                    
                    if ($status_final_a_definir_no_video && empty($_SESSION['info'])) {  //
                        $_SESSION['info'] = "Status do vídeo atualizado para " . htmlspecialchars($status_final_a_definir_no_video) . ".";
                    }
                } elseif (empty($_SESSION['info'])) {  //
                     if (in_array('correcao', $pareceres_unicos_normalizados)) { //
                         $status_final_a_definir_no_video = 'correcao';
                         $_SESSION['info'] = "Uma das avaliações sugere correção. Vídeo marcado para CORREÇÃO."; //
                     } else {
                         $_SESSION['info'] = "Avaliações registradas. Sem divergência clara ou consenso imediato."; //
                     }
                }
            }
        } else { 
            $_SESSION['info'] = "Avaliação registrada. Aguardando segunda avaliação."; //
        }

        if ($status_final_a_definir_no_video !== null) { //
            $stmt_update_status_video = $pdo->prepare("UPDATE videos SET status = :status WHERE id = :id_video"); //
            $stmt_update_status_video->execute([':status' => $status_final_a_definir_no_video, ':id_video' => $id_video_avaliado]);
        }

        $pdo->commit(); //
        $_SESSION['success'] = "Avaliação enviada com sucesso! " . ($_SESSION['info'] ?? ''); //
        unset($_SESSION['info']); 
        header("Location: index.php"); 
        exit();

    } catch (PDOException $e) {  //
        $pdo->rollBack(); 
        $_SESSION['error'] = "Erro ao enviar avaliação: " . $e->getMessage();  //
        header("Location: index.php");
        exit();
    }
}

// VERIFICAÇÃO DE LIMITE AO TENTAR ABRIR UM VÍDEO (GET)
$video_atual = null;
if (isset($_GET['avaliar'])) { //
    $video_id_para_avaliar = intval($_GET['avaliar']); //
    
    $stmt_video_info_get = $pdo->prepare("SELECT * FROM videos WHERE id = :id"); //
    $stmt_video_info_get->execute(['id' => $video_id_para_avaliar]);
    $video_atual_get = $stmt_video_info_get->fetch(PDO::FETCH_ASSOC); //

    if ($video_atual_get) { //
        $video_category_name_get = $video_atual_get['categoria']; //
        
        // Normaliza o nome da categoria do vídeo para sua CHAVE NOVA correspondente
        $video_category_key_get = array_search($video_category_name_get, $mapa_categorias); // Procura no mapa de nomes NOVOS
        if ($video_category_key_get === false) {
            // Tenta encontrar a CHAVE NOVA correspondente ao NOME ANTIGO do vídeo
             $nome_novo_equivalente_para_video_antigo_get = array_search($video_category_name_get, $mapa_nomes_novos_para_antigos_sql);
            if($nome_novo_equivalente_para_video_antigo_get !== false) {
                 $video_category_key_get = array_search($nome_novo_equivalente_para_video_antigo_get, $mapa_categorias);
            }
        }


        if ($video_category_key_get !== false && in_array($video_category_key_get, $categorias_atribuidas_chaves)) { //
            $quota_para_categoria_get = $user_quotas_por_categoria[$video_category_key_get] ?? null; //
            $contagem_na_categoria_get = $user_eval_counts_por_categoria_chave[$video_category_key_get] ?? 0; //

            if ($quota_para_categoria_get !== null && $contagem_na_categoria_get >= $quota_para_categoria_get) { //
                $_SESSION['error'] = "Você atingiu o limite de ".htmlspecialchars($quota_para_categoria_get)." avaliações para a categoria '".htmlspecialchars($mapa_categorias[$video_category_key_get])."'."; // Exibe o nome novo
                header('Location: index.php');
                exit();
            } else {
                $video_atual = $video_atual_get; //
            }
        } else {
            $_SESSION['error'] = "Vídeo de categoria não permitida ou inválida para avaliação."; //
            header('Location: index.php');
            exit();
        }
    } else {
        $_SESSION['error'] = "Vídeo (ID: ".htmlspecialchars($video_id_para_avaliar).") não encontrado para avaliação."; //
        header('Location: index.php');
        exit();
    }
}

function extrairIdYouTube($url) { //
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
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
        /* Esta parte estava com um ponto final antes da classe, o que a torna inválida em CSS. Removido. */
        } /* Fechamento do .form-check-label que estava aberto */
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
            <nav class="col-md-2 d-none d-md-block bg-light sidebar">
                <div class="sidebar-sticky">
                    <ul class="nav flex-column">
                        <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link active" href="index.php">
                                <i class="fas fa-home mr-2"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#"> <i class="fas fa-video mr-2"></i> Atualizar Vídeo
                            </a>
                    </ul>
                </div>
            </nav>

            <main role="main" class="col-md-9 ml-sm-auto col-lg-10 px-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Avaliação de Vídeos</h1>
                </div>

                <div class="alert alert-info">
                    <strong>Seus Limites de Avaliação por Categoria:</strong><br>
                    <?php if (empty($categorias_atribuidas_chaves)): ?>
                        Nenhuma categoria atribuída a você no momento.
                    <?php else: ?>
                        <ul class="list-unstyled mb-0">
                        <?php foreach ($categorias_atribuidas_chaves as $cat_key): ?>
                            <?php
                                $cat_nome_display = isset($mapa_categorias[$cat_key]) ? $mapa_categorias[$cat_key] : htmlspecialchars($cat_key) . ' (Chave desconhecida)';
                                $quota = $user_quotas_por_categoria[$cat_key] ?? null; 
                                $count = $user_eval_counts_por_categoria_chave[$cat_key] ?? 0;
                                $quota_display = ($quota === null) ? "Ilimitado" : $quota;
                                
                                $restantes_display = "N/A";
                                if ($quota !== null) {
                                    $restantes_num = max(0, $quota - $count);
                                    $restantes_display = $restantes_num;
                                } elseif ($quota === null) { 
                                    $restantes_display = "Ilimitadas";
                                }
                            ?>
                            <li>
                                <strong><?= htmlspecialchars($cat_nome_display) ?>:</strong> 
                                Realizadas <?= $count ?> de <?= $quota_display ?>. 
                                (Restantes: <?= $restantes_display ?>)
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

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
                                <?php
                                function convertToEmbedUrl($url) { //
                                    return str_replace('watch?v=', 'embed/', $url);
                                }
                                ?>

                                <iframe width="420" height="315"
                                    src="<?= htmlspecialchars(convertToEmbedUrl($video_atual['link_youtube'])) ?>">
                                </iframe>

                            </div>

                            <form action="index.php" method="post">
                                <input type="hidden" name="id_video" value="<?= $video_atual['id'] ?>">
                                
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
                                    <div class="criteria-title">2. Tempo (necessário ser menor que 6 minutos)</div>
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
                                <a href="index.php" class="btn btn-secondary btn-lg">Cancelar</a>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="card-header card-header-custom-purple text-white">
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
                                    <?php if(empty($categorias_atribuidas_chaves)): ?>
                                        <br>Verifique se você possui categorias de avaliação atribuídas pelo administrador.
                                    <?php elseif(empty($categorias_para_sql_query)): ?>
                                        <br>Não foram encontradas categorias válidas (novas ou antigas mapeadas) para buscar vídeos. Verifique as configurações de categoria.
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- JavaScript -->

<script>
// Controlar campos de comentário baseados na seleção do radio
document.querySelectorAll('.criteria-radio').forEach(radio => {
    radio.addEventListener('change', function() {
        const commentId = this.dataset.commentId;
        const commentField = document.getElementById(commentId);

        if (commentField) {
            if (this.value === '1') { // Se a opção "SIM" (correto/aplicável) for marcada
                commentField.disabled = true;
                commentField.required = false;
                commentField.value = '';
                commentField.placeholder = "Não é necessário comentar se o critério foi cumprido.";
            } else { // Se a opção "NÃO" (incorreto/não aplicável) for marcada (value === '0')
                commentField.disabled = false;
                commentField.required = true;
                commentField.placeholder = "Escreva aqui o motivo pelo qual não cumpre o critério.";
            }
        }
    });
});

// Função para converter duração ISO 8601 para segundos
function convertYouTubeDuration(duration) {
    const match = duration.match(/PT(\d+H)?(\d+M)?(\d+S)?/);
    if (!match) return 0; // Retorna 0 se o formato da duração for inesperado
    const hours = (parseInt(match[1]) || 0);
    const minutes = (parseInt(match[2]) || 0);
    const seconds = (parseInt(match[3]) || 0);
    return hours * 3600 + minutes * 60 + seconds;
}

// Função principal que verifica a duração do vídeo do YouTube
async function checkVideoDuration(videoId, apiKey) {
    const tempoRespeitadoNao = document.getElementById('tempo_respeitado_nao');
    const comentarioTempo = document.getElementById('comentario_tempo');
    const tempoRespeitadoSim = document.getElementById('tempo_respeitado_sim');

    // Garante que todos os elementos necessários do formulário de tempo existam
    if (!tempoRespeitadoNao || !comentarioTempo || !tempoRespeitadoSim) {
        // console.warn("Um ou mais elementos do formulário de tempo não foram encontrados. Verificação de duração ignorada.");
        return;
    }

    try {
        const response = await fetch(`https://www.googleapis.com/youtube/v3/videos?id=${videoId}&part=contentDetails&key=${apiKey}`);
        if (!response.ok) {
            // console.error(`Erro na API do YouTube: ${response.status} ${response.statusText}`);
            // Silenciosamente falha ou pode-se adicionar um feedback visual discreto se necessário
            return;
        }
        const data = await response.json();
        
        if (data.items && data.items.length > 0) {
            const durationISO = data.items[0].contentDetails.duration;
            const totalSeconds = convertYouTubeDuration(durationISO);
            
            // Regulamento: vídeos de até 6 minutos (360 segundos)
            if (totalSeconds > 360) {
                tempoRespeitadoNao.checked = true;
                const totalMinutesDisplay = Math.floor(totalSeconds / 60);
                const segundosRestantesDisplay = totalSeconds % 60;
                comentarioTempo.value = `O tempo total é de ${totalMinutesDisplay} minutos e ${segundosRestantesDisplay} segundos (limite: 6 minutos).`;
                tempoRespeitadoNao.dispatchEvent(new Event('change')); // Dispara o evento para atualizar o estado do comentário
            } else {
                tempoRespeitadoSim.checked = true;
                tempoRespeitadoSim.dispatchEvent(new Event('change')); // Dispara o evento
            }
        } else {
            // console.warn("Não foram encontrados detalhes para o vídeo ID:", videoId);
        }
    } catch (error) {
        // console.error('Erro ao verificar duração do vídeo:', error);
    }
}

// Obtém o ID do vídeo e a chave da API do PHP (apenas se $video_atual estiver definido)
const videoIdForDurationCheck = '<?= isset($video_atual) && $video_atual && isset($video_atual['link_youtube']) ? extrairIdYouTube($video_atual['link_youtube']) : "" ?>';
const apiKeyForDurationCheck = '<?= $apikey_youtube ?? "" ?>'; // Garante que $apikey_youtube esteja definida no PHP

document.addEventListener('DOMContentLoaded', function() {
    // Executa a verificação da duração do vídeo apenas se o formulário de avaliação estiver presente
    if (videoIdForDurationCheck && apiKeyForDurationCheck) {
        checkVideoDuration(videoIdForDurationCheck, apiKeyForDurationCheck);
    }

    // Garante que o estado inicial dos campos de comentário esteja correto com base nos rádios checados
    document.querySelectorAll('.criteria-radio').forEach(radio => {
        if (radio.checked) {
            radio.dispatchEvent(new Event('change'));
        }
    });
});
</script> 

    <!-- Footer -->
    <?php include '../includes/footer.php'; ?>
</body>
</html>