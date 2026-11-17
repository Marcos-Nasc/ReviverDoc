<?php
/**
 * Sugestão de livro para uma solicitação de pesquisa.
 * Fonte principal: a planilha de livros do arquivo (planilha_arquivo.xlsx).
 * Complementos: livros cadastrados no acervo e, se a planilha não existir, a tabela livros_sugestoes.
 *
 * GET ?q=texto (datas, meses e anos são extraídos) [&setor=texto]
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/planilha_livros.php';
api_guard(NIVEL_ARQUIVO, ['GET']);

$q     = trim((string) ($_GET['q'] ?? ($_GET['ano'] ?? '')));
$setor = trim((string) ($_GET['setor'] ?? ''));
$refs  = planilha_referencias($q);

$out = [
    'referencias'   => array_map(function ($r) { return ['label' => $r['label'], 'tipo' => $r['tipo']]; }, $refs),
    'anos'          => array_values(array_unique(array_map(function ($r) { return (int) substr($r['de'], 0, 4); }, $refs))),
    'tem_planilha'  => is_file(PLANILHA_LIVROS),
    'planilha'      => [],
    'total_planilha'=> 0,
    'sugestoes'     => [],
    'livros'        => [],
    'tabela_sugestoes' => false,
];

if (!$refs) {
    json_out('ok', '', $out);
}

$fmt = function ($iso) { return $iso ? date('d/m/Y', strtotime($iso)) : '—'; };

try {
    /* 1) Planilha de livros do arquivo */
    if ($out['tem_planilha']) {
        $achados = planilha_buscar($refs, $setor);
        $out['total_planilha'] = count($achados);
        foreach (array_slice($achados, 0, 60) as $l) {
            $out['planilha'][] = [
                'numero'  => $l['numero'],
                'setor'   => $l['setor'] ?: '—',
                'periodo' => $fmt($l['ini']) . ' a ' . $fmt($l['fim']),
                'original'=> trim($l['periodo'] . ($l['mes1'] ? ' (' . mb_strtolower($l['mes1'], 'UTF-8') . ($l['mes2'] && $l['mes2'] !== $l['mes1'] ? ' a ' . mb_strtolower($l['mes2'], 'UTF-8') : '') . ')' : '')),
                'motivo'  => $l['motivo'],
            ];
        }
        $out['setores_planilha'] = array_values(array_unique(array_filter(array_column(planilha_livros(), 'setor'))));
    } elseif (table_exists('livros_sugestoes')) {
        /* Sem planilha: usa a tabela livros_sugestoes do banco */
        $out['tabela_sugestoes'] = true;
        $cond = [];
        $p = [];
        foreach (array_unique($out['anos']) as $i => $ano) {
            $cond[] = "(ls.ano1 LIKE :a{$i} OR ls.ano2 LIKE :b{$i} OR (CAST(ls.ano1 AS UNSIGNED) > 0 AND CAST(ls.ano2 AS UNSIGNED) > 0 AND :d{$i} BETWEEN CAST(ls.ano1 AS UNSIGNED) AND CAST(ls.ano2 AS UNSIGNED)))";
            $p[":a{$i}"] = '%' . $ano . '%';
            $p[":b{$i}"] = '%' . $ano . '%';
            $p[":d{$i}"] = $ano;
        }
        if ($cond) {
            $st = $pdo->prepare('SELECT ls.numero_livro, ls.periodo, ls.ano1, ls.ano2 FROM livros_sugestoes ls WHERE ' . implode(' OR ', $cond)
                . ' ORDER BY CAST(ls.numero_livro AS UNSIGNED) LIMIT 60');
            $st->execute($p);
            foreach ($st as $r) {
                $out['sugestoes'][] = ['numero_livro' => (string) $r['numero_livro'], 'periodo' => trim($r['periodo'] . ' ' . $r['ano1'] . ($r['ano2'] && $r['ano2'] !== $r['ano1'] ? '/' . $r['ano2'] : ''))];
            }
        }
    }

    /* 2) Livros cadastrados no acervo cujo período cruza as referências */
    $cond = [];
    $p = [];
    foreach ($refs as $i => $r) {
        $cond[] = "(l.data_inicio IS NOT NULL AND l.data_fim IS NOT NULL AND l.data_inicio <= :ate{$i} AND l.data_fim >= :de{$i})";
        $p[":ate{$i}"] = $r['ate'];
        $p[":de{$i}"] = $r['de'];
    }
    $st = $pdo->prepare('SELECT l.id, l.numero_livro, l.data_inicio, l.data_fim, l.posicao, l.digitalizado, l.tipo, s.nome AS setor,
            (SELECT COUNT(*) FROM paginas pg WHERE pg.livro_id = l.id) AS paginas
        FROM livros l LEFT JOIN setores s ON s.id = l.setor WHERE ' . implode(' OR ', $cond) . '
        ORDER BY l.data_inicio, l.numero_livro LIMIT 40');
    $st->execute($p);
    foreach ($st as $l) {
        $out['livros'][] = [
            'id' => (int) $l['id'], 'numero_livro' => (string) $l['numero_livro'],
            'periodo' => fmt_date($l['data_inicio']) . ' a ' . fmt_date($l['data_fim']),
            'setor' => $l['setor'] ?: '—', 'posicao' => $l['posicao'] ?: '—', 'tipo' => $l['tipo'] ?: '',
            'digitalizado' => $l['digitalizado'] === 'Sim', 'paginas' => (int) $l['paginas'],
        ];
    }
    json_out('ok', '', $out);
} catch (Exception $e) {
    json_out('error', db_error_message($e, 'Não foi possível buscar sugestões de livro agora.'), [], 500);
}
