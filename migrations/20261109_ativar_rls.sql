-- Liga RLS em todas as tabelas de public. O app conecta como dono (postgres) e
-- ignora RLS; sem politicas, a API publica do Supabase (anon/authenticated) fica sem acesso.
DO $$
DECLARE t record;
BEGIN
  FOR t IN SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND NOT rowsecurity LOOP
    EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY', t.tablename);
  END LOOP;
END $$;
