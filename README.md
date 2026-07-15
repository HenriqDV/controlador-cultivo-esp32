# 🌿 GrowController ESP32 — Automação para Cultivo Indoor

Sistema de automação para estufa de cultivo indoor baseado em **ESP32-S3**, com controle de iluminação, ventilação, monitoramento de sensores (internos e externos), interface **OLED SSD1306** com telas dedicadas, acesso remoto via **Blynk**, persistência de estado entre quedas de energia e sincronização de horário via **NTP** com fallback para RTC.

> No firmware, o projeto é identificado internamente como **"Cultivo"** (nome do template Blynk e título exibido no cabeçalho do OLED).

---

## Funcionalidades

- **Controle automático de iluminação** com base no modo de cultivo e horário do dia
- **Override manual da luz** (Auto / Ligada / Desligada) que **não desabilita** o fotoperíodo — o timer continua contando por baixo e retoma sozinho após 30 minutos
- **Dois modos de cultivo**: Vegetação (18h luz) e Floração (12h luz)
- **Monitoramento ambiental interno** com sensor de temperatura e umidade do ar dentro da estufa (SHT40 ou DHT22, conforme o modo de compilação)
- **Monitoramento ambiental externo** com sensor de temperatura e umidade independente, do lado de fora da estufa (DHT11 no hardware físico / DHT22 na simulação Wokwi), sempre presente nos dois modos de compilação
- **Sensor de umidade do solo** analógico com calibração por faixa de leitura bruta e média de múltiplas amostras (redução de ruído do ADC)
- **Controle de 8 relés** para luz, acesso, exaustores, ventiladores e periféricos
- **Trava eletrônica de acesso** com liberação temporizada via botão físico ou app remoto
- **Display OLED SSD1306 (128x64)** com tela de descanso animada (sol/lua), tela de informações e menu de configuração interativo, navegáveis por botão físico
- **Indicador de conexão** no cabeçalho em formato de barras, cada uma com significado fixo (WiFi / Blynk configurado / Blynk conectado)
- **Indicadores de "sensor vivo"**: piscam a cada leitura válida de cada sensor (interno, externo e solo), mesmo sem mudança de valor
- **Persistência entre quedas de energia**: estado dos relés manuais e modo de cultivo sobrevivem a reinícios, gravados na NVRAM com bateria do RTC DS1307
- **LED NeoPixel** de status com animações por estado do sistema (interno no hardware físico, externo na simulação)
- **Buzzer** para feedback sonoro em ações
- **Controle remoto via Blynk** (IoT): telemetria, comandos e navegação de menu
- **Sincronização de hora via NTP** com múltiplos servidores de fallback e reserva no RTC DS1307
- **Reconexão automática** de Wi-Fi e Blynk com estratégia de backoff
- **Modo de simulação Wokwi** para testes sem hardware físico, com troca automática de sensor interno, LED e pinagem

---

## Hardware Necessário

### Barramento I2C
Os dispositivos SHT40, RTC DS1307 e display OLED compartilham o mesmo barramento I2C.

| Sinal | Pino ESP32-S3 | Conectar em |
|---|---|---|
| SDA | 8 | SDA do OLED + SDA do RTC DS1307 + SDA do SHT40 |
| SCL | 9 | SCL do OLED + SCL do RTC DS1307 + SCL do SHT40 |
| 3.3V | 3V3 | VCC do OLED + VCC do RTC DS1307 + VCC do SHT40 |
| GND | GND | GND do OLED + GND do RTC DS1307 + GND do SHT40 |

> O display OLED deve estar configurado no endereço **0x3C** (padrão da maioria dos módulos SSD1306 128x64).

---

### Sensor de Temperatura e Umidade (Interno)

**Hardware real → SHT40** (padrão quando `WOKWI_SIMULATION 0`)

| Pino SHT40 | Pino ESP32-S3 |
|---|---|
| VIN | 3V3 |
| GND | GND |
| SDA | 8 |
| SCL | 9 |

**Simulação Wokwi → DHT22** (quando `WOKWI_SIMULATION 1`)

| Pino DHT22 | Pino ESP32-S3 |
|---|---|
| VCC | 3V3 |
| GND | GND |
| DATA | 15 |

> Resistor de pull-up de 10kΩ entre DATA e VCC é recomendado para o DHT22.

---

### Sensor de Temperatura e Umidade (Externo)

Sensor independente, instalado **fora da estufa**, usado para comparar as condições internas com o ambiente externo. Ao contrário do sensor interno, este **não muda de tipo entre os dois modos de compilação** — só o pino GPIO é fixo (16) e o tipo de sensor muda por padrão:

- **Hardware físico:** DHT11
- **Simulação Wokwi:** DHT22 (o Wokwi não tem um componente DHT11 dedicado, então a simulação usa o mesmo modelo do sensor interno)

| Pino DHT (externo) | Pino ESP32-S3 |
|---|---|
| VCC | 3V3 |
| GND | GND |
| DATA | 16 |

> Resistor de pull-up de 10kΩ entre DATA e VCC é recomendado.

---

### Módulo RTC DS1307

| Pino DS1307 | Pino ESP32-S3 |
|---|---|
| VCC | 3V3 |
| GND | GND |
| SDA | 8 |
| SCL | 9 |

> O DS1307 tem 56 bytes de memória RAM com bateria de backup (CR2032), usada não só para manter a hora, mas também para **gravar o estado dos relés manuais e o modo de cultivo entre reinícios**. Sem a bateria (ou com ela descarregada), o oscilador para e a hora fica travada em 2000-01-01 — o firmware detecta isso no boot (`rtc.isrunning()`) e ajusta para a hora de compilação como ponto de partida até o próximo sincronismo NTP.

---

### Display OLED SSD1306 (128x64)

| Pino OLED | Pino ESP32-S3 |
|---|---|
| VCC | 3V3 |
| GND | GND |
| SDA | 8 |
| SCL | 9 |

> Compatível com os módulos "bicolores" (faixa amarela de 16px no topo + azul no restante) — o layout das telas já reserva essa faixa para o cabeçalho e evita desenhar conteúdo na costura entre as cores.

---

### Sensor de Umidade do Solo (analógico)

| Pino do sensor | Pino ESP32-S3 |
|---|---|
| VCC | 3V3 |
| GND | GND |
| AOUT (sinal analógico) | 6 |

> A leitura bruta é invertida por natureza em sensores capacitivos/resistivos reais (seco = ADC alto, úmido = ADC baixo). Os valores de calibração estão definidos no código como `SOLO_ADC_SECO` e `SOLO_ADC_UMIDO` — ajuste-os com os valores reais do seu sensor (veja o log no Serial Monitor, que imprime a leitura bruta a cada mudança). Valores de referência calibrados neste projeto: seco = `4095`, úmido = `2240`.
>
> ⚠️ Confira também a tensão de alimentação: se o sensor for alimentado em 5V com a saída ligada direto num GPIO do ESP32 (limite de 3,3V), a leitura pode saturar no máximo independente da umidade real.

---

### Módulo de 8 Relés

Todos os relés são **ativo em LOW** por padrão (HIGH = desligado, LOW = ligado), configurável pela constante `RELE_ATIVO_EM_BAIXO`. Conecte o sinal de controle de cada canal ao pino correspondente do ESP32-S3.

| Canal do módulo | Pino ESP32-S3 | Função |
|---|---|---|
| IN1 | 42 | Luz de Cultivo |
| IN2 | 41 | Trava de Acesso |
| IN3 | 40 | Saída de Ar Vivosun |
| IN4 | 39 | Entrada de Ar Fan |
| IN5 | 38 | Ventilador Principal |
| IN6 | 37 | Saída de Ar Dois |
| IN7 | 36 | Entrada de Ar Dois |
| IN8 | 35 | Ventilador Dois |
| VCC | 5V | Alimentação do módulo |
| GND | GND | GND comum |

> Módulos de relé com optoacoplador geralmente exigem alimentação de **5V** no VCC do módulo. O sinal de controle de 3.3V do ESP32-S3 é suficiente para acionar os optoacopladores na maioria dos modelos.
>
> ⚠️ **Atenção (ESP32-S3 com PSRAM Octal):** em módulos como variantes "N16R8" (WROOM-2), os pinos **GPIO35, GPIO36 e GPIO37** são usados internamente pela PSRAM e **não podem** ser usados como GPIO comum — isso afeta diretamente os canais IN6, IN7 e IN8 da tabela acima. Confira a serigrafia do módulo (WROOM-1 = Quad, livre / WROOM-2 = Octal, reservado) ou teste empiricamente antes de montar o circuito definitivo; remapeie esses relés para outros GPIOs livres se for o seu caso.

---

### LED de Status (NeoPixel)

O pino do LED **muda automaticamente** conforme o modo de compilação (`WOKWI_SIMULATION`):

| Modo | Pino | Observação |
|---|---|---|
| Simulação Wokwi (`1`) | 4 | LED NeoPixel externo (WS2812B) |
| Hardware físico (`0`) | 48 | LED RGB **embutido** na placa ESP32-S3 DevKit (não precisa de fiação externa) |

Se estiver usando um NeoPixel externo no hardware físico em vez do LED embutido, ajuste `LED_PIN` manualmente para o GPIO desejado e conecte com um resistor de 300–500Ω em série no DIN.

---

### Buzzer

| Pino Buzzer | Pino ESP32-S3 |
|---|---|
| + (positivo) | 17 |
| − (negativo) | GND |

> Use um buzzer **passivo** (sem oscilador interno). O código aciona o pino diretamente em HIGH/LOW, sem bloquear o loop principal (desligamento agendado por temporizador).

---

### Botões

| Botão | Pino ESP32-S3 | Modo |
|---|---|---|
| Botão Menu OLED | 5 | INPUT_PULLUP — conectar entre o pino e GND |
| Botão Acesso (TRV) | 19 | INPUT_PULLUP — conectar entre o pino e GND |

> Nenhum resistor externo é necessário — o pull-up interno do ESP32-S3 é habilitado via `INPUT_PULLUP`. Ambos os botões têm debounce por software.

---

## Modos de Cultivo

### 🌱 Vegetação
- **Luz ligada:** 16h00 até 10h00 (18h de luz / 6h de escuro)
- Ideal para crescimento vegetativo

### 🌸 Floração
- **Luz ligada:** 19h00 até 07h00 (12h de luz / 12h de escuro)
- Ideal para indução e manutenção da fase de floração

O modo pode ser alternado pelo botão físico (item "Modo Cultivo" no menu de configuração), pelo app Blynk (V10) ou remotamente simulando cliques no menu (V18/V19).

### Override manual da luz

Além do modo automático, existe um item dedicado **"Luz Manual"** no menu de configuração, com 3 estados alternados por pressão longa:

```
Auto → Ligada → Desligada → Auto → ...
```

Pontos importantes sobre esse recurso:

- O cálculo do fotoperíodo **nunca para** — mesmo em modo manual, o sistema continua calculando se a luz "deveria" estar ligada pelo horário; o override apenas sobrepõe a saída final do relé.
- Por segurança, o override **expira sozinho após 30 minutos**, retomando o controle automático sem qualquer ação do usuário — evita esquecer a luz travada manualmente e comprometer a floração por vazamento de luz. A expiração é silenciosa (não há contador regressivo na tela); o item volta a mostrar "Auto" no menu assim que isso acontece.
- O item "Luz Manual" no menu de configuração mostra o estado atual como texto: `Auto`, `ON` ou `OFF`.
- Não existe pino virtual dedicado no Blynk para este override — hoje ele só é acessível pelo menu local do OLED.

---

## Interface OLED (SSD1306 128x64)

O display é ativado por clique curto no botão do menu e volta automaticamente para a tela de descanso após **15 segundos** de inatividade (ou logo após uma mensagem temporária expirar).

Todas as telas — **inclusive a de descanso** — compartilham o mesmo cabeçalho: título, relógio e indicador de conexão em barras, numa faixa de 16px no topo. Não existe mais um rodapé fixo padrão; cada tela desenha suas próprias informações inferiores.

### Telas / estados disponíveis

| Tela / Estado | Conteúdo |
|---|---|
| **Descanso** (padrão/ociosa) | Cabeçalho compartilhado (título "Cultivo", hora, conexão) + coluna de dados à esquerda (temperatura e umidade internas, modo de cultivo, status do solo) + ícone animado de sol (luz ligada) ou lua (luz desligada) à direita, com o tempo restante do ciclo de luz logo abaixo |
| **Informações** (tela principal) | Duas colunas lado a lado — Interna e Externa — cada uma com temperatura, umidade e um indicador de "sensor vivo" (bolinha que pisca a cada leitura); linha final "Solo:" com o status do solo e seu próprio indicador de "sensor vivo" |
| **Configuração** (confirmação) | Tela intermediária com o cabeçalho de tabela "Dispositivos"/"Status" e a instrução "Segure Para Abrir" — exige pressão longa para entrar de fato na lista |
| **Configuração** (lista) | Lista rolável com 8 itens: Modo Cultivo, Luz Manual e os 6 relés manuais, mostrando nome e estado atual de cada um, com janela de 4 itens visíveis centralizada na seleção |
| **Mensagem temporária** | Texto centralizado (ex: "Modo: Floracao", "Porta Liberada"), fecha sozinha após um tempo definido e volta para a tela de descanso |

### Indicador de conexão (cabeçalho)

Três barras de sinal, cada uma com significado **fixo e independente** (não é uma contagem de nível):

| Barra | Significado |
|---|---|
| 1ª | WiFi conectado |
| 2ª | Blynk configurado (`Blynk.config()` já foi chamado) |
| 3ª | Blynk realmente conectado ao servidor |

Isso permite diagnosticar exatamente em qual etapa a conexão travou (ex: 1ª e 2ª cheias, 3ª vazia = token do Blynk incorreto ou servidor inacessível).

### Navegação por botão

| Ação | Contexto | Resultado |
|---|---|---|
| Clique curto | Tela de descanso | Acorda o display direto na tela de Informações |
| Clique curto | Tela de Informações | Avança para a tela de Configuração (pede confirmação) |
| Clique curto | Tela de confirmação da Configuração | Pula a confirmação e volta para Informações |
| Clique curto | Lista de dispositivos (Configuração já aberta) | Avança o item selecionado na lista |
| Pressão longa | Tela de confirmação da Configuração | Abre a lista de dispositivos, já com a seta no primeiro item |
| Pressão longa | Lista de dispositivos (Configuração já aberta) | Ativa/alterna o item atualmente selecionado |

O mesmo fluxo pode ser acionado remotamente pelo Blynk (V18 = clique curto, V19 = clique longo).

---

## Relés

| Índice | Identificação | Controle |
|---|---|---|
| 0 | Luz de Cultivo | Automático (horário) + override manual |
| 1 | Trava de Acesso | Automático (temporizado) |
| 2 | Saída de Ar Vivosun | Manual / Blynk V11 |
| 3 | Entrada de Ar Fan | Manual / Blynk V12 |
| 4 | Ventilador Principal | Manual / Blynk V13 |
| 5 | Saída de Ar Dois | Manual / Blynk V14 |
| 6 | Entrada de Ar Dois | Manual / Blynk V15 |
| 7 | Ventilador Dois | Manual / Blynk V16 |

Todos os relés são **ativo em LOW** por padrão (`RELE_ATIVO_EM_BAIXO`).

### Persistência entre quedas de energia

O estado dos relés manuais (2 a 7) e o modo de cultivo são gravados na NVRAM do RTC DS1307 sempre que mudam, e restaurados automaticamente no boot. **Não são restaurados**, por design:
- **Luz (relé 0):** é sempre recalculada pelo horário assim que o sistema liga.
- **Acesso/trava (relé 1):** nunca volta ligada sozinha, por segurança.
- **Override manual de luz:** sempre volta para "Automático" após um reinício.

---

## Integração Blynk

O projeto utiliza **Blynk IoT** para monitoramento e controle remoto.

### Pinos virtuais — Telemetria (leitura no app)

| Virtual Pin | Dado |
|---|---|
| V1 | Umidade do ar interna (%) |
| V2 | Temperatura interna (°C) |
| V4 | Umidade do solo (%) |
| V5 | Estado do relé de luz (0/1) |
| V6 | Modo de cultivo (texto) |
| V7 | Exaustão ativa — relés 2 e 3 (0/1) |
| V8 | Timestamp completo (string) |
| V9 | Tempo restante do ciclo de luz |

> Não há pinos virtuais dedicados para o sensor externo (GPIO16) — ele é exibido apenas na tela de Informações do OLED.

### Pinos virtuais — Controle (escrita no app)

| Virtual Pin | Função |
|---|---|
| V10 | Modo de cultivo (0=Vegetação, 1=Floração) |
| V11–V16 | Relés 2 a 7 (0=OFF, 1=ON) |
| V17 | Liberar acesso por 3 segundos (autozera) |
| V18 | Clique curto no menu do OLED (autozera) |
| V19 | Clique longo no menu do OLED (autozera) |

> O override manual de luz (Auto/Ligada/Desligada) ainda não tem um pino virtual dedicado — hoje só é acessível pelo menu local do OLED.

---

## LED de Status (NeoPixel)

| Estado | Cor / Animação |
|---|---|
| Blynk conectado / sistema ocioso | Azul pulsante suave |
| Conectando Wi-Fi | Laranja pulsante |
| Conectando Blynk | Ciano pulsante |
| Porta liberada | Verde sólido |
| Ação confirmada (LED_OK) | Verde sólido temporário |
| Erro | Vermelho sólido (definido, mas ainda não disparado por nenhuma condição do código — ponto de extensão futuro) |

---

## Sincronização de Hora

A hora é obtida por ordem de prioridade:

1. **RTC DS1307** local (usado sempre que disponível e com ano plausível, ≥ 2024)
2. **NTP** via internet, com múltiplos servidores de fallback (`pool.ntp.org`, `time.google.com`, `a.st1.ntp.br`, `time.windows.com`) — alterna de servidor a cada falha, fuso UTC-3
3. **Fallback** — data fixa `2026-01-01 00:00:00` caso nenhuma fonte esteja disponível (RTC sem bateria/nunca sincronizado e sem WiFi)

Quando o NTP sincroniza com sucesso, o RTC é atualizado automaticamente.

---

## Reconexão Automática

- **Wi-Fi:** tenta a cada 5 segundos no primeiro minuto desconectado, depois a cada 30 segundos
- **Blynk:** tenta a cada 5 segundos, somente quando Wi-Fi está ativo e a interface local não está em uso
- **NTP:** retenta a cada 60 segundos até sincronizar, alternando entre servidores a cada tentativa

---

## Simulação Wokwi

O projeto possui suporte nativo ao [Wokwi](https://wokwi.com), plataforma de simulação de hardware para Arduino e ESP32 que roda direto no navegador, sem necessidade de nenhum componente físico.

🔗 **Simulador pronto:** [wokwi.com/projects/469072620110084097](https://wokwi.com/projects/469072620110084097)

### Como ativar

No início do arquivo `.ino`, altere a linha:

```cpp
#define WOKWI_SIMULATION 0  // hardware real
```
para:
```cpp
#define WOKWI_SIMULATION 1  // simulação Wokwi
```

### O que muda com a flag ativada

| Comportamento | Hardware real (`0`) | Simulação Wokwi (`1`) |
|---|---|---|
| Sensor de temp/umidade interno | SHT40 via I2C | DHT22 no pino 15 |
| Biblioteca do sensor interno | `Adafruit_SHT4x` | `DHT.h` |
| Inicialização do sensor interno | `sht4.begin()` | `dht.begin()` |
| Leitura do sensor interno | `sht4.getEvent()` | `dht.readTemperature/Humidity()` |
| Sensor de temp/umidade externo | DHT11 no pino 16 | DHT22 no pino 16 |
| LED de status (NeoPixel) | Pino 48 (LED embutido) | Pino 4 (LED externo) |
| Rede Wi-Fi | Precisa ser a rede real do usuário | `"Wokwi-GUEST"` (aberta, com internet real dentro do simulador) |

> O sensor externo (GPIO16) usa a mesma classe `DHT` da biblioteca `DHT.h` nos dois modos — só o tipo do sensor (`DHT_EXT_TYPE`) muda automaticamente.

### O que pode ser testado na simulação

- Navegação completa pelo menu do OLED (botão físico virtual ou via Blynk V18/V19)
- Leitura e exibição de temperatura e umidade interna (DHT22) e externa (DHT22)
- Leitura analógica do solo (variando o potenciômetro)
- Controle de relés pelo menu ou pelo app Blynk
- Alternância entre modos Vegetação e Floração, e override manual de luz
- Lógica de horário para controle automático da luz
- Conexão Wi-Fi, NTP e Blynk (o Wokwi suporta Wi-Fi simulado com acesso real à internet)
- Animação da tela de descanso (sol/lua) e indicadores de sensor vivo (interno, externo e solo)

---

## Dependências

Instale as seguintes bibliotecas via Arduino Library Manager ou PlatformIO:

- `Adafruit GFX Library`
- `Adafruit SSD1306`
- `RTClib` (Adafruit)
- `Adafruit SHT4x` (+ dependências: `Adafruit BusIO`, `Adafruit Unified Sensor`) — só necessária no modo hardware físico
- `DHT sensor library` (Adafruit) — usada pelo sensor interno em simulação e sempre pelo sensor externo
- `Adafruit NeoPixel`
- `Blynk` (BlynkSimpleEsp32)
- `WiFi` (built-in ESP32)

---

## Configuração

Antes de compilar, edite as seguintes constantes no início do arquivo:

```cpp
// Modo de compilação
#define WOKWI_SIMULATION 0   // 0 = hardware físico | 1 = simulação Wokwi

// Credenciais Wi-Fi (trocar pela rede real ao gravar no hardware físico)
const char* ssid     = "SUA_REDE";
const char* password = "SUA_SENHA";

// Credenciais Blynk
#define BLYNK_TEMPLATE_ID   "SEU_TEMPLATE_ID"
#define BLYNK_TEMPLATE_NAME "Nome do Template"
#define BLYNK_AUTH_TOKEN    "SEU_AUTH_TOKEN"
```

E calibre o sensor de solo com os valores reais do seu hardware:

```cpp
const int SOLO_ADC_SECO = 4095;   // leitura bruta com o sensor seco (ver Serial Monitor)
const int SOLO_ADC_UMIDO = 2240;  // leitura bruta com o sensor em água
```

Se os relés vierem invertidos ao ligar o sistema (acendendo quando deveriam estar desligados), ajuste também:

```cpp
#define RELE_ATIVO_EM_BAIXO true // troque para false se necessário
```

---

## Estrutura do Código

```
setup()
├── Inicialização de pinos, relés (respeitando RELE_ATIVO_EM_BAIXO), buzzer e botões
├── LED NeoPixel (pixel.begin)
├── Barramento I2C (Wire.begin)
├── Display OLED, sensor de ar interno (SHT40 ou DHT22) e sensor externo (DHT11/DHT22)
├── RTC: detecção + verificação do oscilador (isrunning) + ajuste de emergência
├── Restauração de relés/modo salvos na NVRAM (queda de energia anterior)
├── Conexão Wi-Fi (bloqueante, com timeout)
├── Sincronização NTP
└── Configuração Blynk

loop()
├── atualizarLED()                — animações NeoPixel por estado
├── atualizarBuzzer()             — desliga buzzer após tempo configurado
├── atualizarAcesso()             — fecha trava após tempo de liberação
├── atualizarMensagemTemporaria() — expira mensagens no OLED
├── atualizarBotaoOLED()          — leitura, debounce e clique curto/longo do botão de menu
├── atualizarBotaoAcesso()        — leitura e debounce do botão físico de liberar acesso
├── atualizarTimeoutOLED()        — volta para a tela de descanso por inatividade
├── atualizarDHT()                — leitura do sensor de temp/umidade interno + sensor vivo
├── atualizarDHTExterno()         — leitura do sensor de temp/umidade externo + sensor vivo
├── atualizarPulsoSensor()        — apaga o indicador de sensor vivo (interno)
├── atualizarPulsoSensorExterno() — apaga o indicador de sensor vivo (externo)
├── atualizarSolo()               — leitura (com média) e calibração do sensor de solo
├── atualizarPulsoSensorSolo()    — apaga o indicador de sensor vivo (solo)
├── atualizarSincronizacaoHora()  — retry NTP periódico com múltiplos servidores
├── atualizarConexaoWiFi()        — reconexão automática Wi-Fi com backoff
├── atualizarBlynk()              — conecta, roda (Blynk.run) e publica telemetria/estado
├── controlarLuz(now)             — aplica fotoperíodo/override no relé de luz
└── renderizarOLED(now)           — renderiza a tela ativa no display (só se houver mudança)
```

---

## Licença

Este projeto é de uso pessoal/educacional. Adapte livremente para seu ambiente de cultivo.
