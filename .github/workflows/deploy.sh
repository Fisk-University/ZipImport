#!/bin/bash
set -e

#ENV & ARG VALIDATION
ENV=$1
TAG=$2

if [ -z "$ENV" ] || [ -z "$TAG" ]; then
  echo "[ERROR] Usage: $0 <env> <tag>"
  exit 1
fi

#SETUP LOGGING PATH 
mkdir -p /var/log/deploy
DATE=$(date +"%Y%m%d_%H%M%S")
LOG_FILE="/var/log/deploy/deploy_${TAG}.log"
S3_LOG_PATH="s3://rw.rosenwald-ci-${ENV}/logs/deploy_${TAG}.log"

echo "[INFO] Deployment started for ${TAG} on ${ENV}" | tee $LOG_FILE

#CHECK FOR ARTIFACT
if [ ! -f ~/artifact.zip ]; then
  echo "[ERROR] ~/artifact.zip not found." | tee -a $LOG_FILE
  exit 1
fi

#UNPACK ARTIFACT FILE
echo "[STEP] Unpacking artifact.zip..." | tee -a $LOG_FILE
rm -rf ~/deployed
unzip -o ~/artifact.zip -d ~/deployed >> $LOG_FILE 2>&1

#DEPLOY MODULES
if [ -d ~/deployed/modules ]; then
  echo "[INFO] Module deployment detected." | tee -a $LOG_FILE

  for dir in ~/deployed/modules/*; do
    if [ -d "$dir" ]; then
      name=$(basename "$dir")
      echo "[STEP] Deploying module: $name" | tee -a $LOG_FILE

      TARGET_DIR="/var/www/html/omeka-s/modules/$name"
      rsync -av "$dir/" "$TARGET_DIR/" >> $LOG_FILE 2>&1

      if [ -f "$dir/composer.json" ] && [ ! -d "$dir/vendor" ]; then
        echo "[INFO] Running composer install for module: $name" | tee -a $LOG_FILE
        if command -v composer &> /dev/null; then
          cd "$TARGET_DIR"
          composer install --no-dev --prefer-dist >> $LOG_FILE 2>&1 || {
            echo "[WARN] Composer install failed for $name" | tee -a $LOG_FILE
          }
        else
          echo "[WARN] Composer not found, skipping for $name" | tee -a $LOG_FILE
        fi
      fi
    fi
  done
else
  echo "[ERROR] modules/ directory not found in artifact." | tee -a $LOG_FILE
  exit 1
fi

#CLEANUP
echo "[STEP] Cleaning up..." | tee -a $LOG_FILE
rm -rf ~/artifact.zip ~/deployed

#PUSH TO S3
echo "[SUCCESS] Deployment complete for ${TAG}" | tee -a $LOG_FILE
aws s3 cp "$LOG_FILE" "$S3_LOG_PATH" || echo "[WARN] S3 log upload failed"
