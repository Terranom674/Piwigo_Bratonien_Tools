CREATE OR REPLACE VIEW piwigo_showcase_sources AS
SELECT
  s.id AS share_id,
  s.item_type,
  s.file_target AS display_name,
  st.id AS storage_id,
  f.path AS source_path,
  s.accepted,
  s.permissions
FROM oc_share AS s
JOIN oc_filecache AS f ON f.fileid = s.file_source
JOIN oc_storages AS st ON st.numeric_id = f.storage
WHERE lower(s.share_with) = 'showcase'
  AND s.item_type IN ('folder', 'file')
  AND s.accepted = 1;

GRANT SELECT ON piwigo_showcase_sources TO piwigo_reader;
