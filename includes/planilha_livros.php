<?php
/**
 * Leitura da planilha de livros (planilha_arquivo.xlsx, na raiz do sistema) usada na sugestão de livro.
 * Colunas esperadas: ID_UNICO | Nº DO LIVRO | SETOR (DONO) | PERÍODO | MÊS 1 | MÊS 2 | ANO 1 | ANO 2
 * O .xlsx é lido diretamente (ZipArchive ou leitor em PHP puro + XML, sem bibliotecas extras) e o resultado fica em cache
 * até o arquivo ser alterado.
 */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(403); exit; }
require_once __DIR__ . '/bootstrap.php';

const PLANILHA_LIVROS = __DIR__ . '/../planilha_arquivo.xlsx';

function planilha_livros_cache_file()
{
    return __DIR__ . '/../backup_temp/planilha_livros_cache.json';
}

/** Converte "A1"/"BC12" na posição da coluna (0 = A). */
function planilha_col_index($ref)
{
    $letras = preg_replace('/\d+/', '', $ref);
    $n = 0;
    for ($i = 0; $i < strlen($letras); $i++) {
        $n = $n * 26 + (ord($letras[$i]) - 64);
    }
    return $n - 1;
}

/**
 * Leitor de .zip em PHP puro (usado quando a extensão zip do PHP não está instalada no servidor).
 * Devolve [nome => conteúdo] dos arquivos pedidos (ou de todos, se $nomes for null). Requer apenas zlib.
 */
function rd_zip_ler($path, $nomes = null)
{
    $bin = @file_get_contents($path);
    if ($bin === false || strlen($bin) < 22) {
        throw new RuntimeException('Não foi possível abrir a planilha de livros.');
    }
    $eocd = strrpos(substr($bin, -65557), "PK\x05\x06");
    if ($eocd === false) {
        throw new RuntimeException('A planilha de livros não é um arquivo .xlsx válido.');
    }
    $eocd += max(0, strlen($bin) - 65557);
    $e = unpack('vdisk/vdiskcd/vqtdDisco/vqtd/Vtamanho/Voffset', substr($bin, $eocd + 4, 16));
    $pos = $e['offset'];
    $arquivos = [];
    for ($i = 0; $i < $e['qtd']; $i++) {
        if (substr($bin, $pos, 4) !== "PK\x01\x02") break;
        $c = unpack('vmetodo', substr($bin, $pos + 10, 2))
           + unpack('Vcomp/Vtam/vnome/vextra/vcoment', substr($bin, $pos + 20, 14))
           + unpack('Vlocal', substr($bin, $pos + 42, 4));
        $nome = substr($bin, $pos + 46, $c['nome']);
        $pos += 46 + $c['nome'] + $c['extra'] + $c['coment'];
        if ($nomes !== null && !in_array($nome, $nomes, true)) {
            $arquivos[$nome] = null;
            continue;
        }
        $l = unpack('vnome/vextra', substr($bin, $c['local'] + 26, 4));
        $dados = substr($bin, $c['local'] + 30 + $l['nome'] + $l['extra'], $c['comp']);
        if ($c['metodo'] === 8) {
            if (!function_exists('gzinflate')) {
                throw new RuntimeException('O PHP do servidor não tem as extensões zip nem zlib, necessárias para ler a planilha de livros.');
            }
            $dados = @gzinflate($dados);
        } elseif ($c['metodo'] !== 0) {
            $dados = false;
        }
        $arquivos[$nome] = $dados === false ? null : $dados;
    }
    return $arquivos;
}

/** Lê a primeira aba de um .xlsx e devolve as linhas (arrays de strings). */
function planilha_ler_xlsx($path)
{
    if (!function_exists('simplexml_load_string')) {
        throw new RuntimeException('A extensão SimpleXML do PHP é necessária para ler a planilha de livros.');
    }
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Não foi possível abrir a planilha de livros.');
        }
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        $sheetName = 'xl/worksheets/sheet1.xml';
        if ($zip->locateName($sheetName) === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = $zip->getNameIndex($i);
                if (strpos($n, 'xl/worksheets/sheet') === 0) { $sheetName = $n; break; }
            }
        }
        $xml = $zip->getFromName($sheetName);
        $zip->close();
    } else {
        // Sem a extensão zip: leitura em PHP puro
        $todos = rd_zip_ler($path, []);
        $sheetName = 'xl/worksheets/sheet1.xml';
        if (!array_key_exists($sheetName, $todos)) {
            foreach (array_keys($todos) as $n) {
                if (strpos($n, 'xl/worksheets/sheet') === 0) { $sheetName = $n; break; }
            }
        }
        $lidos = rd_zip_ler($path, ['xl/sharedStrings.xml', $sheetName]);
        $ss  = isset($lidos['xl/sharedStrings.xml']) ? $lidos['xl/sharedStrings.xml'] : false;
        $xml = isset($lidos[$sheetName]) ? $lidos[$sheetName] : false;
    }
    $shared = [];
    if ($ss !== false) {
        $x = simplexml_load_string($ss);
        foreach ($x->si as $si) {
            if (isset($si->t)) {
                $shared[] = (string) $si->t;
            } else {
                $t = '';
                foreach ($si->r as $r) {
                    $t .= (string) $r->t;
                }
                $shared[] = $t;
            }
        }
    }
    if ($xml === false) {
        throw new RuntimeException('A planilha de livros não tem nenhuma aba legível.');
    }
    $sheet = simplexml_load_string($xml);
    $linhas = [];
    foreach ($sheet->sheetData->row as $row) {
        $linha = [];
        foreach ($row->c as $c) {
            $idx = planilha_col_index((string) $c['r']);
            $tipo = (string) $c['t'];
            if ($tipo === 's') {
                $v = $shared[(int) $c->v] ?? '';
            } elseif ($tipo === 'inlineStr') {
                $v = (string) $c->is->t;
            } else {
                $v = (string) $c->v;
            }
            $linha[$idx] = trim($v);
        }
        if ($linha) {
            $max = max(array_keys($linha));
            $cheia = [];
            for ($i = 0; $i <= $max; $i++) $cheia[] = $linha[$i] ?? '';
            $linhas[] = $cheia;
        }
    }
    return $linhas;
}

/** Interpreta "27/05 a 01/09" + anos em datas de início e fim (Y-m-d). */
function planilha_intervalo($periodo, $anoIni, $anoFim)
{
    $ini = $fim = null;
    if (preg_match_all('#(\d{1,2})/(\d{1,2})#', $periodo, $m, PREG_SET_ORDER)) {
        if ($anoIni && isset($m[0])) {
            $ini = sprintf('%04d-%02d-%02d', $anoIni, $m[0][2], $m[0][1]);
        }
        if ($anoFim && isset($m[1])) {
            $fim = sprintf('%04d-%02d-%02d', $anoFim, $m[1][2], $m[1][1]);
        }
    }
    if (!$ini && $anoIni) $ini = sprintf('%04d-01-01', $anoIni);
    if (!$fim && $anoFim) $fim = sprintf('%04d-12-31', $anoFim);
    // Datas inválidas (ex.: 31/02) caem para o limite do ano
    if ($ini && !strtotime($ini)) $ini = sprintf('%04d-01-01', $anoIni);
    if ($fim && !strtotime($fim)) $fim = sprintf('%04d-12-31', $anoFim);
    return [$ini, $fim];
}

/** Livros da planilha já normalizados (com cache). Retorna [] se a planilha não existir. */
function planilha_livros()
{
    static $mem = null;
    if ($mem !== null) return $mem;
    if (!is_file(PLANILHA_LIVROS)) return $mem = [];

    $cache = planilha_livros_cache_file();
    $mtime = filemtime(PLANILHA_LIVROS);
    if (is_file($cache)) {
        $c = json_decode((string) @file_get_contents($cache), true);
        if (is_array($c) && ($c['mtime'] ?? 0) === $mtime && isset($c['livros'])) {
            return $mem = $c['livros'];
        }
    }

    $linhas = planilha_ler_xlsx(PLANILHA_LIVROS);
    $cab = array_map(function ($h) { return mb_strtoupper(trim($h)); }, array_shift($linhas) ?: []);
    $col = function ($nomes, $padrao) use ($cab) {
        foreach ($cab as $i => $h) {
            foreach ((array) $nomes as $n) {
                if (strpos($h, $n) !== false) return $i;
            }
        }
        return $padrao;
    };
    $cId = $col('ID', 0); $cNum = $col(['Nº DO LIVRO', 'LIVRO'], 1); $cSetor = $col('SETOR', 2); $cPer = $col(['PÉRIODO', 'PERÍODO', 'PERIODO'], 3);
    $cM1 = $col('MÊS 1', 4); $cM2 = $col('MÊS 2', 5); $cA1 = $col('ANO 1', 6); $cA2 = $col('ANO 2', 7);

    $livros = [];
    foreach ($linhas as $l) {
        $num = trim($l[$cNum] ?? '');
        if ($num === '') continue;
        preg_match_all('/(1[89]\d{2}|20\d{2})/', ($l[$cA1] ?? '') . ' ' . ($l[$cA2] ?? ''), $anos);
        $anos = array_map('intval', $anos[1]);
        $anoIni = $anos ? min($anos) : null;
        $anoFim = $anos ? max($anos) : null;
        $periodo = trim($l[$cPer] ?? '');
        list($ini, $fim) = planilha_intervalo($periodo, $anoIni, $anoFim);
        // Período incompleto (ex.: "30/11 A -"): usa os meses informados nas colunas MÊS 1 / MÊS 2
        $mesNum = function ($nome) {
            $k = substr(strtr(mb_strtolower(trim($nome), 'UTF-8'), ['ç' => 'c']), 0, 3);
            $map = ['jan' => 1, 'fev' => 2, 'mar' => 3, 'abr' => 4, 'mai' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8, 'set' => 9, 'out' => 10, 'nov' => 11, 'dez' => 12];
            return $map[$k] ?? 0;
        };
        $temDatas = preg_match_all('#\d{1,2}/\d{1,2}#', $periodo);
        if ($temDatas < 2 && $anoFim && ($m2 = $mesNum($l[$cM2] ?? ''))) {
            $fim = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $anoFim, $m2)));
        }
        if ($temDatas < 1 && $anoIni && ($m1 = $mesNum($l[$cM1] ?? ''))) {
            $ini = sprintf('%04d-%02d-01', $anoIni, $m1);
        }
        // Período que vira o ano mas com ANO 2 igual ao ANO 1 na planilha (ex.: 10/11 a 22/01): o fim é no ano seguinte
        if ($ini && $fim && $fim < $ini) {
            $fim = date('Y-m-d', strtotime($fim . ' +1 year'));
        }
        $livros[] = [
            'id'      => trim($l[$cId] ?? ''),
            'numero'  => preg_replace('/\.0$/', '', $num),
            'setor'   => trim($l[$cSetor] ?? ''),
            'periodo' => $periodo,
            'mes1'    => trim($l[$cM1] ?? ''),
            'mes2'    => trim($l[$cM2] ?? ''),
            'ano1'    => $anoIni,
            'ano2'    => $anoFim,
            'ini'     => $ini,
            'fim'     => $fim,
        ];
    }
    @file_put_contents($cache, json_encode(['mtime' => $mtime, 'livros' => $livros]));
    return $mem = $livros;
}

/**
 * Procura livros da planilha cujo período cruza as referências informadas.
 * $refs: lista de ['de' => Y-m-d, 'ate' => Y-m-d, 'tipo' => data|mes|ano]. $setor: filtro opcional (texto).
 * Resultados mais precisos (data exata, depois mês, depois ano) vêm primeiro.
 */
function planilha_buscar(array $refs, $setor = '')
{
    $peso = ['data' => 0, 'mes' => 1, 'ano' => 2];
    $res = [];
    foreach (planilha_livros() as $l) {
        if ($setor !== '' && stripos($l['setor'], $setor) === false) continue;
        if (!$l['ini'] || !$l['fim']) continue;
        $melhor = null;
        foreach ($refs as $r) {
            if ($r['de'] <= $l['fim'] && $r['ate'] >= $l['ini']) {
                if ($melhor === null || $peso[$r['tipo']] < $peso[$melhor]) $melhor = $r['tipo'];
            }
        }
        if ($melhor) {
            $l['motivo'] = $melhor;
            $res[] = $l;
        }
    }
    usort($res, function ($a, $b) use ($peso) {
        if ($a['motivo'] !== $b['motivo']) return $peso[$a['motivo']] - $peso[$b['motivo']];
        return strcmp((string) $a['ini'], (string) $b['ini']);
    });
    return $res;
}

/** Extrai datas (dd/mm/aaaa, dd/mm/aa), meses ("março de 2015", "03/2015") e anos de um texto. */
function planilha_referencias($texto)
{
    $refs = [];
    $usado = $texto;
    $anoMax = (int) date('Y') + 1;
    $ano4 = function ($a) { $a = (int) $a; return $a < 100 ? ($a > (int) date('y') + 1 ? 1900 + $a : 2000 + $a) : $a; };
    // datas completas
    if (preg_match_all('#\b(\d{1,2})[/.-](\d{1,2})[/.-](\d{2}|\d{4})\b#', $texto, $m, PREG_SET_ORDER)) {
        foreach ($m as $d) {
            $a = $ano4($d[3]);
            if (checkdate((int) $d[2], (int) $d[1], $a) && $a <= $anoMax) {
                $iso = sprintf('%04d-%02d-%02d', $a, $d[2], $d[1]);
                $refs[] = ['de' => $iso, 'ate' => $iso, 'tipo' => 'data', 'label' => sprintf('%02d/%02d/%04d', $d[1], $d[2], $a)];
            }
            $usado = str_replace($d[0], ' ', $usado);
        }
    }
    // mês/ano numérico (03/2015) e por extenso (março de 2015, mar/2015)
    $meses = ['jan' => 1, 'fev' => 2, 'mar' => 3, 'abr' => 4, 'mai' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8, 'set' => 9, 'out' => 10, 'nov' => 11, 'dez' => 12];
    $nomes = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $addMes = function ($mes, $a) use (&$refs, $nomes, $anoMax) {
        if ($mes < 1 || $mes > 12 || $a < 1800 || $a > $anoMax) return;
        $de = sprintf('%04d-%02d-01', $a, $mes);
        $refs[] = ['de' => $de, 'ate' => date('Y-m-t', strtotime($de)), 'tipo' => 'mes', 'label' => $nomes[$mes] . ' de ' . $a];
    };
    if (preg_match_all('#\b(\d{1,2})/(\d{4})\b#', $usado, $m, PREG_SET_ORDER)) {
        foreach ($m as $d) { $addMes((int) $d[1], (int) $d[2]); $usado = str_replace($d[0], ' ', $usado); }
    }
    $semAcento = strtr(mb_strtolower($usado, 'UTF-8'), ['ç' => 'c', 'ã' => 'a', 'á' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'ú' => 'u']);
    if (preg_match_all('#\b(jan|fev|mar|abr|mai|jun|jul|ago|set|out|nov|dez)[a-z]*\.?\s*(?:de\s+|/\s*)?(\d{4})\b#', $semAcento, $m, PREG_SET_ORDER)) {
        foreach ($m as $d) { $addMes($meses[$d[1]], (int) $d[2]); $semAcento = str_replace($d[0], ' ', $semAcento); }
    }
    // anos soltos
    if (preg_match_all('#\b(1[89]\d{2}|20\d{2})\b#', $semAcento, $m)) {
        foreach (array_unique($m[1]) as $a) {
            $a = (int) $a;
            if ($a > $anoMax) continue;
            $refs[] = ['de' => $a . '-01-01', 'ate' => $a . '-12-31', 'tipo' => 'ano', 'label' => (string) $a];
        }
    }
    return array_slice($refs, 0, 12);
}
