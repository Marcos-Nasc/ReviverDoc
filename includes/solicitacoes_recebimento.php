<?php
/**
 * Liga/desliga o recebimento de novas solicitações de pesquisa (equipe do Arquivo).
 * POST aberto=1|0, mensagem (opcional, mostrada aos setores enquanto estiver pausado).
 */
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_ARQUIVO, ['POST']);

$aberto = ($_POST['aberto'] ?? '') === '1';
$mensagem = trim((string) ($_POST['mensagem'] ?? ''));
if (mb_strlen($mensagem) > 300) {
    json_out('error', 'O aviso pode ter no máximo 300 caracteres.', [], 422);
}

// Histórico de pausas (usado para descontar o tempo pausado no tempo médio de resposta)
$cfg = app_config();
$estavaAberto = solicitacoes_recebimento()['aberto'];
$pausas = array_values(array_filter((array) ($cfg['solicitacoes_pausas'] ?? []), 'is_array'));
$agora = date('Y-m-d H:i:s');
if (!$aberto && $estavaAberto) {
    $pausas[] = ['inicio' => $agora, 'fim' => null];
} elseif ($aberto && !$estavaAberto) {
    $fechou = false;
    for ($i = count($pausas) - 1; $i >= 0; $i--) {
        if (empty($pausas[$i]['fim'])) { $pausas[$i]['fim'] = $agora; $fechou = true; break; }
    }
    if (!$fechou && !empty($cfg['solicitacoes_pausa_em'])) {
        // pausa feita antes do histórico existir
        $pausas[] = ['inicio' => $cfg['solicitacoes_pausa_em'], 'fim' => $agora];
    }
}
// mantém só os últimos 3 anos
$limite = date('Y-m-d H:i:s', strtotime('-3 years'));
$pausas = array_values(array_filter($pausas, function ($p) use ($limite) { return empty($p['fim']) || $p['fim'] >= $limite; }));

$valores = $aberto
    ? ['solicitacoes_abertas' => true, 'solicitacoes_pausa_msg' => '', 'solicitacoes_pausa_por' => '', 'solicitacoes_pausa_em' => '']
    : ['solicitacoes_abertas' => false, 'solicitacoes_pausa_msg' => $mensagem,
       'solicitacoes_pausa_por' => (string) ($_SESSION['nome'] ?? ''), 'solicitacoes_pausa_em' => (!$estavaAberto && !empty($cfg['solicitacoes_pausa_em'])) ? $cfg['solicitacoes_pausa_em'] : $agora];

$valores['solicitacoes_pausas'] = $pausas;
if (!app_config_salvar($valores)) {
    json_out('error', 'Não foi possível gravar a configuração (php/config.json). Verifique a permissão de escrita no servidor.', [], 500);
}
log_activity('editar', 'sistema', null, $aberto
    ? 'Recebimento de solicitações reaberto'
    : 'Recebimento de solicitações pausado' . ($mensagem !== '' ? ': ' . $mensagem : ''));

json_out('ok', $aberto ? 'Recebimento de solicitações reaberto.' : 'Recebimento de solicitações pausado.', ['aberto' => $aberto]);
