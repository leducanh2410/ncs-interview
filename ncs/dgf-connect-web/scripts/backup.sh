#!/bin/bash

for i in "$@"; do
  case $i in
  --env=*)
    APP_ENV="${i#*=}"
    shift
    ;;
  --bucket=*)
    BUCKET="${i#*=}"
    shift
    ;;
  esac
done

if [ -z "$APP_ENV" ]; then
  APP_ENV="dev"
fi

CONTAINER_NAME=dgf_"$APP_ENV"_db
FILE_NAME=dgf_"$APP_ENV"_"$(/bin/date '+%Y%m%d-%H%M%S')".sql.gz

docker exec "$CONTAINER_NAME" mysqldump -uroot -proot dgf_connect | gzip >./"$FILE_NAME"
echo 'Backed up database to '"$FILE_NAME"

if [[ -n "$BUCKET" ]]; then
  aws s3 cp "$FILE_NAME" s3://"$BUCKET"
  echo 'Uploaded backup file to S3'
  rm "$FILE_NAME"
else
  COUNT="$(find dgf_*.sql.gz | wc -l)"
  if [ "$COUNT" -gt 10 ]; then
    DELETE_FILE="$(find dgf_*.sql.gz | head -1)"
    rm "$DELETE_FILE"
    echo 'Deleted old file: '"$DELETE_FILE"
  fi
fi
