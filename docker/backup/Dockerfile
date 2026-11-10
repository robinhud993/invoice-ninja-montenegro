# syntax=docker/dockerfile:1

# The MySQL image already ships a version matched mysqldump client, plus tar,
# gzip, find and sha256sum, so no extra packages are required.
FROM mysql:8.0

COPY docker/backup/backup.sh /usr/local/bin/backup.sh
COPY docker/backup/restore.sh /usr/local/bin/restore.sh

RUN chmod +x /usr/local/bin/backup.sh /usr/local/bin/restore.sh

# The service only reads the database and the storage volume, and writes to the
# backup directory, so it never needs root.
USER mysql

ENTRYPOINT ["/usr/local/bin/backup.sh"]

CMD ["schedule"]
