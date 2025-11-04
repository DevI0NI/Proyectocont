-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 29-08-2025 a las 16:17:02
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
-- Base de datos: `proyectocont`
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
  `capacidad_actual` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `bloques`
--

INSERT INTO `bloques` (`id`, `numero`, `patio_id`, `capacidad_maxima`, `capacidad_actual`) VALUES
(48, 1, 1, 25, 6),
(49, 1, 2, 25, 3),
(50, 2, 1, 25, 1),
(51, 2, 2, 25, 0);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `contenedores`
--

CREATE TABLE `contenedores` (
  `id` int(11) NOT NULL,
  `posicion` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `estatus_unidad` varchar(50) NOT NULL,
  `observaciones_unidad` text DEFAULT NULL,
  `estatus_box` varchar(50) NOT NULL,
  `observaciones_box` text DEFAULT NULL,
  `estatus_ipsa` varchar(50) DEFAULT 'Pendiente',
  `bloque_id` int(11) NOT NULL,
  `tipo_unidad_id` int(11) NOT NULL,
  `modelo_id` int(11) NOT NULL,
  `fecha_ingreso` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `contenedores`
--

INSERT INTO `contenedores` (`id`, `posicion`, `nombre`, `estatus_unidad`, `observaciones_unidad`, `estatus_box`, `observaciones_box`, `estatus_ipsa`, `bloque_id`, `tipo_unidad_id`, `modelo_id`, `fecha_ingreso`) VALUES
(9, 25, 'SUDU2094034523', 'OK', '', 'OK', '', 'Disponible', 48, 3, 6, '2025-12-20 16:28:16'),
(10, 24, 'GPT12453541266', 'DMG', '', 'DMG', '', 'OOS', 48, 3, 5, '2025-05-04 16:28:33'),
(11, 23, 'GPT124535412', 'Pendiente PTI', '', 'Pendiente Revisión', '', 'Pendiente', 48, 2, 2, '2025-05-21 16:28:50'),
(12, 25, 'ACH84636385', 'OK', '', 'OK', '', 'Disponible', 49, 2, 2, '2025-02-20 10:03:41'),
(13, 25, 'SUDU12345678', 'OK', '', 'OK', '', 'Disponible', 50, 4, 11, '2025-06-19 11:21:29'),
(14, 22, 'SUDU123456785', 'DMG', '', 'OK', '', 'OOS', 48, 2, 2, '2025-05-20 13:39:36'),
(15, 21, 'SUDU2384744', 'OK', '', 'OK', '', 'Disponible', 48, 3, 6, '2025-07-03 11:47:10');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `estatus_contenedores`
--

CREATE TABLE `estatus_contenedores` (
  `id` int(11) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `tipo` enum('Unidad','Estructura') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `estatus_contenedores`
--

INSERT INTO `estatus_contenedores` (`id`, `nombre`, `tipo`) VALUES
(1, 'OK', 'Unidad'),
(2, 'DMG', 'Unidad'),
(3, 'Pendiente PTI', 'Unidad'),
(4, 'OK', 'Estructura'),
(5, 'DMG Reparación Mínima', 'Estructura'),
(6, 'DMG', 'Estructura'),
(7, 'Pendiente Revisión', 'Estructura');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `modelos`
--

CREATE TABLE `modelos` (
  `id` int(11) NOT NULL,
  `nombre` varchar(50) NOT NULL,
  `tipo_unidad_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `modelos`
--

INSERT INTO `modelos` (`id`, `nombre`, `tipo_unidad_id`) VALUES
(7, 'AS', 3),
(1, 'cgfg', 1),
(2, 'CS', 2),
(3, 'FQ', 2),
(13, 'QQW', 4),
(5, 'QW', 3),
(11, 'VVC', 4),
(12, 'VVW', 4),
(6, 'ZZ', 3),
(4, 'ZZZ', 2);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `patios`
--

CREATE TABLE `patios` (
  `id` int(11) NOT NULL,
  `nombre` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `patios`
--

INSERT INTO `patios` (`id`, `nombre`) VALUES
(1, 'Patio 1'),
(2, 'Patio 2');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tipos_unidad`
--

CREATE TABLE `tipos_unidad` (
  `id` int(11) NOT NULL,
  `nombre` varchar(50) NOT NULL,
  `descripcion` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `tipos_unidad`
--

INSERT INTO `tipos_unidad` (`id`, `nombre`, `descripcion`) VALUES
(1, 'Comunicaciones lk', 'rgee'),
(2, 'Comunicaciones Celulares', ''),
(3, 'Acumuladores Iberia', ''),
(4, 'Ejemplo', '');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `role` enum('admin','employee','programmer') NOT NULL DEFAULT 'employee'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `email`, `created_at`, `updated_at`, `role`) VALUES
(1, 'IPROPORTSA', '$2y$10$GefqqDbCRL0Zb0uM4Qqc9.4k4jEm4CJ0rTn0Xd1kwiSs7YD16S6Bm', 'asistencia@iproportsa.net', '2025-03-11 19:43:59', '2025-03-26 16:00:25', 'admin'),
(2, 'Ioni', '$2y$10$RmbQ9dvajSy6EAs2hWoMd.9Qq2Lj3RH4bCBMOyb8YYmNdGWHeljY2', 'ionirodas69@gmail.com', '2025-03-25 17:46:06', '2025-03-25 17:46:06', 'programmer');

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `bloques`
--
ALTER TABLE `bloques`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `numero` (`numero`,`patio_id`),
  ADD KEY `patio_id` (`patio_id`);

--
-- Indices de la tabla `contenedores`
--
ALTER TABLE `contenedores`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_contenedores_bloque_id` (`bloque_id`),
  ADD KEY `fk_contenedores_modelo_id` (`modelo_id`);

--
-- Indices de la tabla `estatus_contenedores`
--
ALTER TABLE `estatus_contenedores`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `modelos`
--
ALTER TABLE `modelos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`,`tipo_unidad_id`),
  ADD KEY `tipo_unidad_id` (`tipo_unidad_id`);

--
-- Indices de la tabla `patios`
--
ALTER TABLE `patios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `tipos_unidad`
--
ALTER TABLE `tipos_unidad`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `bloques`
--
ALTER TABLE `bloques`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;

--
-- AUTO_INCREMENT de la tabla `contenedores`
--
ALTER TABLE `contenedores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT de la tabla `estatus_contenedores`
--
ALTER TABLE `estatus_contenedores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `modelos`
--
ALTER TABLE `modelos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT de la tabla `patios`
--
ALTER TABLE `patios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT de la tabla `tipos_unidad`
--
ALTER TABLE `tipos_unidad`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de la tabla `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `bloques`
--
ALTER TABLE `bloques`
  ADD CONSTRAINT `bloques_ibfk_1` FOREIGN KEY (`patio_id`) REFERENCES `patios` (`id`),
  ADD CONSTRAINT `fk_bloques_patio_id` FOREIGN KEY (`patio_id`) REFERENCES `patios` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `contenedores`
--
ALTER TABLE `contenedores`
  ADD CONSTRAINT `fk_contenedores_bloque_id` FOREIGN KEY (`bloque_id`) REFERENCES `bloques` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_contenedores_modelo_id` FOREIGN KEY (`modelo_id`) REFERENCES `modelos` (`id`) ON UPDATE CASCADE;

--
-- Filtros para la tabla `modelos`
--
ALTER TABLE `modelos`
  ADD CONSTRAINT `fk_modelos_tipo_unidad_id` FOREIGN KEY (`tipo_unidad_id`) REFERENCES `tipos_unidad` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `modelos_ibfk_1` FOREIGN KEY (`tipo_unidad_id`) REFERENCES `tipos_unidad` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
