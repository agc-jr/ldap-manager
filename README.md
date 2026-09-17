# AD Manager Web

Ferramenta web para gerenciar **usuários e grupos** de domínios **Active
Directory / Samba 4 AD DC**, com dashboard de métricas, instalação pelo
navegador e log de auditoria.

Feita para times de TI pequenos (escolas, institutos, empresas) que precisam
de algo mais simples que mexer no LDAP na mão, sem depender de ferramentas
Windows para o dia a dia — e que querem delegar tarefas a operadores ou
estagiários sem entregar acesso administrativo ao domínio.

> Nada de domínios, IPs ou credenciais reais fica no código. A conexão com o
> banco vive em `config/config.php`, gerado pelo instalador e **não
> versionado**; os domínios LDAP ficam cadastrados no próprio banco, com a
> senha da conta de serviço guardada cifrada.

## Funcionalidades

- **Instalação pelo navegador**: `git clone` e abrir a URL. O instalador
  coleta os dados do banco, gera a configuração, cria as tabelas, o
  administrador e o primeiro domínio, testando cada conexão antes de gravar.
- **Vários domínios** na mesma instalação, com seletor no cabeçalho.
- **Dashboard**: total de usuários, ativos vs. desativados, usuários por
  grupo, contas aguardando troca de senha, atividade recente.
- **Usuários**: listar com busca e paginação, criar (com senha inicial e
  troca obrigatória no primeiro logon), resetar senha, ativar/desativar e
  excluir.
- **Grupos**: listar membros, adicionar e remover usuários.
- **Papéis**: `admin` (gerencia operadores, domínios e exclusões) e
  `operator` (opera apenas os domínios que lhe forem liberados).
- **Auditoria**: toda escrita fica registrada — quem, o quê, em qual domínio
  e quando —, além de login, logout e tentativas de acesso frustradas.

## Como funciona por baixo dos panos

- Conecta ao diretório via `ext-ldap`, sem framework nem dependências
  externas.
- Buscas são **paginadas**: o AD corta qualquer consulta em `MaxPageSize`
  (1000 por padrão) sem avisar, e uma listagem truncada passaria despercebida.
- Alterar senha **exige LDAPS** (porta 636). Não é escolha do projeto: o AD
  só aceita gravar `unicodePwd` sobre conexão cifrada.
- Senha inicial com `pwdLastSet = 0` reproduz o "precisa trocar a senha no
  próximo login" nativo do AD.
- A senha do bind é cifrada com AES-256-GCM antes de ir para o banco. A chave
  fica no `config.php`, de modo que obter só o banco — por backup, phpMyAdmin
  ou injeção de SQL — não entrega a senha da conta de serviço.

## Requisitos

- PHP 8.0+ com as extensões `ldap`, `pdo_mysql`, `mbstring` e `openssl`
- MariaDB/MySQL, preferencialmente um banco dedicado
- Um Active Directory / Samba 4 AD DC acessível pela rede, com LDAPS
- Apache (ou outro servidor web) com PHP

## Instalação

### 1. Prepare o banco

O instalador **não cria o usuário do MySQL** — ele precisa de credenciais que
já existam. Crie o banco e o usuário antes, com acesso administrativo ao
servidor de banco:

```sql
CREATE DATABASE ldap_manager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'ldap_manager'@'localhost' IDENTIFIED BY 'uma-senha-forte-aqui';

-- CREATE é necessário porque o instalador cria as tabelas na primeira etapa
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, INDEX, ALTER
  ON ldap_manager.* TO 'ldap_manager'@'localhost';

FLUSH PRIVILEGES;
```

Se preferir, dê ao usuário permissão de `CREATE` no servidor e informe um
banco que ainda não existe: o instalador tenta criá-lo. Depois de instalado,
`CREATE`, `INDEX` e `ALTER` podem ser revogados — a aplicação em uso só lê e
escreve linhas.

### 2. Clone e dê permissão de escrita ao diretório de configuração

```bash
git clone <url-do-repo> /var/www/ldap-manager
cd /var/www/ldap-manager

# o instalador precisa gravar config/config.php uma vez
chown www-data:www-data config/
```

Sem essa permissão a instalação não trava: o instalador mostra o conteúdo do
arquivo na tela para você criar à mão.

### 3. Aponte o servidor web para `public/`

Use `deploy/apache-vhost.conf.example` como base. O `DocumentRoot` deve ser a
pasta `public/`, e não a raiz do projeto — só ela deve ficar exposta.
**Restrinja o acesso à rede interna**, já que a ferramenta administra contas
do domínio.

Se não for possível usar um VirtualHost próprio e o projeto ficar numa
subpasta do site, os arquivos `.htaccess` incluídos bloqueiam tudo que não
seja `public/`. Sem eles, `/.git/config` ficaria acessível e o repositório
inteiro poderia ser baixado.

### 4. Abra o navegador

Acesse a URL da ferramenta. Qualquer página leva ao instalador enquanto não
houver configuração. Serão quatro etapas:

1. **Banco de dados** — os dados do passo 1. A conexão é testada e as tabelas
   criadas antes de seguir.
2. **Administrador** — a conta de acesso à ferramenta, que não tem relação com
   contas do domínio.
3. **Domínio LDAP** — servidor, base DN e conta de serviço. O bind é testado
   de verdade; se falhar, nada é gravado.
4. **Pronto.**

### 5. Depois de instalar

```bash
rm public/install.php
```

Ele já se recusa a rodar com a instalação concluída, mas remover é mais
seguro. E **guarde o `config/config.php` junto com os backups do banco**: sem
a `app_key` que está nele, as senhas dos domínios não podem ser lidas de volta
e os domínios precisam ser recadastrados.

## A conta de serviço no domínio

A ferramenta se conecta ao AD com uma conta de serviço. Use uma conta
dedicada, nunca uma conta administrativa pessoal, e **delegue a ela apenas o
necessário, apenas onde for necessário**.

O padrão recomendado é uma unidade organizacional dedicada, com os usuários
gerenciáveis dentro dela:

```bash
# no controlador de domínio (exemplo com Samba)
samba-tool ou create "OU=usuarios-gerenciados,DC=exemplo,DC=local"
samba-tool user create svc-ldapmanager
samba-tool user setexpiry svc-ldapmanager --noexpiry
```

Em seguida delegue, **somente nessa OU**, o direito de criar e apagar objetos
`user`, escrever seus atributos e redefinir senha (com `samba-tool dsacl set`
ou pelo console do Windows).

Por que isso importa: contas administrativas costumam ficar no mesmo container
que os demais usuários. Se a delegação abranger esse container, um operador
consegue redefinir a senha de um administrador do domínio pela interface e
escalar privilégios — o que anula todo o propósito do papel `operator`. Com a
delegação restrita a uma OU, as contas privilegiadas ficam fora de alcance.

Configure essa OU no campo **OU padrão de usuários** do domínio. Ela também
delimita o que a ferramenta pode excluir.

## Validando a conexão com o AD

O diagnóstico é **somente leitura** e pode ser executado em produção:

```bash
php bin/ldap-check.php                    # primeiro domínio cadastrado
php bin/ldap-check.php "Administrativo"   # um domínio específico
```

Ele verifica as extensões do PHP, o bind da conta de serviço via LDAPS, se o
`base_dn` bate com o domínio real (lido do RootDSE), se os filtros encontram
os usuários e grupos existentes, e se os atributos que a interface consome
chegam preenchidos. Sai com código 0 quando não há problema bloqueante.

| Sintoma | Causa provável |
| --- | --- |
| `Can't contact LDAP server` | sem rota/firewall até o DC, ou certificado recusado — desligue a validação TLS no cadastro do domínio |
| `Invalid credentials` | bind DN ou senha errados; com Samba AD, prefira o formato UPN (`usuario@dominio`) |
| `base_dn é DIFERENTE do domínio real` | o base DN cadastrado não corresponde ao `defaultNamingContext` do DC |
| nenhum usuário encontrado | filtro LDAP ou base DN não batem com a estrutura real |
| atributo `VAZIO EM TODOS` | a interface consome esse atributo e a tela ficará em branco |

As operações de **escrita** não são exercitadas pelo diagnóstico, justamente
para que ele seja seguro. Teste-as pela interface com um usuário descartável
antes de liberar para os operadores.

## Senhas recusadas pelo domínio

Ao criar usuário ou redefinir senha, o AD responde apenas `Constraint
violation` quando recusa — sem dizer qual regra falhou. A ferramenta valida a
senha antes de enviá-la e explica o que falta: comprimento mínimo, combinação
de tipos de caractere, ou conter o nome do usuário.

Consulte a política real do seu domínio com:

```bash
samba-tool domain passwordsettings show
```

e ajuste o **tamanho mínimo de senha** no cadastro do domínio. Se a senha
passar na validação e ainda assim for recusada, quase sempre é o histórico: o
AD guarda as últimas senhas da conta e não aceita repetir nenhuma delas.

## Segurança

- Sem framework ou pacotes externos — só `ext-ldap` e `PDO`, o que reduz a
  superfície de dependências.
- Apenas `public/` é exposto pelo servidor web; `config/`, `src/` e `.git`
  ficam fora, reforçados por `.htaccess`.
- A senha do bind é cifrada no banco; a chave fica no arquivo de configuração.
- Sessões com cookies `HttpOnly` e `SameSite=Strict`, e troca obrigatória de
  senha provisória no primeiro acesso.
- Exclusão de contas exige papel `admin` e confirmação digitada, e é barrada
  fora da OU gerenciada.
- Restrinja o acesso por IP/rede no servidor web, além de qualquer firewall.
- Convém ligar `zend.exception_ignore_args=On` no `php.ini`: com ele
  desligado, um erro fatal grava os argumentos das funções no log — incluindo
  senhas digitadas na tela de login.
- O certificado LDAPS de domínios internos costuma ser autoassinado ou estar
  vencido. Nesse caso, desmarque a validação TLS no cadastro do domínio.
  Renovar o certificado e voltar a validá-lo é o caminho recomendado.

## Estrutura do projeto

```
public/         document root do servidor web (único diretório exposto)
  install.php   instalador; apague após instalar
src/            classes PHP (Config, Database, Auth, Crypto, camada LDAP)
config/         config.php gerado pelo instalador (ignorado pelo git)
sql/            schema do banco
bin/            linha de comando (diagnóstico do AD, admin inicial, migração)
deploy/         exemplos de configuração de infraestrutura (vhost Apache)
```

## Atualizando de uma versão anterior

Instalações antigas guardavam o domínio em `config/config.php`. Para migrar:

```bash
php bin/migrar-dominio.php
```

O script testa a conexão, grava o domínio no banco com a senha cifrada e
mantém o arquivo intacto. Depois de conferir que tudo funciona, remova o bloco
`ldap` do `config.php`, preservando `db` e `app`.

## Roadmap / ideias futuras

- Página de detalhes do usuário com histórico de auditoria filtrado
- Exportação de relatórios (CSV/Excel)
- Suporte a 2FA para login na própria ferramenta
- Internacionalização (hoje a interface é só em português)

## Licença

MIT — veja [LICENSE](LICENSE).
