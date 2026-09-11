-- Seed awal (fresh start). Password default — GANTI setelah login pertama.
-- admin / admin123, proktor / proktor123
INSERT IGNORE INTO users (username, pass_hash, role) VALUES
  ('admin', '$2y$12$NW1eTzT5A5yLHAxrK.ft1OdVgwSMA8EyI09k4LLkOiHbGa1ztsqmC', 'admin'),
  ('proktor', '$2y$12$e2e1ylPJqB722fsbMMIOQ.kj4elalooASM8mBPyBa27ztIM.Xx08S', 'proktor');
