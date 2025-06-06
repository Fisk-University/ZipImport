#!/bin/bash
set -e

ENV=$1
TAG=$2

mkdir -p /var/log/deploy
mkdir -p ~/backups/${TAG}
DATE=$(date +"%Y%m%d_%H%M%S")

LOG_FILE="/var/log/deploy/deploy_${TAG}.log"
S3_LOG_PATH="s3://rw.rosenwald-ci-${ENV}/logs/deploy_${TAG}.log"
S3_BACKUP_PATH="s3://rw.rosenwald-ci-${ENV}/backups/${TAG}_${DATE}.tar.gz"

echo "[INFO] Deployment started for ${TAG} on ${ENV}" | tee $LOG_FILE

# Backup currently deployed modules and themes
echo "[STEP] Backing up existing deployment..." | tee -a $LOG_FILE
tar -czf ~/backups/${TAG}/omeka_backup_${TAG}.tar.gz \
  /var/www/html/omeka-s/modules/ \
  /var/www/html/omeka-s/themes/ \
  >> $LOG_FILE 2>&1 || echo "[WARN] Backup failed" | tee -a $LOG_FILE

aws s3 cp ~/backups/${TAG}/omeka_backup_${TAG}.tar.gz $S3_BACKUP_PATH >> $LOG_FILE 2>&1 || echo "[WARN] S3 backup upload failed" | tee -a $LOG_FILE

# Unpack new artifact
echo "[STEP] Unpacking new artifact..." | tee -a $LOG_FILE
unzip -o ~/artifact.zip -d ~/deployed >> $LOG_FILE

# Move new code into place
echo "[STEP] Deploying modules and themes..." | tee -a $LOG_FILE
rsync -av ~/deployed/modules/ /var/www/html/omeka-s/modules/ >> $LOG_FILE
rsync -av ~/deployed/themes/ /var/www/html/omeka-s/themes/ >> $LOG_FILE

# Clean up
echo "[STEP] Cleaning up..." | tee -a $LOG_FILE
rm -rf ~/artifact.zip ~/deployed

# Done
echo "[SUCCESS] Deployment complete for ${TAG}" | tee -a $LOG_FILE
aws s3 cp $LOG_FILE $S3_LOG_PATH || echo "[WARN] S3 log upload failed"
