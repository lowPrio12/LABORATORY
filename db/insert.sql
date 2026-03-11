START TRANSACTION;

-- 1️⃣ Insert 1 user with a simple password
INSERT INTO
    users (username, password, user_role)
VALUES
    (
        'admin',
        '$2y$10$HNfhClczEWBxcFuJwP53iu2Y75Tba7IEtmX8vX.1tp0dZ5EVt9CbO',
        'admin'
    );

-- password = password123
-- 2️⃣ Insert 1 egg batch linked to the user (user_id = 1)
INSERT INTO
    egg (
        user_id,
        total_egg,
        status,
        date_started_incubation,
        balut_count,
        failed_count,
        chick_count,
        batch_number
    )
VALUES
    (1, 100, 'incubating', '2026-03-10', 20, 5, 10, 1);

-- 3️⃣ Insert 1 activity log for the user
INSERT INTO
    user_activity_logs (user_id, action)
VALUES
    (1, 'User logged in');

COMMIT;