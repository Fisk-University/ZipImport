#!/bin/bash
set -e

ENV=$1
TAG=$2

MODULE_NAME="ZipImport"
DATE=$(date +"%Y%m%d_%H%M%S")
S3_BUCKET="rw.rosenwald-ci-cd-logs-backups"

LOG_DIR="$HOME/deploy-logs"
BACKUP_DIR="$HOME/backups/omeka-modules-${DATE}"
DEST_PATH="/var/www/html/omeka-s/modules/$MODULE_NAME"
SRC_PATH="/tmp/deployed-module"

mkdir -p "$LOG_DIR" "$BACKUP_DIR"
LOG_FILE="$LOG_DIR/module_deploy_${MODULE_NAME}_${TAG}.log"

echo "[INFO] Deploying module: $MODULE_NAME - $TAG" | tee "$LOG_FILE"

# Backup existing
if [ -d "$DEST_PATH" ]; then
  echo "[INFO] Backing up current module..." | tee -a "$LOG_FILE"
  cp -r "$DEST_PATH" "$BACKUP_DIR/${MODULE_NAME}_preupdate"
fi

# Deploy files
mkdir -p "$DEST_PATH"
rsync -av "$SRC_PATH/" "$DEST_PATH/" >> "$LOG_FILE"

# Validations
echo "[STEP] Validating structure..." | tee -a "$LOG_FILE"

[ ! -f "$DEST_PATH/config/module.ini" ] && echo "[ERROR] module.ini missing" | tee -a "$LOG_FILE" && FAIL=1
find "$DEST_PATH/view" -name "*.phtml" | grep . || { echo "[ERROR] No .phtml templates" | tee -a "$LOG_FILE"; FAIL=1; }

echo "[STEP] Scanning Apache logs..." | tee -a "$LOG_FILE"
tail -n 200 /var/log/apache2/error.log | grep -i "fatal" >> "$LOG_FILE" || echo "[INFO] No fatal errors" | tee -a "$LOG_FILE"

# Rollback
if [ "$FAIL" == "1" ]; then
  echo "[ROLLBACK] Validation failed. Rolling back..." | tee -a "$LOG_FILE"
  rm -rf "$DEST_PATH"
  cp -r "$BACKUP_DIR/${MODULE_NAME}_preupdate" "$DEST_PATH"
  aws s3 cp "$LOG_FILE" "s3://${S3_BUCKET}/${ENV}/logs/modules/${MODULE_NAME}_${TAG}_FAILED.log"
  aws s3 cp --recursive "$BACKUP_DIR/" "s3://${S3_BUCKET}/${ENV}/backups/modules/${MODULE_NAME}_${TAG}/"
  echo "[INFO] Check rollback logs in S3" | tee -a "$LOG_FILE"
  exit 1
fi

# Upload logs
rm -rf /tmp/module-artifact.zip /tmp/deployed-module
aws s3 cp "$LOG_FILE" "s3://${S3_BUCKET}/${ENV}/logs/modules/${MODULE_NAME}_deploy_${TAG}.log"
aws s3 cp --recursive "$BACKUP_DIR/" "s3://${S3_BUCKET}/${ENV}/backups/modules/${MODULE_NAME}_${TAG}/"
echo "[COMPLETE] ZipImport module deployment successful: $TAG" | tee -a "$LOG_FILE"
