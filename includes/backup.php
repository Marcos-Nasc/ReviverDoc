<?php
/**
 * Backup completo: dump SQL do banco (gerado em PHP, sem depender do mysqldump)
 * + pasta uploads/ + configurações, compactados em .zip para download.
 * Acesso: somente administradores (nível 3).
 */
require_once __DIR__ . '/bootstrap.php';
page_guard(NIVEL_ADMIN);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid()) {
    // Só gera backup a partir do botão em Configurações (evita disparo por links externos)
    redirect_to('../php/configuracoes.php');
}

@set_time_limit(0);
@ini_set('memory_limit', '512M');

/**
 * Gravador de .zip em PHP puro, usado quando a extensão zip do PHP não está instalada.
 * Mesmos métodos usados abaixo: open(), addFile(), close().
 */
class RdZipSimples
{
    private $fh;
    private $central = '';
    private $qtd = 0;
    private $pos = 0;

    public function open($arquivo)
    {
        $this->fh = @fopen($arquivo, 'wb');
        return $this->fh ? true : false;
    }

    public function addFile($origem, $nome)
    {
        $dados = @file_get_contents($origem);
        if ($dados === false) return false;
        $crc = crc32($dados);
        $tam = strlen($dados);
        $metodo = 0;
        if (function_exists('gzdeflate') && !preg_match('/\.(pdf|zip|jpe?g|png|gif|gz|xlsx|docx)$/i', $nome)) {
            $comp = gzdeflate($dados, 6);
            if ($comp !== false && strlen($comp) < $tam) { $dados = $comp; $metodo = 8; }
        }
        $t = getdate(filemtime($origem) ?: time());
        $dosHora = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
        $dosData = (max(0, $t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
        $flag = 0x0800; // nomes em UTF-8
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, $flag, $metodo, $dosHora, $dosData, $crc, strlen($dados), $tam, strlen($nome), 0) . $nome;
        fwrite($this->fh, $local);
        fwrite($this->fh, $dados);
        $this->central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, $flag, $metodo, $dosHora, $dosData, $crc, strlen($dados), $tam, strlen($nome), 0, 0, 0, 0, 0, $this->pos) . $nome;
        $this->pos += strlen($local) + strlen($dados);
        $this->qtd++;
        return true;
    }

    public function close()
    {
        fwrite($this->fh, $this->central);
        fwrite($this->fh, pack('VvvvvVVv', 0x06054b50, 0, 0, $this->qtd, $this->qtd, strlen($this->central), $this->pos, 0));
        fclose($this->fh);
        return true;
    }
}

$dir = __DIR__ . '/../backup_temp/';
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
    http_response_code(500);
    exit('Não foi possível criar a pasta temporária de backup (backup_temp). Verifique as permissões.');
}
// Impede acesso web à pasta temporária
if (!is_file($dir . '.htaccess')) {
    @file_put_contents($dir . '.htaccess', "Require all denied\nDeny from all\n");
}

$stamp   = date('Y-m-d_H-i-s');
$sqlFile = $dir . 'db_backup_' . $stamp . '.sql';
$zipFile = $dir . 'backup_reviverdoc_' . $stamp . '.zip';

/* ---------- 1. Dump do banco ---------- */
$fh = fopen($sqlFile, 'w');
if (!$fh) {
    http_response_code(500);
    exit('Não foi possível gravar o arquivo temporário do backup.');
}
fwrite($fh, "-- Backup ReviverDoc\n-- Gerado em " . date('d/m/Y H:i:s') . "\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");
try {
    $tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as $t) {
        $table = $t[0];
        $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_NUM);
        fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n");
        $rows = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`', PDO::FETCH_NUM);
        $batch = [];
        foreach ($rows as $row) {
            $vals = [];
            foreach ($row as $v) {
                $vals[] = $v === null ? 'NULL' : $pdo->quote((string) $v);
            }
            $batch[] = '(' . implode(',', $vals) . ')';
            if (count($batch) >= 200) {
                fwrite($fh, "INSERT INTO `{$table}` VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch) {
            fwrite($fh, "INSERT INTO `{$table}` VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        fwrite($fh, "\n");
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
    fclose($fh);
} catch (Exception $e) {
    fclose($fh);
    @unlink($sqlFile);
    error_log('Backup: ' . $e->getMessage());
    http_response_code(500);
    exit('Não foi possível exportar o banco de dados. Detalhes foram registrados no log do servidor.');
}

/* ---------- 2. Compactação ---------- */
if (class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    $aberto = $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
} else {
    $zip = new RdZipSimples();
    $aberto = $zip->open($zipFile);
}
if ($aberto !== true) {
    @unlink($sqlFile);
    http_response_code(500);
    exit('Não foi possível criar o arquivo .zip do backup.');
}
$zip->addFile($sqlFile, 'database_dump/' . basename($sqlFile));
$uploads = realpath(__DIR__ . '/../uploads');
if ($uploads && is_dir($uploads)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
    foreach ($it as $file) {
        if ($file->isFile()) {
            $zip->addFile($file->getRealPath(), 'uploads/' . str_replace('\\', '/', substr($file->getRealPath(), strlen($uploads) + 1)));
        }
    }
}
$cfg = __DIR__ . '/../php/config.json';
if (is_file($cfg)) {
    $zip->addFile($cfg, 'config/config.json');
}
$zip->close();
@unlink($sqlFile);

log_activity('backup', 'sistema', null, 'Backup completo gerado (' . round(filesize($zipFile) / 1048576, 1) . ' MB)');

// Cópia opcional na pasta configurada
$dest = trim((string) (app_config()['backup_path'] ?? ''));
if ($dest !== '' && is_dir($dest) && is_writable($dest)) {
    @copy($zipFile, rtrim($dest, '/\\') . DIRECTORY_SEPARATOR . basename($zipFile));
}

/* ---------- 3. Download ---------- */
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . basename($zipFile) . '"');
header('Content-Length: ' . filesize($zipFile));
header('Cache-Control: no-store');
readfile($zipFile);
@unlink($zipFile);
exit;
