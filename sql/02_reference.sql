-- Expéditeurs des e-mails : le compte Gmail de chaque association JaXaJ. Le transport correspondant se configure par la variable
-- MAILER_DSN_<CLE> (par exemple MAILER_DSN_BEARN).

insert into compte.expediteur (cle, association_id, adresse, nom, par_defaut)
select v.cle, o.id, v.adresse, o.nom, v.cle = 'mere'
from (values
  ('mere',        'JaXaJ MC',          'mc.jaxaj@gmail.com'),
  ('bearn',       'JaXaJ MC Béarn',    'bearn.jaxaj@gmail.com'),
  ('euskadi',     'JaXaJ MC Euskadi',  'euskadi.jaxaj@gmail.com'),
  ('productions', 'JaXaJ Productions', 'prod.jaxaj@gmail.com')
) as v (cle, association, adresse)
join annuaire.organisation o on o.nom = v.association and o.genre = 'association_jaxaj';
