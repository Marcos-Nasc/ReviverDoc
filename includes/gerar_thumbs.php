<?php
/**
 * Gera miniaturas ausentes de páginas e documentos (uso exclusivo via linha de comando):
 *   php includes/gerar_thumbs.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Este utilitário só pode ser executado pela linha de comando.');
}
require_once __DIR__ . '/bootstrap.php';

$dirThumbs = __DIR__ . '/../uploads/thumbs';
if (!is_dir($dirThumbs)) {
    @mkdir($dirThumbs, 0775, true);
}
$grupos = [
    ['Páginas de livros', __DIR__ . '/../uploads/paginas', 'pagina'],
    ['Documentos', __DIR__ . '/../uploads/documentos', 'documento'],
];
foreach ($grupos as $g) {
    list($titulo, $dir, $modo) = $g;
    echo "Verificando {$titulo}...\n";
    foreach (glob($dir . '/*.pdf') ?: [] as $pdf) {
        $thumb = $dirThumbs . '/' . basename($pdf, '.pdf') . '.jpg';
        if (is_file($thumb)) {
            continue;
        }
        echo (make_pdf_thumb($pdf, $thumb, $modo) ? '  ok   ' : '  erro ') . basename($pdf) . "\n";
    }
}
echo "\nProcesso de geração de miniaturas concluído.\n";
