<?php
/**
 * Envia um e-mail de teste usando os dados digitados na tela (sem precisar salvar antes).
 * Senha em branco = usa a senha já salva. Em caso de falha, devolve o motivo e a conversa com o servidor.
 */
require_once __DIR__ . '/bootstrap.php';
api_guard(NIVEL_ADMIN, ['POST']);
$cfg = app_config();
foreach (['email_notificacao', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pass'] as $c) {
    if (!isset($_POST[$c])) continue;
    $v = trim((string) $_POST[$c]);
    if ($c === 'smtp_pass' && $v === '') continue;
    $cfg[$c] = $v;
}
$para = emails_lista($cfg['email_notificacao'] ?? '');
if (!$para) {
    json_out('error', 'Informe um e-mail válido em "E-mail que recebe os avisos".', [], 422);
}
if (empty($cfg['smtp_host']) || empty($cfg['smtp_user']) || ($cfg['smtp_pass'] ?? '') === '') {
    json_out('error', 'Preencha servidor, usuário e senha do SMTP.', [], 422);
}
$ok = send_mail($para, 'Teste de envio · ReviverDoc',
    '<p>Este é um e-mail de teste enviado pelo ReviverDoc em ' . date('d/m/Y H:i') . '.</p>'
    . '<p>Conta de envio: ' . e($cfg['smtp_user']) . '</p>'
    . '<p>Se você recebeu esta mensagem, as notificações por e-mail estão funcionando.</p>',
    'ReviverDoc', true, $cfg);
if ($ok) {
    json_out('ok', 'E-mail de teste enviado para ' . implode(', ', $para) . '. Se chegou, clique em "Salvar configurações".');
}
$detalhe = send_mail_ultimo_erro();
$dica = '';
if (stripos($detalhe, 'authenticate') !== false || preg_match('/\b535\b|Username and Password not accepted/i', $detalhe)) {
    $dica = 'O servidor recusou usuário/senha. No Gmail, use uma senha de app (Conta Google › Segurança › Senhas de app) da mesma conta informada em Usuário.';
} elseif (preg_match('/connect|timed out|Connection refused|getaddrinfo|Network is unreachable/i', $detalhe)) {
    $dica = 'Não foi possível conectar ao servidor SMTP. Confira servidor e porta; alguns provedores de hospedagem bloqueiam envio por SMTP externo (consulte a hospedagem).';
} elseif (stripos($detalhe, 'openssl') !== false || stripos($detalhe, 'certificate') !== false) {
    $dica = 'Problema de TLS/SSL no servidor. Confira o campo Segurança ou peça à hospedagem para ativar a extensão openssl.';
}
json_out('error', 'Não foi possível enviar o e-mail de teste. ' . ($dica ?: 'Veja o detalhe abaixo.'), ['detalhe' => mb_substr($detalhe, 0, 4000)], 502);
