# Hostinger - Dashboard remoto do Cultivo

Esta pasta contém a parte que deve ser enviada para a Hostinger.

## Estrutura

- `sql/schema.sql`: cria as duas tabelas do histórico remoto.
- `api/registrar_leitura.php`: recebe temperatura, umidade e solo do ESP32.
- `api/registrar_comando.php`: recebe comandos/mudanças de estado do sistema.
- `api/dados.php`: consulta leituras para o dashboard.
- `api/comandos.php`: consulta comandos para o dashboard.
- `index.php`: dashboard remoto detalhado.

## Como instalar

1. No hPanel da Hostinger, crie um banco MySQL.
2. Importe `sql/schema.sql` no phpMyAdmin.
3. Edite `api/config.php` e preencha `DB_NAME`, `DB_USER` e `DB_PASS`.
4. Envie o conteúdo desta pasta para o `public_html` do domínio:
   `https://powderblue-rhinoceros-609254.hostingersite.com`.
5. Grave o firmware atualizado no ESP32.

O histórico local do ESP32 continua limitado a 14 dias. O histórico completo
fica salvo nas tabelas MySQL da Hostinger.
