# ReviverDoc

Sistema de gestão do **Arquivo Geral** da Concessionária Reviver, da marca ATHENA. Ele centraliza:

- o acervo físico: caixas, livros e documentos;
- as páginas digitalizadas;
- as solicitações de pesquisa feitas pelos setores;
- a movimentação de caixas por romaneio;
- os indicadores de atendimento.

![PHP](https://img.shields.io/badge/PHP-7.1%2B-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-5.7-4479A1?logo=mysql&logoColor=white)
![Versão](https://img.shields.io/badge/vers%C3%A3o-2.1.0-14213D)

---

## Sumário

- [Funcionalidades](#funcionalidades)
- [Perfis de acesso](#perfis-de-acesso)
- [Tecnologias](#tecnologias)
- [Requisitos](#requisitos)
- [Instalação](#instalação)
- [Configuração](#configuração)
- [Estrutura de pastas](#estrutura-de-pastas)
- [Atualizando o servidor](#atualizando-o-servidor)
- [Segurança](#segurança)
- [Documentação](#documentação)

---

## Funcionalidades

### Acervo
- **Caixas**: cadastro com numeração automática, setor, período, situação no arquivo ("No Arquivo" ou "Fora do Arquivo"), higienização e etiqueta em PDF.
- **Livros e páginas**: livros por setor e período, com o envio das páginas digitalizadas e miniaturas.
- **Documentos**: documentos vinculados às caixas, com busca por conteúdo.

### Atendimento
- **Solicitações de pesquisa**:
  - os setores pedem documentos ao Arquivo, com prazo, setor da pesquisa e anexos;
  - o Arquivo responde com um resultado: *Realizada*, *Não está no Arquivo*, *Não encontrada* ou *Dados insuficientes*;
  - cada solicitação tem um **chat** entre o setor e o Arquivo.
- **Sugestão de livro**: a partir das datas citadas na solicitação, o sistema indica em quais livros procurar. Ele usa a planilha do arquivo (`planilha_arquivo.xlsx`) e os livros cadastrados.
- **Pausa no recebimento**: a equipe do Arquivo pode pausar e reabrir o recebimento de novas solicitações, com um aviso aos setores. Por exemplo, durante um inventário.
- **Romaneios**:
  - *Saída*: o solicitante descreve o que precisa sair do arquivo, e o Arquivo registra a devolução;
  - *Entrada*: o setor envia caixas novas, que o administrador aprova e o sistema cadastra com numeração automática;
  - os romaneios são impressos em PDF.
- **Mural de avisos**: um letreiro no topo das páginas, com mensagens da equipe.

### Gestão
- **Painel**: indicadores do dia, com solicitações pendentes, atrasadas, romaneios a devolver e tempo médio de resposta.
- **Relatórios**:
  - solicitações por mês, por resultado e por setor;
  - romaneios;
  - caixas por setor;
  - páginas digitalizadas;
  - exportação em CSV.
- **Tempo médio de resposta**: conta só o horário de atendimento, de segunda a sexta, das 8h às 18h. Também desconta os períodos em que o recebimento estava pausado.
- **Notificações**:
  - no sistema e por e-mail;
  - um e-mail é enviado a cada nova solicitação;
  - outro é enviado quando um romaneio vence no dia seguinte.
- **Histórico de atividades**: quem fez o quê e quando.
- **Busca global**: atalho `Ctrl + K` para encontrar caixas, livros, documentos e solicitações.

### Administração
- Usuários, setores e níveis de acesso.
- Configuração de e-mail (SMTP), com teste de envio e a resposta do servidor de e-mail em caso de erro.
- Registro de erros, backup e manutenção da numeração das caixas.

---

## Perfis de acesso

| Nível | Perfil | O que pode fazer |
|:---:|---|---|
| 1 | **Setor** | Abre e acompanha as solicitações do próprio setor, conversa no chat e registra romaneios do setor. |
| 2 | **Arquivo** | Tudo do nível 1 para todos os setores. Gerencia o acervo, responde solicitações, pausa o recebimento, vê relatórios e cadastra setores. |
| 3 | **Administrador** | Tudo do nível 2. Aprova romaneios de entrada e gerencia usuários, sistema, e-mail, histórico e backup. |

---

## Tecnologias

| Camada | Tecnologia |
|---|---|
| Backend | PHP 7.1+ (testado até o 8.3), PDO |
| Banco de dados | MySQL 5.7 (compatível com `STRICT_TRANS_TABLES` e `ONLY_FULL_GROUP_BY`) |
| PDF | FPDF (etiquetas e romaneios), dompdf |
| E-mail | PHPMailer (SMTP) |
| Frontend | HTML, CSS e JavaScript puros, Chart.js (gráficos) e Font Awesome (ícones) |
| Servidor | Apache com `.htaccess` (testado em hospedagem compartilhada) |

O sistema funciona mesmo sem as extensões `zip`, `mbstring`, `ctype` e `iconv`, porque há alternativas em PHP puro para elas.

---

## Requisitos

- PHP 7.1 ou superior, com as extensões `pdo_mysql`, `SimpleXML` e `openssl`. A `openssl` é necessária para enviar e-mail com TLS ou SSL.
- MySQL 5.7 ou compatível, como o MariaDB.
- Apache com permissão para usar `.htaccess`.
- [Composer](https://getcomposer.org/), para instalar as dependências. Também é possível enviar a pasta `vendor/` já pronta.
- ImageMagick (opcional), para gerar as miniaturas das páginas.

---

## Instalação

**1. Clone o repositório:**
```bash
git clone https://github.com/Marcos-Nasc/ReviverDoc.git
cd ReviverDoc
```

**2. Instale as dependências:**
```bash
composer install --no-dev
```

**3. Configure o banco de dados.** Copie o modelo e preencha com os seus dados:
```bash
cp includes/config.local.example.php includes/config.local.php
```
```php
<?php
return [
    'host'   => 'SEU_HOST',
    'dbname' => 'reviverdoc',
    'user'   => 'USUARIO',
    'pass'   => 'SENHA',
    'port'   => 3306,
];
```
Se preferir, use as variáveis de ambiente `REVIVERDOC_DB_HOST`, `_NAME`, `_USER`, `_PASS` e `_PORT`.

**4. (Opcional) Rode a migração** `db/migracao_2026_11_modernizacao.sql` no phpMyAdmin. Ela só cria o que falta, como o histórico e os índices, e não altera nem apaga nada que já existe.

**5. Dê permissão de escrita** às pastas:
```
php/  uploads/  backup_temp/  paginas/  documentos/  thumbs/
```

**6. Faça a primeira configuração.** Entre como administrador e abra **Configurações › Sistema e e-mail** para configurar o envio de e-mail.

**7. (Opcional) Ative a sugestão de livros.** Coloque a `planilha_arquivo.xlsx` na raiz do sistema.

---

## Configuração

### Banco de dados
Fica em `includes/config.local.php`, que não vai para o Git. O `includes/config.php` lê esse arquivo automaticamente.

### E-mail
Configure em **Configurações › Sistema e e-mail**. A tela grava os dados em `php/config.json`, que também não vai para o Git. Há um modelo em `php/config.example.json`.

| Campo | Exemplo (Gmail) |
|---|---|
| Servidor SMTP | `smtp.gmail.com` |
| Porta | `587` |
| Segurança | `TLS` |
| Usuário | conta que envia os e-mails |
| Senha | **senha de app** do Google (Conta Google › Segurança › Senhas de app) |
| E-mail que recebe os avisos | um ou mais endereços, separados por vírgula |

Use o botão **Enviar e-mail de teste** antes de salvar. Se der erro, a tela mostra o motivo.

### Horário de atendimento
O tempo médio de resposta considera de segunda a sexta, das 8h às 18h. Para mudar o horário, altere `ATEND_INICIO_MIN` e `ATEND_FIM_MIN` em `includes/solicitacoes_lib.php`.

---

## Estrutura de pastas

```
ReviverDoc/
├── index.php                 # Painel
├── php/                      # Páginas (caixas, livros, solicitações, romaneios, relatórios…)
├── includes/                 # Endpoints (AJAX), funções e configuração
│   ├── bootstrap.php         # Sessão, conexão, segurança, CSRF, e-mail e funções gerais
│   ├── layout.php            # Menu lateral, cabeçalho e rodapé
│   ├── config.php            # Conexão com o banco (lê config.local.php)
│   ├── solicitacoes_lib.php  # Regras de solicitações, resultados e tempo de atendimento
│   ├── romaneios_lista.php   # Listagem, filtros e formulário de romaneios
│   └── fpdf/                 # Biblioteca de PDF
├── script/                   # JavaScript (app.js, romaneios.js)
├── css/                      # Estilos (app.css, login.css)
├── images/                   # Logos
├── db/                       # Migração SQL opcional
├── docs/                     # Relatório da modernização e regras de negócio
├── paginas/ documentos/ thumbs/   # Arquivos digitalizados (não versionados)
├── uploads/ backup_temp/          # Anexos e backups (não versionados)
└── vendor/                   # Dependências do Composer (não versionado)
```

O menu lateral fica em `includes/layout.php`, na função `nav_items()`.

---

## Atualizando o servidor

1. Faça **backup** dos arquivos e do banco.
2. Envie os arquivos novos **por cima** dos atuais. **Preserve** no servidor:
   - `includes/config.local.php`;
   - `php/config.json`;
   - `uploads/`, `paginas/`, `documentos/` e `thumbs/`;
   - `vendor/`;
   - `planilha_arquivo.xlsx`.
3. Arquivos removidos de uma versão para outra precisam ser **apagados à mão** no servidor, porque enviar os novos por cima não apaga os antigos.
4. Depois de atualizar, aperte `Ctrl + F5` no navegador para carregar os scripts novos.

> **Atenção:** se o servidor ainda tiver as credenciais do banco dentro do `includes/config.php`, crie o `includes/config.local.php` **antes** de substituir esse arquivo. Sem isso, o sistema perde a conexão com o banco.

---

## Segurança

- Proteção contra CSRF em todos os formulários e chamadas AJAX.
- Senhas guardadas com `password_hash`, além de um limite de tentativas de login.
- Consultas ao banco parametrizadas, com PDO e *prepared statements*.
- As permissões são conferidas no servidor em cada endpoint, por nível e por setor.
- Os `.htaccess` bloqueiam o acesso a `db/`, `docs/` e `backup_temp/`, aos arquivos de configuração e à planilha. Em `uploads/`, impedem que arquivos enviados sejam executados.
- Os arquivos internos de `includes/` recusam o acesso direto pelo navegador.
- As exportações em CSV são protegidas contra injeção de fórmulas.
- Os erros são gravados em um registro, visível só para administradores, sem expor detalhes aos usuários.
- As credenciais **nunca** vão para o repositório. Veja o `.gitignore`.

---

## Documentação

O documento [`docs/RELATORIO_MODERNIZACAO.md`](docs/RELATORIO_MODERNIZACAO.md) traz em detalhe:

- tudo o que mudou na versão 2.x;
- as regras de negócio;
- as decisões técnicas;
- as notas de implantação.

---

Desenvolvido por **Marcos Nascimento** · Inovação · Concessionária Reviver
