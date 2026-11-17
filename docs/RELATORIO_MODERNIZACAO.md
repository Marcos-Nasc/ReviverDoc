# ReviverDoc 2.0 — Relatório da modernização

Data: 04/11/2026 · Versão: 2.0.0

## Como implantar

1. **Faça um backup** do servidor atual (arquivos + banco).
2. Envie os arquivos desta versão por cima dos atuais, **preservando** em produção:
   `uploads/`, `php/config.json`, `includes/last_romaneio_check.txt` e `vendor/`.
3. (Recomendado) Execute `db/migracao_2026_11_modernizacao.sql` no phpMyAdmin. Ela só **cria**
   tabelas/índices — nada existente é alterado. Sem ela o sistema funciona, mas ficam desativados
   o Histórico de atividades.
4. (Recomendado) Crie `includes/config.local.php` com as credenciais do banco e remova-as de
   `includes/config.php` (modelo no próprio arquivo).
5. Em hospedagem Apache, os novos arquivos `.htaccess` protegem `php/config.json`, `db/`,
   `backup_temp/`, `vendor/` e impedem execução de scripts em `uploads/`.
   Em Nginx, replique essas regras na configuração do servidor.
6. Requisitos: PHP 7.1+ (testado no 8.3), extensões pdo_mysql, iconv, SimpleXML e openssl (openssl para e-mail com TLS/SSL; zip e mbstring são opcionais); ImageMagick
   opcional (miniaturas de PDF).

## 1. Melhorias visuais (implementado)
- Novo design system único (`css/app.css`): paleta "tinta de arquivo" (azul-marinho) + latão
  (dourado da marca), tipografia IBM Plex Sans com números tabulares, componentes padronizados
  (botões, badges, tabelas, formulários, modais, toasts, abas, estados vazios e de carregamento).
- Tema claro/escuro (alternável pelo usuário, lembrado no navegador).
- Layout com menu lateral por módulos (Acervo, Atendimento, Gestão, Cadastros, Administração),
  barra superior com busca global, notificações e menu do usuário.
- Etiqueta de identificação visual para nº de caixa/livro/romaneio/solicitação.
- Nova tela de login. Cada página passou de CSS próprio duplicado para o sistema único.

## 2. Usabilidade (implementado)
- Busca global (Ctrl K ou “/”): caixas, livros, documentos, solicitações e romaneios,
  respeitando nível e setor do usuário.
- Filtros rápidos com contadores (ex.: pendentes, atrasadas, fora do arquivo, sem etiqueta).
- Destaque de atrasos e prazos próximos em solicitações e romaneios.
- Confirmações claras antes de excluir/concluir/aprovar, mensagens de erro específicas
  (antes muitas mensagens se perdiam porque o JS lia `mensagem` e o servidor enviava `message`).
- Links diretos (`?id=`, `?nova=1`, `?novo=1`, `?editar=`) usados por notificações, painel e busca.
- Celular/tablet: tabelas viram cartões, filtros recolhíveis, modais em tela cheia, sem rolagem lateral.
- Exportação CSV (abre no Excel) em caixas, documentos, livros, solicitações, romaneios e relatórios.

## 3. Funcionalidades aprimoradas (implementado)
- **Painel**: pendências do dia (solicitações por prazo, romaneios a devolver, entradas
  aguardando aprovação), tempo médio de resposta, situação do acervo; versão por setor para o nível 1.
- **Relatórios**: filtro por setor passou a funcionar, atalhos de período, indicadores (taxa de
  resposta, % no prazo, % localizados), gráficos novos (romaneios por mês, tempo de
  resposta) e tabela mensal exportável; impressão/PDF pelo navegador.
- **Caixas**: detalhes com histórico de movimentações (romaneios), etiqueta disponível na consulta,
  aviso de número duplicado, sugestão do próximo número.
- **Solicitações**: chat atualiza sozinho enquanto aberto, Enter envia; resposta notifica o solicitante.
- **Romaneios**: seleção de caixas com busca e caixas de seleção (sem Ctrl+clique), PDF do
  romaneio com assinaturas (o antigo estava quebrado), notificações de entrada/aprovação.
- **Usuários**: perfis com descrição, gerador de senha, proteção contra o admin se bloquear.

## 4. Novos recursos (implementado)
- Meu perfil (troca da própria senha) · Central de notificações (marcar todas como lidas)
- Histórico de atividades (auditoria: quem criou/alterou/excluiu, com filtros) — requer a migração
- Teste de envio de e-mail em Sistema · Backup portátil (não depende mais do XAMPP)

## 4.1 Recursos mantidos a pedido (04/11/2026)
- **Letreiro de avisos + chat de avisos**: faixa no topo de todas as páginas rolando os últimos recados
  (tabela `chat_avisos_geral`), com indicador de aviso novo. Clicar abre o **Mural de avisos**, um chat
  onde qualquer usuário escreve (Enter envia); o autor ou um administrador pode remover. Atualiza sozinho.
  A tabela é latin1: emojis viram “?”.
- **Sugestão de livro nas solicitações** (equipe do Arquivo): usa a **planilha de livros do arquivo**
  (`planilha_arquivo.xlsx`, 341 livros, lida diretamente pelo sistema sem bibliotecas extras). Na aba
  “Sugestão de livro” de cada solicitação o sistema reconhece datas (15/03/1953), meses (março de 1953, 03/1953)
  e anos citados e lista os livros cujo período os contém — primeiro os que contêm a data exata — com filtro
  por setor (dono) e botão “Usar na resposta”. Também mostra os livros cadastrados no sistema que cobrem o
  período. Botão “Sugestão de livro” no topo da página para buscas manuais. Em Sistema e e-mail, o administrador
  pode enviar uma nova versão da planilha (a anterior é guardada). Sem a planilha, usa a tabela `livros_sugestoes`.
  Observação: na planilha, o livro 458 (10/11 a 22/01) está com ANO 2 = 1970; o sistema considera o fim em 1971.

## 5. Correções técnicas e de segurança (implementado)
- **Críticas**: vários endpoints não exigiam login — qualquer pessoa na internet podia criar
  usuários administradores, excluir caixas/livros/documentos, alterar SMTP. Agora todos verificam
  login, nível e (no nível 1) o setor; ações de escrita exigem token CSRF.
- `php/config.json` (senha SMTP) era baixável pela URL — bloqueado e a senha não é mais exibida no formulário.
- SQL injection no filtro de datas dos Relatórios — corrigido (consultas parametrizadas).
- Páginas de gestão (*-conf) checavam apenas login: um usuário de setor acessava pela URL — agora exigem nível 2.
- Usuários **inativos conseguiam entrar** — agora bloqueados (inclusive sessões abertas).
- Login: regeneração de sessão, limite de tentativas, cookies HttpOnly/SameSite.
- XSS: escape de saídas (nomes no chat, observações, conteúdos); uploads validados (PDF real,
  nome saneado, lista de extensões), sem execução de scripts em `uploads/`.
- Responder solicitação sem anexo apagava o anexo existente — corrigido.
- Romaneios: setor escolhido pelo admin era ignorado; entrada criada na gestão sempre falhava;
  aprovação ignorava o setor de cada caixa; concluir aceitava romaneio não pendente; saída
  aceitava caixa que já estava fora — todos corrigidos.
- `setor_pesquisa` (coluna numérica) recebia o nome do setor — agora grava o id (registros antigos continuam exibidos).
- Backup tinha erro de sintaxe e caminho fixo do Windows — reescrito.
- Datas vazias apareciam como a data de hoje — corrigido. `utf8_decode` (obsoleto no PHP 8.2) substituído.
- Arquivos mortos/quebrados removidos: `includes/sidebar.php`, `includes/documentos_save.php`,
  `includes/visualizar_pagina.php`, `php/paginas_save.php`, `gerar_hash.php`, `teste_pdf.php`, CSS antigos e `script/script.js`.

- Após revisão independente: compatibilidade real com PHP 7.1/7.2, bloqueio de tentativas de
  login por IP + e-mail, revalidação do usuário também nos PDFs/backup, backup só via botão (POST + token),
  proteção contra fórmulas em CSV, parâmetros de URL malformados não derrubam páginas,
  notificações antigas abrem a tela certa, setor antigo de pesquisa não é apagado ao editar.

- **Registro de erros** (04/11/2026): qualquer erro inesperado do servidor (erro 500) é gravado em
  `backup_temp/erros.log` (protegido) e listado em Sistema e e-mail › Registro de erros, com o arquivo e a linha.
  O administrador vê o detalhe técnico na própria mensagem de erro; os demais usuários veem uma mensagem genérica.
  Também há substitutos para servidores sem a extensão mbstring.
- **Servidor sem a extensão zip do PHP** (caso da hospedagem atual): a planilha de livros e o backup passaram a
  funcionar mesmo assim, com leitor/gravador de .zip próprio em PHP (usa só a zlib, que vem embutida no PHP).

- **Números de caixa com "E+"** (04/11/2026, falha que já existia no sistema antigo): uma caixa com número negativo
  (ex.: "-5") era lida pelo MySQL como 18.446.744.073.709.551.611, e as caixas criadas depois (próximo nº na
  gestão e aprovação de romaneio de entrada) ficavam com números como 9.2233720368548E+18. Agora o próximo número
  considera só números normais, o cadastro recusa números negativos/científicos/gigantes, e Sistema e e-mail ›
  Numeração das caixas lista e corrige as caixas afetadas (renumera em sequência e registra no histórico).

## 5.1 Revisão geral (04/11/2026, à tarde)
Revisão independente de todo o código (três revisores, um por área) + navegação automática por mais de 300 telas
nos três perfis, inclusive num servidor sem as extensões zip, mbstring, ctype e iconv. Corrigido:
- **Servidor com poucas extensões**: substitutos para mbstring (incl. `mb_strimwidth`), ctype e iconv. Sem eles,
  Solicitações, listas paginadas, Relatórios, Histórico, PDF do romaneio, etiqueta e avisos davam erro 500.
- **Contadores que não batiam com a lista**: os filtros rápidos de Caixas, Livros e Romaneios agora respeitam os
  filtros aplicados (setor, tipo, busca, datas); itens sem setor passam a aparecer nas listas; os atalhos do Painel
  abrem a lista com o mesmo número mostrado; "vencem hoje" não conta mais as já atrasadas.
- **Dados antigos/incompletos**: situação vazia de solicitação/romaneio é tratada como pendente em todas as telas
  (antes aparecia como pendente mas ficava fora dos filtros e sem botão de ação); entrada com aprovação vazia pode ser
  aprovada; caixa com situação vazia não aparece mais como "Fora do arquivo" só na gestão; datas 00/00/0000 não
  aparecem mais como "30/11/-1"; usuário com status vazio é tratado como ativo e volta a receber notificações.
- **Notificações**: ao excluir uma solicitação/romaneio as notificações dele são apagadas (e a rotina diária limpa as
  antigas); abrir notificação de item excluído mostra "não encontrado" em vez de janela vazia; ninguém é notificado da
  própria ação; quando outro administrador aprova uma entrada ou outro atendente responde uma solicitação, os avisos
  dos demais saem da lista de não lidas; o e-mail de vencimento usa o endereço real do sistema.
- **Rotina diária**: e-mail que falha não é reenviado a cada página (antes cada acesso podia esperar 15 s por romaneio);
  se a pasta `includes/` não aceitar gravação, usa `backup_temp/`.
- **Uploads acima do limite do servidor** mostram "arquivo maior que o limite (X MB)" em vez de "informe o nome";
  o texto de ajuda mostra o limite real do servidor.
- **Formulários**: salvar depois de abrir por link (?editar=, ?id=) não reabre o formulário; excluir livro com páginas
  leva às páginas em vez de oferecer um botão que sempre falhava; datas impossíveis (31/02) são recusadas;
  período invertido nos Relatórios é corrigido sozinho e falhas aparecem como aviso em vez de gráficos vazios.
- **Login** volta para a página que a pessoa tentou abrir (ex.: link de notificação ou e-mail).
- **Busca global**: "Nota 2023" não abre mais a solicitação #2023; sessão expirada avisa e leva ao login.
- **Setores**: exclusão confere no servidor todos os vínculos (usuários, caixas, livros, solicitações, romaneios) e diz quais são.
- Painel sem rolagem lateral no celular; CSV não mostra mais `'-` para caixa sem número; ordenação estável na
  paginação de caixas; mensagem "Usuário removido" no lugar de "—" para autores de avisos excluídos.

## 5.2 Módulo de feedback removido (04/11/2026, a pedido)
- Saíram do sistema: a página Feedback (avaliação com estrelas), Gerenciar feedbacks, os itens de menu e de
  Configurações, o indicador "Satisfação" e o gráfico "Avaliações dos usuários" dos Relatórios.
- A tabela `feedback` não foi apagada do banco (se existir em produção, os dados antigos continuam lá) e a
  migração deixou de criá-la.
- Arquivos removidos: `php/feedback.php`, `php/feedback-conf.php`, `includes/feedback_save.php`,
  `includes/feedback_get.php`, `includes/feedback_delete.php`. Ao atualizar o servidor, apague também esses
  arquivos de lá (enviar a versão nova por cima não remove arquivos antigos).

## 5.3 Notificações por e-mail (05/11/2026)
- Causa do problema: o pacote trazia o `php/config.json` da versão antiga, que ao ser enviado por cima
  substituiu a configuração do servidor pela conta de e-mail e senha de app antigas. O pacote **não traz mais**
  `php/config.json`; a configuração fica só no servidor e é editada em Configurações › Sistema e e-mail.
- O botão "Enviar e-mail de teste" usa os dados digitados na tela (não precisa salvar antes) e, em caso de
  falha, mostra o motivo, uma dica (senha recusada, conexão bloqueada, TLS) e a conversa com o servidor SMTP
  (com as credenciais ocultas).
- Toda falha de envio (nova solicitação, romaneio vencendo, teste) vai para o Registro de erros com o motivo.
- "E-mail que recebe os avisos" aceita vários endereços separados por vírgula.
- Ajustes automáticos: senha de app do Gmail aceita com ou sem espaços; porta 465 usa SSL e 587 usa TLS mesmo
  que o campo Segurança esteja trocado; e-mails saem também com versão em texto simples.

## 5.4 Ligar/desligar o recebimento de solicitações (09/11/2026)
- Em Solicitações de pesquisa, a equipe do Arquivo (nível 2+) tem o botão "Recebendo solicitações" /
  "Recebimento pausado". Ao pausar, pode deixar um aviso para os setores (ex.: período de inventário).
- Enquanto pausado: os setores veem o aviso no Painel e em Solicitações, o botão "Nova solicitação" fica
  desativado e o servidor recusa novas solicitações deles. Solicitações já abertas, respostas e chat continuam
  normais, e a equipe do Arquivo ainda pode registrar solicitações.
- O estado fica em `php/config.json` (chaves `solicitacoes_abertas` e `solicitacoes_pausa_*`); sem mudança no
  banco. Cada pausa/reabertura fica no Histórico de atividades.

## 5.5 Saída de romaneio por descrição (09/11/2026)
- Saída: a lista de caixas disponíveis saiu do formulário. No lugar, há o campo **Descrição** (obrigatório)
  onde quem pede escreve o que precisa sair do arquivo; a equipe do Arquivo separa o material. Vale para todos
  os perfis. A saída não muda mais o status das caixas (não há caixas vinculadas); "Registrar devolução" só
  conclui o romaneio.
- Entrada: sem mudança (continua exigindo as caixas novas e a aprovação do administrador).
- A descrição da saída aparece na lista, no detalhe, no PDF (no lugar da tabela de caixas), no CSV (coluna
  própria) e entra na busca.
- Banco: sem mudança. A descrição fica na coluna `romaneios.conteudo`, que a saída não usava. Romaneios de saída
  antigos, com caixas vinculadas, continuam exibindo as caixas.

## 5.6 Resultado "Não consta" renomeado (10/11/2026)
- O resultado "Não consta" passou a aparecer como **"Não está no Arquivo"** em todas as telas (lista, detalhe,
  painel, relatórios e CSV). O valor gravado no banco continua `Não Consta`; só o nome exibido mudou, definido
  em um único lugar (`SOLIC_RESULTADOS` em `includes/solicitacoes_lib.php`).
- No formulário de resposta, cada resultado mostra uma explicação curta abaixo do campo (ex.: "Não está no
  Arquivo" = o documento nunca foi enviado para cá; "Não encontrada" = deveria estar, mas não foi achado).

## 5.7 Tempo médio de resposta em horário de atendimento (10/11/2026)
- O tempo médio de resposta (Painel e Relatórios) passou a contar só o horário de atendimento do Arquivo:
  **segunda a sexta, das 8h às 18h**. Noites e fins de semana não contam.
- Os períodos em que o Arquivo pausou o recebimento de solicitações (botão em Solicitações) também não contam.
  Cada pausa fica registrada com início e fim em `php/config.json` (`solicitacoes_pausas`). Pausas feitas
  antes desta atualização não têm fim registrado e não são descontadas.
- Exibição: minutos e horas até 10 h; a partir disso, em dias úteis (1 dia útil = 10 h de atendimento).
- Correção: "Atualizar resposta" não troca mais a data da resposta; a data registrada é a da primeira resposta.
- Feriados não são descontados. O horário fica em `ATEND_INICIO_MIN` / `ATEND_FIM_MIN`
  (`includes/solicitacoes_lib.php`).
- Banco: sem mudança.

## 6. Banco de dados
- Nenhuma tabela/coluna existente foi alterada. Migração opcional cria `logs_atividade` e índices.

## 7. Regras alteradas conscientemente (avaliar)
- Usuário inativo não entra mais (antes entrava). · Nível 1 precisa ter setor.
- Admin não pode excluir/rebaixar/desativar a própria conta.
- Número de caixa duplicado gera aviso (pode salvar mesmo assim).
- Datas finais anteriores às iniciais são recusadas.
- Nível 1 agora tem um painel inicial do próprio setor (antes era redirecionado para o login).
- Entrada de caixas: a equipe do Arquivo pode definir o setor de cada caixa; usuário de setor cadastra só caixas do próprio setor.
- Excluir romaneio de saída devolve as caixas ao arquivo apenas se ele ainda estiver pendente.
- Resposta de solicitação limitada a 100 caracteres (limite que já existia na coluna; antes o texto longo dava erro sem aviso).
- Relatório de digitalização passou a contar páginas pela data de digitalização da página.
- Novas solicitações e mensagens de chat dos setores passam a notificar também a equipe do Arquivo (nível 2), que é quem responde.
- Quem registrou um romaneio de saída também é avisado na véspera do vencimento (antes só os administradores).
- Prazo de nova solicitação não pode ser uma data já passada.
- Usuário de setor sem setor definido é avisado e não consegue abrir solicitação/romaneio (antes abria e não via o que criou).
- Usuário de setor não escolhe mais o setor de cada caixa na entrada (o sistema já gravava sempre o setor dele).
- Filtros "Pendentes" e "Concluídos" dos romaneios referem-se às saídas; entradas ficam em "Aguardando aprovação".
- Setores ficou disponível para a equipe do Arquivo no menu (as ações já eram permitidas a ela).

## 8. Testes executados
- `php -l` em todos os arquivos; servidor MySQL-compatível com o schema real e dados de teste.
- Navegação automatizada de todas as páginas com os 3 perfis, em desktop, tablet, celular e tema
  escuro: sem erros PHP/JS e sem rolagem horizontal; matriz de permissões conferida.
- Testes de fluxo ponta a ponta (CRUDs, uploads, chat, notificações, romaneios, aprovação,
  conclusão, PDFs, backup, CSV, CSRF 419, anônimo 401, nível insuficiente 403).
- Funcionamento sem a migração (o histórico mostra aviso em vez de erro).

## 9. Pendências / oportunidades (não implementado)
- Testar em produção: envio de e-mail SMTP e geração de miniaturas (ImageMagick/Ghostscript não disponíveis no ambiente de teste).
- Mover credenciais do banco para `config.local.php` e trocar a senha do banco e a senha de app do Gmail (estavam no código/arquivo público).
- Recuperação de senha por e-mail; registro de quem respondeu cada solicitação (exige nova coluna).
- Tabela `romaneios` tem colunas não usadas pelo sistema (solicitante_nome, motivo_retirada etc.) — avaliar se há processo que deveria usá-las.
- Unificar as páginas de consulta e gestão (ex.: caixas e caixas-conf) em uma só, com ações por perfil.
- Rotina diária de alertas depende de alguém acessar o sistema; ideal agendar via cron.
- Os arquivos `.htaccess` protegem `php/config.json` e `backup_temp/`; se um dia o servidor deixar de aceitá-los, essas proteções somem — conferir após mudanças de hospedagem.
- Arquivos em `uploads/` são públicos para quem souber o endereço (já era assim); ideal servi-los por um script que confira o login e o setor.
- Numeração automática na aprovação de entradas pode repetir em aprovações simultâneas (raro, já existia).
