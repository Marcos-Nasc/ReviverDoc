# ReviverDoc

Sistema de gestão do Arquivo Geral da Concessionária Reviver (marca ATHENA): caixas, livros e páginas digitalizadas, documentos, solicitações de pesquisa com chat, romaneios de entrada e saída, relatórios e mural de avisos.

**Stack:** PHP 7.1+ (testado no 8.3) · MySQL 5.7 · PDO · FPDF · dompdf · PHPMailer

## Instalação

1. Copie os arquivos para o servidor.
2. Instale as dependências: `composer install` (ou envie a pasta `vendor/` já pronta).
3. Crie `includes/config.local.php` a partir de `includes/config.local.example.php` com os dados do banco.
4. Dê permissão de escrita em `php/`, `uploads/`, `backup_temp/`, `paginas/`, `documentos/` e `thumbs/`.
5. (Opcional) Rode `db/migracao_2026_11_modernizacao.sql` no phpMyAdmin.
6. Entre como administrador e configure o e-mail em **Configurações › Sistema e e-mail** (grava `php/config.json`; modelo em `php/config.example.json`).
7. Para a sugestão de livros, coloque a `planilha_arquivo.xlsx` na raiz do sistema no servidor.

## O que não vai para o repositório

Senhas (`includes/config.local.php`, `php/config.json`), documentos digitalizados e anexos (`paginas/`, `documentos/`, `thumbs/`, `uploads/`), backups e a planilha interna de livros. Veja o `.gitignore`.

## Documentação

Mudanças da versão 2.x, regras de negócio e notas de implantação: [`docs/RELATORIO_MODERNIZACAO.md`](docs/RELATORIO_MODERNIZACAO.md).
