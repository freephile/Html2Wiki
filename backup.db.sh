#!/bin/sh
# @author Greg Rundlett <info@eQuality-Tech.com>
# This is a quick shell script to create a sql dump of your database.
# You may need to adjust the path of mysqldump, 
# or sudo apt-get install mysqldump  if it doesn't exist

# To configure this script, 
# you could hardcode which database to backup
# DB=wiki
# We'll make it so you can pass the database name as the first parameter 
# to the script.  If no parameter is passed, we'll prompt you for the name
DB=$1
if [ $# -ne 1 ]; then 
  echo "Here are the current databases on the server"
  mysql -u root --batch --skip-column-names -e 'show databases;'
  echo "Enter the name of the database you want to backup"
  read DB
fi
# We'll use a location that is exported to the host, so that our backups are 
# accessible even if the virtual machine is no longer accessible.
backupdir="/vagrant/mediawiki/backups";
if [ ! -d "$backupdir" ]; then
  mkdir -p "$backupdir";
fi

# we'll start with a default backup file named '01' in the sequence
backup="${backupdir}/dump-$(date +%F).$(hostname)-${DB}.01.sql";
# and we'll increment the counter in the filename if it already exists
i=1
filename=$(basename "$backup") # foo.txt (basename is everything after the last slash)
# shell parameter expansion see http://www.gnu.org/software/bash/manual/html_node/Shell-Parameter-Expansion.html
extension=${filename##*.}    # .txt (filename with the longest matching pattern of *. being deleted)
file=${filename%.*}        # foo (filename with the shortest matching pattern of .* deleted)
file=${file%.*}        # repeat the strip to get rid of the counter
# file=${filename%.{00..99}.$extension} # foo (filename with the shortest matching pattern of .[01-99].* deleted)
while [ -f $backup ]; do
  backup="$backupdir/${file}.$(printf '%.2d' $(( i+1 ))).${extension}"
  i=$(( i+1 ))  # increments $i 
  # note that i is naked because $(( expression )) is arithmetic expansion in bash
done
if /usr/bin/mysqldump "$DB" > "$backup"; then
  echo "backup created successfully"
  ls -al "$backup";
  echo "A command such as"
  echo "mysql -u root $DB < $backup" 
  echo "will restore the database from the chosen sql dump file"
else
  echo "Something went wrong with the backup"
  exit 1
fi