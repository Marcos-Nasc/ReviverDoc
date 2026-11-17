<?php
require_once __DIR__ . '/bootstrap.php';
page_guard(NIVEL_ARQUIVO);
require_once __DIR__ . '/fpdf/fpdf.php';

function cm($centimetros)
{
    return $centimetros * 28.3465;
}

class PDF_Etiqueta extends FPDF
{
    function etiqueta($caixa)
    {
        $this->AddPage('P', [cm(10.5), cm(23)]);
        if ($caixa['higienizada'] === 'Não') {
            $this->SetFillColor(255, 173, 22);
        } else {
            $this->SetFillColor(128, 128, 128);
        }
        $this->Rect(0, 0, cm(10.5), cm(23), 'F');
        $x = cm(0.5);
        $larguraCaixa = cm(9.5);
        $paddingTexto = cm(0.3);
        $y = cm(0.5);
        $espaco = cm(0.2);
        $this->SetFillColor(255);
        $this->Rect($x, $y, $larguraCaixa, cm(3.5), 'F');
        $logoPath = __DIR__ . '/fpdf/LOGO-REVIVER-PNG.png';
        if (file_exists($logoPath)) {
            $this->Image($logoPath, $x + cm(0.5), $y + cm(0.35), cm(8.5), cm(2.8));
        }
        $y += cm(3.5) + $espaco;
        $this->Rect($x, $y, $larguraCaixa, cm(2.5), 'F');
        $this->SetY($y + cm(0.2));
        $this->SetX($x + $paddingTexto);
        $this->SetFont('Arial', '', 40);
        $this->Cell(0, cm(0.6), pdf_txt('CAIXA Nº'), 0, 1, 'L');
        $this->SetX($x + $paddingTexto);
        $this->SetFont('Arial', 'B', 70);
        $this->Cell(0, cm(2.3), pdf_txt((string) $caixa['numero']), 0, 1, 'L');
        $y += cm(2.5) + $espaco;
        $periodo = '';
        if (!empty($caixa['data_inicio']) && !empty($caixa['data_fim'])) {
            $ini = new DateTime($caixa['data_inicio']);
            $fim = new DateTime($caixa['data_fim']);
            if (
                $ini->format('m') === $fim->format('m') &&
                $ini->format('Y') === $fim->format('Y')
            ) {
                $periodo = $ini->format('d') . ' a ' . $fim->format('d/m/Y');
            } elseif ($ini->format('Y') === $fim->format('Y')) {
                $periodo = $ini->format('d/m') . ' a ' . $fim->format('d/m/Y');
            } else {
                $periodo = $ini->format('d/m/Y') . ' a ' . $fim->format('d/m/Y');
            }
        }
        $this->Rect($x, $y, $larguraCaixa, cm(2.5), 'F');
        $this->SetY($y + cm(0.2));
        $this->SetX($x + $paddingTexto);
        $this->SetFont('Arial', '', 40);
        $this->Cell(0, cm(0.6), pdf_txt('PERÍODO / ANO'), 0, 1, 'L');
        $this->SetX($x + $paddingTexto);
        $this->SetFont('Arial', 'B', 60);
        $this->Cell(0, cm(2.3), strtoupper($periodo), 0, 1, 'L');
        $y += cm(2.5) + $espaco;
        $this->Rect($x, $y, $larguraCaixa, cm(2.5), 'F');
        $this->SetY($y + cm(0.2));
        $this->SetX($x + $paddingTexto);
        $this->SetFont('Arial', '', 40);
        $this->Cell(0, cm(0.6), pdf_txt('UNIDADE / DEPARTAMENTO'), 0, 1, 'L');
        $this->SetX($x + $paddingTexto);
        $this->SetFont('Arial', 'B', 50);
        $this->Cell(0, cm(2.3), pdf_txt(mb_strtoupper((string) $caixa['setor'], 'UTF-8')), 0, 1, 'L');
        $y += cm(2.5) + $espaco;
        $this->Rect($x, $y, $larguraCaixa, cm(7.5), 'F');
        $this->SetY($y + cm(0.2));
        $this->SetX($x + $paddingTexto);
        $this->SetFont('Arial', '', 40);
        $this->Cell(0, cm(0.6), pdf_txt('CONTEÚDO'), 0, 1, 'L');
        $this->SetX($x + $paddingTexto);
        $this->SetFont('Arial', 'B', 70);
        $conteudoCompleto = pdf_txt(mb_strtoupper(trim($caixa['conteudo'] . "\n" . $caixa['observacao']), 'UTF-8'));
        $this->MultiCell($larguraCaixa - $paddingTexto, cm(2.9), $conteudoCompleto, 0, 'L');
        $y += cm(7.5) + $espaco;
        $this->Rect($x, $y, $larguraCaixa, cm(2.5), 'F');
        $this->SetY($y + cm(0.2));
        $this->SetX($x + $paddingTexto);
        $this->SetFont('Arial', '', 40);
        $this->Cell(0, cm(0.6), pdf_txt('PERMANÊNCIA ATÉ'), 0, 1, 'L');
        $this->SetX($x + $paddingTexto);
        $this->SetFont('Arial', 'B', 70);
        $this->Cell($larguraCaixa - $paddingTexto * 2, cm(0.9), '', '0');
    }
}

$id = (int) ($_GET['id'] ?? 0);
$sql = "
SELECT 
  caixas.numero,
  caixas.data_inicio,
  caixas.data_fim,
  caixas.conteudo,
  caixas.observacao,
  caixas.higienizada,
  s.nome AS setor
FROM caixas
LEFT JOIN setores s ON caixas.setor_id = s.id
WHERE caixas.id = ?
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$caixa = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$caixa) {
    http_response_code(404);
    exit("Caixa não encontrada.");
}

$pdf = new PDF_Etiqueta();
$pdf->etiqueta($caixa);
$pdf->Output('I', 'etiqueta_caixa_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $caixa['numero']) . '.pdf');
?>