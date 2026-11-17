-- Corrige os labels iniciais dos menus da empresa Modelo em PT, EN e ES.
USE `gmodelo_dbp`;
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

UPDATE `menus` SET `label`='Dashboard', `label_pt`='Dashboard', `label_en`='Dashboard', `label_es`='Panel' WHERE `codigo`='DASHBOARD';
UPDATE `menus` SET `label`='Setup', `label_pt`='Setup', `label_en`='Setup', `label_es`='Configuración' WHERE `codigo`='SETUP';
UPDATE `menus` SET `label`='Login', `label_pt`='Login', `label_en`='Login', `label_es`='Inicio de sesión' WHERE `codigo`='SETUP_LOGIN';
UPDATE `menus` SET `label`='Main', `label_pt`='Main', `label_en`='Main', `label_es`='Principal' WHERE `codigo`='SETUP_MAIN';
UPDATE `menus` SET `label`='Grid', `label_pt`='Grid', `label_en`='Grid', `label_es`='Cuadrícula' WHERE `codigo`='SETUP_GRID';
UPDATE `menus` SET `label`='Formulário', `label_pt`='Formulário', `label_en`='Form', `label_es`='Formulario' WHERE `codigo`='SETUP_FORMULARIO';
UPDATE `menus` SET `label`='Segurança', `label_pt`='Segurança', `label_en`='Security', `label_es`='Seguridad' WHERE `codigo`='SEGURANCA';
UPDATE `menus` SET `label`='Manutenção Legal', `label_pt`='Manutenção Legal', `label_en`='Legal Maintenance', `label_es`='Mantenimiento Legal' WHERE `codigo`='MANUTENCAO_LEGAL';
UPDATE `menus` SET `label`='Módulo Legal', `label_pt`='Módulo Legal', `label_en`='Legal Module', `label_es`='Módulo Legal' WHERE `codigo`='MODULO_LEGAL';
UPDATE `menus` SET `label`='Licenças', `label_pt`='Licenças', `label_en`='Licenses', `label_es`='Licencias' WHERE `codigo`='LICENCAS';
