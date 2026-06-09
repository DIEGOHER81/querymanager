-- ============================================================
-- PROCEDIMIENTO ALMACENADO GENÉRICO PARA EJECUCIÓN VIA JSON
-- Motor: MySQL 5.7+ / MariaDB 10.2+
-- ============================================================
--
-- DESCRIPCIÓN:
--   Este SP recibe un JSON con la consulta SQL y parámetros,
--   la ejecuta dinámicamente y retorna los resultados.
--
-- FORMATO DEL JSON DE ENTRADA:
--   {
--     "query": "SELECT * FROM tabla WHERE campo = ? AND otro = ?",
--     "params": ["valor1", "valor2"],
--     "limit": 10
--   }
--
-- FORMATO DEL JSON DE RESPUESTA (éxito):
--   Se retorna un result set con las columnas originales de la consulta.
--   En caso de necesitar JSON puro, usar JSON_ARRAYAGG / JSON_OBJECT en la query.
--
-- EJEMPLO DE USO:
--   CALL sp_ExecuteJsonQuery('{"query":"SELECT * FROM clientes WHERE ciudad = ?","params":["Bogotá"],"limit":10}');
--
-- INSTALACIÓN:
--   Ejecutar este script en la base de datos destino.
-- ============================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_ExecuteJsonQuery$$

CREATE PROCEDURE sp_ExecuteJsonQuery(
    IN p_json_input TEXT
)
BEGIN
    DECLARE v_query TEXT;
    DECLARE v_limit INT DEFAULT 100;
    DECLARE v_param_count INT DEFAULT 0;
    DECLARE v_final_query TEXT;
    DECLARE v_i INT DEFAULT 0;
    DECLARE v_param_value TEXT;

    -- Extraer valores del JSON
    SET v_query = JSON_UNQUOTE(JSON_EXTRACT(p_json_input, '$.query'));

    -- Extraer limit si existe
    IF JSON_EXTRACT(p_json_input, '$.limit') IS NOT NULL THEN
        SET v_limit = JSON_EXTRACT(p_json_input, '$.limit');
    END IF;

    -- Validar que la query no sea nula
    IF v_query IS NULL OR v_query = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Error: La consulta SQL es requerida en el JSON (campo "query")';
    END IF;

    -- Contar parámetros
    IF JSON_EXTRACT(p_json_input, '$.params') IS NOT NULL THEN
        SET v_param_count = JSON_LENGTH(JSON_EXTRACT(p_json_input, '$.params'));
    END IF;

    -- Reemplazar placeholders (?) con valores
    SET v_final_query = v_query;
    SET v_i = 0;

    WHILE v_i < v_param_count DO
        SET v_param_value = JSON_UNQUOTE(JSON_EXTRACT(p_json_input, CONCAT('$.params[', v_i, ']')));

        -- Escapar comillas simples en el valor
        SET v_param_value = REPLACE(v_param_value, "'", "''");

        -- Reemplazar el primer ? encontrado
        SET v_final_query = CONCAT(
            SUBSTRING(v_final_query, 1, LOCATE('?', v_final_query) - 1),
            "'", v_param_value, "'",
            SUBSTRING(v_final_query, LOCATE('?', v_final_query) + 1)
        );

        SET v_i = v_i + 1;
    END WHILE;

    -- Agregar LIMIT si la consulta es un SELECT y no tiene LIMIT
    IF UPPER(TRIM(v_final_query)) LIKE 'SELECT%' AND UPPER(v_final_query) NOT LIKE '%LIMIT%' THEN
        SET v_final_query = CONCAT(v_final_query, ' LIMIT ', v_limit);
    END IF;

    -- Ejecutar la consulta dinámica
    SET @sql_exec = v_final_query;
    PREPARE stmt FROM @sql_exec;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;

END$$

DELIMITER ;

-- ============================================================
-- EJEMPLO DE USO Y PRUEBAS
-- ============================================================
--
-- 1. SELECT simple:
--    CALL sp_ExecuteJsonQuery('{"query":"SELECT NOW() as fecha_actual","params":[],"limit":1}');
--
-- 2. SELECT con parámetros:
--    CALL sp_ExecuteJsonQuery('{"query":"SELECT * FROM usuarios WHERE estado = ? AND rol = ?","params":["activo","admin"],"limit":10}');
--
-- 3. SELECT con limit personalizado:
--    CALL sp_ExecuteJsonQuery('{"query":"SELECT * FROM productos","params":[],"limit":5}');
--
-- 4. INSERT (sin limit):
--    CALL sp_ExecuteJsonQuery('{"query":"INSERT INTO logs (accion, detalle) VALUES (?, ?)","params":["login","Usuario ingresó al sistema"]}');
