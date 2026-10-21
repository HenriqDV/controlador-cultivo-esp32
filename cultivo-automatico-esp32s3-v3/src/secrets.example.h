#pragma once
// Modelo de segredos: copie para secrets.h (fora do git) e preencha.
// Opcional desde a v2.3.0: sem rede preenchida, o ESP32 abre o portal Wi-Fi
// "SmartDemetra-Setup" (192.168.4.1), onde se escolhe a rede e cola o token de
// vinculação gerado em Dispositivo. O que for salvo no portal vale mais que este arquivo.

#define SECRET_WIFI_SSID     "SUA_REDE_WIFI"
#define SECRET_WIFI_PASSWORD "SUA_SENHA_WIFI"

// Mesma API_KEY de hostinger/config.php (usada até o ESP32 receber um token sd_hw_)
#define SECRET_HOSTINGER_BASE_URL "https://SEU-DOMINIO.hostingersite.com"
#define SECRET_HOSTINGER_API_KEY  "SUA_API_KEY_IGUAL_AO_CONFIG_PHP"
