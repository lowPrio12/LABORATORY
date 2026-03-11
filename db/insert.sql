START TRANSACTION;

-- 1️⃣ Insert 1 user with a simple password
INSERT INTO
    users (username, password, user_role)
VALUES
    (
        'admin',
        '$2y$10$HNfhClczEWBxcFuJwP53iu2Y75Tba7IEtmX8vX.1tp0dZ5EVt9CbO',
        'admin'
    ),
    (
        'user',
        '$2y$10$HNfhClczEWBxcFuJwP53iu2Y75Tba7IEtmX8vX.1tp0dZ5EVt9CbO',
        'user'
    );

-- 3️⃣ Insert 1 activity log for the user
INSERT INTO
    user_activity_logs (user_id, action)
VALUES
    (1, 'User logged in');

COMMIT;