-- ============================================================
-- PROCEDIMIENTO ALMACENADO GENÉRICO PARA EJECUCIÓN VIA JSON
-- Motor: SQL Server 2016+
-- ============================================================
--
-- DESCRIPCIÓN:
--   Este SP recibe un JSON con la consulta SQL y parámetros,
--   la ejecuta dinámicamente y retorna los resultados.
--
-- FORMATO DEL JSON DE ENTRADA:
--   {
--     "query": "SELECT * FROM tabla WHERE campo = @p0 AND otro = @p1",
--     "params": ["valor1", "valor2"],
--     "limit": 10
--   }
--
-- NOTA: Para SQL Server los placeholders son @p0, @p1, @p2...
--       (Si se envían con ? se convierten automáticamente)
--
-- FORMATO DEL JSON DE RESPUESTA:
--   Se retorna un result set estándar con las columnas de la consulta.
--
-- EJEMPLO DE USO:
--   EXEC sp_ExecuteJsonQuery @JsonInput = '{"query":"SELECT * FROM clientes WHERE ciudad = @p0","params":["Bogotá"],"limit":10}';
--
-- INSTALACIÓN:
--   Ejecutar este script en la base de datos destino.
-- ============================================================

IF OBJECT_ID('dbo.sp_ExecuteJsonQuery', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_ExecuteJsonQuery;
GO

CREATE PROCEDURE dbo.sp_ExecuteJsonQuery
    @JsonInput NVARCHAR(MAX)
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Query NVARCHAR(MAX);
    DECLARE @Limit INT = 100;
    DECLARE @ParamCount INT = 0;
    DECLARE @FinalQuery NVARCHAR(MAX);
    DECLARE @i INT = 0;
    DECLARE @ParamValue NVARCHAR(MAX);
    DECLARE @ParamName NVARCHAR(20);
    DECLARE @ParamDefs NVARCHAR(MAX) = N'';
    DECLARE @ExecParams NVARCHAR(MAX) = N'';

    -- Validar JSON
    IF ISJSON(@JsonInput) = 0
    BEGIN
        RAISERROR('Error: El parámetro de entrada no es un JSON válido', 16, 1);
        RETURN;
    END

    -- Extraer valores del JSON
    SET @Query = JSON_VALUE(@JsonInput, '$.query');

    -- Extraer limit si existe
    IF JSON_VALUE(@JsonInput, '$.limit') IS NOT NULL
        SET @Limit = CAST(JSON_VALUE(@JsonInput, '$.limit') AS INT);

    -- Validar que la query no sea nula
    IF @Query IS NULL OR @Query = ''
    BEGIN
        RAISERROR('Error: La consulta SQL es requerida en el JSON (campo "query")', 16, 1);
        RETURN;
    END

    -- Contar parámetros
    SELECT @ParamCount = COUNT(*)
    FROM OPENJSON(@JsonInput, '$.params');

    -- Construir la query con parámetros
    SET @FinalQuery = @Query;

    -- Convertir placeholders ? a @p0, @p1... si es necesario
    SET @i = 0;
    WHILE @i < @ParamCount AND CHARINDEX('?', @FinalQuery) > 0
    BEGIN
        SET @FinalQuery = STUFF(@FinalQuery, CHARINDEX('?', @FinalQuery), 1, '@p' + CAST(@i AS NVARCHAR));
        SET @i = @i + 1;
    END

    -- Agregar TOP si es SELECT y no tiene TOP
    IF UPPER(LTRIM(@FinalQuery)) LIKE 'SELECT%'
       AND UPPER(@FinalQuery) NOT LIKE '%TOP%'
       AND UPPER(@FinalQuery) NOT LIKE '%OFFSET%FETCH%'
    BEGIN
        SET @FinalQuery = STUFF(@FinalQuery, CHARINDEX('SELECT', UPPER(@FinalQuery)) + 6, 0, ' TOP ' + CAST(@Limit AS NVARCHAR) + ' ');
    END

    -- Construir parámetros dinámicos con sp_executesql
    IF @ParamCount > 0
    BEGIN
        -- Declarar variables dinámicas para cada parámetro
        DECLARE @DynSQL NVARCHAR(MAX) = N'';
        DECLARE @ParamDefinitions NVARCHAR(MAX) = N'';

        SET @i = 0;
        WHILE @i < @ParamCount
        BEGIN
            SET @ParamValue = JSON_VALUE(@JsonInput, '$.params[' + CAST(@i AS NVARCHAR) + ']');
            SET @ParamName = '@p' + CAST(@i AS NVARCHAR);

            -- Reemplazar @pN con el valor directamente (escaped)
            SET @ParamValue = REPLACE(@ParamValue, '''', '''''');
            SET @FinalQuery = REPLACE(@FinalQuery, @ParamName, '''' + @ParamValue + '''');

            SET @i = @i + 1;
        END
    END

    -- Ejecutar la consulta
    BEGIN TRY
        EXEC sp_executesql @FinalQuery;
    END TRY
    BEGIN CATCH
        DECLARE @ErrorMessage NVARCHAR(4000) = ERROR_MESSAGE();
        DECLARE @ErrorSeverity INT = ERROR_SEVERITY();
        DECLARE @ErrorState INT = ERROR_STATE();
        DECLARE @ErrorLine INT = ERROR_LINE();

        DECLARE @FullError NVARCHAR(MAX) =
            'Error al ejecutar la consulta: ' + @ErrorMessage +
            ' (Línea: ' + CAST(@ErrorLine AS NVARCHAR) +
            ', Severidad: ' + CAST(@ErrorSeverity AS NVARCHAR) + ')';

        RAISERROR(@FullError, 16, 1);
    END CATCH
END
GO

-- ============================================================
-- EJEMPLO DE USO Y PRUEBAS
-- ============================================================
--
-- 1. SELECT simple:
--    EXEC sp_ExecuteJsonQuery @JsonInput = '{"query":"SELECT GETDATE() as fecha_actual","params":[],"limit":1}';
--
-- 2. SELECT con parámetros (usando ?):
--    EXEC sp_ExecuteJsonQuery @JsonInput = '{"query":"SELECT * FROM usuarios WHERE estado = ? AND rol = ?","params":["activo","admin"],"limit":10}';
--
-- 3. SELECT con parámetros (usando @pN):
--    EXEC sp_ExecuteJsonQuery @JsonInput = '{"query":"SELECT * FROM usuarios WHERE estado = @p0 AND rol = @p1","params":["activo","admin"],"limit":10}';
--
-- 4. INSERT:
--    EXEC sp_ExecuteJsonQuery @JsonInput = '{"query":"INSERT INTO logs (accion, detalle) VALUES (?, ?)","params":["login","Usuario ingresó al sistema"]}';
