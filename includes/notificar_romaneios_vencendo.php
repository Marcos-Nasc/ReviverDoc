<?php
/**
 * Rotina diária: avisa administradores sobre romaneios de saída que vencem amanhã
 * (notificação no sistema + e-mail opcional). Executada automaticamente pelo layout
 * uma vez por dia; não deve ser acessada diretamente pelo navegador.
 */
if (!defined('RD_INTERNAL_JOB')) {
    http_response_code(403);
    exit('Acesso direto não permitido.');
}
require_once __DIR__ . '/bootstrap.php';

$stmt = $pdo->query("
    SELECT r.id, r.data_retorno, r.usuario_id, u.nome AS usuario_nome, s.nome AS setor_nome
    FROM romaneios r
    LEFT JOIN usuarios u ON r.usuario_id = u.id
    LEFT JOIN setores s ON r.setor_id = s.id
    WHERE (r.status = 'Pendente' OR r.status IS NULL OR r.status = '') AND r.tipo = 'Saída' AND DATE(r.data_retorno) = CURDATE() + INTERVAL 1 DAY
");
$romaneios_vencendo = $stmt->fetchAll();

$cfg = app_config();
$admins = user_ids_by_level(NIVEL_ADMIN);
$template_path = __DIR__ . '/email_template_romaneio.html';
$template = is_file($template_path) ? file_get_contents($template_path) : '';
$appUrl = app_url();

foreach ($romaneios_vencendo as $r) {
    $id = (int) $r['id'];
    $link = 'php/romaneio-conf.php?id=' . $id;

    // E-mail (uma única vez por romaneio, registrado em notificacoes com usuario_id = 0)
    if (!empty($cfg['email_notificacao']) && $template !== '') {
        $marker = 'EMAIL_SENT_ROMANEIO_#' . $id;
        $chk = $pdo->prepare('SELECT COUNT(*) FROM notificacoes WHERE mensagem = ? AND (usuario_id = 0 OR usuario_id IS NULL)');
        $chk->execute([$marker]);
        if ((int) $chk->fetchColumn() === 0) {
            $assunto = "Alerta: Romaneio #{$id} vence amanhã";
            $html = strtr($template, [
                '{{assunto}}'       => e($assunto),
                '{{id_romaneio}}'   => $id,
                '{{setor_nome}}'    => e($r['setor_nome'] ?? '—'),
                '{{usuario_nome}}'  => e($r['usuario_nome'] ?? '—'),
                '{{data_retorno}}'  => fmt_date($r['data_retorno']),
                '{{link_romaneio}}' => e($appUrl . '/php/romaneio-conf.php?id=' . $id),
                '{{ano_atual}}'     => date('Y'),
            ]);
            // Registra a tentativa antes de enviar: se o servidor de e-mail estiver fora do ar,
            // não tenta de novo a cada página (cada tentativa pode levar vários segundos)
            try {
                $pdo->prepare('INSERT INTO notificacoes (usuario_id, mensagem, lida) VALUES (0, ?, 1)')->execute([$marker]);
            } catch (Exception $e) {
            }
            if (!send_mail($cfg['email_notificacao'], $assunto, $html, 'ReviverDoc - Alertas')) {
                rd_log_erro("Não foi possível enviar o e-mail de alerta do romaneio #{$id} (verifique o SMTP em Sistema e e-mail).");
            }
        }
    }

    // Notificação no sistema para administradores e para quem registrou o romaneio (sem duplicar)
    $msg = "O Romaneio #{$id} (Setor: " . ($r['setor_nome'] ?? '—') . ") vence amanhã.";
    $chk = $pdo->prepare('SELECT COUNT(*) FROM notificacoes WHERE usuario_id = ? AND link = ? AND mensagem = ?');
    $destinos = $admins;
    if (!empty($r['usuario_id'])) $destinos[] = (int) $r['usuario_id'];
    foreach (array_unique(array_map('intval', $destinos)) as $uid) {
        $linkUid = in_array($uid, $admins, true) ? $link : 'php/romaneios.php?id=' . $id;
        $txt = in_array($uid, $admins, true) ? $msg : "Seu romaneio de saída #{$id} vence amanhã: devolva o material ao Arquivo.";
        $chk->execute([$uid, $linkUid, $txt]);
        if ((int) $chk->fetchColumn() === 0) {
            notify([$uid], $txt, $linkUid, false);
        }
    }
}

/* Limpeza: notificações que apontam para solicitações/romaneios que já não existem */
try {
    $pdo->exec("DELETE FROM notificacoes WHERE (link LIKE 'php/solicitacoes.php?id=%' OR link LIKE '../php/solicitacoes.php?id=%')
        AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(link, 'id=', -1), '&', 1) AS UNSIGNED) NOT IN (SELECT id FROM solicitacoes)");
    $pdo->exec("DELETE FROM notificacoes WHERE (link LIKE 'php/romaneio%.php?id=%' OR link LIKE '../php/romaneio%.php?id=%')
        AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(link, 'id=', -1), '&', 1) AS UNSIGNED) NOT IN (SELECT id FROM romaneios)");
} catch (Exception $e) {
    rd_log_erro('Limpeza de notificações antigas falhou: ' . $e->getMessage());
}
