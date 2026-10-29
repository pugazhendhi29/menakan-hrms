-- News thread messages can hold formatted text, tables and pictures pasted from Word, Excel or a web page
-- (stored as cleaned HTML; pictures are files in storage/news_images). TEXT (64 KB) is too small for pasted tables.
SET NAMES utf8mb4;

ALTER TABLE news_thread_messages MODIFY body MEDIUMTEXT NOT NULL COMMENT 'Plain text, or cleaned HTML from the rich editor (starts with a tag)';
