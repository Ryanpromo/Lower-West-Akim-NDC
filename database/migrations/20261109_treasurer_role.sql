ALTER TABLE users
  MODIFY COLUMN role ENUM('chairman', 'secretary', 'deputy_secretary', 'treasurer', 'executive') NOT NULL;
