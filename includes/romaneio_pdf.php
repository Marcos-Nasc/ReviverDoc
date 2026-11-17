<?php
/** Romaneio para impressão (comprovante de saída/entrada com assinaturas). */
require_once __DIR__ . '/bootstrap.php';
page_guard(NIVEL_SETOR);
require_once __DIR__ . '/fpdf/fpdf.php';

$id = (int) ($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT r.*, u.nome AS usuario_nome, s.nome AS setor_nome
    FROM romaneios r
    LEFT JOIN usuarios u ON u.id = r.usuario_id
    LEFT JOIN setores s ON s.id = r.setor_id
    WHERE r.id = ?');
$st->execute([$id]);
$r = $st->fetch();
if (!$r || !can_access_setor($r['setor_id'])) {
    http_response_code(404);
    exit('Romaneio não encontrado ou sem permissão de acesso.');
}

// Itens: caixas vinculadas; para entrada ainda não aprovada, as caixas descritas no JSON
$itens = [];
$cx = $pdo->prepare('SELECT c.numero, c.conteudo, c.data_inicio, c.data_fim, c.coluna, c.fileira
    FROM romaneio_caixas rc JOIN caixas c ON c.id = rc.caixa_id
    WHERE rc.romaneio_id = ? ORDER BY CAST(c.numero AS UNSIGNED), c.id');
$cx->execute([$id]);
foreach ($cx->fetchAll() as $c) {
    $itens[] = [
        'numero'   => in_array(trim((string) $c['numero']), ['', '-'], true) ? 'S/N' : $c['numero'],
        'conteudo' => (string) $c['conteudo'],
        'periodo'  => ($c['data_inicio'] || $c['data_fim']) ? fmt_date($c['data_inicio']) . ' a ' . fmt_date($c['data_fim']) : '-',
        'local'    => trim($c['coluna'] . ' ' . $c['fileira']) ?: '-',
    ];
}
$dados = rom_conteudo_dados($r['conteudo']);
$descricao = $dados['descricao'];
$pendente_aprovacao = false;
if (!$itens && $r['tipo'] === 'Entrada') {
    $json = $dados['caixas'];
    if ($json) {
        $pendente_aprovacao = $r['aprovado'] !== 'Sim';
        foreach ($json as $c) {
            $itens[] = [
                'numero'   => 'A definir',
                'conteudo' => (string) ($c['conteudo'] ?? ''),
                'periodo'  => (!empty($c['data_inicio']) || !empty($c['data_fim'])) ? fmt_date($c['data_inicio'] ?? null) . ' a ' . fmt_date($c['data_fim'] ?? null) : '-',
                'local'    => '-',
            ];
        }
    }
}

class RomaneioPDF extends FPDF
{
    public $numero = '';

    function Footer()
    {
        $this->SetY(-14);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(110);
        $this->Cell(0, 5, pdf_txt('Romaneio nº ' . $this->numero . ' · emitido em ' . date('d/m/Y H:i') . ' · ReviverDoc'), 0, 0, 'L');
        $this->Cell(0, 5, pdf_txt('Página ' . $this->PageNo() . ' de {nb}'), 0, 0, 'R');
    }

    function fitCell($w, $h, $txt, $border = 1, $align = 'L', $fill = false)
    {
        $t = pdf_txt($txt);
        while ($this->GetStringWidth($t) > $w - 2 && strlen($t) > 3) {
            $t = substr($t, 0, -4) . '...';
        }
        $this->Cell($w, $h, $t, $border, 0, $align, $fill);
    }
}

$pdf = new RomaneioPDF('P', 'mm', 'A4');
$pdf->numero = fmt_id($id);
$pdf->AliasNbPages();
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 20);
$pdf->SetTitle(pdf_txt('Romaneio ' . fmt_id($id)));
$pdf->AddPage();

$logo = __DIR__ . '/fpdf/LOGO-REVIVER-VERTICAL-PNG.png';
$y = 15;
if (is_file($logo)) {
    $pdf->Image($logo, 15, 12, 24);
    $y = 40;
}
$titulo = $r['tipo'] === 'Saída' ? 'SAÍDA DE DOCUMENTOS DO ARQUIVO GERAL' : 'ENTRADA DE DOCUMENTOS AO ARQUIVO GERAL';
$pdf->SetXY(15, $y);
$pdf->SetFont('Arial', 'B', 14);
$pdf->SetTextColor(20, 33, 61);
$pdf->Cell(0, 8, pdf_txt($titulo), 0, 1, 'C');
$pdf->SetFont('Arial', '', 10);
$pdf->SetTextColor(80);
$pdf->Cell(0, 6, pdf_txt('Romaneio nº ' . fmt_id($id)), 0, 1, 'C');
if ($pendente_aprovacao) {
    $pdf->SetTextColor(150, 90, 0);
    $pdf->Cell(0, 6, pdf_txt('Aguardando aprovação — números das caixas serão definidos na aprovação'), 0, 1, 'C');
}
$pdf->Ln(4);
$pdf->SetTextColor(0);

// Bloco de informações
$info = [
    ['Setor', $r['setor_nome'] ?: '-', 'Solicitante', $r['usuario_nome'] ?: '-'],
    [$r['tipo'] === 'Saída' ? 'Data de saída' : 'Data de entrega', fmt_date($r['data_saida'], 'd/m/Y', '-'),
     $r['tipo'] === 'Saída' ? 'Retorno previsto' : 'Situação',
     $r['tipo'] === 'Saída' ? fmt_date($r['data_retorno'], 'd/m/Y', '-') : ($r['aprovado'] === 'Sim' ? 'Aprovado' : 'Aguardando aprovação')],
];
if ($r['tipo'] === 'Saída') {
    $info[] = $itens
        ? ['Situação', $r['status'] ?: 'Pendente', 'Quantidade de caixas', (string) count($itens)]
        : ['Situação', $r['status'] ?: 'Pendente', '', ''];
} elseif ($itens) {
    $info[] = ['Quantidade de caixas', (string) count($itens), '', ''];
}
foreach ($info as $row) {
    $pdf->SetFillColor(238, 240, 234);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(35, 8, pdf_txt($row[0]), 1, 0, 'L', true);
    $pdf->SetFont('Arial', '', 10);
    $pdf->fitCell(55, 8, $row[1]);
    if ($row[2] !== '') {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(35, 8, pdf_txt($row[2]), 1, 0, 'L', true);
        $pdf->SetFont('Arial', '', 10);
        $pdf->fitCell(55, 8, $row[3]);
    }
    $pdf->Ln();
}
$pdf->Ln(6);

// Descrição escrita no romaneio
if ($descricao !== '') {
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(20, 33, 61);
    $pdf->SetTextColor(255);
    $pdf->Cell(0, 8, pdf_txt($r['tipo'] === 'Saída' ? 'Descrição do que sai do arquivo' : 'Descrição'), 1, 1, 'L', true);
    $pdf->SetTextColor(0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->MultiCell(0, 6, pdf_txt($descricao), 1, 'L');
    $pdf->Ln(6);
}

// Tabela de caixas (se não houver caixas e houver descrição, a descrição basta)
if ($itens || $descricao === ''):
$w = [22, 78, 50, 30];
$head = ['Caixa nº', 'Descrição do conteúdo', 'Competência', 'Localização'];
$drawHead = function () use ($pdf, $w, $head) {
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(20, 33, 61);
    $pdf->SetTextColor(255);
    foreach ($head as $i => $h) {
        $pdf->Cell($w[$i], 8, pdf_txt($h), 1, 0, $i === 1 ? 'L' : 'C', true);
    }
    $pdf->Ln();
    $pdf->SetTextColor(0);
    $pdf->SetFont('Arial', '', 9);
};
$drawHead();
if (!$itens) {
    $pdf->Cell(array_sum($w), 9, pdf_txt('Nenhuma caixa vinculada a este romaneio.'), 1, 1, 'C');
}
foreach ($itens as $i => $it) {
    if ($pdf->GetY() > 255) {
        $pdf->AddPage();
        $drawHead();
    }
    $fill = $i % 2 === 1;
    $pdf->SetFillColor(248, 249, 246);
    $pdf->fitCell($w[0], 8, $it['numero'], 1, 'C', $fill);
    $pdf->fitCell($w[1], 8, mb_strtoupper($it['conteudo'], 'UTF-8'), 1, 'L', $fill);
    $pdf->fitCell($w[2], 8, $it['periodo'], 1, 'C', $fill);
    $pdf->fitCell($w[3], 8, $it['local'], 1, 'C', $fill);
    $pdf->Ln();
}
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell($w[0] + $w[1] + $w[2], 8, pdf_txt('Total de caixas'), 1, 0, 'R');
$pdf->Cell($w[3], 8, (string) count($itens), 1, 1, 'C');
endif;

// Assinaturas
if ($pdf->GetY() > 235) {
    $pdf->AddPage();
}
$pdf->Ln(22);
$y = $pdf->GetY();
$pdf->SetDrawColor(60);
$pdf->Line(20, $y, 95, $y);
$pdf->Line(115, $y, 190, $y);
$pdf->SetFont('Arial', '', 9);
$pdf->SetXY(20, $y + 1);
$pdf->Cell(75, 5, pdf_txt('Entregue por'), 0, 0, 'C');
$pdf->SetXY(115, $y + 1);
$pdf->Cell(75, 5, pdf_txt('Recebido por'), 0, 1, 'C');
$pdf->SetTextColor(110);
$pdf->SetX(20);
$pdf->Cell(75, 5, pdf_txt('Nome, assinatura e data'), 0, 0, 'C');
$pdf->SetX(115);
$pdf->Cell(75, 5, pdf_txt('Nome, assinatura e data'), 0, 1, 'C');

while (ob_get_level() > 0) {
    ob_end_clean();
}
$pdf->Output('I', 'romaneio_' . fmt_id($id) . '.pdf');
