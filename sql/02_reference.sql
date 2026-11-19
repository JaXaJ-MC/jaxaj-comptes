-- Expéditeurs des e-mails, un par association JaXaJ. Adresses à compléter : chaque
-- association a son compte Gmail. Le transport correspondant se configure par la variable
-- MAILER_DSN_<CLE> (par exemple MAILER_DSN_BEARN).

insert into compte.expediteur (cle, association_id, adresse, nom, par_defaut)
select v.cle, o.id, v.adresse, o.nom, v.cle = 'mere'
from (values
  ('mere',        'JaXaJ MC',          'a-completer-mere@gmail.com'),
  ('bearn',       'JaXaJ MC Béarn',    'a-completer-bearn@gmail.com'),
  ('euskadi',     'JaXaJ MC Euskadi',  'a-completer-euskadi@gmail.com'),
  ('productions', 'JaXaJ Productions', 'a-completer-productions@gmail.com')
) as v (cle, association, adresse)
join annuaire.organisation o on o.nom = v.association and o.genre = 'association_jaxaj';
