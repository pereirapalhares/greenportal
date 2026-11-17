# Regras de negócio documentadas (c563, 24/10/2026; atualizado em c564)

Pedido do usuário: "todas as regras de negócio envolvendo os módulos do greenportal têm que estar documentadas em docs".

Ficam em E:\wamp\apps\greenportal\docs:
- regras-negocio-README.md: índice, princípios comuns, principais decisões em aberto, pendências de aplicação, como manter;
- regras-negocio-01-plataforma.md (RN-PLT, 130 regras);
- regras-negocio-02-modulo-legal.md (RN-LEG, 104);
- regras-negocio-03-tarefas-responsabilidades.md (RN-TAR, 111);
- regras-negocio-04-homologacao-fornecedores.md (RN-HOM, 118 — c564 acrescentou RN-HOM-109 a 118, análise de adulteração, migração 349);
- regras-negocio-05-nao-conformidades.md (RN-NC, 103);
- regras-negocio-06-migracao-legado.md (RN-MIG, 98).

Cada regra tem situação ([FEITO], [PENDENTE], [A CONFIRMAR]), onde (tela/procedure/classe/serviço) e origem (migração, documento ou decisão do usuário com data). Fontes: docs do portal, código e migrações, e estes documentos do projeto.

**Manutenção:** a cada mudança de regra (nova migração ou decisão do usuário), atualizar o documento do módulo com o próximo número livre do código, sem renumerar. Nunca registrar senhas, chaves ou valores do .env. Quando a migração for aplicada, trocar [PENDENTE] por [FEITO].
