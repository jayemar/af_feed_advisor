#!/bin/bash
#
# Export TT-RSS Docker logs for system health monitoring
#
# This script exports the last 24 hours of Docker logs to a file
# that can be mounted into the TT-RSS container for analysis.
#
# af_feed_advisor's system health report runs once daily at midnight UTC and
# reads whatever range this script exported - the 24h window here must cover
# a full day so nothing between runs is missed, regardless of exactly when
# this cron job fires relative to that check.
#
# Setup:
# 1. Create log directory:
#    mkdir -p /tmp/ttrss-logs
#
# 2. Add to crontab (any cadence is fine as long as it runs before midnight
#    UTC each day - the existing twice-daily schedule works, e.g.):
#    0 6,18 * * * /path/to/export-docker-logs.sh
#
# 3. Mount the log file in docker-compose.yaml:
#    volumes:
#      - /tmp/ttrss-logs/docker.log:/var/log/ttrss/docker.log:ro
#

set -e

# Configuration
COMPOSE_DIR="/home/jayemar/projects/homelab/ttrss"
LOG_DIR="/tmp/ttrss-logs"
LOG_FILE="${LOG_DIR}/docker.log"

# Create log directory if it doesn't exist
mkdir -p "${LOG_DIR}"

# Export logs from last 24 hours
cd "${COMPOSE_DIR}"
docker compose logs --since 24h updater app 2>&1 > "${LOG_FILE}"

# Set permissions
chmod 644 "${LOG_FILE}"

echo "$(date): Exported Docker logs to ${LOG_FILE}"
