#!/bin/bash
#mcp.sh


set -e
set -o pipefail

BASE="/home/psegatori/www/castor"

date >> "$BASE/run.log"

stdin_log="$BASE/stdin.log"
stdout_log="$BASE/stdout.log"
stderr_log="$BASE/stderr.log"

tee -a "$stdin_log" | \
  $BASE/bin/castor castor:run-mcp-server > >(tee -a "$stdout_log") 2> >(tee -a "$stderr_log" >&2)
