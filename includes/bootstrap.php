<?php
/**
 * ReviverDoc — núcleo comum (sessão, conexão, segurança e utilitários).
 * Incluído por todas as páginas e endpoints.
 */
if (defined('RD_BOOTSTRAP')) {
    return;
}
define('RD_BOOTSTRAP', true);
define('RD_VERSION', '2.1.0');

date_default_timezone_set('America/Sao_Paulo');

/* ------------------------------------------------------------------ */
/* Registro de erros                                                   */
/* Erros não tratados ficam em backup_temp/erros.log (protegido) e      */
/* aparecem para o administrador em Sistema e e-mail › Registro de erros */
/* ------------------------------------------------------------------ */

const RD_ERROS_LOG = __DIR__ . '/../backup_temp/erros.log';

function rd_log_erro($msg)
{
    $linha = date('Y-m-d H:i:s') . ' | ' . ($_SERVER['REQUEST_METHOD'] ?? '-') . ' ' . ($_SERVER['REQUEST_URI'] ?? '-')
        . ' | ' . str_replace(["\r", "\n"], ' ', (string) $msg) . "\n";
    if (is_file(RD_ERROS_LOG) && filesize(RD_ERROS_LOG) > 512 * 1024) {
        @rename(RD_ERROS_LOG, RD_ERROS_LOG . '.1');
    }
    @file_put_contents(RD_ERROS_LOG, $linha, FILE_APPEND | LOCK_EX);
    error_log('ReviverDoc - ' . $msg);
}

/** Resposta para um erro inesperado: JSON nos endpoints, página simples nas telas. */
function rd_falha($detalhe)
{
    rd_log_erro($detalhe);
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $admin = (int) ($_SESSION['nivel'] ?? 0) >= 3;
    $msg = 'Ocorreu um erro interno no servidor. Ele foi registrado em Sistema e e-mail › Registro de erros.';
    $json = (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '')
        || strpos(str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? ''), '/includes/') !== false;
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: ' . ($json ? 'application/json' : 'text/html') . '; charset=utf-8');
    }
    if ($json) {
        echo json_encode(['status' => 'error', 'message' => $msg . ($admin ? ' Detalhe: ' . $detalhe : ''), 'mensagem' => $msg], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    } else {
        echo '<!DOCTYPE html><meta charset="UTF-8"><title>Erro</title><div style="font:15px system-ui;max-width:640px;margin:60px auto;padding:0 16px">'
            . '<h2>Não foi possível abrir esta página</h2><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
            . ($admin ? '<p style="color:#555;font-size:13px">Detalhe: ' . htmlspecialchars($detalhe, ENT_QUOTES, 'UTF-8') . '</p>' : '')
            . '<p><a href="javascript:history.back()">Voltar</a></p></div>';
    }
}

set_exception_handler(function ($e) {
    rd_falha(get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        rd_falha('Erro fatal: ' . $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')');
    }
});

/* Servidores sem a extensão mbstring: substitutos simples (UTF-8) */
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $enc = null) { return strtr(strtolower((string) $s), ['Á'=>'á','À'=>'à','Â'=>'â','Ã'=>'ã','É'=>'é','Ê'=>'ê','Í'=>'í','Ó'=>'ó','Ô'=>'ô','Õ'=>'õ','Ú'=>'ú','Ü'=>'ü','Ç'=>'ç']); }
    function mb_strtoupper($s, $enc = null) { return strtr(strtoupper((string) $s), ['á'=>'Á','à'=>'À','â'=>'Â','ã'=>'Ã','é'=>'É','ê'=>'Ê','í'=>'Í','ó'=>'Ó','ô'=>'Ô','õ'=>'Õ','ú'=>'Ú','ü'=>'Ü','ç'=>'Ç']); }
    function mb_strlen($s, $enc = null) { return (int) preg_match_all('/./us', (string) $s); }
    function mb_substr($s, $start, $len = null, $enc = null) { preg_match_all('/./us', (string) $s, $m); return implode('', array_slice($m[0], $start, $len)); }
}
if (!function_exists('mb_strimwidth')) {
    function mb_strimwidth($s, $start, $width, $trim = '', $enc = null)
    {
        $s = mb_substr((string) $s, $start);
        if (mb_strlen($s) <= $width) return $s;
        return mb_substr($s, 0, max(0, $width - mb_strlen($trim))) . $trim;
    }
}
/* Servidores sem a extensão ctype */
if (!function_exists('ctype_digit')) {
    function ctype_digit($t) { return is_string($t) && $t !== '' && preg_match('/^[0-9]+$/', $t) === 1; }
}

/**
 * Mantém só os caracteres que cabem numa coluna latin1 do MySQL (Windows-1252).
 * Emojis e outros símbolos são removidos em vez de virarem "?". Não depende de extensões.
 */
function texto_latin1($texto)
{
    static $extras = '€‚ƒ„…†‡ˆ‰Š‹ŒŽ‘’“”•–—˜™š›œžŸ';
    $out = '';
    foreach (preg_split('//u', (string) $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        if (strlen($ch) === 1 || (strlen($ch) === 2 && ord($ch[0]) <= 0xC3 && !(ord($ch[0]) === 0xC2 && ord($ch[1]) < 0xA0))
            || strpos($extras, $ch) !== false) {
            $out .= $ch;
        }
    }
    return $out;
}

if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $secure, true);
    }
    session_start();
}

require_once __DIR__ . '/config.php';

// Nenhuma página usa parâmetros GET em formato de lista: valores como ?busca[]=x viram texto vazio
foreach ($_GET as $__k => $__v) {
    if (is_array($__v)) {
        $_GET[$__k] = '';
    }
}
unset($__k, $__v);

/* ------------------------------------------------------------------ */
/* Sessão e perfis                                                     */
/* ------------------------------------------------------------------ */

const NIVEL_SETOR   = 1; // usuário de setor (solicita pesquisas e romaneios)
const NIVEL_ARQUIVO = 2; // equipe do Arquivo (opera o acervo)
const NIVEL_ADMIN   = 3; // administrador

function get_session_data()
{
    return [
        'id'       => $_SESSION['usuario_id'] ?? null,
        'nivel'    => $_SESSION['nivel'] ?? null,
        'setor_id' => $_SESSION['setor_id'] ?? null,
        'nome'     => $_SESSION['nome'] ?? '',
        'email'    => $_SESSION['email'] ?? '',
    ];
}

function is_logged_in()
{
    return !empty($_SESSION['usuario_id']);
}

function user_level()
{
    return (int) ($_SESSION['nivel'] ?? 0);
}

function user_id()
{
    return (int) ($_SESSION['usuario_id'] ?? 0);
}

function user_setor()
{
    return isset($_SESSION['setor_id']) ? (int) $_SESSION['setor_id'] : null;
}

function nivel_label($nivel)
{
    switch ((int) $nivel) {
        case 1: return 'Usuário de setor';
        case 2: return 'Equipe do Arquivo';
        case 3: return 'Administrador';
        default: return 'Nível ' . (int) $nivel;
    }
}

/** Raiz relativa da aplicação a partir do script atual ('' na raiz, '../' em /php e /includes). */
function app_base()
{
    static $base = null;
    if ($base === null) {
        $root   = realpath(__DIR__ . '/..');
        $script = realpath(dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__));
        $base   = ($root && $script && $root === $script) ? '' : '../';
    }
    return $base;
}

/** URL absoluta da raiz da aplicação (para links em e-mails). */
function app_url()
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $root   = realpath(__DIR__ . '/..');
    $doc    = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $path   = ($doc && $root && strpos($root, $doc) === 0) ? str_replace('\\', '/', substr($root, strlen($doc))) : '';
    return $scheme . '://' . $host . rtrim($path, '/');
}

function redirect_to($path)
{
    header('Location: ' . $path);
    exit();
}

function require_login($loginPath = null)
{
    if (!is_logged_in()) {
        // Guarda a página pedida para voltar a ela depois do login (ex.: link de notificação ou de e-mail)
        $next = '';
        $raiz = realpath(__DIR__ . '/..');
        $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $raiz && $script && strpos($script, $raiz) === 0) {
            $rel = ltrim(str_replace('\\', '/', substr($script, strlen($raiz))), '/');
            $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
            if ($rel !== 'index.php' || $qs !== '') {
                $next = $rel . ($qs !== '' ? '?' . $qs : '');
            }
        }
        redirect_to(($loginPath ?: app_base() . 'php/login.php') . ($next !== '' ? '?next=' . rawurlencode($next) : ''));
    }
    // Revalida o usuário a cada 5 minutos (desativação/alteração de nível têm efeito rápido)
    if (empty($_SESSION['_rd_checked']) || $_SESSION['_rd_checked'] < time() - 300) {
        refresh_session_user();
    }
}

function refresh_session_user()
{
    global $pdo;
    try {
        $st = $pdo->prepare('SELECT id, nome, email, nivel, setor_id, status FROM usuarios WHERE id = ?');
        $st->execute([user_id()]);
        $u = $st->fetch();
        if (!$u || $u['status'] === 'Inativo') {
            $_SESSION = [];
            session_destroy();
            redirect_to(app_base() . 'php/login.php?erro=inativo');
        }
        $_SESSION['nome']     = $u['nome'];
        $_SESSION['email']    = $u['email'];
        $_SESSION['nivel']    = (int) $u['nivel'];
        $_SESSION['setor_id'] = $u['setor_id'];
        $_SESSION['_rd_checked'] = time();
    } catch (Exception $e) {
        // falha momentânea: mantém a sessão
    }
}

function require_level($required_level, $loginPath = null, $deniedPath = null)
{
    require_login($loginPath);
    if (user_level() < (int) $required_level) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'Você não tem permissão para acessar aquela página.'];
        redirect_to($deniedPath ?: app_base() . (user_level() >= NIVEL_ARQUIVO ? 'index.php' : 'php/solicitacoes.php'));
    }
}

/** Usuários de setor (nível 1) só acessam dados do próprio setor. */
function can_access_setor($setor_id)
{
    if (user_level() >= NIVEL_ARQUIVO) {
        return true;
    }
    return $setor_id !== null && (int) $setor_id === (int) user_setor();
}

/* ------------------------------------------------------------------ */
/* CSRF                                                                */
/* ------------------------------------------------------------------ */

function csrf_token()
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid()
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
}

/* ------------------------------------------------------------------ */
/* Respostas JSON e proteção dos endpoints                              */
/* ------------------------------------------------------------------ */

/** Resposta JSON padronizada. Mantém 'message' e 'mensagem' por compatibilidade. */
function json_out($status, $message = '', array $extra = [], $http_code = 200)
{
    if (ob_get_level() > 0) {
        $stray = ob_get_clean();
        if (trim((string) $stray) !== '') {
            error_log('ReviverDoc - saída inesperada descartada: ' . substr($stray, 0, 500));
        }
    }
    http_response_code($http_code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(array_merge($extra, ['status' => $status, 'message' => $message, 'mensagem' => $message]), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Protege um endpoint: exige login, nível mínimo, método HTTP e token CSRF em requisições POST.
 * Responde em JSON quando a verificação falha.
 */
function api_guard($min_level = NIVEL_SETOR, array $methods = ['GET', 'POST'])
{
    ob_start();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, $methods, true)) {
        json_out('error', 'Método não permitido.', [], 405);
    }
    if (!is_logged_in()) {
        json_out('error', 'Sua sessão expirou. Entre novamente para continuar.', ['login' => true], 401);
    }
    if (empty($_SESSION['_rd_checked']) || $_SESSION['_rd_checked'] < time() - 300) {
        refresh_session_user();
    }
    if (user_level() < (int) $min_level) {
        json_out('error', 'Você não tem permissão para realizar esta ação.', [], 403);
    }
    if ($method === 'POST' && !csrf_valid()) {
        json_out('error', 'A página ficou aberta por muito tempo. Recarregue e tente novamente.', ['csrf' => true], 419);
    }
    if ($method === 'POST' && empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > upload_limite_bytes()) {
        // O PHP descarta todo o formulário quando o envio passa do limite do servidor (post_max_size)
        json_out('error', 'O arquivo enviado é maior que o limite aceito pelo servidor (' . upload_limite_texto() . '). Envie um arquivo menor.', [], 413);
    }
}

/** Limite real de upload do servidor em bytes (o menor entre upload_max_filesize e post_max_size). */
function upload_limite_bytes()
{
    $b = function ($v) {
        $v = trim((string) $v);
        if ($v === '' || $v === '0' || $v === '-1') return PHP_INT_MAX;
        $n = (float) $v;
        switch (strtolower(substr($v, -1))) {
            case 'g': $n *= 1024;
            case 'm': $n *= 1024;
            case 'k': $n *= 1024;
        }
        return (int) $n;
    };
    return min($b(ini_get('upload_max_filesize')), $b(ini_get('post_max_size')));
}

function upload_limite_texto($teto_mb = null)
{
    $l = upload_limite_bytes();
    if ($teto_mb) $l = min($l, $teto_mb * 1048576);
    return $l >= PHP_INT_MAX ? 'sem limite' : round($l / 1048576) . ' MB';
}

/** Usuário de setor sem setor definido não consegue ver o que cadastra: bloqueia o cadastro com uma mensagem clara. */
function exigir_setor_do_usuario()
{
    if (user_level() == NIVEL_SETOR && !user_setor()) {
        json_out('error', 'Seu usuário não está vinculado a nenhum setor. Peça a um administrador para definir o seu setor em Usuários.', [], 422);
    }
}

/** Para endpoints que devolvem HTML/PDF em vez de JSON. */
function page_guard($min_level = NIVEL_SETOR)
{
    if (!is_logged_in()) {
        http_response_code(401);
        exit('Sessão expirada. Entre novamente no sistema.');
    }
    if (empty($_SESSION['_rd_checked']) || $_SESSION['_rd_checked'] < time() - 300) {
        refresh_session_user();
    }
    if (user_level() < (int) $min_level) {
        http_response_code(403);
        exit('Você não tem permissão para acessar este recurso.');
    }
}

/* ------------------------------------------------------------------ */
/* Numeração de caixas                                                 */
/* ------------------------------------------------------------------ */

/**
 * Próximo número de caixa. Considera só números normais (até 9 dígitos): um número negativo
 * ("-5") era lido pelo MySQL como 18446744073709551611 e gerava caixas com número "1.8E+19".
 */
function proximo_numero_caixa()
{
    global $pdo;
    $max = $pdo->query("SELECT MAX(CAST(numero AS UNSIGNED)) FROM caixas WHERE numero REGEXP '^[0-9]{1,9}$'")->fetchColumn();
    return (int) $max + 1;
}

/** Número que quebra a numeração: negativo (-5), notação científica (1.8E+19) ou número gigante. */
function numero_caixa_invalido($numero)
{
    $n = trim((string) $numero);
    return (bool) preg_match('/^-\s*\d|[0-9][.,]?[0-9]*e[+-]?\d+$/i', $n) || (bool) preg_match('/^\d{10,}$/', $n);
}

/** Apaga as notificações que apontam para um registro excluído (ex.: 'php/solicitacoes.php?id=', 12). */
function notificacoes_remover_links(array $prefixos, $id)
{
    global $pdo;
    $st = $pdo->prepare('DELETE FROM notificacoes WHERE link = ? OR link LIKE ? OR link = ? OR link LIKE ?');
    foreach ($prefixos as $pre) {
        $l = $pre . (int) $id;
        $st->execute([$l, $l . '&%', '../' . $l, '../' . $l . '&%']);
    }
}

/** Violação de chave estrangeira/única (MySQL 1451/1452/1062, SQLSTATE 23000). */
function is_constraint_error(Exception $e)
{
    $info = ($e instanceof PDOException && is_array($e->errorInfo)) ? $e->errorInfo : [];
    return $e->getCode() == '23000' || in_array((int) ($info[1] ?? 0), [1062, 1451, 1452], true)
        || stripos($e->getMessage(), 'foreign key') !== false || stripos($e->getMessage(), 'duplicate') !== false;
}

function db_error_message(Exception $e, $fallback = 'Não foi possível concluir a operação.')
{
    rd_log_erro(get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    if (user_level() >= NIVEL_ADMIN) {
        $fallback .= ' Detalhe: ' . $e->getMessage();
    }
    return $fallback;
}

/* ------------------------------------------------------------------ */
/* Formatação                                                          */
/* ------------------------------------------------------------------ */

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function valid_date($d)
{
    return is_string($d) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[1] >= 1800;
}

function post_date($key)
{
    $v = trim((string) ($_POST[$key] ?? ''));
    return valid_date($v) ? $v : null;
}

function fmt_date($date, $format = 'd/m/Y', $empty = '—')
{
    if (empty($date) || strpos((string) $date, '0000-00-00') === 0) {
        return $empty;
    }
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : $empty;
}

function fmt_datetime($date, $empty = '—')
{
    return fmt_date($date, 'd/m/Y H:i', $empty);
}

/** Período compacto: "05 - 28/02/25" quando no mesmo mês, senão "05/01/25 - 28/02/25". */
function fmt_periodo($inicio, $fim, $empty = '—')
{
    $zero = function ($d) { return empty($d) || strpos((string) $d, '0000-00-00') === 0; };
    if ($zero($inicio)) $inicio = null;
    if ($zero($fim)) $fim = null;
    if (!$inicio && !$fim) {
        return $empty;
    }
    if (!$inicio) {
        return 'até ' . fmt_date($fim, 'd/m/y');
    }
    if (!$fim) {
        return 'desde ' . fmt_date($inicio, 'd/m/y');
    }
    $i = strtotime($inicio);
    $f = strtotime($fim);
    if (!$i || !$f) {
        return $empty;
    }
    if (date('mY', $i) === date('mY', $f)) {
        return date('d', $i) . ' - ' . date('d/m/y', $f);
    }
    return date('d/m/y', $i) . ' - ' . date('d/m/y', $f);
}

/** Converte texto UTF-8 para a codificação usada pelo FPDF (substitui utf8_decode, obsoleto no PHP 8.2+). */
function pdf_txt($text)
{
    $text = (string) $text;
    if (function_exists('iconv')) {
        $r = @iconv('UTF-8', 'windows-1252//TRANSLIT', $text);
        if ($r !== false) return $r;
    }
    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }
    // Último recurso: converte só os caracteres latinos (U+0080–U+00FF)
    return preg_replace_callback('/[\xC2-\xC3][\x80-\xBF]/', function ($m) {
        return chr(((ord($m[0][0]) & 0x1F) << 6) | (ord($m[0][1]) & 0x3F));
    }, $text);
}

function fmt_num($n)
{
    return number_format((float) $n, 0, ',', '.');
}

function fmt_id($id, $pad = 4)
{
    return str_pad((string) (int) $id, $pad, '0', STR_PAD_LEFT);
}

function rel_time($datetime)
{
    $ts = strtotime((string) $datetime);
    if (!$ts) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 60) return 'agora';
    if ($diff < 3600) return 'há ' . floor($diff / 60) . ' min';
    if ($diff < 86400) return 'há ' . floor($diff / 3600) . ' h';
    if ($diff < 172800) return 'ontem';
    if ($diff < 604800) return 'há ' . floor($diff / 86400) . ' dias';
    return date('d/m/Y', $ts);
}

function mes_label($ym)
{
    $meses = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    $p = explode('-', (string) $ym);
    if (count($p) < 2) return (string) $ym;
    return ($meses[(int) $p[1]] ?? $p[1]) . '/' . substr($p[0], 2);
}

/* ------------------------------------------------------------------ */
/* Paginação e filtros                                                 */
/* ------------------------------------------------------------------ */

function current_page()
{
    $p = isset($_GET['page']) && ctype_digit((string) $_GET['page']) ? (int) $_GET['page'] : 1;
    return max(1, $p);
}

/** Parâmetros atuais da URL sem paginação e sem links diretos (?id, ?editar, ?nova...). */
function clean_query()
{
    $q = $_GET;
    unset($q['page'], $q['export'], $q['id'], $q['editar'], $q['nova'], $q['novo'], $q['chat']);
    return $q;
}

function render_pagination($page, $total_pages, $total_records = null, $per_page = 20)
{
    $html = '<nav class="pagination" aria-label="Paginação">';
    if ($total_records !== null) {
        $from = $total_records ? (($page - 1) * $per_page + 1) : 0;
        $to   = min($total_records, $page * $per_page);
        $html .= '<span class="pagination-info">' . fmt_num($from) . '–' . fmt_num($to) . ' de ' . fmt_num($total_records) . '</span>';
    }
    if ($total_pages > 1) {
        $q = clean_query();
        $base = http_build_query($q);
        $url = function ($p) use ($base) {
            return '?' . ($base ? $base . '&' : '') . 'page=' . $p;
        };
        $html .= '<div class="pagination-pages">';
        $html .= $page > 1
            ? '<a class="page-link" href="' . e($url($page - 1)) . '" aria-label="Página anterior"><i class="fa-solid fa-chevron-left"></i></a>'
            : '<span class="page-link is-disabled"><i class="fa-solid fa-chevron-left"></i></span>';
        $window = 2;
        $last_printed = 0;
        for ($i = 1; $i <= $total_pages; $i++) {
            if ($i === 1 || $i === (int) $total_pages || ($i >= $page - $window && $i <= $page + $window)) {
                if ($last_printed && $i - $last_printed > 1) {
                    $html .= '<span class="page-gap">…</span>';
                }
                $html .= '<a class="page-link' . ($i === $page ? ' is-active" aria-current="page' : '') . '" href="' . e($url($i)) . '">' . $i . '</a>';
                $last_printed = $i;
            }
        }
        $html .= $page < $total_pages
            ? '<a class="page-link" href="' . e($url($page + 1)) . '" aria-label="Próxima página"><i class="fa-solid fa-chevron-right"></i></a>'
            : '<span class="page-link is-disabled"><i class="fa-solid fa-chevron-right"></i></span>';
        $html .= '</div>';
    }
    return $html . '</nav>';
}

/** Monta <option> de um array de setores. */
function setor_options(array $setores, $selected = '', $placeholder = null)
{
    $html = $placeholder !== null ? '<option value="">' . e($placeholder) . '</option>' : '';
    foreach ($setores as $s) {
        $html .= '<option value="' . (int) $s['id'] . '"' . ((string) $selected === (string) $s['id'] ? ' selected' : '') . '>' . e($s['nome']) . '</option>';
    }
    return $html;
}

function export_url()
{
    $q = clean_query();
    $q['export'] = 'csv';
    return '?' . http_build_query($q);
}

/** Envia CSV (separador ';' e BOM para abrir corretamente no Excel). */
function csv_download($filename, array $headers, $rows)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers, ';');
    foreach ($rows as $row) {
        // Evita que o Excel interprete valores como fórmulas (=, +, -, @)
        $row = array_map(function ($v) {
            $v = (string) $v;
            return (strlen($v) > 1 && strpos('=+-@', $v[0]) !== false && !is_numeric($v)) ? "'" . $v : $v;
        }, array_values($row));
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit;
}

/* ------------------------------------------------------------------ */
/* Banco: utilitários                                                  */
/* ------------------------------------------------------------------ */

function table_exists($table)
{
    global $pdo;
    static $cache = [];
    if (!array_key_exists($table, $cache)) {
        try {
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $st->execute([$table]);
            $cache[$table] = (int) $st->fetchColumn() > 0;
        } catch (Exception $e) {
            $cache[$table] = false;
        }
    }
    return $cache[$table];
}

function all_setores()
{
    global $pdo;
    static $setores = null;
    if ($setores === null) {
        $setores = $pdo->query('SELECT id, nome FROM setores ORDER BY nome')->fetchAll();
    }
    return $setores;
}

function setor_nome($id)
{
    foreach (all_setores() as $s) {
        if ((int) $s['id'] === (int) $id) {
            return $s['nome'];
        }
    }
    return null;
}

/* ------------------------------------------------------------------ */
/* Histórico de atividades (requer a tabela logs_atividade — opcional)  */
/* ------------------------------------------------------------------ */

function log_activity($acao, $entidade, $entidade_id = null, $descricao = '')
{
    global $pdo;
    if (!table_exists('logs_atividade')) {
        return;
    }
    try {
        $st = $pdo->prepare('INSERT INTO logs_atividade (usuario_id, acao, entidade, entidade_id, descricao, ip) VALUES (?, ?, ?, ?, ?, ?)');
        $st->execute([user_id() ?: null, $acao, $entidade, $entidade_id, mb_substr((string) $descricao, 0, 500), $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) {
        error_log('ReviverDoc - falha ao registrar histórico: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ */
/* Notificações                                                        */
/* ------------------------------------------------------------------ */

/** Cria notificação para os usuários informados; com $dedupe evita repetir uma não lida com o mesmo link. */
function notify($usuario_ids, $mensagem, $link = null, $dedupe = true)
{
    global $pdo;
    $ids = array_unique(array_filter(array_map('intval', (array) $usuario_ids)));
    // Ninguém precisa ser notificado da própria ação
    $ids = array_diff($ids, [user_id()]);
    if (!$ids) {
        return;
    }
    try {
        $check = $pdo->prepare('SELECT COUNT(*) FROM notificacoes WHERE usuario_id = ? AND link = ? AND lida = 0');
        $ins   = $pdo->prepare('INSERT INTO notificacoes (usuario_id, mensagem, link) VALUES (?, ?, ?)');
        foreach ($ids as $uid) {
            if ($dedupe && $link) {
                $check->execute([$uid, $link]);
                if ((int) $check->fetchColumn() > 0) {
                    continue;
                }
            }
            $ins->execute([$uid, $mensagem, $link]);
        }
    } catch (Exception $e) {
        error_log('ReviverDoc - falha ao notificar: ' . $e->getMessage());
    }
}

function user_ids_by_level($nivel)
{
    global $pdo;
    $st = $pdo->prepare("SELECT id FROM usuarios WHERE nivel = ? AND (status IS NULL OR status IN ('Ativo', ''))");
    $st->execute([(int) $nivel]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** Usuários ativos da equipe do Arquivo e administradores (nível 2 ou mais). */
function equipe_arquivo_ids()
{
    return array_merge(user_ids_by_level(NIVEL_ARQUIVO), user_ids_by_level(NIVEL_ADMIN));
}

/** Marca como lidas as notificações de um registro já tratado (ex.: romaneio aprovado por outro administrador). */
function notificacoes_marcar_lidas_link($link, $exceto_usuario = 0)
{
    global $pdo;
    try {
        $pdo->prepare('UPDATE notificacoes SET lida = 1 WHERE (link = ? OR link = ?) AND usuario_id <> ? AND lida = 0')
            ->execute([$link, '../' . $link, (int) $exceto_usuario]);
    } catch (Exception $e) {
    }
}

/* ------------------------------------------------------------------ */
/* Configurações (php/config.json) e e-mail                             */
/* ------------------------------------------------------------------ */

function app_config($recarregar = false)
{
    static $cfg = null;
    if ($cfg === null || $recarregar) {
        $path = __DIR__ . '/../php/config.json';
        $cfg = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
    }
    return $cfg;
}

/** Grava chaves no php/config.json mantendo as demais. Retorna true/false. */
function app_config_salvar(array $valores)
{
    $path = __DIR__ . '/../php/config.json';
    $fp = @fopen($path, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    $atual = json_decode((string) stream_get_contents($fp), true) ?: [];
    foreach ($valores as $k => $v) $atual[$k] = $v;
    ftruncate($fp, 0);
    rewind($fp);
    $ok = fwrite($fp, json_encode($atual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    app_config(true);
    return $ok;
}

/**
 * Situação do recebimento de solicitações de pesquisa (ligado/desligado pelo Arquivo).
 * Sem configuração = recebendo normalmente.
 */
function solicitacoes_recebimento()
{
    $cfg = app_config();
    return [
        'aberto'   => !array_key_exists('solicitacoes_abertas', $cfg) || !empty($cfg['solicitacoes_abertas']),
        'mensagem' => (string) ($cfg['solicitacoes_pausa_msg'] ?? ''),
        'por'      => (string) ($cfg['solicitacoes_pausa_por'] ?? ''),
        'em'       => (string) ($cfg['solicitacoes_pausa_em'] ?? ''),
    ];
}

/** Aviso exibido quando o recebimento está pausado ('' quando está aberto). */
function solicitacoes_pausa_html($staff)
{
    $r = solicitacoes_recebimento();
    if ($r['aberto']) return '';
    $quando = $r['em'] ? ' desde ' . date('d/m/Y \à\s H:i', strtotime($r['em'])) : '';
    $quem = $r['por'] ? ' por ' . e($r['por']) : '';
    $msg = $r['mensagem'] !== '' ? '<br><span>' . nl2br(e($r['mensagem'])) . '</span>' : '';
    if ($staff) {
        return '<div class="alert alert-warning mb-2" id="avisoPausa"><i class="fa-solid fa-circle-pause"></i><div><strong>Recebimento de solicitações pausado</strong>' . $quem . $quando
            . '. Os setores não conseguem abrir novas solicitações; as que já existem continuam normalmente.' . $msg . '</div></div>';
    }
    return '<div class="alert alert-warning mb-2" id="avisoPausa"><i class="fa-solid fa-circle-pause"></i><div><strong>O Arquivo pausou o recebimento de novas solicitações' . $quando . '.</strong>'
        . ' Você pode acompanhar e conversar nas solicitações já abertas.' . $msg . '</div></div>';
}

/**
 * Lê o campo romaneios.conteudo nos três formatos que existem:
 *  - texto livre (descrição da saída, ou registros antigos);
 *  - lista JSON de caixas (entradas antigas);
 *  - objeto JSON {"descricao": "...", "caixas": [...]} (entradas novas).
 * Retorna ['descricao' => string, 'caixas' => array].
 */
function rom_conteudo_dados($conteudo)
{
    $conteudo = trim((string) $conteudo);
    if ($conteudo === '') return ['descricao' => '', 'caixas' => []];
    if ($conteudo[0] === '[' || $conteudo[0] === '{') {
        $j = json_decode($conteudo, true);
        if (is_array($j)) {
            if (!$j || array_keys($j) === range(0, count($j) - 1)) {
                return ['descricao' => '', 'caixas' => array_values(array_filter($j, 'is_array'))];
            }
            return [
                'descricao' => trim((string) ($j['descricao'] ?? '')),
                'caixas'    => array_values(array_filter((array) ($j['caixas'] ?? []), 'is_array')),
            ];
        }
    }
    // Entradas aprovadas na versão antiga gravavam "Caixas criadas: ..." (as caixas já aparecem vinculadas)
    if (strpos($conteudo, 'Caixas criadas: ') === 0) return ['descricao' => '', 'caixas' => []];
    return ['descricao' => $conteudo, 'caixas' => []];
}

/** Lista de destinatários a partir de "a@x.com, b@y.com" (vírgula, ponto e vírgula ou espaço). */
function emails_lista($texto)
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', (string) $texto) as $e) {
        $e = trim($e);
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[strtolower($e)] = $e;
    }
    return array_values($out);
}

/** Último erro de envio (para mostrar no teste de e-mail e no registro de erros). */
function send_mail_ultimo_erro($novo = null)
{
    static $erro = '';
    if ($novo !== null) $erro = (string) $novo;
    return $erro;
}

/**
 * Envia e-mail HTML via SMTP configurado em Sistema e e-mail.
 * $to aceita um endereço, vários separados por vírgula ou um array.
 * Retorna true/false sem interromper o fluxo; falhas vão para o Registro de erros.
 * Com $debug = true, guarda a conversa com o servidor SMTP em send_mail_ultimo_erro().
 */
function send_mail($to, $subject, $html, $fromName = 'ReviverDoc', $debug = false, array $cfg = null)
{
    send_mail_ultimo_erro('');
    if ($cfg === null) $cfg = app_config();
    $destinos = is_array($to) ? emails_lista(implode(',', $to)) : emails_lista($to);
    if (!$destinos) {
        send_mail_ultimo_erro('Nenhum destinatário válido.');
        return false;
    }
    if (empty($cfg['smtp_host']) || empty($cfg['smtp_user'])) {
        send_mail_ultimo_erro('Servidor SMTP não configurado (Configurações › Sistema e e-mail).');
        rd_log_erro('E-mail não enviado ("' . $subject . '"): servidor SMTP não configurado.');
        return false;
    }
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        send_mail_ultimo_erro('Biblioteca de e-mail ausente: envie a pasta vendor/ para o servidor.');
        rd_log_erro('E-mail não enviado: vendor/autoload.php não encontrado (a pasta vendor/ não foi enviada ao servidor).');
        return false;
    }
    require_once $autoload;
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        send_mail_ultimo_erro('Biblioteca PHPMailer ausente em vendor/phpmailer.');
        rd_log_erro('E-mail não enviado: PHPMailer não encontrado em vendor/phpmailer.');
        return false;
    }
    $porta = (int) ($cfg['smtp_port'] ?? 0) ?: 587;
    $seg = strtolower(trim((string) ($cfg['smtp_secure'] ?? 'tls')));
    if ($seg === 'starttls') $seg = 'tls';
    if ($seg === 'tls' && $porta === 465) $seg = 'ssl';      // 465 é sempre SSL direto
    if ($seg === 'ssl' && $porta === 587) $seg = 'tls';      // 587 é sempre STARTTLS
    if (in_array($seg, ['tls', 'ssl'], true) && !extension_loaded('openssl')) {
        send_mail_ultimo_erro('A extensão openssl do PHP está desativada no servidor; ela é necessária para TLS/SSL.');
        rd_log_erro('E-mail não enviado: extensão openssl do PHP ausente (necessária para TLS/SSL).');
        return false;
    }
    $conversa = '';
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = trim((string) $cfg['smtp_host']);
        $mail->Port       = $porta;
        $mail->SMTPAuth   = true;
        $mail->Username   = trim((string) $cfg['smtp_user']);
        $senha = (string) ($cfg['smtp_pass'] ?? '');
        if (stripos($mail->Host, 'gmail') !== false) $senha = str_replace(' ', '', $senha); // senha de app do Google vem em blocos de 4
        $mail->Password   = $senha;
        $mail->SMTPSecure = $seg;
        $mail->SMTPAutoTLS = ($seg !== '');
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = 'base64';
        $mail->Timeout    = 15;
        if ($debug) {
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = function ($str) use (&$conversa) {
                // não expõe a senha (AUTH LOGIN manda usuário/senha em base64)
                if (preg_match('/^CLIENT -> SERVER: [A-Za-z0-9+\/=]{8,}\s*$/', trim($str))) $str = "CLIENT -> SERVER: [credencial oculta]\n";
                $str = preg_replace('/^(CLIENT -> SERVER: AUTH (PLAIN|XOAUTH2)) \S+/i', '$1 [credencial oculta]', $str);
                $conversa .= $str;
            };
        }
        $mail->setFrom($mail->Username, $fromName);
        foreach ($destinos as $d) {
            $mail->addAddress($d);
        }
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = trim(html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/tr|\/h\d)[^>]*>/i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
        $mail->send();
        return true;
    } catch (\Exception $e) {
        $msg = isset($mail) && $mail->ErrorInfo ? $mail->ErrorInfo : $e->getMessage();
        send_mail_ultimo_erro($msg . ($conversa !== '' ? "\n\n" . $conversa : ''));
        rd_log_erro('Falha ao enviar e-mail "' . $subject . '" para ' . implode(', ', $destinos) . ': ' . $msg);
        return false;
    }
}

/** E-mail dos usuários (ativos) a partir dos ids. */
function emails_de_usuarios(array $ids)
{
    global $pdo;
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    try {
        $st = $pdo->prepare('SELECT email FROM usuarios WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ")
            AND (status IS NULL OR status IN ('Ativo', ''))");
        $st->execute($ids);
        return emails_lista(implode(',', $st->fetchAll(PDO::FETCH_COLUMN)));
    } catch (Exception $e) {
        return [];
    }
}

/* Limite de tentativas de login por IP + e-mail (arquivo temporário, independe da sessão) */
function login_throttle_file($email)
{
    $dir = __DIR__ . '/../backup_temp/login_throttle';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . '/' . sha1(($_SERVER['REMOTE_ADDR'] ?? '') . '|' . strtolower(trim((string) $email))) . '.json';
}
function login_blocked($email)
{
    $f = login_throttle_file($email);
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    return $d && $d['n'] >= 5 && $d['t'] > time() - 300;
}
function login_fail($email)
{
    $f = login_throttle_file($email);
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (!$d || $d['t'] < time() - 300) {
        $d = ['n' => 0, 't' => time()];
    }
    $d['n']++;
    $d['t'] = time();
    @file_put_contents($f, json_encode($d));
}
function login_reset($email)
{
    @unlink(login_throttle_file($email));
}

function flash_pop()
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/* ------------------------------------------------------------------ */
/* Uploads                                                             */
/* ------------------------------------------------------------------ */

/** Valida um upload de PDF (extensão + assinatura do arquivo). Retorna mensagem de erro ou null. */
function validate_pdf_upload(array $file, $max_mb = 50)
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return upload_error_message($file['error']);
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        return 'Envie um arquivo em PDF.';
    }
    $fh = @fopen($file['tmp_name'], 'rb');
    $sig = $fh ? fread($fh, 5) : '';
    if ($fh) fclose($fh);
    if ($sig !== '%PDF-') {
        return 'O arquivo enviado não é um PDF válido.';
    }
    if ($file['size'] > $max_mb * 1024 * 1024) {
        return "O arquivo excede o limite de {$max_mb} MB.";
    }
    return null;
}

function upload_error_message($code)
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return 'O arquivo é maior que o limite permitido pelo servidor (' . upload_limite_texto() . ').';
        case UPLOAD_ERR_PARTIAL:   return 'O envio do arquivo foi interrompido. Tente novamente.';
        case UPLOAD_ERR_NO_FILE:   return 'Nenhum arquivo foi selecionado.';
        default:                   return 'Não foi possível receber o arquivo (código ' . (int) $code . ').';
    }
}

function slugify($text)
{
    $t = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $text) : (string) $text;
    $t = preg_replace('/[^a-zA-Z0-9]+/', '-', (string) $t);
    $t = strtolower(trim((string) $t, '-'));
    return substr($t, 0, 80);
}

/** Gera miniatura JPG da(s) primeira(s) página(s) de um PDF com ImageMagick, se disponível. */
function make_pdf_thumb($pdfPath, $thumbPath, $mode = 'documento')
{
    if (!function_exists('exec')) {
        return false;
    }
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $bins  = $isWin ? ['magick'] : ['magick', 'convert'];
    $src   = escapeshellarg($pdfPath . ($mode === 'pagina' ? '[0-1]' : '[0]'));
    $dst   = escapeshellarg($thumbPath);
    $opts  = $mode === 'pagina'
        ? '-density 150 ' . $src . ' +append -quality 85 -thumbnail 600x'
        : '-density 150 ' . $src . ' -crop 100%x50%+0+0 +repage -quality 85 -thumbnail 400x';
    foreach ($bins as $bin) {
        $out = [];
        $ret = 1;
        @exec($bin . ' ' . $opts . ' ' . $dst . ' 2>&1', $out, $ret);
        if ($ret === 0 && is_file($thumbPath)) {
            return true;
        }
    }
    error_log('ReviverDoc - miniatura não gerada para ' . basename($pdfPath));
    return false;
}

function thumb_url_for($arquivo)
{
    if (empty($arquivo)) {
        return null;
    }
    $name = pathinfo($arquivo, PATHINFO_FILENAME) . '.jpg';
    return is_file(__DIR__ . '/../uploads/thumbs/' . $name) ? 'uploads/thumbs/' . rawurlencode($name) : null;
}

$sessionData = get_session_data();
$isAdmin = ((int) $sessionData['nivel'] === NIVEL_ADMIN);
