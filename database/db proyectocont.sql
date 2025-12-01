-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 01-12-2025 a las 05:59:40
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `proyectocont_v2`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `bloques`
--

CREATE TABLE `bloques` (
  `id` int(11) NOT NULL,
  `numero` int(11) NOT NULL,
  `patio_id` int(11) NOT NULL,
  `capacidad_maxima` int(11) NOT NULL DEFAULT 25,
  `capacidad_actual` int(11) NOT NULL DEFAULT 0,
  `estado_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `bloques`
--

INSERT INTO `bloques` (`id`, `numero`, `patio_id`, `capacidad_maxima`, `capacidad_actual`, `estado_id`) VALUES
(1, 1, 2, 25, 3, 1),
(2, 1, 4, 25, 0, 1),
(3, 2, 2, 25, 3, 1),
(4, 2, 4, 25, 1, 1);

--
-- Disparadores `bloques`
--
DELIMITER $$
CREATE TRIGGER `bloques_check_capacidad` BEFORE INSERT ON `bloques` FOR EACH ROW BEGIN
    IF NEW.capacidad_actual > NEW.capacidad_maxima THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La capacidad actual no puede exceder la capacidad máxima.';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `bloques_update_check_capacidad` BEFORE UPDATE ON `bloques` FOR EACH ROW BEGIN
    IF NEW.capacidad_actual > NEW.capacidad_maxima THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La capacidad actual no puede exceder la capacidad máxima.';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `contenedores`
--

CREATE TABLE `contenedores` (
  `id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `posicion` int(11) NOT NULL,
  `bloque_id` int(11) NOT NULL,
  `tipo_unidad_id` int(11) NOT NULL,
  `modelo_id` int(11) NOT NULL,
  `estatus_unidad_id` int(11) NOT NULL,
  `observaciones_unidad` text DEFAULT NULL,
  `estatus_box_id` int(11) NOT NULL,
  `observaciones_box` text DEFAULT NULL,
  `estatus_ipsa_id` int(11) NOT NULL,
  `estado_id` int(11) NOT NULL DEFAULT 1,
  `fecha_ingreso` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `contenedores`
--

INSERT INTO `contenedores` (`id`, `nombre`, `posicion`, `bloque_id`, `tipo_unidad_id`, `modelo_id`, `estatus_unidad_id`, `observaciones_unidad`, `estatus_box_id`, `observaciones_box`, `estatus_ipsa_id`, `estado_id`, `fecha_ingreso`) VALUES
(1, 'PRUEBA12334', 23, 3, 2, 4, 3, 'qwert', 4, 'qwert', 1, 1, '2025-11-24 21:13:38'),
(2, 'PRUEBA12444', 24, 3, 1, 1, 3, '', 4, '', 1, 1, '2025-11-24 23:25:52'),
(3, 'PRUEBA12222', 25, 3, 2, 4, 5, '', 7, '', 3, 1, '2025-11-24 23:26:18'),
(4, 'PRUEBAU3455', 25, 4, 1, 5, 3, 'Todo bien', 4, 'Todo Bien', 1, 1, '2025-11-26 10:38:16'),
(5, 'PRUEBAMODIF', 22, 3, 1, 1, 3, '', 2, '', 2, 1, '2025-11-27 09:59:10'),
(6, 'HGF23453535', 25, 1, 1, 1, 3, '', 4, '', 1, 1, '2025-11-29 18:32:37'),
(8, 'VAN23233232', 24, 1, 2, 3, 3, '', 2, '', 2, 1, '2025-11-30 17:18:09');

--
-- Disparadores `contenedores`
--
DELIMITER $$
CREATE TRIGGER `contenedores_ad` AFTER DELETE ON `contenedores` FOR EACH ROW BEGIN
    UPDATE bloques
    SET capacidad_actual = capacidad_actual - 1
    WHERE id = OLD.bloque_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `contenedores_ai` AFTER INSERT ON `contenedores` FOR EACH ROW BEGIN
    -- Se recomienda que la lógica de bloqueo se maneje en el trigger BEFORE INSERT 
    -- o directamente en el PHP. Aquí solo se hace la actualización:
    
    UPDATE bloques
    SET capacidad_actual = capacidad_actual + 1
    WHERE id = NEW.bloque_id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `estados`
--

CREATE TABLE `estados` (
  `id` int(11) NOT NULL,
  `nombre` varchar(50) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `estados`
--

INSERT INTO `estados` (`id`, `nombre`, `descripcion`) VALUES
(1, 'Activo', 'El registro está habilitado y visible en la aplicación.'),
(2, 'Inactivo', 'El registro está deshabilitado y oculto en la aplicación.');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `estatus_contenedores`
--

CREATE TABLE `estatus_contenedores` (
  `id` int(11) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `tipo_estatus_id` int(11) NOT NULL,
  `estado_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `estatus_contenedores`
--

INSERT INTO `estatus_contenedores` (`id`, `nombre`, `tipo_estatus_id`, `estado_id`) VALUES
(1, 'DMG', 1, 1),
(2, 'DMG', 2, 1),
(3, 'OK', 1, 1),
(4, 'OK', 2, 1),
(5, 'Pendiente PTI', 1, 1),
(6, 'DMG Reparación Mínima', 2, 1),
(7, 'Pendiente Revisión', 2, 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `estatus_ipsa`
--

CREATE TABLE `estatus_ipsa` (
  `id` int(11) NOT NULL,
  `nombre` varchar(50) NOT NULL,
  `estado_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `estatus_ipsa`
--

INSERT INTO `estatus_ipsa` (`id`, `nombre`, `estado_id`) VALUES
(1, 'Disponible', 1),
(2, 'OOS', 1),
(3, 'Pendiente', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `logs_estatus`
--

CREATE TABLE `logs_estatus` (
  `evento_id` int(11) NOT NULL,
  `tipo_estatus_id` int(11) NOT NULL,
  `valor_anterior_id` int(11) DEFAULT NULL,
  `valor_nuevo_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `logs_estatus`
--

INSERT INTO `logs_estatus` (`evento_id`, `tipo_estatus_id`, `valor_anterior_id`, `valor_nuevo_id`) VALUES
(16, 1, 3, 1),
(23, 1, 1, 5),
(31, 1, 5, 3),
(32, 2, 4, 2),
(39, 1, 3, 5),
(40, 2, 4, 2),
(43, 1, 1, 3),
(44, 2, 2, 4),
(46, 1, 1, 3),
(47, 2, 2, 4),
(49, 1, 3, 1),
(51, 1, 1, 3),
(53, 2, 4, 6),
(54, 2, 4, 2),
(56, 2, 2, 4),
(58, 2, 6, 4),
(60, 2, 4, 6),
(61, 2, 6, 4),
(62, 1, 5, 3),
(63, 2, 2, 4),
(65, 1, 3, 1),
(66, 2, 4, 2),
(68, 1, 1, 3),
(69, 2, 2, 4),
(71, 2, 4, 2),
(73, 1, 3, 5),
(74, 2, 2, 7),
(76, 2, 7, 4),
(80, 1, 5, 3),
(84, 2, 4, 2),
(90, 2, 4, 2),
(92, 2, 2, 4),
(96, 1, 1, 3),
(97, 2, 2, 4);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `logs_eventos`
--

CREATE TABLE `logs_eventos` (
  `id` int(11) NOT NULL,
  `contenedor_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `fecha_evento` datetime NOT NULL DEFAULT current_timestamp(),
  `tipo_operacion_id` int(11) NOT NULL,
  `observaciones` text DEFAULT NULL,
  `estado_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `logs_eventos`
--

INSERT INTO `logs_eventos` (`id`, `contenedor_id`, `usuario_id`, `fecha_evento`, `tipo_operacion_id`, `observaciones`, `estado_id`) VALUES
(1, 1, 1, '2025-11-24 21:13:38', 1, 'Ingreso inicial.', 1),
(2, 2, 1, '2025-11-24 23:25:52', 1, 'Ingreso inicial (Equipo nuevo).', 1),
(3, 3, 1, '2025-11-24 23:26:18', 1, 'Ingreso inicial (Equipo nuevo).', 1),
(4, 4, 1, '2025-11-26 10:38:16', 1, 'Ingreso inicial (Equipo nuevo).', 1),
(5, 3, 1, '2025-11-26 10:54:29', 2, 'Traslado interno: Bloque 1 -> 3', 1),
(6, 2, 1, '2025-11-26 10:55:06', 2, 'Traslado interno: Bloque 1 -> 3', 1),
(7, 2, 1, '2025-11-26 11:22:13', 2, 'Traslado: De Patio 1 Bloque 1 (Pos 24) -> A Patio 1 Bloque 2 (Pos 24)', 1),
(8, 1, 1, '2025-11-26 14:39:11', 2, 'Traslado: De Patio 1 Bloque 1 (Pos 25) -> A Patio 1 Bloque 2 (Pos 23)', 1),
(9, 5, 1, '2025-11-26 14:46:50', 1, 'Ingreso inicial (Equipo nuevo).', 1),
(10, 5, 1, '2025-11-26 15:41:38', 4, 'Cambio de código de equipo', 1),
(11, 5, 1, '2025-11-26 15:41:38', 4, 'Cambio de Tipo de Unidad', 1),
(12, 5, 1, '2025-11-26 15:41:38', 4, 'Cambio de Modelo', 1),
(13, 5, 1, '2025-11-26 15:41:38', 4, 'Actualización Obs. Unidad', 1),
(14, 5, 1, '2025-11-26 15:41:38', 4, 'Actualización Obs. Box', 1),
(15, 5, 1, '2025-11-26 15:41:38', 3, 'Cambio de Estatus IPSA', 1),
(16, 5, 1, '2025-11-26 15:41:38', 3, 'Cambio Estatus de Unidad', 1),
(18, 5, 1, '2025-11-26 20:45:56', 4, 'Modificación: Nombre', 1),
(19, 5, 1, '2025-11-26 20:45:56', 4, 'Modificación: Tipo Unidad', 1),
(20, 5, 1, '2025-11-26 20:45:56', 4, 'Modificación: Modelo', 1),
(21, 5, 1, '2025-11-26 20:45:56', 4, 'Modificación: Obs. Unidad', 1),
(22, 5, 1, '2025-11-26 20:45:56', 4, 'Modificación: Obs. Box', 1),
(23, 5, 1, '2025-11-26 20:45:56', 3, 'Cambio Estatus Unidad', 1),
(24, 5, 1, '2025-11-26 20:45:56', 3, 'Cambio Estatus IPSA', 1),
(25, 5, 1, '2025-11-26 20:50:05', 4, 'Modificación: Obs. Unidad', 1),
(26, 5, 1, '2025-11-26 22:09:25', 4, 'Cambio de Nombre', 1),
(27, 5, 1, '2025-11-26 22:09:25', 4, 'Cambio de Tipo de Unidad', 1),
(28, 5, 1, '2025-11-26 22:09:25', 4, 'Cambio de Modelo', 1),
(29, 5, 1, '2025-11-26 22:09:25', 4, 'Actualización Obs. Unidad', 1),
(30, 5, 1, '2025-11-26 22:09:25', 4, 'Actualización Obs. Box', 1),
(31, 5, 1, '2025-11-26 22:09:25', 3, 'Cambio Estatus Unidad', 1),
(32, 5, 1, '2025-11-26 22:09:25', 3, 'Cambio Estatus Estructura', 1),
(33, 5, 1, '2025-11-26 22:09:25', 3, 'Cambio Estatus IPSA', 1),
(34, 1, 1, '2025-11-26 23:19:00', 4, 'Cambio de Nombre', 1),
(35, 1, 1, '2025-11-26 23:19:00', 4, 'Cambio de Tipo de Unidad', 1),
(36, 1, 1, '2025-11-26 23:19:00', 4, 'Cambio de Modelo', 1),
(37, 1, 1, '2025-11-26 23:19:00', 4, 'Actualización Obs. Unidad', 1),
(38, 1, 1, '2025-11-26 23:19:00', 4, 'Actualización Obs. Box', 1),
(39, 1, 1, '2025-11-26 23:19:00', 3, 'Cambio Estatus Unidad', 1),
(40, 1, 1, '2025-11-26 23:19:00', 3, 'Cambio Estatus Estructura', 1),
(41, 1, 1, '2025-11-26 23:19:00', 3, 'Cambio Estatus IPSA', 1),
(42, 5, 1, '2025-11-27 09:59:10', 1, 'Reingreso de equipo (Historial recuperado).', 1),
(43, 2, 1, '2025-11-28 09:08:34', 3, 'Cambio Estatus Unidad', 1),
(44, 2, 1, '2025-11-28 09:08:34', 3, 'Cambio Estatus Estructura', 1),
(45, 2, 1, '2025-11-28 09:08:34', 3, 'Cambio Estatus IPSA', 1),
(46, 5, 1, '2025-11-28 09:11:24', 3, 'Cambio Estatus Unidad', 1),
(47, 5, 1, '2025-11-28 09:11:24', 3, 'Cambio Estatus Estructura', 1),
(48, 5, 1, '2025-11-28 09:11:24', 3, 'Cambio Estatus IPSA', 1),
(49, 5, 1, '2025-11-28 09:18:34', 3, 'Cambio Estatus Unidad', 1),
(50, 5, 1, '2025-11-28 09:18:34', 3, 'Cambio Estatus IPSA', 1),
(51, 5, 1, '2025-11-28 09:21:07', 3, 'Cambio Estatus Unidad', 1),
(52, 5, 1, '2025-11-28 09:21:07', 3, 'Cambio Estatus IPSA', 1),
(53, 2, 1, '2025-11-28 09:22:57', 3, 'Cambio Estatus Estructura', 1),
(54, 5, 1, '2025-11-28 09:23:50', 3, 'Cambio Estatus Estructura', 1),
(55, 5, 1, '2025-11-28 09:23:50', 3, 'Cambio Estatus IPSA', 1),
(56, 5, 1, '2025-11-28 09:26:55', 3, 'Cambio Estatus Estructura', 1),
(57, 5, 1, '2025-11-28 09:26:55', 3, 'Cambio Estatus IPSA', 1),
(58, 2, 1, '2025-11-28 09:28:08', 3, 'Cambio Estatus Estructura', 1),
(59, 5, 1, '2025-11-28 09:34:17', 4, 'Cambio de Modelo', 1),
(60, 5, 1, '2025-11-28 09:34:52', 3, 'Cambio Estatus Estructura', 1),
(61, 5, 1, '2025-11-28 09:36:19', 3, 'Cambio Estatus Estructura', 1),
(62, 1, 1, '2025-11-28 09:39:50', 3, 'Cambio Estatus Unidad', 1),
(63, 1, 1, '2025-11-28 09:39:50', 3, 'Cambio Estatus Estructura', 1),
(64, 1, 1, '2025-11-28 09:41:21', 3, 'Cambio Estatus IPSA', 1),
(65, 5, 1, '2025-11-28 09:42:00', 3, 'Cambio Estatus Unidad', 1),
(66, 5, 1, '2025-11-28 09:42:00', 3, 'Cambio Estatus Estructura', 1),
(67, 5, 1, '2025-11-28 09:42:00', 3, 'Cambio Estatus IPSA', 1),
(68, 5, 1, '2025-11-28 09:51:15', 3, 'Cambio Estatus Unidad', 1),
(69, 5, 1, '2025-11-28 09:51:15', 3, 'Cambio Estatus Estructura', 1),
(70, 5, 1, '2025-11-28 09:51:15', 3, 'Cambio Estatus IPSA', 1),
(71, 5, 1, '2025-11-28 10:17:51', 3, 'Cambio Estatus Estructura', 1),
(72, 5, 1, '2025-11-28 10:17:51', 3, 'Cambio Estatus IPSA', 1),
(73, 5, 1, '2025-11-28 10:18:17', 3, 'Cambio Estatus Unidad', 1),
(74, 5, 1, '2025-11-28 10:18:17', 3, 'Cambio Estatus Estructura', 1),
(75, 5, 1, '2025-11-28 10:18:17', 3, 'Cambio Estatus IPSA', 1),
(76, 5, 1, '2025-11-28 10:19:36', 3, 'Cambio Estatus Estructura', 1),
(77, 5, 1, '2025-11-28 11:08:47', 4, 'Cambio de Nombre', 1),
(78, 5, 1, '2025-11-28 11:08:47', 4, 'Cambio de Tipo de Unidad', 1),
(79, 5, 1, '2025-11-28 11:08:47', 4, 'Cambio de Modelo', 1),
(80, 5, 1, '2025-11-28 11:08:47', 3, 'Cambio Estatus Unidad', 1),
(81, 5, 1, '2025-11-28 11:08:47', 3, 'Cambio Estatus IPSA', 1),
(82, 6, 1, '2025-11-28 11:31:13', 1, 'Ingreso inicial (Equipo nuevo).', 2),
(83, 6, 5, '2025-11-28 21:42:10', 4, 'Contenedor asignado para auditoría PYP', 2),
(84, 5, 1, '2025-11-28 21:45:01', 3, 'Cambio Estatus Estructura', 1),
(85, 5, 1, '2025-11-28 21:45:01', 3, 'Cambio Estatus IPSA', 1),
(86, 6, 5, '2025-11-28 22:27:44', 4, 'Auditoría PYP Actualizada. Estatus: Asignado.', 2),
(87, 6, 1, '2025-11-29 15:10:23', 5, 'Despacho exitoso. Destino/Piloto: Despachado el dia de hoy', 2),
(88, 2, 5, '2025-11-29 15:30:43', 4, 'Contenedor asignado para auditoría PYP', 1),
(89, 6, 1, '2025-11-29 18:14:34', 1, 'Reingreso de equipo (Historial recuperado).', 2),
(90, 6, 1, '2025-11-29 18:17:36', 3, 'Cambio Estatus Estructura', 2),
(91, 6, 1, '2025-11-29 18:17:46', 3, 'Cambio Estatus IPSA', 2),
(92, 6, 1, '2025-11-29 18:31:25', 3, 'Cambio Estatus Estructura', 2),
(93, 6, 1, '2025-11-29 18:31:25', 3, 'Cambio Estatus IPSA', 2),
(94, 6, 1, '2025-11-29 18:31:47', 5, 'Despacho exitoso. Observaciones: prueba 2', 2),
(95, 6, 1, '2025-11-29 18:32:37', 1, 'Reingreso de equipo (Historial previo archivado).', 1),
(96, 6, 1, '2025-11-29 18:34:36', 3, 'Cambio Estatus Unidad', 1),
(97, 6, 1, '2025-11-29 18:34:36', 3, 'Cambio Estatus Estructura', 1),
(98, 6, 1, '2025-11-29 18:34:36', 3, 'Cambio Estatus IPSA', 1),
(99, 4, 1, '2025-11-30 14:45:48', 4, 'Actualización Obs. Unidad', 1),
(100, 4, 1, '2025-11-30 14:45:48', 4, 'Actualización Obs. Box', 1),
(108, 8, 1, '2025-11-30 17:18:09', 1, 'Ingreso inicial (Equipo nuevo).', 1),
(109, 4, 1, '2025-11-30 22:49:10', 2, 'Traslado: De Patio santo Bloque 1 (Pos 25) -> A Patio santo Bloque 2 (Pos 25)', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `logs_ipsa`
--

CREATE TABLE `logs_ipsa` (
  `evento_id` int(11) NOT NULL,
  `estatus_anterior_id` int(11) DEFAULT NULL,
  `estatus_nuevo_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `logs_ipsa`
--

INSERT INTO `logs_ipsa` (`evento_id`, `estatus_anterior_id`, `estatus_nuevo_id`) VALUES
(15, 1, 2),
(24, 2, 3),
(33, 3, 2),
(41, 1, 2),
(45, 2, 1),
(48, 2, 1),
(50, 1, 2),
(52, 2, 1),
(55, 1, 2),
(57, 2, 1),
(64, 2, 1),
(67, 1, 2),
(70, 2, 1),
(72, 1, 2),
(75, 2, 3),
(81, 3, 1),
(85, 1, 2),
(91, 1, 2),
(93, 2, 1),
(98, 2, 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `logs_modificaciones`
--

CREATE TABLE `logs_modificaciones` (
  `evento_id` int(11) NOT NULL,
  `campo_modificado` varchar(50) NOT NULL,
  `valor_anterior` text DEFAULT NULL,
  `valor_nuevo` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `logs_modificaciones`
--

INSERT INTO `logs_modificaciones` (`evento_id`, `campo_modificado`, `valor_anterior`, `valor_nuevo`) VALUES
(10, 'Nombre', 'COSTCO12313', 'COSTCO12345'),
(11, 'Tipo Unidad', 'Prueba 2', 'Comunicaciones Celulares'),
(12, 'Modelo', 'cgfg', 'Cd2'),
(13, 'Obs. Unidad', '', 'Prueba'),
(14, 'Obs. Box', '', 'Prueba'),
(18, 'Nombre', 'COSTCO12345', 'FUNCIONA232'),
(19, 'Tipo Unidad', 'Comunicaciones Celulares', 'Prueba 2'),
(20, 'Modelo', 'Cd2', 'PVZ2'),
(21, 'Obs. Unidad', 'Prueba', 'Prueba2'),
(22, 'Obs. Box', 'Prueba', 'Prueba2'),
(25, 'Obs. Unidad', 'Prueba2', 'Prueba223232'),
(26, 'Nombre', 'FUNCIONA232', 'CREALOG2323'),
(27, 'Tipo Unidad', 'Prueba 2', 'Comunicaciones Celulares'),
(28, 'Modelo', 'PVZ2', 'CS'),
(29, 'Obs. Unidad', 'Prueba223232', 'CREALOG2323'),
(30, 'Obs. Box', 'Prueba2', 'CREALOG2323'),
(34, 'Nombre', 'PRUEBA12333', 'PRUEBA12334'),
(35, 'Tipo Unidad', 'Comunicaciones Celulares', 'Prueba 2'),
(36, 'Modelo', 'CS', 'PVZ2'),
(37, 'Obs. Unidad', '', 'qwert'),
(38, 'Obs. Box', '', 'qwert'),
(59, 'Modelo', 'cgfg', 'PVZ2'),
(77, 'Nombre', 'CREALOG2323', 'PRUEBAMODIF'),
(78, 'Tipo Unidad', 'Prueba 2', 'Comunicaciones Celulares'),
(79, 'Modelo', 'PVZ2', 'CS'),
(99, 'Obs. Unidad', '', 'Todo bien'),
(100, 'Obs. Box', '', 'Todo Bien');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `logs_traslados`
--

CREATE TABLE `logs_traslados` (
  `evento_id` int(11) NOT NULL,
  `bloque_anterior_id` int(11) DEFAULT NULL,
  `posicion_anterior` int(11) DEFAULT NULL,
  `bloque_nuevo_id` int(11) DEFAULT NULL,
  `posicion_nueva` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `logs_traslados`
--

INSERT INTO `logs_traslados` (`evento_id`, `bloque_anterior_id`, `posicion_anterior`, `bloque_nuevo_id`, `posicion_nueva`) VALUES
(1, NULL, NULL, 1, 25),
(2, NULL, NULL, 1, 24),
(3, NULL, NULL, 1, 23),
(4, NULL, NULL, 2, 25),
(5, 1, 23, 3, 25),
(6, 1, 24, 3, 24),
(7, 1, 24, 3, 24),
(8, 1, 25, 3, 23),
(9, NULL, NULL, 1, 25),
(42, NULL, NULL, 3, 22),
(82, NULL, NULL, 1, 25),
(89, NULL, NULL, 1, 25),
(95, NULL, NULL, 1, 25),
(108, NULL, NULL, 1, 24),
(109, 2, 25, 4, 25);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `modelos`
--

CREATE TABLE `modelos` (
  `id` int(11) NOT NULL,
  `nombre` varchar(50) NOT NULL,
  `tipo_unidad_id` int(11) NOT NULL,
  `estado_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `modelos`
--

INSERT INTO `modelos` (`id`, `nombre`, `tipo_unidad_id`, `estado_id`) VALUES
(1, 'CS', 1, 1),
(2, 'QWw', 1, 2),
(3, 'cgfg', 2, 1),
(4, 'PVZ2', 2, 1),
(5, 'Cd2', 1, 1),
(6, 'elimirar', 3, 2),
(7, 'Pruebaq', 3, 2);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `patios`
--

CREATE TABLE `patios` (
  `id` int(11) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `sucursal_id` int(11) NOT NULL,
  `estado_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `patios`
--

INSERT INTO `patios` (`id`, `nombre`, `sucursal_id`, `estado_id`) VALUES
(2, 'Patio 1', 1, 1),
(3, 'Patio1', 2, 2),
(4, 'Patio santo', 2, 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `pyp_auditoria`
--

CREATE TABLE `pyp_auditoria` (
  `contenedor_id` int(11) NOT NULL,
  `estatus_pyp` varchar(255) DEFAULT NULL,
  `informacion_adicional` text DEFAULT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  `usuario_id` int(11) NOT NULL,
  `estado_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `pyp_auditoria`
--

INSERT INTO `pyp_auditoria` (`contenedor_id`, `estatus_pyp`, `informacion_adicional`, `fecha_registro`, `usuario_id`, `estado_id`) VALUES
(2, 'Asignado', NULL, '2025-11-29 15:30:43', 5, 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `nombre` varchar(50) NOT NULL,
  `estado_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `roles`
--

INSERT INTO `roles` (`id`, `nombre`, `estado_id`) VALUES
(1, 'Super Admin', 1),
(2, 'Admin', 1),
(3, 'Usuario', 1),
(4, 'Mecánico', 1),
(5, 'Empresa Externa', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `sucursales`
--

CREATE TABLE `sucursales` (
  `id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `ubicacion` varchar(255) DEFAULT NULL,
  `estado_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `sucursales`
--

INSERT INTO `sucursales` (`id`, `nombre`, `ubicacion`, `estado_id`) VALUES
(1, 'Puerto Barrios', 'Izabal', 1),
(2, 'Santo Tomas', 'Izabal', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tipos_estatus`
--

CREATE TABLE `tipos_estatus` (
  `id` int(11) NOT NULL,
  `nombre` varchar(50) NOT NULL,
  `estado_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `tipos_estatus`
--

INSERT INTO `tipos_estatus` (`id`, `nombre`, `estado_id`) VALUES
(1, 'Unidad', 1),
(2, 'Estructura', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tipos_operacion`
--

CREATE TABLE `tipos_operacion` (
  `id` int(11) NOT NULL,
  `nombre` varchar(50) NOT NULL,
  `estado_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `tipos_operacion`
--

INSERT INTO `tipos_operacion` (`id`, `nombre`, `estado_id`) VALUES
(1, 'INGRESO', 1),
(2, 'TRASLADO', 1),
(3, 'MODIFICACION_ESTATUS', 1),
(4, 'MODIFICACION_DATOS', 1),
(5, 'RETIRO', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tipos_unidad`
--

CREATE TABLE `tipos_unidad` (
  `id` int(11) NOT NULL,
  `nombre` varchar(50) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `estado_id` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `tipos_unidad`
--

INSERT INTO `tipos_unidad` (`id`, `nombre`, `descripcion`, `estado_id`) VALUES
(1, 'Comunicaciones Celulares', 'Prueba', 1),
(2, 'Prueba 2', 'Prueaakda', 1),
(3, 'Eliminar prubea', 'Eliminar prubea1', 2);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `rol_id` int(11) NOT NULL,
  `sucursal_id` int(11) DEFAULT NULL,
  `estado_id` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `email`, `rol_id`, `sucursal_id`, `estado_id`, `created_at`, `updated_at`) VALUES
(1, 'Ionito', '$2y$10$VnGT/HVqnKnkHTssiqNvOOkj/CpDwn2HGZp1vlQJK/3hCaZZ/qUYO', 'ionirodas69@gmail.com', 1, 1, 1, '2025-11-06 01:53:40', '2025-11-28 21:35:39'),
(2, 'Pruebas', '$2y$10$qpzoUDer3bMMb5Plso07kOSiGs26gvAXmNOhK.ob5y3gNjHDnAsxi', 'prueba@gmail.com', 2, 2, 1, '2025-11-24 05:13:12', '2025-11-28 01:45:42'),
(3, 'user', '$2y$10$hXVqz9APgErY9Hk2YumxOe.sJGUVjlfk4ABKXQYE7E4qzz9Z1yk6i', 'user@gmail.com', 3, 1, 1, '2025-11-24 14:57:56', '2025-11-24 14:57:56'),
(4, 'mecanico', '$2y$10$DXmzrqw9Zr2bF.9nub3s7u8OstkrhoddIwqufFSWFD7AHZYy9oT8m', 'mec@gmail.com', 4, 1, 1, '2025-11-24 14:58:25', '2025-11-24 14:58:25'),
(5, 'PYP', '$2y$10$q73FqZbxz0eVqrPaSmhcL.MBpjJokArTi8H5F1C3EaHgti6lgEHCi', 'pyp@gmail.com', 5, 1, 1, '2025-11-24 14:58:45', '2025-11-24 14:58:45');

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `bloques`
--
ALTER TABLE `bloques`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `numero_patio_unico` (`numero`,`patio_id`),
  ADD KEY `fk_bloques_patio_id` (`patio_id`),
  ADD KEY `fk_bloques_estado` (`estado_id`);

--
-- Indices de la tabla `contenedores`
--
ALTER TABLE `contenedores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`),
  ADD KEY `fk_cont_bloque` (`bloque_id`),
  ADD KEY `fk_cont_tipo_unidad` (`tipo_unidad_id`),
  ADD KEY `fk_cont_modelo` (`modelo_id`),
  ADD KEY `fk_cont_estatus_unidad` (`estatus_unidad_id`),
  ADD KEY `fk_cont_estatus_box` (`estatus_box_id`),
  ADD KEY `fk_cont_estatus_ipsa` (`estatus_ipsa_id`),
  ADD KEY `fk_cont_estado` (`estado_id`);

--
-- Indices de la tabla `estados`
--
ALTER TABLE `estados`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `estatus_contenedores`
--
ALTER TABLE `estatus_contenedores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre_tipo_unico` (`nombre`,`tipo_estatus_id`),
  ADD KEY `fk_estatus_tipo` (`tipo_estatus_id`),
  ADD KEY `fk_estatus_contenedores_estado` (`estado_id`);

--
-- Indices de la tabla `estatus_ipsa`
--
ALTER TABLE `estatus_ipsa`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`),
  ADD KEY `fk_estatus_ipsa_estado` (`estado_id`);

--
-- Indices de la tabla `logs_estatus`
--
ALTER TABLE `logs_estatus`
  ADD PRIMARY KEY (`evento_id`),
  ADD KEY `fk_logs_estatus_tipo` (`tipo_estatus_id`);

--
-- Indices de la tabla `logs_eventos`
--
ALTER TABLE `logs_eventos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_evento_contenedor` (`contenedor_id`),
  ADD KEY `fk_evento_usuario` (`usuario_id`),
  ADD KEY `fk_evento_tipo` (`tipo_operacion_id`),
  ADD KEY `fk_evento_estado` (`estado_id`);

--
-- Indices de la tabla `logs_ipsa`
--
ALTER TABLE `logs_ipsa`
  ADD PRIMARY KEY (`evento_id`),
  ADD KEY `fk_ipsa_estatus_nuevo` (`estatus_nuevo_id`);

--
-- Indices de la tabla `logs_modificaciones`
--
ALTER TABLE `logs_modificaciones`
  ADD PRIMARY KEY (`evento_id`);

--
-- Indices de la tabla `logs_traslados`
--
ALTER TABLE `logs_traslados`
  ADD PRIMARY KEY (`evento_id`),
  ADD KEY `fk_traslado_bloque_anterior` (`bloque_anterior_id`),
  ADD KEY `fk_traslado_bloque_nuevo` (`bloque_nuevo_id`);

--
-- Indices de la tabla `modelos`
--
ALTER TABLE `modelos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre_tipo_unico` (`nombre`,`tipo_unidad_id`),
  ADD KEY `fk_modelos_tipo_unidad_id` (`tipo_unidad_id`),
  ADD KEY `fk_modelos_estado` (`estado_id`);

--
-- Indices de la tabla `patios`
--
ALTER TABLE `patios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre_sucursal_unico` (`nombre`,`sucursal_id`),
  ADD KEY `fk_patio_sucursal` (`sucursal_id`),
  ADD KEY `fk_patio_estado` (`estado_id`);

--
-- Indices de la tabla `pyp_auditoria`
--
ALTER TABLE `pyp_auditoria`
  ADD PRIMARY KEY (`contenedor_id`),
  ADD KEY `fk_pyp_usuario` (`usuario_id`),
  ADD KEY `fk_pyp_estado` (`estado_id`);

--
-- Indices de la tabla `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`),
  ADD KEY `fk_rol_estado` (`estado_id`);

--
-- Indices de la tabla `sucursales`
--
ALTER TABLE `sucursales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`),
  ADD KEY `fk_sucursal_estado` (`estado_id`);

--
-- Indices de la tabla `tipos_estatus`
--
ALTER TABLE `tipos_estatus`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`),
  ADD KEY `fk_tipos_estatus_estado` (`estado_id`);

--
-- Indices de la tabla `tipos_operacion`
--
ALTER TABLE `tipos_operacion`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`),
  ADD KEY `fk_tipos_operacion_estado` (`estado_id`);

--
-- Indices de la tabla `tipos_unidad`
--
ALTER TABLE `tipos_unidad`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`),
  ADD KEY `fk_tipos_unidad_estado` (`estado_id`);

--
-- Indices de la tabla `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `fk_user_rol` (`rol_id`),
  ADD KEY `fk_user_sucursal` (`sucursal_id`),
  ADD KEY `fk_user_estado` (`estado_id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `bloques`
--
ALTER TABLE `bloques`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de la tabla `contenedores`
--
ALTER TABLE `contenedores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT de la tabla `estados`
--
ALTER TABLE `estados`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `estatus_contenedores`
--
ALTER TABLE `estatus_contenedores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `estatus_ipsa`
--
ALTER TABLE `estatus_ipsa`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `logs_eventos`
--
ALTER TABLE `logs_eventos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=110;

--
-- AUTO_INCREMENT de la tabla `modelos`
--
ALTER TABLE `modelos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `patios`
--
ALTER TABLE `patios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de la tabla `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `sucursales`
--
ALTER TABLE `sucursales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `tipos_estatus`
--
ALTER TABLE `tipos_estatus`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `tipos_operacion`
--
ALTER TABLE `tipos_operacion`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `tipos_unidad`
--
ALTER TABLE `tipos_unidad`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `bloques`
--
ALTER TABLE `bloques`
  ADD CONSTRAINT `fk_bloques_estado` FOREIGN KEY (`estado_id`) REFERENCES `estados` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_bloques_patio_id` FOREIGN KEY (`patio_id`) REFERENCES `patios` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `contenedores`
--
ALTER TABLE `contenedores`
  ADD CONSTRAINT `fk_cont_bloque` FOREIGN KEY (`bloque_id`) REFERENCES `bloques` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cont_estado` FOREIGN KEY (`estado_id`) REFERENCES `estados` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cont_estatus_box` FOREIGN KEY (`estatus_box_id`) REFERENCES `estatus_contenedores` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cont_estatus_ipsa` FOREIGN KEY (`estatus_ipsa_id`) REFERENCES `estatus_ipsa` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cont_estatus_unidad` FOREIGN KEY (`estatus_unidad_id`) REFERENCES `estatus_contenedores` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cont_modelo` FOREIGN KEY (`modelo_id`) REFERENCES `modelos` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cont_tipo_unidad` FOREIGN KEY (`tipo_unidad_id`) REFERENCES `tipos_unidad` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `estatus_contenedores`
--
ALTER TABLE `estatus_contenedores`
  ADD CONSTRAINT `fk_estatus_contenedores_estado` FOREIGN KEY (`estado_id`) REFERENCES `estados` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_estatus_tipo` FOREIGN KEY (`tipo_estatus_id`) REFERENCES `tipos_estatus` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `estatus_ipsa`
--
ALTER TABLE `estatus_ipsa`
  ADD CONSTRAINT `fk_estatus_ipsa_estado` FOREIGN KEY (`estado_id`) REFERENCES `estados` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `logs_estatus`
--
ALTER TABLE `logs_estatus`
  ADD CONSTRAINT `fk_estatus_evento` FOREIGN KEY (`evento_id`) REFERENCES `logs_eventos` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_logs_estatus_tipo` FOREIGN KEY (`tipo_estatus_id`) REFERENCES `tipos_estatus` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `logs_eventos`
--
ALTER TABLE `logs_eventos`
  ADD CONSTRAINT `fk_evento_contenedor` FOREIGN KEY (`contenedor_id`) REFERENCES `contenedores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_evento_estado` FOREIGN KEY (`estado_id`) REFERENCES `estados` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_evento_tipo` FOREIGN KEY (`tipo_operacion_id`) REFERENCES `tipos_operacion` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_evento_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `logs_ipsa`
--
ALTER TABLE `logs_ipsa`
  ADD CONSTRAINT `fk_ipsa_estatus_nuevo` FOREIGN KEY (`estatus_nuevo_id`) REFERENCES `estatus_ipsa` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ipsa_evento` FOREIGN KEY (`evento_id`) REFERENCES `logs_eventos` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `logs_modificaciones`
--
ALTER TABLE `logs_modificaciones`
  ADD CONSTRAINT `fk_mod_evento` FOREIGN KEY (`evento_id`) REFERENCES `logs_eventos` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `logs_traslados`
--
ALTER TABLE `logs_traslados`
  ADD CONSTRAINT `fk_traslado_bloque_anterior` FOREIGN KEY (`bloque_anterior_id`) REFERENCES `bloques` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_traslado_bloque_nuevo` FOREIGN KEY (`bloque_nuevo_id`) REFERENCES `bloques` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_traslado_evento` FOREIGN KEY (`evento_id`) REFERENCES `logs_eventos` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `modelos`
--
ALTER TABLE `modelos`
  ADD CONSTRAINT `fk_modelos_estado` FOREIGN KEY (`estado_id`) REFERENCES `estados` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_modelos_tipo_unidad_id` FOREIGN KEY (`tipo_unidad_id`) REFERENCES `tipos_unidad` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `patios`
--
ALTER TABLE `patios`
  ADD CONSTRAINT `fk_patio_estado` FOREIGN KEY (`estado_id`) REFERENCES `estados` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_patio_sucursal` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `pyp_auditoria`
--
ALTER TABLE `pyp_auditoria`
  ADD CONSTRAINT `fk_pyp_contenedor` FOREIGN KEY (`contenedor_id`) REFERENCES `contenedores` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pyp_estado` FOREIGN KEY (`estado_id`) REFERENCES `estados` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pyp_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `roles`
--
ALTER TABLE `roles`
  ADD CONSTRAINT `fk_rol_estado` FOREIGN KEY (`estado_id`) REFERENCES `estados` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `sucursales`
--
ALTER TABLE `sucursales`
  ADD CONSTRAINT `fk_sucursal_estado` FOREIGN KEY (`estado_id`) REFERENCES `estados` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `tipos_estatus`
--
ALTER TABLE `tipos_estatus`
  ADD CONSTRAINT `fk_tipos_estatus_estado` FOREIGN KEY (`estado_id`) REFERENCES `estados` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `tipos_operacion`
--
ALTER TABLE `tipos_operacion`
  ADD CONSTRAINT `fk_tipos_operacion_estado` FOREIGN KEY (`estado_id`) REFERENCES `estados` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `tipos_unidad`
--
ALTER TABLE `tipos_unidad`
  ADD CONSTRAINT `fk_tipos_unidad_estado` FOREIGN KEY (`estado_id`) REFERENCES `estados` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_user_estado` FOREIGN KEY (`estado_id`) REFERENCES `estados` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_user_rol` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_user_sucursal` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursales` (`id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
