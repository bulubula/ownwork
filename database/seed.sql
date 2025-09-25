INSERT INTO users (login_id, name, role, birthdate, password_hash)
VALUES
('admin01', '系统管理员', '管理员', '1980-01-01', '$2y$12$SAI5OhUuDWCFTT9OawjK/OdJLyzEUbT6IxBrdm6kaSNlpYoQri4gG'),
('u1001', '张三', '普通用户', '1990-05-05', '$2y$12$D1l.MLTLwNLMM6fG70rFce..bftPfflxeZjbYuNJShIDtZXx08ERW'),
('u1002', '李四', '中层', '1985-02-10', '$2y$12$b.XzGKuySieke5u1Y5uZnOby4vU/F2noHFgMKey/qySpKIPUsfOW6'),
('u1003', '王五', '普通用户', '1992-08-20', '$2y$12$vcph/7dpneAh9EkABdIEyOlueQ8K5ZLOO7Y1Kj3Ox2wse28OsVWiO'),
('u1004', '赵六', '中层', '1988-09-12', '$2y$12$Q4G8/tPmBMQJyMsPf4Q.r.JBL2mlhN.YFEkxMGHlJL/EwmCZkgX8K'),
('u1005', '孙七', '普通用户', '1995-11-30', '$2y$12$c1NteV7zLeZbFxQ/FVxEWevTSs7Cp62dViTn0UW7HTxJPQP1fY3.K');

INSERT INTO projects (name, category, level, total_amount, manager_id)
VALUES
('营销提升项目', '市场', '公司级', 100000, 2),
('流程优化项目', '运营', '部门级', 50000, 3);

INSERT INTO allocations (project_id, user_id, amount)
VALUES
(1, 2, 40000),
(1, 3, 20000),
(1, 4, 20000),
(1, 5, 20000),
(2, 3, 20000),
(2, 4, 15000),
(2, 6, 15000);
