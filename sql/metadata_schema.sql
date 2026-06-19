-- dblib metadata schema.
-- The app's own bookkeeping — entirely separate from student sandboxes.
-- Applied by `php cli/migrate.php`.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS classes (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120) NOT NULL,
    teacher_id  INT UNSIGNED NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- App-owned accounts. Shaped so it could later defer to a shared suite-wide
-- auth service without a rewrite.
--
-- Login identity differs by role:
--   * students log in with student_id (no email required)
--   * teachers log in with email
-- Both columns are nullable + unique so each role fills only its own; NULLs are
-- allowed to repeat in MySQL unique indexes, so unused columns don't collide.
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email         VARCHAR(190) NULL,
    student_id    VARCHAR(32)  NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('teacher','student') NOT NULL,
    display_name  VARCHAR(120) NULL,
    class_id      INT UNSIGNED NULL,            -- one student -> one class
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_student_id (student_id),
    KEY idx_users_class (class_id),
    CONSTRAINT fk_users_class FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE classes
    ADD CONSTRAINT fk_classes_teacher FOREIGN KEY (teacher_id) REFERENCES users (id) ON DELETE SET NULL;

-- One scoped MySQL sandbox per student. cred_ciphertext holds the AES-256-GCM
-- encrypted MySQL password; the key lives outside the DB and web root.
CREATE TABLE IF NOT EXISTS student_sandboxes (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    db_name         VARCHAR(80)  NOT NULL,
    db_user         VARCHAR(32)  NOT NULL,
    db_host         VARCHAR(60)  NOT NULL DEFAULT 'localhost',
    cred_ciphertext TEXT         NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sandbox_user (user_id),
    UNIQUE KEY uq_sandbox_db (db_name),
    CONSTRAINT fk_sandbox_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed datasets a teacher can push to a class (schema + sample data).
CREATE TABLE IF NOT EXISTS seeds (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    class_id       INT UNSIGNED NOT NULL,
    name           VARCHAR(120) NOT NULL,
    sql_script     LONGTEXT NOT NULL,
    apply_on_enrol TINYINT(1) NOT NULL DEFAULT 0,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_seeds_class (class_id),
    CONSTRAINT fk_seeds_class FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
