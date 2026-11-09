# Backup e restauração

## O que existe

- `scripts/backup.php` gera uma cópia completa do banco (`pg_dump` no
  PostgreSQL, `mysqldump` no MySQL/MariaDB), comprimida, em
  `backups/agendei-AAAAMMDD-HHMMSS.sql.gz`, e guarda as 14 mais recentes
  (`--manter=N` muda isso). Usa as mesmas credenciais do sistema
  (variáveis `AGENDEI_DB_*` ou `config/database.local.php`).
- A pasta `backups/` fica fora do Git (`.gitignore`) e fora da imagem Docker
  (`.dockerignore`).

## Rotina recomendada

1. **Diária, fora da hospedagem.** Uma máquina com `pg_dump` (ou um GitHub
   Action agendado) roda `php scripts/backup.php` todo dia e envia o arquivo
   para um armazenamento separado (Google Drive, S3). Backup guardado só no
   mesmo servidor do banco não protege contra a perda desse servidor.
2. **Backup do provedor.** No Supabase, os planos pagos fazem backup diário
   automático e restauração para um ponto no tempo. O plano gratuito **não**
   faz: nele, o passo 1 é a única cópia.
3. **Teste de restauração todo mês.** Restaure o backup mais recente num banco
   descartável e abra o sistema apontando para ele. Backup que nunca foi
   restaurado não é backup confirmado.

## Como restaurar

PostgreSQL (Supabase):

```bash
gunzip -c backups/agendei-20261109-030000.sql.gz | psql "postgresql://USUARIO:SENHA@HOST:5432/postgres"
```

MySQL/MariaDB:

```bash
gunzip -c backups/agendei-20261109-030000.sql.gz | mysql -u root -p agendei
```

Restaure sempre num banco **novo ou vazio** e só depois aponte o sistema
para ele (variáveis `AGENDEI_DB_*`). Nunca restaure por cima do banco em uso
sem ter um backup dele mesmo.

## Retenção dentro do sistema

Separado do backup: a tarefa periódica (`tarefas.php` ou
`scripts/enviar_lembretes.php`) apaga, uma vez por dia, os registros que
passaram do prazo da Política de Privacidade (`models/Legal.php`): registros
de acesso e notificações depois de 12 meses, auditoria master depois de
5 anos. As tentativas de acesso e as sessões lembradas já tinham limpeza
própria.
