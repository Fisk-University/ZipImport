#!/bin/bash
set -e

ENV=$1
TAG=$2

mkdir -p /var/log/deploy
DATE=$(date +"%Y%m%d_%H%M%S")
LOG_FILE="/var/log/deploy/deploy_${TAG}.log"
LOCAL_BACKUP_DIR="/var/backups/omeka-${ENV}-${DATE}"
S3_BUCKET="rw.rosenwald-ci-cd-logs-backups"

# Create local backup
echo "[INFO] Creating local backup of current modules..." | tee $LOG_FILE
mkdir -p "$LOCAL_BACKUP_DIR"
cp -r /var/www/html/omeka-s/modules "$LOCAL_BACKUP_DIR/" || echo "[WARN] Local backup failed" | tee -a $LOG_FILE

# Copy backup to S3
echo "[INFO] Uploading backup to S3..." | tee -a $LOG_FILE
aws s3 cp --recursive "$LOCAL_BACKUP_DIR/" "s3://${S3_BUCKET}/backups/${ENV}/${TAG}/" || echo "[WARN] S3 backup upload failed" | tee -a $LOG_FILE

echo "[INFO] Deployment started for ${TAG} on ${ENV}" | tee -a $LOG_FILE

# Unpack artifact (already unzipped in ~/deployed)
echo "[STEP] Running module deployment logic..." | tee -a $LOG_FILE

FAILED=0

if [ -d ~/deployed/modules ]; then
  for dir in ~/deployed/modules/*; do
    if [ -d "$dir" ]; then
      name=$(basename "$dir")
      echo "Deploying module: $name" | tee -a $LOG_FILE

      # Backup individual module before rsync
      cp -r "/var/www/html/omeka-s/modules/$name" "$LOCAL_BACKUP_DIR/${name}_preupdate" 2>/dev/null || true

      rsync -av "$dir/" "/var/www/html/omeka-s/modules/$name/" >> $LOG_FILE || FAILED=1

      if [ -f "$dir/composer.json" ] && [ ! -d "$dir/vendor" ]; then
        echo "Running composer install for module: $name" | tee -a $LOG_FILE
        cd "/var/www/html/omeka-s/modules/$name"
        composer install --no-dev --prefer-dist >> $LOG_FILE 2>&1 || FAILED=1
      fi

      if [ "$FAILED" -ne 0 ]; then
        echo "[ERROR] Deployment failed for $name. Restoring backup..." | tee -a $LOG_FILE
        rm -rf "/var/www/html/omeka-s/modules/$name"
        cp -r "$LOCAL_BACKUP_DIR/${name}_preupdate" "/var/www/html/omeka-s/modules/$name" || echo "[FATAL] Could not restore backup for $name" | tee -a $LOG_FILE
      fi
    fi
  done
else
  echo "[ERROR] No modules found to deploy." | tee -a $LOG_FILE
  exit 1
fi

# Cleanup
echo "[STEP] Cleaning up..." | tee -a $LOG_FILE
rm -rf ~/artifact.zip ~/deployed

# Upload log to S3
echo "[INFO] Uploading log file to S3..." | tee -a $LOG_FILE
aws s3 cp "$LOG_FILE" "s3://${S3_BUCKET}/logs/${ENV}/deploy_${TAG}.log" || echo "[WARN] S3 log upload failed" | tee -a $LOG_FILE

echo "[SUCCESS] Deployment complete for ${TAG}" | tee -a $LOG_FILE
