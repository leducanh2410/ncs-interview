#!/bin/bash

until nc -z -v -w30 $DB_HOST 3306
do
  echo "Waiting until the database is ready..."
  sleep 1
done
