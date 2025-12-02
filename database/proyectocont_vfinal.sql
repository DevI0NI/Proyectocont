-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 02-12-2025 a las 03:15:45
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

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `logs_ipsa`
--

CREATE TABLE `logs_ipsa` (
  `evento_id` int(11) NOT NULL,
  `estatus_anterior_id` int(11) DEFAULT NULL,
  `estatus_nuevo_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
(1, 'Puerto Barrios', 'Izabal', 1);

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
(1, 'Ioni', '$2y$10$Puw./ZkQSrUy.4UedWBgpOt549hoI4fryoXYLDX/WDXJDMTAgyaBK', 'ionirodas@gmail.com', 1, 1, 1, '2025-12-02 02:11:32', '2025-12-02 02:11:32'),
(2, 'Admin', '$2y$10$NapYxoDbOIZLtECVkRP1/.MBRQ92pFEbO/2fcpwGYFPPmsnzZTx6u', 'admin@gmail.com', 2, 1, 1, '2025-12-02 02:13:33', '2025-12-02 02:13:33'),
(3, 'Usuario', '$2y$10$D2tfgW/oNSV2GxbkK98IyuG7OX2NwMOy/zms2rcmEVHyDOg44xCEC', 'user@gmail.com', 3, 1, 1, '2025-12-02 02:14:09', '2025-12-02 02:14:09'),
(4, 'Mecanico', '$2y$10$84k7RTpFLqBosQbO/V8SPOH8fnP8i8rWZ0OQHV3frE9onj.fJbcCG', 'mec@gmail.com', 4, 1, 1, '2025-12-02 02:14:32', '2025-12-02 02:14:32'),
(5, 'PYP', '$2y$10$./vsKRw5Dzi1V.GFUhaSvONr5PMvRA1GJs5M9UXzAeRyE9CAI71O2', 'pyp@gmail.com', 5, 1, 1, '2025-12-02 02:14:50', '2025-12-02 02:14:50');

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `contenedores`
--
ALTER TABLE `contenedores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `modelos`
--
ALTER TABLE `modelos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `patios`
--
ALTER TABLE `patios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `sucursales`
--
ALTER TABLE `sucursales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

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
