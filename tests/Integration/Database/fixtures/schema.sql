-- Shared schema for integration tests that need real tables to query.
--
-- Loaded once per test class by TransactionalIntegrationTestCase, outside the
-- per-test transaction, because DDL commits implicitly on MySQL.
--
-- Tables are dropped and recreated so a run never depends on what a previous
-- run left behind. Rows are not seeded here: tests insert what they need
-- inside the fixture transaction, which is rolled back afterwards.
--
-- posts.user_id carries an index but no foreign key: a constraint would force
-- every test touching posts to insert a user first, which is unrelated to what
-- those tests assert.
--
-- COLLATE is pinned rather than left to the server default, which differs
-- between the two engines the suite runs against (MySQL 8 defaults to
-- utf8mb4_0900_ai_ci, MariaDB 10.11 to utf8mb4_general_ci). Those disagree on
-- comparisons such as 'straße' = 'strasse', which would make a WHERE, ORDER BY
-- or unique-key test pass on one engine and fail on the other.
--
-- Parsing constraint: statements are split on semicolons, so a statement must
-- not contain a semicolon inside a string literal. Comments may use -- or #
-- line form or /* */ block form; all three are stripped before splitting.

DROP TABLE IF EXISTS posts;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    score INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    UNIQUE KEY users_email_unique (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE posts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    published TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY posts_user_id_index (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
