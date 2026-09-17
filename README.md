# AD Manager Web

Ferramenta web para gerenciar **usuários e grupos** de um domínio **Active
Directory / Samba4 AD DC**, com dashboard de métricas e log de auditoria.
Feita para times de TI pequenos (escolas, institutos, empresas) que precisam
de algo mais simples e seguro que "mexer direto no LDAP na mão", sem depender
de ferramentas Windows para o dia a dia (delegar a operadores/estagiários,
por exemplo).

> Este projeto é genérico: nada de nomes de domínio, IPs ou credenciais reais
> ficam no código — tudo fica em `config/config.php`, que **não é versionado**.

## Funcionalidades

- **Dashboard**: total de usuários, ativos vs. desativados, usuários por
  grupo, contas aguardando troca de senha, atividade recente.
- **Usuários**: listar, criar (com senha inicial + força de troca no
  primeiro logon), resetar senha, ativar/desativar conta.
- **Grupos**: listar membros, adicionar/remover usuários de grupos.
- **Multiusuário com papéis**: `admin` (gerencia quem acessa a ferramenta)
  e `operator` (só mexe no LDAP) — pensado para permitir estagiários
  operarem sem dar acesso total.
- **Auditoria**: toda ação de escrita fica registrada (quem, o quê, quando).

## Como funciona por baixo dos panos

- Conecta ao AD via `ext-ldap` do PHP.
- Leitura pode ser feita em LDAP simples (porta 389), mas **alteração de
  senha exige LDAPS (porta 636)** — é uma exigência do próprio protocolo do
  Active Directory (`unicodePwd` só é gravável sobre conexão criptografada).
- Senha inicial + `pwdLastSet = 0` reproduz o mesmo comportamento de "o
  usuário deve trocar a senha no próximo login" que o AD já tem nativamente.

## Requisitos

- PHP 8.1+ com as extensões `ldap`, `pdo_mysql`, `mbstring`
- MariaDB/MySQL (recomenda-se um banco dedicado, separado de outras
  aplicações do mesmo servidor)
- Um Active Directory / Samba4 AD DC acessível pela rede, com LDAPS
  habilitado
- Apache (ou outro servidor web) com suporte a PHP

## Instalação

1. Clone o repositório no servidor:
   ```bash
   git clone <url-do-repo> ldap-manager
   cd ldap-manager
   ```

2. Copie e preencha a configuração:
   ```bash
   cp config/config.example.php config/config.php
   # edite config/config.php com host do AD, bind DN/senha, dados do banco, etc.
   ```

3. Crie o banco e as tabelas:
   ```bash
   mysql -u root -p < sql/schema.sql
   ```

4. Crie o primeiro usuário administrador da ferramenta:
   ```bash
   php bin/create-admin.php admin "senha-forte-aqui" "Seu Nome"
   ```

5. Configure o VirtualHost do Apache apontando para `public/` como
   document root (veja `deploy/apache-vhost.conf.example`) — **restrinja o
   acesso à rede interna**, já que esta ferramenta administra contas do
   domínio.

6. Acesse pelo navegador e faça login com o usuário criado no passo 4.

## Segurança

- A ferramenta não usa nenhum framework/pacote externo — só `ext-ldap` e
  `PDO`, reduzindo superfície de dependências.
- Credenciais do bind LDAP e do banco ficam em `config/config.php`, fora do
  controle de versão e fora de qualquer diretório servido publicamente
  (apenas `public/` é exposto pelo Apache).
- Sessões usam cookies `HttpOnly` + `SameSite=Strict`.
- Recomenda-se fortemente restringir o acesso por IP/rede no próprio
  servidor web, além de qualquer firewall existente.
- O certificado usado pelo LDAPS do seu AD pode estar autoassinado ou
  expirado (comum em ambientes internos) — veja o comentário sobre
  `tls_verify` em `config/config.example.php`. Renovar esse certificado é
  recomendado, mas não bloqueia o funcionamento da ferramenta.

## Estrutura do projeto

```
public/         document root do Apache (único diretório exposto)
src/            classes PHP (Config, Database, Auth, camada LDAP, auditoria)
config/         config.example.php (versionado) e config.php (real, ignorado)
sql/            schema do banco local
bin/            scripts de linha de comando (criação do admin inicial)
deploy/         exemplos de configuração de infraestrutura (vhost Apache)
```

## Roadmap / ideias futuras

- Página de detalhes do usuário com histórico de auditoria filtrado
- Exportação de relatórios (CSV/Excel)
- Suporte a 2FA para login na própria ferramenta
- Internacionalização (hoje a interface é só em português)

## Licença

MIT — veja [LICENSE](LICENSE).
