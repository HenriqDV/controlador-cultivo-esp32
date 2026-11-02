-- Caixa d'água (HC-SR04): distância medida, litros e percentual da capacidade (firmware 2.7.0).
ALTER TABLE leituras_ambiente ADD COLUMN agua_distancia_cm DECIMAL(5,1) NULL AFTER vpd;
ALTER TABLE leituras_ambiente ADD COLUMN agua_litros DECIMAL(5,1) NULL AFTER agua_distancia_cm;
ALTER TABLE leituras_ambiente ADD COLUMN agua_percentual TINYINT UNSIGNED NULL AFTER agua_litros;
