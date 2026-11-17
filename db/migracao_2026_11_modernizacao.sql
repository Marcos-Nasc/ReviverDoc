-- =====================================================================
-- ReviverDoc 2.0 — migração opcional (novembro/2026)
-- Compatível com MySQL 5.7+. Pode ser executada pelo phpMyAdmin.
-- Nenhuma tabela ou coluna existente é alterada ou removida.
-- O sistema funciona sem esta migração; ela habilita recursos extras.
-- =====================================================================

-- 1) Histórico de atividades (auditoria: quem fez o quê e quando)
--    Habilita a página "Histórico de atividades" (nível 3).
CREATE TABLE IF NOT EXISTS `logs_atividade` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) DEFAULT NULL,
  `acao` varchar(40) NOT NULL,
  `entidade` varchar(40) NOT NULL,
  `entidade_id` int(11) DEFAULT NULL,
  `descricao` varchar(500) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `data` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_logs_data` (`data`),
  KEY `idx_logs_entidade` (`entidade`, `entidade_id`),
  KEY `idx_logs_usuario` (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Índices de desempenho (opcionais). Execute uma única vez;
--    se algum já existir, o MySQL apenas informará "Duplicate key name" e os demais seguem válidos.
ALTER TABLE `solicitacoes` ADD INDEX `idx_solic_status_prazo` (`status`, `prazo`);
ALTER TABLE `solicitacoes` ADD INDEX `idx_solic_data` (`data_solicitacao`);
ALTER TABLE `romaneios` ADD INDEX `idx_rom_status_retorno` (`status`, `data_retorno`);
ALTER TABLE `caixas` ADD INDEX `idx_caixas_status` (`status`);
ALTER TABLE `notificacoes` ADD INDEX `idx_notif_usuario_lida` (`usuario_id`, `lida`);
ALTER TABLE `chat_mensagens` ADD INDEX `idx_chat_solic_id` (`solicitacao_id`, `id`);
