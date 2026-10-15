-- Record of the email sent to the employee when a P60 is uploaded.
ALTER TABLE p60_documents
  ADD COLUMN emailed_to  VARCHAR(190) NULL AFTER deleted_reason,
  ADD COLUMN emailed_at  DATETIME     NULL AFTER emailed_to,
  ADD COLUMN email_error VARCHAR(255) NULL AFTER emailed_at;
