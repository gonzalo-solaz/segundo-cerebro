-- Verificación en dos pasos (código de 6 cifras de una app como Google
-- Authenticator), obligatoria para los administradores desde que dan paso a
-- finanzas sin otra contraseña (3/10/2026). Ver includes/dos-pasos.php.
--   totp_secreto       la semilla en base32; NULL = no activada
--   totp_ultimo_paso   el último intervalo de 30 s aceptado: un código ya
--                      usado no vale dos veces
--   totp_recuperacion  JSON con los hashes de los códigos de un solo uso
ALTER TABLE usuarios ADD COLUMN totp_secreto VARCHAR(64) NULL;
ALTER TABLE usuarios ADD COLUMN totp_ultimo_paso INT NULL;
ALTER TABLE usuarios ADD COLUMN totp_recuperacion TEXT NULL;
