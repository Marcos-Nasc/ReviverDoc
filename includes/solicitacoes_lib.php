<?php
/**
 * Funções do módulo de Atendimento (solicitações de pesquisa e chat).
 */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(403); exit; }
require_once __DIR__ . '/bootstrap.php';

const SOLIC_RESULTADOS = [
    'Realizada'           => 'Realizada',
    'Não Consta'          => 'Não está no Arquivo',   // valor no banco continua 'Não Consta'
    'Não Realizada'       => 'Não encontrada',
    'Dados Insuficientes' => 'Dados insuficientes',
];

/* ------------------------------------------------------------------
 * Tempo de resposta em horário de atendimento
 * Conta só segunda a sexta, das 8h às 18h, e desconta os períodos em que
 * o Arquivo pausou o recebimento de solicitações (botão em Solicitações).
 * ------------------------------------------------------------------ */
const ATEND_INICIO_MIN = 8 * 60;    // 08:00
const ATEND_FIM_MIN    = 18 * 60;   // 18:00
const ATEND_MIN_DIA    = ATEND_FIM_MIN - ATEND_INICIO_MIN; // 600 min = 1 dia útil

/** Períodos de pausa registrados: lista de [inicio_ts, fim_ts]; pausa em andamento termina "agora". */
function solic_pausas()
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cfg = app_config();
    $out = [];
    foreach ((array) ($cfg['solicitacoes_pausas'] ?? []) as $p) {
        $ini = strtotime((string) ($p['inicio'] ?? ''));
        $fim = !empty($p['fim']) ? strtotime((string) $p['fim']) : time();
        if ($ini && $fim && $fim > $ini) $out[] = [$ini, $fim];
    }
    // Pausa feita antes do histórico existir (só havia a data de início)
    $r = solicitacoes_recebimento();
    if (!$r['aberto'] && $r['em'] && !array_filter((array) ($cfg['solicitacoes_pausas'] ?? []), function ($p) { return empty($p['fim']); })) {
        $ini = strtotime($r['em']);
        if ($ini && time() > $ini) $out[] = [$ini, time()];
    }
    return $cache = $out;
}

/** Quantos dias de semana (seg–sex) há entre duas datas 'Y-m-d', incluindo as duas. */
function dias_uteis_entre($d1, $d2)
{
    $a = new DateTime($d1);
    $b = new DateTime($d2);
    if ($b < $a) return 0;
    $dias = (int) $a->diff($b)->days + 1;
    $semanas = intdiv($dias, 7);
    $n = $semanas * 5;
    $resto = $dias % 7;
    $dow = (int) $a->format('N'); // 1 = segunda ... 7 = domingo
    for ($i = 0; $i < $resto; $i++) {
        $d = ($dow + $semanas * 7 + $i - 1) % 7 + 1;
        if ($d <= 5) $n++;
    }
    return $n;
}

/** Minutos dentro do expediente (seg–sex, 8h–18h) entre dois timestamps, sem considerar pausas. */
function minutos_expediente($ini, $fim)
{
    if ($fim <= $ini) return 0;
    $janela = function ($ts) {
        // minutos do dia limitados à janela de atendimento
        $m = (int) date('G', $ts) * 60 + (int) date('i', $ts) + ((int) date('s', $ts)) / 60;
        return max(ATEND_INICIO_MIN, min(ATEND_FIM_MIN, $m));
    };
    $util = function ($ts) { return (int) date('N', $ts) <= 5; };
    $dIni = date('Y-m-d', $ini);
    $dFim = date('Y-m-d', $fim);
    if ($dIni === $dFim) {
        return $util($ini) ? max(0, $janela($fim) - $janela($ini)) : 0;
    }
    $total = 0;
    if ($util($ini)) $total += ATEND_FIM_MIN - $janela($ini);   // resto do primeiro dia
    if ($util($fim)) $total += $janela($fim) - ATEND_INICIO_MIN; // começo do último dia
    $meioIni = date('Y-m-d', strtotime($dIni . ' +1 day'));
    $meioFim = date('Y-m-d', strtotime($dFim . ' -1 day'));
    if ($meioIni <= $meioFim) $total += dias_uteis_entre($meioIni, $meioFim) * ATEND_MIN_DIA;
    return $total;
}

/** Tempo de resposta (minutos de atendimento) entre abertura e resposta, descontando as pausas. */
function tempo_atendimento_min($abertura, $resposta)
{
    $a = is_numeric($abertura) ? (int) $abertura : strtotime((string) $abertura);
    $b = is_numeric($resposta) ? (int) $resposta : strtotime((string) $resposta);
    if (!$a || !$b || $b <= $a) return 0;
    $min = minutos_expediente($a, $b);
    foreach (solic_pausas() as $p) {
        $pi = max($a, $p[0]);
        $pf = min($b, $p[1]);
        if ($pf > $pi) $min -= minutos_expediente($pi, $pf);
    }
    return max(0, $min);
}

/** Formata minutos de atendimento: min, h ou dias úteis (1 dia útil = 10 h). */
function tempo_atendimento_fmt($min)
{
    if ($min === null) return '—';
    $min = (float) $min;
    if ($min < 1) return $min > 0 ? '< 1 min' : '0 min';
    if ($min < 60) return round($min) . ' min';
    if ($min < ATEND_MIN_DIA) return str_replace('.', ',', (string) round($min / 60, 1)) . ' h';
    $d = round($min / ATEND_MIN_DIA, 1);
    return str_replace('.', ',', (string) $d) . ($d == 1 ? ' dia útil' : ' dias úteis');
}

/** Média de tempo de atendimento para linhas com data_solicitacao/data_resposta. Retorna null sem dados. */
function tempo_atendimento_media(array $linhas)
{
    $soma = 0; $n = 0;
    foreach ($linhas as $l) {
        if (empty($l['data_solicitacao']) || empty($l['data_resposta'])) continue;
        $soma += tempo_atendimento_min($l['data_solicitacao'], $l['data_resposta']);
        $n++;
    }
    return $n ? $soma / $n : null;
}

/** Busca uma solicitação e verifica se o usuário logado pode acessá-la (nível 1: só do próprio setor). */
function solicitacao_acessivel($id)
{
    global $pdo;
    $st = $pdo->prepare('SELECT id, setor_id, usuario_id, status FROM solicitacoes WHERE id = ?');
    $st->execute([(int) $id]);
    $s = $st->fetch();
    if (!$s) {
        return null;
    }
    if (user_level() < NIVEL_ARQUIVO && !can_access_setor($s['setor_id'])) {
        return false;
    }
    return $s;
}

function resultado_badge($resultado, $status)
{
    if ($status !== 'Respondida') {
        return '<span class="badge no-dot">Aguardando resposta</span>';
    }
    switch ($resultado) {
        case 'Realizada':           return '<span class="badge badge-success">Realizada</span>';
        case 'Não Consta':          return '<span class="badge badge-warning" title="O documento não está guardado no Arquivo">Não está no Arquivo</span>';
        case 'Não Realizada':       return '<span class="badge badge-danger">Não encontrada</span>';
        case 'Dados Insuficientes': return '<span class="badge badge-info">Dados insuficientes</span>';
        default:                    return '<span class="muted">—</span>';
    }
}

/** Situação do prazo: 'atrasada', 'proxima' (vence em até 24h) ou ''. */
function prazo_situacao($prazo, $status)
{
    if (!in_array((string) $status, ['Pendente', ''], true) || empty($prazo)) {
        return '';
    }
    $ts = strtotime($prazo);
    if (!$ts) return '';
    if ($ts < time()) return 'atrasada';
    if ($ts < time() + 86400) return 'proxima';
    return '';
}

/** Nome do setor da pesquisa: aceita id (novo) ou texto legado. */
function setor_pesquisa_label($valor, $nome_join = null)
{
    if ($nome_join) return $nome_join;
    if ($valor === null || $valor === '' || $valor === '0' || $valor === 0) return '—';
    return (string) $valor;
}
