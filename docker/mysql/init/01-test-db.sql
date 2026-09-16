-- Dedicated database for the test suite (see tests/bootstrap.php + .env.test).
-- Runs only once, on first MySQL initialization (empty data volume).
--
-- The `taskflow` user and its password are created by the MySQL entrypoint from the
-- MYSQL_USER / MYSQL_PASSWORD environment variables (docker-compose.yml) *before* these
-- init scripts run, so only the database and the grant belong here — no CREATE USER, to
-- keep a single source of truth for the credentials.
CREATE DATABASE IF NOT EXISTS taskflow_test
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_0900_ai_ci;

GRANT ALL PRIVILEGES ON taskflow_test.* TO 'taskflow'@'%';
FLUSH PRIVILEGES;
