#!/bin/bash

# --- Configuration ---
HOST=$DISCO_HOST
USER=$DISCO_USER
PASS=$DISCO_PASS
REMOTE_BASE=$DISCO_REMOTE_BASE_VBX
SINCE_DATE="2026-04-16"
LOCAL_BASE=$(pwd)

# --- Logic ---

# 1. Get list of uncommitted files
files=$(git ls-files --others --modified --exclude-standard | grep -v "^tests/")
# files=$(git log master --since="$SINCE_DATE" --name-only --pretty=format: | grep -vE "^(\.agents/|\.ai/|\.claude/|\.cursor/|\.gemini/|tests/)" | grep -vE "^(\.editorconfig|\.env.*|error_log)" | grep . | sort -u)

if [ -z "$files" ]; then
    echo "No uncommitted changes to upload."
    exit 0
fi

echo "Found changed files. Starting sync..."

# 2. Build the command string
# 'set xfer:clobber on' ensures we overwrite existing files
# 'set net:max-retries 1' prevents hanging on errors
COMMANDS="set ftp:ssl-allow no;
open $HOST;
user $USER $PASS;
lcd $LOCAL_BASE;
cd $REMOTE_BASE;"
echo "Syncing files to $REMOTE_BASE"

for file in $files; do
    dir=$(dirname "$file")

    # We use 'mkdir -f' (force) or just ignore the error for existing dirs
    if [ "$dir" != "." ]; then
        COMMANDS="$COMMANDS mkdir -p -f $dir 2>/dev/null;"
    fi

    # Upload the file
    COMMANDS="$COMMANDS put $file -o $file;"
done

COMMANDS="$COMMANDS bye;"

# 3. Execute lftp and filter out the "File exists" noise
# We redirect stderr (2) to dev/null for the mkdir commands
echo "$COMMANDS" | lftp 2>&1 | grep -v "File exists"

# 4. Provide a clean summary for the user
for file in $files; do
    echo " [OK] Uploaded: $file"
done

echo "-----------------------------------"
echo "Sync to $HOST complete!"
